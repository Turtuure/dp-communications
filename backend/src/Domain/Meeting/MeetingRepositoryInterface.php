<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Meeting;

use Daems\Domain\Tenant\TenantId;

interface MeetingRepositoryInterface
{
    public function save(Meeting $meeting): void;

    public function findById(MeetingId $id): ?Meeting;

    /**
     * @return list<Meeting>
     */
    public function listForTenant(
        TenantId $tenantId,
        ?\DateTimeImmutable $startsAfter = null,
        ?\DateTimeImmutable $endsBefore = null,
    ): array;
}
