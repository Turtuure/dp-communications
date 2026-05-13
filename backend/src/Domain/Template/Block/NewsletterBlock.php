<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

abstract class NewsletterBlock
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toRenderable(): array;
}
