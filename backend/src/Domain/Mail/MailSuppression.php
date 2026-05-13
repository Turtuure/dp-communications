<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class MailSuppression
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $emailAddress,
        public readonly SuppressionReason $reason,
        public readonly \DateTimeImmutable $suppressedAt,
        public readonly ?string $smtpResponseCode,
        public readonly ?UserId $suppressedBy,
    ) {
        if (filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid suppression email: ' . $emailAddress);
        }
    }
}
