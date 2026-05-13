<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Integration;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DaemsModule\Communications\Application\DrainMailOutbox\DrainMailOutbox;
use DaemsModule\Communications\Application\DrainMailOutbox\Input;
use DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending\MarkSuppressedRecipientsInPending;
use DaemsModule\Communications\Domain\Mail\Exception\MailerHardBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerSoftBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerTransportException;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Mailer\InMemoryMailer;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailOutboxRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailSuppressionRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlTenantCommunicationSettingsRepository;

/**
 * Exercises the end-to-end {@see DrainMailOutbox} pipeline against the live
 * MySQL schema (= the same drain a cron tick would run).
 *
 * Each test seeds a single queued row, configures the {@see InMemoryMailer}
 * to simulate a specific SMTP outcome, runs `drain(batchSize: 50)`, and
 * asserts the resulting row status + suppression-list side effects.
 */
final class DrainMailOutboxTest extends MigrationTestCase
{
    private SqlMailOutboxRepository $outboxRepo;

    private SqlMailSuppressionRepository $suppressionRepo;

    private SqlTenantCommunicationSettingsRepository $settingsRepo;

    private InMemoryMailer $mailer;

    private DrainMailOutbox $useCase;

    private TenantId $tenantId;

    private UserId $queuedBy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigrationsUpTo(98);

        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->outboxRepo      = new SqlMailOutboxRepository($conn);
        $this->suppressionRepo = new SqlMailSuppressionRepository($conn);
        $this->settingsRepo    = new SqlTenantCommunicationSettingsRepository($conn);
        $this->mailer          = new InMemoryMailer();

        $this->useCase = new DrainMailOutbox(
            $this->outboxRepo,
            $this->suppressionRepo,
            $this->settingsRepo,
            $this->mailer,
            new MarkSuppressedRecipientsInPending($this->outboxRepo, $this->suppressionRepo),
        );

