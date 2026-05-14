<?php

declare(strict_types=1);

use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Application\ComposeAndPreviewMessage\ComposeAndPreviewMessage;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\CreateMeetingFromComposer;
use DaemsModule\Communications\Application\CreateNewsletterDraft\CreateNewsletterDraft;
use DaemsModule\Communications\Application\DeleteNewsletterDraft\DeleteNewsletterDraft;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use DaemsModule\Communications\Application\DrainMailOutbox\DrainMailOutbox;
use DaemsModule\Communications\Application\EnqueueLapseWarnings\EnqueueLapseWarnings;
use DaemsModule\Communications\Application\EnqueuePaymentReminders\EnqueuePaymentReminders;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Application\GetMeetingForReInvite\GetMeetingForReInvite;
use DaemsModule\Communications\Application\GetTemplateOverrides\GetTemplateOverrides;
use DaemsModule\Communications\Application\GetUserCommunicationPreferences\GetUserCommunicationPreferences;
use DaemsModule\Communications\Application\AddManualSuppression\AddManualSuppression;
use DaemsModule\Communications\Application\ListNewsletters\ListNewsletters;
use DaemsModule\Communications\Application\ListOutboxRows\ListOutboxRows;
use DaemsModule\Communications\Application\ListSuppressions\ListSuppressions;
use DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending\MarkSuppressedRecipientsInPending;
use DaemsModule\Communications\Application\RemoveSuppression\RemoveSuppression;
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
use DaemsModule\Communications\Infrastructure\Audience\SqlAudienceResolver;
use DaemsModule\Communications\Infrastructure\Auth\UnsubscribeTokenSigner;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Infrastructure\Mailer\SymfonyMailerAdapter;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailOutboxRepository;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use DaemsModule\Communications\Infrastructure\Renderer\MailTemplateRegistry;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailSuppressionRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailTemplateRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMeetingRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlNewsletterDraftRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlTenantCommunicationSettingsRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlUserCommunicationPreferenceRepository;

return static function (Container $container): void {
    // ---------------------------------------------------------------------
    // Encryption — DsnEncryptor uses APP_ENCRYPTION_KEY ($_ENV / getenv).
    // ---------------------------------------------------------------------
    $container->singleton(
        DsnEncryptor::class,
        static function (): DsnEncryptor {
            $key = $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?: '';
            if (!is_string($key) || $key === '') {
                throw new \RuntimeException(
                    'APP_ENCRYPTION_KEY env var is not set; cannot build DsnEncryptor.',
                );
            }
            return new DsnEncryptor($key);
        },
    );

    // UnsubscribeTokenSigner — HMAC over (user, tenant, category, expiry).
    // Shares APP_ENCRYPTION_KEY with DsnEncryptor; the signer derives a
    // per-purpose key via sodium_crypto_generichash so the HMAC key cannot be
    // reused to forge anything else if it leaks.
    $container->singleton(
        UnsubscribeTokenSigner::class,
        static function (): UnsubscribeTokenSigner {
            $key = $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?: '';
            if (!is_string($key) || $key === '') {
                throw new \RuntimeException(
                    'APP_ENCRYPTION_KEY env var is not set; cannot build UnsubscribeTokenSigner.',
                );
            }
            return new UnsubscribeTokenSigner($key);
        },
    );

    // ---------------------------------------------------------------------
    // Repositories — bind each interface to its SQL implementation.
    // ---------------------------------------------------------------------
    $container->singleton(
        TenantCommunicationSettingsRepositoryInterface::class,
        static fn(Container $c) => new SqlTenantCommunicationSettingsRepository($c->make(Connection::class)),
    );
    $container->singleton(
        UserCommunicationPreferenceRepositoryInterface::class,
        static fn(Container $c) => new SqlUserCommunicationPreferenceRepository($c->make(Connection::class)),
    );
    $container->singleton(
        MailOutboxRepositoryInterface::class,
        static fn(Container $c) => new SqlMailOutboxRepository($c->make(Connection::class)),
    );
    $container->singleton(
        MailSuppressionRepositoryInterface::class,
        static fn(Container $c) => new SqlMailSuppressionRepository($c->make(Connection::class)),
    );
    $container->singleton(
        MailTemplateRepositoryInterface::class,
        static fn(Container $c) => new SqlMailTemplateRepository($c->make(Connection::class)),
    );
    $container->singleton(
        NewsletterDraftRepositoryInterface::class,
        static fn(Container $c) => new SqlNewsletterDraftRepository($c->make(Connection::class)),
    );
    $container->singleton(
        MeetingRepositoryInterface::class,
        static fn(Container $c) => new SqlMeetingRepository($c->make(Connection::class)),
    );

    // ---------------------------------------------------------------------
    // Domain services
    // ---------------------------------------------------------------------
    $container->singleton(
        AudienceResolverInterface::class,
        static fn(Container $c) => new SqlAudienceResolver($c->make(Connection::class)),
    );

    // ---------------------------------------------------------------------
    // Mail transport — Symfony Mailer 6.4 over per-tenant SMTP DSN (C3).
    // ---------------------------------------------------------------------
    $container->singleton(
        MailerInterface::class,
        static fn(Container $c) => new SymfonyMailerAdapter($c->make(DsnEncryptor::class)),
    );

    // ---------------------------------------------------------------------
    // Render foundation — Wave D (D1+D2+D3).
    // Pure render logic, no I/O beyond reading template files + lang files.
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
    // Use cases
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
        SendSmtpTestEmail::class,
        static fn(Container $c) => new SendSmtpTestEmail(
            $c->make(MailerInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
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

    // ---------------------------------------------------------------------
    // Outbox + drain — Wave C tasks C4 + C5 (cron + admin retry).
    // ---------------------------------------------------------------------
    $container->bind(
        MarkSuppressedRecipientsInPending::class,
        static fn(Container $c) => new MarkSuppressedRecipientsInPending(
            $c->make(MailOutboxRepositoryInterface::class),
            $c->make(MailSuppressionRepositoryInterface::class),
        ),
    );
    $container->bind(
        DrainMailOutbox::class,
        static fn(Container $c) => new DrainMailOutbox(
            $c->make(MailOutboxRepositoryInterface::class),
            $c->make(MailSuppressionRepositoryInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
            $c->make(MailerInterface::class),
            $c->make(MarkSuppressedRecipientsInPending::class),
        ),
    );
    $container->bind(
        ListOutboxRows::class,
        static fn(Container $c) => new ListOutboxRows(
            $c->make(MailOutboxRepositoryInterface::class),
        ),
    );

    // ---------------------------------------------------------------------
    // Reminder + lapse-warning crons — Wave F (0.8) Tasks F1 + F2.
    // ---------------------------------------------------------------------
    $container->bind(
        EnqueuePaymentReminders::class,
        static fn(Container $c) => new EnqueuePaymentReminders(
            $c->make(TenantRepositoryInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
            $c->make(MemberFeeInvoiceRepositoryInterface::class),
            $c->make(MailOutboxRepositoryInterface::class),
            $c->make(MailSuppressionRepositoryInterface::class),
            $c->make(MailTemplateRepositoryInterface::class),
            $c->make(EmailHtmlRenderer::class),
            $c->make(Connection::class),
        ),
    );
    $container->bind(
        EnqueueLapseWarnings::class,
        static fn(Container $c) => new EnqueueLapseWarnings(
            $c->make(TenantRepositoryInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
            $c->make(TenantGovernanceSettingsRepositoryInterface::class),
            $c->make(MemberFeeInvoiceRepositoryInterface::class),
            $c->make(MailOutboxRepositoryInterface::class),
            $c->make(MailSuppressionRepositoryInterface::class),
            $c->make(MailTemplateRepositoryInterface::class),
            $c->make(EmailHtmlRenderer::class),
            $c->make(Connection::class),
        ),
    );
    $container->bind(
        RetryOutboxRow::class,
        static fn(Container $c) => new RetryOutboxRow(
            $c->make(MailOutboxRepositoryInterface::class),
        ),
    );

    // ---------------------------------------------------------------------
    // HTTP controllers — backstage outbox (C6).
    // ---------------------------------------------------------------------
    $container->bind(
        OutboxController::class,
        static fn(Container $c) => new OutboxController(
            $c->make(ListOutboxRows::class),
            $c->make(RetryOutboxRow::class),
            $c->make(MailOutboxRepositoryInterface::class),
        ),
    );

    // ---------------------------------------------------------------------
    // Suppression use cases (Wave G Task G1).
    // ---------------------------------------------------------------------
    $container->bind(
        ListSuppressions::class,
        static fn(Container $c) => new ListSuppressions(
            $c->make(MailSuppressionRepositoryInterface::class),
        ),
    );
    $container->bind(
        AddManualSuppression::class,
        static fn(Container $c) => new AddManualSuppression(
            $c->make(MailSuppressionRepositoryInterface::class),
            $c->make(Clock::class),
        ),
    );
    $container->bind(
        RemoveSuppression::class,
        static fn(Container $c) => new RemoveSuppression(
            $c->make(MailSuppressionRepositoryInterface::class),
        ),
    );

    // Backstage settings (Wave C8 + G2) — GET / PUT / POST smtp-test
    //   + suppression list/add/remove (G2).
    $container->bind(
        SettingsController::class,
        static fn(Container $c) => new SettingsController(
            $c->make(GetCommunicationSettings::class),
            $c->make(SaveCommunicationSettings::class),
            $c->make(SendSmtpTestEmail::class),
            $c->make(ListSuppressions::class),
            $c->make(AddManualSuppression::class),
            $c->make(RemoveSuppression::class),
        ),
    );

    // ---------------------------------------------------------------------
    // Composer use cases — Wave D Tasks D4/D5/D6.
    // ---------------------------------------------------------------------
    $container->bind(
        ComposeAndPreviewMessage::class,
        static fn(Container $c) => new ComposeAndPreviewMessage(
            $c->make(AudienceResolverInterface::class),
            $c->make(MailTemplateRepositoryInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
            $c->make(EmailHtmlRenderer::class),
        ),
    );
    $container->bind(
        CreateMeetingFromComposer::class,
        static fn(Container $c) => new CreateMeetingFromComposer(
            $c->make(MeetingRepositoryInterface::class),
            $c->make(Clock::class),
        ),
    );
    $container->bind(
        GetMeetingForReInvite::class,
        static fn(Container $c) => new GetMeetingForReInvite(
            $c->make(MeetingRepositoryInterface::class),
        ),
    );
    $container->bind(
        SendComposedMessage::class,
        static fn(Container $c) => new SendComposedMessage(
            $c->make(AudienceResolverInterface::class),
            $c->make(MailTemplateRepositoryInterface::class),
            $c->make(TenantCommunicationSettingsRepositoryInterface::class),
            $c->make(MailOutboxRepositoryInterface::class),
            $c->make(MailSuppressionRepositoryInterface::class),
            $c->make(EmailHtmlRenderer::class),
            $c->make(CreateMeetingFromComposer::class),
            $c->make(Clock::class),
        ),
    );
    $container->bind(
        ComposerController::class,
        static fn(Container $c) => new ComposerController(
            $c->make(ComposeAndPreviewMessage::class),
            $c->make(SendComposedMessage::class),
            $c->make(CreateMeetingFromComposer::class),
        ),
    );

    // ---------------------------------------------------------------------
    // Template overrides — Wave D Task D8.
    // ---------------------------------------------------------------------
    $container->bind(
        GetTemplateOverrides::class,
        static fn(Container $c) => new GetTemplateOverrides(
            $c->make(MailTemplateRepositoryInterface::class),
        ),
    );
    $container->bind(
        SaveTemplateOverrides::class,
        static fn(Container $c) => new SaveTemplateOverrides(
            $c->make(MailTemplateRepositoryInterface::class),
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
    // Newsletter CRUD — Wave E Tasks E2 + E3 (Milestone 0.8).
    // The block renderer is intentionally NOT a singleton — SendNewsletter
    // instantiates it inline with the per-tenant brand-primary-color.
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
            $c->make(UnsubscribeTokenSigner::class),
            rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://daems-platform.local'), '/'),
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
