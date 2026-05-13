<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\JoinedWithinPeriod;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\Block\ButtonBlock;
use DaemsModule\Communications\Domain\Template\Block\DividerBlock;
use DaemsModule\Communications\Domain\Template\Block\EventCardBlock;
use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\ImageBlock;
use DaemsModule\Communications\Domain\Template\Block\NewsletterBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use DaemsModule\Communications\Domain\Template\Block\TwoColumnsBlock;
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
                        $list[] = $this->blockFromArray($blockArr);
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
                $arr[] = $this->blockToArray($block);
            }
            $out[$locale] = $arr;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function blockToArray(NewsletterBlock $block): array
    {
        if ($block instanceof TwoColumnsBlock) {
            $left  = [];
            foreach ($block->left as $b) {
                if ($b instanceof NewsletterBlock) {
                    $left[] = $this->blockToArray($b);
                }
            }
            $right = [];
            foreach ($block->right as $b) {
                if ($b instanceof NewsletterBlock) {
                    $right[] = $this->blockToArray($b);
                }
            }
            return ['type' => 'two_columns', 'left' => $left, 'right' => $right];
        }
        if ($block instanceof HeadingBlock) {
            return ['type' => 'heading', 'level' => $block->level, 'text' => $block->text];
        }
        if ($block instanceof ParagraphBlock) {
            return ['type' => 'paragraph', 'markdown' => $block->markdown];
        }
        if ($block instanceof ImageBlock) {
            return ['type' => 'image', 'url' => $block->url, 'alt' => $block->alt, 'caption' => $block->caption];
        }
        if ($block instanceof ButtonBlock) {
            return ['type' => 'button', 'text' => $block->text, 'url' => $block->url];
        }
        if ($block instanceof DividerBlock) {
            return ['type' => 'divider'];
        }
        if ($block instanceof EventCardBlock) {
            return ['type' => 'event_card', 'eventId' => $block->eventId, 'title' => $block->title, 'whenLabel' => $block->whenLabel];
        }
        throw new \DomainException('Unknown newsletter block type: ' . $block::class);
    }

    /**
     * @param array<string, mixed> $arr
     */
    private function blockFromArray(array $arr): NewsletterBlock
    {
        $type = $arr['type'] ?? null;
        if (!is_string($type)) {
            throw new \DomainException('Newsletter block missing type');
        }

        switch ($type) {
            case 'heading':
                $level = isset($arr['level']) && is_int($arr['level']) ? $arr['level'] : 1;
                $text  = isset($arr['text'])  && is_string($arr['text']) ? $arr['text']  : '';
                return new HeadingBlock($level, $text);

            case 'paragraph':
                $md = isset($arr['markdown']) && is_string($arr['markdown']) ? $arr['markdown'] : '';
                return new ParagraphBlock($md);

            case 'image':
                $url     = isset($arr['url'])     && is_string($arr['url'])     ? $arr['url']     : '';
                $alt     = isset($arr['alt'])     && is_string($arr['alt'])     ? $arr['alt']     : '';
                $caption = isset($arr['caption']) && is_string($arr['caption']) ? $arr['caption'] : null;
                return new ImageBlock($url, $alt, $caption);

            case 'button':
                $text = isset($arr['text']) && is_string($arr['text']) ? $arr['text'] : '';
                $url  = isset($arr['url'])  && is_string($arr['url'])  ? $arr['url']  : '';
                return new ButtonBlock($text, $url);

            case 'divider':
                return new DividerBlock();

            case 'event_card':
                $eventId   = isset($arr['eventId'])   && is_string($arr['eventId'])   ? $arr['eventId']   : '';
                $title     = isset($arr['title'])     && is_string($arr['title'])     ? $arr['title']     : null;
                $whenLabel = isset($arr['whenLabel']) && is_string($arr['whenLabel']) ? $arr['whenLabel'] : null;
                return new EventCardBlock($eventId, $title, $whenLabel);

            case 'two_columns':
                $left  = [];
                $right = [];
                if (isset($arr['left']) && is_array($arr['left'])) {
                    foreach ($arr['left'] as $child) {
                        if (is_array($child)) {
                            /** @var array<string, mixed> $child */
                            $left[] = $this->blockFromArray($child);
                        }
                    }
                }
                if (isset($arr['right']) && is_array($arr['right'])) {
                    foreach ($arr['right'] as $child) {
                        if (is_array($child)) {
                            /** @var array<string, mixed> $child */
                            $right[] = $this->blockFromArray($child);
                        }
                    }
                }
                return new TwoColumnsBlock($left, $right);

            default:
                throw new \DomainException('Unknown newsletter block type: ' . $type);
        }
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
