<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\UpdateUserCommunicationPreference;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

final class Input
{
    public function __construct(
        public readonly UserId $userId,
        public readonly TenantId $tenantId,
        public readonly CommunicationCategory $category,
        public readonly bool $optedIn,
    ) {
    }
}
