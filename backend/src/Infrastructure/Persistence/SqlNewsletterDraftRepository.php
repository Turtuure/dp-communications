<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\JoinedWithinPeriod;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\Block\BlockSerializer;
use DaemsModule\Communications\Domain\Template\Block\NewsletterBlock;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;

final class SqlNewsletterDraftRepository implements NewsletterDraftRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(NewsletterDraft $draft): void
    {
        $subjectJson  = json_encode($draft->subjectByLocale, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $blocksJson   = json_encode($this->encodeBlocksByLocale($draft->blocksByLocale), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $audienceJson = json_encode($this->encodeAudience($draft->audience), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->db->execute(
            'INSERT INTO newsletter_drafts (
                id, tenant_id, internal_name, subject_i18n, blocks_i18n, audience_filter,
                status, sent_at, created_at, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                internal_name   = VALUES(internal_name),
                subject_i18n    = VALUES(subject_i18n),
                blocks_i18n     = VALUES(blocks_i18n),
                audience_filter = VALUES(audience_filter),
                status          = VALUES(status),
                sent_at         = VALUES(sent_at)',
            [
                $draft->id->value(),
                $draft->tenantId->value(),
                $draft->internalName,
                $subjectJson,
                $blocksJson,
                $audienceJson,
                $draft->status->value,
                $draft->sentAt?->format('Y-m-d H:i:s.v'),
                $draft->createdAt->format('Y-m-d H:i:s.v'),
                $draft->createdBy->value(),
            ],
        );
    }

    public function findById(NewsletterId $id): ?NewsletterDraft
    {
        $row = $this->db->queryOne(
            'SELECT * FROM newsletter_drafts WHERE id = ?',
            [$id->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listForTenant(TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM newsletter_drafts WHERE tenant_id = ? ORDER BY created_at DESC',
            [$tenantId->value()],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function delete(NewsletterId $id): void
    {
        $this->db->execute(
            'DELETE FROM newsletter_drafts WHERE id = ?',
            [$id->value()],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): NewsletterDraft
    {
        $id            = $this->str($row, 'id');
        $tenantId      = $this->str($row, 'tenant_id');
        $internalName  = $this->str($row, 'internal_name');
        $subjectJson   = $this->str($row, 'subject_i18n');
        $blocksJson    = $this->str($row, 'blocks_i18n');
        $audienceJson  = $this->str($row, 'audience_filter');
        $status        = $this->str($row, 'status');
        $sentAt        = $this->strOrNull($row, 'sent_at');
        $createdAt     = $this->str($row, 'created_at');
        $createdBy     = $this->str($row, 'created_by');

        /** @var array<string, string> $subjectByLocale */
        $subjectByLocale = [];
        $subjectDecoded = json_decode($subjectJson, true);
        if (is_array($subjectDecoded)) {
            foreach ($subjectDecoded as $loc => $subj) {
                if (is_string($loc) && is_string($subj)) {
                    $subjectByLocale[$loc] = $subj;
                }
            }
        }

        /** @var array<string, list<NewsletterBlock>> $blocksByLocale */
        $blocksByLocale = [];
        $blocksDecoded = json_decode($blocksJson, true);
        if (is_array($blocksDecoded)) {
            foreach ($blocksDecoded as $loc => $blocks) {
                if (!is_string($loc) || !is_array($blocks)) {
                    continue;
                }
                $list = [];
                foreach ($blocks as $blockArr) {
                    if (is_array($blockArr)) {
                        /** @var array<string, mixed> $blockArr */
                        $list[] = BlockSerializer::fromArray($blockArr);
                    }
                }
                $blocksByLocale[$loc] = $list;
            }
        }

        $audienceDecoded = json_decode($audienceJson, true);
        $audience        = $this->audienceFromArray(is_array($audienceDecoded) ? $audienceDecoded : []);

        return new NewsletterDraft(
            id:               NewsletterId::fromString($id),
            tenantId:         TenantId::fromString($tenantId),
            internalName:     $internalName,
            subjectByLocale:  $subjectByLocale,
            blocksByLocale:   $blocksByLocale,
            audience:         $audience,
            status:           NewsletterStatus::from($status),
            sentAt:           $sentAt !== null ? new \DateTimeImmutable($sentAt) : null,
            createdAt:        new \DateTimeImmutable($createdAt),
            createdBy:        UserId::fromString($createdBy),
        );
    }

    /**
     * @param array<string, list<NewsletterBlock>> $blocksByLocale
     * @return array<string, list<array<string, mixed>>>
     */
    private function encodeBlocksByLocale(array $blocksByLocale): array
    {
        $out = [];
        foreach ($blocksByLocale as $locale => $blocks) {
            $arr = [];
            foreach ($blocks as $block) {
                $arr[] = BlockSerializer::toArray($block);
            }
            $out[$locale] = $arr;
        }
        return $out;
    }

    /**
     * @return array{membershipTypes: list<string>, locales: list<string>, joinedWithin: ?string, applicationStatuses: list<string>}
     */
    private function encodeAudience(AudienceFilter $audience): array
    {
        return [
            'membershipTypes'     => $audience->membershipTypes,
            'locales'             => $audience->locales,
            'joinedWithin'        => $audience->joinedWithin?->value,
            'applicationStatuses' => $audience->applicationStatuses,
        ];
    }

    /**
     * @param array<string, mixed> $arr
     */
    private function audienceFromArray(array $arr): AudienceFilter
    {
        /** @var list<string> $membershipTypes */
        $membershipTypes = [];
        if (isset($arr['membershipTypes']) && is_array($arr['membershipTypes'])) {
            foreach ($arr['membershipTypes'] as $v) {
                if (is_string($v)) {
                    $membershipTypes[] = $v;
                }
            }
        }
        /** @var list<string> $locales */
        $locales = [];
        if (isset($arr['locales']) && is_array($arr['locales'])) {
            foreach ($arr['locales'] as $v) {
                if (is_string($v)) {
                    $locales[] = $v;
                }
            }
        }
        /** @var list<string> $applicationStatuses */
        $applicationStatuses = [];
        if (isset($arr['applicationStatuses']) && is_array($arr['applicationStatuses'])) {
            foreach ($arr['applicationStatuses'] as $v) {
                if (is_string($v)) {
                    $applicationStatuses[] = $v;
                }
            }
        }
        $joinedWithin = null;
        if (isset($arr['joinedWithin']) && is_string($arr['joinedWithin'])) {
            $joinedWithin = JoinedWithinPeriod::tryFrom($arr['joinedWithin']);
        }

        return new AudienceFilter(
            membershipTypes:     $membershipTypes,
            locales:             $locales,
            joinedWithin:        $joinedWithin,
            applicationStatuses: $applicationStatuses,
        );
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $col): string
    {
        $val = $row[$col] ?? null;
        if (!is_string($val)) {
            throw new \DomainException("Corrupt newsletter_drafts.{$col}");
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
            throw new \DomainException("Corrupt newsletter_drafts.{$col}");
        }
        return $val;
    }
}
