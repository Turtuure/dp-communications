<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreference;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;

final class InMemoryUserCommunicationPreferenceRepository implements UserCommunicationPreferenceRepositoryInterface
{
    /** @var list<UserCommunicationPreference> */
    public array $rows = [];

    public function findFor(UserId $userId, TenantId $tenantId): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r->userId->equals($userId) && $r->tenantId->equals($tenantId)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public function setFor(
        UserId $userId,
        TenantId $tenantId,
        CommunicationCategory $category,
        bool $optedIn,
    ): void {
        // Replace any existing row for (user, tenant, category).
        $kept = [];
        foreach ($this->rows as $r) {
            if ($r->userId->equals($userId) && $r->tenantId->equals($tenantId) && $r->category === $category) {
                continue;
            }
            $kept[] = $r;
        }
        $kept[] = new UserCommunicationPreference(
            userId:    $userId,
            tenantId:  $tenantId,
            category:  $category,
            optedIn:   $optedIn,
            updatedAt: new \DateTimeImmutable('now'),
        );
        $this->rows = $kept;
    }

    public function categoriesAllowing(UserId $userId, TenantId $tenantId): array
    {
        $out = [];
        $seen = [];
        // Explicit opt-ins first.
        foreach ($this->rows as $r) {
            if ($r->userId->equals($userId) && $r->tenantId->equals($tenantId)) {
                $seen[$r->category->value] = true;
                if ($r->optedIn) {
                    $out[] = $r->category;
                }
            }
        }
        // Categories without an explicit row inherit the default.
        foreach (CommunicationCategory::cases() as $cat) {
            if (!isset($seen[$cat->value]) && $cat->defaultOptedIn()) {
                $out[] = $cat;
            }
        }
        return $out;
    }
}
