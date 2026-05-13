<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\DeleteNewsletterDraft\DeleteNewsletterDraft;
use DaemsModule\Communications\Application\DeleteNewsletterDraft\Input;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Tests\Support\InMemoryNewsletterDraftRepository;
use PHPUnit\Framework\TestCase;

final class DeleteNewsletterDraftTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

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

    private function seedDraft(InMemoryNewsletterDraftRepository $repo, NewsletterStatus $status = NewsletterStatus::Draft): NewsletterId
    {
        $id = NewsletterId::generate();
        $repo->save(new NewsletterDraft(
            id:               $id,
            tenantId:         $this->tenantId(),
            internalName:     'X',
            subjectByLocale:  [],
            blocksByLocale:   [],
            audience:         new AudienceFilter([], [], null, []),
            status:           $status,
            sentAt:           $status === NewsletterStatus::Sent ? new \DateTimeImmutable() : null,
            createdAt:        new \DateTimeImmutable(),
            createdBy:        UserId::generate(),
        ));
        return $id;
    }

    public function test_admin_deletes_draft(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $id   = $this->seedDraft($repo);
        $uc   = new DeleteNewsletterDraft($repo);

        $out = $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertTrue($out->deleted);
        self::assertNull($repo->findById($id));
    }

    public function test_sent_newsletter_cannot_be_deleted(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $id   = $this->seedDraft($repo, NewsletterStatus::Sent);
        $uc   = new DeleteNewsletterDraft($repo);

        $this->expectException(\DomainException::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_moderator_cannot_delete(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $id   = $this->seedDraft($repo);
        $uc   = new DeleteNewsletterDraft($repo);

        $this->expectException(ForbiddenException::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Moderator),
        );
    }
}
