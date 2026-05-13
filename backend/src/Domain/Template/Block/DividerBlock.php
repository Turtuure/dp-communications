<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

final class DividerBlock extends NewsletterBlock
{
    /**
     * @return array{type: string}
     */
    public function toRenderable(): array
    {
        return ['type' => 'divider'];
    }
}
