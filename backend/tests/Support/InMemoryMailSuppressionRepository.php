<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;

final class InMemoryMailSuppressionRepository implements MailSuppressionRepositoryInterface
{
    /** @var array<string, list<MailSuppression>> indexed by tenant id */
    public array $byTenant = [];

    public function isSuppressed(TenantId $tenantId, string $email): bool
    {
        foreach ($this->byTenant[$tenantId->value()] ?? [] as $s) {
            if (strcasecmp($s->emailAddress, $email) === 0) {
                return true;
            }
        }
        return false;
    }

    public function add(MailSuppression $suppression): void
    {
        $key = $suppression->tenantId->value();
        $this->byTenant[$key] ??= [];
        $this->byTenant[$key][] = $suppression;
    }

    public function remove(TenantId $tenantId, string $email): void
    {
        $key = $tenantId->value();
        if (!isset($this->byTenant[$key])) {
            return;
        }
        $this->byTenant[$key] = array_values(array_filter(
            $this->byTenant[$key],
            static fn(MailSuppression $s): bool => strcasecmp($s->emailAddress, $email) !== 0,
        ));
    }

    public function listForTenant(TenantId $tenantId): array
    {
        return $this->byTenant[$tenantId->value()] ?? [];
    }
}
