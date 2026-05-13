<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use DaemsModule\Communications\Application\SendNewsletter\Input;
use DaemsModule\Communications\Application\SendNewsletter\SendNewsletter;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\ResolvedRecipient;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use DaemsModule\Communications\Infrastructure\Renderer\MailTemplateRegistry;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;
use DaemsModule\Communications\Tests\Support\InMemoryAudienceResolver;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMailSuppressionRepository;
use DaemsModule\Communications\Tests\Support\InMemoryNewsletterDraftRepository;
use DaemsModule\Communications\Tests\Support\InMemoryTenantCommunicationSettingsRepository;
use PHPUnit\Framework\TestCase;

final class SendNewsletterTest extends TestCase
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
            email:              'admin@x',
            isPlatformAdmin:    false,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    /**
     * @return array{0:SendNewsletter, 1:InMemoryNewsletterDraftRepository, 2:InMemoryAudienceResolver, 3:InMemoryTenantCommunicationSettingsRepository, 4:InMemoryMailOutboxRepository, 5:InMemoryMailSuppressionRepository}
     */
    private function build(): array
    {
        $repo            = new InMemoryNewsletterDraftRepository();
        $resolver        = new InMemoryAudienceResolver();
        $settingsRepo    = new InMemoryTenantCommunicationSettingsRepository();
        $outboxRepo      = new InMemoryMailOutboxRepository();
        $suppressionRepo = new InMemoryMailSuppressionRepository();
        $clock           = FrozenClock::at('2026-05-14T09:00:00Z');
        $md              = new MarkdownRenderer();
        $renderer        = new EmailHtmlRenderer(
            new MailTemplateRegistry(),
            new VarSubstituter(),
            $md,
            new Html2Text(),
        );
        $uc = new SendNewsletter(
            repo:            $repo,
            resolver:        $resolver,
            settingsRepo:    $settingsRepo,
            outboxRepo:      $outboxRepo,
            suppressionRepo: $suppressionRepo,
            renderer:        $renderer,
            markdown:        $md,
            clock:           $clock,
        );
        return [$uc, $repo, $resolver, $settingsRepo, $outboxRepo, $suppressionRepo];
    }

    private function configureSmtp(InMemoryTenantCommunicationSettingsRepository $settingsRepo): void
    {
        $settingsRepo->save(new TenantCommunicationSettings(
            tenantId:               $this->tenantId(),
            smtpDsnEncrypted:       'fake-encrypted-dsn',
            mailFromAddress:        'noreply@daems.fi',
            mailDisplayName:        'Daems Society',
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      '#1f3a5f',
            brandFooterAddress:     'Daems ry, 33100 Tampere',
            updatedAt:              new \DateTimeImmutable('2026-05-14T09:00:00Z'),
        ));
    }

    /** @param array<string, list<\DaemsModule\Communications\Domain\Template\Block\NewsletterBlock>>|null $blocksByLocale */
    private function seedDraft(
        InMemoryNewsletterDraftRepository $repo,
        NewsletterStatus $status = NewsletterStatus::Draft,
        ?array $subjectByLocale = null,
        ?array $blocksByLocale = null,
    ): NewsletterId {
        $id = NewsletterId::generate();
        $repo->save(new NewsletterDraft(
            id:               $id,
            tenantId:         $this->tenantId(),
            internalName:     'X',
            subjectByLocale:  $subjectByLocale ?? [
                'fi_FI' => 'Aihe',
                'en_GB' => 'Subject',
                'sw_TZ' => 'Mada',
            ],
            blocksByLocale:   $blocksByLocale ?? [
                'fi_FI' => [new HeadingBlock(1, 'FI')],
                'en_GB' => [new HeadingBlock(1, 'EN')],
                'sw_TZ' => [new HeadingBlock(1, 'SW')],
            ],
            audience:         new AudienceFilter([], [], null, []),
            status:           $status,
            sentAt:           $status === NewsletterStatus::Sent ? new \DateTimeImmutable() : null,
            createdAt:        new \DateTimeImmutable('2026-05-12T00:00:00Z'),
            createdBy:        UserId::generate(),
        ));
        return $id;
    }

    /** @return list<ResolvedRecipient> */
    private function seedRecipients(InMemoryAudienceResolver $resolver, int $count = 2): array
    {
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $list[] = new ResolvedRecipient(
                userId:      UserId::generate(),
                email:       "r{$i}@example.com",
                locale:      SupportedLocale::fromString('fi_FI'),
                firstName:   "R{$i}",
                contextVars: [],
            );
        }
        $resolver->seed($this->tenantId(), $list);
        return $list;
    }

    public function test_admin_sends_newsletter_enqueues_outbox_rows_and_marks_sent(): void
    {
        [$uc, $repo, $resolver, $settingsRepo, $outboxRepo] = $this->build();
        $this->configureSmtp($settingsRepo);
        $id = $this->seedDraft($repo);
        $this->seedRecipients($resolver, 3);

        $out = $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(3, $out->enqueuedCount);
        self::assertCount(3, $outboxRepo->byId);
        foreach ($outboxRepo->byId as $row) {
            self::assertSame(MailKind::Newsletter, $row->kind);
            self::assertSame(CommunicationCategory::Marketing, $row->category);
            self::assertSame(MailOutboxStatus::Queued, $row->status);
            self::assertSame('Aihe', $row->subject);
            self::assertTrue($row->payloadNewsletterId?->equals($id) ?? false);
        }
        // Marketing-opt-in category forwarded to resolver.
        self::assertNotEmpty($resolver->calls);
        self::assertSame(CommunicationCategory::Marketing, $resolver->calls[0]['category']);
        // Draft flipped to Sent.
        $saved = $repo->findById($id);
        self::assertNotNull($saved);
        self::assertSame(NewsletterStatus::Sent, $saved->status);
        self::assertNotNull($saved->sentAt);
    }

    public function test_moderator_cannot_send(): void
    {
        [$uc, $repo, $resolver, $settingsRepo] = $this->build();
        $this->configureSmtp($settingsRepo);
        $id = $this->seedDraft($repo);
        $this->seedRecipients($resolver, 1);

        $this->expectException(ForbiddenException::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Moderator),
        );
    }

    public function test_send_throws_when_smtp_not_configured(): void
    {
        [$uc, $repo, $resolver] = $this->build();
        // NOTE: SMTP not configured.
        $id = $this->seedDraft($repo);
        $this->seedRecipients($resolver, 1);

        $this->expectException(SmtpNotConfigured::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_send_rejects_already_sent_newsletter(): void
    {
        [$uc, $repo, $resolver, $settingsRepo] = $this->build();
        $this->configureSmtp($settingsRepo);
        $id = $this->seedDraft($repo, NewsletterStatus::Sent);
        $this->seedRecipients($resolver, 1);

        $this->expectException(\DomainException::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_send_throws_when_audience_empty(): void
    {
        [$uc, $repo, $_r, $settingsRepo] = $this->build();
        $this->configureSmtp($settingsRepo);
        $id = $this->seedDraft($repo);
        // No recipients seeded.

        $this->expectException(\DomainException::class);
        $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_locale_parity_failure_when_fi_missing_and_no_fallback(): void
    {
        [$uc, $repo, $_r, $settingsRepo] = $this->build();
        $this->configureSmtp($settingsRepo);
        // fi_FI has empty blocks AND en_GB has empty blocks → fallback unavailable → reject.
        $id = $this->seedDraft($repo, subjectByLocale: ['fi_FI' => 'A', 'sw_TZ' => 'B'], blocksByLocale: [
            'fi_FI' => [],
            'en_GB' => [],
            'sw_TZ' => [new HeadingBlock(1, 'SW')],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/fi_FI/');
        $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_fallback_to_en_gb_allows_other_locales_to_be_empty(): void
    {
        [$uc, $repo, $resolver, $settingsRepo, $outboxRepo] = $this->build();
        $this->configureSmtp($settingsRepo);
        // Only en_GB has content; fi_FI + sw_TZ are empty.
        $id = $this->seedDraft(
            $repo,
            subjectByLocale: ['en_GB' => 'Subject'],
            blocksByLocale:  ['en_GB' => [new HeadingBlock(1, 'EN'), new ParagraphBlock('Body')]],
        );
        $this->seedRecipients($resolver, 2);

        $out = $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(2, $out->enqueuedCount);
        // Recipients are fi_FI but block content falls back to en_GB.
        foreach ($outboxRepo->byId as $row) {
            self::assertSame('Subject', $row->subject);
            self::assertStringContainsString('EN', $row->bodyHtml);
        }
    }

    public function test_suppressed_recipients_are_skipped(): void
    {
        [$uc, $repo, $resolver, $settingsRepo, $outboxRepo, $suppressionRepo] = $this->build();
        $this->configureSmtp($settingsRepo);
        $id = $this->seedDraft($repo);
        $recipients = $this->seedRecipients($resolver, 3);
        $suppressionRepo->add(new MailSuppression(
            tenantId:         $this->tenantId(),
            emailAddress:     $recipients[1]->email,
            reason:           SuppressionReason::HardBounce,
            suppressedAt:     new \DateTimeImmutable(),
            smtpResponseCode: null,
            suppressedBy:     null,
        ));

        $out = $uc->execute(
            new Input(tenantId: $this->tenantId(), newsletterId: $id),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(2, $out->enqueuedCount);
        self::assertCount(2, $outboxRepo->byId);
    }
}
