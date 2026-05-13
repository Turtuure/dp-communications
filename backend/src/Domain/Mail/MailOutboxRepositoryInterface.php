<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

use Daems\Domain\Tenant\TenantId;

interface MailOutboxRepositoryInterface
{
    public function save(MailOutbox $row): void;

    public function findById(MailOutboxId $id): ?MailOutbox;

    /**
     * @param array{status?: MailOutboxStatus, kind?: MailKind, from?: \DateTimeImmutable, to?: \DateTimeImmutable, recipient_substring?: string} $filters
     * @return list<MailOutbox>
     */
    public function listForTenant(TenantId $tenantId, array $filters, int $page, int $perPage): array;

    /**
     * @param array{status?: MailOutboxStatus, kind?: MailKind, from?: \DateTimeImmutable, to?: \DateTimeImmutable, recipient_substring?: string} $filters
     */
    public function countForTenant(TenantId $tenantId, array $filters): int;

    /**
     * @return list<MailOutbox>
     */
    public function pickNextForSending(int $limit): array;

    /**
     * Return every row currently in `queued` status across all tenants.
     *
     * Used by {@see \DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending\MarkSuppressedRecipientsInPending}
     * as a pre-step before draining: rows whose recipient is on their
     * tenant's suppression list are flipped to `suppressed` without being
     * sent. Returns rows ordered by `queued_at ASC` for determinism in
     * tests.
     *
     * @return list<MailOutbox>
     */
    public function listQueued(): array;

    public function markStatus(MailOutboxId $id, MailOutboxStatus $status, ?string $error = null): void;

    public function incrementAttempt(MailOutboxId $id, string $error): void;

    /**
     * Reset `attempt_count` to 0 for the given row. Used by
     * {@see \DaemsModule\Communications\Application\RetryOutboxRow\RetryOutboxRow}
     * so a `Failed` row that is re-queued by an admin starts the retry
     * counter clean rather than tripping the soft-bounce backoff again on
     * the first re-send.
     */
    public function resetAttemptCount(MailOutboxId $id): void;
}
