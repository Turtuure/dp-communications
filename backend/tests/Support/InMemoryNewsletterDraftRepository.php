<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;

/**
 * In-memory fake for {@see NewsletterDraftRepositoryInterface}.
 *
 * Stores rows in a public `byId` map keyed by newsletter-id value so tests can
 * inspect or pre-seed state directly.
 */
final class InMemoryNewsletterDraftRepository implements NewsletterDraftRepositoryInterface
{
    /** @var array<string, NewsletterDraft> */
    public array $byId = [];

    public function save(NewsletterDraft $draft): void
    {
        $this->byId[$draft->id->value()] = $draft;
    }

    public function findById(NewsletterId $id): ?NewsletterDraft
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function listForTenant(TenantId $tenantId): array
    {
        $out = [];
        foreach ($this->byId as $draft) {
            if ($draft->tenantId->equals($tenantId)) {
                $out[] = $draft;
            }
        }
        return $out;
    }

    public function delete(NewsletterId $id): void
    {
        unset($this->byId[$id->value()]);
    }
}
