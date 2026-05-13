<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

enum MailKind: string
{
    case MeetingInvitation  = 'meeting_invitation';
    case PaymentReminder    = 'payment_reminder';
    case MembershipApproved = 'membership_approved';
    case GroupMessage       = 'group_message';
    case Newsletter         = 'newsletter';

    public function category(): CommunicationCategory
    {
        return match ($this) {
            self::MeetingInvitation,
            self::PaymentReminder,
            self::MembershipApproved => CommunicationCategory::Transactional,
            self::GroupMessage       => CommunicationCategory::Operational,
            self::Newsletter         => CommunicationCategory::Marketing,
        };
    }
}
