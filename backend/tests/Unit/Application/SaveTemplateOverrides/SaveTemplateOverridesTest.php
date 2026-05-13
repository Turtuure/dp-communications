<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application\SaveTemplateOverrides;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\SaveTemplateOverrides\Input;
use DaemsModule\Communications\Application\SaveTemplateOverrides\SaveTemplateOverrides;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Tests\Support\InMemoryMailTemplateRepository;
use PHPUnit\Framework\TestCase;

final class SaveTemplateOverridesTest extends TestCase
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
        $useCase = new SaveTemplateOverrides($repo);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                tenantId:  $this->tenantId(),
                kind:      MailKind::MeetingInvitation,
                locale:    SupportedLocale::fromString('fi_FI'),
                overrides: ['subject' => 'X'],
            ),
            $this->acting(UserTenantRole::Member),
        );
    }

    public function test_unknown_key_throws_invalid_argument(): void
    {
        $repo = new InMemoryMailTemplateRepository();
        $useCase = new SaveTemplateOverrides($repo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown template override key.*evil_html/');

        $useCase->execute(
            new Input(
                tenantId:  $this->tenantId(),
                kind:      MailKind::GroupMessage,
                locale:    SupportedLocale::fromString('fi_FI'),
                overrides: ['subject' => 'OK', 'evil_html' => '<script>'],
            ),
            $this->acting(),
        );
    }

    public function test_creates_and_round_trips_overrides(): void
    {
        $repo = new InMemoryMailTemplateRepository();
        $useCase = new SaveTemplateOverrides($repo);

        $out = $useCase->execute(
            new Input(
                tenantId:  $this->tenantId(),
                kind:      MailKind::PaymentReminder,
                locale:    SupportedLocale::fromString('en_GB'),
                overrides: [
                    'subject'    => 'Membership fee due',
                    'intro_text' => 'Hello — friendly reminder.',
                    'signature'  => 'The Board',
                    'footer'     => 'Reg. no. 1234',
                ],
            ),
            $this->acting(),
        );

        self::assertTrue($out->success);

        $stored = $repo->findOverrides(
            $this->tenantId(),
            MailKind::PaymentReminder,
            SupportedLocale::fromString('en_GB'),
        );
        self::assertNotNull($stored);
        self::assertSame('Membership fee due', $stored->stringOverrides['subject']);
        self::assertSame('Hello — friendly reminder.', $stored->stringOverrides['intro_text']);
        self::assertSame('The Board', $stored->stringOverrides['signature']);
        self::assertSame('Reg. no. 1234', $stored->stringOverrides['footer']);
    }

    public function test_overwrites_existing_row(): void
    {
        $repo = new InMemoryMailTemplateRepository();
        $useCase = new SaveTemplateOverrides($repo);

        $tenantId = $this->tenantId();
        $kind     = MailKind::MembershipApproved;
        $locale   = SupportedLocale::fromString('sw_TZ');

        $useCase->execute(
            new Input(
                tenantId:  $tenantId,
                kind:      $kind,
                locale:    $locale,
                overrides: ['subject' => 'First version'],
            ),
            $this->acting(),
        );
        $useCase->execute(
            new Input(
                tenantId:  $tenantId,
                kind:      $kind,
                locale:    $locale,
                overrides: ['subject' => 'Second version', 'signature' => 'Karibu'],
            ),
            $this->acting(),
        );

        $stored = $repo->findOverrides($tenantId, $kind, $locale);
        self::assertNotNull($stored);
        self::assertSame('Second version', $stored->stringOverrides['subject']);
        self::assertSame('Karibu', $stored->stringOverrides['signature']);
    }

    public function test_accepts_empty_string_values(): void
    {
        // Whitelist allows empty strings — admin can deliberately blank a slot.
        $repo = new InMemoryMailTemplateRepository();
        $useCase = new SaveTemplateOverrides($repo);

        $useCase->execute(
            new Input(
                tenantId:  $this->tenantId(),
                kind:      MailKind::GroupMessage,
                locale:    SupportedLocale::fromString('fi_FI'),
                overrides: ['signature' => ''],
            ),
            $this->acting(),
        );

        $stored = $repo->findOverrides(
            $this->tenantId(),
            MailKind::GroupMessage,
            SupportedLocale::fromString('fi_FI'),
        );
        self::assertNotNull($stored);
        self::assertSame('', $stored->stringOverrides['signature']);
    }
}
