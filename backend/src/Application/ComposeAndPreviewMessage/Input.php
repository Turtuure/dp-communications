<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ComposeAndPreviewMessage;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\MailKind;

final class Input
{
    /**
     * @param array<string, mixed> $payload admin-supplied + DB-resolved context vars
     *                                       (e.g. subject, intro_text, meeting_id)
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly array $payload,
        public readonly AudienceFilter $audienceFilter,
        public readonly SupportedLocale $locale,
        public readonly ?string $templateVariant = null,
    ) {
    }
}
