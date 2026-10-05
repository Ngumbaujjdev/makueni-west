# Calendar Spec

One **Calendar** for each church, region and the diocese, built once and inherited like Budgets and Settings. Each place sees its own events and everything above it. At the top sits the **CCI calendar**: the national calendar of Christian Church International Kenya, which global admins type in or import each year. Every place sees it, marked as a CCI calendar event.

**Status:** C1 (data, API, permissions, menu, CCI import) and C2 (the page) done 2026-10-05. Decided with the owner:
- only **global admins** manage the CCI calendar;
- CCI events are **typed in or imported from an Excel/CSV file**;
- **every level** adds its own events from the first build.

**Phases**
- **C0:** this spec.
- **C1:** data, API, permissions and menu; CCI calendar management, including import.
- **C2:** the calendar page at church, region and diocese: inherited events, filters, and adding your own.
- **Later (not in this build):**
  - reminders by SMS/email through each place's Communication settings (`PlaceMessenger`);
  - an iCal link to subscribe from Google/Outlook;
  - linking a Special Event to its attendance record;
  - the dashboards' "Upcoming" widget (dashboards are designed last).

## Principles
- **One owner per event.** An event belongs to one territory. The CCI calendar is the national territory (`territory_type = global`, "Christian Church International Kenya", `CCI-KENYA`), which is already the root of the tree.
- **Seen from below, view only.** A place sees its own events, plus events from every place above it whose `shared_below` is true. CCI and diocese events are always shared. Nobody edits another place's event.
- **Looking down is a choice.** The diocese and a region can switch on "Churches below" to see the shared events of the places under them, view only.
- **The colour carries the layer**, and the icon and chip carry the kind:
  - CCI: a solid **CCI** badge, the danger colour (red);
  - Diocese: primary;
  - Region: purple;
  - Ours: success;
  - Below: secondary.
- **No empty pages.** The dead "Diocese Calendar" menu rows (views, management, sync, meetings, reports, all 0-byte files) are switched off. Calendar becomes one page per level.

## Data Model

### `calendar_events` (C1)
| Column | Type | Notes |
|---|---|---|
| id | bigint | |
| uid | char(36), unique | Stable id for a later iCal feed and for re-importing the same row |
| territory_id | FK territories | The owner. The national territory = CCI calendar |
| title | varchar(160) | |
| description | text, null | |
| kind | varchar(30) | `conference`, `fasting_prayer`, `service`, `meeting`, `deadline`, `holiday`, `celebration`, `other` |
| starts_on, ends_on | date | Inclusive. `ends_on >= starts_on`; one-day events have equal dates |
| all_day | bool | default true |
| start_time, end_time | time, null | Only when not all day. `end_time > start_time` on a one-day event |
| location | varchar(160), null | |
| repeats | varchar(10) | `none`, `weekly`, `monthly`, `yearly` |
| repeat_until | date, null | Required for weekly/monthly; optional for yearly (open-ended) |
| shared_below | bool | Default true for CCI, diocese and region; false for a church |
| source | varchar(10) | `manual` or `import` |
| created_by, updated_by | FK users, null | |
| timestamps, soft deletes | | Audited (OwenIt), like the other modules |

Index: (territory_id, starts_on).

**Occurrences are expanded on read**, not stored. A repeating event yields an occurrence per week, month or year from `starts_on` to `repeat_until` (or the end of the requested range), each keeping the event's length. Monthly on the 31st falls on the last day of shorter months.

## API Contract
All routes are under `auth:sanctum`, and the acting place comes from `X-Assignment-Id`, as in Budgets. Responses use `{success, status, message, data}` with real HTTP statuses.

| Method | Path | Body / query | Returns | Permission |
|---|---|---|---|---|
| GET | `/calendar/events` | `from`, `to` (≤ 400 days apart), `layers[]` (`cci`, `diocese`, `region`, `ours`, `below`), `kinds[]` | occurrences `{event_id, title, kind, start, end, all_day, layer, owner {id, name, type}, location, description, repeats, can_edit}` | `{level}.calendar.events.read` |
| GET | `/calendar/overview` | — | `{this_month, last_month, this_week, ours_upcoming, next_cci {title, start} \| null, by_month[12]}` | `.read` |
| POST | `/calendar/events` | event fields, optional `cci: true` | the event | `.manage` at the place; `cci: true` needs a global admin |
| PUT | `/calendar/events/{id}` | event fields | the event | the event's own place with `.manage`, or a global admin for CCI events |
| DELETE | `/calendar/events/{id}` | — | 204 | as PUT |
| GET | `/calendar/cci` | `year` | CCI events of the year (not expanded), for the management list | global admin |
| GET | `/calendar/cci/template` | — | a CSV template (`title, kind, starts_on, ends_on, all_day, start_time, end_time, location, description, repeats, repeat_until`) | global admin |
| POST | `/calendar/cci/import` | `file` (csv/xlsx ≤ 2 MB), `commit` (bool) | `{rows: [{line, values, errors[], duplicate}], valid, invalid}`; with `commit=1`, the valid, non-duplicate rows are saved: `{created}` | global admin |

**Import rules:**
- Dates are accepted as `YYYY-MM-DD` or `DD/MM/YYYY` (Excel dates too).
- `kind` accepts the label or the key ("Fasting & prayer" or `fasting_prayer`).
- A row is a **duplicate** when a CCI event with the same title and `starts_on` already exists; it is skipped, never doubled.
- At most 500 rows per file.

