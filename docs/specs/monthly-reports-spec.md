# Monthly Reports Spec

Part of the **Church life** plan (2026-10-05), L4. Each church writes a short report every month and sends it to its region; each region writes its own and sends it to the diocese. The figures are **filled in for you** from what is already recorded (Demographics, Attendance, Budgets, Events and Initiatives); the pastor adds what only they know. Those above **see and comment**. Nothing is approved or sent back (decided with the owner).

**Status:** planned 2026-10-05.
- **L4a:** data, API, figures, reminders, reports, permissions and menu (this PR).
- **L4b:** the pages.

## Principles
- **Write your own, read the places below, never upwards or sideways.** The acting role (`X-Assignment-Id`) decides the place, as in Budgets and Events (`PlaceAccess`).
- **Figures are never typed twice.** While a report is a draft, its figures are worked out live. Sending **freezes** them into the report, so a later correction elsewhere doesn't change what was sent.
- **Plain words, no control.** "Sent", "Seen", "Comment". No approval, no "rejected", no "owed".
- **Due on a day the diocese sets:** the 5th of the next month by default (`reports.monthly_due_day`). A report not sent by the end of that day is **late**.

## Data Model

### `monthly_reports`
| Column | Notes |
|---|---|
| id, uid | |
| territory_id | The reporting place (a church or a region); unique with year and month |
| year, month | The month reported on |
| status | `draft`, `sent`, `seen` |
| figures | JSON, null while a draft (worked out live); frozen on send |
| achievements, challenges, prayer_requests, support_needed, testimonies, next_month | Text, nullable: "in the pastor's words" |
| pastoral_visits | int, nullable |
| outreach | text, nullable |
| sent_at, sent_by, seen_at, seen_by | |
| created_by, updated_by, timestamps | Audited |

**Attachments:** media collection `attachments` (private `local` disk), at most 5 files: JPEG, PNG, WebP or PDF, each up to 5 MB.

