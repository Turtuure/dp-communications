<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\AudienceResolverInterface;
use DaemsModule\Communications\Domain\Audience\ResolvedRecipient;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

/**
 * Test double for {@see AudienceResolverInterface}.
 *
 * Tests push pre-built `ResolvedRecipient` lists keyed by tenant id; resolve()
 * returns the matching list (or `[]` if none seeded). The category + filter
 * arguments are recorded so tests can assert the caller forwarded them
 * correctly.
 */
final class InMemoryAudienceResolver implements AudienceResolverInterface
{
    /** @var array<string, list<ResolvedRecipient>> keyed by tenant id value */
    public array $recipientsByTenant = [];

    /** @var list<array{tenant:TenantId, filter:AudienceFilter, category:CommunicationCategory}> */
    public array $calls = [];

    public function resolve(
        TenantId $tenant,
        AudienceFilter $filter,
        CommunicationCategory $category,
    ): array {
        $this->calls[] = ['tenant' => $tenant, 'filter' => $filter, 'category' => $category];
        return $this->recipientsByTenant[$tenant->value()] ?? [];
    }

    /**
     * @param list<ResolvedRecipient> $recipients
     */
    public function seed(TenantId $tenant, array $recipients): void
    {
        $this->recipientsByTenant[$tenant->value()] = $recipients;
    }
}
