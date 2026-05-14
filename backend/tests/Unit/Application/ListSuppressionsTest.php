<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\ListSuppressions\Input;
use DaemsModule\Communications\Application\ListSuppressions\ListSuppressions;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;
use DaemsModule\Communications\Tests\Support\InMemoryMailSuppressionRepository;
use PHPUnit\Framework\TestCase;

final class ListSuppressionsTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(?UserTenantRole $role = UserTenantRole::Admin, bool $isPlatformAdmin = false): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'admin@x',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    public function test_admin_lists_suppressions(): void
    {
        $repo = new InMemoryMailSuppressionRepository();
        $repo->add(new MailSuppression(
            tenantId:         $this->tenantId(),
            emailAddress:     'blocked@example.com',
            reason:           SuppressionReason::HardBounce,
            suppressedAt:     new \DateTimeImmutable('2026-05-01T00:00:00Z'),
            smtpResponseCode: '550',
            suppressedBy:     null,
        ));

        $useCase = new ListSuppressions($repo);
        $out = $useCase->execute(new Input($this->tenantId()), $this->acting());

        self::assertCount(1, $out->suppressions);
        self::assertSame('blocked@example.com', $out->suppressions[0]->emailAddress);
        self::assertSame(SuppressionReason::HardBounce, $out->suppressions[0]->reason);
    }

    public function test_non_admin_throws_forbidden(): void
    {
        $repo = new InMemoryMailSuppressionRepository();
        $useCase = new ListSuppressions($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input($this->tenantId()),
            $this->acting(role: UserTenantRole::Member),
        );
    }
}
