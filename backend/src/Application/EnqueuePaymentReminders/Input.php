<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\EnqueuePaymentReminders;

/**
 * Cron-side input for the daily payment-reminder pass.
 *
 * `$now` is overridable so integration tests can pin the "today" anchor
 * without needing to muck with the system clock — production injects
 * `null` so the use case stamps `new \DateTimeImmutable()` at start.
 */
final class Input
{
    public function __construct(
        public readonly ?\DateTimeImmutable $now = null,
    ) {}
}
