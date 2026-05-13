<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

/**
 * Round-trip JSON serializer for {@see NewsletterBlock} values.
 *
 * Single source of truth used by:
 *   - {@see \DaemsModule\Communications\Infrastructure\Persistence\SqlNewsletterDraftRepository}
 *     for `newsletter_drafts.blocks_i18n` JSON column round-trip
 *   - Newsletter CRUD use cases for API payload (de)serialization
 *
 * Block discriminator: each array carries `'type'` =
 *   heading | paragraph | image | button | divider | two_columns | event_card.
 *
 * The `event_card` payload uses the same camelCase property names as
 * {@see EventCardBlock::toRenderable()} (`eventId`, `whenLabel`) so a
 * round-trip via the renderable representation is also stable.
 */
final class BlockSerializer
{
    /** @param list<NewsletterBlock> $blocks */
    public static function toJson(array $blocks): string
    {
        $arr = array_map(static fn(NewsletterBlock $b): array => self::toArray($b), $blocks);
        $json = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to JSON-encode newsletter blocks: ' . json_last_error_msg());
        }
        return $json;
    }

    /** @return list<NewsletterBlock> */
    public static function fromJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid newsletter blocks JSON (expected array)');
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('Invalid newsletter blocks JSON (non-array entry)');
            }
            /** @var array<string, mixed> $entry */
            $out[] = self::fromArray($entry);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(NewsletterBlock $b): array
    {
        if ($b instanceof TwoColumnsBlock) {
            $left = [];
            foreach ($b->left as $child) {
                assert($child instanceof NewsletterBlock);
                $left[] = self::toArray($child);
            }
            $right = [];
            foreach ($b->right as $child) {
                assert($child instanceof NewsletterBlock);
                $right[] = self::toArray($child);
            }
            return ['type' => 'two_columns', 'left' => $left, 'right' => $right];
        }
        if ($b instanceof HeadingBlock) {
            return ['type' => 'heading', 'level' => $b->level, 'text' => $b->text];
        }
        if ($b instanceof ParagraphBlock) {
            return ['type' => 'paragraph', 'markdown' => $b->markdown];
        }
        if ($b instanceof ImageBlock) {
            return ['type' => 'image', 'url' => $b->url, 'alt' => $b->alt, 'caption' => $b->caption];
        }
        if ($b instanceof ButtonBlock) {
            return ['type' => 'button', 'text' => $b->text, 'url' => $b->url];
        }
        if ($b instanceof DividerBlock) {
            return ['type' => 'divider'];
        }
        if ($b instanceof EventCardBlock) {
            return [
                'type'      => 'event_card',
                'eventId'   => $b->eventId,
                'title'     => $b->title,
                'whenLabel' => $b->whenLabel,
            ];
        }
        throw new \RuntimeException('Unknown newsletter block: ' . $b::class);
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): NewsletterBlock
    {
        $type = $a['type'] ?? null;
        if (!is_string($type)) {
            throw new \InvalidArgumentException('Newsletter block missing type');
        }
        switch ($type) {
            case 'heading':
                $level = isset($a['level']) && is_int($a['level']) ? $a['level'] : 1;
                $text  = isset($a['text']) && is_string($a['text']) ? $a['text'] : '';
                return new HeadingBlock($level, $text);

            case 'paragraph':
                $md = isset($a['markdown']) && is_string($a['markdown']) ? $a['markdown'] : '';
                return new ParagraphBlock($md);

            case 'image':
                $url     = isset($a['url']) && is_string($a['url']) ? $a['url'] : '';
                $alt     = isset($a['alt']) && is_string($a['alt']) ? $a['alt'] : '';
                $caption = isset($a['caption']) && is_string($a['caption']) ? $a['caption'] : null;
                return new ImageBlock($url, $alt, $caption);

            case 'button':
                $text = isset($a['text']) && is_string($a['text']) ? $a['text'] : '';
                $url  = isset($a['url']) && is_string($a['url']) ? $a['url'] : '';
                return new ButtonBlock($text, $url);

            case 'divider':
                return new DividerBlock();

            case 'event_card':
                $eventId = isset($a['eventId']) && is_string($a['eventId']) ? $a['eventId'] : '';
                $title   = isset($a['title']) && is_string($a['title']) ? $a['title'] : null;
                $whenLabel = isset($a['whenLabel']) && is_string($a['whenLabel']) ? $a['whenLabel'] : null;
                return new EventCardBlock($eventId, $title, $whenLabel);

            case 'two_columns':
                $left  = [];
                $right = [];
                if (isset($a['left']) && is_array($a['left'])) {
                    foreach ($a['left'] as $child) {
                        if (is_array($child)) {
                            /** @var array<string, mixed> $child */
                            $left[] = self::fromArray($child);
                        }
                    }
                }
                if (isset($a['right']) && is_array($a['right'])) {
                    foreach ($a['right'] as $child) {
                        if (is_array($child)) {
                            /** @var array<string, mixed> $child */
                            $right[] = self::fromArray($child);
                        }
                    }
                }
                return new TwoColumnsBlock($left, $right);

            default:
                throw new \InvalidArgumentException("Unknown newsletter block type: {$type}");
        }
    }
}
