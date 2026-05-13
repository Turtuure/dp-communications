<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Meeting;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class Meeting
{
    /**
     * @param array<string, string> $titleByLocale         locale-code => title
     * @param array<string, list<string>> $agendaItemsByLocale locale-code => list of agenda lines
     * @param list<string> $documentUrls
     */
    public function __construct(
        public readonly MeetingId $id,
        public readonly TenantId $tenantId,
        public readonly MeetingType $type,
        public readonly array $titleByLocale,
        public readonly \DateTimeImmutable $startsAt,
        public readonly ?string $location,
        public readonly ?string $remoteUrl,
        public readonly array $agendaItemsByLocale,
        public readonly array $documentUrls,
        public readonly MeetingStatus $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly UserId $createdBy,
    ) {
        if ($titleByLocale === []) {
            throw new \InvalidArgumentException('Meeting requires at least one localized title');
        }
    }
}
