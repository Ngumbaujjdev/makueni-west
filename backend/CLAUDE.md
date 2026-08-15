# Makueni West Diocese — Backend (Laravel API)

Laravel 12 API for the diocese management system. See the root `../CLAUDE.md` for the overall project (spec-driven process, design rules — those apply here too). This file is backend-specific conventions only, and is written to be self-contained enough to survive on its own once this local `backend/` copy is retired in favor of the real backend repo (see below).

**This is a real, separate repo already: `https://github.com/Ngumbaujjdev/v1-makueni-west-backend`.** `backend/` here in the `makueni-west` frontend repo is a **local working copy** — tracked in `makueni-west`'s own git history (no nested `.git`), kept co-located purely so backend + frontend run together during local dev. It does **not** auto-sync with the real backend repo; that repo's own `main` had gone stale (last real commit was the initial Laravel/auth scaffolding) until this local copy's state got synced over.

## Git Workflow

**Syncing this local copy to the real backend repo** (do this after backend work is merged in `makueni-west`, not per-commit):
1. Clone (or reuse a clone of) `v1-makueni-west-backend` separately, e.g. to `/tmp/backend-sync`.
2. Create a branch there: `git checkout -b sync-from-makueni-west-<date>`.
3. From `makueni-west`, run `git ls-files backend/` and copy every listed file into the clone, stripping the `backend/` prefix, replacing what's there.
4. Commit, push, `gh pr create` — pointed at the backend repo's own remote.
5. Don't force-push over that repo's `main` — its history predates the move into `makueni-west` and shouldn't be silently overwritten; let a PR reconcile it.

**Working in this local copy** (same rules as the root `CLAUDE.md`, repeated here so this file stands alone), always in this order:
- Before starting: `git checkout main && git pull origin main`, then `composer test` to confirm `main` itself is green — a failure here is pre-existing, not caused by the work you're about to do. **Then** `git checkout -b <prefix>/<name>`. Prefixes: `feature/<name>`, `bugfix/<name>`, `hotfix/<name>`. One branch per task. Never commit directly to `main`.
- After finishing: run `composer test` again — all green before going further — commit with a why-focused message, `git push -u origin <branch>`, `gh pr create` with a real title/summary/test-plan.
- `gh pr merge --merge` — **the push → PR → merge sequence is pre-authorized**, complete it without stopping to ask once the branch is committed and verified. Only pause if something is uncommitted, unverified, or unrelated to the task.
- Never commit `.env` or any file containing real credentials.

## Stack

- Laravel 12, PHP 8.2+
- Auth: `laravel/sanctum` (Bearer token API auth), `spatie/laravel-permission` (RBAC), `laravel/fortify` (2FA infra, installed but session-auth unused — API is token-based)
- Audit trail: `owen-it/laravel-auditing`, `spatie/laravel-activitylog`
- Other: `spatie/laravel-medialibrary`, `spatie/laravel-query-builder`, `barryvdh/laravel-dompdf`, `maatwebsite/excel`, `africastalking/africastalking` (SMS), `brick/money`

## Architecture: Territory Single-Table-Inheritance

There is **one** `territories` table. `Diocese`, `Region`, `Subregion`, `Church` all `extend Territory`, scoped by `territory_type` (global scope) and `parent_territory_id` for the hierarchy. `App\Enums\TerritoryType` defines the type enum. Any new module that's territory-scoped (like Budget, and like the upcoming Demographics) stores `territory_type` + `territory_id` columns and follows the same pattern — do **not** create a separate `churches`/`regions`/`dioceses` table.

Reference implementation for a new territory-scoped module: `app/Models/Budget.php` + `app/Http/Controllers/Api/BudgetController.php` (or `ChurchController.php` for a lighter example). Copy their `territory_type`/`territory_id` fillable pattern, their `Auditable` + `SoftDeletes` traits, and their route-group shape in `routes/api.php`.

## Roles & Permissions

Spatie-backed, but with a custom hierarchy layered on top: `Module → ModuleGroup → Submodule → SubSubmodule → Permission`. Permission names follow `<scope>.<module>.<submodule>.<action>` (e.g. `diocese.settings.budgetsettings.budgettype.read`). New modules add their module/submodule/permission rows via a seeder modeled on `database/seeders/DioceseBudgetModuleSeeder.php` — don't hand-create permission rows ad hoc in a controller or migration.

`UserTerritoryAssignment` scopes a user to a territory with `assignment_type` (`PRIMARY`/`SECONDARY`), and flags like `can_see_children`, `can_see_siblings`, `can_manage_users`, `can_manage_finances`. A user can hold multiple assignments (e.g. a Regional Overseer is also a Diocese Council Member) — check `getCurrentRole()` / role-switch behavior in `AuthController` before assuming "one user, one role."

## Testing & Code Quality

PHPUnit (not Pest — `pestphp/pest-plugin` is pre-authorized in `composer.json`'s `allow-plugins` but Pest itself isn't installed; don't add it without discussing it first, it'd be an unrequested framework migration). Suites are pre-declared in `phpunit.xml`:

```bash
composer test                             # preferred — clears config cache, then runs the full suite
php artisan test --testsuite=Diocese
php artisan test --testsuite=Financial
php artisan test --testsuite=Demographics # suite dir exists, no tests yet — first real content per docs/specs/demographics-module-spec.md
```

Only `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` exist today — there is no real coverage yet. New modules should NOT ship without Feature tests covering their permission boundaries (who can read/write what, scoped by territory) — that's the highest-value test surface in this codebase given how central the territory/permission system is.

Also already installed, from `composer.json`'s `require-dev` (mirrors the quality-gate tools `compass` uses):

- **`laravel/pint`** (code style) — run `./vendor/bin/pint` before opening a PR. No `pint.json` exists yet, so it runs Laravel's default preset.
- **`nunomaduro/larastan`** (PHPStan-based static analysis) — installed but **not yet configured**: there's no `phpstan.neon`, so `./vendor/bin/phpstan analyse` won't run out of the box. Tracked in `../docs/ROADMAP.md` as a gap to close, not something to treat as working today.

## Known Issues (see `../docs/AUDIT-2026-08.md` and `../docs/ROADMAP.md` for full detail)

- `database/seeders/SampleUsersSeeder.php` and `database/seeders/DioceseLeadershipSeeder.php` both `firstOrCreate` a Bishop at the same email with different data — a real bug, not yet fixed. Don't copy this dual-seeder-same-email pattern for new seeders.
- No seeder currently creates a Subregional Overseer user, despite the role existing.
- Larastan has no config file yet (see above).

## Environment / Serving

Currently defaults to Laravel's standard `php artisan serve` (port 8000). Target is port **8004**, tracked in `../docs/ROADMAP.md` — not yet applied. When it is, update `.env` `APP_URL` and the frontend's `assets/js/config/app.js` API base URL together in the same change.

There's already a `composer dev` script here (`npx concurrently` running `php artisan serve` + queue listener + `pail` logs + `npm run dev`) — **don't treat this as the real frontend dev command.** The `npm run dev`/Vite/Tailwind/`resources/js/`/`resources/css/` in this `backend/` folder are Laravel's untouched default scaffold, never wired to anything — the actual frontend is the separate PHP/YNEX app at the repo root (see root `CLAUDE.md`). When the root-level `npm run dev` orchestration script gets built (`../docs/ROADMAP.md` → 3), it should boot `php artisan serve --port=8004` + the real frontend, not reuse this script's Vite watcher.
