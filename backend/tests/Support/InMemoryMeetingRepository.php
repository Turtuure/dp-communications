<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Meeting\Meeting;
use DaemsModule\Communications\Domain\Meeting\MeetingId;
use DaemsModule\Communications\Domain\Meeting\MeetingRepositoryInterface;

final class InMemoryMeetingRepository implements MeetingRepositoryInterface
{
    /** @var array<string, Meeting> indexed by meeting id */
    public array $byId = [];

    public function save(Meeting $meeting): void
    {
        $this->byId[$meeting->id->value()] = $meeting;
    }

    public function findById(MeetingId $id): ?Meeting
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function listForTenant(
        TenantId $tenantId,
        ?\DateTimeImmutable $startsAfter = null,
        ?\DateTimeImmutable $endsBefore = null,
    ): array {
        $out = [];
        foreach ($this->byId as $m) {
            if (!$m->tenantId->equals($tenantId)) {
                continue;
            }
            if ($startsAfter !== null && $m->startsAt < $startsAfter) {
                continue;
            }
            if ($endsBefore !== null && $m->startsAt > $endsBefore) {
                continue;
            }
            $out[] = $m;
        }
        return $out;
    }
}
