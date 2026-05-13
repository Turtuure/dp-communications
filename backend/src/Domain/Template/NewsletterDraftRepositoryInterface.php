<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\NewsletterId;

interface NewsletterDraftRepositoryInterface
{
    public function save(NewsletterDraft $draft): void;

    public function findById(NewsletterId $id): ?NewsletterDraft;

    /**
     * @return list<NewsletterDraft>
     */
    public function listForTenant(TenantId $tenantId): array;

    public function delete(NewsletterId $id): void;
}
