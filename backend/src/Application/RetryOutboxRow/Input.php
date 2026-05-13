<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\RetryOutboxRow;

use DaemsModule\Communications\Domain\Mail\MailOutboxId;

final class Input
{
    public function __construct(
        public readonly MailOutboxId $rowId,
    ) {
    }
}
