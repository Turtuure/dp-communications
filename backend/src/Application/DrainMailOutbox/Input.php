<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\DrainMailOutbox;

final class Input
{
    public function __construct(
        public readonly ?int $batchSize = null,
    ) {
    }
}
