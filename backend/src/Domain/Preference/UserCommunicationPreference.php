<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Preference;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class UserCommunicationPreference
{
    public function __construct(
        public readonly UserId $userId,
        public readonly TenantId $tenantId,
        public readonly CommunicationCategory $category,
        public readonly bool $optedIn,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
