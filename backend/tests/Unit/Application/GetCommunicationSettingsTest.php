<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Application\GetCommunicationSettings\Input;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Tests\Support\InMemoryTenantCommunicationSettingsRepository;
use PHPUnit\Framework\TestCase;

final class GetCommunicationSettingsTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(?UserTenantRole $role, bool $isPlatformAdmin = false): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'admin@x',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    private function seedConfiguredSettings(InMemoryTenantCommunicationSettingsRepository $repo): void
    {
        $repo->byTenant[self::TENANT] = new TenantCommunicationSettings(
            tenantId:               $this->tenantId(),
            smtpDsnEncrypted:       'real-cipher-blob',
            mailFromAddress:        'from@tenant.test',
            mailDisplayName:        'Tenant',
            mailReplyTo:            null,
            smtpTestSucceededAt:    new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      null,
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    public function test_non_admin_throws_forbidden(): void
    {
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $useCase = new GetCommunicationSettings($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(new Input($this->tenantId()), $this->acting(UserTenantRole::Member));
    }

    public function test_gsa_sees_raw_encrypted_dsn(): void
    {
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $this->seedConfiguredSettings($repo);

        $useCase = new GetCommunicationSettings($repo);
        $out = $useCase->execute(
            new Input($this->tenantId()),
            $this->acting(role: null, isPlatformAdmin: true),
        );

        self::assertFalse($out->dsnMasked);
        self::assertTrue($out->dsnIsConfigured);
        self::assertSame('real-cipher-blob', $out->settings->smtpDsnEncrypted);
    }

    public function test_tenant_admin_sees_masked_dsn_when_configured(): void
    {
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $this->seedConfiguredSettings($repo);

        $useCase = new GetCommunicationSettings($repo);
        $out = $useCase->execute(
            new Input($this->tenantId()),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertTrue($out->dsnMasked);
        self::assertTrue($out->dsnIsConfigured);
        self::assertSame(GetCommunicationSettings::MASKED_DSN, $out->settings->smtpDsnEncrypted);
        // Non-secret fields must come through unchanged.
        self::assertSame('from@tenant.test', $out->settings->mailFromAddress);
    }

    public function test_tenant_admin_dsn_not_masked_when_unconfigured(): void
    {
        // Default settings have smtpDsnEncrypted=null. There is nothing to mask.
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $useCase = new GetCommunicationSettings($repo);

        $out = $useCase->execute(new Input($this->tenantId()), $this->acting(UserTenantRole::Admin));

        self::assertFalse($out->dsnMasked);
        self::assertFalse($out->dsnIsConfigured);
        self::assertNull($out->settings->smtpDsnEncrypted);
    }
}
