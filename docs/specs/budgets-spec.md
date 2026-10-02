# Budgets Spec

Budgets for a church, a region and the diocese. Each place plans a month or a whole year, records the money that actually comes in and goes out, and sees how it's doing.

**Status:** complete (built in phases, 2026-09-30 to 2026-10-02).
- Phase 1: the budget model, access, the list, the form and details.
- Phase 2 (2026-10-01): recording money in and out (Spending), the Overview dashboard, and "What we noticed".
- Phase 4 (2026-10-02): Budget Settings, with lines and deductions for each level. Reports were brought forward.
- Phase 5 (2026-10-02): the places below - a region's churches, the diocese's churches and regions - read-only, with the budget.rollup report.
- Phase 6 (2026-10-02): cleanup - the approval-era routes, controllers, pages, permissions and columns removed.

This spec replaced `church-budgeting-spec.md` (a church → diocese approval that no longer exists; removed in phase 6).

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
- `budget_types` and `budget_periods` stay, read-only (`GET /budget-types`, `GET /budget-periods`), because Demographics reads months from them; budgets no longer point at them.
- Dropped in phase 6: `budget_type_id`, `budget_period_id`, `status_id`, the approval columns (`submitted_at`, `approved_*`, `approval_notes`, `rejection_reason`) and `budget_line_items.is_locked`. `total_deductions` is the sum of the budget's deduction snapshots (`BudgetBook::recalculate()`).

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

## Detail pages and when money moved (phase 2e)

- **History** is shown as the template's Recent Activity timeline (`.crm-recent-activity`):
  - grouped by day, with a coloured dot per kind of event and the amount in colour;
  - an event about one entry links to that entry's page.
  - From now on, entry events name the entry in `budget_logs.affected_model = 'budget_entry'` / `affected_model_id`.
- **Entry page** (`entry.php?id=`, from `GET /budget-entries/{id}`):
  - the amount, what for, when and how;
  - every recorded detail;
  - its effect on the line: planned, before this, this amount, left or over;
  - its own History, and the other money on the line.
  - Change and Delete (with Undo) for the place itself; a place below is view-only; sideways is 403. A removed entry can still be opened.
- **Line page** (`line.php?budget=&line=`, from `GET /budgets/{budget}/lines/{lineId}`):
  - planned against received or spent, and against the budget before;
  - **when the money moved:** a whole-year budget month by month **by entry date**, against a planned twelfth; a month day by day against an even pace;
  - every amount on the line;
  - "Record money on this line" (the window opens with the line chosen).
- **Whole-year budgets:**
  - `GET /budgets/{id}` returns `months` (money in and out per month by entry date);
  - the budget's page shows a Month by month card, and its Spending tab groups entries by month.
- **Record money window:** when today is outside the budget's period, the date starts empty and must be picked ("When was it paid? Pick the day, between …"), so nothing silently lands on the period's last day.
- **Acceptance:**
  1. An entry's "before this" counts the line's earlier entries only.
  2. An entry's History lists only its own events.
  3. A year budget's months follow the entry dates.
  4. A place below sees entries view-only; sideways gets 403.

## Budget Settings (phase 4)

One simple page per level: **Settings → Budget Settings → Lines and deductions** (`includes/budget/settings.php`; wrappers `church|region|diocese/settings/budget-settings/index.php`). The church's old Budget Lines page redirects there.

