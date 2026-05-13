<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Audience;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\AudienceResolverInterface;
use DaemsModule\Communications\Domain\Audience\JoinedWithinPeriod;
use DaemsModule\Communications\Domain\Audience\ResolvedRecipient;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

/**
 * SQL implementation of {@see AudienceResolverInterface}.
 *
 * Resolves an {@see AudienceFilter} + {@see CommunicationCategory} into a
 * concrete list of recipients by joining `users`, `user_tenants`, the
 * tenant's `default_locale`, optionally `user_communication_preferences`
 * (for Operational + Marketing categories) and optionally `member_applications`
 * (only when the filter carries `applicationStatuses`).
 *
 * NOTE — locale handling: `users` does not yet carry a per-user
 * `preferred_locale` column (planned for a later milestone). Until then, the
 * resolver uses `tenants.default_locale` as the recipient's locale and applies
 * the locale filter against that column. When per-user locale lands the only
 * thing changing here is the column reference.
 */
final class SqlAudienceResolver implements AudienceResolverInterface
{
    public function __construct(private readonly Connection $db) {}

    public function resolve(
        TenantId $tenant,
        AudienceFilter $filter,
        CommunicationCategory $category,
    ): array {
        /** @var list<mixed> $params */
        $params = [$tenant->value()];
        $where  = ['ut.left_at IS NULL', 'u.deleted_at IS NULL'];

        $sql = "SELECT u.id            AS user_id,
                       u.email         AS email,
                       u.name          AS name,
                       u.member_number AS member_number,
                       t.default_locale AS tenant_default_locale,
                       ut.role         AS tenant_role,
                       ut.joined_at    AS joined_at,
                       u.membership_type AS membership_type
                FROM users u
                JOIN user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
                JOIN tenants      t  ON t.id        = ut.tenant_id";

        // Opt-in gate for non-transactional categories.
        if ($category !== CommunicationCategory::Transactional) {
            $sql .= ' JOIN user_communication_preferences ucp
                        ON ucp.user_id   = u.id
                       AND ucp.tenant_id = ut.tenant_id
                       AND ucp.category  = ?
                       AND ucp.opted_in  = 1';
            $params[] = $category->value;
        }

        // Application-status filter requires joining member_applications by
        // user email (the canonical link until applications carry user_id).
        $hasApplicationFilter = $filter->applicationStatuses !== [];
        if ($hasApplicationFilter) {
            $sql .= ' JOIN member_applications ma
                        ON ma.email     = u.email
                       AND ma.tenant_id = ut.tenant_id';
        }

        // membership_types IN (...)
        if ($filter->membershipTypes !== []) {
            $placeholders = implode(',', array_fill(0, count($filter->membershipTypes), '?'));
            $where[] = "u.membership_type IN ({$placeholders})";
            foreach ($filter->membershipTypes as $mt) {
                $params[] = $mt;
            }
        }

        // locales IN (...) — currently against tenants.default_locale (see class note).
        if ($filter->locales !== []) {
            $placeholders = implode(',', array_fill(0, count($filter->locales), '?'));
            $where[] = "t.default_locale IN ({$placeholders})";
            foreach ($filter->locales as $loc) {
                $params[] = $loc;
            }
        }

        // joined_within — only one value at a time, fixed-INT interval.
        if ($filter->joinedWithin instanceof JoinedWithinPeriod) {
            $days = $filter->joinedWithin->days();
            // $days comes from the enum (30/90/365), so safe to inline as int.
            $where[] = "ut.joined_at >= (NOW() - INTERVAL {$days} DAY)";
        }

        // application_statuses IN (...)
        if ($hasApplicationFilter) {
            $placeholders = implode(',', array_fill(0, count($filter->applicationStatuses), '?'));
            $where[] = "ma.status IN ({$placeholders})";
            foreach ($filter->applicationStatuses as $status) {
                $params[] = $status;
            }
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY u.name ASC, u.email ASC';

        $rows = $this->db->query($sql, $params);

        $recipients = [];
        $seenUserIds = [];
        foreach ($rows as $row) {
            $userId = $this->str($row, 'user_id');
            // De-duplicate when the member_applications join produced multiple
            // rows per user (e.g. resubmissions). One ResolvedRecipient per user.
            if (isset($seenUserIds[$userId])) {
                continue;
            }
            $seenUserIds[$userId] = true;

            $email          = $this->str($row, 'email');
            $name           = $this->str($row, 'name', '');
            $localeRaw      = $this->str($row, 'tenant_default_locale', SupportedLocale::CONTENT_FALLBACK);
            $memberNumber   = $this->nullableStr($row, 'member_number');
            $tenantRole     = $this->str($row, 'tenant_role', '');
            $membershipType = $this->str($row, 'membership_type', '');
            $joinedAt       = $this->nullableStr($row, 'joined_at');

            $firstName = $this->firstNameFrom($name);
            $lastName  = $this->lastNameFrom($name);

            $recipients[] = new ResolvedRecipient(
                userId:      UserId::fromString($userId),
                email:       $email,
                locale:      SupportedLocale::fromString($localeRaw),
                firstName:   $firstName,
                contextVars: [
                    'name'            => $name,
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    'email'           => $email,
                    'member_number'   => $memberNumber,
                    'tenant_role'     => $tenantRole,
                    'membership_type' => $membershipType,
                    'joined_at'       => $joinedAt,
                ],
            );
        }

        return $recipients;
    }

    /**
     * Pull a string column out of a PDO row defensively.
     *
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $col, ?string $default = null): string
    {
        $v = $row[$col] ?? null;
        if (is_string($v)) {
            return $v;
        }
        if ($default !== null) {
            return $default;
        }
        throw new \DomainException("Missing or non-string column: {$col}");
    }

    /** @param array<string, mixed> $row */
    private function nullableStr(array $row, string $col): ?string
    {
        $v = $row[$col] ?? null;
        return is_string($v) ? $v : null;
    }

    /**
     * Derive a usable first-name from the single `users.name` column.
     *
     * `users.name` is a free-form display name; first token before the first
     * space is the conventional first name. Empty input → empty string (the
     * template renderer is responsible for graceful fallbacks).
     */
    private function firstNameFrom(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $trimmed, 2);
        if ($parts === false) {
            return $trimmed;
        }
        return $parts[0];
    }

    private function lastNameFrom(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $trimmed, 2);
        if ($parts === false || count($parts) < 2) {
            return '';
        }
        return $parts[1];
    }
}
