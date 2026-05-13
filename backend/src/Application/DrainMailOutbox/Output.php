<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\DrainMailOutbox;

final class Output
{
    public function __construct(
        public readonly int $sent,
        public readonly int $bounced,
        public readonly int $failed,
        public readonly int $retried,
    ) {
    }
}
