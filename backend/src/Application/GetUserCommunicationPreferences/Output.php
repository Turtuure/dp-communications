<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetUserCommunicationPreferences;

use DaemsModule\Communications\Domain\Preference\UserCommunicationPreference;

final class Output
{
    /**
     * @param list<UserCommunicationPreference> $preferences
     */
    public function __construct(
        public readonly array $preferences,
    ) {
    }
}
