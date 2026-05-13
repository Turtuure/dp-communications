<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use DaemsModule\Communications\Application\CreateNewsletterDraft\CreateNewsletterDraft;
use DaemsModule\Communications\Application\CreateNewsletterDraft\Input;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Tests\Support\InMemoryNewsletterDraftRepository;
use PHPUnit\Framework\TestCase;

final class CreateNewsletterDraftTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(?UserTenantRole $role, bool $platform = false): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'a@x',
            isPlatformAdmin:    $platform,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    public function test_admin_creates_empty_draft(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $uc   = new CreateNewsletterDraft($repo, FrozenClock::at('2026-05-14T09:00:00Z'));

        $out = $uc->execute(
            new Input(tenantId: $this->tenantId(), internalName: 'Spring 2026'),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertNotNull($out->newsletterId);
        $saved = $repo->findById($out->newsletterId);
        self::assertNotNull($saved);
        self::assertSame('Spring 2026', $saved->internalName);
        self::assertSame(NewsletterStatus::Draft, $saved->status);
        self::assertSame([], $saved->subjectByLocale);
        self::assertSame([], $saved->blocksByLocale);
    }

    public function test_moderator_cannot_create_draft(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $uc   = new CreateNewsletterDraft($repo, FrozenClock::at('2026-05-14T09:00:00Z'));

        $this->expectException(ForbiddenException::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), internalName: 'x'),
            $this->acting(UserTenantRole::Moderator),
        );
    }

    public function test_empty_internal_name_rejected(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $uc   = new CreateNewsletterDraft($repo, FrozenClock::at('2026-05-14T09:00:00Z'));

        $this->expectException(\InvalidArgumentException::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), internalName: '   '),
            $this->acting(UserTenantRole::Admin),
        );
    }
}
