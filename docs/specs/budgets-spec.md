# Budgets Spec

Budgets for a church, a region and the diocese. Each place plans a month or a whole year, records the money that actually comes in and goes out, and sees how it's doing.

**Status:** being built in phases (started 2026-09-30).
- Phase 1: the budget model, access, the list, the form and details.
- Phase 2 (2026-10-01): recording money in and out (Spending), the Overview dashboard, and "What we noticed".
- Later phases: deductions and Budget Settings, reports, and the top-to-bottom views.

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

### `budget_entries` (money in and out, phase 2)

- `budget_id`, `budget_line_item_id`, `direction` (`in` | `out`), `amount` decimal(15,2), `entry_date`, `description`, `counterparty` (paid to / received from, optional), `method` (`cash` | `mpesa` | `bank` | `cheque`), `reference` (optional), `recorded_by`, `updated_by`, timestamps, soft deletes.
- Money can only be recorded on an **In use** budget, with a date inside its period. A draft returns 422 "The X budget is still a draft. Start using it first."; a closed budget can't be changed.
- A line's `actual_amount` is always the sum of its entries, refreshed by `BudgetBook` on every record, change, delete and restore; then the budget totals are recalculated once.
- Money recorded on a line the budget didn't plan adds that line with planned 0 and `budget_line_items.is_unplanned = true` ("Added X as an unplanned line" in History).
- Deleting is a soft delete, so it can be undone.

### `budget_logs` (History)

- `action` is a string: `created`, `updated`, `started`, `closed`, `reopened`, `deleted`, `retired`, and from phase 2 `entry_recorded`, `entry_changed`, `entry_removed`, `entry_restored` (e.g. "Recorded KES 300.00 spent on Church Rent (Rent for January, 10 Jan)").
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
| `{level}.budgets.overview.read` | The Overview dashboard (phase 2) |
| `{level}.budgets.spending.read` / `.record` | See / record, change and delete money in and out (phase 2) |
| `{level}.settings.budgetsettings.read` / `.update` | Budget Settings (phase 3) |

Seeded by `BudgetsAccessSeeder`. Whoever can read budgets can read the Overview and Spending; whoever can prepare budgets can record money.

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

## API Contract (phase 2)

