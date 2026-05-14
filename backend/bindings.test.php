<?php

declare(strict_types=1);

use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Container\Container;
use DaemsModule\Communications\Application\ComposeAndPreviewMessage\ComposeAndPreviewMessage;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\CreateMeetingFromComposer;
use DaemsModule\Communications\Application\CreateNewsletterDraft\CreateNewsletterDraft;
use DaemsModule\Communications\Application\DeleteNewsletterDraft\DeleteNewsletterDraft;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Application\GetMeetingForReInvite\GetMeetingForReInvite;
use DaemsModule\Communications\Application\GetTemplateOverrides\GetTemplateOverrides;
use DaemsModule\Communications\Application\GetUserCommunicationPreferences\GetUserCommunicationPreferences;
use DaemsModule\Communications\Application\ListNewsletters\ListNewsletters;
use DaemsModule\Communications\Application\ListOutboxRows\ListOutboxRows;
use DaemsModule\Communications\Application\RetryOutboxRow\RetryOutboxRow;
use DaemsModule\Communications\Application\SaveCommunicationSettings\SaveCommunicationSettings;
use DaemsModule\Communications\Application\SaveTemplateOverrides\SaveTemplateOverrides;
use DaemsModule\Communications\Application\SendComposedMessage\SendComposedMessage;
use DaemsModule\Communications\Application\SendNewsletter\SendNewsletter;
use DaemsModule\Communications\Application\SendSmtpTestEmail\SendSmtpTestEmail;
use DaemsModule\Communications\Application\UpdateNewsletterDraft\UpdateNewsletterDraft;
use DaemsModule\Communications\Application\UpdateUserCommunicationPreference\UpdateUserCommunicationPreference;
use DaemsModule\Communications\Domain\Audience\AudienceResolverInterface;
use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;
use DaemsModule\Communications\Domain\Meeting\MeetingRepositoryInterface;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\ComposerController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\NewslettersController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\OutboxController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\SettingsController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\TemplatesController;
use DaemsModule\Communications\Infrastructure\Auth\UnsubscribeTokenSigner;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Infrastructure\Mailer\InMemoryMailer;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use DaemsModule\Communications\Infrastructure\Renderer\MailTemplateRegistry;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;
use DaemsModule\Communications\Tests\Support\InMemoryAudienceResolver;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMailSuppressionRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMailTemplateRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMeetingRepository;
use DaemsModule\Communications\Tests\Support\InMemoryNewsletterDraftRepository;
use DaemsModule\Communications\Tests\Support\InMemoryTenantCommunicationSettingsRepository;
use DaemsModule\Communications\Tests\Support\InMemoryUserCommunicationPreferenceRepository;

