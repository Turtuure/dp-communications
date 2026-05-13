<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Audience;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

interface AudienceResolverInterface
{
    /**
     * @return list<ResolvedRecipient>
     */
    public function resolve(
        TenantId $tenant,
        AudienceFilter $filter,
        CommunicationCategory $category,
    ): array;
}
