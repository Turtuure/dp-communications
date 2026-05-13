<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SaveCommunicationSettings;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;

final class SaveCommunicationSettings
{
    public function __construct(
        private readonly TenantCommunicationSettingsRepositoryInterface $repo,
        private readonly DsnEncryptor $encryptor,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $current = $this->repo->findForTenant($input->tenantId);

        // Encrypt the new DSN if provided; otherwise keep the current ciphertext.
        $newDsnEnc = $input->plainSmtpDsn !== null
            ? $this->encryptor->encrypt($input->plainSmtpDsn)
            : $current->smtpDsnEncrypted;

        // Whenever the DSN changes (= caller supplied plainSmtpDsn), the
        // "last successful smtp test" timestamp MUST reset to null: the new
        // credentials are untested.
        $testAt = $input->plainSmtpDsn !== null
            ? null
            : $current->smtpTestSucceededAt;

        $updated = new TenantCommunicationSettings(
            tenantId:               $input->tenantId,
            smtpDsnEncrypted:       $newDsnEnc,
            mailFromAddress:        $input->mailFromAddress ?? $current->mailFromAddress,
            mailDisplayName:        $input->mailDisplayName ?? $current->mailDisplayName,
            mailReplyTo:            $input->mailReplyTo ?? $current->mailReplyTo,
            smtpTestSucceededAt:    $testAt,
            reminderPreDueDays:     $input->reminderPreDueDays ?? $current->reminderPreDueDays,
            reminderPostDueDays:    $input->reminderPostDueDays ?? $current->reminderPostDueDays,
            lapseWarningDaysBefore: $input->lapseWarningDaysBefore ?? $current->lapseWarningDaysBefore,
            brandLogoUrl:           $input->brandLogoUrl ?? $current->brandLogoUrl,
            brandPrimaryColor:      $input->brandPrimaryColor ?? $current->brandPrimaryColor,
            brandFooterAddress:     $input->brandFooterAddress ?? $current->brandFooterAddress,
            updatedAt:              new \DateTimeImmutable(),
        );

        $this->repo->save($updated);

        return new Output(success: true);
    }
}
