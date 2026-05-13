<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetTemplateOverrides;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\MailKind;

final class Input
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly SupportedLocale $locale,
    ) {
    }
}
