<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\EnqueueLapseWarnings;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;

/**
 * Daily § 4 lapse-warning cron (Milestone 0.8 Wave F Task F2; spec § 5.9).
 *
 * Predicts when the 0.7 `membership:lapse-inactive-members` cron will flip
 * a user to `lapsed` and enqueues a pre-warning N days ahead, where
 * `N = TenantCommunicationSettings.lapse_warning_days_before` (default 30).
 *
 * Heuristic — keeping the 0.8 implementation simple by design (per plan F2
 * "a simpler approximation is acceptable as long as it (a) doesn't false-
 * positive (warn already-good users), (b) doesn't double-send within 24h"):
 *
 *   1. Per tenant, fetch each user with two consecutive OVERDUE years from
 *      `MemberFeeInvoiceRepository::findUsersWithConsecutiveOverdueYears()`.
 *      These are the exact users the 0.7 lapse cron will lapse on its next
 *      eligible tick (no false positives).
 *   2. Predict the lapse-trigger date as the *newer* invoice's
 *      `due_date + overdue_grace_days` — i.e. the moment that invoice
 *      itself became OVERDUE in MarkOverdueInvoices' view. By the time
 *      both years are OVERDUE the trigger has effectively armed; we
 *      backdate the warning N days from that arming moment.
 *   3. Fire the warning when `predicted_lapse - now == lapse_warning_days_before`
 *      (date-only match). Idempotent within 24h via `offset_tag=lapse_warning`.
 *
 * Total outstanding (`outstanding_total`) is rendered into the template
 * as the sum of all open (PENDING|OVERDUE|REDUCED) invoices for the user
 * within the tenant.
 */
