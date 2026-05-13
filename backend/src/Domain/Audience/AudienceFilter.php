<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Audience;

final class AudienceFilter
{
    /**
     * @param list<string> $membershipTypes     e.g. ['regular','student','honorary']
     * @param list<string> $locales             e.g. ['fi_FI','en_GB']
     * @param list<string> $applicationStatuses e.g. ['approved','pending']
     */
    public function __construct(
        public readonly array $membershipTypes,
        public readonly array $locales,
        public readonly ?JoinedWithinPeriod $joinedWithin,
        public readonly array $applicationStatuses,
    ) {
    }
}
