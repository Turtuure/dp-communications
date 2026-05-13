<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetUserCommunicationPreferences;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class Input
{
    public function __construct(
        public readonly UserId $userId,
        public readonly TenantId $tenantId,
    ) {
    }
}
