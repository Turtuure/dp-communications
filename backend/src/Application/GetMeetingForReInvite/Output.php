<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetMeetingForReInvite;

use DaemsModule\Communications\Domain\Meeting\Meeting;

final class Output
{
    public function __construct(
        public readonly Meeting $meeting,
    ) {
    }
}
