# Architecture

## Two apps, one repo

The system is **two logically separate applications** that currently share one git repository for dev convenience:

- **`backend/`** — a Laravel 12 API. Stateless (Sanctum Bearer tokens), no server-rendered views. Intended to become its own repo eventually.
- **Repo root** — a Bootstrap 5 "YNEX" admin template, used as a plain PHP-include shell (`includes/header.php`, `includes/sidebar.php`, `includes/footer.php`) around pages that fetch their real data client-side via vanilla JS `fetch()` calls to the backend API.

This isn't a clean SPA-and-API split, and it isn't server-rendered Blade either — it's a hybrid: PHP renders the page shell and does a server-side permission check before any HTML is sent, then JS takes over for data. That hybrid is a deliberate, working design (see "Access control" below) — not an accident to be "fixed" by picking one paradigm, at least not in the current phase (see `ROADMAP.md`).

## Territory hierarchy (Single Table Inheritance)

One database table, `territories`, holds every level of the org: Diocese → Region → Subregion → Church. `App\Models\Territory` is the base Eloquent model; `Diocese`, `Region`, `Subregion`, `Church` (in `backend/app/Models/`) each `extend Territory` and apply a global scope on `territory_type` (an `App\Enums\TerritoryType` enum) so, e.g., `Church::all()` only returns church-type rows even though they're all in the same table.

- `parent_territory_id` self-references `territories.id` to build the tree.
- `level` (integer) and `full_path` are denormalized for fast hierarchy queries without recursive CTEs.
- Type-specific controllers exist (`DioceseController`, `RegionController`, `ChurchController`) alongside a generic `TerritoryController` for cross-type hierarchy/tree queries.

**Why this matters for new modules**: any module whose data belongs to "a place in the hierarchy" (Budget today; Demographics next) should store `territory_type` + `territory_id` columns pointing at a row in `territories`, not create its own `church_id`/`region_id`/`diocese_id` foreign keys. `Budget.php` is the reference implementation — copy its `territory_type`/`territory_id` fillable pair and its scoping approach.

## Access control: two layers kept in sync

1. **Backend**: `spatie/laravel-permission`, layered with a custom `Module → ModuleGroup → Submodule → SubSubmodule → Permission` hierarchy. A user's permissions are a function of their **role** and their **territory assignment(s)** (`UserTerritoryAssignment` — a user can hold more than one, e.g. Regional Overseer + Diocese Council Member simultaneously).
2. **Frontend, PHP layer**: every protected page starts with `require_once` for `session-manager.php`, `auth-check.php`, `permission-check.php`, then calls `requirePermission('scope.module.submodule.action')` before rendering any HTML. This is a genuine server-side gate, not just UI hiding.
3. **Frontend, JS layer**: `assets/js/utils/auth-helpers.js` fetches the caller's permitted modules from `/api/modules/for-role` (or `/api/modules` if the user has global access) and caches them in `localStorage`. `includes/sidebar.php` renders the nav menu **from that cached data** — the sidebar is never hardcoded, so a role with no permission for a module simply never sees it in the nav.
4. **Sync bridge**: `authentication/ajax/sync-session.php` copies the token/permission state from JS/localStorage into PHP's `$_SESSION`, so layers 2 and 3 stay consistent even though one lives server-side and one client-side.

This is real, working infrastructure — the user has explicitly asked that it be preserved, not rebuilt, when new modules (starting with Demographics) are added.

## Where the real frontend work actually lives

Folder names in this repo are misleading if you go by name alone:

- **`diocese/`** — the real, built-out custom application. `diocese/settings/admin/*` (users, roles, modules, module-groups, permissions, role-management — all wired to the API, 300–1200+ lines each) and `diocese/budget-management/*` + `diocese/settings/budget-settings/*` (full budget CRUD + approval workflow UI).
- **`administration/`** — despite the name, this is a near-empty duplicate of what `diocese/settings/admin/*` already does. Don't build here; it looks like scaffolding that was abandoned once `diocese/settings/admin/*` was built instead.
- **`church/`, `region/`** — almost entirely 0-byte placeholder files. The one non-empty file in each (`dashboard/index.php`) is still the unmodified YNEX demo "Ecommerce" content, just wrapped in a real `requirePermission()` call.
- **`diocese/demographics-analytics/*`** — 6 files, all empty stubs. This is where the Demographics module's analytics pages will go.

Full inventory with line counts: `AUDIT-2026-08.md`.
