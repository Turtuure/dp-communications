<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Application\GetUserCommunicationPreferences\GetUserCommunicationPreferences;
use DaemsModule\Communications\Application\ListOutboxRows\ListOutboxRows;
use DaemsModule\Communications\Application\RetryOutboxRow\RetryOutboxRow;
use DaemsModule\Communications\Application\SaveCommunicationSettings\SaveCommunicationSettings;
use DaemsModule\Communications\Application\SendSmtpTestEmail\SendSmtpTestEmail;
use DaemsModule\Communications\Application\UpdateUserCommunicationPreference\UpdateUserCommunicationPreference;
use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\OutboxController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\SettingsController;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Infrastructure\Mailer\InMemoryMailer;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
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

    // Backstage settings (Wave C8) — same wiring as prod against the fake repo / mailer.
    $container->bind(
        SettingsController::class,
        static fn(Container $c) => new SettingsController(
            $c->make(GetCommunicationSettings::class),
            $c->make(SaveCommunicationSettings::class),
            $c->make(SendSmtpTestEmail::class),
        ),
    );
};