**Lines tab:**
- A place uses the shared lines meant for its level (set by the diocese, **locked** for churches and regions) and its **own** lines.
- The diocese looks after the shared lines and says who uses them: only the diocese's own budgets, every church, every region, or everyone.
- For each line: add, rename, describe, switch on/off (switched-off lines aren't offered for new budgets), and delete only when no budget has used it.
- A used line can't move between money in and money out.

**API** (`BudgetSettingsController`; the acting role decides the place, a global admin names it with `territory_id`):

| Method | Path | Does |
|---|---|---|
| GET | `/budget-settings` | The place's lines: side, own or shared, who it's shared with, editable, used here, used anywhere |
| POST | `/budget-settings/lines` | `{name, side: in\|out, description?, share_with?}` - `share_with` is for the diocese only (own / church / region / all) |
| PUT | `/budget-settings/lines/{id}` | Rename, describe, `is_active`, side (if unused), `share_with` (shared lines) |
| DELETE | `/budget-settings/lines/{id}` | Only an unused line; otherwise 422 "switch it off instead" |

**Permissions:**
- `{level}.settings.budgetsettings.read` / `.update`, granted to:
  - church: Senior Pastor, Associate Pastor, Treasurer, Administrator;
  - region: Overseer, Treasurer;
  - diocese: Bishop, Treasurer, Finance Officer, Administrator.
- A place may only change its own lines (and the diocese the shared ones); anything else gets 403.

### Deductions (2026-10-02)

A deduction is a share sent up out of the money a place **actually receives** - what it records - worked out for you. For example, the diocese sets **"Diocese share: 10% of Tithes received, for every church"**. When a church records KES 40,000 of tithes, its budget shows **due KES 4,000 · sent KES 2,500 · still owed KES 1,500**; offerings and other money in don't count. While it plans, the church sees an **estimate** (10% of the tithes it plans) on its "Diocese share" money-out line, so money out and money left stay realistic - but what's due always follows what is recorded (2026-10-02).

**The rule:**
- **How:** a % of money in, or a fixed amount each month. A fixed amount counts 12 times on a whole-year budget.
- **On:** all money received, or only some money-in lines, e.g. Tithes (`basis` = `all` | `lines`, `basis_line_ids`). The diocese's Add window starts on Tithes.
- **Paid through:** a money-out line (`budget_line_id`). What was sent is just money recorded on that line, so nothing is counted twice. The add window can make that line ("make a new line called …"). A diocese line made this way is shared with whoever the deduction applies to. A region can't make lines for its churches; it picks an existing line instead.
- **Applies to** (`applies_to_level`):
  - church: its own only;
  - region: its own, or its churches;
  - diocese: its own, every church, every region, or everyone.
- **On or off:** a switched-off deduction is left out of budgets saved after. It can only be deleted when no budget uses it; otherwise 422 "switch it off instead".

**Owner:** `budget_deductions.territory_type` / `territory_id`, with the slug unique per owner. A deduction from above is shown **locked** on the page below as **"Standard"** - worded neutrally on purpose (2026-10-02): it's the system's standard, not one level ordering another. Standard lines read "Standard · Everyone" the same way.

**The standard share** (`StandardDeductionsSeeder`, DatabaseSeeder phase 28, only created when missing): "Diocese share: 10% of Tithes received", every church, paid through the standard "Diocesan Tithe" line.

**Reaching budgets already in use:** when a deduction is added, changed or switched on/off, every **Draft or In use** budget it reaches works it out again at once (`Deductions::openBudgetsFor()` + `BudgetBook::reapplyDeductions()`), past months included; History says "Added Diocese share: 10% of Tithes received (estimate from the plan KES x)" or "… no longer applies". **Closed** budgets are frozen and left as they were.

**Which apply to a place:** its own (applies own or all), plus those set by the places above it that apply to its level or to all. Only active ones count.

**Estimated on every save** (`BudgetBook::save`, `App\Services\Budgets\Deductions`) - the plan's figure, not what's due:
- base = planned money in (all, or the chosen lines);
- amount = % × base, or the fixed amount (×12 for a year);
- written as the planned amount of the paid-through line (added if the budget lacks it), with `budget_line_items.budget_deduction_id` set;
- a snapshot is kept in `budget_deduction_items` (`rate_type`, `rate_value`, `base_amount`, `deduction_amount`), so a later change to the rule doesn't rewrite old budgets until they're saved again;
- History: "Estimated Diocese share from the plan: 10% of Tithes received (KES 100,000.00 planned) = KES 10,000.00".

**Due · sent · still owed** (budget details, Overview, reports) - always from what is recorded:
- due = the snapshot's % × money in **received** (a fixed amount is due as planned);
- sent = what was spent on the paid-through line;
- still owed = due − sent, never below 0.

**API:**

| Method | Path | Does |
|---|---|---|
| GET | `/budget-settings` | Also returns `deductions` (own and inherited, with `editable`), `applies_choices` and `paid_through` (money-out lines, with the levels each can serve) |
| POST | `/budget-settings/deductions` | `{name, deduction_type: percentage\|fixed_amount, deduction_value, basis: all\|lines, basis_line_ids?, applies_to_level, budget_line_id? \| new_line_name?, description?}`; a % is at most 100 |
| PUT | `/budget-settings/deductions/{id}` | The same fields, or just `{is_active}` to switch it on or off |
| DELETE | `/budget-settings/deductions/{id}` | Only when no budget uses it |

`GET /budgets/form` returns the place's `deductions` rules; `GET /budgets/{id}` returns `deductions` with planned / due / sent / owed per deduction; `GET /budgets/dashboard` returns their totals when whole budgets are in view.

**Where it shows:**
- Budget Settings → Deductions tab: one card per deduction with its rule, paid-through line, who it applies to, an on/off switch and Edit. The rule names its lines ("10% of Tithes received"). The add window shows a live example ("Tithes received KES 60,000 → KES 6,000 due").
- The budget form, step 3: a locked "Estimate" tile per deduction that updates as money in is typed ("The real share is 10% of Tithes you record"), and a note in the preview.
- A budget's page: a Deductions tab led by due on money received · sent · still owed (then the plan's estimate), each row saying "10% of KES 40,000.00 Tithes received = KES 4,000.00 due", and "Record what was sent".
- Recording money in on a counted line: the window (and the entry's page) says "10% of this (KES 4,000.00) is the Diocese share".
- Overview: "KES x still owed in deductions" in What we noticed (`BudgetDeductionsOwedRule`).
- Reports: Budget summary and One budget get a Deductions section (due on received · sent · still owed · estimate).

**Acceptance criteria** (`BudgetDeductionsTest`):
1. A diocese deduction for every church is worked out on a church budget, shown as "Standard" and locked for the church, and tracked as due / sent / owed. History names it, and the Overview notices what's still owed.
1a. A new deduction reaches budgets already in use at once (not closed ones), and switching it off removes it from them.
2. A deduction on only some lines uses only those lines: 10% of Tithes counts only the tithes recorded, not offerings.
3. A fixed amount is per month, and 12 times that on a year budget.
4. A switched-off deduction is left out of budgets saved after; a used one can't be deleted.
5. A region's deduction reaches only its own churches.
6. A region can't make lines for its churches, and a church can't set deductions for others. A % over 100 is refused.
7. The summary report lists the deductions.

## The places below (phase 5)

A region sees its churches' budgets and the diocese sees every church's (grouped by region) and its regions' own budgets - **read-only**, for a month or a whole year. Nobody sees upwards or sideways.

**Page:** region **Churches' budgets**, diocese **Regions and churches** (`includes/budget/below.php` + `assets/js/pages/budgets/below.js`; wrappers `region|diocese/budgets/below.php`; menu page `below`, linked to `{level}.budgets.below.read`).
- Year buttons and a month picker (the whole year first); the diocese has a **Churches | Regions** switch (`?level=region`).
- Cards: with a budget in use (x of y, drafts, without one), money received and spent (against the period before, with a six-month sparkline), deductions still owed.
- Spent against plan for the ten places with the most money out (red when over); who has a budget (In use / Draft / Closed / No budget) and What we noticed.
- The groups at a glance (the diocese's regions, or a region's subregions) - tapping one filters the table.
- One row per place: its budget and status, received and spent against plan (% pill: green, gold from 80%, red over), money left, deductions still owed. A place with no budget shows a gold "No budget" pill. Opening a row shows that place's Overview, view only, with a way back.

**Which budget counts** for a period is the Overview's rule (`BudgetData::plansFrom()`): a month budget for a month, or a twelfth of the whole-year budget; for a year, the whole-year budget or the month budgets. Received and spent are by entry date. Deductions come from `Deductions::status()` and, as on the Overview, are only counted on whole budgets.

**Service:** `App\Reports\Budget\BudgetRollup`, shared by the page and the report:
- `placesBelow(Territory, level)` - the churches (or regions) below, with the group each is shown under;
- `rows(Territory, year, month, level)` - one row per place;
- `summary(...)` - rows, totals, the period before, sparkline, groups and What we noticed (`BudgetRollupCoverageRule`, `BudgetRollupOverPlanRule`, `BudgetRollupOwedRule`, `BudgetRollupDraftsRule`);
- `owedFor(Territory, year, month)` - one place's deductions: who each is owed to (`owed_to`), its rate, planned, due, sent, still owed, in total and budget by budget. Built for a church's "what we owe upward" view (see Handed over).

**API:** `GET /budgets/below?year=&month=&level=church|region` - the acting region or diocese, with `{level}.budgets.below.read` (a global admin names it with `territory_id`). A church, or a role without "below", gets 403.

**Report `budget.rollup`** (region and diocese): tiles (in use x of y, received, spent, over plan, still owed), a chart of spent against plan, one section per region (or subregion) listing its churches with a budget, then "No budget yet", and What we noticed. Needs export and below, for the acting place itself.

**Acceptance criteria** (`BudgetBelowTest`):
1. A region sees only its own churches, with the right planned, received, spent, left and over-plan figures, and what we noticed.
2. The diocese sees every church with its region, the groups, and (level=region) its regions' own budgets.
3. A whole-year budget counts a twelfth in a month; entries count in the month of their date.
4. Deductions still owed roll up; `owedFor()` says who each is owed to.
5. A church, and a role without "below", get 403.
6. The rollup report builds, and isn't offered for a church.

## Reports (phase 4, brought forward)

PDF and Excel, through the shared report engine (`docs/specs/reports-spec.md`): queued on `reports`, verification codes `MWD-BUD-…`, files kept 7 days. The figures come from `BudgetData`, the same as the Overview, so the page and the report agree.

| Key | Title | Period | What's in it |
|---|---|---|---|
| `budget.summary` | Budget summary | a month or a whole year (`fiscal_year_id` + optional `month`; fiscal years are calendar years) | Tiles: planned in, received, planned out, spent, money left. Money in by line (planned · received · still to come · %). Money out by line (planned · spent · left · % used · "Over by …"). Month by month for a year (a year budget's twelfths add up to its plan). What we noticed, with recommendations. |
| `budget.spending` | Money in and out | the same | Money in, then money out: date · what for · line · from/to · how · reference · recorded by · amount, with totals. |
| `budget.statement` | Budget statement | one budget (`budget_id`), only from its own page | Its lines in and out, every entry recorded against it, and the latest 15 History sentences. |

- **More reports (phase 2f):**

  | Key | Title | What's in it |
  |---|---|---|
  | `budget.lines` | Line by line | Every line: planned · used · % · over or under · entries · last entry |
  | `budget.year` | Year at a glance | Year only: the 12 months, each month's budget, planned against received and spent, and money left |
  | `budget.compare` | Compare two periods | A month or year next to the one before: every line, and the change in KES and % |
  | `budget.exceptions` | Unplanned and over-plan | Lines over plan, and money on unplanned lines |
  | `budget.line` | Budget line | One line of one budget (`budget_id` + `line_id`, only from the line's page): every amount on it, and when it moved |

- **Charts in the PDF:** `ReportChart` (`bars`: grouped columns; `hbars`: planned soft behind actual, red when over). They are drawn by `DioceseReportPdf` under "At a glance"; two line charts sit side by side, and Excel keeps the tables.
  - Summary: money by month (a year) and the top lines, in and out.
  - Statement and Line by line: the lines.
  - Year at a glance and Money in and out: money by month.
  - Compare: this period against the one before.
  - Unplanned and over-plan: lines over plan.
- **Export window:** "Whole year" is first and the default; a month is preselected only when the page shows one. Year at a glance has no month to pick.
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

## Cleanup (phase 6)

Removed once nothing live used them:
- **Routes and code:** the old line-item, audit, summary, deduction and log actions on `/budgets/{id}`; `/budget-deductions`, `/budget-lines`, `/budget-categories`, `/budget-logs` and the write routes of `/budget-types` / `/budget-periods` (several had no permission check); their controllers, `DenyChurchSettingsWrites`, `BudgetsExport`, and the approval-era model methods.
- **Pages:** the old church Budget Lines and diocese Types / Categories / Lines settings pages are now redirects to Budget Settings, and leave the menu; the empty `diocese/budget-management/*` stubs and the old budget-management scripts are gone. The 301 redirects from the old list / create / edit / details pages stay, for bookmarks.
- **Permissions:** `BudgetsAccessSeeder` retires the old names on every run (`financialmanagement.budgetmanagement.*`, `diocesebudgetmanagement.*`, `diocese.budgetmanagement.budgetoverview.*`, `church.settings.budgetsettings.budgetlines.*`, `diocese.settings.budgetsettings.{budgettype,budgetcategory,budgetline}.*`). `ChurchBudgetAccessSeeder` and `DioceseBudgetModuleSeeder` are gone.
- **Seeding:** `BudgetsAccessSeeder` is the last phase of `DatabaseSeeder` (PHASE 27), so a fresh setup gets every level's Budgets and Budget Settings menus; the church-modules focus seeder no longer mutes the module holding the church's Budgets pages.
- **Columns:** see Data Model → Other columns.

## Finance follow-up (2026-10-02)

Built here, on the budgets engine (the Settings session keeps Finance *settings*):

**F1. Money in and out** - the Spending page, renamed (menu and page): every amount received and spent in a month or a year, across the place's budgets, with search, filters (in/out, line, how paid, **recorded by**) and Export (`budget.spending`). A 📎 marks entries with a receipt.

**F2. Receipts on entries** - a photo (JPG, PNG, WEBP) or PDF, at most 5 MB and 3 per entry:
- attached when recording (Record money → "Attach a receipt", optional) or later on the entry's page (a **Receipts** card: thumbnails, open full size, remove);
- kept with the media library on the **private** `local` disk (`BudgetEntry` media collection `receipts`), never public; streamed by the API only to people who may see the budget (`BudgetAccess::canSee`) - a level above can view, only the place itself (record permission) can add or remove;
- History says "Attached a receipt to …" / "Removed a receipt from …".

| Method | Path | Does |
|---|---|---|
| POST | `/budget-entries/{id}/receipts` | multipart `receipt`; 422 for another type, over 5 MB, or a 4th |
| GET | `/budget-entries/{id}/receipts/{mediaId}` | the file, inline |
| DELETE | `/budget-entries/{id}/receipts/{mediaId}` | take it off |

`GET /budget-entries/{id}` keeps its shape and adds `receipts` (id, name, type image/pdf, size, url) and `can.receipts`; the list's rows add a `receipts` count.

Acceptance (`BudgetReceiptsTest`): attach, stream, list and remove; a level above views but can't add; another place gets 403; a wrong type, too big a file or a 4th receipt gets 422.

**F3. Contributions** - a page per level in the Budgets menu (`contributions.php`, permission `{level}.budgets.contributions.read`, granted with read). Neutral wording: "the share", "sent", "still to send".
- **A church - month by month:** for each budget of the year that works out a share - received on the lines it counts, due, sent, still to send - and a status: **Sent** (all of it sent), **Still to send** (the month is still running), **Late** (the month ended with something unsent), **Nothing due** (nothing received on those lines). "Record what was sent" opens Record money on the share's line. Cards: received, due, sent, still to send.
- **A region / the diocese:** its own share (if any), and **Our churches** - every church below (grouped by region for the diocese) with due, sent, still to send, late months and a status; filters, search, and Open → that church's months, view only.
- `GET /budgets/contributions?year=&territory_id=` - `BudgetRollup::contributionsOf()` (rows), `contributionTotals()`, `contributionsBelow()` (with "below"); due / sent / owed from `Deductions::status()`.
- Report `budget.contributions` (PDF/Excel): month by month for a church; the churches (by region) above.
- Acceptance (`BudgetContributionsTest`): statuses Sent / Late / Still to send / Nothing due on dated months; a region sees only its churches, the diocese all; view only below, never upward; the report builds for a church and a region.

**Still to build here:** F5 clean-up of the empty finance menus and files.

**Stays with the Settings work:** Finance settings - financial year start, payment methods, M-Pesa and bank details, receipt numbering.

Budget Settings becomes one section of each level's Settings hub, embedding `includes/budget/settings.php` as is.

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
