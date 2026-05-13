<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Meeting\Meeting;
use DaemsModule\Communications\Domain\Meeting\MeetingId;
use DaemsModule\Communications\Domain\Meeting\MeetingRepositoryInterface;
use DaemsModule\Communications\Domain\Meeting\MeetingStatus;
use DaemsModule\Communications\Domain\Meeting\MeetingType;

final class SqlMeetingRepository implements MeetingRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(Meeting $meeting): void
    {
        $titleJson    = json_encode($meeting->titleByLocale,        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $agendaJson   = json_encode($meeting->agendaItemsByLocale,  JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $documentJson = json_encode($meeting->documentUrls,         JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->db->execute(
            'INSERT INTO meetings (
                id, tenant_id, type, starts_at, location, remote_url,
                title_i18n, agenda_items_i18n, document_urls, status,
                created_at, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                type              = VALUES(type),
                starts_at         = VALUES(starts_at),
                location          = VALUES(location),
                remote_url        = VALUES(remote_url),
                title_i18n        = VALUES(title_i18n),
                agenda_items_i18n = VALUES(agenda_items_i18n),
                document_urls     = VALUES(document_urls),
                status            = VALUES(status)',
            [
                $meeting->id->value(),
                $meeting->tenantId->value(),
                $meeting->type->value,
                $meeting->startsAt->format('Y-m-d H:i:s.v'),
                $meeting->location,
                $meeting->remoteUrl,
                $titleJson,
                $agendaJson,
                $documentJson,
                $meeting->status->value,
                $meeting->createdAt->format('Y-m-d H:i:s.v'),
                $meeting->createdBy->value(),
            ],
        );
    }

    public function findById(MeetingId $id): ?Meeting
    {
        $row = $this->db->queryOne(
            'SELECT * FROM meetings WHERE id = ?',
            [$id->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listForTenant(
        TenantId $tenantId,
        ?\DateTimeImmutable $startsAfter = null,
        ?\DateTimeImmutable $endsBefore = null,
    ): array {
        $where  = ['tenant_id = ?'];
        $params = [$tenantId->value()];

        if ($startsAfter !== null) {
            $where[]  = 'starts_at >= ?';
            $params[] = $startsAfter->format('Y-m-d H:i:s.v');
        }
        if ($endsBefore !== null) {
            $where[]  = 'starts_at <= ?';
            $params[] = $endsBefore->format('Y-m-d H:i:s.v');
        }

        $sql = 'SELECT * FROM meetings WHERE ' . implode(' AND ', $where) . ' ORDER BY starts_at ASC';

        $out = [];
        foreach ($this->db->query($sql, $params) as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Meeting
    {
        $id           = $this->str($row, 'id');
        $tenantId     = $this->str($row, 'tenant_id');
        $type         = $this->str($row, 'type');
        $startsAt     = $this->str($row, 'starts_at');
        $location     = $this->strOrNull($row, 'location');
        $remoteUrl    = $this->strOrNull($row, 'remote_url');
        $titleJson    = $this->str($row, 'title_i18n');
        $agendaJson   = $this->str($row, 'agenda_items_i18n');
        $documentJson = $this->str($row, 'document_urls');
        $status       = $this->str($row, 'status');
        $createdAt    = $this->str($row, 'created_at');
        $createdBy    = $this->str($row, 'created_by');

        /** @var array<string, string> $titleByLocale */
        $titleByLocale = [];
        $td = json_decode($titleJson, true);
        if (is_array($td)) {
            foreach ($td as $loc => $title) {
                if (is_string($loc) && is_string($title)) {
                    $titleByLocale[$loc] = $title;
                }
            }
        }

        /** @var array<string, list<string>> $agendaItemsByLocale */
        $agendaItemsByLocale = [];
        $ad = json_decode($agendaJson, true);
        if (is_array($ad)) {
            foreach ($ad as $loc => $items) {
                if (!is_string($loc) || !is_array($items)) {
                    continue;
                }
                $list = [];
                foreach ($items as $item) {
                    if (is_string($item)) {
                        $list[] = $item;
                    }
                }
                $agendaItemsByLocale[$loc] = $list;
            }
        }

        /** @var list<string> $documentUrls */
        $documentUrls = [];
        $dd = json_decode($documentJson, true);
        if (is_array($dd)) {
            foreach ($dd as $url) {
                if (is_string($url)) {
                    $documentUrls[] = $url;
                }
            }
        }

        return new Meeting(
            id:                  MeetingId::fromString($id),
            tenantId:            TenantId::fromString($tenantId),
            type:                MeetingType::from($type),
            titleByLocale:       $titleByLocale,
            startsAt:            new \DateTimeImmutable($startsAt),
            location:            $location,
            remoteUrl:           $remoteUrl,
            agendaItemsByLocale: $agendaItemsByLocale,
            documentUrls:        $documentUrls,
            status:              MeetingStatus::from($status),
            createdAt:           new \DateTimeImmutable($createdAt),
            createdBy:           UserId::fromString($createdBy),
        );
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $col): string
    {
        $val = $row[$col] ?? null;
        if (!is_string($val)) {
            throw new \DomainException("Corrupt meetings.{$col}");
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
            throw new \DomainException("Corrupt meetings.{$col}");
        }
        return $val;
    }
}
