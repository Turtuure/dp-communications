<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;

final class SqlMailSuppressionRepository implements MailSuppressionRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function isSuppressed(TenantId $tenantId, string $email): bool
    {
        $row = $this->db->queryOne(
            'SELECT 1 AS one FROM mail_suppressions WHERE tenant_id = ? AND email_address = ?',
            [$tenantId->value(), $email],
        );
        return $row !== null;
    }

    public function add(MailSuppression $suppression): void
    {
        // INSERT … ON DUPLICATE KEY UPDATE makes re-adding the same address a
        // no-op on the primary key (tenant_id, email_address).
        $this->db->execute(
            'INSERT INTO mail_suppressions (
                tenant_id, email_address, reason, smtp_response_code, suppressed_at, suppressed_by
             ) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                reason             = VALUES(reason),
                smtp_response_code = VALUES(smtp_response_code),
                suppressed_at      = VALUES(suppressed_at),
                suppressed_by      = VALUES(suppressed_by)',
            [
                $suppression->tenantId->value(),
                $suppression->emailAddress,
                $suppression->reason->value,
                $suppression->smtpResponseCode,
                $suppression->suppressedAt->format('Y-m-d H:i:s.v'),
                $suppression->suppressedBy?->value(),
            ],
        );
    }

    public function remove(TenantId $tenantId, string $email): void
    {
        $this->db->execute(
            'DELETE FROM mail_suppressions WHERE tenant_id = ? AND email_address = ?',
            [$tenantId->value(), $email],
        );
    }

    public function listForTenant(TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM mail_suppressions WHERE tenant_id = ? ORDER BY suppressed_at DESC',
            [$tenantId->value()],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): MailSuppression
    {
        $tenantId        = $this->str($row, 'tenant_id');
        $email           = $this->str($row, 'email_address');
        $reason          = $this->str($row, 'reason');
        $suppressedAt    = $this->str($row, 'suppressed_at');
        $smtpCode        = $this->strOrNull($row, 'smtp_response_code');
        $suppressedBy    = $this->strOrNull($row, 'suppressed_by');

        return new MailSuppression(
            tenantId:         TenantId::fromString($tenantId),
            emailAddress:     $email,
            reason:           SuppressionReason::from($reason),
            suppressedAt:     new \DateTimeImmutable($suppressedAt),
            smtpResponseCode: $smtpCode,
            suppressedBy:     $suppressedBy !== null ? UserId::fromString($suppressedBy) : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $col): string
    {
        $val = $row[$col] ?? null;
        if (!is_string($val)) {
            throw new \DomainException("Corrupt mail_suppressions.{$col}");
        }
        return $val;
    }

    /** @param array<string, mixed> $row */
    private function strOrNull(array $row, string $col): ?string
    {
        $val = $row[$col] ?? null;
        if ($val === null) {
            return null;
        }
        if (!is_string($val)) {
            throw new \DomainException("Corrupt mail_suppressions.{$col}");
        }
        return $val;
    }
}