return static function (Container $container): void {
    // ---------------------------------------------------------------------
    // Encryption — same DsnEncryptor as prod, but with a generated test key.
    // Avoids depending on APP_ENCRYPTION_KEY env var in tests.
    // ---------------------------------------------------------------------
    $container->singleton(
        DsnEncryptor::class,
        static fn(): DsnEncryptor => new DsnEncryptor(
            base64_encode(sodium_crypto_secretbox_keygen()),
        ),
    );

    // UnsubscribeTokenSigner — generate a fresh deterministic key per test
    // container so the signer can be exercised without depending on
    // APP_ENCRYPTION_KEY env var.
    $container->singleton(
        UnsubscribeTokenSigner::class,
        static fn(): UnsubscribeTokenSigner => new UnsubscribeTokenSigner(
            base64_encode(sodium_crypto_secretbox_keygen()),
        ),
    );

    // ---------------------------------------------------------------------
    // Repositories — InMemory fakes (override prod SQL singletons).
    // Mail/Meeting/Newsletter/Template/Audience/Suppression land in C-G.
    // ---------------------------------------------------------------------
    $container->singleton(
        TenantCommunicationSettingsRepositoryInterface::class,
        static fn(): TenantCommunicationSettingsRepositoryInterface => new InMemoryTenantCommunicationSettingsRepository(),
    );
    $container->singleton(
        UserCommunicationPreferenceRepositoryInterface::class,
        static fn(): UserCommunicationPreferenceRepositoryInterface => new InMemoryUserCommunicationPreferenceRepository(),
    );
    $container->singleton(
        MailOutboxRepositoryInterface::class,
        static fn(): MailOutboxRepositoryInterface => new InMemoryMailOutboxRepository(),
    );
    $container->singleton(
        MailSuppressionRepositoryInterface::class,
        static fn(): MailSuppressionRepositoryInterface => new InMemoryMailSuppressionRepository(),
    );
    $container->singleton(
        MailTemplateRepositoryInterface::class,
        static fn(): MailTemplateRepositoryInterface => new InMemoryMailTemplateRepository(),
    );
    $container->singleton(
        MeetingRepositoryInterface::class,
        static fn(): MeetingRepositoryInterface => new InMemoryMeetingRepository(),
    );
    $container->singleton(
        NewsletterDraftRepositoryInterface::class,
        static fn(): NewsletterDraftRepositoryInterface => new InMemoryNewsletterDraftRepository(),
    );
    $container->singleton(
        AudienceResolverInterface::class,
        static fn(): AudienceResolverInterface => new InMemoryAudienceResolver(),
    );

    // ---------------------------------------------------------------------
    // Render foundation — Wave D (D1+D2+D3). Same wiring as prod.
    // ---------------------------------------------------------------------
    $container->singleton(MarkdownRenderer::class, static fn(): MarkdownRenderer => new MarkdownRenderer());
    $container->singleton(VarSubstituter::class, static fn(): VarSubstituter => new VarSubstituter());
    $container->singleton(Html2Text::class, static fn(): Html2Text => new Html2Text());
    $container->singleton(MailTemplateRegistry::class, static fn(): MailTemplateRegistry => new MailTemplateRegistry());
    $container->singleton(
        EmailHtmlRenderer::class,
        static fn(Container $c) => new EmailHtmlRenderer(
            $c->make(MailTemplateRegistry::class),
            $c->make(VarSubstituter::class),
            $c->make(MarkdownRenderer::class),
            $c->make(Html2Text::class),
        ),
    );

    // ---------------------------------------------------------------------
    // Mail transport — capture-only InMemoryMailer for tests (C2).
    // Tests that need to assert on a transport failure can flip
    // `$mailer->simulateFailure = new MailerTransportException(...)` before
    // exercising the use case.
    // ---------------------------------------------------------------------
    $container->singleton(
        MailerInterface::class,
        static fn(): MailerInterface => new InMemoryMailer(),
    );

    // ---------------------------------------------------------------------
    // Use cases (same wiring as prod, but constructed against fakes).
    // ---------------------------------------------------------------------
    $container->bind(
        GetCommunicationSettings::class,
        static fn(Container $c) => new GetCommunicationSettings(
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
        ),
    );
    $container->bind(
        SaveCommunicationSettings::class,
        static fn(Container $c) => new SaveCommunicationSettings(
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
            $c->make(DsnEncryptor::class),
        ),
    );
    $container->bind(
        GetUserCommunicationPreferences::class,
        static fn(Container $c) => new GetUserCommunicationPreferences(
            $c->make(UserCommunicationPreferenceRepositoryInterface::class),
        ),
    );
    $container->bind(
        UpdateUserCommunicationPreference::class,
        static fn(Container $c) => new UpdateUserCommunicationPreference(
            $c->make(UserCommunicationPreferenceRepositoryInterface::class),
        ),
    );
    $container->bind(
        SendSmtpTestEmail::class,
        static fn(Container $c) => new SendSmtpTestEmail(
            $c->make(MailerInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
        ),
    );

    // ---------------------------------------------------------------------
    // Outbox use cases + controller — Wave C C5/C6 (admin list/retry API).
    // ---------------------------------------------------------------------
    $container->bind(
        ListOutboxRows::class,
        static fn(Container $c) => new ListOutboxRows(
            $c->make(MailOutboxRepositoryInterface::class),
        ),
    );
    $container->bind(
        RetryOutboxRow::class,
        static fn(Container $c) => new RetryOutboxRow(
            $c->make(MailOutboxRepositoryInterface::class),
        ),
    );
    $container->bind(
        OutboxController::class,
        static fn(Container $c) => new OutboxController(
            $c->make(ListOutboxRows::class),
            $c->make(RetryOutboxRow::class),
            $c->make(MailOutboxRepositoryInterface::class),
        ),
    );

    // Backstage settings (Wave C8 + G2) — same wiring as prod against the fake repo / mailer.
    $container->bind(
        \DaemsModule\Communications\Application\ListSuppressions\ListSuppressions::class,
        static fn(Container $c) => new \DaemsModule\Communications\Application\ListSuppressions\ListSuppressions(
            $c->make(\DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface::class),
        ),
    );
    $container->bind(
        \DaemsModule\Communications\Application\AddManualSuppression\AddManualSuppression::class,
        static fn(Container $c) => new \DaemsModule\Communications\Application\AddManualSuppression\AddManualSuppression(
            $c->make(\DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface::class),
            $c->make(\Daems\Domain\Shared\Clock::class),
        ),
    );
    $container->bind(
        \DaemsModule\Communications\Application\RemoveSuppression\RemoveSuppression::class,
        static fn(Container $c) => new \DaemsModule\Communications\Application\RemoveSuppression\RemoveSuppression(
            $c->make(\DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface::class),
        ),
    );
    $container->bind(
        SettingsController::class,
        static fn(Container $c) => new SettingsController(
            $c->make(GetCommunicationSettings::class),
            $c->make(SaveCommunicationSettings::class),
            $c->make(SendSmtpTestEmail::class),
            $c->make(\DaemsModule\Communications\Application\ListSuppressions\ListSuppressions::class),
            $c->make(\DaemsModule\Communications\Application\AddManualSuppression\AddManualSuppression::class),
            $c->make(\DaemsModule\Communications\Application\RemoveSuppression\RemoveSuppression::class),
        ),
    );

    // Template overrides (Wave D Task D8) — same wiring as prod against the InMemory repo.
    $container->bind(
        GetTemplateOverrides::class,
        static fn(Container $c) => new GetTemplateOverrides(
            $c->make(\DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface::class),
        ),
    );
    $container->bind(
        SaveTemplateOverrides::class,
        static fn(Container $c) => new SaveTemplateOverrides(
            $c->make(\DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface::class),
        ),
    );
    $container->bind(
        TemplatesController::class,
        static fn(Container $c) => new TemplatesController(
            $c->make(GetTemplateOverrides::class),
            $c->make(SaveTemplateOverrides::class),
        ),
    );

    // ---------------------------------------------------------------------
    // Newsletter CRUD (Wave E Tasks E2 + E3) — same wiring as prod against
    // the InMemory newsletter-draft repo. NewsletterBlockRenderer is
    // instantiated per-call inside SendNewsletter (brand-color is per-tenant),
    // so we do NOT register it as a singleton here.
    // ---------------------------------------------------------------------
    $container->bind(
        CreateNewsletterDraft::class,
        static fn(Container $c) => new CreateNewsletterDraft(
            $c->make(NewsletterDraftRepositoryInterface::class),
            $c->make(Clock::class),
        ),
    );
    $container->bind(
        UpdateNewsletterDraft::class,
        static fn(Container $c) => new UpdateNewsletterDraft(
            $c->make(NewsletterDraftRepositoryInterface::class),
        ),
    );
    $container->bind(
        DeleteNewsletterDraft::class,
        static fn(Container $c) => new DeleteNewsletterDraft(
            $c->make(NewsletterDraftRepositoryInterface::class),
        ),
    );
    $container->bind(
        ListNewsletters::class,
        static fn(Container $c) => new ListNewsletters(
            $c->make(NewsletterDraftRepositoryInterface::class),
        ),
    );
    $container->bind(
        SendNewsletter::class,
        static fn(Container $c) => new SendNewsletter(
            $c->make(NewsletterDraftRepositoryInterface::class),
            $c->make(AudienceResolverInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
            $c->make(MailOutboxRepositoryInterface::class),
            $c->make(MailSuppressionRepositoryInterface::class),
            $c->make(EmailHtmlRenderer::class),
            $c->make(MarkdownRenderer::class),
            $c->make(Clock::class),
        ),
    );

    // Newsletters HTTP controller — Wave E Task E4.
    $container->bind(
        NewslettersController::class,
        static fn(Container $c) => new NewslettersController(
            $c->make(ListNewsletters::class),
            $c->make(CreateNewsletterDraft::class),
            $c->make(UpdateNewsletterDraft::class),
            $c->make(DeleteNewsletterDraft::class),
            $c->make(SendNewsletter::class),
        ),
    );
};