final class EnqueueLapseWarnings
{
    private const IDEMPOTENCY_WINDOW_HOURS = 24;
    private const OFFSET_TAG               = 'lapse_warning';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantCommunicationSettingsRepositoryInterface $settingsRepo,
        private readonly TenantGovernanceSettingsRepositoryInterface $governanceRepo,
        private readonly MemberFeeInvoiceRepositoryInterface $invoiceRepo,
        private readonly MailOutboxRepositoryInterface $outboxRepo,
        private readonly MailSuppressionRepositoryInterface $suppressionRepo,
        private readonly MailTemplateRepositoryInterface $templateRepo,
        private readonly EmailHtmlRenderer $renderer,
        private readonly Connection $db,
    ) {}

    public function execute(Input $input, ?ActingUser $acting = null): Output
    {
        $now         = $input->now ?? new \DateTimeImmutable();
        $today       = new \DateTimeImmutable($now->format('Y-m-d'));
        $enqueued    = 0;
        $considered  = 0;
        $skippedSmtp = 0;

        foreach ($this->tenants->findAll() as $tenant) {
            if ($tenant->suspended()) {
                continue;
            }
            $considered++;
            $commSettings = $this->settingsRepo->findForTenant($tenant->id);
            if (!$commSettings->isSmtpConfigured()) {
                $skippedSmtp++;
                continue;
            }
            $govSettings  = $this->governanceRepo->find($tenant->id);
            $graceDays    = $govSettings?->overdueGraceDays() ?? 30;
            $warnDays     = max(0, $commSettings->lapseWarningDaysBefore);

            $candidates = $this->invoiceRepo->findUsersWithConsecutiveOverdueYears($tenant->id);
            foreach ($candidates as $candidate) {
                /** @var UserId $userId */
                $userId    = $candidate['user_id'];
                /** @var list<int> $years */
                $years     = $candidate['years'];
                if ($years === []) {
                    continue;
                }
                $newerYear = max($years);

                $newerInvoice = $this->invoiceRepo->findFor($tenant->id, $userId, $newerYear);
                if ($newerInvoice === null) {
                    continue;
                }
                $predictedLapse = new \DateTimeImmutable(
                    $newerInvoice->dueDate()
                        ->modify('+' . $graceDays . ' days')
                        ->format('Y-m-d'),
                );
                $daysUntil = (int) $today->diff($predictedLapse)->format('%r%a');
                if ($daysUntil !== $warnDays) {
                    continue;
                }

                if ($this->enqueueOne(
                    $tenant,
                    $commSettings,
                    $newerInvoice,
                    $userId,
                    $predictedLapse,
                    $now,
                )) {
                    $enqueued++;
                }
            }
        }

        return new Output(
            enqueued:             $enqueued,
            tenantsConsidered:    $considered,
            tenantsSkippedNoSmtp: $skippedSmtp,
        );
    }

    /**
     * @return bool TRUE when a row was enqueued, FALSE when skipped
     *              (idempotency hit, recipient suppressed, or user missing).
     */
    private function enqueueOne(
        Tenant $tenant,
        TenantCommunicationSettings $settings,
        MemberFeeInvoice $newerInvoice,
        UserId $userId,
        \DateTimeImmutable $predictedLapse,
        \DateTimeImmutable $now,
    ): bool {
        if ($this->outboxRepo->existsRecentInvoiceReminder(
            $tenant->id,
            $newerInvoice->id(),
            self::OFFSET_TAG,
            self::IDEMPOTENCY_WINDOW_HOURS,
            $now,
        )) {
            return false;
        }

        $recipient = $this->loadRecipient($userId);
        if ($recipient === null) {
            return false;
        }
        [$email, $name] = $recipient;

        if ($this->suppressionRepo->isSuppressed($tenant->id, $email)) {
            return false;
        }

        $locale            = SupportedLocale::fromString($tenant->defaultLocale());
        $openInvoices      = $this->invoiceRepo->listOpenForUser($tenant->id, $userId);
        $outstandingCents  = 0;
        foreach ($openInvoices as $open) {
            $outstandingCents += $open->amountCents();
        }

        $vars = [
            'first_name'           => $this->firstNameFrom($name),
            'locale'               => $locale->value(),
            'tenant_name'          => $settings->mailDisplayName ?? $tenant->displayName($locale->value()),
            'invoice_year'         => (string) $newerInvoice->year(),
            'amount'               => number_format($newerInvoice->amountCents() / 100, 2, '.', ' '),
            'currency'             => $newerInvoice->currency(),
            'due_date'             => $newerInvoice->dueDate()->format('Y-m-d'),
            'payment_link'         => '',
            'predicted_lapse_date' => $predictedLapse->format('Y-m-d'),
            'outstanding_total'    => number_format($outstandingCents / 100, 2, '.', ' '),
            'brand_primary_color'  => $settings->brandPrimaryColor ?? '',
            'brand_logo_url'       => $settings->brandLogoUrl ?? '',
            'brand_footer_address' => $settings->brandFooterAddress ?? '',
            'offset_tag'           => self::OFFSET_TAG,
        ];

        $overrides = $this->templateRepo->findOverrides(
            $tenant->id,
            MailKind::PaymentReminder,
            $locale,
        );
        $stringOverrides = $overrides !== null ? $overrides->stringOverrides : [];

        [$html, $text] = $this->renderer->render(
            MailKind::PaymentReminder,
            $vars,
            $locale,
            $stringOverrides,
            templateVariant: 'lapse_warning',
        );

        $subject = trim($stringOverrides['subject'] ?? '');
        if ($subject === '') {
            $subject = 'Jäsenyytesi on vaarassa erota § 4 mukaan';
        }

        $row = new MailOutbox(
            id:                  MailOutboxId::generate(),
            tenantId:            $tenant->id,
            kind:                MailKind::PaymentReminder,
            category:            MailKind::PaymentReminder->category(),
            recipientEmail:      $email,
            recipientUserId:     $userId,
            locale:              $locale,
            subject:             $subject,
            bodyHtml:            $html,
            bodyText:            $text,
            payloadVars:         $vars,
            payloadMeetingId:    null,
            payloadInvoiceId:    $newerInvoice->id(),
            payloadNewsletterId: null,
            status:              MailOutboxStatus::Queued,
            attemptCount:        0,
            lastError:           null,
            queuedAt:            $now,
            sentAt:              null,
            queuedBy:            $userId,
        );
        $this->outboxRepo->save($row);
        return true;
    }

    /**
     * @return array{0:string,1:string}|null  [email, name] or null when user is gone.
     */
    private function loadRecipient(UserId $userId): ?array
    {
        $row = $this->db->queryOne(
            'SELECT email, name FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$userId->value()],
        );
        if ($row === null) {
            return null;
        }
        $email = $row['email'] ?? null;
        $name  = $row['name']  ?? null;
        if (!is_string($email) || $email === '') {
            return null;
        }
        return [$email, is_string($name) ? $name : ''];
    }

    private function firstNameFrom(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $trimmed, 2);
        if ($parts === false) {
            return $trimmed;
        }
        return $parts[0];
    }
}
