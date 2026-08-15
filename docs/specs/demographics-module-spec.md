# Demographics Module Spec

**Status: data model & workflow decided; API contract and acceptance criteria still to be finalized before implementation starts**, per the spec-driven process in the root `CLAUDE.md`. Superseded the earlier aggregate-fields guess with the concrete field list and workflow from `../design/demographics-mobile-app-design.md` (a mobile PWA design reference — not being built as an app yet, but its screens define real product requirements).

## Data Model (decided)

- Aggregate counts per church per **month** — not an individual member/congregant registry. Matches the original scope's "monitoring system" framing; a full registry is materially larger scope (dedup, PII handling) and wasn't requested.
- **Entry only at Church level**, by the Pastor. Region, Subregion, and Diocese never create their own rows — their views are computed by summing descendant churches through `territories.parent_territory_id`.
- Reuse the existing `fiscal_years`/`fiscal_quarters`/`fiscal_months` tables (already built for Budgets) for the period dimension — no new period system. (The design reference calls this "month" — map it onto `fiscal_months`.)
- Follow `backend/app/Models/Budget.php`'s conventions: `territory_type` + `territory_id` fillable columns, `Auditable` + `SoftDeletes` traits, `created_by`/`updated_by`.

### Fields (from the design reference — this is the real, concrete list, not a guess)

Membership counts:
- `total_members`
- `youth_count` (ages 13–35)
- `womens_fellowship_count`
- `mens_fellowship_count`
- `sunday_school_count` (children)
- `seniors_count`
- gender split (male/female — the design shows a donut chart, implying `male_count`/`female_count` are tracked; confirm against `total_members` at validation time)

This month's changes:
- `new_members_count`
- `transferred_out_count`

Spiritual activities:
- `baptisms_count`
- `communion_participants_count`
- `conversions_count`

Workflow status: `status` — `draft` → `submitted` → `approved` / `flagged` / `changes_requested` (see Workflow below). This mirrors Budget's status pattern (`Budget.php` has `status`/`status_id`, submit/approve/reject actions) — reuse that shape rather than inventing a new one.

Validation rule from the design reference: youth/fellowship/Sunday-school/senior counts should not individually exceed `total_members` — surface as inline validation, not a hard DB constraint (a pastor might legitimately have edge cases; don't block submission on it, flag it).

## Workflow (decided — not pure read-only)

This corrects an earlier assumption: Region/Subregion/Diocese are read-only **for editing the raw counts**, but Subregion Overseer has a real review/approval action:

1. Pastor fills the form (`draft`), submits (`submitted`).
2. **Subregion Overseer reviews and acts**: Approve (→ `approved`, forwarded to Region), Flag (→ `flagged`, stays visible with a flag but doesn't block forwarding — per the design, flagging surfaces an anomaly like "youth up 40%" without necessarily rejecting), or Request changes (→ `changes_requested`, sent back to the Pastor with a note).
3. Region and Diocese: **summary/analytics only, no review action** in this design — they see consolidated, approved data, not a per-submission approval queue of their own.

## Multi-Tenancy (new — see `../ROADMAP.md` → Multi-Tenancy for the full decision)

The design reference's login screen requires a "Diocese code," confirming the backend needs to become genuinely multi-diocese-capable, not single-diocese with an unused `GLOBAL` territory type. Demographics itself doesn't need special multi-tenancy logic beyond what the rest of the system needs — it's already territory-scoped by `territory_id`, and a territory always belongs to exactly one diocese via the hierarchy. **Don't block starting the Demographics build on the multi-tenancy work finishing** — just don't hardcode any single-diocese assumption (e.g., don't hardcode the `CCI-MWD` territory ID anywhere in Demographics code; existing controllers already avoid this per the audit).

## API Contract (not yet written)

To define when implementation starts: full route list (church-level CRUD + the Subregion approve/flag/request-changes actions + region/subregion/diocese summary/trend/compare endpoints), request/response shapes, and which permission string each route requires. Follow the route-group shape already used for Budgets in `backend/routes/api.php`, including its submit/approve/reject action-endpoint pattern (`BudgetController`'s workflow methods are the direct precedent for the Subregion review actions here).

## Permission Rules (decided in outline, refined)

- `diocese.demographics.entry.create` / `...update` — Church-level role assignments only, and only for the assigning user's own church.
- `diocese.demographics.review.approve` / `...flag` / `...request-changes` — Subregion-level role assignments only (new — not in the original outline; added per the workflow decision above).
- `diocese.demographics.summary.read` — Region, Subregion, and Diocese-level role assignments. No write permission for Region/Diocese.
- Exact permission-string naming and module/submodule seeder entries to be finalized against `backend/database/seeders/DioceseBudgetModuleSeeder.php` as the reference pattern when this spec is completed.

## Acceptance Criteria (not yet written)

To define when implementation starts. At minimum must cover:
- A church user can create/update demographics for their own church, in `draft` status.
- A church user can submit (`draft → submitted`), but not approve their own submission.
- A church user gets 403 attempting to create/update another church's demographics.
- A subregion user can approve/flag/request-changes only for churches within their subregion; gets 403 outside it.
- A region/diocese user gets 403 on any write or review endpoint.
- A region/subregion/diocese user gets 200 on the summary endpoint, with correct roll-up totals (sum of descendant churches, `approved` submissions only — decide whether `flagged` counts toward rollups when this is finalized).
- Each acceptance criterion here should map directly to a test in `backend/tests/Feature/Demographics/`.
