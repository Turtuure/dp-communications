<?php
declare(strict_types=1);

/**
 * 099_seed_tenant_modules_communications.php
 *
 * Seed tenant_modules for `communications` across every existing tenant
 * (mirrors migration 072 for the original 5 modules). Without this row,
 * ModuleRouteGuard 404s /backstage/communications/* for daems + sahegroup.
 *
 * GUARD: tenant_modules is created at core slot 069. Tests that use
 * MigrationTestCase::runMigrationsUpTo(N) for N < 69 still pull in this
 * unmapped post-extraction module migration (per the >=59 cutoff rule), so
 * we MUST check the table exists before INSERTing — otherwise integration
 * tests with bounded historic schema (e.g. SearchIntegrationTest at 61)
 * blow up with "Table doesn't exist".
 *
 * Idempotent on re-run.
 *
 * Test mode: MigrationTestCase passes $pdo via require.
 * Standalone: `php modules/communications/backend/migrations/099_seed_tenant_modules_communications.php`.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../../../../daems-platform/vendor/autoload.php';
    $envFile = __DIR__ . '/../../../../daems-platform/.env';
    $env = [];
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $env[$k] = $v;
        }
    }
    $pdo = new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1')
            . ';port=' . ($env['DB_PORT'] ?? '3306')
            . ';dbname=' . ($env['DB_DATABASE'] ?? 'daems_db')
            . ';charset=utf8mb4',
        $env['DB_USERNAME'] ?? 'root',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

// GUARD: skip if tenant_modules doesn't exist yet (test runs with bounded
// historic schema where core slot 069 hasn't run). Production + apply-
// pending-migrations always has it.
$hasTable = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES "
    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_modules'"
)->fetchColumn();

if (!$hasTable) {
    return;
}

$pdo->exec(<<<'SQL'
INSERT INTO tenant_modules
    (id, tenant_id, module_slug, available_at, available_by, enabled_at, enabled_by, disabled_at, created_at, updated_at)
SELECT UUID(), t.id, 'communications', UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP(), NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM tenants t
WHERE NOT EXISTS (
    SELECT 1 FROM tenant_modules tm
    WHERE tm.tenant_id = t.id AND tm.module_slug = 'communications'
)
SQL
);
