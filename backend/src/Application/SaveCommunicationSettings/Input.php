<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SaveCommunicationSettings;

use Daems\Domain\Tenant\TenantId;

final class Input
{
    /**
     * @param list<int>|null $reminderPostDueDays
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly ?string $plainSmtpDsn = null,
        public readonly ?string $mailFromAddress = null,
        public readonly ?string $mailDisplayName = null,
        public readonly ?string $mailReplyTo = null,
        public readonly ?int $reminderPreDueDays = null,
        public readonly ?array $reminderPostDueDays = null,
        public readonly ?int $lapseWarningDaysBefore = null,
        public readonly ?string $brandLogoUrl = null,
        public readonly ?string $brandPrimaryColor = null,
        public readonly ?string $brandFooterAddress = null,
    ) {
    }
}
