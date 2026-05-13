<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;

final class InMemoryTenantCommunicationSettingsRepository implements TenantCommunicationSettingsRepositoryInterface
{
    /** @var array<string, TenantCommunicationSettings> indexed by tenant id */
    public array $byTenant = [];

    public function findForTenant(TenantId $tenantId): TenantCommunicationSettings
    {
        return $this->byTenant[$tenantId->value()] ?? new TenantCommunicationSettings(
            tenantId:               $tenantId,
            smtpDsnEncrypted:       null,
            mailFromAddress:        null,
            mailDisplayName:        null,
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      null,
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable('now'),
        );
    }

    public function save(TenantCommunicationSettings $settings): void
    {
        $this->byTenant[$settings->tenantId->value()] = $settings;
    }
}
