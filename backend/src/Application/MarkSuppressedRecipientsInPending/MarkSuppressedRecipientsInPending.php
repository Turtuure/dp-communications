<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending;

use Daems\Domain\Auth\ActingUser;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;

/**
 * Scans every `queued` mail-outbox row; for rows whose recipient is on
 * their tenant's suppression list, flips the row's status to `suppressed`
 * with the reason `recipient on suppression list`.
 *
 * Runs:
 *   1. As a pre-step inside {@see \DaemsModule\Communications\Application\DrainMailOutbox\DrainMailOutbox}
 *      before the next batch is picked, so a recipient added to the
 *      suppression list AFTER queueing never gets sent.
 *   2. Optionally on demand from a backstage admin action.
 *
 * `$acting` is nullable because this is a system/cron use case — no auth
 * check is required. Per-tenant correctness is enforced by the
 * `MailSuppression` rows themselves being tenant-scoped.
 */
final class MarkSuppressedRecipientsInPending
{
    public function __construct(
        private readonly MailOutboxRepositoryInterface $outboxRepo,
        private readonly MailSuppressionRepositoryInterface $suppressionRepo,
    ) {
    }

    public function execute(Input $input, ?ActingUser $acting = null): Output
    {
        $marked = 0;
        foreach ($this->outboxRepo->listQueued() as $row) {
            if ($this->suppressionRepo->isSuppressed($row->tenantId, $row->recipientEmail)) {
                $this->outboxRepo->markStatus(
                    $row->id,
                    MailOutboxStatus::Suppressed,
                    'recipient on suppression list',
                );
                $marked++;
            }
        }

        return new Output($marked);
    }
}
