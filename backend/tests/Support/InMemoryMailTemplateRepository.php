<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Template\MailTemplate;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;

final class InMemoryMailTemplateRepository implements MailTemplateRepositoryInterface
{
    /** @var array<string, MailTemplate> */
    public array $byKey = [];

    public function findOverrides(
        TenantId $tenantId,
        MailKind $kind,
        SupportedLocale $locale,
    ): ?MailTemplate {
        return $this->byKey[$this->key($tenantId, $kind, $locale)] ?? null;
    }

    public function saveOverrides(MailTemplate $template): void
    {
        $this->byKey[$this->key($template->tenantId, $template->kind, $template->locale)] = $template;
    }

    private function key(TenantId $tenantId, MailKind $kind, SupportedLocale $locale): string
    {
        return $tenantId->value() . '|' . $kind->value . '|' . $locale->value();
    }
}
