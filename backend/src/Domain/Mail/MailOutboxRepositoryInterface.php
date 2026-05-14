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

    /**
     * Idempotency probe for the F1 + F2 cron use cases: return TRUE if any
     * outbox row exists for `(tenant, invoice_id)` whose `payload_vars`
     * contains the literal `"offset_tag":"<tag>"` AND whose `queued_at` is
     * within the last `$hoursWindow` hours.
     *
     * The lookup avoids re-enqueueing a pre-due / post-due / lapse-warning
     * reminder for the same invoice when the cron runs twice in the same
     * day (e.g. Sat double-tick after a missed Fri tick).
     */
    public function existsRecentInvoiceReminder(
        \Daems\Domain\Tenant\TenantId $tenantId,
        \Daems\Domain\Membership\Billing\MemberFeeInvoiceId $invoiceId,
        string $offsetTag,
        int $hoursWindow,
        \DateTimeImmutable $now,
    ): bool;
}
