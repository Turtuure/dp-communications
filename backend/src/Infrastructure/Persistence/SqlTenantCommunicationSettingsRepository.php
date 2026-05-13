<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Persistence;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;

final class SqlTenantCommunicationSettingsRepository implements TenantCommunicationSettingsRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findForTenant(TenantId $tenantId): TenantCommunicationSettings
    {
        $row = $this->db->queryOne(
            'SELECT * FROM tenant_communication_settings WHERE tenant_id = ?',
            [$tenantId->value()],
        );

        if ($row === null) {
            // Race-condition / pre-seed fallback. Migration 098 seeds one row
            // per tenant — this default exists for safety only.
            return new TenantCommunicationSettings(
                tenantId:               $tenantId,
                smtpDsnEncrypted:       null,
                mailFromAddress:        null,
                mailDisplayName:        null,
                mailReplyTo:            null,
                smtpTestSucceededAt:    null,
                reminderPreDueDays:     7,
                reminderPostDueDays:    [14, 30],
                lapseWarningDaysBefore: 30,
                brandLogoUrl:           null,
                brandPrimaryColor:      null,
                brandFooterAddress:     null,
                updatedAt:              new \DateTimeImmutable('now'),
            );
        }

        return $this->hydrate($row, $tenantId);
    }

    public function save(TenantCommunicationSettings $settings): void
    {
        $remindersJson = json_encode($settings->reminderPostDueDays, JSON_THROW_ON_ERROR);

        $this->db->execute(
            'INSERT INTO tenant_communication_settings (
                tenant_id, smtp_dsn_encrypted, mail_from_address, mail_display_name, mail_reply_to,
                smtp_test_succeeded_at, reminder_pre_due_days, reminder_post_due_days,
                lapse_warning_days_before, brand_logo_url, brand_primary_color, brand_footer_address
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                smtp_dsn_encrypted        = VALUES(smtp_dsn_encrypted),
                mail_from_address         = VALUES(mail_from_address),
                mail_display_name         = VALUES(mail_display_name),
                mail_reply_to             = VALUES(mail_reply_to),
                smtp_test_succeeded_at    = VALUES(smtp_test_succeeded_at),
                reminder_pre_due_days     = VALUES(reminder_pre_due_days),
                reminder_post_due_days    = VALUES(reminder_post_due_days),
                lapse_warning_days_before = VALUES(lapse_warning_days_before),
                brand_logo_url            = VALUES(brand_logo_url),
                brand_primary_color       = VALUES(brand_primary_color),
                brand_footer_address      = VALUES(brand_footer_address)',
            [
                $settings->tenantId->value(),
                $settings->smtpDsnEncrypted,
                $settings->mailFromAddress,
                $settings->mailDisplayName,
                $settings->mailReplyTo,
                $settings->smtpTestSucceededAt?->format('Y-m-d H:i:s.v'),
                $settings->reminderPreDueDays,
                $remindersJson,
                $settings->lapseWarningDaysBefore,
                $settings->brandLogoUrl,
                $settings->brandPrimaryColor,
                $settings->brandFooterAddress,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row, TenantId $tenantId): TenantCommunicationSettings
    {
        $smtpDsn         = $this->strOrNull($row, 'smtp_dsn_encrypted');
        $mailFrom        = $this->strOrNull($row, 'mail_from_address');
        $mailDisplayName = $this->strOrNull($row, 'mail_display_name');
        $mailReplyTo     = $this->strOrNull($row, 'mail_reply_to');
        $smtpTestAt      = $this->strOrNull($row, 'smtp_test_succeeded_at');
        $preDue          = $this->intVal($row,    'reminder_pre_due_days');
        $postDueJson     = $this->str($row,       'reminder_post_due_days');
        $lapseWarn       = $this->intVal($row,    'lapse_warning_days_before');
        $brandLogo       = $this->strOrNull($row, 'brand_logo_url');
        $brandColor      = $this->strOrNull($row, 'brand_primary_color');
        $brandFooter     = $this->strOrNull($row, 'brand_footer_address');
        $updatedAt       = $this->str($row,       'updated_at');

        /** @var list<int> $postDue */
        $postDue = [];
        $decoded = json_decode($postDueJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $d) {
                if (is_int($d)) {
                    $postDue[] = $d;
                } elseif (is_string($d) && ctype_digit($d)) {
                    $postDue[] = (int) $d;
                }
            }
        }

        return new TenantCommunicationSettings(
            tenantId:               $tenantId,
            smtpDsnEncrypted:       $smtpDsn,
            mailFromAddress:        $mailFrom,
            mailDisplayName:        $mailDisplayName,
            mailReplyTo:            $mailReplyTo,
            smtpTestSucceededAt:    $smtpTestAt !== null ? new \DateTimeImmutable($smtpTestAt) : null,
            reminderPreDueDays:     $preDue,
            reminderPostDueDays:    $postDue,
            lapseWarningDaysBefore: $lapseWarn,
            brandLogoUrl:           $brandLogo,
            brandPrimaryColor:      $brandColor,
            brandFooterAddress:     $brandFooter,
            updatedAt:              new \DateTimeImmutable($updatedAt),
        );
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $col): string
    {
        $val = $row[$col] ?? null;
        if (!is_string($val)) {
            throw new \DomainException("Corrupt tenant_communication_settings.{$col}");
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
            throw new \DomainException("Corrupt tenant_communication_settings.{$col}");
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
        throw new \DomainException("Corrupt tenant_communication_settings.{$col}");
    }
}
