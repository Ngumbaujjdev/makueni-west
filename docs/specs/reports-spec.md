# Reports (PDF + Excel) — spec

**Status:** Phase 2 (engine and the church-level Demographics reports). Phase 3 adds the modal, the monitor and the verify page; Phase 4 adds subregion, region and diocese.

## Why
Churches need printable, trustworthy reports of what they record. The design follows v1-events-backend's reporting (one class per report, one PDF layout, background generation), with three changes:
- Totals are opt-in per column instead of printed everywhere.
- Insights and recommendations are built once and reused by any report.
- Every PDF carries a QR code that proves it came from the system.

## Data model

### `report_runs`
| column | notes |
|---|---|
| `id`, `uuid` | the uuid is the public handle; routes bind by uuid |
| `user_id` | who asked for it |
| `territory_id` | the church (later region/diocese) it's about |
| `report_key` | e.g. `demographics.summary` |
| `format` | `pdf` / `xlsx` |
| `params` | json: `fiscal_year_id`, `years`, `demographic_id`… |
| `status` | `queued` → `running` → `ready` / `failed` |
| `progress`, `stage` | 0–100 and a short human line ("Drawing the PDF") |
| `title`, `period_label`, `scope_label` | snapshotted, so the verify page never has to re-run the report |
| `file_path`, `file_name`, `file_size` | on the `local` disk under `reports/` |
| `verification_code` | unique, e.g. `MWD-DEM-7K4Q-92XF` |
| `file_hash` | SHA-256 of the finished file |
| `error` | set when failed |
| `started_at`, `finished_at`, `expires_at` | files are pruned after 7 days (`reports:prune`); the run row and its code stay, so a printed copy can still be verified |

## Report definitions (`app/Reports`)
- `Report`: `key()`, `title()`, `description()`, `icon()`, `module()`, `scopes()` (TerritoryType values), `build(ReportContext): ReportData`.
- `ReportRegistry` lists the report classes, and `forScope($type)` returns the ones that can run at a territory level.
- `ReportContext`: the territory, its type, the ancestry label ("St Paul's · Kathonzweni Subregion · Makueni Region"), the church ids it covers, the params and the user.
- `ReportData`: `kicker`, `title`, `periodLabel`, `scopeLabel`, `tiles`, `meta`, `sections`, `insights`.
- `ReportSection`: `heading`, `note`, `columns`, `rows`, `totalsLabel`.
- `ReportColumn`: `header`, `align` (`L`/`R`), `strong`, and `total` (`sum` | `latest` | `avg` | `null`).
  - A section has a totals row **only if some column declares a total**. `latest` is for headcounts, where adding months together would be wrong.
  - A totals row that only counts rows is not allowed.

