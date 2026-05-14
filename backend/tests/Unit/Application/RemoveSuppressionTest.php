<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\RemoveSuppression\Input;
use DaemsModule\Communications\Application\RemoveSuppression\RemoveSuppression;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;
use DaemsModule\Communications\Tests\Support\InMemoryMailSuppressionRepository;
use PHPUnit\Framework\TestCase;

final class RemoveSuppressionTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'admin@x',
            isPlatformAdmin:    false,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    public function test_admin_removes_suppression(): void
    {
        $repo = new InMemoryMailSuppressionRepository();
        $repo->add(new MailSuppression(
            tenantId:         $this->tenantId(),
            emailAddress:     'gone@example.com',
            reason:           SuppressionReason::ManualBlock,
            suppressedAt:     new \DateTimeImmutable('2026-05-01T00:00:00Z'),
            smtpResponseCode: null,
            suppressedBy:     null,
        ));
        self::assertCount(1, $repo->listForTenant($this->tenantId()));

        $useCase = new RemoveSuppression($repo);
        $out = $useCase->execute(
            new Input($this->tenantId(), 'gone@example.com'),
            $this->acting(),
        );

        self::assertTrue($out->success);
        self::assertCount(0, $repo->listForTenant($this->tenantId()));
        self::assertFalse($repo->isSuppressed($this->tenantId(), 'gone@example.com'));
    }

    public function test_non_admin_throws_forbidden(): void
    {
        $repo = new InMemoryMailSuppressionRepository();
        $useCase = new RemoveSuppression($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input($this->tenantId(), 'gone@example.com'),
            $this->acting(role: UserTenantRole::Member),
        );
    }
}
