<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

final class TwoColumnsBlock extends NewsletterBlock
{
    /**
     * Column lists are kept loosely typed so the constructor can perform the
     * runtime guard (non-NewsletterBlock children, nested TwoColumns). Once
     * past the constructor each entry is guaranteed to be a NewsletterBlock.
     *
     * @param array<int, mixed> $left
     * @param array<int, mixed> $right
     */
    public function __construct(
        public readonly array $left,
        public readonly array $right,
    ) {
        foreach ([...$left, ...$right] as $child) {
            if (!$child instanceof NewsletterBlock) {
                throw new \InvalidArgumentException('TwoColumns children must be NewsletterBlocks');
            }
            if ($child instanceof TwoColumnsBlock) {
                throw new \InvalidArgumentException('TwoColumns blocks cannot be nested');
            }
        }
    }

    /**
     * @return array{type: string, left: list<array<string, mixed>>, right: list<array<string, mixed>>}
     */
    public function toRenderable(): array
    {
        $renderChild = static function (mixed $b): array {
            assert($b instanceof NewsletterBlock);
            return $b->toRenderable();
        };

        return [
            'type'  => 'two_columns',
            'left'  => array_values(array_map($renderChild, $this->left)),
            'right' => array_values(array_map($renderChild, $this->right)),
        ];
    }
}
