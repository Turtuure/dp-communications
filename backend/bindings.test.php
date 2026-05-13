<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Application\GetUserCommunicationPreferences\GetUserCommunicationPreferences;
use DaemsModule\Communications\Application\SaveCommunicationSettings\SaveCommunicationSettings;
use DaemsModule\Communications\Application\UpdateUserCommunicationPreference\UpdateUserCommunicationPreference;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
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
    // Only the repos used by Wave B use cases need fake implementations;
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

    // ---------------------------------------------------------------------
    // Use cases (same wiring as prod, but constructed against fakes).
    // SendSmtpTestEmail is intentionally NOT wired here — it needs
    // MailerInterface, which lands in Wave C.
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
};