### `monthly_report_comments`
`monthly_report_id`, `user_id`, `territory_id` (the commenter's acting place), `body` (≤ 2000), timestamps. A thread between the place and those above it.

### Setting
`reports.monthly_due_day` (diocese, 1–28, default 5), in a new **Monthly reports** section of the diocese's Settings.

## Figures (`App\Services\Reports\MonthlyFigures`)
Worked out for a place and a month, reusing the existing data:
- **People** (church): the latest Demographics submission for the month or before: members (and the change from the one before), and for that exact month the new members, baptisms, conversions and communion. If the church records half-yearly, it says which period the numbers are from.
- **Attendance** (church): Sundays recorded, the average Sunday, and other gatherings held (`AttendanceData`, one month).
- **Money:**
  - income, expenses and what's left for the month (`BudgetData::dashboard()['totals']`);
  - the diocese share for the month: due, sent and still to send (`BudgetRollup::contributionsOf`). It is never worked out again.
- **Events and initiatives:**
  - our events held that month, with how many came;
  - events we took part in, with our numbers;
  - our initiative sessions held, with attendance.
- **Our churches** (a region's own report): reports sent, seen and late out of the churches; their Sunday attendance added up; and income and expenses across them (`BudgetRollup::summary`).

Each block says when nothing was recorded ("No attendance recorded for May"), so the page can link to where to record it.

## API Contract
All routes are under `auth:sanctum` and the acting role. Responses are `{success, status, message, data}`.

| Method | Path | Notes | Permission |
|---|---|---|---|
| GET | `/monthly-reports?year=` | The 12 months: status (`sent`, `seen`, `draft`, `not_started`, `late`), due date, comments, plus the year's figures (sent on time, late, the current month's due-in days) | `{L}.reports.monthly.read` |
| GET | `/monthly-reports/{year}/{month}` | The report (or a blank one), live figures while a draft, comments, attachments, `can` | read |
| PUT | `/monthly-reports/{year}/{month}` | Save the draft: the words, `pastoral_visits`, `outreach`. A sent report can't be changed | `.monthly.write` |
| POST | `/monthly-reports/{year}/{month}/send` | Freezes the figures, status `sent`, tells the place above. Not before the month has started | `.monthly.send` |
| POST | `/monthly-reports/{year}/{month}/reopen` | Back to a draft, while it is sent but not yet seen | `.monthly.send` |
| GET | `/monthly-reports/{id}` | One report by id: the place's own, or one below | read (own) / `.below.read` |
| POST | `/monthly-reports/{id}/attachments` | `file` | write (own, draft) |
| DELETE | `/monthly-reports/{id}/attachments/{media}` | | write (own, draft) |
| GET | `/monthly-reports/{id}/attachments/{media}` | Streams the file | read, as for the report |
| POST | `/monthly-reports/{id}/comments` | `body`; tells the other side | write (own) / `.below.review` |
| POST | `/monthly-reports/{id}/seen` | `sent` → `seen`; tells the place | `.below.review` |
| GET | `/monthly-reports/below?year=&month=` | One row per place below that reports (churches; at the diocese, regions and churches), with status, sent date, key figures and comments, plus counts and "What we noticed" | `.below.read` |

Any other place gets 404 (it can't tell the report exists), as with Events.

**Reports (PDF/Excel):**
- `monthly.report`: one report (`report_id`);
- `monthly.status`: who sent and who's late for a month, grouped by region (`year`, `month`).

## Reminders
`reports:remind`, run daily (`routes/console.php`) for last month's report of every church and region that hasn't sent it:
- **3 days before** the due day, **on** it, and **3 days after** (late);
- an in-app notification (kind `reminder`) to the people at the place with `{L}.reports.monthly.write`;
- an SMS through the place's own or the diocese's sender (`PlaceMessenger::sms(..., kind: 'report_reminder')`) to those with `.monthly.send` who have a phone, so it shows in the Settings message log;
- each reminder is sent once per place and stage, even if the command runs twice.

## Notifications
- **Sent:** the leaders above with `.below.review`.
- **Seen:** the place's leaders with `.monthly.write`.
- **Comment:** the other side.

## Calendar
The calendar's due dates (C3) gain "{Month}'s report due" on the due day, red once late, for a church and a region (with `.monthly.read`). Clicking it opens the report.

## Permission Rules
Per level (`church`, `region`, `diocese`):
- `{L}.reports.monthly.read`, `.monthly.write`, `.monthly.send` (church and region: they report);
- `{L}.reports.below.read`, `.below.review` (region and diocese: they see the reports below).

| Grant | Who |
|---|---|
| Everything | Senior Pastor, Regional Overseer, Bishop |
| read, write, send (+ below at region/diocese) | Associate Pastor, Church Secretary, Church Administrator, Regional Secretary, Regional Coordinator, Diocese Secretary, Diocese Administrator |
| read (+ below.read at region/diocese) | Treasurers, Committee and Council members, Elders, Deacons |

**Menu** (`MonthlyReportsAccessSeeder`, DatabaseSeeder phase 32): a **Monthly reports** page per level at `/{L}/monthly-reports/`.
- church: reuses the placeholder "Church Reports" (M37);
- region: reuses "Regional Reporting" (M25);
- diocese: a new module in `diocese-overview`.

The placeholders' unbuilt sub-pages are switched off.

## Acceptance Criteria

### L4a: backend
- [ ] The figures come from Demographics, Attendance, Budgets, Events and Initiatives for the month; a draft shows them live; sending freezes them.
- [ ] A church saves its draft, sends it, and can reopen it until it's seen; a sent report can't be edited; nobody else can write it.
- [ ] The region sees its churches' reports (not another region's), marks one seen and comments; the church is told; the diocese sees regions and churches.
- [ ] The 12-month list gives status and late against the due day from Settings.
- [ ] Attachments: up to 5, the right types, streamed only to those who can read the report.
- [ ] `reports:remind` sends on the right days, once each, in the app and by SMS (logged).
- [ ] The calendar shows the report's due date.
- [ ] `monthly.report` and `monthly.status` build; the seeder is idempotent and reuses the placeholders.

### L4b: pages
- [ ] Church and region: Monthly reports (the year at a glance, KPI cards, comments), Write (stepper with the live figures and Send), and the report page.
- [ ] Region and diocese: the reports below for a month, with tiles that filter, Mark as seen and Comment.
- [ ] No console errors; light and dark; 390 px.
