<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Renderer;

use DaemsModule\Communications\Domain\Template\Block\ButtonBlock;
use DaemsModule\Communications\Domain\Template\Block\DividerBlock;
use DaemsModule\Communications\Domain\Template\Block\EventCardBlock;
use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\ImageBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use DaemsModule\Communications\Domain\Template\Block\TwoColumnsBlock;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\NewsletterBlockRenderer;
use PHPUnit\Framework\TestCase;

final class NewsletterBlockRendererTest extends TestCase
{
    private const BRAND = '#2e5c8a';

    private function renderer(): NewsletterBlockRenderer
    {
        return new NewsletterBlockRenderer(new MarkdownRenderer(), self::BRAND);
    }

    public function test_heading_renders_with_brand_color_and_escapes_html(): void
    {
        $html = $this->renderer()->renderAll([new HeadingBlock(1, 'Hei <script>')]);
        self::assertStringContainsString('Hei &lt;script&gt;', $html);
        self::assertStringContainsString('color:' . self::BRAND, $html);
        self::assertStringContainsString('font-size:22px', $html);
        self::assertStringContainsString('<table', $html);
    }

    public function test_heading_level_2_and_3_use_smaller_font_sizes(): void
    {
        $h2 = $this->renderer()->renderAll([new HeadingBlock(2, 'H2')]);
        $h3 = $this->renderer()->renderAll([new HeadingBlock(3, 'H3')]);
        self::assertStringContainsString('font-size:18px', $h2);
        self::assertStringContainsString('font-size:15px', $h3);
    }

    public function test_paragraph_renders_markdown_through_safe_pipeline(): void
    {
        $html = $this->renderer()->renderAll([new ParagraphBlock('**Bold** and _italic_')]);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('<strong>Bold</strong>', $html);
        self::assertStringContainsString('<em>italic</em>', $html);
        self::assertStringContainsString('line-height:1.55', $html);
    }

    public function test_paragraph_strips_unsafe_html_input(): void
    {
        // CommonMark safe-mode (`html_input => 'strip'`) removes raw HTML
        // blocks; we assert the dangerous markup never reaches output.
        $html = $this->renderer()->renderAll([
            new ParagraphBlock("hello\n\n<script>alert(1)</script>"),
        ]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('hello', $html);
    }

    public function test_image_renders_inline_safe_html_with_escaped_alt(): void
    {
        $html = $this->renderer()->renderAll([
            new ImageBlock('https://x.example/y.jpg', 'a"b', 'caption with <html>'),
        ]);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('src="https://x.example/y.jpg"', $html);
        self::assertStringContainsString('alt="a&quot;b"', $html);
        self::assertStringContainsString('caption with &lt;html&gt;', $html);
        self::assertStringContainsString('max-width:100%', $html);
    }

    public function test_image_without_caption_omits_caption_block(): void
    {
        $html = $this->renderer()->renderAll([
            new ImageBlock('https://x.example/y.jpg', 'alt', null),
        ]);
        self::assertStringNotContainsString('font-size:11px;color:#888;text-align:center', $html);
    }

    public function test_button_renders_with_brand_color_and_escapes_attributes(): void
    {
        $html = $this->renderer()->renderAll([
            new ButtonBlock('Click "me"', 'https://x.example?a=1&b=2'),
        ]);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('background:' . self::BRAND, $html);
        self::assertStringContainsString('Click &quot;me&quot;', $html);
        self::assertStringContainsString('href="https://x.example?a=1&amp;b=2"', $html);
    }

    public function test_divider_renders_horizontal_rule_inside_table(): void
    {
        $html = $this->renderer()->renderAll([new DividerBlock()]);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('border-top:1px solid #d8d8d8', $html);
    }

    public function test_two_columns_renders_nested_blocks_side_by_side(): void
    {
        $html = $this->renderer()->renderAll([
            new TwoColumnsBlock(
                [new HeadingBlock(2, 'Left H')],
                [new ParagraphBlock('Right body')],
            ),
        ]);
        self::assertStringContainsString('class="col" width="50%"', $html);
        self::assertStringContainsString('Left H', $html);
        self::assertStringContainsString('Right body', $html);
        // Outer table + nested heading table + nested paragraph table.
        self::assertGreaterThanOrEqual(3, substr_count($html, '<table'));
    }

    public function test_event_card_renders_with_title_and_when_label_escaped(): void
    {
        $html = $this->renderer()->renderAll([
            new EventCardBlock('event-1', 'Title <x>', '2026-06-15 18:00'),
        ]);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('border-left:3px solid ' . self::BRAND, $html);
        self::assertStringContainsString('<strong>Title &lt;x&gt;</strong>', $html);
        self::assertStringContainsString('2026-06-15 18:00', $html);
    }

    public function test_event_card_falls_back_to_default_label_when_title_missing(): void
    {
        $html = $this->renderer()->renderAll([new EventCardBlock('event-2')]);
        self::assertStringContainsString('(Tapahtuma)', $html);
    }

    public function test_render_all_returns_empty_string_for_empty_input(): void
    {
        self::assertSame('', $this->renderer()->renderAll([]));
    }

    public function test_render_all_concatenates_multiple_blocks_with_newlines(): void
    {
        $html = $this->renderer()->renderAll([
            new HeadingBlock(2, 'A'),
            new DividerBlock(),
            new ParagraphBlock('p'),
        ]);
        // Three top-level <table> elements joined by newline.
        self::assertSame(3, substr_count($html, '<table'));
    }
}
