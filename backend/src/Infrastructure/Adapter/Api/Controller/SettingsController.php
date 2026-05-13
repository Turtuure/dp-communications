<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Adapter\Api\Controller;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Application\GetCommunicationSettings\Input as GetInput;
use DaemsModule\Communications\Application\SaveCommunicationSettings\Input as SaveInput;
use DaemsModule\Communications\Application\SaveCommunicationSettings\SaveCommunicationSettings;
use DaemsModule\Communications\Application\SendSmtpTestEmail\Input as TestInput;
use DaemsModule\Communications\Application\SendSmtpTestEmail\SendSmtpTestEmail;
use DaemsModule\Communications\Domain\Mail\Exception\MailerHardBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerSoftBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerTransportException;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;

/**
 * Backstage Communications Settings HTTP controller (Wave C8).
 *
 * Routes (registered in modules/communications/backend/routes.php):
 *   GET  /api/v1/backstage/communications/settings           → show
 *   PUT  /api/v1/backstage/communications/settings           → update
 *   POST /api/v1/backstage/communications/settings/smtp-test → testSmtp
 *
 * The DSN is treated as a secret: GET returns the literal `***` placeholder
 * for non-GSA callers and the boolean `dsn_configured` so the UI can show
 * "configured" without ever exposing ciphertext.
 *
 * SMTP transport failures (RuntimeException subclasses, not DomainException)
 * are caught here and surfaced as 422 — the Kernel's global mapping doesn't
 * know about them.
 */
final class SettingsController
{
    public function __construct(
        private readonly GetCommunicationSettings $getSettings,
        private readonly SaveCommunicationSettings $saveSettings,
        private readonly SendSmtpTestEmail $sendTest,
    ) {
    }

    public function show(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $output = $this->getSettings->execute(new GetInput($tenantId), $acting);

        return Response::json([
            'data' => $this->serializeSettings(
                $output->settings,
                $output->dsnIsConfigured,
                $output->dsnMasked,
            ),
        ]);
    }

    public function update(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $plainDsn = $this->trimmedString($request->string('smtp_dsn'));
        // Sentinel "***" means "no change — keep current DSN". Any other
        // non-empty value is treated as a fresh DSN and re-encrypted.
        if ($plainDsn === GetCommunicationSettings::MASKED_DSN) {
            $plainDsn = null;
        }

        $reminderPostDueDays = $this->parsePostDueDays($request);

        $input = new SaveInput(
            tenantId:               $tenantId,
            plainSmtpDsn:           $plainDsn,
            mailFromAddress:        $this->trimmedString($request->string('mail_from_address')),
            mailDisplayName:        $this->trimmedString($request->string('mail_display_name')),
            mailReplyTo:            $this->trimmedString($request->string('mail_reply_to')),
            reminderPreDueDays:     $request->int('reminder_pre_due_days'),
            reminderPostDueDays:    $reminderPostDueDays,
            lapseWarningDaysBefore: $request->int('lapse_warning_days_before'),
            brandLogoUrl:           $this->trimmedString($request->string('brand_logo_url')),
            brandPrimaryColor:      $this->trimmedString($request->string('brand_primary_color')),
            brandFooterAddress:     $this->trimmedString($request->string('brand_footer_address')),
        );

        $output = $this->saveSettings->execute($input, $acting);

        return Response::json(['data' => ['success' => $output->success]]);
    }

    public function testSmtp(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $recipient = $this->trimmedString($request->string('recipient_email'));
        if ($recipient === null || $recipient === '') {
            // Default to the acting user's own address — the test only makes
            // sense if the operator can read the inbox they're targeting.
            $recipient = $acting->email;
        }

        try {
            $output = $this->sendTest->execute(
                new TestInput($tenantId, $recipient),
                $acting,
            );
        } catch (SmtpNotConfigured $e) {
            return Response::json(
                ['error' => 'smtp_not_configured', 'message' => $e->getMessage()],
                422,
            );
        } catch (MailerHardBounceException | MailerSoftBounceException | MailerTransportException $e) {
            return Response::json(
                ['error' => 'smtp_send_failed', 'message' => $e->getMessage()],
                422,
            );
        }

        return Response::json([
            'data' => [
                'success' => $output->success,
                'sent_at' => $output->sentAt->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSettings(
        TenantCommunicationSettings $settings,
        bool $dsnIsConfigured,
        bool $dsnMasked,
    ): array {
        // Never echo the raw ciphertext blob. For GSAs `smtpDsnEncrypted` is
        // already the actual encrypted value, but exposing that to the JSON
        // wire would leak the cipher format — show the same "***" sentinel
        // for both audiences. The `dsn_configured` flag conveys whether a
        // DSN exists; the page form treats "***" as "unchanged" on PUT.
        $dsnValue = $dsnIsConfigured ? GetCommunicationSettings::MASKED_DSN : null;

        return [
            'tenant_id'                  => $settings->tenantId->value(),
            'smtp_dsn'                   => $dsnValue,
            'dsn_configured'             => $dsnIsConfigured,
            'dsn_masked'                 => $dsnMasked,
            'mail_from_address'          => $settings->mailFromAddress,
            'mail_display_name'          => $settings->mailDisplayName,
            'mail_reply_to'              => $settings->mailReplyTo,
            'smtp_test_succeeded_at'     => $settings->smtpTestSucceededAt?->format(\DateTimeInterface::ATOM),
            'reminder_pre_due_days'      => $settings->reminderPreDueDays,
            'reminder_post_due_days'     => $settings->reminderPostDueDays,
            'lapse_warning_days_before'  => $settings->lapseWarningDaysBefore,
            'brand_logo_url'             => $settings->brandLogoUrl,
            'brand_primary_color'        => $settings->brandPrimaryColor,
            'brand_footer_address'       => $settings->brandFooterAddress,
            'updated_at'                 => $settings->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Parse the post-due days input. Accepts either:
     *   - array<int|string> from JSON: [14, 30]
     *   - comma-separated string: "14,30"
     * Returns null if the field is absent so SaveCommunicationSettings
     * keeps the current value untouched.
     *
     * @return list<int>|null
     */
    private function parsePostDueDays(Request $request): ?array
    {
        $raw = $request->arrayValue('reminder_post_due_days');
        if ($raw === null) {
            $str = $request->string('reminder_post_due_days');
            if ($str === null || trim($str) === '') {
                return null;
            }
            $raw = array_filter(array_map('trim', explode(',', $str)), static fn(string $p) => $p !== '');
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_int($entry)) {
                $out[] = $entry;
                continue;
            }
            if (is_string($entry) && is_numeric($entry)) {
                $out[] = (int) $entry;
                continue;
            }
            throw new \InvalidArgumentException('reminder_post_due_days entries must be integers.');
        }
        return $out;
    }

    private function trimmedString(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    private function resolveTenantId(Request $request, TenantId $fallback): TenantId
    {
        $tenant = $request->attribute('tenant');
        if ($tenant instanceof Tenant) {
            return $tenant->id;
        }
        return $fallback;
    }
}
