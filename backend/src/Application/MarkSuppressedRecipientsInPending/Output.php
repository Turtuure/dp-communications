<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending;

final class Output
{
    public function __construct(
        public readonly int $marked,
    ) {
    }
}
