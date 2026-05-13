<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\UpdateNewsletterDraft;

use DaemsModule\Communications\Domain\Mail\NewsletterId;

final class Output
{
    public function __construct(
        public readonly NewsletterId $newsletterId,
    ) {
    }
}
