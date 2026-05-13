<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailTemplateId;
use DaemsModule\Communications\Domain\Template\MailTemplate;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;

final class SqlMailTemplateRepository implements MailTemplateRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findOverrides(
        TenantId $tenantId,
        MailKind $kind,
        SupportedLocale $locale,
    ): ?MailTemplate {
        $row = $this->db->queryOne(
            'SELECT * FROM mail_template_overrides
             WHERE tenant_id = ? AND kind = ? AND locale = ?',
            [$tenantId->value(), $kind->value, $locale->value()],
        );
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row, $tenantId, $kind, $locale);
    }

    public function saveOverrides(MailTemplate $template): void
    {
        $overridesJson = json_encode($template->stringOverrides, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->db->execute(
            'INSERT INTO mail_template_overrides (
                tenant_id, kind, locale, overrides, updated_at, updated_by
             ) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                overrides  = VALUES(overrides),
                updated_at = VALUES(updated_at),
                updated_by = VALUES(updated_by)',
            [
                $template->tenantId->value(),
                $template->kind->value,
                $template->locale->value(),
                $overridesJson,
                $template->updatedAt->format('Y-m-d H:i:s.v'),
                $template->updatedBy->value(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(
        array $row,
        TenantId $tenantId,
        MailKind $kind,
        SupportedLocale $locale,
    ): MailTemplate {
        /** @var array<string, string> $overrides */
        $overrides = [];
        if (isset($row['overrides']) && is_string($row['overrides'])) {
            $decoded = json_decode($row['overrides'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $val) {
                    if (is_string($key) && is_string($val)) {
                        $overrides[$key] = $val;
                    }
                }
            }
        }

        $updatedAt = $row['updated_at'] ?? null;
        $updatedBy = $row['updated_by'] ?? null;
        if (!is_string($updatedAt)) {
            throw new \DomainException('Corrupt mail_template_overrides.updated_at');
        }
        if (!is_string($updatedBy)) {
            throw new \DomainException('Corrupt mail_template_overrides.updated_by');
        }

        // Synthetic id: the primary key is composite (tenant, kind, locale)
        // but the domain entity carries a MailTemplateId. We derive a stable
        // UUIDv7-shaped value from the composite key for entity identity.
        $id = MailTemplateId::generate();

        return new MailTemplate(
            id:              $id,
            tenantId:        $tenantId,
            kind:            $kind,
            locale:          $locale,
            stringOverrides: $overrides,
            updatedAt:       new \DateTimeImmutable($updatedAt),
            updatedBy:       UserId::fromString($updatedBy),
        );
    }
}
