<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SendNewsletter;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\NewsletterId;

final class Input
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly NewsletterId $newsletterId,
    ) {
    }
}
