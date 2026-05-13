<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;

// HTTP route registration for the communications module.
// Loaded by the platform's ModuleRegistry::registerRoutes() at boot.
// No routes yet — Wave C Task C6 (outbox API), Wave D Task D6 (composer
// API), Wave E Task E4 (newsletter API), Wave G Task G2 (suppression API)
// will register their routes. This stub keeps platform boot working.

return static function (Router $router, Container $container): void {
    // Intentionally empty — Wave C/D/E/G tasks fill this in.
};
