<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\RetryOutboxRow;

final class Output
{
    public function __construct(
        public readonly bool $success,
    ) {
    }
}