## Permission Rules
- `{level}.calendar.events.read` (level = church, region, diocese) opens the page and lists events. It's granted to every role at that level.
- `{level}.calendar.events.manage` adds, edits and deletes the place's own events. It's granted to:
  - church: Senior Pastor, Church Administrator, Church Secretary;
  - region: Regional Overseer, Regional Secretary;
  - diocese: Bishop, Diocese Administrator, Diocese Secretary.
- **CCI events:** global admins only (`hasGlobalAccess()`). There is no permission to grant, as decided.
- Nobody edits an event from another place, up or down. `can_edit` is true only for your own place's events, and for CCI events when you're a global admin.
- Menu: a **Calendar** module in each level's Programs group (`{level}-programs`) with one page `/{level}/calendar/`, created idempotently by `CalendarAccessSeeder`. It also switches off the dead "Diocese Calendar" module and its 0-byte pages.

### C1 as built
- `App\Services\Calendar\Calendar` works out the layers (`layersFor`: ancestors by type, `below` by walking `parent_territory_id`), the occurrences (`expand`: jumps close to the range, then steps; at most 600 per event) and the overview.
- `CalendarController::validated()` is the one set of event rules, shared by the API and the import. It forces `shared_below` on CCI and diocese events, and defaults it to false for a church.
- The import reads CSV and XLSX with PhpSpreadsheet (already installed through `maatwebsite/excel`). Each row goes through the same rules. Duplicates are matched on title and start date, against the CCI calendar and earlier rows of the same file.
- The tests are in the new `Calendar` suite (`tests/Feature/Calendar`).

## Pages
- `/{level}/calendar/` is a 5-line wrapper around `includes/calendar/page.php`, shared like Budgets.
- **KPI cards** (`renderSparkCard`):
  - events this month, with a trend vs last month and a sparkline over 12 months;
  - this week;
  - next CCI event;
  - our upcoming events.
- **Toolbar:**
  - layer chips (CCI · Diocese · Region · Ours, plus "Churches below" at region/diocese) and a kind filter;
  - view switch: Month / Week / List;
  - **Add event**, for those who can manage.
  - The state is kept in the URL.
- **FullCalendar v5** (already in `assets/libs`). Events are coloured by layer, with a kind icon, and CCI ones show a "CCI" badge.
- **Event window:** the title, kind, dates and times, place, repeat, location and description. It shows "CCI calendar event · Christian Church International Kenya" when it applies, with Edit/Delete only when `can_edit`.
- **Add / edit window** (`.app-modal`, tinted panels):
  - what (title, kind, description);
  - when (dates, all day or times, repeats and until);
  - where (location);
  - who sees it ("Us only" / "Us and the places below").
- **CCI national calendar** (global admins, at the diocese): a second section tab with:
  - the year's CCI events in a DataTable, with Add, Edit and Delete;
  - **Import** with a template download: upload the file, check each row (errors and duplicates shown), then save the valid rows.

### C2 as built
- `assets/js/pages/calendar/`:
  - `api.js` (`CalendarAPI`; the template downloads through `fetch` because it needs the sign-in header);
  - `event-modal.js` (`CalendarMeta` for layer colours and kind icons, `CalendarEventModal.details/form`);
  - `calendar.js` (the page);
  - `cci.js` (the CCI tab).
- **Layer chips by level:**
  - church: CCI · Diocese · Region · Ours;
  - region: CCI · Diocese · Ours · Churches below;
  - diocese: CCI · Ours · Churches below.
  - "Below" starts off; the view, date, layers and kind are kept in the URL.
- **Occurrences carry `base`** (the event's own dates and times) when editable, so editing a repeating event starts from the event, not the occurrence clicked.
- **`CalendarEvent` is in the morph map** (`calendar_event`). Without it the audit failed on the first save in a web request. Console tests don't audit, so `test_events_are_audited` switches auditing on.
- **The import's save is one transaction:** all the ready rows or none.
- The old `diocese/calendar/{views,management,sync,meetings,reports}.php` stubs redirect to the calendar.

## Acceptance Criteria

### C1: data, API, CCI management
- [ ] A church sees CCI, diocese, its region's shared events and its own; not another church's, and not a region's event that isn't shared.
- [ ] A region sees CCI, diocese and its own; with `below`, its churches' shared events too, view only.
- [ ] `layers` and `kinds` filter the result; `from` and `to` more than 400 days apart is refused.
- [ ] A weekly event until a date gives one occurrence per week in range; a yearly event repeats each year; a 3-day event keeps 3 days in every occurrence; monthly on the 31st falls on the last day of shorter months.
- [ ] A Senior Pastor adds, edits and deletes their church's event; a Church Treasurer (read only) gets 403; nobody edits another place's event (403).
- [ ] Only a global admin creates, edits or deletes a CCI event; a Bishop gets 403.
- [ ] Validation: the end isn't before the start; times are needed when not all day; `repeat_until` is required for weekly and monthly.
- [ ] CCI import: a CSV or XLSX with good, bad and duplicate rows returns each row's errors without saving; `commit=1` saves only the valid, non-duplicate rows; importing the same file twice creates nothing the second time.
- [ ] `CalendarAccessSeeder` is idempotent: a Calendar page per level, grants as listed, the dead "Diocese Calendar" rows switched off.

### C2: the calendar page
- [ ] Each level's page shows the KPI cards, the layer and kind filters and the calendar. CCI events carry the CCI badge and colour.
- [ ] Add event, edit and delete happen in place (no reload), and the calendar refreshes.
- [ ] The diocese page shows the CCI national calendar tab to global admins only, with import and a template.
- [ ] No page errors; the phone width works.
