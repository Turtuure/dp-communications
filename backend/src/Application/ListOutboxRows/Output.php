<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListOutboxRows;

use DaemsModule\Communications\Domain\Mail\MailOutbox;

final class Output
{
    /**
     * @param list<MailOutbox> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalCount,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }
}
