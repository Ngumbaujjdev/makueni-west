# Attendance PDF Reports Spec

## Data Model

No new tables or columns. Reuses `AttendanceReportWidgetService::widgetsFor()`'s
existing output verbatim — the same aggregation that already backs the
Attendance Reports dashboard tabs (`church/attendance/reports.php`). The
PDF layer only formats that output; it does not re-derive any number or
insight sentence.

## API Contract

`GET /api/attendance-reports/export-pdf` and `GET /api/attendance-reports/export-excel`
share one request contract (`AttendanceReportController::resolveExportContext()`).

Query params:

| Param | Required | Notes |
|---|---|---|
| `territory_id` | yes | The church; ownership-checked against the authenticated user's active assignments (same check `widgets()` uses). |
| `fiscal_year_id` | yes | Must exist in `fiscal_years`. |
| `fiscal_month_id` | no | If given, scopes the report to one fiscal month instead of the whole year. |
| `gathering_category_id` | **yes** | Unlike `widgets()`, this is required — the report picker only offers the 3 real gathering categories (Sunday Service / Ministry Gatherings / Special Events), no "combined" report type. |
| `gathering_type_id` | no | Drills a non-weekly category (Ministry Gatherings/Special Events) down to one configured gathering type (e.g. just "Kesha"). Must belong to the same church - a type from another church returns `422` on `gathering_type_id`, same convention `AttendanceController` already uses. Ignored (not an error) if passed alongside a weekly category, since Sunday Service has no "types" concept. |

`export-pdf` response: `200` with `Content-Type: application/pdf`, body
is the raw PDF binary (`Content-Disposition: inline`). `export-excel`
response: `200` with the standard `.xlsx` content type, streamed via
`Maatwebsite\Excel\Facades\Excel::download()`. Errors (both endpoints):
`403` (territory ownership fails), `422` (validation, or an invalid
cross-church `gathering_type_id`).

Permission: `attendancemanagement.attendancereports.export` — this
permission row is **already seeded** (`ChurchSystemSeeder::seedAttendanceManagement()`
creates `['create', 'read', 'update', 'export']` for every submodule
including "Attendance Reports", and `ChurchRolePermissionsSeeder` already
grants all church-module permissions to every church-level role). No new
seeder work was needed. As with `widgets()`, this endpoint checks
territory ownership only, not the Spatie permission directly — matching
this codebase's existing convention where API controllers don't call
`hasPermissionTo()` themselves; enforcement is the two-layer PHP-page
`requirePermission()` + JS-sidebar/tab-visibility gate on the frontend.

## Permission Rules

Same territory/role rules as `attendancemanagement.attendancereports.read`
— any role with read access to Attendance Reports for a church already
has export access too (both come from the same bulk "full access to all
church modules" grant).

## Acceptance Criteria

- [x] A Senior Pastor (or any church-level role) requesting a PDF for
      their own church's data gets a `200` with a valid PDF body.
- [x] Requesting a PDF for a `territory_id` the user has no active
      assignment to returns `403`.
- [x] Omitting `fiscal_year_id` or `gathering_category_id` returns `422`.
- [x] The PDF's KPI-box values match the same category/period's
      `widgets()` response `stats` values exactly (no independent
      computation drift between the dashboard and the PDF).
- [x] A long descriptive KPI value (e.g. "Tuesday Fellowship (2x)")
      wraps within its box instead of spilling into the next one.
- [x] The footer includes a QR code encoding the report ID, church,
      category, period, and generation timestamp.
- [x] `gathering_type_id` collapses the breakdown table to that one
      type's row and recomputes the KPI stats/insights against only
      that type's records - not just re-labels the same whole-category
      numbers.
- [x] A `gathering_type_id` belonging to a different church returns
      `422` on that field.
- [x] `export-excel` returns a real `.xlsx` for both a weekly and a
      non-weekly category.
- [ ] The frontend's "PDF Reports" tab does not currently hide itself
      for a role without `attendancemanagement.attendancereports.export`
      specifically - this codebase has no existing per-action (as
      opposed to per-submodule) client-side permission check to reuse,
      and every church role that can reach this page already holds
      `.export` too (bundled with `.read` in the same bulk grant - see
      Permission Rules above). Revisit only if a role is ever seeded
      with `.read` but not `.export`.
