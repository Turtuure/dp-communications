<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\CreateMeetingFromComposer;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Meeting\MeetingType;

final class Input
{
    /**
     * @param array<string, string>        $titleByLocale       e.g. ['fi_FI' => 'Vuosikokous 2026']
     * @param array<string, list<string>>  $agendaItemsByLocale e.g. ['fi_FI' => ['1. Avaus', '2. ...']]
     * @param list<string>                 $documentUrls
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly MeetingType $type,
        public readonly array $titleByLocale,
        public readonly \DateTimeImmutable $startsAt,
        public readonly ?string $location,
        public readonly ?string $remoteUrl,
        public readonly array $agendaItemsByLocale,
        public readonly array $documentUrls,
    ) {
    }
}
