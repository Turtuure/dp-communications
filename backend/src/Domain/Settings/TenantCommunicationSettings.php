<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Settings;

use Daems\Domain\Tenant\TenantId;

final class TenantCommunicationSettings
{
    /**
     * @param list<int> $reminderPostDueDays
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly ?string $smtpDsnEncrypted,
        public readonly ?string $mailFromAddress,
        public readonly ?string $mailDisplayName,
        public readonly ?string $mailReplyTo,
        public readonly ?\DateTimeImmutable $smtpTestSucceededAt,
        public readonly int $reminderPreDueDays,
        public readonly array $reminderPostDueDays,
        public readonly int $lapseWarningDaysBefore,
        public readonly ?string $brandLogoUrl,
        public readonly ?string $brandPrimaryColor,
        public readonly ?string $brandFooterAddress,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    public function isSmtpConfigured(): bool
    {
        return $this->smtpDsnEncrypted !== null && $this->mailFromAddress !== null;
    }
}
