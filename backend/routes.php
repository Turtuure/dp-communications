<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\ComposerController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\NewslettersController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\OutboxController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\SettingsController;
use DaemsModule\Communications\Infrastructure\Adapter\Api\Controller\TemplatesController;

// HTTP route registration for the communications module.
// Loaded by the platform's ModuleRegistry::registerRoutes() at boot.
// C6 registers outbox list/show/retry. Wave D Task D6 (composer API),
// Wave E Task E4 (newsletter API), Wave G Task G2 (suppression API)
// will add their own routes below.

return static function (Router $router, Container $container): void {
    // Backstage — outbox (admin / moderator)
    $router->get('/api/v1/backstage/communications/outbox', static function (Request $req) use ($container): Response {
        return $container->make(OutboxController::class)->index($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/communications/outbox/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(OutboxController::class)->show($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/communications/outbox/{id}/retry', static function (Request $req, array $params) use ($container): Response {
        return $container->make(OutboxController::class)->retry($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — communication settings (admin / GSA)
    $router->get('/api/v1/backstage/communications/settings', static function (Request $req) use ($container): Response {
        return $container->make(SettingsController::class)->show($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->put('/api/v1/backstage/communications/settings', static function (Request $req) use ($container): Response {
        return $container->make(SettingsController::class)->update($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/communications/settings/smtp-test', static function (Request $req) use ($container): Response {
        return $container->make(SettingsController::class)->testSmtp($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — composer (Wave D Task D6, preview + send + meeting creation)
    $router->post('/api/v1/backstage/communications/preview', static function (Request $req) use ($container): Response {
        return $container->make(ComposerController::class)->preview($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/communications/send', static function (Request $req) use ($container): Response {
        return $container->make(ComposerController::class)->send($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/meetings/from-composer', static function (Request $req) use ($container): Response {
        return $container->make(ComposerController::class)->createMeeting($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — template overrides (Wave D Task D8, per-tenant subject/intro/signature/footer)
    $router->get('/api/v1/backstage/communications/templates/{kind}/{locale}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(TemplatesController::class)->show($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->put('/api/v1/backstage/communications/templates/{kind}/{locale}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(TemplatesController::class)->update($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — newsletters CRUD + send (Wave E Task E4).
    $router->get('/api/v1/backstage/communications/newsletters', static function (Request $req) use ($container): Response {
        return $container->make(NewslettersController::class)->index($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/communications/newsletters', static function (Request $req) use ($container): Response {
        return $container->make(NewslettersController::class)->create($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->patch('/api/v1/backstage/communications/newsletters/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(NewslettersController::class)->update($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->delete('/api/v1/backstage/communications/newsletters/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(NewslettersController::class)->destroy($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/communications/newsletters/{id}/send', static function (Request $req, array $params) use ($container): Response {
        return $container->make(NewslettersController::class)->send($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — suppression list (Wave G Task G2) — admin only.
    // Public unsubscribe + auto-add-from-bounce flows are not routed here.
    $router->get('/api/v1/backstage/communications/suppressions', static function (Request $req) use ($container): Response {
        return $container->make(SettingsController::class)->listSuppressions($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/communications/suppressions', static function (Request $req) use ($container): Response {
        return $container->make(SettingsController::class)->addSuppression($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->delete('/api/v1/backstage/communications/suppressions/{email}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(SettingsController::class)->removeSuppression($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
};
