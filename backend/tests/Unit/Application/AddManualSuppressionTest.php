<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use DaemsModule\Communications\Application\AddManualSuppression\AddManualSuppression;
use DaemsModule\Communications\Application\AddManualSuppression\Input;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;
use DaemsModule\Communications\Tests\Support\InMemoryMailSuppressionRepository;
use PHPUnit\Framework\TestCase;

final class AddManualSuppressionTest extends TestCase
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

    public function test_admin_adds_manual_suppression(): void
    {
        $repo = new InMemoryMailSuppressionRepository();
        $useCase = new AddManualSuppression($repo, FrozenClock::at('2026-05-14T09:00:00Z'));

        $out = $useCase->execute(
            new Input(
                tenantId:     $this->tenantId(),
                emailAddress: 'block-me@example.com',
                reason:       SuppressionReason::ManualBlock,
            ),
            $this->acting(),
        );

        self::assertTrue($out->success);
        $list = $repo->listForTenant($this->tenantId());
        self::assertCount(1, $list);
        self::assertSame('block-me@example.com', $list[0]->emailAddress);
        self::assertSame(SuppressionReason::ManualBlock, $list[0]->reason);
        self::assertNotNull($list[0]->suppressedBy);
    }

    public function test_non_admin_throws_forbidden(): void
    {
        $repo = new InMemoryMailSuppressionRepository();
        $useCase = new AddManualSuppression($repo, FrozenClock::at('2026-05-14T09:00:00Z'));

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                tenantId:     $this->tenantId(),
                emailAddress: 'block-me@example.com',
            ),
            $this->acting(role: UserTenantRole::Member),
        );
    }
}
