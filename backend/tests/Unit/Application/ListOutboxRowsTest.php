<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\ListOutboxRows\Input;
use DaemsModule\Communications\Application\ListOutboxRows\ListOutboxRows;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
use PHPUnit\Framework\TestCase;

final class ListOutboxRowsTest extends TestCase
{
    private const TENANT       = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';

    private function tenantId(string $value = self::TENANT): TenantId
    {
        return TenantId::fromString($value);
    }

    private function acting(?UserTenantRole $role, bool $isPlatformAdmin = false, ?TenantId $active = null): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'a@x',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $active ?? $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    public function test_admin_can_list_rows_in_own_tenant(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $queuer = UserId::generate();
        $repo->save(InMemoryMailOutboxRepository::makeQueued($this->tenantId(), $queuer));
        $repo->save(InMemoryMailOutboxRepository::makeQueued($this->tenantId(), $queuer, 'b@example.com'));

        $useCase = new ListOutboxRows($repo);
        $output = $useCase->execute(new Input(tenantId: $this->tenantId()), $this->acting(UserTenantRole::Admin));

        self::assertSame(2, $output->totalCount);
        self::assertCount(2, $output->rows);
    }

    public function test_moderator_can_list_rows_in_own_tenant(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $repo->save(InMemoryMailOutboxRepository::makeQueued($this->tenantId(), UserId::generate()));

        $useCase = new ListOutboxRows($repo);
        $output = $useCase->execute(new Input(tenantId: $this->tenantId()), $this->acting(UserTenantRole::Moderator));

        self::assertSame(1, $output->totalCount);
    }

    public function test_member_cannot_list_rows(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $useCase = new ListOutboxRows($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(new Input(tenantId: $this->tenantId()), $this->acting(UserTenantRole::Member));
    }

    public function test_platform_admin_can_list_other_tenant(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $repo->save(InMemoryMailOutboxRepository::makeQueued($this->tenantId(self::OTHER_TENANT), UserId::generate()));

        $useCase = new ListOutboxRows($repo);
        $output = $useCase->execute(
            new Input(tenantId: $this->tenantId(self::OTHER_TENANT)),
            $this->acting(UserTenantRole::Member, isPlatformAdmin: true),
        );

        self::assertSame(1, $output->totalCount);
    }

    public function test_filter_by_status_passes_through(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $queuer = UserId::generate();
        $sentRow = InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            $queuer,
            status: MailOutboxStatus::Sent,
        );
        $queued = InMemoryMailOutboxRepository::makeQueued($this->tenantId(), $queuer);
        $repo->save($sentRow);
        $repo->save($queued);

        $useCase = new ListOutboxRows($repo);
        $output = $useCase->execute(
            new Input(tenantId: $this->tenantId(), status: MailOutboxStatus::Sent),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(1, $output->totalCount);
        self::assertSame($sentRow->id->value(), $output->rows[0]->id->value());
    }

    public function test_filter_by_kind_passes_through(): void
    {
        $repo = new InMemoryMailOutboxRepository();
        $queuer = UserId::generate();
        $repo->save(InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            $queuer,
            kind: MailKind::PaymentReminder,
        ));
        $repo->save(InMemoryMailOutboxRepository::makeQueued(
            $this->tenantId(),
            $queuer,
            kind: MailKind::GroupMessage,
        ));

        $useCase = new ListOutboxRows($repo);
        $output = $useCase->execute(
            new Input(tenantId: $this->tenantId(), kind: MailKind::PaymentReminder),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(1, $output->totalCount);
        self::assertSame(MailKind::PaymentReminder, $output->rows[0]->kind);
    }
}
