<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending\Input;
use DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending\MarkSuppressedRecipientsInPending;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMailSuppressionRepository;
use PHPUnit\Framework\TestCase;

final class MarkSuppressedRecipientsInPendingTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    public function test_flips_queued_rows_with_suppressed_recipients(): void
    {
        $outbox = new InMemoryMailOutboxRepository();
        $suppression = new InMemoryMailSuppressionRepository();

        $queuer = UserId::generate();
        $blocked = InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            $queuer,
            recipientEmail: 'blocked@example.com',
        );
        $clean = InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            $queuer,
            recipientEmail: 'clean@example.com',
        );
        $outbox->save($blocked);
        $outbox->save($clean);

        $suppression->add(new MailSuppression(
            tenantId:         $this->tenantId(),
            emailAddress:     'blocked@example.com',
            reason:           SuppressionReason::HardBounce,
            suppressedAt:     new \DateTimeImmutable(),
            smtpResponseCode: '5.1.1',
            suppressedBy:     null,
        ));

        $useCase = new MarkSuppressedRecipientsInPending($outbox, $suppression);
        $output = $useCase->execute(new Input(), null);

        self::assertSame(1, $output->marked);

        $blockedReloaded = $outbox->findById($blocked->id);
        self::assertNotNull($blockedReloaded);
        self::assertSame(MailOutboxStatus::Suppressed, $blockedReloaded->status);
        self::assertSame('recipient on suppression list', $blockedReloaded->lastError);

        $cleanReloaded = $outbox->findById($clean->id);
        self::assertNotNull($cleanReloaded);
        self::assertSame(MailOutboxStatus::Queued, $cleanReloaded->status);
    }

    public function test_zero_when_nothing_to_mark(): void
    {
        $outbox = new InMemoryMailOutboxRepository();
        $suppression = new InMemoryMailSuppressionRepository();

        $outbox->save(InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            UserId::generate(),
            recipientEmail: 'fine@example.com',
        ));

        $useCase = new MarkSuppressedRecipientsInPending($outbox, $suppression);
        $output = $useCase->execute(new Input(), null);

        self::assertSame(0, $output->marked);
    }

    public function test_only_queued_rows_are_considered(): void
    {
        $outbox = new InMemoryMailOutboxRepository();
        $suppression = new InMemoryMailSuppressionRepository();

        // A row in `Sent` status to the suppressed address must NOT be touched.
        $alreadySent = InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            UserId::generate(),
            recipientEmail: 'blocked@example.com',
            status: MailOutboxStatus::Sent,
        );
        $outbox->save($alreadySent);

        $suppression->add(new MailSuppression(
            tenantId:         $this->tenantId(),
            emailAddress:     'blocked@example.com',
            reason:           SuppressionReason::ManualBlock,
            suppressedAt:     new \DateTimeImmutable(),
            smtpResponseCode: null,
            suppressedBy:     null,
        ));

        $useCase = new MarkSuppressedRecipientsInPending($outbox, $suppression);
        $output = $useCase->execute(new Input(), null);

        self::assertSame(0, $output->marked);

        $sentReloaded = $outbox->findById($alreadySent->id);
        self::assertNotNull($sentReloaded);
        self::assertSame(MailOutboxStatus::Sent, $sentReloaded->status);
    }
}
