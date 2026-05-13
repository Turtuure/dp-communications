<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

final class ButtonBlock extends NewsletterBlock
{
    public function __construct(
        public readonly string $text,
        public readonly string $url,
    ) {
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Button text cannot be empty');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Button url must be a valid URL');
        }
    }

    /**
     * @return array{type: string, text: string, url: string}
     */
    public function toRenderable(): array
    {
        return [
            'type' => 'button',
            'text' => $this->text,
            'url'  => $this->url,
        ];
    }
}
