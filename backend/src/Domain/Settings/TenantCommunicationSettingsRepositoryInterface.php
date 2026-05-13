<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Settings;

use Daems\Domain\Tenant\TenantId;

interface TenantCommunicationSettingsRepositoryInterface
{
    public function findForTenant(TenantId $tenantId): TenantCommunicationSettings;

    public function save(TenantCommunicationSettings $settings): void;
}
