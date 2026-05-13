<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application\GetTemplateOverrides;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\GetTemplateOverrides\GetTemplateOverrides;
use DaemsModule\Communications\Application\GetTemplateOverrides\Input;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailTemplateId;
use DaemsModule\Communications\Domain\Template\MailTemplate;
use DaemsModule\Communications\Tests\Support\InMemoryMailTemplateRepository;
use PHPUnit\Framework\TestCase;

final class GetTemplateOverridesTest extends TestCase
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

    public function test_non_admin_throws_forbidden(): void
    {
        $repo = new InMemoryMailTemplateRepository();
        $useCase = new GetTemplateOverrides($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                tenantId: $this->tenantId(),
                kind:     MailKind::MeetingInvitation,
                locale:   SupportedLocale::fromString('fi_FI'),
            ),
            $this->acting(UserTenantRole::Member),
        );
    }

    public function test_empty_when_no_row_exists(): void
    {
        $repo = new InMemoryMailTemplateRepository();
        $useCase = new GetTemplateOverrides($repo);

        $out = $useCase->execute(
            new Input(
                tenantId: $this->tenantId(),
                kind:     MailKind::MeetingInvitation,
                locale:   SupportedLocale::fromString('fi_FI'),
            ),
            $this->acting(),
        );

        self::assertSame([], $out->stringOverrides);
        self::assertNull($out->updatedAt);
        self::assertNull($out->updatedByUserId);
    }

    public function test_returns_existing_overrides(): void
    {
        $repo = new InMemoryMailTemplateRepository();
        $userId = UserId::generate();
        $updatedAt = new \DateTimeImmutable('2026-05-10T12:00:00Z');

        $repo->saveOverrides(new MailTemplate(
            id:              MailTemplateId::generate(),
            tenantId:        $this->tenantId(),
            kind:            MailKind::MeetingInvitation,
            locale:          SupportedLocale::fromString('fi_FI'),
            stringOverrides: ['subject' => 'Custom subject', 'signature' => 'Yhdistys ry'],
            updatedAt:       $updatedAt,
            updatedBy:       $userId,
        ));

        $useCase = new GetTemplateOverrides($repo);
        $out = $useCase->execute(
            new Input(
                tenantId: $this->tenantId(),
                kind:     MailKind::MeetingInvitation,
                locale:   SupportedLocale::fromString('fi_FI'),
            ),
            $this->acting(),
        );

        self::assertSame(['subject' => 'Custom subject', 'signature' => 'Yhdistys ry'], $out->stringOverrides);
        self::assertEquals($updatedAt, $out->updatedAt);
        self::assertSame($userId->value(), $out->updatedByUserId);
    }
}
