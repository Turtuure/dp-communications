<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailTemplateId;

final class MailTemplate
{
    /**
     * @param array<string, string> $stringOverrides admin-editable string keys (e.g. subject, intro, signature, footer)
     */
    public function __construct(
        public readonly MailTemplateId $id,
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly SupportedLocale $locale,
        public readonly array $stringOverrides,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly UserId $updatedBy,
    ) {
    }
}
