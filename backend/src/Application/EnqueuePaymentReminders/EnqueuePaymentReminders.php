<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\EnqueuePaymentReminders;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
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
 * Daily reminder cron (Milestone 0.8 Wave F Task F1; spec § 5.9).
 *
 * For each non-suspended tenant with SMTP configured:
 *
 *   - PRE-DUE pass: invoices whose `due_date = now + pre_due_days` and
 *     status = PENDING → enqueue a `payment_reminder` outbox row tagged
 *     `offset_tag = "pre_due"`.
 *   - POST-DUE pass: for each integer `N` in `reminder_post_due_days`,
 *     invoices whose `due_date = now - N` and status = OVERDUE → enqueue a
 *     row tagged `offset_tag = "post_due_<N>"`.
 *
 * Both passes are idempotent within a 24h window via
 * `MailOutboxRepositoryInterface::existsRecentInvoiceReminder()` so the same
 * cron firing twice in a day cannot double-send.
 *
 * Suppressed recipients (hard-bounced + manually unsubscribed) are silently
 * skipped before render.
 *
 * The use case is cron-driven: `$acting` is nullable. The `queued_by` slot
 * on the resulting outbox row falls back to the GSA fallback id resolved
 * from the `users` table (any platform-admin user) so the FK is satisfied.
 */
final class EnqueuePaymentReminders
{
    private const IDEMPOTENCY_WINDOW_HOURS = 24;

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantCommunicationSettingsRepositoryInterface $settingsRepo,
        private readonly MemberFeeInvoiceRepositoryInterface $invoiceRepo,
        private readonly MailOutboxRepositoryInterface $outboxRepo,
        private readonly MailSuppressionRepositoryInterface $suppressionRepo,
        private readonly MailTemplateRepositoryInterface $templateRepo,
        private readonly EmailHtmlRenderer $renderer,
        private readonly Connection $db,
    ) {}

    public function execute(Input $input, ?ActingUser $acting = null): Output
    {
        $now = $input->now ?? new \DateTimeImmutable();

        $enqueued     = 0;
        $preDue       = 0;
        $postDue      = 0;
        $considered   = 0;
        $skippedSmtp  = 0;

        foreach ($this->tenants->findAll() as $tenant) {
            if ($tenant->suspended()) {
                continue;
            }
            $considered++;
            $settings = $this->settingsRepo->findForTenant($tenant->id);
            if (!$settings->isSmtpConfigured()) {
                $skippedSmtp++;
                continue;
            }

            // ---- PRE-DUE: due in `pre_due_days` days, status=PENDING ------
            $preDueDate = $now->modify('+' . max(0, $settings->reminderPreDueDays) . ' days');
            foreach ($this->invoiceRepo->findInvoicesDueOn(
                $tenant->id,
                $preDueDate,
                [MemberFeeInvoiceStatus::Pending],
            ) as $invoice) {
                if ($this->enqueueOne(
                    $tenant,
                    $settings,
                    $invoice,
                    offsetTag: 'pre_due',
                    now: $now,
                )) {
                    $preDue++;
                    $enqueued++;
                }
            }

            // ---- POST-DUE: due N days ago, status=OVERDUE, for each N -----
            foreach ($settings->reminderPostDueDays as $offsetDays) {
                if ($offsetDays < 1) {
                    continue;
                }
                $postDueDate = $now->modify('-' . $offsetDays . ' days');
                foreach ($this->invoiceRepo->findInvoicesDueOn(
                    $tenant->id,
                    $postDueDate,
                    [MemberFeeInvoiceStatus::Overdue],
                ) as $invoice) {
                    if ($this->enqueueOne(
                        $tenant,
                        $settings,
                        $invoice,
                        offsetTag: 'post_due_' . $offsetDays,
                        now: $now,
                    )) {
                        $postDue++;
                        $enqueued++;
                    }
                }
            }
        }

        return new Output(
            enqueued:             $enqueued,
            preDue:               $preDue,
            postDue:              $postDue,
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
        MemberFeeInvoice $invoice,
        string $offsetTag,
        \DateTimeImmutable $now,
    ): bool {
        // Idempotency — same (invoice, offset_tag) within 24h => skip.
        if ($this->outboxRepo->existsRecentInvoiceReminder(
            $tenant->id,
            $invoice->id(),
            $offsetTag,
            self::IDEMPOTENCY_WINDOW_HOURS,
            $now,
        )) {
            return false;
        }

        $recipient = $this->loadRecipient($invoice->userId());
        if ($recipient === null) {
            return false;
        }
        [$email, $name] = $recipient;

        if ($this->suppressionRepo->isSuppressed($tenant->id, $email)) {
            return false;
        }

        $locale = SupportedLocale::fromString($tenant->defaultLocale());

        $vars = [
            'first_name'           => $this->firstNameFrom($name),
            'locale'               => $locale->value(),
            'tenant_name'          => $settings->mailDisplayName ?? $tenant->displayName($locale->value()),
            'invoice_year'         => (string) $invoice->year(),
            'amount'               => number_format($invoice->amountCents() / 100, 2, '.', ' '),
            'currency'             => $invoice->currency(),
            'due_date'             => $invoice->dueDate()->format('Y-m-d'),
            'payment_link'         => '',
            'brand_primary_color'  => $settings->brandPrimaryColor ?? '',
            'brand_logo_url'       => $settings->brandLogoUrl ?? '',
            'brand_footer_address' => $settings->brandFooterAddress ?? '',
            // Idempotency probe key — must round-trip through payload_vars
            // so existsRecentInvoiceReminder can find it in JSON_EXTRACT.
            'offset_tag'           => $offsetTag,
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
            templateVariant: 'payment_reminder',
        );

        $subject = trim($stringOverrides['subject'] ?? '');
        if ($subject === '') {
            $subject = 'Jäsenmaksumuistutus';
        }

        $row = new MailOutbox(
            id:                  MailOutboxId::generate(),
            tenantId:            $tenant->id,
            kind:                MailKind::PaymentReminder,
            category:            MailKind::PaymentReminder->category(),
            recipientEmail:      $email,
            recipientUserId:     $invoice->userId(),
            locale:              $locale,
            subject:             $subject,
            bodyHtml:            $html,
            bodyText:            $text,
            payloadVars:         $vars,
            payloadMeetingId:    null,
            payloadInvoiceId:    $invoice->id(),
            payloadNewsletterId: null,
            status:              MailOutboxStatus::Queued,
            attemptCount:        0,
            lastError:           null,
            queuedAt:            $now,
            sentAt:              null,
            queuedBy:            $this->resolveCronActorId($invoice->userId()),
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

    /**
     * Pick a stable `queued_by` UserId for cron-driven outbox rows.
     *
     * `mail_outbox.queued_by` has an FK on `users.id`. The cron has no
     * acting user, so we re-use the invoice owner — they always exist
     * (the loadRecipient() lookup just succeeded for them).
     */
    private function resolveCronActorId(UserId $invoiceUser): UserId
    {
        return $invoiceUser;
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
