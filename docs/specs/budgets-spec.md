# Budgets Spec

Budgets for a church, a region and the diocese. Each place plans a month or a whole year, records the money that actually comes in and goes out, and sees how it's doing.

**Status:** being built in phases (started 2026-09-30).
- Phase 1: the budget model, access, the list, the form and details.
- Later phases: spending, the dashboard, deductions, reports and the top-to-bottom views.

This spec replaces `church-budgeting-spec.md`, which described a church → diocese approval that no longer exists.

## Principles

- **Every level is independent.** A church's budgets are made and used within the church, a region's within the region, the diocese's within the diocese. Nobody from another level approves or changes them.
- **There is no approval step.** A budget is a **Draft** while it's being prepared, **In use** once someone starts using it, and **Closed** when it's done.
- **Viewing goes top to bottom, read-only.** The diocese can look at every region's and church's budgets, and a region at its churches'. Nobody sees upwards or sideways.
- **Plain words.**

| On screen | Stored as | Was |
|---|---|---|
| Money in / Money out | category slug `income` / `expense` | Income / Expense |
| Planned | `budgeted_amount` | Budgeted |
| Received / Spent | `actual_amount` | Actual |
| Which month or year? | `fiscal_year` + `period_month` | Fiscal Year + Budget Type + Budget Period + dates |
| Draft / In use / Closed | `status` `draft` / `active` / `closed` | Draft / Submitted / Under review / Approved / Rejected / Active / Closed |
| Start using | draft → active | Submit, Approve, Activate |
| History | `budget_logs` | Budget Logs |

## Data Model

### `budgets`

**Period**
- `fiscal_year` is the year. `period_month` is 1–12, or null for the **whole year**.
- `start_date` and `end_date` are always worked out from the period.
- `name` is also worked out ("January 2026 budget" / "2026 budget").
- A place has **either** a whole-year budget **or** month budgets in a year, and at most one per month. This is checked on save (422) and ignores deleted budgets.

**Status**
- `status` is `draft | active | closed`.
- Moves: draft → active ("Start using"); active → closed ("Close"); closed → active ("Reopen").
- A **Draft** or **In use** budget can be edited. A **Closed** one can't, until it's reopened.
- `started_at` / `started_by` and `closed_at` / `closed_by` record who moved it and when.

**Other columns**
- `budget_type_id` and `budget_period_id` are nullable and no longer written. `budget_types` and `budget_periods` stay, because Demographics reads months from them.
- The approval columns (`submitted_at`, `approved_*`, `approval_notes`, `rejection_reason`) and `status_id` are no longer used. They are dropped in the cleanup phase.

### `budget_line_items`

- One row per line in a budget, holding `budgeted_amount` (planned) and `actual_amount` (received or spent).
- The totals on `budgets` are recalculated in one place, `App\Services\Budgets\BudgetBook::recalculate()`.
- Net money left = money in − money out.

### `budget_logs` (History)

- `action` is a string: `created`, `updated`, `started`, `closed`, `reopened`, `deleted`, `retired`.
- A save writes **one** entry, listing the amounts that changed, e.g. "Electricity 5,000.00 → 6,000.00".
- Totals are not logged.

### Existing data

- Budgets that can't be a month or a year (the diocese's seeded quarterly drafts) are soft-deleted by the migration, with a `retired` history entry. `down()` restores them.

## Access

- The **acting place** is the territory of the role the user is working in: the `X-Assignment-Id` header, which must be one of the user's own active assignments, otherwise their primary assignment. Implemented in `App\Support\BudgetAccess`.
- **Own place:** read, prepare (create, edit, start using, close, reopen, delete a draft), record and export, depending on the role's permissions.
- **Places below** (diocese → regions and churches; region → its churches): read only, with `{level}.budgets.below.read`. Any write returns 403 "View only".
- **Never upwards or sideways.**
- Global admins can do everything; they pass `territory_id`.

### Permissions, per level (`{level}` = `church`, `region`, `diocese`)

| Permission | Allows |
|---|---|
| `{level}.budgets.budgets.read` | See this place's budgets |
| `{level}.budgets.budgets.prepare` | Create, edit, start using, close, reopen, delete a draft |
| `{level}.budgets.budgets.export` | Export (reports phase) |
| `{level}.budgets.below.read` | View budgets of places below (region, diocese) |
| `{level}.budgets.spending.read` / `.record` | Spending (phase 2) |
| `{level}.settings.budgetsettings.read` / `.update` | Budget Settings (phase 3) |

Seeded by `BudgetsAccessSeeder`.

