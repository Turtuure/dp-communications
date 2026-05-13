<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Domain\Template\Block;

use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use DaemsModule\Communications\Domain\Template\Block\TwoColumnsBlock;
use PHPUnit\Framework\TestCase;

final class BlockTest extends TestCase
{
    public function test_heading_level_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HeadingBlock(4, 'invalid');
    }

    public function test_heading_text_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HeadingBlock(1, '   ');
    }

    public function test_two_columns_disallows_nested_two_columns(): void
    {
        $inner = new TwoColumnsBlock([new ParagraphBlock('a')], [new ParagraphBlock('b')]);
        $this->expectException(\InvalidArgumentException::class);
        new TwoColumnsBlock([$inner], []);
    }

    public function test_two_columns_disallows_non_block_children(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line */
        new TwoColumnsBlock(['not-a-block'], []);
    }
}
