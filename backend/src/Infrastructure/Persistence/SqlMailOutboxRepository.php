<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Meeting\MeetingId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

final class SqlMailOutboxRepository implements MailOutboxRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(MailOutbox $row): void
    {
        $payloadVarsJson = json_encode($row->payloadVars, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->db->execute(
            'INSERT INTO mail_outbox (
                id, tenant_id, kind, category, recipient_email, recipient_user_id,
                locale, subject, body_html, body_text, payload_vars,
                payload_meeting_id, payload_invoice_id, payload_newsletter_id,
                status, attempt_count, last_error, queued_at, sent_at, queued_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row->id->value(),
                $row->tenantId->value(),
                $row->kind->value,
                $row->category->value,
                $row->recipientEmail,
                $row->recipientUserId?->value(),
                $row->locale->value(),
                $row->subject,
                $row->bodyHtml,
                $row->bodyText,
                $payloadVarsJson,
                $row->payloadMeetingId?->value(),
                $row->payloadInvoiceId?->value(),
                $row->payloadNewsletterId?->value(),
                $row->status->value,
                $row->attemptCount,
                $row->lastError,
                $row->queuedAt->format('Y-m-d H:i:s.v'),
                $row->sentAt?->format('Y-m-d H:i:s.v'),
                $row->queuedBy->value(),
            ],
        );
    }

    public function findById(MailOutboxId $id): ?MailOutbox
    {
        $row = $this->db->queryOne(
            'SELECT * FROM mail_outbox WHERE id = ?',
            [$id->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listForTenant(TenantId $tenantId, array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($tenantId, $filters);

        $limit  = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);

        $sql = 'SELECT * FROM mail_outbox WHERE ' . implode(' AND ', $where)
             . ' ORDER BY queued_at DESC'
             . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $out = [];
        foreach ($this->db->query($sql, $params) as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function countForTenant(TenantId $tenantId, array $filters): int
    {
        [$where, $params] = $this->buildWhere($tenantId, $filters);
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS c FROM mail_outbox WHERE ' . implode(' AND ', $where),
            $params,
        );
        if ($row === null) {
            return 0;
        }
        $c = $row['c'] ?? 0;
        return is_numeric($c) ? (int) $c : 0;
    }

    public function pickNextForSending(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $sql = 'SELECT * FROM mail_outbox
                    WHERE status = ?
                    ORDER BY queued_at ASC
                    LIMIT ' . (int) $limit . '
                    FOR UPDATE SKIP LOCKED';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([MailOutboxStatus::Queued->value]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                $pdo->commit();
                return [];
            }

            $picked  = [];
            $ids     = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                /** @var array<string, mixed> $row */
                // Reflect the new status in the returned entities without
                // re-reading from the database.
                $row['status'] = MailOutboxStatus::Sending->value;
                $picked[]      = $this->hydrate($row);
                $idVal         = $row['id'] ?? null;
                if (is_string($idVal)) {
                    $ids[] = $idVal;
                }
            }

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $updateSql = 'UPDATE mail_outbox SET status = ? WHERE id IN (' . $placeholders . ')';
                $upd = $pdo->prepare($updateSql);
                $upd->execute(array_merge([MailOutboxStatus::Sending->value], $ids));
            }

            $pdo->commit();
            return $picked;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listQueued(): array
    {
        $sql = 'SELECT * FROM mail_outbox WHERE status = ? ORDER BY queued_at ASC';
        $out = [];
        foreach ($this->db->query($sql, [MailOutboxStatus::Queued->value]) as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function markStatus(MailOutboxId $id, MailOutboxStatus $status, ?string $error = null): void
    {
        if ($status === MailOutboxStatus::Sent) {
            $this->db->execute(
                'UPDATE mail_outbox SET status = ?, last_error = ?, sent_at = CURRENT_TIMESTAMP(3) WHERE id = ?',
                [$status->value, $error, $id->value()],
            );
            return;
        }

        $this->db->execute(
            'UPDATE mail_outbox SET status = ?, last_error = ? WHERE id = ?',
            [$status->value, $error, $id->value()],
        );
    }

    public function incrementAttempt(MailOutboxId $id, string $error): void
    {
        $this->db->execute(
            'UPDATE mail_outbox SET attempt_count = attempt_count + 1, last_error = ? WHERE id = ?',
            [$error, $id->value()],
        );
    }

    public function resetAttemptCount(MailOutboxId $id): void
    {
        $this->db->execute(
            'UPDATE mail_outbox SET attempt_count = 0 WHERE id = ?',
            [$id->value()],
        );
    }

    public function existsRecentInvoiceReminder(
        TenantId $tenantId,
        MemberFeeInvoiceId $invoiceId,
        string $offsetTag,
        int $hoursWindow,
        \DateTimeImmutable $now,
    ): bool {
        $cutoff = $now->modify('-' . max(0, $hoursWindow) . ' hours');
        $row = $this->db->queryOne(
            'SELECT 1 AS hit FROM mail_outbox
             WHERE tenant_id = ?
               AND payload_invoice_id = ?
               AND JSON_EXTRACT(payload_vars, "$.offset_tag") = ?
               AND queued_at >= ?
             LIMIT 1',
            [
                $tenantId->value(),
                $invoiceId->value(),
                $offsetTag,
                $cutoff->format('Y-m-d H:i:s.v'),
            ],
        );
        return $row !== null;
    }

    public function pseudonymizeOlderThan(\DateTimeImmutable $cutoff): int
    {
        $sql = <<<SQL
            UPDATE mail_outbox
            SET body_html        = '',
                body_text        = '',
                payload_vars     = '{}',
                recipient_email  = LOWER(SHA2(recipient_email, 256)),
                pseudonymized_at = NOW(3)
            WHERE queued_at < ?
              AND pseudonymized_at IS NULL
            SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$cutoff->format('Y-m-d H:i:s.v')]);

        return $stmt->rowCount();
    }

    /**
     * @param array{status?: MailOutboxStatus, kind?: MailKind, from?: \DateTimeImmutable, to?: \DateTimeImmutable, recipient_substring?: string} $filters
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function buildWhere(TenantId $tenantId, array $filters): array
    {
        $where  = ['tenant_id = ?'];
        $params = [$tenantId->value()];

        if (isset($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status']->value;
        }
        if (isset($filters['kind'])) {
            $where[]  = 'kind = ?';
            $params[] = $filters['kind']->value;
        }
        if (isset($filters['from'])) {
            $where[]  = 'queued_at >= ?';
            $params[] = $filters['from']->format('Y-m-d H:i:s.v');
        }
        if (isset($filters['to'])) {
            $where[]  = 'queued_at <= ?';
            $params[] = $filters['to']->format('Y-m-d H:i:s.v');
        }
        if (isset($filters['recipient_substring']) && $filters['recipient_substring'] !== '') {
            $where[]  = 'recipient_email LIKE ?';
            $params[] = '%' . $filters['recipient_substring'] . '%';
        }

        return [$where, $params];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): MailOutbox
    {
        $payloadVars = [];
        if (isset($row['payload_vars']) && is_string($row['payload_vars'])) {
            $decoded = json_decode($row['payload_vars'], true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $payloadVars = $decoded;
            }
        }

        $id              = $this->str($row, 'id');
        $tenantId        = $this->str($row, 'tenant_id');
        $kind            = $this->str($row, 'kind');
        $category        = $this->str($row, 'category');
        $recipientEmail  = $this->str($row, 'recipient_email');
        $recipientUserId = $this->strOrNull($row, 'recipient_user_id');
        $locale          = $this->str($row, 'locale');
        $subject         = $this->str($row, 'subject');
        $bodyHtml        = $this->str($row, 'body_html');
        $bodyText        = $this->str($row, 'body_text');
        $meetingId       = $this->strOrNull($row, 'payload_meeting_id');
        $invoiceId       = $this->strOrNull($row, 'payload_invoice_id');
        $newsletterId    = $this->strOrNull($row, 'payload_newsletter_id');
        $status          = $this->str($row, 'status');
        $attemptCount    = $this->intVal($row, 'attempt_count');
        $lastError       = $this->strOrNull($row, 'last_error');
        $queuedAt        = $this->str($row, 'queued_at');
        $sentAt          = $this->strOrNull($row, 'sent_at');
        $queuedBy        = $this->str($row, 'queued_by');

        return new MailOutbox(
            id:                  MailOutboxId::fromString($id),
            tenantId:            TenantId::fromString($tenantId),
            kind:                MailKind::from($kind),
            category:            CommunicationCategory::from($category),
            recipientEmail:      $recipientEmail,
            recipientUserId:     $recipientUserId !== null ? UserId::fromString($recipientUserId) : null,
            locale:              SupportedLocale::fromString($locale),
            subject:             $subject,
            bodyHtml:            $bodyHtml,
            bodyText:            $bodyText,
            payloadVars:         $payloadVars,
            payloadMeetingId:    $meetingId    !== null ? MeetingId::fromString($meetingId)         : null,
            payloadInvoiceId:    $invoiceId    !== null ? MemberFeeInvoiceId::fromString($invoiceId) : null,
            payloadNewsletterId: $newsletterId !== null ? NewsletterId::fromString($newsletterId)   : null,
            status:              MailOutboxStatus::from($status),
            attemptCount:        $attemptCount,
            lastError:           $lastError,
            queuedAt:            new \DateTimeImmutable($queuedAt),
            sentAt:              $sentAt !== null ? new \DateTimeImmutable($sentAt) : null,
            queuedBy:            UserId::fromString($queuedBy),
        );
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $col): string
    {
        $val = $row[$col] ?? null;
        if (!is_string($val)) {
            throw new \DomainException("Corrupt mail_outbox.{$col}");
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
            throw new \DomainException("Corrupt mail_outbox.{$col}");
        }
        return $val;
    }

    /** @param array<string, mixed> $row */
    private function intVal(array $row, string $col): int
    {
        $val = $row[$col] ?? null;
        if (is_int($val)) {
            return $val;
        }
        if (is_string($val) && ctype_digit($val)) {
            return (int) $val;
        }
        throw new \DomainException("Corrupt mail_outbox.{$col}");
    }
}
