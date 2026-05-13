<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Renderer;

use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $md;

    protected function setUp(): void
    {
        $this->md = new MarkdownRenderer();
    }

    public function test_renders_basic_markdown_to_html(): void
    {
        $html = $this->md->renderSafe("Hello **world**!");

        self::assertStringContainsString('<strong>world</strong>', $html);
    }

    public function test_renders_lists(): void
    {
        $html = $this->md->renderSafe("- one\n- two\n- three");

        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>one</li>', $html);
        self::assertStringContainsString('<li>three</li>', $html);
    }

    public function test_strips_raw_html_in_safe_mode(): void
    {
        $html = $this->md->renderSafe('Hello <script>alert("xss")</script> world');

        // Safe mode strips the tag but keeps the text content (HTML-escaped) —
        // the harmless residue is rendered as `alert(&quot;xss&quot;)`, no
        // executable <script> element survives.
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('</script>', $html);
    }

    public function test_blocks_javascript_links(): void
    {
        $html = $this->md->renderSafe('[click](javascript:alert(1))');

        self::assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_renders_safe_links_normally(): void
    {
        $html = $this->md->renderSafe('[home](https://daems.fi)');

        self::assertStringContainsString('<a href="https://daems.fi">home</a>', $html);
    }
}
