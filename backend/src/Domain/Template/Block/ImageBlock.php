<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

final class ImageBlock extends NewsletterBlock
{
    public function __construct(
        public readonly string $url,
        public readonly string $alt,
        public readonly ?string $caption = null,
    ) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Image url must be a valid URL');
        }
        if (trim($alt) === '') {
            throw new \InvalidArgumentException('Image alt text cannot be empty');
        }
        if ($caption !== null && trim($caption) === '') {
            throw new \InvalidArgumentException('Image caption, when provided, cannot be empty whitespace');
        }
    }

    /**
     * @return array{type: string, url: string, alt: string, caption: ?string}
     */
    public function toRenderable(): array
    {
        return [
            'type'    => 'image',
            'url'     => $this->url,
            'alt'     => $this->alt,
            'caption' => $this->caption,
        ];
    }
}
