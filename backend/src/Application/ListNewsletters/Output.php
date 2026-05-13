<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListNewsletters;

use DaemsModule\Communications\Domain\Template\NewsletterDraft;

final class Output
{
    /**
     * @param list<NewsletterDraft> $newsletters
     */
    public function __construct(
        public readonly array $newsletters,
    ) {
    }
}