| Method | Path | Does |
|---|---|---|
| GET | `/budgets/dashboard?year=&month=&territory_id=` | The Overview, from `App\Reports\Budget\BudgetData` (which the PDF reports will also use). `month` empty = the whole year. Returns `period` (`label`, `start`, `end`, `previous_label`, `time_pct` = how much of the period has gone, null unless it's the current one, `ended`), `budget` (the one covering the period: a month's own, or the whole year's with `share` 1/12 when viewing a month) or null, `budgets`, `totals` (`in_planned`, `in_actual`, `out_planned`, `out_actual`, `left_planned`, `left_actual`, `entries`), `previous` (the same totals for the period before), `lines` (`in` / `out`: name, planned, actual, left, pct, is_unplanned), `trend` (`days`: spent, received and an even pace per day for a month; `months` for a year), `spark` (six months), `recent` (8 entries), `insights`, plus `place`, `view_only` and `can_record`. |
| GET | `/budget-entries?year=&month=&budget_id=&direction=&territory_id=` | Money in and out for the period, newest first, with `stats` (`in`, `out`, `count`, `biggest_line`, `biggest_amount`). |
| POST | `/budget-entries` | Body `{budget_id?, budget_line_id, amount, entry_date, description, counterparty?, method?, reference?}`. The line decides money in or out. Without `budget_id`, the budget in use on that date is used (a month's before the year's); none returns 422 "There's no budget in use for April 2026…". Returns the entry and the line's new `planned`, `actual`, `left`. |
| PUT | `/budget-entries/{id}` | Change it, same body. |
| DELETE | `/budget-entries/{id}` | Soft delete. |
| POST | `/budget-entries/{id}/restore` | Undo a delete. |

Writing needs `{level}.budgets.spending.record` on the acting place's own budget; a place below gets 403 "View only".

### What we noticed (insight rules)

`BudgetStatusRule` (no budget for the period, still a draft, nothing recorded, nothing recorded for 14+ days), `BudgetOverPlanRule` (lines spent beyond plan), `BudgetSpendingPaceRule` (spending ahead of the time gone), `BudgetIncomeShortfallRule` (money in behind the time gone), `BudgetBalanceRule` (more out than in), `BudgetUnplannedRule` (money on unplanned lines).

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
- **Form, step by step** (the same stepper as recording Demographics, with a live preview beside it):
  1. **Month or year?** A clear "A month | The whole year" choice and the year. A period that can't be picked says why, e.g. "2026 already has budgets for January, February and March, so it can't also have a whole-year budget". **Copy amounts** from the last budget; afterwards the banner keeps an **Undo**.
  2. **Money in**: one amount per line, with "Last time" under each.
  3. **Money out (spending)**: the same. Deductions are not money out; they come from Budget Settings.
  4. **Check and save**: every planned line with its change against last time, and notes.
  - It uses the Demographics form's colours:
    - month chips tinted by status (In use green, Draft gold, Closed purple, Free white), with a "This month" badge and a legend;
    - each line as a number tile with its own coloured icon;
    - a preview with money in / out / left tiles, "x of y lines filled", and every line with its coloured dot.
  - Buttons: **Save as draft** (any step) and **Save and start using** (last step). Changing a budget opens at Money in with every step open.
- **Budget details:**
  - header: period, status, place, prepared by;
  - Lines, Spending and History tabs, each opening with a tinted strip of its figures; each line shows an icon, its % used, a bar, and planned / received or spent / left;
  - buttons only where allowed;
  - a "View only" banner for a place below.

## Pages (phase 2)

- **Finance → Budgets** gains **Overview** and **Spending** for each level, and **New budget** (the form) for people who can prepare budgets: `budgets.budgets.prepare` is linked to that page, so the menu shows it only to them.
- **Overview** (month by default, or the whole year):
  - a verdict card: **On track** / **Spending ahead** / **Over plan**, one sentence ("You've spent 4% of October's plan with 3% of the month gone"), money in and out bars with a "today" marker, and money left with a spent/left ring;
  - four cards with "vs last month" and sparklines;
  - "Spending through the month" (spent, received and an even-pace line) or, for a year, money in and out per month; next to it **What we noticed**;
  - **Plan vs actual** (the biggest 8 lines, Out/In, red when over plan) and **Money in by source** (a ring donut);
  - **Where the money is going** (top 6 lines with Show all) and **Recent money**;
  - no budget → "Prepare it"; a draft → "Start using it".
- **Spending:** cards, a filter bar (in/out, line, how it was paid, search) and the list; change, and delete with Undo; a note explaining why recording isn't possible when there's no budget in use.
- **Record money window** (Overview, Spending, a budget's page): in or out, which line (with what's left), amount and date (limited to the period), details and how it was paid; a live preview ("After this, Electricity has KES 800.00 left", or "over plan"); **Record another**.
- **Budget page:** a **Record money** button and a **Spending** tab.
- Windows everywhere follow the Demographics report window: a white header with one solid coloured icon tile, small uppercase section labels, tinted panels and a tinted footer.

## Reports (phase 4, brought forward)

PDF and Excel, through the shared report engine (`docs/specs/reports-spec.md`): queued on `reports`, verification codes `MWD-BUD-…`, files kept 7 days. The figures come from `BudgetData`, the same as the Overview, so the page and the report agree.

| Key | Title | Period | What's in it |
|---|---|---|---|
| `budget.summary` | Budget summary | a month or a whole year (`fiscal_year_id` + optional `month`; fiscal years are calendar years) | Tiles: planned in, received, planned out, spent, money left. Money in by line (planned · received · still to come · %). Money out by line (planned · spent · left · % used · "Over by …"). Month by month for a year (a year budget's twelfths add up to its plan). What we noticed, with recommendations. |
| `budget.spending` | Money in and out | the same | Money in, then money out: date · what for · line · from/to · how · reference · recorded by · amount, with totals. |
| `budget.statement` | Budget statement | one budget (`budget_id`), only from its own page | Its lines in and out, every entry recorded against it, and the latest 15 History sentences. |

- **Money** is a `ReportColumn::money()` column: 2 decimals in the PDF and preview, numbers formatted `#,##0.00` in Excel (totals stay `=SUM()`).
- **Who can export:**
  - the acting role needs `{level}.budgets.budgets.export`, for the place it acts for or a place below (`BudgetAccess::canView`), checked by `BudgetReport::authorize()`;
  - the catalogue hides budget reports from anyone else, and a request gets 403;
  - `budget_id` must belong to the territory asked for (422) and be one the user may see (403);
  - "All time" is refused (422).
- **Where:**
  - an **Export** button on Overview (summary), Budgets (summary for the year), a budget's page (statement) and Spending (money in and out), shown only with export;
  - a **Reports** page per level (`includes/budget/reports.php`, using `assets/js/pages/demographics/reports.js` with `REPORTS_PAGE.module = 'budget'`).
  - `budgets.budgets.export` is linked to the Reports page, so the menu shows it only to exporters.
- **Acceptance:**
  1. The summary's figures match the Overview's for the same period.
  2. Money shows with 2 decimals in the PDF, and as numbers in Excel.
  3. A statement for another place's budget is refused.
  4. A place below can be exported; upwards or sideways can't.
  5. A role without export sees no budget reports.
  6. A ready run has a `MWD-BUD-` code and a real PDF or XLSX file.

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

## Acceptance Criteria (phase 2)

1. Money can be recorded on an In use budget only, dated inside its period; a draft or an outside date returns 422 with a plain message.
2. Recording, changing, deleting and restoring an entry keeps the line's actual and the budget's totals right, and writes a History sentence.
3. Money on a line the budget didn't plan adds it as an unplanned line.
4. Without `budget_id`, money goes to the budget in use on that date; with none, 422.
5. A place below can read the dashboard and the entries, but recording returns 403.
6. The dashboard's totals and lines match the entries; a month of a whole-year budget counts a twelfth of its plan.
