<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\RetentionCleanup;

final class Output
{
    public function __construct(
        public readonly int $pseudonymizedCount,
    ) {
    }
}
