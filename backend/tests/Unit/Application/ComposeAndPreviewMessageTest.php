<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Application\ComposeAndPreviewMessage\ComposeAndPreviewMessage;
use DaemsModule\Communications\Application\ComposeAndPreviewMessage\Input;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\ResolvedRecipient;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use DaemsModule\Communications\Infrastructure\Renderer\MailTemplateRegistry;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;
use DaemsModule\Communications\Tests\Support\InMemoryAudienceResolver;
use DaemsModule\Communications\Tests\Support\InMemoryMailTemplateRepository;
use DaemsModule\Communications\Tests\Support\InMemoryTenantCommunicationSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ComposeAndPreviewMessageTest extends TestCase
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

    private function filter(): AudienceFilter
    {
        return new AudienceFilter(
            membershipTypes:     [],
            locales:             [],
            joinedWithin:        null,
            applicationStatuses: [],
        );
    }

    private function buildUseCase(InMemoryAudienceResolver $resolver): ComposeAndPreviewMessage
    {
        return new ComposeAndPreviewMessage(
            resolver:     $resolver,
            templateRepo: new InMemoryMailTemplateRepository(),
            settingsRepo: new InMemoryTenantCommunicationSettingsRepository(),
            renderer:     new EmailHtmlRenderer(
                new MailTemplateRegistry(),
                new VarSubstituter(),
                new MarkdownRenderer(),
                new Html2Text(),
            ),
        );
    }

    public function test_admin_can_preview_group_message(): void
    {
        $resolver = new InMemoryAudienceResolver();
        $resolver->seed($this->tenantId(), [
            new ResolvedRecipient(
                userId:      UserId::generate(),
                email:       'sara@example.com',
                locale:      SupportedLocale::fromString('fi_FI'),
                firstName:   'Sara',
                contextVars: [],
            ),
            new ResolvedRecipient(
                userId:      UserId::generate(),
                email:       'mikko@example.com',
                locale:      SupportedLocale::fromString('fi_FI'),
                firstName:   'Mikko',
                contextVars: [],
            ),
        ]);

        $useCase = $this->buildUseCase($resolver);

        $output = $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         [
                    'subject'   => 'Tervetuloa',
                    'body_html' => '<p>Hi everyone</p>',
                    'signature' => 'Hallitus',
                ],
                audienceFilter:  $this->filter(),
                locale:          SupportedLocale::fromString('fi_FI'),
            ),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(2, $output->audienceCount);
        self::assertSame(['Sara', 'Mikko'], $output->audienceSampleNames);
        self::assertSame('fi_FI', $output->previewLocale);
        self::assertStringContainsString('<html', strtolower($output->htmlPreview));
        self::assertStringContainsString('Tervetuloa', $output->htmlPreview);
        // first_name is substituted from the first resolved recipient.
        self::assertStringContainsString('Sara', $output->htmlPreview);
        self::assertNotSame('', $output->textPreview);
    }

    public function test_member_cannot_preview(): void
    {
        $resolver = new InMemoryAudienceResolver();
        $useCase  = $this->buildUseCase($resolver);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         ['subject' => 'x'],
                audienceFilter:  $this->filter(),
                locale:          SupportedLocale::fromString('fi_FI'),
            ),
            $this->acting(UserTenantRole::Member),
        );
    }

    public function test_empty_audience_returns_placeholder_first_name_and_count_zero(): void
    {
        $resolver = new InMemoryAudienceResolver(); // no seed → []
        $useCase  = $this->buildUseCase($resolver);

        $output = $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         ['subject' => 'Preview', 'body_html' => '<p>x</p>'],
                audienceFilter:  $this->filter(),
                locale:          SupportedLocale::fromString('en_GB'),
            ),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(0, $output->audienceCount);
        self::assertSame([], $output->audienceSampleNames);
        self::assertSame('en_GB', $output->previewLocale);
        // Placeholder name is used so the preview renders meaningfully.
        self::assertStringContainsString('Etunimi', $output->htmlPreview);
    }

    public function test_sample_names_truncate_at_three_with_plus_suffix(): void
    {
        $resolver = new InMemoryAudienceResolver();
        $recipients = [];
        foreach (['Sara', 'Mikko', 'Aino', 'Liisa', 'Antti'] as $i => $name) {
            $recipients[] = new ResolvedRecipient(
                userId:      UserId::generate(),
                email:       strtolower($name) . '@example.com',
                locale:      SupportedLocale::fromString('fi_FI'),
                firstName:   $name,
                contextVars: [],
            );
        }
        $resolver->seed($this->tenantId(), $recipients);

        $useCase = $this->buildUseCase($resolver);
        $output  = $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         ['subject' => 'x', 'body_html' => '<p>x</p>'],
                audienceFilter:  $this->filter(),
                locale:          SupportedLocale::fromString('fi_FI'),
            ),
            $this->acting(UserTenantRole::Moderator),
        );

        self::assertSame(5, $output->audienceCount);
        self::assertSame(['Sara', 'Mikko', 'Aino', '+2 muuta'], $output->audienceSampleNames);
    }
}
