# Church Budgeting Spec

A church pastor prepares their church's budget and submits it; the diocese approves or sends it back. A church also keeps its own budget lines next to the diocese's shared ones.

Status: built (2026-09-30). The budget module itself (types, categories, lines, budgets, workflow) predates the spec convention; this spec covers what opening it to churches added and changed.

## Data Model

- `budgets` is unchanged. A church budget has `territory_type = church` and `territory_id` = the church.
- `budget_lines` gains an owner: nullable `territory_type` + `territory_id`.
  - Null means a **shared** line, set by the diocese.
  - Set means a line that belongs to **one church**. Its `territory_scope` is always `church`.
  - Slugs are unique per owner. The old global unique index is now `(slug, territory_type, territory_id)`.
- Status flow: `draft` → `submitted` → `approved` → `active` → `closed`, or `submitted` → `rejected`.
  - A `rejected` budget can be edited and **resubmitted**, which also clears its `rejection_reason`.

## Who is acting

The API keeps no "current role", so a person who holds a church role *and* a diocese role (e.g. a pastor on the Diocese Council) would otherwise act with every role at once.

- The budget pages send `X-Assignment-Id`: the assignment the user has switched to. It must be one of the user's own active assignments; any other value is ignored.
- Without that header, the user's primary assignment is used.
- Permissions are that assignment's role permissions at that territory's scope. This is the same list `AuthController::getUserPermissions()` gives the frontend.
- A **church user** is someone acting in an assignment at a church.
- Implemented in `App\Support\BudgetAccess`.

## Permission Rules

| Who | Can |
|---|---|
| Church user with `financialmanagement.budgetmanagement.budgetplanning.read` | List, view and export **their own church's** budgets only. The territory filter is forced from the assignment, whatever the request sends. |
| … `.create` | Create a budget. It always lands on their church. Also clone. |
| … `.update` | Edit, delete, and add/change/remove lines, **only while the budget is `draft` or `rejected`**. Once submitted it is the diocese's to review (400). |
| … `.submit` | Submit a draft, or resubmit a rejected budget. |
| Holder of `diocesebudgetmanagement.budgetplanning.budgetapprovalworkflow.approve`, or a global admin | See every budget; approve, reject, activate, close; apply, reverse or recalculate deductions. |
| Church user | Never approves: 403 on approve, reject, activate, close and deductions. |
| Church user | Read-only on budget types, categories, periods and deductions settings. Writes get 403 (`DenyChurchSettingsWrites`). |
| Church user with `church.settings.budgetsettings.budgetlines.{create,update,delete}` | Add, change and delete **their church's own** lines. Shared lines and other churches' lines return 403. |

Anyone else keeps today's behaviour, where the diocese pages gate access in PHP.

Seeded by `ChurchBudgetAccessSeeder`. It grants the church permissions to Senior Pastor, Associate Pastor, Church Administrator and Church Treasurer, and creates the diocese budget page permissions and nav rows that were missing.

## API Contract

All routes are under `auth:sanctum`. `/budgets/*` runs through `EnsureBudgetAccess`.

- `GET /budget-lines`
  - Church user: shared lines with scope `church` or `all`, plus the church's own lines. Never another church's.
  - Others: shared lines only, or `?church_id=` for one church's view.
  - Each line carries `budget_line_items_count`. For a church user it counts only that church's budgets.
- `POST /budget-lines`
  - For a church user, the owner is set to their church and the scope to `church`.
  - The slug is generated to be unique within that owner.
- `PUT/DELETE /budget-lines/{id}`
  - Church user: own lines only (403 otherwise).
  - `DELETE` returns 422 with `data.usage_count` if the line is used in any budget. Switch it off instead.
- `GET /budget-lines/{id}`: another church's line returns 404 to a church user.
- `POST /budgets` validates that every `items.*.budget_line_id` is usable by the budget's territory. A church may use shared church/all lines and its own lines; other territories may use shared lines only. Otherwise 422.
- `POST /budgets/{budget}/line-items`: add a line (same usability rule; 422 if the line is already in the budget).
- `PUT /budgets/{budget}/line-items/{lineItem}` and `DELETE /budgets/{budget}/line-items/{lineItem}`: what the Edit Budget page calls. These routes didn't exist before.
- `POST /budgets/{budget}/reject` accepts `reason` as well as `rejection_reason`.

## Pages

- Shared page bodies live in `includes/budget/*.php`. Wrappers check the permission and set `$budgetCtx` / `window.BUDGET_CTX` (see `includes/budget/context.php`):
  - diocese: `diocese/budget-management/budget-overview/*.php`
  - church: `church/budget/*.php`
- `church/settings/budget-settings/budget-lines.php` shows:
  - the shared types, categories and lines, locked
  - the church's own lines, which it can add, edit, switch off and delete

## Acceptance Criteria

Covered by `tests/Feature/Financial/ChurchBudgetAccessTest.php` and `ChurchBudgetLinesTest.php`.

1. A pastor lists only their church's budgets, even when asking for another territory.
2. A budget a pastor creates lands on their church.
3. A church budget can't use another church's line.
4. A pastor edits, adds, changes and removes lines, and submits their own draft.
5. A submitted or under-review budget is locked for the church.
6. Another church's budget returns 403 for every action.
7. A pastor can't approve, reject, activate or close.
8. The diocese approves. Reject accepts `reason`, and the pastor can resubmit.
9. A pastor who also sits on the diocese council acts in the role they're in. A foreign `X-Assignment-Id` is ignored.
10. Church users can't change shared budget settings.
11. A church sees shared church/all lines plus its own, and the diocese list shows shared lines only.
12. A pastor adds their own line, which is scoped to the church with a per-church slug, and can edit and delete it.
13. Shared lines and other churches' lines are locked for a pastor.
14. A line used in a budget can't be deleted.
15. The usage count a church sees counts only its own budgets.
