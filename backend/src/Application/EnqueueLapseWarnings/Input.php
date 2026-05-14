<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\EnqueueLapseWarnings;

/**
 * Cron-side input for the daily lapse-warning pass.
 *
 * `$now` is overridable so integration tests can pin "today" without
 * touching the system clock.
 */
final class Input
{
    public function __construct(
        public readonly ?\DateTimeImmutable $now = null,
    ) {}
}
