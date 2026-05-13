<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

final class HeadingBlock extends NewsletterBlock
{
    public function __construct(
        public readonly int $level,
        public readonly string $text,
    ) {
        if ($level < 1 || $level > 3) {
            throw new \InvalidArgumentException('Heading level must be 1-3');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Heading text cannot be empty');
        }
    }

    /**
     * @return array{type: string, level: int, text: string}
     */
    public function toRenderable(): array
    {
        return [
            'type'  => 'heading',
            'level' => $this->level,
            'text'  => $this->text,
        ];
    }
}
