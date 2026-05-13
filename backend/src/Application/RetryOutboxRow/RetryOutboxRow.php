<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\RetryOutboxRow;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;

/**
 * Re-queue a `Failed` outbox row so the next cron drain re-attempts delivery.
 *
 * Auth: admin in the row's tenant (or platform admin).
 * Pre-condition: row's current status must be `Failed`; any other status
 * throws `\DomainException` so the caller can surface a 409.
 *
 * Effect: `status = Queued`, `attempt_count = 0`, `last_error = NULL`.
 * Implemented as a `markStatus(Queued, null)` followed by an
 * `resetAttemptCount()` call because the repository contract intentionally
 * exposes the two columns through separate write methods.
 */
final class RetryOutboxRow
{
    public function __construct(
        private readonly MailOutboxRepositoryInterface $outboxRepo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        $row = $this->outboxRepo->findById($input->rowId);
        if ($row === null) {
            throw new \DomainException('Outbox row not found: ' . $input->rowId->value());
        }

        if (!$acting->isAdminIn($row->tenantId)) {
            throw new ForbiddenException();
        }

        if ($row->status !== MailOutboxStatus::Failed) {
            throw new \DomainException(sprintf(
                'Row not retryable in status %s',
                $row->status->value,
            ));
        }

        $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Queued, null);
        $this->outboxRepo->resetAttemptCount($row->id);

        return new Output(success: true);
    }
}
