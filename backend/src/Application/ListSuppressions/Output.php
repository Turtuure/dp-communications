<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListSuppressions;

use DaemsModule\Communications\Domain\Mail\MailSuppression;

final class Output
{
    /**
     * @param list<MailSuppression> $suppressions
     */
    public function __construct(
        public readonly array $suppressions,
    ) {
    }
}
