# Roadmap

Deferred implementation work, in priority order. Nothing in this file has been built yet — it's the plan for the next session(s), captured now so it doesn't need to be re-derived. Each item links back to the audit finding that motivates it.

## 1. Demographics module (top priority)

**Why**: "Core Module 1" in the original scope, zero implementation today. See `AUDIT-2026-08.md` → Backend → Not started.

**Locked-in design decisions** (from planning conversation, refined by `docs/design/demographics-mobile-app-design.md` — a mobile PWA design reference, not being built as an app yet, but its screens define real field lists and workflow):
- Aggregate counts, not an individual member registry — matches the "monitoring system" framing of the original scope, avoids the scope blow-up of a full congregant database (dedup rules, PII handling).
- **Entry only at Church level**, by the Pastor. Real field list (from the design reference, replaces an earlier guessed set): total members, youth (13–35), women's/men's fellowship, Sunday school, seniors, gender split, new members, transfers out, baptisms, communion participants, conversions.
- **Not pure read-only above Church**: Subregion Overseer has a real approve/flag/request-changes review action (mirrors Budget's submit/approve/reject pattern). Region and Diocese are summary/analytics-only, no review action. See `docs/specs/demographics-module-spec.md` → Workflow.
- Reuse the existing `fiscal_years`/`fiscal_quarters`/`fiscal_months` tables (already built for Budgets) — no new period system.
- Follow the `Budget.php` / `BudgetController` pattern exactly: `territory_type` + `territory_id` columns, `Auditable` + `SoftDeletes` traits, same route-group shape, and reuse its submit/approve/reject workflow shape for the Subregion review actions.

**Steps**:
1. Finish `docs/specs/demographics-module-spec.md` (data model + workflow already decided; API Contract and Acceptance Criteria still need to be written in full).
2. Migration + `ChurchDemographic` model (or similar name — finalize in the spec).
3. `DemographicsController` — church-level CRUD (create/update own church only), Subregion review actions (approve/flag/request-changes), and summary/trend/compare read endpoints for Region/Subregion/Diocese.
4. `routes/api.php` — new `demographics` route group.
5. New module/submodule/permission rows, seeded the same way `DioceseBudgetModuleSeeder.php` did for Budgets: `diocese.demographics.entry.create|update` (Church), `diocese.demographics.review.approve|flag|request-changes` (Subregion), `diocese.demographics.summary.read` (Region/Subregion/Diocese).
6. `backend/tests/Feature/Demographics/*` (suite already reserved in `phpunit.xml`) — cover the full permission matrix per the spec's Acceptance Criteria.
7. Frontend: build out the 6 stub files in `diocese/demographics-analytics/*` + one new church-level data-entry page, in the existing PHP-include + `fetch()` pattern (no new frontend framework — see root `CLAUDE.md` → Project Structure). The mobile PWA in `docs/design/` is a separate, later effort — not part of this step.

## 2. Multi-Tenancy Readiness (real diocese-scoped, multi-diocese platform)

**Why**: confirmed as a real requirement (not just mockup future-proofing) — the app needs to genuinely support multiple dioceses, each with its own users, territories, and data, isolated from each other.

**Good news — partially designed for already**: `App\Enums\TerritoryType` already has a `GLOBAL` level *above* `DIOCESE` (`GLOBAL(0) → DIOCESE(1) → REGION(2) → SUBREGION(3) → CHURCH(4)`), and `TerritoryController` already has logic and a comment for "Global admin sees ALL dioceses (root level)" — the schema and at least one controller were built anticipating multiple Diocese-type root territories under one Global root. Only one diocese (`CCI-MWD`) currently has data seeded, and no hardcoded diocese assumptions were found in `app/`/`routes/` outside seeders (verified by grep).

**The real gap — not yet verified or built**:
- **Data isolation audit**: confirm every controller/query that lists or aggregates data (Territory, Budget, Users, Roles, and the upcoming Demographics) actually scopes to the requesting user's diocese, rather than silently returning platform-wide data because only one diocese has ever existed. This needs a real read-through of each controller, not an assumption — `TerritoryController` shows evidence of diocese-awareness, `BudgetController` was not confirmed either way.
- **Diocese-code login**: the mobile design's login screen requires a diocese code before email/password. The backend has no such gate today — `AuthController` has no diocese-code check. Territory already has a unique `code` column (Diocese rows use codes like `CCI-MWD`) — reuse that as the diocese code rather than adding a new field.
- **Onboarding a new diocese**: is there a flow to create a brand-new Diocese-type territory + its own admin user (a "create tenant" flow), or does that stay a manual/seeder-driven process for now? Not decided — needs a decision before this is genuinely usable with more than one diocese.
- **Cross-diocese Global Administrator behavior**: already partially built (`SuperAdminConfig`, `global_access` flag) — verify it actually means "see everything across all dioceses" in practice, not just "see everything within one diocese."

**Sequencing**: doesn't block starting Demographics (item 1) — Demographics inherits territory-scoping automatically since it's keyed off `territory_id`, and no diocese-specific assumption needs to go into it. Do the data-isolation audit as its own focused pass, ideally before onboarding any second real diocese, but it doesn't need to happen before Demographics ships.

## 3. Seeder bug fixes — DONE (2026-08-18)

**Why**: see `AUDIT-2026-08.md` → Seeders. These made the documented test logins (`TEST-LOGINS.md`) untrustworthy.

- ✅ Retired `SampleUsersSeeder` from `DatabaseSeeder`'s call list — its users are fully superseded by `DioceseLeadershipSeeder` + `RegionalLeadershipSeeder` (and, for church-level pastors, `FixAndSeedRealChurchLeadershipSeeder`).
- ✅ Added `FixBishopIdentityAndRetireSampleAccountsSeeder` — corrects any database seeded before the retirement (Bishop identity, leftover duplicate overseer accounts) so the fix applies to existing dev DBs, not just fresh ones.
- ✅ Added `SubregionalLeadershipSeeder` (one Subregional Overseer, read-only role, test/dev account — no real person named in any source document) so every territory level has a documented, working test login.
- ✅ Re-verified end to end: `php artisan migrate:fresh --seed` runs clean, Bishop resolves to Peter Kilonzo, no `Pastor Sample`/generic overseer placeholders are created, `composer test` 46/46 green. `TEST-LOGINS.md` updated to match.
- Still open, optional and low priority: seed one user each for Diocese Secretary/Treasurer/Administrator if those roles ever need to be testable — currently unused by any seeded account.

## 4. Backend port → 8004

**Why**: requested directly, to have a stable, memorable local port instead of Laravel's default 8000.

- `backend/.env` (and `.env.example`) — set `APP_URL=http://localhost:8004`.
- Standard dev command becomes `php artisan serve --port=8004`.
- Update the frontend's `assets/js/config/app.js` API base URL from `127.0.0.1:8000` to `127.0.0.1:8004` **in the same change** — these two must move together or the frontend breaks.
- Add a root `package.json` with an `npx concurrently`-based `dev` script (mirroring `compass`'s `composer dev` pattern) that boots backend (8004) and frontend together in one command. `backend/`'s existing `composer dev` script is *not* reusable as-is for this — it boots Laravel's unused default Vite scaffold alongside the server; the root script needs to start the server + queue/logs (worth keeping) but point at the real PHP/YNEX frontend instead of Vite.

## 5. Configure Larastan

**Why**: `nunomaduro/larastan` is already installed (`backend/composer.json` → `require-dev`) but has no `phpstan.neon`, so static analysis isn't actually runnable yet despite the tooling being present. See `AUDIT-2026-08.md` → Backend → Testing & Code Quality.

- Add `backend/phpstan.neon` (Larastan's standard starter config, scoped to `app/`).
- Decide a rule level pragmatically — `compass` runs level 9, but that's a mature, already-typed codebase; starting Makueni West at a lower level (5–6) and ratcheting up is more realistic given zero prior static-analysis history here.
- Not blocking on demographics (item 1) — do this whenever there's a natural pause, it's independent of everything else in this roadmap.

## 6. Remaining budget stub pages

**Why**: the only real gap left in an otherwise fully-built module. See `AUDIT-2026-08.md` → Frontend → Stub.

- `diocese/budget-management/{index,monitoring,planning,reports}.php` — landing dashboard + monitoring/planning/reporting views. Same build pattern as the demographics analytics pages (item 1, step 7) — do this right after demographics as a natural follow-on, reusing whatever frontend conventions get established there.

## Deferred, not forgotten

- **Mobile PWA** (`docs/design/demographics-mobile-app-design.md`) — a real, detailed design for a native-feeling mobile app covering Demographics (and, later, Financial/Tithe) across all four roles. Confirmed as a real future build, explicitly **not started now** — kept as a design reference so the Demographics backend (item 1) is built compatible with it (field names, workflow) without committing to building the PWA itself yet.

## Explicitly not planned

- No rewrite of the *existing desktop* frontend to a JS framework (Vue/React/Inertia) — the original scope docs specified Vue, but the decision (see root `CLAUDE.md`) is to keep finishing the current PHP-include + fetch pattern for the desktop admin pages, not chase the old spec's tech choice. (The mobile PWA above is a separate, later, explicitly-deferred effort — not a contradiction of this.)
- No splitting `backend/` into its own repo yet — documented as the eventual intent, not scheduled.
- No individual member/congregant registry — the aggregate-counts decision for Demographics (item 1) is deliberate, not a placeholder for "do it properly later."
