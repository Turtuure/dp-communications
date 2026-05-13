# dp-communications — Communications module

0.8 milestone module. See platform repo `docs/superpowers/specs/2026-05-13-communications-v1-design.md` (spec) and `docs/superpowers/plans/2026-05-13-communications-v1.md` (plan).

Manages: per-tenant SMTP, outbox queue, email-safe HTML templates, newsletter block composer, 3-category opt-in, public unsubscribe, payment-reminder + lapse-warning crons.

## Structure

- `module.json` — manifest read by core's `ModuleRegistry`
- `backend/src/` — PHP code under namespace `DaemsModule\Communications\`
- `backend/bindings.php` — production DI bindings
- `backend/bindings.test.php` — test container bindings (InMemory fakes)
- `backend/routes.php` — public + backstage HTTP route registrations
- `backend/migrations/` — `communication_NNN_*.sql` migrations
- `frontend/backstage/`, `frontend/assets/` — daem-society UI

## Conventions

- PHPStan level 9 = 0 errors
- Tests use core's `KernelHarness`; per-module `bindings.test.php` swaps InMemory fakes
- Domain `DaemsModule\Communications\Domain\*` lives in module; no cross-domain consumers in core
- No `Co-Authored-By` in commits; identity is `Dev Team <dev@daems.fi>`
