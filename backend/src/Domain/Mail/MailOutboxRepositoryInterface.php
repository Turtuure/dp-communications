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

    public function markStatus(MailOutboxId $id, MailOutboxStatus $status, ?string $error = null): void;

    public function incrementAttempt(MailOutboxId $id, string $error): void;
}
