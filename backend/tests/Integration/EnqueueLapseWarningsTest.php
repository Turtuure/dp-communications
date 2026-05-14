<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Integration;

use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantGovernanceSettingsRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DaemsModule\Communications\Application\EnqueueLapseWarnings\EnqueueLapseWarnings;
use DaemsModule\Communications\Application\EnqueueLapseWarnings\Input;
use DaemsModule\Communications\Domain\Mail\MailKind;
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
 * Exercises 0.8 Wave F Task F2 against MySQL.
 *
 * The use case predicts the lapse-trigger date as
 * `(newer-overdue-invoice due_date) + governance.overdue_grace_days`,
 * and fires `lapse_warning_days_before` days before that. Tests pin
 * both `$now` and the seeded due-dates so the date arithmetic is exact.
 */
final class EnqueueLapseWarningsTest extends MigrationTestCase
{
    private Connection $conn;
    private SqlMailOutboxRepository $outboxRepo;
    private SqlMailSuppressionRepository $suppressionRepo;
    private SqlTenantCommunicationSettingsRepository $settingsRepo;
    private SqlTenantGovernanceSettingsRepository $governanceRepo;
    private SqlMemberFeeInvoiceRepository $invoiceRepo;
    private SqlTenantRepository $tenantRepo;
    private SqlMailTemplateRepository $templateRepo;
    private EnqueueLapseWarnings $useCase;

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
        $this->governanceRepo  = new SqlTenantGovernanceSettingsRepository($this->conn->pdo());
        $this->invoiceRepo     = new SqlMemberFeeInvoiceRepository($this->conn->pdo());
        $this->tenantRepo      = new SqlTenantRepository($this->conn->pdo());
        $this->templateRepo    = new SqlMailTemplateRepository($this->conn);

        $renderer = new EmailHtmlRenderer(
            new MailTemplateRegistry(),
            new VarSubstituter(),
            new MarkdownRenderer(),
            new Html2Text(),
        );

        $this->useCase = new EnqueueLapseWarnings(
            $this->tenantRepo,
            $this->settingsRepo,
            $this->governanceRepo,
            $this->invoiceRepo,
            $this->outboxRepo,
            $this->suppressionRepo,
            $this->templateRepo,
            $renderer,
            $this->conn,
        );

        $this->tenantId = $this->resolveDaemsTenantId();
        $this->seedSmtpSettings(warnDays: 30);
        $this->seedGovernanceSettings(graceDays: 30, lapseEnabled: true);
    }

    public function testEnqueuesWarningExactlyNDaysBeforePredictedLapse(): void
    {
        // Newer (2026) invoice due 2026-03-01, governance grace = 30 days,
        //   → predicted lapse = 2026-03-31.
        // lapse_warning_days_before = 30 → fire on 2026-03-01.
        $now  = new \DateTimeImmutable('2026-03-01 09:00:00');
        $user = $this->seedUser('carla@example.test', 'Carla Doe');

        // Two consecutive OVERDUE years (2025 + 2026).
        $this->seedInvoice($user, 2025, '2025-03-01', MemberFeeInvoiceStatus::Overdue);
        $newerId = $this->seedInvoice($user, 2026, '2026-03-01', MemberFeeInvoiceStatus::Overdue);

        $out = $this->useCase->execute(new Input(now: $now), null);

        $this->assertSame(1, $out->enqueued);

        $rows = $this->fetchOutboxRowsForInvoice($newerId);
        $this->assertCount(1, $rows);
        $vars = json_decode((string) $rows[0]['payload_vars'], true);
        $this->assertIsArray($vars);
        $this->assertSame('lapse_warning', $vars['offset_tag'] ?? null);
        $this->assertSame('2026-03-31', $vars['predicted_lapse_date'] ?? null);
        $this->assertSame(MailKind::PaymentReminder->value, $rows[0]['kind']);
    }

    public function testDoesNotWarnUsersWithOnlyOneOverdueYear(): void
    {
        $now  = new \DateTimeImmutable('2026-03-01 09:00:00');
        $user = $this->seedUser('single@example.test', 'Single Overdue');
        // Only the 2026 invoice is OVERDUE; 2025 doesn't exist.
        $this->seedInvoice($user, 2026, '2026-03-01', MemberFeeInvoiceStatus::Overdue);

        $out = $this->useCase->execute(new Input(now: $now), null);

        $this->assertSame(0, $out->enqueued);
    }

    public function testIdempotentWithin24Hours(): void
    {
        $now  = new \DateTimeImmutable('2026-03-01 09:00:00');
        $user = $this->seedUser('twice@example.test', 'Double Run');
        $this->seedInvoice($user, 2025, '2025-03-01', MemberFeeInvoiceStatus::Overdue);
        $this->seedInvoice($user, 2026, '2026-03-01', MemberFeeInvoiceStatus::Overdue);

        $first = $this->useCase->execute(new Input(now: $now), null);
        $this->assertSame(1, $first->enqueued);

        $second = $this->useCase->execute(
            new Input(now: $now->modify('+6 hours')),
            null,
        );
        $this->assertSame(0, $second->enqueued, 'second run within 24h must skip');
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
            createdAt:           new \DateTimeImmutable('2025-01-01 00:00:00'),
        );
        $this->invoiceRepo->save($invoice);

        if ($status !== MemberFeeInvoiceStatus::Pending) {
            $this->conn->execute(
                'UPDATE member_fee_invoices SET status = ? WHERE id = ?',
                [$status->value, $invoice->id()->value()],
            );
        }
        return $invoice->id();
    }

    private function seedSmtpSettings(int $warnDays): void
    {
        $this->settingsRepo->save(new TenantCommunicationSettings(
            tenantId:               $this->tenantId,
            smtpDsnEncrypted:       'fake-cipher',
            mailFromAddress:        'no-reply@example.test',
            mailDisplayName:        'Daems',
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: $warnDays,
            brandLogoUrl:           null,
            brandPrimaryColor:      '#005599',
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable(),
        ));
    }

    private function seedGovernanceSettings(int $graceDays, bool $lapseEnabled): void
    {
        $this->governanceRepo->save(new TenantGovernanceSettings(
            tenantId:                       $this->tenantId,
            expulsionHearingDays:           30,
            decisionExpirationDays:         60,
            requiresFormalDecisionForFees:  false,
            defaultDueDaysFromAnniversary:  60,
            overdueGraceDays:               $graceDays,
            lapseCheckEnabled:              $lapseEnabled,
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