        $this->tenantId = $this->resolveDaemsTenantId();
        $this->queuedBy = $this->seedQueueingUser();
        $this->seedSmtpSettings();
    }

    public function testDrainSendsQueuedRowAndMarksItSent(): void
    {
        $row = $this->makeOutbox(recipient: 'happy@example.com');
        $this->outboxRepo->save($row);

        $out = $this->useCase->execute(new Input(batchSize: 50), null);

        $this->assertSame(1, $out->sent);
        $this->assertSame(0, $out->bounced);
        $this->assertSame(0, $out->failed);
        $this->assertSame(0, $out->retried);
        $this->assertCount(1, $this->mailer->sent);

        $loaded = $this->outboxRepo->findById($row->id);
        $this->assertNotNull($loaded);
        $this->assertSame(MailOutboxStatus::Sent, $loaded->status);
        $this->assertNotNull($loaded->sentAt);
    }

    public function testDrainHardBounceAddsSuppressionAndMarksBounced(): void
    {
        $row = $this->makeOutbox(recipient: 'bounce@example.com');
        $this->outboxRepo->save($row);

        $this->mailer->simulateFailure = new MailerHardBounceException(
            smtpCode: '5.1.1',
            message:  'Mailbox does not exist',
        );

        $out = $this->useCase->execute(new Input(batchSize: 50), null);

        $this->assertSame(1, $out->bounced);
        $this->assertSame(0, $out->sent);

        $loaded = $this->outboxRepo->findById($row->id);
        $this->assertNotNull($loaded);
        $this->assertSame(MailOutboxStatus::Bounced, $loaded->status);
        $this->assertSame('Mailbox does not exist', $loaded->lastError);

        $this->assertTrue($this->suppressionRepo->isSuppressed($this->tenantId, 'bounce@example.com'));
    }

    public function testDrainSoftBounceIncrementsAttemptAndCountsRetry(): void
    {
        $row = $this->makeOutbox(recipient: 'soft@example.com');
        $this->outboxRepo->save($row);

        $this->mailer->simulateFailure = new MailerSoftBounceException(
            smtpCode: '4.2.1',
            message:  'Temporarily over quota',
        );

        $out = $this->useCase->execute(new Input(batchSize: 50), null);

        $this->assertSame(0, $out->sent);
        $this->assertSame(1, $out->retried);
        $this->assertSame(0, $out->failed);

        $loaded = $this->outboxRepo->findById($row->id);
        $this->assertNotNull($loaded);
        // pickNextForSending already flipped Queued → Sending; soft-bounce
        // only increments the counter, status stays as-is.
        $this->assertSame(MailOutboxStatus::Sending, $loaded->status);
        $this->assertSame(1, $loaded->attemptCount);
        $this->assertSame('Temporarily over quota', $loaded->lastError);
    }

    public function testDrainSoftBounceMarksFailedOnThirdAttempt(): void
    {
        // Seed row with attempt_count = 2 so the next +1 hits MAX_ATTEMPTS.
        $row = $this->makeOutbox(recipient: 'softfinal@example.com', attemptCount: 2);
        $this->outboxRepo->save($row);

        $this->mailer->simulateFailure = new MailerSoftBounceException(
            smtpCode: '4.4.2',
            message:  'Final retry exhausted',
        );

        $out = $this->useCase->execute(new Input(batchSize: 50), null);

        $this->assertSame(1, $out->failed);
        $this->assertSame(0, $out->retried);

        $loaded = $this->outboxRepo->findById($row->id);
        $this->assertNotNull($loaded);
        $this->assertSame(MailOutboxStatus::Failed, $loaded->status);
        $this->assertSame('Final retry exhausted', $loaded->lastError);
    }

    public function testDrainTransportErrorMarksFailed(): void
    {
        $row = $this->makeOutbox(recipient: 'transport@example.com');
        $this->outboxRepo->save($row);

        $this->mailer->simulateFailure = new MailerTransportException('Connection refused');

        $out = $this->useCase->execute(new Input(batchSize: 50), null);

        $this->assertSame(1, $out->failed);
        $this->assertSame(0, $out->sent);

        $loaded = $this->outboxRepo->findById($row->id);
        $this->assertNotNull($loaded);
        $this->assertSame(MailOutboxStatus::Failed, $loaded->status);
        $this->assertSame('Connection refused', $loaded->lastError);
    }

    public function testDrainSkipsRecipientOnSuppressionList(): void
    {
        // Pre-suppress a recipient, then queue a row to that address.
        $this->suppressionRepo->add(new \DaemsModule\Communications\Domain\Mail\MailSuppression(
            tenantId:         $this->tenantId,
            emailAddress:     'blocked@example.com',
            reason:           \DaemsModule\Communications\Domain\Mail\SuppressionReason::HardBounce,
            suppressedAt:     new \DateTimeImmutable(),
            smtpResponseCode: '5.1.1',
            suppressedBy:     null,
        ));

        $row = $this->makeOutbox(recipient: 'blocked@example.com');
        $this->outboxRepo->save($row);

        $out = $this->useCase->execute(new Input(batchSize: 50), null);

        // The mark-suppressed pre-step flips the row to Suppressed BEFORE
        // pickNextForSending sees it, so all four counters stay 0.
        $this->assertSame(0, $out->sent);
        $this->assertSame(0, $out->bounced);
        $this->assertSame(0, $out->failed);
        $this->assertSame(0, $out->retried);
        $this->assertCount(0, $this->mailer->sent);

        $loaded = $this->outboxRepo->findById($row->id);
        $this->assertNotNull($loaded);
        $this->assertSame(MailOutboxStatus::Suppressed, $loaded->status);
        $this->assertSame('recipient on suppression list', $loaded->lastError);
    }

    private function makeOutbox(
        string $recipient = 'recipient@example.com',
        int $attemptCount = 0,
    ): MailOutbox {
        $kind = MailKind::GroupMessage;
        return new MailOutbox(
            id:                  MailOutboxId::generate(),
            tenantId:            $this->tenantId,
            kind:                $kind,
            category:            $kind->category(),
            recipientEmail:      $recipient,
            recipientUserId:     null,
            locale:              SupportedLocale::fromString('fi_FI'),
            subject:             'Drain test',
            bodyHtml:            '<p>Hi</p>',
            bodyText:            'Hi',
            payloadVars:         [],
            payloadMeetingId:    null,
            payloadInvoiceId:    null,
            payloadNewsletterId: null,
            status:              MailOutboxStatus::Queued,
            attemptCount:        $attemptCount,
            lastError:           null,
            queuedAt:            new \DateTimeImmutable(),
            sentAt:              null,
            queuedBy:            $this->queuedBy,
        );
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
        $id = '01958000-0000-7000-8000-0000000000ab';
        $stmt = $this->pdo()->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            $ins = $this->pdo()->prepare(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$id, 'Drainer', 'drainer@test', 'x', '1990-01-01', 0]);
        }
        return UserId::fromString($id);
    }

    /**
     * Seed tenant_communication_settings so the SMTP-not-configured branch
     * never trips. The InMemoryMailer ignores the DSN ciphertext anyway.
     */
    private function seedSmtpSettings(): void
    {
        $this->settingsRepo->save(new TenantCommunicationSettings(
            tenantId:               $this->tenantId,
            smtpDsnEncrypted:       'fake-cipher',
            mailFromAddress:        'from@example.test',
            mailDisplayName:        null,
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      null,
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable(),
        ));
    }
}
