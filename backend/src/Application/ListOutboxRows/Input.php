<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListOutboxRows;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;

final class Input
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly int $page = 1,
        public readonly int $perPage = 50,
        public readonly ?MailOutboxStatus $status = null,
        public readonly ?MailKind $kind = null,
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
        public readonly ?string $recipientSubstring = null,
    ) {
    }
}
