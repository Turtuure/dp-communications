<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\UpdateNewsletterDraft;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\Block\NewsletterBlock;

final class Input
{
    /**
     * @param array<string, string>                $subjectByLocale subjects keyed by locale code (e.g. `fi_FI`)
     * @param array<string, list<NewsletterBlock>> $blocksByLocale  ordered block lists keyed by locale code
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly NewsletterId $newsletterId,
        public readonly ?string $internalName,
        public readonly array $subjectByLocale,
        public readonly array $blocksByLocale,
        public readonly AudienceFilter $audience,
    ) {
    }
}
