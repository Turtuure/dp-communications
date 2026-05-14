<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Integration;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DaemsModule\Communications\Application\EnqueuePaymentReminders\EnqueuePaymentReminders;
use DaemsModule\Communications\Application\EnqueuePaymentReminders\Input;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailOutboxRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailSuppressionRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailTemplateRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlTenantCommunicationSettingsRepository;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use DaemsModule\Communications\Infrastructure\Renderer\MailTemplateRegistry;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;

/**
 * Exercises the 0.8 Wave F Task F1 cron against the live MySQL schema.
 *
 * Seeds one tenant, three users + three invoices in different states, runs
 * the use case at a pinned `$now`, and asserts only the expected (pre-due
 * PENDING + post-due OVERDUE) invoices produce outbox rows.
 */
final class EnqueuePaymentRemindersTest extends MigrationTestCase
{
    private Connection $conn;
    private SqlMailOutboxRepository $outboxRepo;
    private SqlMailSuppressionRepository $suppressionRepo;
    private SqlTenantCommunicationSettingsRepository $settingsRepo;
    private SqlMemberFeeInvoiceRepository $invoiceRepo;
    private SqlTenantRepository $tenantRepo;
    private SqlMailTemplateRepository $templateRepo;
    private EnqueuePaymentReminders $useCase;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(98);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->outboxRepo      = new SqlMailOutboxRepository($this->conn);
        $this->suppressionRepo = new SqlMailSuppressionRepository($this->conn);
        $this->settingsRepo    = new SqlTenantCommunicationSettingsRepository($this->conn);
        $this->invoiceRepo     = new SqlMemberFeeInvoiceRepository($this->conn->pdo());
        $this->tenantRepo      = new SqlTenantRepository($this->conn->pdo());
        $this->templateRepo    = new SqlMailTemplateRepository($this->conn);

        $renderer = new EmailHtmlRenderer(
            new MailTemplateRegistry(),
            new VarSubstituter(),
            new MarkdownRenderer(),
            new Html2Text(),
        );

        $this->useCase = new EnqueuePaymentReminders(
            $this->tenantRepo,
            $this->settingsRepo,
            $this->invoiceRepo,
            $this->outboxRepo,
            $this->suppressionRepo,
            $this->templateRepo,
            $renderer,
            $this->conn,
        );

