# Attendance PDF Reports Spec

## Data Model

No new tables or columns. Reuses `AttendanceReportWidgetService::widgetsFor()`'s
existing output verbatim — the same aggregation that already backs the
Attendance Reports dashboard tabs (`church/attendance/reports.php`). The
PDF layer only formats that output; it does not re-derive any number or
insight sentence.

## API Contract

`GET /api/attendance-reports/export-pdf`

Query params:

| Param | Required | Notes |
|---|---|---|
| `territory_id` | yes | The church; ownership-checked against the authenticated user's active assignments (same check `widgets()` uses). |
| `fiscal_year_id` | yes | Must exist in `fiscal_years`. |
| `fiscal_month_id` | no | If given, scopes the report to one fiscal month instead of the whole year. |
| `gathering_category_id` | **yes** | Unlike `widgets()`, this is required — the PDF report picker only offers the 3 real gathering categories (Sunday Service / Ministry Gatherings / Special Events), no "combined" report type. |

Response: `200` with `Content-Type: application/pdf`, body is the raw
PDF binary (`Content-Disposition: inline`). Errors: `403` (territory
ownership fails), `422` (validation).

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

- [ ] A Senior Pastor (or any church-level role) requesting a PDF for
      their own church's data gets a `200` with a valid PDF body.
- [ ] Requesting a PDF for a `territory_id` the user has no active
      assignment to returns `403`.
- [ ] Omitting `fiscal_year_id` or `gathering_category_id` returns `422`.
- [ ] The PDF's KPI-box values match the same category/period's
      `widgets()` response `stats` values exactly (no independent
      computation drift between the dashboard and the PDF).
- [ ] The frontend's "PDF Reports" tab is hidden for a role without
      `attendancemanagement.attendancereports.export`.
