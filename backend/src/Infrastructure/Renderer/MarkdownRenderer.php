<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Renderer;

use League\CommonMark\CommonMarkConverter;

/**
 * Safe-mode CommonMark converter for admin-authored body text.
 *
 * Configuration:
 *   - `html_input` => 'strip'       — raw HTML in admin input is silently dropped.
 *   - `allow_unsafe_links` => false — javascript:, vbscript:, data: links blocked.
 *
 * The result is HTML suitable for direct embedding inside an email template;
 * VarSubstituter will treat the returned string as a `*_html` value (pre-rendered)
 * and will NOT re-escape it.
 */
final class MarkdownRenderer
{
    private readonly CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Render Markdown source to HTML in safe mode.
     *
     * Raw HTML in the source is stripped; unsafe link schemes are blocked.
     */
    public function renderSafe(string $markdown): string
    {
        return rtrim($this->converter->convert($markdown)->getContent());
    }
}
