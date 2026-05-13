<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Meeting\MeetingId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

final class MailOutbox
{
    /**
     * @param array<string, mixed> $payloadVars
     */
    public function __construct(
        public readonly MailOutboxId $id,
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly CommunicationCategory $category,
        public readonly string $recipientEmail,
        public readonly ?UserId $recipientUserId,
        public readonly SupportedLocale $locale,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly string $bodyText,
        public readonly array $payloadVars,
        public readonly ?MeetingId $payloadMeetingId,
        public readonly ?MemberFeeInvoiceId $payloadInvoiceId,
        public readonly ?NewsletterId $payloadNewsletterId,
        public readonly MailOutboxStatus $status,
        public readonly int $attemptCount,
        public readonly ?string $lastError,
        public readonly \DateTimeImmutable $queuedAt,
        public readonly ?\DateTimeImmutable $sentAt,
        public readonly UserId $queuedBy,
    ) {
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid recipient email: ' . $recipientEmail);
        }
        if ($attemptCount < 0) {
            throw new \InvalidArgumentException('attemptCount must be >= 0');
        }
        if (trim($subject) === '') {
            throw new \InvalidArgumentException('Subject cannot be empty');
        }
    }
}
