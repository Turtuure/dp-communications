<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Domain\Mail;

use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use PHPUnit\Framework\TestCase;

final class MailKindTest extends TestCase
{
    public function test_each_kind_maps_to_category(): void
    {
        $this->assertSame(CommunicationCategory::Transactional, MailKind::MeetingInvitation->category());
        $this->assertSame(CommunicationCategory::Transactional, MailKind::PaymentReminder->category());
        $this->assertSame(CommunicationCategory::Transactional, MailKind::MembershipApproved->category());
        $this->assertSame(CommunicationCategory::Operational, MailKind::GroupMessage->category());
        $this->assertSame(CommunicationCategory::Marketing, MailKind::Newsletter->category());
    }
}
