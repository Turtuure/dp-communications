<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListNewsletters;

use Daems\Domain\Tenant\TenantId;

final class Input
{
    public function __construct(
        public readonly TenantId $tenantId,
    ) {
    }
}
