<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

// All DI bindings for the communications module.
// Loaded by the platform's ModuleRegistry::registerBindings() at boot.
// No bindings yet — Wave B Task B10 wires the real bindings (repositories,
// use cases, MailerInterface adapter, DsnEncryptor) once the domain classes
// exist. This stub keeps platform boot working.

return static function (Container $container): void {
    // Intentionally empty — Wave B Task B10 fills this in.
};
