<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\SaveCommunicationSettings\Input;
use DaemsModule\Communications\Application\SaveCommunicationSettings\SaveCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Tests\Support\InMemoryTenantCommunicationSettingsRepository;
use PHPUnit\Framework\TestCase;

final class SaveCommunicationSettingsTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private string $key;

    protected function setUp(): void
    {
        $this->key = base64_encode(sodium_crypto_secretbox_keygen());
    }

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
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $useCase = new SaveCommunicationSettings($repo, new DsnEncryptor($this->key));

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(tenantId: $this->tenantId(), plainSmtpDsn: 'smtp://x:y@z:587'),
            $this->acting(role: UserTenantRole::Member),
        );
    }

    public function test_saving_new_dsn_encrypts_it(): void
    {
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $encryptor = new DsnEncryptor($this->key);
        $useCase = new SaveCommunicationSettings($repo, $encryptor);

        $plain = 'smtp://alice:secret@smtp.example.com:587?encryption=tls';
        $useCase->execute(
            new Input(tenantId: $this->tenantId(), plainSmtpDsn: $plain),
            $this->acting(),
        );

        $saved = $repo->findForTenant($this->tenantId());
        self::assertNotNull($saved->smtpDsnEncrypted);
        self::assertNotSame($plain, $saved->smtpDsnEncrypted, 'DSN must be encrypted, not stored verbatim');
        self::assertSame($plain, $encryptor->decrypt($saved->smtpDsnEncrypted));
    }

    public function test_saving_new_dsn_resets_test_succeeded_at(): void
    {
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $repo->byTenant[self::TENANT] = new TenantCommunicationSettings(
            tenantId:               $this->tenantId(),
            smtpDsnEncrypted:       'old-cipher',
            mailFromAddress:        'old@x.test',
            mailDisplayName:        null,
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

        $useCase = new SaveCommunicationSettings($repo, new DsnEncryptor($this->key));
        $useCase->execute(
            new Input(tenantId: $this->tenantId(), plainSmtpDsn: 'smtp://new:secret@smtp.example.com:587'),
            $this->acting(),
        );

        $saved = $repo->findForTenant($this->tenantId());
        self::assertNull($saved->smtpTestSucceededAt, 'Test timestamp must reset when DSN changes');
    }

    public function test_saving_without_changing_dsn_preserves_test_succeeded_at(): void
    {
        $existingTimestamp = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $repo = new InMemoryTenantCommunicationSettingsRepository();
        $repo->byTenant[self::TENANT] = new TenantCommunicationSettings(
            tenantId:               $this->tenantId(),
            smtpDsnEncrypted:       'existing-cipher',
            mailFromAddress:        'a@x.test',
            mailDisplayName:        null,
            mailReplyTo:            null,
            smtpTestSucceededAt:    $existingTimestamp,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      null,
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        $useCase = new SaveCommunicationSettings($repo, new DsnEncryptor($this->key));
        // Caller updates only display name; plainSmtpDsn left null.
        $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                plainSmtpDsn:    null,
                mailDisplayName: 'New display name',
            ),
            $this->acting(),
        );

        $saved = $repo->findForTenant($this->tenantId());
        self::assertSame('existing-cipher', $saved->smtpDsnEncrypted, 'DSN ciphertext must be untouched');
        self::assertEquals($existingTimestamp, $saved->smtpTestSucceededAt, 'Test timestamp must be preserved');
        self::assertSame('New display name', $saved->mailDisplayName);
    }
}
