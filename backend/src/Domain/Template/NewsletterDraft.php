<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\Block\NewsletterBlock;

final class NewsletterDraft
{
    /**
     * @param array<string, string>                  $subjectByLocale  locale-code => subject
     * @param array<string, list<NewsletterBlock>>   $blocksByLocale   locale-code => ordered block list
     */
    public function __construct(
        public readonly NewsletterId $id,
        public readonly TenantId $tenantId,
        public readonly string $internalName,
        public readonly array $subjectByLocale,
        public readonly array $blocksByLocale,
        public readonly AudienceFilter $audience,
        public readonly NewsletterStatus $status,
        public readonly ?\DateTimeImmutable $sentAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly UserId $createdBy,
    ) {
        if (trim($internalName) === '') {
            throw new \InvalidArgumentException('Newsletter internalName cannot be empty');
        }
    }
}
