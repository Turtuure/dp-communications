-- 099_seed_tenant_modules_communications.sql
-- Backfill: every existing tenant gets a tenant_modules row for the
-- communications module with available_at = NOW() and enabled_at = NOW(),
-- matching the 0.5-era convention of migration 072 (which seeded the 5
-- existing modules).
--
-- Without this row, ModuleRouteGuard returns 404 for /backstage/communications
-- and /api/v1/backstage/communications/* paths because the module is not
-- enabled for the tenant — even though `default_available = true` in
-- config/modules.php.
--
-- Idempotent via NOT EXISTS guard. New tenants onboard via the GSA
-- CreateTenant flow (which honours default_available), not via this seed.

SET @now := UTC_TIMESTAMP();

INSERT INTO tenant_modules
    (id, tenant_id, module_slug, available_at, available_by, enabled_at, enabled_by, disabled_at, created_at, updated_at)
SELECT UUID(), t.id, 'communications', @now, NULL, @now, NULL, NULL, @now, @now
FROM tenants t
WHERE NOT EXISTS (
    SELECT 1 FROM tenant_modules tm
    WHERE tm.tenant_id = t.id AND tm.module_slug = 'communications'
);
