<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\RetryOutboxRow\Input;
use DaemsModule\Communications\Application\RetryOutboxRow\RetryOutboxRow;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
use PHPUnit\Framework\TestCase;

final class RetryOutboxRowTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'a@x',
            isPlatformAdmin:    false,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    public function test_retry_succeeds_for_failed_row(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $row = InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            UserId::generate(),
            status: MailOutboxStatus::Failed,
            attemptCount: 2,
        );
        // Force a non-null lastError to confirm reset behaviour.
        $repo->save($row);
        $repo->markStatus($row->id, MailOutboxStatus::Failed, 'previous SMTP 451');

        $useCase = new RetryOutboxRow($repo);
        $output = $useCase->execute(new Input(rowId: $row->id), $this->acting(UserTenantRole::Admin));

        self::assertTrue($output->success);
        $reloaded = $repo->findById($row->id);
        self::assertNotNull($reloaded);
        self::assertSame(MailOutboxStatus::Queued, $reloaded->status);
        self::assertSame(0, $reloaded->attemptCount);
        self::assertNull($reloaded->lastError);
    }

    public function test_retry_throws_when_row_not_found(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $useCase = new RetryOutboxRow($repo);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $useCase->execute(
            new Input(rowId: MailOutboxId::generate()),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_retry_throws_when_row_not_failed(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $statuses = [
            MailOutboxStatus::Queued,
            MailOutboxStatus::Sending,
            MailOutboxStatus::Sent,
            MailOutboxStatus::Bounced,
            MailOutboxStatus::Suppressed,
        ];
        foreach ($statuses as $status) {
            $row = InMemoryMailOutboxRepository::makeQueued(
                $this->tenantId(),
                UserId::generate(),
                status: $status,
            );
            $repo->save($row);
            $useCase = new RetryOutboxRow($repo);

            try {
                $useCase->execute(new Input(rowId: $row->id), $this->acting(UserTenantRole::Admin));
                self::fail('Expected DomainException for status ' . $status->value);
            } catch (\DomainException $e) {
                self::assertStringContainsString($status->value, $e->getMessage());
            }
        }
    }

    public function test_retry_requires_admin(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $row = InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            UserId::generate(),
            status: MailOutboxStatus::Failed,
        );
        $repo->save($row);

        $useCase = new RetryOutboxRow($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(new Input(rowId: $row->id), $this->acting(UserTenantRole::Member));
    }
}