| Level | Role | Grants |
|---|---|---|
| Church | Senior Pastor | Everything |
| Church | Associate Pastor, Church Treasurer | Read, prepare, export, record, settings |
| Church | Church Administrator | Read, prepare, record |
| Church | Church Secretary, Church Committee Member | Read |
| Region | Regional Overseer | Everything |
| Region | Regional Treasurer | Everything except settings.update |
| Region | Regional Secretary | Read, prepare, below |
| Region | Other region roles | Read, below |
| Diocese | Bishop | Everything |
| Diocese | Diocese Treasurer, Diocese Finance Officer | Everything |
| Diocese | Diocese Secretary, Diocese Administrator | Read, prepare, below |
| Diocese | Diocese Council Member | Read, below |

## API Contract (phase 1)

All routes are under `auth:sanctum` and `EnsureBudgetAccess`.

| Method | Path | Does |
|---|---|---|
| GET | `/budgets?year=&status=&search=&territory_id=` | This place's budgets, with stats. A `territory_id` below the acting place gives a read-only list. Also returns `previous_stats` (last year's totals, for "vs 2025"), and `top_out` / `top_in` (the year's five biggest lines by planned amount plus "Other", for "Where the money goes"). |
| GET | `/budgets/form?year=&month=` | What the form needs: usable lines grouped Money in / Money out, amounts from the latest earlier budget (`copy`), periods already taken in that year. |
| GET | `/budgets/{id}/form` | The same, for editing. |
| POST | `/budgets` | Body `{year, month\|null, notes, lines:[{budget_line_id, amount}], start: bool}`. One transaction. Lines at 0 are left out. |
| PUT | `/budgets/{id}` | Same body. Replaces the amounts. Sending `updated_at` returns 409 if someone saved in between. |
| DELETE | `/budgets/{id}` | Drafts only. |
| POST | `/budgets/{id}/start`, `/close`, `/reopen` | Status moves. |
| GET | `/budgets/{id}` | Details: lines with planned, actual, left and % used; totals; `can: {edit, start, close, reopen, delete}`; `view_only`; `previous` (the place's budget just before, with its planned amounts per line, for "vs January 2026"). |
| GET | `/budgets/{id}/history` | The History timeline in plain sentences. |

The old approval routes (`submit`, `approve`, `reject`, `activate`) and `clone` are removed.

## Pages (phase 1)

- Same pages for every level:
  - shared bodies: `includes/budget/{budgets,form,budget}.php`
  - thin wrappers: `church/budget/*`, `region/budgets/*`, `diocese/budgets/*`
  - old paths redirect.
- **Budgets:**
  - stats: budgets this year, in use, planned money in, planned money out;
  - a filter bar: year, status, search;
  - a table: period, status, planned in, planned out, money left, received, spent, prepared by;
  - a **New budget** button.
- **Form (one page):**
  1. Which month or year?
  2. Money in: one amount per line.
  3. Money out: one amount per line.
  4. Notes.
  - **Copy last budget**, **+ Add a line**, live totals.
  - Buttons: **Save as draft** and **Save and start using**.
- **Budget details:**
  - header: period, status, place, prepared by;
  - Lines and History tabs;
  - buttons only where allowed;
  - a "View only" banner for a place below.

## Look

Every budget page matches the redesigned Demographics pages (reference: `church/demographics-growth/index.php`):
- year buttons in the toolbar;
- KPI cards with "vs last year" or "vs last budget" and sparklines;
- one hero chart with summary chips;
- "Where the money goes" as a ring donut;
- breakdown bars and a status card.

The Budgets page is the year's dashboard, with "The year at a glance" as a tile per month. The form picks the period with chips and shows last time's amounts. The budget page compares every figure and line with the budget before it.

## Acceptance Criteria (phase 1)

1. A church user creates a month budget and a year budget for their church only, whatever territory the request names.
2. A second budget for the same month, or a month budget when a year budget exists (and the other way round), returns 422.
3. The form offers the lines this place may use, and **Copy last budget** fills the latest earlier budget's amounts.
4. Draft → Start using → Close → Reopen works. Only drafts can be deleted. A closed budget can't be edited.
5. There is no submit/approve/reject/activate endpoint any more.
6. A region user prepares the region's own budget and never sees another region's.
7. The diocese and a region can read the budgets of places below them. Writing to them returns 403.
8. A church user can't read a region's or the diocese's budgets, or another church's.
9. Saving a budget writes one History entry listing the changed amounts. History shows who did it.
10. Seeded quarterly budgets are retired by the migration, and the migration can be rolled back.
