<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

final class ParagraphBlock extends NewsletterBlock
{
    public function __construct(
        public readonly string $markdown,
    ) {
        if (trim($markdown) === '') {
            throw new \InvalidArgumentException('Paragraph markdown cannot be empty');
        }
    }

    /**
     * @return array{type: string, markdown: string}
     */
    public function toRenderable(): array
    {
        return [
            'type'     => 'paragraph',
            'markdown' => $this->markdown,
        ];
    }
}
