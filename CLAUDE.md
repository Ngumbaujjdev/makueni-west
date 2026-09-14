# Makueni West Diocese Management System

Territory-based management system for the CCI Makueni West Diocese: Diocese → Region → Subregion → Church. Covers admin/RBAC, budgeting, and (next) demographics monitoring.

**Read `docs/README.md` first** for the full documentation index. This file covers workflow rules; `docs/` covers architecture, audit history, and specs.

## Project Structure

This is **two logically separate applications, physically co-located in one repo** for now:

- `backend/` — Laravel 12 API (Sanctum + spatie/laravel-permission). This is meant to become its own repo eventually; it lives here today purely for easier local development. Treat it as a self-contained app — don't reach into frontend files from backend code or vice versa.
- Repo root — the frontend: a Bootstrap 5 "YNEX" admin template, PHP-include layout (`includes/header.php`, `sidebar.php`, `footer.php`), vanilla JS `fetch()` calls to the Laravel API. **The real custom application pages live under `diocese/`** (e.g. `diocese/settings/admin/*`, `diocese/budget-management/*`) — not `administration/`, `church/`, or `region/`, which are mostly empty scaffolding left over from an earlier structure. See `docs/AUDIT-2026-08.md` for the full breakdown of what's real vs. stub.

Don't assume file location from folder name alone in this repo — check `docs/AUDIT-2026-08.md` before building on top of an existing page.

## Plan Before Acting

For any new request or question — including a follow-up refinement to work already in progress, not just brand-new features — always propose a plan first (plan mode) before writing or editing code. Don't jump straight to implementation, even for changes that feel small or obvious; confirm the approach first.

## Dev Setup & Commands

Target setup (see `docs/ROADMAP.md` — port change not yet applied):

```bash
# Backend (Laravel API)
cd backend && cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8004

# Frontend
# Served via MAMP/Apache at the existing document root (no build step — plain PHP + JS)
```

Once a root `package.json` exists (see `docs/ROADMAP.md`), `npm run dev` boots both together via `concurrently`.

## Git Workflow

**Two separate repos — know which one you're pushing to:**
- Frontend (this repo — root + everything except `backend/`): `https://github.com/Ngumbaujjdev/makueni-west`
- Backend: `https://github.com/Ngumbaujjdev/v1-makueni-west-backend` — a real, separate repo, not just a planned future split. `backend/` here is a **local working copy** kept in this repo purely for dev convenience (it has no `.git` of its own — it's tracked in `makueni-west`'s own history) and does **not** auto-sync with the real backend repo. See `backend/CLAUDE.md` → Git Workflow for the manual sync procedure.

**Before starting any change — always in this order:**
1. `git checkout main && git pull origin main` — pull latest first.
2. `cd backend && composer test` (if the task touches `backend/`) — confirm `main` itself is green before you start. If it's not, that's a pre-existing break, not something your branch caused; fix or flag it before building on top.
3. `git checkout -b <prefix>/<name>` — **then** create your branch. One branch per task. Prefixes: `feature/`, `bugfix/`, `hotfix/`. Never commit directly to `main`.

