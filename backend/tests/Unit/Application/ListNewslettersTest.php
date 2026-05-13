<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\ListNewsletters\Input;
use DaemsModule\Communications\Application\ListNewsletters\ListNewsletters;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Tests\Support\InMemoryNewsletterDraftRepository;
use PHPUnit\Framework\TestCase;

final class ListNewslettersTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER  = '01958000-0000-7000-8000-0000000000ff';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'a@x',
            isPlatformAdmin:    false,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    private function seedFor(InMemoryNewsletterDraftRepository $repo, TenantId $tenant, string $name): void
    {
        $repo->save(new NewsletterDraft(
            id:               NewsletterId::generate(),
            tenantId:         $tenant,
            internalName:     $name,
            subjectByLocale:  [],
            blocksByLocale:   [],
            audience:         new AudienceFilter([], [], null, []),
            status:           NewsletterStatus::Draft,
            sentAt:           null,
            createdAt:        new \DateTimeImmutable(),
            createdBy:        UserId::generate(),
        ));
    }

    public function test_admin_lists_drafts_for_their_tenant_only(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $this->seedFor($repo, $this->tenantId(), 'A');
        $this->seedFor($repo, $this->tenantId(), 'B');
        $this->seedFor($repo, TenantId::fromString(self::OTHER), 'X');

        $out = (new ListNewsletters($repo))->execute(
            new Input(tenantId: $this->tenantId()),
            $this->acting(UserTenantRole::Admin),
        );
        self::assertCount(2, $out->newsletters);
        $names = array_map(static fn($d) => $d->internalName, $out->newsletters);
        sort($names);
        self::assertSame(['A', 'B'], $names);
    }

    public function test_moderator_can_list(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $this->seedFor($repo, $this->tenantId(), 'A');

        $out = (new ListNewsletters($repo))->execute(
            new Input(tenantId: $this->tenantId()),
            $this->acting(UserTenantRole::Moderator),
        );
        self::assertCount(1, $out->newsletters);
    }

    public function test_member_role_forbidden(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $this->expectException(ForbiddenException::class);
        (new ListNewsletters($repo))->execute(
            new Input(tenantId: $this->tenantId()),
            $this->acting(UserTenantRole::Member),
        );
    }
}
