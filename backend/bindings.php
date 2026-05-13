<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Application\GetUserCommunicationPreferences\GetUserCommunicationPreferences;
use DaemsModule\Communications\Application\SaveCommunicationSettings\SaveCommunicationSettings;
use DaemsModule\Communications\Application\SendSmtpTestEmail\SendSmtpTestEmail;
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
use DaemsModule\Communications\Infrastructure\Audience\SqlAudienceResolver;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailOutboxRepository;
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

    // Note: MailerInterface is wired in Wave C (Task C2 = InMemoryMailer for tests,
    // Task C3 = SymfonyMailerAdapter for prod). SendSmtpTestEmail will fail at
    // construction time until that binding lands — by design, it's controller-
    // gated behind an admin route that doesn't exist yet.
};
