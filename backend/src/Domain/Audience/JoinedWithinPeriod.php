<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Audience;

enum JoinedWithinPeriod: string
{
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';
    case Last1Year  = 'last_1_year';

    public function days(): int
    {
        return match ($this) {
            self::Last30Days => 30,
            self::Last90Days => 90,
            self::Last1Year  => 365,
        };
    }
}