### Demographics reports (church scope)
| key | title | totals |
|---|---|---|
| `demographics.summary` | Demographics summary (fiscal year) | headcounts `latest`, changes `sum` |
| `demographics.monthly` | Monthly statistics (fiscal year) | per column: `latest` / `sum` |
| `demographics.spiritual` | Spiritual activities, all four (fiscal year) | `sum` |
| `demographics.baptisms` / `.holy_communion` / `.conversions` / `.departures` | One activity on its own - the report behind each Spiritual Activities tab | by period `sum`; membership comparison none |
| `demographics.growth` | Growth analytics (1/3/5 years or all) | none |
| `demographics.metric` | One metric (`metric`: the metric page's keys, e.g. `sunday_school`), all time by default. Metrics recorded by gender show both parts plus the total. `lockedOnly`: reached from its metric page, not listed in the full report list. | headcounts `latest`, flows `sum` |

Fiscal-year reports also take `fiscal_year_id: "all"`, which means **All time**: every approved submission, labelled "All time (2024-2026)". There are no "not reported" gaps for all time, because gaps only make sense within one year.

Spiritual activities and its four activity reports share `group: "Spiritual activities"`, so the modal shows them together.
| `demographics.submission` | Submission report (one submission) | none |

### Attendance reports (church scope, 2026-09-30)
All five build from `App\Reports\Attendance\AttendanceData`, the same class behind the Attendance Analytics page (see `demographics-module-spec.md`). `module()` is `attendance`, and the verification codes start `MWD-ATT-`.

**Inputs.** Every attendance report takes `fiscal_year` (an id, or `"all"`) and `fiscal_month` (`month`: 1-12, ignored for all time). Ministries and events also take `gathering_type` (`gathering_type_id`), which must belong to one of the report's churches or the request returns 422 on `gathering_type_id`.

**Totals.** Sunday figures are **averages, never sums**: the same congregation comes every week.

| key | title | contents | totals |
|---|---|---|---|
| `attendance.summary` | Attendance | Sunday service by month, ministries, special events; every attendance insight rule | Sundays `sum`; averages none (an average of monthly averages isn't the period's average) |
| `attendance.sunday` | Sunday service | Every Sunday, with missed Sundays listed as "Not recorded", plus month by month | every count `avg` ("Average Sunday") |
| `attendance.ministries` | Ministry gatherings (with `gathering_type_id`: that ministry, e.g. "Kesha") | Leaderboard (times met, average, most, last met, status) and every meeting. With a type: its meetings only | times met `sum`; meeting total `avg` |
| `attendance.events` | Special events | Same shape as ministries | same |
| `attendance.children` | Children's attendance | Boys, girls, children and share for every Sunday, plus month by month | `avg` per Sunday |

**Replaces the old path.** These replace the synchronous `GET /attendance-reports/export-pdf|export-excel`, `AttendanceSummaryPdfReport`, `AttendanceReportExport`, `AttendanceReportWidgetService` and `GET /attendance-reports/widgets`, all removed. `attendance-pdf-reports-spec.md` is superseded.

### Page-aware export
Export on a page opens the modal **locked to that page's report**: only the period and the format are chosen, and "Choose a different report" unlocks the full list. The Reports page shows the full list.

**One module per modal.** The modal only lists its own module's reports:
- It is opened with `module` (from `data-module`, or the report key's prefix).
- `GET /reports/catalogue` takes `?module=`.
- Each module has its own Reports page (`church/demographics-growth/reports.php`, `church/attendance/reports.php`), built by the same `reports.js`.

**Attendance Export buttons:**

| Page | Report |
|---|---|
| Overview | `attendance.summary` |
| Sunday Services | `attendance.sunday`, for the year and month filtered |
| Ministries / Special Events | `attendance.ministries` / `.events`; with a ministry picked, that ministry's report (`gathering_type_id`) |
| Analytics | the open tab's report, for its period |

**Year numbers.** Pages pass `data-year` (e.g. 2026), and the modal turns it into the fiscal year id.

Each report names itself in the line above its title (`subject()`), e.g. "CHURCH HOLY COMMUNION REPORT" or "CHURCH SUNDAY SCHOOL REPORT", so a report never reads as a generic demographics report. The PDF header names the church body as **Christian Church International**.

## Insights (`app/Support/Reports/Insights`)
- `Insight {tone: good|watch|concern, title, detail, recommendation?}`
- `InsightRule::evaluate(ReportFacts): ?Insight`. Rules are small and independent, and a report lists the ones it uses. Later modules (attendance, budget) can add their own rules to the same engine.
- A report with no insights shows **no** insights part, in either the PDF or the Excel file.

## Outputs
- **PDF** (`App\Services\Pdf\DioceseReportPdf`, TCPDF). The layout of v1-events-backend's `TuqioReportPdf`, in diocese teal and gold:
  - a keyline and logo header
  - a scope heading above the title ("CHURCH REPORT"), then the title with an underline and "period · territory path"
  - a KPI strip and a details panel
  - tables with a teal heading band and totals only where declared
  - **One orientation per report, chosen automatically:** portrait when every table fits, landscape when any table doesn't. Every page of a report shares it; mixing portrait and landscape pages in one report was tried and rejected.
  - Insights ("What we noticed") and Recommendations come last, and only when there are any.
  - Footer: authenticity QR · "Generated … by …" · the verification code · page n / N.
- **Excel** (`App\Exports\ReportWorkbook`):
  - a Summary sheet (tiles and details, with the verification code in the header)
  - one sheet per section, with teal headers, frozen header row, auto widths and numbers kept as numbers; totals only where declared, as `SUM()` formulas or the latest value
  - a final "Insights & recommendations" sheet, only when there are insights

## API (auth:sanctum unless noted)
| method | path | purpose |
|---|---|---|
| GET | `/reports/catalogue?territory_id=&module=` | reports runnable for that territory's level, optionally one module's (`demographics`, `attendance`) |
| POST | `/reports/preview` | builds the report without queuing it; returns tiles, insights, per-section row counts, first 5 rows, and whether each section has totals |
| POST | `/reports` | queues a run and returns `{uuid, status}` |
| GET | `/reports/runs` | the user's 20 most recent runs |
| GET | `/reports/runs/{uuid}` | status, progress, stage, file info |
| GET | `/reports/runs/{uuid}/download` | the file (only while `ready`), with `Content-Length` so the browser can show real download progress |
| GET | `/reports/verify/{code}` | **public**, throttled to 30/min: `genuine` + title, scope, period, generated at/by, file hash. Never any figures. |

Body for preview and store: `{report_key, territory_id, format?, fiscal_year_id?, years?, demographic_id?, metric?, month?, gathering_type_id?}`.
- Bad input returns JSON 422, never a redirect, because a fetch would save a redirect's HTML as the file.

## Permissions
- **API:** `user->canAccessTerritory(territory)`, which honours `can_see_children`. This matches the Attendance export convention: the API checks territory access, and the PHP page gates the `…export` permission with `requirePermission()` before the Export button is shown.
- Runs are private to the user who created them. Another user's uuid returns 404.

## Acceptance criteria
1. The catalogue for a church lists the nine Demographics reports; a territory level no report supports gets an empty list.
9. `fiscal_year_id: "all"` covers every approved submission. Each activity report holds just its own column.
10. Attendance (`AttendanceReportsTest`):
    - `?module=attendance` lists the five attendance reports.
    - The Sunday report lists missed Sundays, and its totals row is the average Sunday, not the sum.
    - A ministry report narrows to one gathering type; another church's type returns 422.
    - Verification codes start `MWD-ATT-`.
    - Every attendance report also builds as Excel.
2. A report with no totals has no totals row. `latest` shows the last reported value, not the sum.
3. A report with no insights produces no insights part in either the PDF or the Excel file.
4. A narrow table lays out portrait and a wide one landscape (the test uses a 17-column table). A report containing any wide table is landscape on every page.
   - Monthly statistics is split into two tables in the PDF: "Membership & groups" and "Changes & Holy Communion". All 16 columns in one table wouldn't fit even in landscape without cutting numbers.
   - The whole Monthly statistics report is landscape.
5. `POST /reports` (sync queue in tests) produces a real PDF (`%PDF`) or xlsx, marks the run `ready`, and stores its size, SHA-256 and verification code.
6. Download works for the owner and returns 404 for anyone else. A user from another church gets 403 on preview and store.
7. Verify returns `genuine` with the details for a real code, and `not recognised` for an unknown one. It never includes figures.
8. A failing build marks the run `failed` with the error.
