<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Integration\Persistence;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailOutboxRepository;

final class SqlMailOutboxRepositoryTest extends MigrationTestCase
{
    private SqlMailOutboxRepository $repo;

    private TenantId $tenantId;

    private UserId $queuedBy;

    protected function setUp(): void
    {
        parent::setUp();

        // 098 = communications module migration (post-extraction module slot).
        // Use 98 to ensure mail_outbox + tenant_communication_settings + friends
        // exist alongside the membership-billing tables (0.7) the outbox can
        // optionally reference via payload_invoice_id.
        $this->runMigrationsUpTo(98);

        $this->repo = new SqlMailOutboxRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));

        $this->tenantId = $this->resolveDaemsTenantId();
        $this->queuedBy = $this->seedQueueingUser();
    }

    private function resolveDaemsTenantId(): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute(['daems']);
        $id = $stmt->fetchColumn();
        if (!is_string($id)) {
            throw new \RuntimeException('daems tenant missing after migrations');
        }
        return TenantId::fromString($id);
    }

    private function seedQueueingUser(): UserId
    {
        $id = '01958000-0000-7000-8000-0000000000aa';
        $stmt = $this->pdo()->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            $ins = $this->pdo()->prepare(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$id, 'Queuer', 'queuer@test', 'x', '1990-01-01', 0]);
        }
        return UserId::fromString($id);
    }

    private function makeOutbox(
        ?MailOutboxId $id = null,
        MailKind $kind = MailKind::GroupMessage,
        string $recipient = 'recipient@example.com',
        MailOutboxStatus $status = MailOutboxStatus::Queued,
        ?\DateTimeImmutable $queuedAt = null,
    ): MailOutbox {
        $kindCategory = $kind->category();

        return new MailOutbox(
            id:                  $id ?? MailOutboxId::generate(),
            tenantId:            $this->tenantId,
            kind:                $kind,
            category:            $kindCategory,
            recipientEmail:      $recipient,
            recipientUserId:     null,
            locale:              SupportedLocale::fromString('fi_FI'),
            subject:             'Test subject',
            bodyHtml:            '<p>Hello</p>',
            bodyText:            'Hello',
            payloadVars:         ['name' => 'Test User', 'foo' => ['bar' => 'baz']],
            payloadMeetingId:    null,
            payloadInvoiceId:    null,
            payloadNewsletterId: null,
            status:              $status,
            attemptCount:        0,
            lastError:           null,
            queuedAt:            $queuedAt ?? new \DateTimeImmutable('now'),
            sentAt:              null,
            queuedBy:            $this->queuedBy,
        );
    }

    public function testSaveAndFindByIdRoundTrip(): void
    {
        $outbox = $this->makeOutbox();
        $this->repo->save($outbox);

        $loaded = $this->repo->findById($outbox->id);
        $this->assertNotNull($loaded);
        $this->assertSame($outbox->id->value(), $loaded->id->value());
        $this->assertSame('recipient@example.com', $loaded->recipientEmail);
        $this->assertSame('Test subject',         $loaded->subject);
        $this->assertSame(MailKind::GroupMessage, $loaded->kind);
        $this->assertSame(MailOutboxStatus::Queued, $loaded->status);
        $this->assertSame('fi_FI', $loaded->locale->value());
        // MySQL JSON normalises object key order; compare canonicalised.
        $this->assertEqualsCanonicalizing(
            ['name' => 'Test User', 'foo' => ['bar' => 'baz']],
            $loaded->payloadVars,
        );
    }

    public function testMarkStatusUpdatesStatusAndSentAtForSent(): void
    {
        $outbox = $this->makeOutbox();
        $this->repo->save($outbox);

        $this->repo->markStatus($outbox->id, MailOutboxStatus::Sent);

        $loaded = $this->repo->findById($outbox->id);
        $this->assertNotNull($loaded);
        $this->assertSame(MailOutboxStatus::Sent, $loaded->status);
        $this->assertNotNull($loaded->sentAt);
        $this->assertNull($loaded->lastError);
    }

    public function testMarkStatusFailedRecordsError(): void
    {
        $outbox = $this->makeOutbox();
        $this->repo->save($outbox);

        $this->repo->markStatus($outbox->id, MailOutboxStatus::Failed, 'SMTP 451');

        $loaded = $this->repo->findById($outbox->id);
        $this->assertNotNull($loaded);
        $this->assertSame(MailOutboxStatus::Failed, $loaded->status);
        $this->assertSame('SMTP 451', $loaded->lastError);
        $this->assertNull($loaded->sentAt);
    }

    public function testListForTenantFiltersByKind(): void
    {
        $this->repo->save($this->makeOutbox(kind: MailKind::MeetingInvitation, recipient: 'a@example.com'));
        $this->repo->save($this->makeOutbox(kind: MailKind::PaymentReminder,   recipient: 'b@example.com'));
        $this->repo->save($this->makeOutbox(kind: MailKind::GroupMessage,      recipient: 'c@example.com'));

        $allInvitations = $this->repo->listForTenant(
            $this->tenantId,
            ['kind' => MailKind::MeetingInvitation],
            page: 1,
            perPage: 50,
        );
        $this->assertCount(1, $allInvitations);
        $this->assertSame('a@example.com', $allInvitations[0]->recipientEmail);
        $this->assertSame(MailKind::MeetingInvitation, $allInvitations[0]->kind);

        $total = $this->repo->countForTenant($this->tenantId, []);
        $this->assertSame(3, $total);

        $remindersOnly = $this->repo->countForTenant(
            $this->tenantId,
            ['kind' => MailKind::PaymentReminder],
        );
        $this->assertSame(1, $remindersOnly);
    }

    public function testPickNextForSendingPicksOldestQueuedAndMarksSending(): void
    {
        $base = new \DateTimeImmutable('2026-05-13 09:00:00');

        // Insert 3 queued rows with increasing queued_at + 1 already-sent row
        // that should not be picked.
        $first  = $this->makeOutbox(queuedAt: $base);
        $second = $this->makeOutbox(queuedAt: $base->modify('+1 minute'));
        $third  = $this->makeOutbox(queuedAt: $base->modify('+2 minutes'));
        $alreadySent = $this->makeOutbox(
            recipient: 'sent@example.com',
            status:    MailOutboxStatus::Sent,
            queuedAt:  $base->modify('-10 minutes'),
        );

        $this->repo->save($first);
        $this->repo->save($second);
        $this->repo->save($third);
        $this->repo->save($alreadySent);

        $picked = $this->repo->pickNextForSending(2);

        $this->assertCount(2, $picked);
        // FIFO by queued_at ASC: first then second.
        $this->assertSame($first->id->value(),  $picked[0]->id->value());
        $this->assertSame($second->id->value(), $picked[1]->id->value());
        foreach ($picked as $p) {
            $this->assertSame(MailOutboxStatus::Sending, $p->status);
        }

        // Verify DB-side: first/second now sending, third still queued, sent untouched.
        $r1 = $this->repo->findById($first->id);
        $r2 = $this->repo->findById($second->id);
        $r3 = $this->repo->findById($third->id);
        $r4 = $this->repo->findById($alreadySent->id);
        $this->assertNotNull($r1);
        $this->assertNotNull($r2);
        $this->assertNotNull($r3);
        $this->assertNotNull($r4);
        $this->assertSame(MailOutboxStatus::Sending, $r1->status);
        $this->assertSame(MailOutboxStatus::Sending, $r2->status);
        $this->assertSame(MailOutboxStatus::Queued,  $r3->status);
        $this->assertSame(MailOutboxStatus::Sent,    $r4->status);
    }

    public function testIncrementAttemptBumpsCountAndStoresError(): void
    {
        $outbox = $this->makeOutbox();
        $this->repo->save($outbox);

        $this->repo->incrementAttempt($outbox->id, 'first failure');
        $this->repo->incrementAttempt($outbox->id, 'second failure');

        $loaded = $this->repo->findById($outbox->id);
        $this->assertNotNull($loaded);
        $this->assertSame(2, $loaded->attemptCount);
        $this->assertSame('second failure', $loaded->lastError);
    }
}
