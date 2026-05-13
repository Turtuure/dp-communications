<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SaveTemplateOverrides;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\MailKind;

final class Input
{
    /**
     * @param array<string, string> $overrides admin-editable keys (subject, intro_text, signature, footer)
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly SupportedLocale $locale,
        public readonly array $overrides,
    ) {
    }
}
