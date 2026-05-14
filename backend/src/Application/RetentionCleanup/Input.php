<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\RetentionCleanup;

final class Input
{
    public function __construct(
        public readonly ?\DateTimeImmutable $cutoff = null,
    ) {
    }
}
