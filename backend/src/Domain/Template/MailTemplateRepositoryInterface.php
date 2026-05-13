<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\MailKind;

interface MailTemplateRepositoryInterface
{
    public function findOverrides(
        TenantId $tenantId,
        MailKind $kind,
        SupportedLocale $locale,
    ): ?MailTemplate;

    public function saveOverrides(MailTemplate $template): void;
}
