<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\EnqueueLapseWarnings;

/**
 * Result of one EnqueueLapseWarnings pass.
 */
final class Output
{
    public function __construct(
        public readonly int $enqueued,
        public readonly int $tenantsConsidered,
        public readonly int $tenantsSkippedNoSmtp,
    ) {}
}
