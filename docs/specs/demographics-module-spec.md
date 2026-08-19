# Demographics Module Spec

**Status: backend fully built and tested (56 passing tests in `backend/tests/Feature/Demographics/`); frontend build in progress.** This spec originally described data model/workflow decisions made before implementation; it's now updated to match what was actually built, per the root `CLAUDE.md` rule that a spec and its implementation shouldn't drift apart.

## Data Model (as built)

- Aggregate counts per church per **month** — not an individual member/congregant registry.
- **Entry only at Church level**, by the Pastor (and, per a later permission decision, Associate Pastor / Church Secretary / Church Administrator too — see Permission Rules below). Region, Subregion, and Diocese never create their own rows — their views are computed by summing descendant churches through `territories.parent_territory_id`, via `App\Services\DemographicsGrowthService`.
- Uses the existing `fiscal_years`/`fiscal_months` tables (built for Budgets) for the period dimension.
- Follows `backend/app/Models/Budget.php`'s conventions: `territory_type` + `territory_id` fillable columns, `Auditable` + `SoftDeletes` traits, `created_by`/`updated_by`.
- Complementary weekly **Attendance** model (`ChurchAttendanceRecord`) added alongside the monthly snapshot — no approval workflow ("high-frequency, low-stakes data by design," per the controller's own docblock). A church can toggle between `weekly_and_monthly` and `monthly_only` entry mode via `Church.metadata->attendance_mode`, for churches with unreliable connectivity.

### Fields (real, from `DemographicsController::store()`'s validator — `backend/app/Models/ChurchDemographic.php`)

```
total_members, male_count, female_count, youth_count,
womens_fellowship_count, mens_fellowship_count,
sunday_school_male_count, sunday_school_female_count, seniors_count,
new_members_count, transferred_out_count,
baptisms_count, communion_participants_count, conversions_count
```

All nullable|integer|min:0 except the three ids (`territory_id`, `fiscal_year_id`, `fiscal_month_id`), which are required. `sunday_school_count` (combined) and `children_count`/`total_count` are computed Eloquent accessors on `ChurchDemographic`, not stored columns.

Validation: sub-counts (youth/fellowship/Sunday-school/seniors) exceeding `total_members` produce a **soft, non-blocking warning** (`buildValidationWarnings()`) returned in the response `warnings[]` array — never a hard rejection.

Workflow status: `status` — `draft` → `approved` on submit (see "Workflow" below for why this skips `submitted` as of 2026-08-19; `flagged`/`changes_requested` remain reachable only via the still-dormant review endpoints).

### Attendance fields (`ChurchAttendanceRecord`)

```
territory_id, service_date, service_type (sunday_service|special_event|ministry_gathering),
event_name (required unless sunday_service), adults_count, youth_count,
children_male_count, children_female_count, notes
```

`fiscal_year_id`/`fiscal_month_id` are derived server-side from `service_date`, not supplied by the client.

## Workflow (as built)

1. Pastor (or Associate Pastor/Secretary/Administrator) fills the form (`draft`), submits — **auto-approved immediately (`status` → `approved`), no manual review step** (changed 2026-08-19, product decision — see below).
2. Region and Diocese: summary/analytics only via `GET /demographics/summary/{territory}`, no review action of their own. Only `status='approved'` rows count toward any rollup figure.

**Why submit auto-approves rather than landing in `submitted`:** the original design (Pastor submits → Subregion Overseer approves/flags/requests changes) was never actually reachable end-to-end — Frontend Gap #1 below meant no page could ever list a Subregion Overseer's pending submissions, so a real `submitted` row would sit stuck forever with nobody able to act on it, and `DemographicsGrowthService`'s rollups only count `status='approved'` rows, so nothing was reaching Region/Diocese analytics either. Rather than build the missing Subregion Review page just to unblock this, the product decision (2026-08-19) was to drop the manual gate entirely for now: submitting **is** the final action. `POST /demographics/{id}/approve`, `/flag`, and `/request-changes` remain in the code, permissioned and tested (`SubregionReviewTest.php`), so a real review step can be reintroduced later without a schema change — they just have no submission left in `submitted` status to act on today.

## API Contract (as built)

Base: `backend/routes/api.php`, under `auth:sanctum`.

| Method | Path | Permission |
|---|---|---|
| GET | `/demographics?territory_id=` | ownership (`userOwnsChurch`) |
| POST | `/demographics` | `churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.create` |
| GET | `/demographics/{id}` | ownership |
| PUT | `/demographics/{id}` | `churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.update` |
| POST | `/demographics/{id}/submit` | ownership + `can_be_submitted` |
| POST | `/demographics/{id}/approve` | `subregiondemographicsreview.churchsubmissions.approve` |
| POST | `/demographics/{id}/flag` | `subregiondemographicsreview.churchsubmissions.flag` |
| POST | `/demographics/{id}/request-changes` | `subregiondemographicsreview.churchsubmissions.requestchanges` |
| GET | `/demographics/summary/{territory}` | tier-dependent: Subregion → `subregiondemographicsreview.churchsubmissions.read`, Region → `regiondemographicsanalytics.summary.read`, Diocese/Global → `demographicsanalytics.demographicssummary.read` |
| GET / PUT | `/churches/{church}/entry-mode` | ownership / `...sundayschoolenrollment.update` |
| GET | `/attendance?territory_id=&service_type=&fiscal_year_id=&fiscal_month_id=` | ownership |
| POST | `/attendance` | per `service_type`: `attendancemanagement.{serviceattendance\|specialeventsattendance\|ministryattendance}.create` |
| PUT | `/attendance/{id}` | same prefix, `.update`, resolved from the record's existing `service_type` |

**Deliberately one reused permission string per model covers the entire row** (not split per field group) — `DemographicsController`'s own docblock explains this: the row is submitted/reviewed as one atomic unit.

`GET /demographics/summary/{territory}` response shape (`DemographicsGrowthService::summaryFor()`):
```
{ membership: { total_members, male_count, female_count, youth_count, womens_fellowship_count,
    mens_fellowship_count, sunday_school_male_count, sunday_school_female_count, seniors_count,
    baptisms_count, communion_participants_count, conversions_count, churches_reporting, churches_total },
  attendance: { services_logged, average_attendance (float or null if zero records) },
  growth: { previous_month_total_members, delta, percentage (or null if previous total is 0) } }
```

No `DELETE` endpoint on either model, by deliberate decision (frontend planning round, 2026-08-18) — corrections happen by editing a `draft`/`changes_requested` row, matching how Budget already works in this app.

## Permission Rules (as built)

- **Church-level entry** (create/update/submit): `Senior Pastor`, `Associate Pastor`, `Church Secretary`, `Church Administrator` — granted via `GrantDemographicsEntryPermissionsSeeder` (2026-08-18; the module/submodule permission rows existed since the original `ChurchSystemSeeder` build but were never actually assigned to any church-tier role until this seeder). Every other church-tier role retains only the generic `...overview.read` permission (landing-page visibility, no entry access).
- **Subregion review** (approve/flag/request-changes): `Subregional Overseer` only, scoped to churches whose `parent_territory_id` matches the overseer's subregion (`userOverseesChurch()`).
- **Region/Diocese summary read**: `Regional Overseer` (region tier), Bishop and other diocese-tier roles already hold `demographicsanalytics.demographicssummary.read` from the original module scaffolding.

## Acceptance Criteria — met, verified by `backend/tests/Feature/Demographics/` (56 tests)

- `ChurchDemographicModelTest.php` / `ChurchAttendanceRecordModelTest.php` — model fillable/casts/computed-attribute coverage.
- `DemographicsControllerTest.php` / `AttendanceControllerTest.php` — own-church CRUD succeeds, cross-church CRUD 403s, submit transition, non-blocking validation warnings, entry-mode toggle.
- `SubregionReviewTest.php` — overseer can act only within their own subregion (403 outside it), all three review-action status transitions, a church user cannot approve their own submission, an already-approved submission can't be re-reviewed.
- `RollupSummaryTest.php` — subregion/region/diocese rollup math (real mixed-hierarchy totals, not just single-level sums), growth-delta calculation, 403 for unauthorized readers.

## Frontend Gaps Discovered (2026-08-18, during frontend planning — tracked here so they aren't rediscovered)

1. **No endpoint lets a Subregion Overseer list pending submissions in their subregion** — `index()`/`show()` only accept the exact church owner (`userOwnsChurch`), not the hierarchy-aware `userOverseesChurch()` that the review actions already use. Blocks the Subregion Review page until a small backend addition lands (planned as its own phase before that page is built).
2. **No `GET /fiscal-months` endpoint** — worked around in the frontend by reusing the already-existing `GET /budget-periods?fiscal_year_id=&budget_type_id=` endpoint (finds the `slug=monthly` budget type, dedupes the embedded `fiscal_month` objects). No backend change planned for this one.
3. **`module_group_id` was NULL on 35/39 modules system-wide** (not Demographics-specific — affected Diocese/Region/Church tiers alike) — a module with no group is invisible in the sidebar regardless of permissions held. Fixed in `BackfillModuleGroupAssignmentsSeeder` (PR #22).
4. **No church-tier role except Global Administrator held the real Demographics/Attendance entry permissions** — the permission rows existed but were never granted to any real role. Fixed in `GrantDemographicsEntryPermissionsSeeder` (PR #24).
5. **IA naming/ordering issues** (module names too long/redundant, two visually-adjacent "Overview" labels, Church Dashboard sorting after Demographics & Growth) — fixed in `FixDemographicsModuleNamingAndOrderingSeeder` (PR #24).