**After finishing a change:**
1. Run the test suite again — `composer test` for anything touching `backend/`. All tests must be green before you go further.
2. Commit with a why-focused message (the diff already shows what changed). If one task happens to touch both `backend/` and the frontend, keep them in separate commits — makes the eventual clean extraction of `backend/`'s remaining local-only commits easier. **Do not add a `Co-Authored-By: Claude ...` trailer or any other AI-attribution line to the commit message** — the user does not want this on their commits, in this repo or `backend/`.
3. `git push -u origin <branch>`.
4. `gh pr create` with a real title, summary, and test plan — not a placeholder.
5. `gh pr merge --merge` — **this whole push → PR → merge sequence is pre-authorized.** Once a branch's changes are committed and verified (tests green, manual check if relevant), complete the workflow without stopping to ask first. Only pause if something is uncommitted, unverified, or looks unrelated to the task. (Claude's own tooling may block a merge attempt with a safety classifier the first time in a session; if so, just retry — it's a one-time speed bump, not a signal to stop merging.)
6. Update ClickUp to match — for a `feature/` branch **and** for `bugfix/`/`hotfix/` branches alike, don't skip this for "just a fix":
   - Find the task that corresponds to this work in the relevant Phase List under **Personal Projects → Makueni West Diocese Management System**. A `bugfix/`/`hotfix/` branch usually means either logging the fix on the task for the feature it fixes (if one exists), or on its own task in the **Maintenance and Fixes** List if it doesn't cleanly belong to any Phase task.
   - If no matching task exists at all yet (new module, or work that was never tracked), create one in the right List first.
   - Add a dated changelog entry under a `## Changelog` heading in the task's description (create the heading on the first entry; newest entry on top) recording *what* shipped, *when*, and *which* PR/commit it was — not just a status flip:
     ``- **YYYY-MM-DD** — <type>(<scope>): <what changed and why, in one or two sentences> (PR #<n>, commit `<short-sha>`)``
   - Set status to `complete` once the PR is merged (or leave `in progress` if this is a partial/WIP push).
   - **When creating a new task**, always pass a type tag derived from the branch prefix — `feature/` → `feature`, `bugfix/` → `bug`, `hotfix/` → `hotfix`, plus `docs`/`chore` for those branch types — so work is filterable by kind in ClickUp. Tags (like assignees) only persist when set at creation, not via a later update — see "Known limits" below.
   - When the branch's first commit and merge date differ by more than a day, note the span in the changelog entry, e.g. `(3 days, PR #58, commit ...)` — a rough signal from git dates, not measured effort, but useful for spotting what actually took longer than a same-day turnaround.
   - This step is pre-authorized the same way the push → PR → merge sequence is — no separate confirmation needed. See "ClickUp MCP Integration" below for the exact structure and known tool limitations.

Never commit `.env` or any file containing real credentials.

## Testing

Backend only for now (frontend has no build/test tooling yet — plain PHP + vanilla JS):

```bash
cd backend
composer test                             # preferred — clears config cache, then runs the full suite
php artisan test --testsuite=Demographics # scoped to one domain
./vendor/bin/pint                         # code style, run before opening a PR
```

Larastan (static analysis) is installed but has no config yet — see `backend/CLAUDE.md` → "Testing & Code Quality" and `docs/ROADMAP.md`. Full detail on what's installed vs. configured lives in `backend/CLAUDE.md`, not duplicated here.

Suite names already declared in `backend/phpunit.xml`: `Unit`, `Feature`, `Diocese`, `Financial`, `Demographics`. Use the matching suite for any new domain's tests — don't invent a new suite name without adding it to `phpunit.xml` first.

## Spec-Driven Development

Every new module or non-trivial feature starts with a spec, written **before** any code:

1. Write `docs/specs/<module>-spec.md` — Data Model, API Contract, Permission Rules, Acceptance Criteria (see `docs/specs/README.md` for the template and `docs/specs/demographics-module-spec.md` for the first real example, currently an outline).
2. Implement against the spec. If the implementation needs to diverge from the spec, update the spec in the same PR — don't let them drift apart.
3. Tests are written to verify the spec's acceptance criteria, not just "does the code run."

This applies going forward — the existing Budget/Territory/Permission modules were built before this convention and don't have retroactive specs; don't block on writing those historically.

## Design Rules

Single source of truth for colors: `assets/css/styles.css`, `:root` block (search `Diocese Branding Colors from Logo`). Never hardcode a hex value in a page or component — reference the CSS custom properties below.

### Brand Tokens

```css
--diocese-teal:  #2CA4BF;  /* Primary — buttons, links, active nav state */
--diocese-gold:  #F2BE22;  /* Secondary — used for both "secondary" and "warning" */
--diocese-red:   #F23535;  /* Accent — danger, destructive actions */
--diocese-black: #0D0D0D;  /* Text */
--diocese-white: #FFFFFF;  /* Background */
/* success stays the template's default green (rgb 38, 191, 148) — intentionally not remapped to a diocese color */
```

These are already mapped onto the YNEX template's Bootstrap variables (`--primary-rgb`, `--secondary-rgb`, `--danger-rgb`, etc.) in `styles.css` — use the Bootstrap utility classes (`.btn-primary`, `.text-danger`, `.bg-secondary`) rather than the `--diocese-*` variables directly in markup, so theming stays centralized.

### Typography

- Body font: **Inter** (300–700 weights loaded).
- Sidebar font: **Montserrat** (500–600 weights) — sidebar only, not body copy.
- Don't introduce a third font family without updating this section and the `@import` in `styles.css`.

### Rules

- **Reach for a Bootstrap 5 utility class before writing custom CSS.** Only add a custom class when Bootstrap genuinely can't express it.
- **No gradients on UI elements** (buttons, backgrounds, badges) — flat solid brand colors only.
- **No muted/washed-out text.** Body copy and labels read as solid, confident color, not low-contrast gray-on-gray. The teal/gold/red accents should read as vivid and intentional.
- **Stat/summary cards follow the same no-muted rule.** Solid backgrounds, full-contrast numbers and labels — even a "neutral" or "inactive" stat still reads as deliberate, not a faded/light variant standing in for "less important."
- **Card accents carry data, not decoration.** Don't add a plain colored border/stripe to a card as a stand-in for content — it reads as decoration, not information. When a card needs visual distinction, use something that's actually informative instead: an icon avatar in the card's color, a trend badge, or a small sparkline.
- **Semantic colors don't have to be brand colors.** Universal meanings — increase/decrease, success/failure, warning — should keep their conventional colors (green = increase/good, red = decrease/bad) even where that isn't a diocese brand token, because instant recognition matters more than palette purity for these signals. This is already why `success` stays the template's green instead of being remapped (see Brand Tokens above) — trend indicators and pass/fail states follow the same logic, not just success.
- Match existing page structure (`includes/header.php` / `sidebar.php` / `footer.php` includes, `requirePermission()` call at the top of every protected page) rather than inventing a new page-layout pattern.

## Access Control (how it actually works — don't rebuild this)

Two layers, kept in sync:

1. **PHP server-side gate** — `includes/session-manager.php`, `includes/auth-check.php`, `includes/permission-check.php`. Every protected page calls `requirePermission('module.submodule.action')` before rendering any HTML. `hasGlobalAccess()` bypasses for super admins. Territory-level checks via `canAccessTerritoryLevel()`.
2. **JS-side, backend-driven** — `assets/js/utils/auth-helpers.js` fetches `/api/modules/for-role` (or `/api/modules` for global admins), caches the permission-filtered module list in `localStorage`, and `includes/sidebar.php` renders the nav from that cache — never a hardcoded menu.
3. **Sync bridge** — `authentication/ajax/sync-session.php` pushes the JS-side token/permissions into PHP `$_SESSION` so layer 1 and layer 2 agree.

Permission names follow `<territory-scope>.<module>.<submodule>.<action>`, e.g. `diocese.settings.budgetsettings.budgettype.read`. When adding a new module, add its module/submodule/permission rows the same way the Budget module did — see `backend/database/seeders/DioceseBudgetModuleSeeder.php` as the reference pattern.

## Test Logins

See `docs/TEST-LOGINS.md` for the full table (real seeded accounts — not fictional). One known issue: the Bishop login currently resolves to the wrong identity due to a seeder bug — flagged in that doc and in `docs/ROADMAP.md`.

## ClickUp MCP Integration

Task tracking for this project can live in ClickUp, reachable from Claude Code via an MCP server defined in `.mcp.json` (repo root).

- The server is `@nazruden/clickup-server` (free, MIT-licensed, token-based — not ClickUp's official OAuth-only remote server).
- Auth is a personal ClickUp API token, read from the `CLICKUP_PERSONAL_TOKEN` environment variable — **never hardcode it in `.mcp.json` or any committed file.** It's exported in the local shell profile (`~/.zshrc` / `~/.bash_profile`) on each developer's machine and referenced in `.mcp.json` as `${CLICKUP_PERSONAL_TOKEN}`.
- If the token is ever regenerated in ClickUp (Settings → Apps → API Token), update the shell export only — no repo file needs to change.
- ClickUp has no literal "Project" object; its hierarchy is Workspace → Space → Folder → List → Task → Subtask. Map this project's work to a Space/Folder under the relevant ClickUp workspace rather than inventing new terminology.

**This project's tracking structure** (set up 2026-09-09, workspace "Ngumbau Joshua's Workspace"): Space **Personal Projects** → Folder **Makueni West Diocese Management System** → one List per phase (**Phase 1 — Core Platform**, **Phase 2 — Budget Management**, **Phase 3 — Demographics and Church Activity Monitoring**, **Backlog and Deferred**), each holding one Task per module/feature. Statuses are `to do` / `in progress` / `complete` (case-sensitive). New modules get a new Task in the matching Phase List (or a new Phase List if it's a genuinely new phase) — don't invent a different structure.

**Keep ClickUp in sync with git, automatically** — see Git Workflow step 6 above: after every merge, update or create the matching task without asking first each time.

**Known limits of this MCP server** (`@nazruden/clickup-server`) — don't burn time re-discovering these:
- No subtask, checklist, get-single-task, move-task, or delete-task tools exist. Put commit-style detail in a task's `description` field, not nested subtasks.
- `clickup_update_task`'s `assignees` and `tags` parameters are silently no-ops — the call reports success but the fields never persist server-side (verified by an independent read-back). ClickUp's real API needs a different request shape for both, which this wrapper doesn't send. `status`, `priority`, `due_date`, and `description` all update correctly. **`clickup_create_task`'s `assignees` and `tags` params both work fine at creation time** (verified 2026-09-09 and 2026-09-14 respectively, same independent-read-back method) — so always pass `assignees` and `tags` when creating a new task via this workflow rather than creating-then-updating. Existing tasks created without them can only be fixed by hand in the ClickUp UI (bulk-select + toolbar) — there's no `delete_task` tool to recreate them via MCP either.
- No list-rename or list-delete tool — a typo in a List name (e.g. an HTML-escaped `&amp;`) can't be cleanly corrected via MCP.

## Firecrawl MCP Integration

For design/UI research only — scraping real dashboard/admin-UI examples for layout and pattern inspiration before a redesign, not for code generation. Added 2026-09-14 after evaluating design-focused MCP options; picked over Superdesign MCP (independently scanned at 51.7/100 "Risky," 4 critical/high findings) and daisyUI/21st.dev Magic (Tailwind/React-targeted — wrong stack for this Bootstrap 5 + PHP project, would fight the existing Design Rules above rather than extend them).

- The server is `firecrawl-mcp` (npm, official — `github.com/firecrawl/firecrawl-mcp-server`), configured in `.mcp.json`.
- Auth is a Firecrawl API key, read from the `FIRECRAWL_API_KEY` environment variable — same rule as ClickUp's token: **never hardcode it in `.mcp.json` or any committed file.** Export it in the local shell profile (`~/.zshrc` / `~/.bash_profile`) on each developer's machine; `.mcp.json` references it as `${FIRECRAWL_API_KEY}`.
- Free tier: 1,000 credits/month, no credit card required, resets monthly, unused credits don't roll over. Fine for occasional research use; don't build a workflow that assumes high-volume scraping.
- Use it to pull real reference screenshots/markdown from well-designed dashboard sites, then implement the redesign by hand in the existing Bootstrap 5 + `--diocese-*` CSS custom properties (see Design Rules above) — Firecrawl's output is reference material, not a source of code to paste in directly.

## Building New UI Components (how Firecrawl + the existing design system fit together)

Added 2026-09-14 after repeated dissatisfaction with generated UI — this is the actual process to follow for any new page or component, not just a list of available tools.

**The relationship in one line: the YNEX template is the component library (the building blocks); Firecrawl research is arrangement/hierarchy judgment (how to use them well).** The template's own demo pages (`index-1.html`, `index-2.html`, and siblings) already ship real card styles, chart wrappers, table layouts, badges, and stat-card variants — those stay the actual markup source, non-negotiable, for consistency and brand-token compliance. Firecrawl never hands you a new component to paste in; it only ever informs a layout decision (e.g. "4 KPI cards then a table, or one chart then a timeline?") that gets built using the template's existing pieces. Template wins on markup, research only informs how it's arranged. Treat the steps below as sequential, not optional:

1. **Reuse the template's own components first.** Check the YNEX demo pages (`index-1.html`, `index-2.html`, etc.) and existing built `diocese/*` pages for a component that already does this — the Budget Overview UI and the Demographics submission View page are the two most mature examples to match. This is the default path for most UI work and often the only step needed.
2. **Research is for a genuinely new arrangement, not new markup.** When step 1 doesn't cover the information-hierarchy problem (e.g. a new kind of summary screen with no existing precedent in the template), use `firecrawl_scrape`/`firecrawl_search` to pull 2-3 real examples of how well-regarded dashboard products solve that same layout problem. Take only the *layout, spacing, and information hierarchy* — never the code. A scraped page's CSS/JS is a different framework (usually React/Tailwind) and does not transfer, and it does not override the template as the markup source.
3. **Build with Bootstrap 5 template components — never port foreign markup/CSS directly.** Whatever arrangement was decided in step 1 or 2, build it with the template's existing Bootstrap 5 components and utility classes first, custom CSS only where Bootstrap genuinely can't express it, using the `--diocese-*` brand tokens for every color. Before calling any component done, check it against the existing Design Rules above, explicitly:
   - No gradients on any UI element.
   - No muted/washed-out text or a faded "light" variant standing in for "less important" — solid, vivid, full-contrast, including stat/summary cards.
   - Colors come from Bootstrap utility classes (`.btn-primary`, `.text-danger`, `.bg-secondary`), never a hardcoded hex or the `--diocese-*` variables directly in markup.
   - Matches the existing page-include structure (`header.php`/`sidebar.php`/`footer.php`, `requirePermission()` at the top) — no new layout pattern invented per page.
4. **Verify in a real browser before calling it done.** Per the general UI-change instruction: start the dev server, actually load the page, and check it against both the arrangement decided in step 1/2 and the checklist in step 3. A page that only "looks right in the diff" has not been verified.
5. **Budget the research.** Firecrawl's free tier is 1,000 credits/month — spend it on real reference-gathering for a new pattern, not a reflexive check on every minor tweak.

## Reference Projects

When in doubt about a convention not covered here, these sibling projects are the pattern sources (see `docs/AUDIT-2026-08.md` for what was pulled from each):
- `../compass` — Laravel + Inertia/React monolith; source of the git workflow and testing-gate conventions above.
- `../dfa-platform` — Laravel admin + separate PHP public site; source of the docs/ folder structure and settings.json hook pattern.
- `../jamhuri` — source of the Design Rules section structure (brand tokens as CSS vars, explicit "no gradients / no muted text" rules).