        $this->tenantId = $this->resolveDaemsTenantId();
        $this->seedSmtpSettings(preDue: 7, postDue: [14, 30]);
    }

    public function testEnqueuesPreDueReminderForPendingInvoice(): void
    {
        // "Today" = 2026-04-01. Pre-due reminder should fire for invoices
        // with due_date = 2026-04-08 (now + 7) AND status = PENDING.
        $now    = new \DateTimeImmutable('2026-04-01 09:00:00');
        $user   = $this->seedUser('alice@example.test', 'Alice Anderson');
        $invoiceId = $this->seedInvoice(
            $user,
            year: 2026,
            dueDate: '2026-04-08',
            status: MemberFeeInvoiceStatus::Pending,
        );

        $out = $this->useCase->execute(new Input(now: $now), null);

        $this->assertSame(1, $out->enqueued, 'expected exactly one pre-due reminder');
        $this->assertSame(1, $out->preDue);
        $this->assertSame(0, $out->postDue);

        $rows = $this->fetchOutboxRowsForInvoice($invoiceId);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('alice@example.test', $row['recipient_email']);
        $this->assertSame(MailKind::PaymentReminder->value, $row['kind']);
        $this->assertSame(MailOutboxStatus::Queued->value, $row['status']);
        $vars = json_decode((string) $row['payload_vars'], true);
        $this->assertIsArray($vars);
        $this->assertSame('pre_due', $vars['offset_tag'] ?? null);
    }

    public function testEnqueuesPostDueReminderForOverdueInvoiceAtConfiguredOffset(): void
    {
        // "Today" = 2026-04-15. Post-due reminder at offset 14d → invoice
        // with due_date = 2026-04-01 AND status = OVERDUE.
        $now    = new \DateTimeImmutable('2026-04-15 09:00:00');
        $user   = $this->seedUser('bob@example.test', 'Bob Builder');
        $invoiceId = $this->seedInvoice(
            $user,
            year: 2026,
            dueDate: '2026-04-01',
            status: MemberFeeInvoiceStatus::Overdue,
        );

        $out = $this->useCase->execute(new Input(now: $now), null);

        $this->assertSame(1, $out->enqueued);
        $this->assertSame(0, $out->preDue);
        $this->assertSame(1, $out->postDue);

        $rows = $this->fetchOutboxRowsForInvoice($invoiceId);
        $this->assertCount(1, $rows);
        $vars = json_decode((string) $rows[0]['payload_vars'], true);
        $this->assertIsArray($vars);
        $this->assertSame('post_due_14', $vars['offset_tag'] ?? null);
    }

    public function testSkipsPaidInvoiceAndOffWindowDueDate(): void
    {
        $now = new \DateTimeImmutable('2026-04-01 09:00:00');

        // Paid invoice — status not in {PENDING}, must be skipped.
        $userA = $this->seedUser('paid@example.test', 'Paid Person');
        $this->seedInvoice(
            $userA,
            year: 2026,
            dueDate: '2026-04-08',
            status: MemberFeeInvoiceStatus::Paid,
            paidAt: '2026-03-30 10:00:00',
        );

        // PENDING but due 2026-04-09 — outside the exact +7 day window.
        $userB = $this->seedUser('offwin@example.test', 'Off Window');
        $this->seedInvoice(
            $userB,
            year: 2026,
            dueDate: '2026-04-09',
            status: MemberFeeInvoiceStatus::Pending,
        );

        $out = $this->useCase->execute(new Input(now: $now), null);

        $this->assertSame(0, $out->enqueued);
        $this->assertSame(0, $out->preDue);
        $this->assertSame(0, $out->postDue);
    }

    public function testIdempotentWithin24Hours(): void
    {
        $now    = new \DateTimeImmutable('2026-04-01 09:00:00');
        $user   = $this->seedUser('again@example.test', 'Alice Again');
        $this->seedInvoice(
            $user,
            year: 2026,
            dueDate: '2026-04-08',
            status: MemberFeeInvoiceStatus::Pending,
        );

        $first = $this->useCase->execute(new Input(now: $now), null);
        $this->assertSame(1, $first->enqueued);

        // Re-run 6 hours later — should be a no-op (idempotent in 24h window).
        $second = $this->useCase->execute(
            new Input(now: $now->modify('+6 hours')),
            null,
        );
        $this->assertSame(0, $second->enqueued, 'second run within 24h must skip');
    }

    public function testSkipsTenantWithoutSmtp(): void
    {
        // Wipe SMTP DSN to simulate unconfigured tenant.
        $this->conn->execute(
            'UPDATE tenant_communication_settings
             SET smtp_dsn_encrypted = NULL, mail_from_address = NULL
             WHERE tenant_id = ?',
            [$this->tenantId->value()],
        );

        $now  = new \DateTimeImmutable('2026-04-01 09:00:00');
        $user = $this->seedUser('nosmtp@example.test', 'No SMTP');
        $this->seedInvoice(
            $user,
            year: 2026,
            dueDate: '2026-04-08',
            status: MemberFeeInvoiceStatus::Pending,
        );

        $out = $this->useCase->execute(new Input(now: $now), null);

        $this->assertSame(0, $out->enqueued);
        $this->assertGreaterThanOrEqual(1, $out->tenantsSkippedNoSmtp);
    }

    // --- helpers -------------------------------------------------------

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

    private function seedUser(string $email, string $name): UserId
    {
        // Unique id derived from the email to keep tests deterministic.
        $hash    = substr(md5($email), 0, 12);
        $userId  = '01958000-0000-7000-8000-' . $hash;
        $check   = $this->pdo()->prepare('SELECT 1 FROM users WHERE id = ?');
        $check->execute([$userId]);
        if ($check->fetchColumn() === false) {
            $ins = $this->pdo()->prepare(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$userId, $name, $email, 'x', '1990-01-01', 0]);
        }
        return UserId::fromString($userId);
    }

    private function seedInvoice(
        UserId $userId,
        int $year,
        string $dueDate,
        MemberFeeInvoiceStatus $status,
        ?string $paidAt = null,
    ): MemberFeeInvoiceId {
        $invoice = new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $this->tenantId,
            userId:              $userId,
            year:                $year,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new \DateTimeImmutable($dueDate),
            amountCents:         2500,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new \DateTimeImmutable($dueDate),
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $this->invoiceRepo->save($invoice);

        // The domain entity enforces Paid via recordPayment; for test seeding
        // we bypass that by issuing a direct UPDATE so we can exercise the
        // "skip Paid" branch without staging a PaymentRecord here.
        if ($status !== MemberFeeInvoiceStatus::Pending) {
            $this->conn->execute(
                'UPDATE member_fee_invoices SET status = ?, paid_at = ? WHERE id = ?',
                [$status->value, $paidAt, $invoice->id()->value()],
            );
        }
        return $invoice->id();
    }

    private function seedSmtpSettings(int $preDue, array $postDue): void
    {
        $this->settingsRepo->save(new TenantCommunicationSettings(
            tenantId:               $this->tenantId,
            smtpDsnEncrypted:       'fake-cipher',
            mailFromAddress:        'no-reply@example.test',
            mailDisplayName:        'Daems',
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     $preDue,
            reminderPostDueDays:    $postDue,
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      '#005599',
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable(),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchOutboxRowsForInvoice(MemberFeeInvoiceId $id): array
    {
        return $this->conn->query(
            'SELECT recipient_email, kind, status, payload_vars
             FROM mail_outbox
             WHERE payload_invoice_id = ?
             ORDER BY queued_at ASC',
            [$id->value()],
        );
    }
}
