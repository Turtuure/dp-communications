<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Preference;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface UserCommunicationPreferenceRepositoryInterface
{
    /**
     * @return list<UserCommunicationPreference>
     */
    public function findFor(UserId $userId, TenantId $tenantId): array;

    public function setFor(
        UserId $userId,
        TenantId $tenantId,
        CommunicationCategory $category,
        bool $optedIn,
    ): void;

    /**
     * @return list<CommunicationCategory>
     */
    public function categoriesAllowing(UserId $userId, TenantId $tenantId): array;
}
