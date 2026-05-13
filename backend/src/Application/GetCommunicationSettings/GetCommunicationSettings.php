<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetCommunicationSettings;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;

final class GetCommunicationSettings
{
    public const MASKED_DSN = '***';

    public function __construct(
        private readonly TenantCommunicationSettingsRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $settings = $this->repo->findForTenant($input->tenantId);
        $dsnIsConfigured = $settings->smtpDsnEncrypted !== null;

        // Only GSAs see the raw encrypted DSN. Tenant admins get the masked placeholder
        // so the page can show "configured" / "not configured" without leaking ciphertext.
        if (!$acting->isPlatformAdmin && $dsnIsConfigured) {
            $masked = new TenantCommunicationSettings(
                tenantId:               $settings->tenantId,
                smtpDsnEncrypted:       self::MASKED_DSN,
                mailFromAddress:        $settings->mailFromAddress,
                mailDisplayName:        $settings->mailDisplayName,
                mailReplyTo:            $settings->mailReplyTo,
                smtpTestSucceededAt:    $settings->smtpTestSucceededAt,
                reminderPreDueDays:     $settings->reminderPreDueDays,
                reminderPostDueDays:    $settings->reminderPostDueDays,
                lapseWarningDaysBefore: $settings->lapseWarningDaysBefore,
                brandLogoUrl:           $settings->brandLogoUrl,
                brandPrimaryColor:      $settings->brandPrimaryColor,
                brandFooterAddress:     $settings->brandFooterAddress,
                updatedAt:              $settings->updatedAt,
            );

            return new Output(
                settings:        $masked,
                dsnMasked:       true,
                dsnIsConfigured: true,
            );
        }

        return new Output(
            settings:        $settings,
            dsnMasked:       false,
            dsnIsConfigured: $dsnIsConfigured,
        );
    }
}
