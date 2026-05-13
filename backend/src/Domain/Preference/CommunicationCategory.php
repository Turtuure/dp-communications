<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Preference;

enum CommunicationCategory: string
{
    case Transactional = 'transactional';
    case Operational   = 'operational';
    case Marketing     = 'marketing';

    public function isImmutable(): bool
    {
        return $this === self::Transactional;
    }

    public function defaultOptedIn(): bool
    {
        return $this !== self::Marketing;
    }
}
