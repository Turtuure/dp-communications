<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Mailer;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Mail\Exception\MailerTransportException;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Mailer\InMemoryMailer;
use PHPUnit\Framework\TestCase;

final class InMemoryMailerTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    public function test_send_stores_row_and_settings(): void
    {
        $mailer = new InMemoryMailer();
        $row = $this->makeRow();
        $settings = $this->makeSettings();

        $mailer->send($row, $settings);

        self::assertCount(1, $mailer->sent);
        self::assertSame($row, $mailer->sent[0]['row']);
        self::assertSame($settings, $mailer->sent[0]['settings']);
    }

    public function test_clear_resets_sent_list_and_failure_flag(): void
    {
        $mailer = new InMemoryMailer();
        $mailer->send($this->makeRow(), $this->makeSettings());
        $mailer->simulateFailure = new MailerTransportException('boom');

        $mailer->clear();

        self::assertSame([], $mailer->sent);
        self::assertNull($mailer->simulateFailure);
    }

    public function test_simulate_failure_throws_on_next_send_and_clears_flag(): void
    {
        $mailer = new InMemoryMailer();
        $mailer->simulateFailure = new MailerTransportException('connection refused');

        try {
            $mailer->send($this->makeRow(), $this->makeSettings());
            self::fail('Expected MailerTransportException to be thrown.');
        } catch (MailerTransportException $e) {
            self::assertSame('connection refused', $e->getMessage());
        }

        // Flag auto-resets so a subsequent send succeeds and is captured.
        self::assertNull($mailer->simulateFailure);
        $mailer->send($this->makeRow(), $this->makeSettings());
        self::assertCount(1, $mailer->sent);
    }

    private function makeRow(): MailOutbox
    {
        $now = new \DateTimeImmutable();

        return new MailOutbox(
            id:                  MailOutboxId::generate(),
            tenantId:            TenantId::fromString(self::TENANT),
            kind:                MailKind::MembershipApproved,
            category:            CommunicationCategory::Transactional,
            recipientEmail:      'recipient@example.com',
            recipientUserId:     null,
            locale:              SupportedLocale::contentFallback(),
            subject:             'Test message',
            bodyHtml:            '<p>hello</p>',
            bodyText:            "hello\n",
            payloadVars:         [],
            payloadMeetingId:    null,
            payloadInvoiceId:    null,
            payloadNewsletterId: null,
            status:              MailOutboxStatus::Queued,
            attemptCount:        0,
            lastError:           null,
            queuedAt:            $now,
            sentAt:              null,
            queuedBy:            UserId::generate(),
        );
    }

    private function makeSettings(): TenantCommunicationSettings
    {
        return new TenantCommunicationSettings(
            tenantId:               TenantId::fromString(self::TENANT),
            smtpDsnEncrypted:       'opaque-ciphertext',
            mailFromAddress:        'noreply@example.com',
            mailDisplayName:        'Example',
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [7, 30],
            lapseWarningDaysBefore: 14,
            brandLogoUrl:           null,
            brandPrimaryColor:      null,
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable(),
        );
    }
}
