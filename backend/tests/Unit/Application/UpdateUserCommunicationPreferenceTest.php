<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\UpdateUserCommunicationPreference\Input;
use DaemsModule\Communications\Application\UpdateUserCommunicationPreference\UpdateUserCommunicationPreference;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Tests\Support\InMemoryUserCommunicationPreferenceRepository;
use PHPUnit\Framework\TestCase;

final class UpdateUserCommunicationPreferenceTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const USER_A = '01958000-0000-7000-8000-0000000000a1';
    private const USER_B = '01958000-0000-7000-8000-0000000000b1';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(
        string $userId,
        ?UserTenantRole $role = null,
        bool $isPlatformAdmin = false,
    ): ActingUser {
        return new ActingUser(
            id:                 UserId::fromString($userId),
            email:              'u@x',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    public function test_updating_transactional_throws(): void
    {
        $repo = new InMemoryUserCommunicationPreferenceRepository();
        $useCase = new UpdateUserCommunicationPreference($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                userId:   UserId::fromString(self::USER_A),
                tenantId: $this->tenantId(),
                category: CommunicationCategory::Transactional,
                optedIn:  false,
            ),
            // Even an admin cannot disable transactional.
            $this->acting(self::USER_B, UserTenantRole::Admin),
        );
    }

    public function test_user_can_update_own_preferences(): void
    {
        $repo = new InMemoryUserCommunicationPreferenceRepository();
        $useCase = new UpdateUserCommunicationPreference($repo);

        $out = $useCase->execute(
            new Input(
                userId:   UserId::fromString(self::USER_A),
                tenantId: $this->tenantId(),
                category: CommunicationCategory::Marketing,
                optedIn:  true,
            ),
            $this->acting(self::USER_A, UserTenantRole::Member),
        );

        self::assertTrue($out->success);
        $stored = $repo->findFor(UserId::fromString(self::USER_A), $this->tenantId());
        self::assertCount(1, $stored);
        self::assertSame(CommunicationCategory::Marketing, $stored[0]->category);
        self::assertTrue($stored[0]->optedIn);
    }

    public function test_admin_can_update_other_users_preferences(): void
    {
        $repo = new InMemoryUserCommunicationPreferenceRepository();
        $useCase = new UpdateUserCommunicationPreference($repo);

        $out = $useCase->execute(
            new Input(
                userId:   UserId::fromString(self::USER_A),
                tenantId: $this->tenantId(),
                category: CommunicationCategory::Operational,
                optedIn:  false,
            ),
            // Acting user is admin, but a *different* userId.
            $this->acting(self::USER_B, UserTenantRole::Admin),
        );

        self::assertTrue($out->success);
        $stored = $repo->findFor(UserId::fromString(self::USER_A), $this->tenantId());
        self::assertCount(1, $stored);
        self::assertFalse($stored[0]->optedIn);
    }

    public function test_non_admin_cannot_update_other_users(): void
    {
        $repo = new InMemoryUserCommunicationPreferenceRepository();
        $useCase = new UpdateUserCommunicationPreference($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                userId:   UserId::fromString(self::USER_A),
                tenantId: $this->tenantId(),
                category: CommunicationCategory::Marketing,
                optedIn:  true,
            ),
            // Acting user is a non-admin member acting on USER_A.
            $this->acting(self::USER_B, UserTenantRole::Member),
        );
    }
}
