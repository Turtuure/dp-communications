<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Domain\Template\Block;

use DaemsModule\Communications\Domain\Template\Block\BlockSerializer;
use DaemsModule\Communications\Domain\Template\Block\ButtonBlock;
use DaemsModule\Communications\Domain\Template\Block\DividerBlock;
use DaemsModule\Communications\Domain\Template\Block\EventCardBlock;
use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\ImageBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use DaemsModule\Communications\Domain\Template\Block\TwoColumnsBlock;
use PHPUnit\Framework\TestCase;

final class BlockSerializerTest extends TestCase
{
    public function test_serialize_roundtrip_for_all_block_types(): void
    {
        $blocks = [
            new HeadingBlock(2, 'Hello'),
            new ParagraphBlock('**Bold** text'),
            new ImageBlock('https://x.example/y.jpg', 'alt', 'caption'),
            new ButtonBlock('Click', 'https://x.example'),
            new DividerBlock(),
            new TwoColumnsBlock([new ParagraphBlock('L')], [new ParagraphBlock('R')]),
            new EventCardBlock('event-1', 'Title', '2026-06-15'),
        ];

        $json    = BlockSerializer::toJson($blocks);
        $rebuilt = BlockSerializer::fromJson($json);

        self::assertEquals($blocks, $rebuilt);
    }

    public function test_serialize_image_without_caption_roundtrip(): void
    {
        $blocks  = [new ImageBlock('https://x.example/y.jpg', 'alt', null)];
        $rebuilt = BlockSerializer::fromJson(BlockSerializer::toJson($blocks));
        self::assertEquals($blocks, $rebuilt);
    }

    public function test_serialize_event_card_without_title_or_when_label(): void
    {
        $blocks  = [new EventCardBlock('event-2', null, null)];
        $rebuilt = BlockSerializer::fromJson(BlockSerializer::toJson($blocks));
        self::assertEquals($blocks, $rebuilt);
    }

    public function test_to_array_emits_type_discriminator_for_each_block(): void
    {
        self::assertSame('heading',     BlockSerializer::toArray(new HeadingBlock(1, 'H'))['type']);
        self::assertSame('paragraph',   BlockSerializer::toArray(new ParagraphBlock('p'))['type']);
        self::assertSame('image',       BlockSerializer::toArray(new ImageBlock('https://x.example/y.jpg', 'a'))['type']);
        self::assertSame('button',      BlockSerializer::toArray(new ButtonBlock('t', 'https://x.example'))['type']);
        self::assertSame('divider',     BlockSerializer::toArray(new DividerBlock())['type']);
        self::assertSame('two_columns', BlockSerializer::toArray(new TwoColumnsBlock([], []))['type']);
        self::assertSame('event_card',  BlockSerializer::toArray(new EventCardBlock('e'))['type']);
    }

    public function test_from_json_rejects_non_array_payload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BlockSerializer::fromJson('"not an array"');
    }

    public function test_from_array_rejects_missing_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BlockSerializer::fromArray(['level' => 1, 'text' => 'no type']);
    }

    public function test_from_array_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BlockSerializer::fromArray(['type' => 'mystery_type']);
    }

    public function test_nested_two_columns_roundtrip_preserves_children(): void
    {
        $blocks = [
            new TwoColumnsBlock(
                [new HeadingBlock(2, 'L'), new ParagraphBlock('left body')],
                [new ParagraphBlock('right body'), new DividerBlock()],
            ),
        ];
        $rebuilt = BlockSerializer::fromJson(BlockSerializer::toJson($blocks));
        self::assertEquals($blocks, $rebuilt);
    }
}
