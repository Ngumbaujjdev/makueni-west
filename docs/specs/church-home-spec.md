# Church home dashboard - spec

Built 2026-10-13. Page: `church/dashboard/index.php`, script `assets/js/pages/home/church.js`, styles: the `.home-*` block at the end of `assets/css/styles.css`.

## Why

The church's home page was still the YNEX "Ecommerce" demo. The user asked for one simple page that shows every side of the church (money, people, attendance, care and what's coming), built from **the same template cards**: nothing removed, only added.

## Data model

None. No backend change: every card reads an endpoint its module already has.

## Cards (template card → what it shows → source)

| Template card | Shows | Endpoint |
|---|---|---|
| 4 KPI cards | Given this month · Spent this month (vs last month) · Last Sunday (vs the Sunday before) · Members (joined this month) | `accounting/overview` · `attendance-reports/analytics?territory_id&from&to` · `people/overview` |
| Sale card | The next event or initiative: title, venue, dates, date badge, days to go; Open / Calendar | `activities?kind=event\|initiative&scope=own` |
| Recent Orders | Latest money: the last 6 documents, money in green (+) and out red (-) | `accounting/overview` → `latest` |
| Orders tabs | Needs attention: Waiting for me · Visitors to follow up · Care (in hospital first, then next steps and people not contacted) | `approvals?tab=waiting` · `visitors/overview` → `due` · `care/overview` + `care/hospital` |
| Earnings | In this year · Out this year · Held today, with the biggest income and spending shares; money in by month (this month solid); staff pay for the year | `accounting/overview` · `hr/dashboard` |
| Top Selling Products | Budget this year: the 5 biggest lines with On track / Behind / Over / Not planned | `budgets/dashboard` |
| Top Countries | Who comes: the Sunday average, then the Sunday service and the 4 biggest gatherings | `attendance-reports/analytics` |
| Top Customers | This week: services, events, bookings and dues from today to Sunday | `calendar/events?from&to` |
| Products Overview | Monthly reports this year, with working search, Sort By and pages (6 a page) | `monthly-reports?year` |

**Added:**
- **Quick action buttons** under the title. Each shows only to a role that can do it: Write a receipt, Record attendance, Add member, Record visitors, Write our report.
- **Get your church started** (round 2). Shown only while a step isn't done: books, members, a Sunday recorded, this year's budget, service times, an event. There's a progress bar; each step has a solid button for a role that can do it, or says who does it.
- **Recent activity** (round 2). My Profile's timeline (`crm-recent-activity budget-timeline`), built from what the cards already loaded:
  - up to 3 money items;
  - Sundays recorded;
  - reports sent and seen;
  - first-time visitors;
  - hospital admissions;
  - events held.

  It shows the newest 6, grouped by day.

**Round 3: the period filter (2026-10-13).** A bar on top, after the Kusoya Help Desk header:
- a live clock;
- the selected period and its duration;
- a date-range field (flatpickr, ✕ back to the default), plus Quick picks: This week, This month, Last month, This quarter, This year, Last 12 months.

**Default: This month.** The period is kept in the URL (`?from&to`).

The period changes:
- **Given / Spent:** `accounting/overview?from&to` returns `period` and `previous`, the same length just before. `period.latest` holds the period's documents.
- **Sunday average:** from attendance analytics for the period's months.
- **Members joined:** from the monthly `joins` series.
- **Who comes.**
- **The chart:** months inside the period are solid.
- **The budget and reports year.**
- **Recent activity.**

Next event, Latest money, Needs attention and This week stay live and carry a "Now" pill.

Also added in round 3:
- **Recent activity redesign:** a date block per day (today and yesterday in teal), icons on a dashed rail, amounts on the right, time or kind chips, and All / Money / People / Reports pills.
- **Who comes:** a "Who sits in the pews" bar and the last 8 Sundays.
- **This week:** a Mon-Sun strip that filters by day, and up to 8 items.

**Round 2 rules (2026-10-13):**
- **Pills:** no pale pills on this page; every pill is solid.
- **Tabs:** a hovered tab turns teal with white text.
- **Equal heights:** cards side by side stretch to the same height, so the two columns end level.
- **Empty cards:** they offer a solid button, not a text link.
- **Monthly reports table:** a strip of four figures (Sent, On time, Late, Comments) and what's next, a status-coloured month tile, due dates with "in N days" or "N days late", and solid status pills.

## Permission rules

- The page opens with `church.churchdashboard.dashboardoverview.read`, as before.
- Each card's endpoint applies its own module's rules. A 403 shows a calm "Not part of your role - …" line in that card; any other failure shows "Couldn't load this just now".
- The staff line is only requested when the role has a Staff permission (`church.hr.staff.read|manage`, `hr.dashboard.read`, `hr.setup.manage`).

## Acceptance criteria

- Every template card is still on the page, in the same columns.
- For a church with data (CCI Sultan Hamud), every card shows real figures that match the module pages.
- An empty church (CCI Upete) gets one plain line per card, with its action ("Your books aren't set up yet · Start here", "Record attendance", "Add the first member"). There are no zero charts.
- A role missing a module (e.g. the Associate Pastor without Staff) never sees an error.
- At 390 px wide there is no sideways page scroll.
