<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ComposeAndPreviewMessage;

final class Output
{
    /**
     * @param list<string>          $audienceSampleNames first 3 first-names (+ trailing summary token if there are more)
     * @param array<string, mixed>  $resolvedVars        merged payload + per-recipient + brand vars actually fed to the renderer
     */
    public function __construct(
        public readonly string $htmlPreview,
        public readonly string $textPreview,
        public readonly int $audienceCount,
        public readonly array $audienceSampleNames,
        public readonly string $previewLocale,
        public readonly array $resolvedVars,
    ) {
    }
}
