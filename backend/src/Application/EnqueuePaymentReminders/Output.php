<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\EnqueuePaymentReminders;

/**
 * Result of one EnqueuePaymentReminders pass.
 *
 *  - `enqueued`        – total outbox rows queued this run
 *  - `preDue`          – of those, how many were pre-due (status=PENDING)
 *  - `postDue`         – of those, how many were post-due (status=OVERDUE)
 *  - `tenantsConsidered` – how many tenants were visited (suspended skipped)
 *  - `tenantsSkippedNoSmtp` – tenants where SMTP wasn't configured yet
 */
final class Output
{
    public function __construct(
        public readonly int $enqueued,
        public readonly int $preDue,
        public readonly int $postDue,
        public readonly int $tenantsConsidered,
        public readonly int $tenantsSkippedNoSmtp,
    ) {}
}
