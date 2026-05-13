<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SendComposedMessage;

use DaemsModule\Communications\Domain\Meeting\MeetingId;

final class Output
{
    public function __construct(
        public readonly int $enqueuedCount,
        public readonly ?MeetingId $meetingId,
    ) {
    }
}
