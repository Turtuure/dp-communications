<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\AddManualSuppression;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;

final class Input
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $emailAddress,
        public readonly SuppressionReason $reason = SuppressionReason::ManualBlock,
    ) {
    }
}
