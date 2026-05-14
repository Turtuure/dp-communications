<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Support;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;

/**
 * Minimal in-memory MailOutboxRepository for unit tests.
 *
 * Stores rows in a public `byId` array keyed by outbox id so tests can
 * directly inspect or pre-seed state. `pickNextForSending()` returns rows
 * in insertion order (NOT queued_at order) to keep the fake lean — tests
 * that care about strict ordering should use the SQL integration test.
 */
final class InMemoryMailOutboxRepository implements MailOutboxRepositoryInterface
{
    /** @var array<string, MailOutbox> indexed by outbox id */
    public array $byId = [];

    /** @var array<string, true> outbox ids whose body fields have been retention-pseudonymized */
    public array $pseudonymizedIds = [];

    public function save(MailOutbox $row): void
    {
        $this->byId[$row->id->value()] = $row;
    }

    public function findById(MailOutboxId $id): ?MailOutbox
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function listForTenant(TenantId $tenantId, array $filters, int $page, int $perPage): array
    {
        $out = [];
        foreach ($this->byId as $row) {
            if (!$row->tenantId->equals($tenantId)) {
                continue;
            }
            if (isset($filters['status']) && $row->status !== $filters['status']) {
                continue;
            }
            if (isset($filters['kind']) && $row->kind !== $filters['kind']) {
                continue;
            }
            if (isset($filters['from']) && $row->queuedAt < $filters['from']) {
                continue;
            }
            if (isset($filters['to']) && $row->queuedAt > $filters['to']) {
                continue;
            }
            if (isset($filters['recipient_substring']) && $filters['recipient_substring'] !== ''
                && !str_contains($row->recipientEmail, (string) $filters['recipient_substring'])
            ) {
                continue;
            }
            $out[] = $row;
        }
        $limit  = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);
        return array_values(array_slice($out, $offset, $limit));
    }

    public function countForTenant(TenantId $tenantId, array $filters): int
    {
        return count($this->listForTenant($tenantId, $filters, 1, PHP_INT_MAX));
    }

    public function pickNextForSending(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $picked = [];
        foreach ($this->byId as $id => $row) {
            if ($row->status !== MailOutboxStatus::Queued) {
                continue;
            }
            $sending = $this->withStatus($row, MailOutboxStatus::Sending);
            $this->byId[$id] = $sending;
            $picked[] = $sending;
            if (count($picked) >= $limit) {
                break;
            }
        }
        return $picked;
    }

    public function listQueued(): array
    {
        $out = [];
        foreach ($this->byId as $row) {
            if ($row->status === MailOutboxStatus::Queued) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function markStatus(MailOutboxId $id, MailOutboxStatus $status, ?string $error = null): void
    {
        $row = $this->byId[$id->value()] ?? null;
        if ($row === null) {
            return;
        }
        $sentAt = $status === MailOutboxStatus::Sent ? new \DateTimeImmutable() : $row->sentAt;
        $this->byId[$id->value()] = new MailOutbox(
            id:                  $row->id,
            tenantId:            $row->tenantId,
            kind:                $row->kind,
            category:            $row->category,
            recipientEmail:      $row->recipientEmail,
            recipientUserId:     $row->recipientUserId,
            locale:              $row->locale,
            subject:             $row->subject,
            bodyHtml:            $row->bodyHtml,
            bodyText:            $row->bodyText,
            payloadVars:         $row->payloadVars,
            payloadMeetingId:    $row->payloadMeetingId,
            payloadInvoiceId:    $row->payloadInvoiceId,
            payloadNewsletterId: $row->payloadNewsletterId,
            status:              $status,
            attemptCount:        $row->attemptCount,
            lastError:           $error,
            queuedAt:            $row->queuedAt,
            sentAt:              $sentAt,
            queuedBy:            $row->queuedBy,
        );
    }

    public function incrementAttempt(MailOutboxId $id, string $error): void
    {
        $row = $this->byId[$id->value()] ?? null;
        if ($row === null) {
            return;
        }
        $this->byId[$id->value()] = new MailOutbox(
            id:                  $row->id,
            tenantId:            $row->tenantId,
            kind:                $row->kind,
            category:            $row->category,
            recipientEmail:      $row->recipientEmail,
            recipientUserId:     $row->recipientUserId,
            locale:              $row->locale,
            subject:             $row->subject,
            bodyHtml:            $row->bodyHtml,
            bodyText:            $row->bodyText,
            payloadVars:         $row->payloadVars,
            payloadMeetingId:    $row->payloadMeetingId,
            payloadInvoiceId:    $row->payloadInvoiceId,
            payloadNewsletterId: $row->payloadNewsletterId,
            status:              $row->status,
            attemptCount:        $row->attemptCount + 1,
            lastError:           $error,
            queuedAt:            $row->queuedAt,
            sentAt:              $row->sentAt,
            queuedBy:            $row->queuedBy,
        );
    }

    public function resetAttemptCount(MailOutboxId $id): void
    {
        $row = $this->byId[$id->value()] ?? null;
        if ($row === null) {
            return;
        }
        $this->byId[$id->value()] = new MailOutbox(
            id:                  $row->id,
            tenantId:            $row->tenantId,
            kind:                $row->kind,
            category:            $row->category,
            recipientEmail:      $row->recipientEmail,
            recipientUserId:     $row->recipientUserId,
            locale:              $row->locale,
            subject:             $row->subject,
            bodyHtml:            $row->bodyHtml,
            bodyText:            $row->bodyText,
            payloadVars:         $row->payloadVars,
            payloadMeetingId:    $row->payloadMeetingId,
            payloadInvoiceId:    $row->payloadInvoiceId,
            payloadNewsletterId: $row->payloadNewsletterId,
            status:              $row->status,
            attemptCount:        0,
            lastError:           $row->lastError,
            queuedAt:            $row->queuedAt,
            sentAt:              $row->sentAt,
            queuedBy:            $row->queuedBy,
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
        foreach ($this->byId as $row) {
            if (!$row->tenantId->equals($tenantId)) {
                continue;
            }
            if ($row->payloadInvoiceId === null
                || $row->payloadInvoiceId->value() !== $invoiceId->value()
            ) {
                continue;
            }
            $tag = $row->payloadVars['offset_tag'] ?? null;
            if (!is_string($tag) || $tag !== $offsetTag) {
                continue;
            }
            if ($row->queuedAt < $cutoff) {
                continue;
            }
            return true;
        }
        return false;
    }

    public function pseudonymizeOlderThan(\DateTimeImmutable $cutoff): int
    {
        $count = 0;
        foreach ($this->byId as $id => $row) {
            if ($row->queuedAt >= $cutoff) {
                continue;
            }
            if (isset($this->pseudonymizedIds[$id])) {
                continue;
            }
            // MailOutbox entity is readonly; rebuild with pseudonymized fields.
            // The pseudonymized_at column tracking lives in $this->pseudonymizedIds
            // since the entity doesn't expose it (SQL repo persists the flag
            // server-side via the schema column).
            $this->byId[$id] = new MailOutbox(
                id:                  $row->id,
                tenantId:            $row->tenantId,
                kind:                $row->kind,
                category:            $row->category,
                recipientEmail:      'pseudo+' . substr(hash('sha256', $row->recipientEmail), 0, 16) . '@pseudonymized.example',
                recipientUserId:     $row->recipientUserId,
                locale:              $row->locale,
                subject:             $row->subject,
                bodyHtml:            '',
                bodyText:            '',
                payloadVars:         [],
                payloadMeetingId:    $row->payloadMeetingId,
                payloadInvoiceId:    $row->payloadInvoiceId,
                payloadNewsletterId: $row->payloadNewsletterId,
                status:              $row->status,
                attemptCount:        $row->attemptCount,
                lastError:           $row->lastError,
                queuedAt:            $row->queuedAt,
                sentAt:              $row->sentAt,
                queuedBy:            $row->queuedBy,
            );
            $this->pseudonymizedIds[$id] = true;
            $count++;
        }
        return $count;
    }

    /** Test helper — make a freshly-queued outbox row with sensible defaults. */
    public static function makeQueued(
        TenantId $tenantId,
        UserId $queuedBy,
        string $recipientEmail = 'recipient@example.com',
        MailKind $kind = MailKind::GroupMessage,
        MailOutboxStatus $status = MailOutboxStatus::Queued,
        int $attemptCount = 0,
    ): MailOutbox {
        return new MailOutbox(
            id:                  MailOutboxId::generate(),
            tenantId:            $tenantId,
            kind:                $kind,
            category:            $kind->category(),
            recipientEmail:      $recipientEmail,
            recipientUserId:     null,
            locale:              SupportedLocale::fromString('fi_FI'),
            subject:             'Test subject',
            bodyHtml:            '<p>Hi</p>',
            bodyText:            'Hi',
            payloadVars:         [],
            payloadMeetingId:    null,
            payloadInvoiceId:    null,
            payloadNewsletterId: null,
            status:              $status,
            attemptCount:        $attemptCount,
            lastError:           null,
            queuedAt:            new \DateTimeImmutable(),
            sentAt:              null,
            queuedBy:            $queuedBy,
        );
    }

    private function withStatus(MailOutbox $row, MailOutboxStatus $status): MailOutbox
    {
        return new MailOutbox(
            id:                  $row->id,
            tenantId:            $row->tenantId,
            kind:                $row->kind,
            category:            $row->category,
            recipientEmail:      $row->recipientEmail,
            recipientUserId:     $row->recipientUserId,
            locale:              $row->locale,
            subject:             $row->subject,
            bodyHtml:            $row->bodyHtml,
            bodyText:            $row->bodyText,
            payloadVars:         $row->payloadVars,
            payloadMeetingId:    $row->payloadMeetingId,
            payloadInvoiceId:    $row->payloadInvoiceId,
            payloadNewsletterId: $row->payloadNewsletterId,
            status:              $status,
            attemptCount:        $row->attemptCount,
            lastError:           $row->lastError,
            queuedAt:            $row->queuedAt,
            sentAt:              $row->sentAt,
            queuedBy:            $row->queuedBy,
        );
    }

}
