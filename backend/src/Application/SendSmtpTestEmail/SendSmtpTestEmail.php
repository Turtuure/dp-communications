<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SendSmtpTestEmail;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;

/**
 * Sends a hard-coded test message via the tenant's configured SMTP transport.
 * Used by the Settings page "Lähetä testiviesti" button.
 *
 * Behaviour:
 *   - Authorisation: caller must be admin in the target tenant.
 *   - Pre-check: `SmtpNotConfigured` if the tenant has no DSN saved.
 *   - On success: updates `tenant_communication_settings.smtp_test_succeeded_at`
 *     to "now", so the UI can show the timestamp.
 *   - On transport failure: the underlying `MailerTransportException` /
 *     `MailerHardBounceException` / `MailerSoftBounceException` bubbles up;
 *     the controller maps it to a 422.
 *
 * The MailOutbox built here is transient — it is NOT persisted in the
 * `mail_outbox` table because a test send is not a tenant communication.
 */
final class SendSmtpTestEmail
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TenantCommunicationSettingsRepositoryInterface $settingsRepo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $settings = $this->settingsRepo->findForTenant($input->tenantId);

        if (!$settings->isSmtpConfigured()) {
            throw new SmtpNotConfigured(
                'Cannot send test email: tenant has no SMTP DSN or mail-from address configured.',
            );
        }

        $now = new \DateTimeImmutable();

        $row = new MailOutbox(
            id:                  MailOutboxId::generate(),
            tenantId:            $input->tenantId,
            kind:                MailKind::MembershipApproved,
            category:            CommunicationCategory::Transactional,
            recipientEmail:      $input->recipientEmail,
            recipientUserId:     null,
            locale:              SupportedLocale::contentFallback(),
            subject:             '[Daems] SMTP test email',
            bodyHtml:            '<p>This is a test message sent from the Daems backstage to verify your SMTP configuration.</p>',
            bodyText:            "This is a test message sent from the Daems backstage to verify your SMTP configuration.\n",
            payloadVars:         [],
            payloadMeetingId:    null,
            payloadInvoiceId:    null,
            payloadNewsletterId: null,
            status:              MailOutboxStatus::Queued,
            attemptCount:        0,
            lastError:           null,
            queuedAt:            $now,
            sentAt:              null,
            queuedBy:            $acting->id,
        );

        $this->mailer->send($row, $settings);

        $updated = new TenantCommunicationSettings(
            tenantId:               $settings->tenantId,
            smtpDsnEncrypted:       $settings->smtpDsnEncrypted,
            mailFromAddress:        $settings->mailFromAddress,
            mailDisplayName:        $settings->mailDisplayName,
            mailReplyTo:            $settings->mailReplyTo,
            smtpTestSucceededAt:    $now,
            reminderPreDueDays:     $settings->reminderPreDueDays,
            reminderPostDueDays:    $settings->reminderPostDueDays,
            lapseWarningDaysBefore: $settings->lapseWarningDaysBefore,
            brandLogoUrl:           $settings->brandLogoUrl,
            brandPrimaryColor:      $settings->brandPrimaryColor,
            brandFooterAddress:     $settings->brandFooterAddress,
            updatedAt:              $now,
        );
        $this->settingsRepo->save($updated);

        return new Output(success: true, sentAt: $now);
    }
}
