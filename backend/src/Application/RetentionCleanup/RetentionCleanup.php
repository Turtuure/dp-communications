<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\RetentionCleanup;

use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;

/**
 * GDPR retention pseudonymization for mail_outbox rows older than 24 months.
 *
 * Cron-context use case (no acting user); executed weekly by
 * `bin/console mail:retention-cleanup`. The repository's
 * `pseudonymizeOlderThan` runs the UPDATE in-place: body fields are emptied,
 * recipient_email is hashed with SHA2-256, payload_vars is reset to {}, and
 * pseudonymized_at is stamped so subsequent passes skip the row.
 *
 * Spec § 7.4 + § O3 (operational defaults). 24 months = the retention period
 * the user committed to during brainstorming.
 */
final class RetentionCleanup
{
    public function __construct(
        private readonly MailOutboxRepositoryInterface $outboxRepo,
    ) {
    }

    public function execute(Input $input): Output
    {
        $cutoff = $input->cutoff ?? new \DateTimeImmutable('-24 months');
        $count = $this->outboxRepo->pseudonymizeOlderThan($cutoff);
        return new Output(pseudonymizedCount: $count);
    }
}
