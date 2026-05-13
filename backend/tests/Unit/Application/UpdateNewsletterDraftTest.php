<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\UpdateNewsletterDraft\Input;
use DaemsModule\Communications\Application\UpdateNewsletterDraft\UpdateNewsletterDraft;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Tests\Support\InMemoryNewsletterDraftRepository;
use PHPUnit\Framework\TestCase;

final class UpdateNewsletterDraftTest extends TestCase
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

    private function seedDraft(InMemoryNewsletterDraftRepository $repo, NewsletterStatus $status = NewsletterStatus::Draft): NewsletterId
    {
        $id = NewsletterId::generate();
        $repo->save(new NewsletterDraft(
            id:               $id,
            tenantId:         $this->tenantId(),
            internalName:     'Initial',
            subjectByLocale:  [],
            blocksByLocale:   [],
            audience:         new AudienceFilter([], [], null, []),
            status:           $status,
            sentAt:           $status === NewsletterStatus::Sent ? new \DateTimeImmutable('2026-05-13T00:00:00Z') : null,
            createdAt:        new \DateTimeImmutable('2026-05-12T00:00:00Z'),
            createdBy:        UserId::generate(),
        ));
        return $id;
    }

    public function test_admin_updates_subjects_blocks_and_audience(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $id   = $this->seedDraft($repo);
        $uc   = new UpdateNewsletterDraft($repo);

        $blocks = [
            'fi_FI' => [new HeadingBlock(1, 'Tervetuloa'), new ParagraphBlock('Sisalto')],
            'en_GB' => [new HeadingBlock(1, 'Welcome'), new ParagraphBlock('Body')],
        ];
        $audience = new AudienceFilter(['regular'], ['fi_FI', 'en_GB'], null, []);

        $out = $uc->execute(
            new Input(
                tenantId:        $this->tenantId(),
                newsletterId:    $id,
                internalName:    'Renamed',
                subjectByLocale: ['fi_FI' => 'Aihe', 'en_GB' => 'Subject'],
                blocksByLocale:  $blocks,
                audience:        $audience,
            ),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertTrue($out->newsletterId->equals($id));
        $saved = $repo->findById($id);
        self::assertNotNull($saved);
        self::assertSame('Renamed', $saved->internalName);
        self::assertSame(['fi_FI' => 'Aihe', 'en_GB' => 'Subject'], $saved->subjectByLocale);
        self::assertCount(2, $saved->blocksByLocale['fi_FI']);
        self::assertSame(['regular'], $saved->audience->membershipTypes);
    }

    public function test_sent_newsletter_cannot_be_updated(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $id   = $this->seedDraft($repo, NewsletterStatus::Sent);
        $uc   = new UpdateNewsletterDraft($repo);

        $this->expectException(\DomainException::class);
        $uc->execute(
            new Input(
                tenantId:        $this->tenantId(),
                newsletterId:    $id,
                internalName:    null,
                subjectByLocale: [],
                blocksByLocale:  [],
                audience:        new AudienceFilter([], [], null, []),
            ),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_cross_tenant_update_rejected(): void
    {
        $repo = new InMemoryNewsletterDraftRepository();
        $id   = $this->seedDraft($repo);
        $uc   = new UpdateNewsletterDraft($repo);

        $this->expectException(ForbiddenException::class);
        $uc->execute(
            new Input(
                tenantId:        TenantId::fromString(self::OTHER),
                newsletterId:    $id,
                internalName:    null,
                subjectByLocale: [],
                blocksByLocale:  [],
                audience:        new AudienceFilter([], [], null, []),
            ),
            new ActingUser(
                id:                 UserId::generate(),
                email:              'attacker@x',
                isPlatformAdmin:    false,
                activeTenant:       TenantId::fromString(self::OTHER),
                roleInActiveTenant: UserTenantRole::Admin,
            ),
        );
    }
}
