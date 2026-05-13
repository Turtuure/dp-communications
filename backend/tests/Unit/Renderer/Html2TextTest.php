<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Renderer;

use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use PHPUnit\Framework\TestCase;

final class Html2TextTest extends TestCase
{
    private Html2Text $h2t;

    protected function setUp(): void
    {
        $this->h2t = new Html2Text();
    }

    public function test_strips_tags_and_decodes_entities(): void
    {
        $text = $this->h2t->convert('<p>Hei &amp; tervetuloa</p>');

        self::assertSame('Hei & tervetuloa', $text);
    }

    public function test_links_render_as_text_paren_url(): void
    {
        $text = $this->h2t->convert('Click <a href="https://daems.fi/x">here</a> please');

        self::assertStringContainsString('here (https://daems.fi/x)', $text);
    }

    public function test_link_with_same_text_as_href_collapses_to_url(): void
    {
        $text = $this->h2t->convert('<a href="https://example.com">https://example.com</a>');

        self::assertSame('https://example.com', $text);
    }

    public function test_ordered_list_uses_numbers(): void
    {
        $text = $this->h2t->convert('<ol><li>Avaus</li><li>Päätös</li></ol>');

        self::assertStringContainsString('1. Avaus', $text);
        self::assertStringContainsString('2. Päätös', $text);
    }

    public function test_unordered_list_uses_bullets(): void
    {
        $text = $this->h2t->convert('<ul><li>foo</li><li>bar</li></ul>');

        self::assertStringContainsString('- foo', $text);
        self::assertStringContainsString('- bar', $text);
    }

    public function test_br_becomes_newline(): void
    {
        $text = $this->h2t->convert('line1<br>line2<br/>line3');

        self::assertSame("line1\nline2\nline3", $text);
    }

    public function test_block_elements_separated_by_blank_lines(): void
    {
        $text = $this->h2t->convert('<p>First paragraph</p><p>Second paragraph</p>');

        self::assertStringContainsString("First paragraph\n\nSecond paragraph", $text);
    }

    public function test_strips_style_and_script_blocks_entirely(): void
    {
        $text = $this->h2t->convert(
            '<style>.x{color:red}</style><p>visible</p><script>alert(1)</script>',
        );

        self::assertSame('visible', $text);
    }

    public function test_collapses_runs_of_blank_lines(): void
    {
        $text = $this->h2t->convert('<p>a</p><p></p><p></p><p>b</p>');

        // At most one blank line between non-empty lines.
        self::assertDoesNotMatchRegularExpression('/\n{3,}/', $text);
        self::assertStringContainsString("a\n\nb", $text);
    }
}
