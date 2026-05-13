<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreference;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;

final class SqlUserCommunicationPreferenceRepository implements UserCommunicationPreferenceRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findFor(UserId $userId, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM user_communication_preferences
             WHERE user_id = ? AND tenant_id = ?
             ORDER BY category ASC',
            [$userId->value(), $tenantId->value()],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function setFor(
        UserId $userId,
        TenantId $tenantId,
        CommunicationCategory $category,
        bool $optedIn,
    ): void {
        $this->db->execute(
            'INSERT INTO user_communication_preferences (
                user_id, tenant_id, category, opted_in
             ) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                opted_in   = VALUES(opted_in),
                updated_at = CURRENT_TIMESTAMP(3)',
            [
                $userId->value(),
                $tenantId->value(),
                $category->value,
                $optedIn ? 1 : 0,
            ],
        );
    }

    public function categoriesAllowing(UserId $userId, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT category FROM user_communication_preferences
             WHERE user_id = ? AND tenant_id = ? AND opted_in = TRUE',
            [$userId->value(), $tenantId->value()],
        );

        $out = [];
        foreach ($rows as $row) {
            $cat = $row['category'] ?? null;
            if (is_string($cat)) {
                $out[] = CommunicationCategory::from($cat);
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): UserCommunicationPreference
    {
        $userId    = $this->str($row, 'user_id');
        $tenantId  = $this->str($row, 'tenant_id');
        $category  = $this->str($row, 'category');
        $updatedAt = $this->str($row, 'updated_at');
        $optedIn   = $row['opted_in'] ?? null;

        $optedInBool = match (true) {
            is_bool($optedIn)             => $optedIn,
            is_int($optedIn)              => $optedIn !== 0,
            is_string($optedIn)
                && ($optedIn === '1' || strcasecmp($optedIn, 'true') === 0) => true,
            is_string($optedIn)
                && ($optedIn === '0' || strcasecmp($optedIn, 'false') === 0) => false,
            default => throw new \DomainException('Corrupt user_communication_preferences.opted_in'),
        };

        return new UserCommunicationPreference(
            userId:    UserId::fromString($userId),
            tenantId:  TenantId::fromString($tenantId),
            category:  CommunicationCategory::from($category),
            optedIn:   $optedInBool,
            updatedAt: new \DateTimeImmutable($updatedAt),
        );
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $col): string
    {
        $val = $row[$col] ?? null;
        if (!is_string($val)) {
            throw new \DomainException("Corrupt user_communication_preferences.{$col}");
        }
        return $val;
    }
}
