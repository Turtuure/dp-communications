<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetTemplateOverrides;

final class Output
{
    /**
     * @param array<string, string> $stringOverrides whitelist-validated admin-editable keys
     */
    public function __construct(
        public readonly array $stringOverrides,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly ?string $updatedByUserId,
    ) {
    }
}
