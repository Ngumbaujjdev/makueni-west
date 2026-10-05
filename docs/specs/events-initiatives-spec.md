# Events and Initiatives Spec

Part of the **Church life** plan (2026-10-05): Events, Initiatives, Calendar additions, Monthly reports and Messages for church, region and diocese.

**What they are:**
- An **event** is a gathering on a date: a convention, revival, kesha, fundraiser or wedding.
- An **initiative** is a programme that runs over time, with sessions: a Bible study, a pastors' training, a youth programme.

Both use one model, so the pages are shared.

**Status:** planned 2026-10-05; L1a (the foundation), L1b (the Events backend) and L1c (the Events pages) done; L2 (Initiatives) in progress.
- **L1a:** the shared foundation: place access, in-app notifications, and the header bell.
- **L1b:** the Events backend.
- **L1c:** the Events pages.
- **L2:** Initiatives.

**Decided with the owner (2026-10-05):**
- A church registers **how many are coming** (youth, adults, children, leaders), with an optional names note and the fee. After the event it records how many came.
- No member list is stored ("counts, not people").
- The region and diocese **see and comment**; nothing is approved.
- Subregions are a grouping, not a level: a filter and a group heading.

## Principles
- **Write to your own place, view the places below, never upwards or sideways.** These are the rules Budgets uses, through the same acting role (`X-Assignment-Id`).
- **Invitations come down.** A published event from above that is open to a place shows under its **Invitations**. The place registers counts; it never edits the event.
- **Counts, not people.** A registration holds numbers and an optional free-text names note.
- **Money stays in Budgets.** Event income and spending are ordinary budget entries tagged with the event, so they still come off a budget line.
- **Attendance stays in Attendance.** A church recording attendance at its own event writes a Special Event attendance record tagged with the event, so it shows in the Attendance analytics.

## Shared foundation (L1a)
- **`App\Support\PlaceAccess`** is the acting-role logic generalised from `BudgetAccess`:
  - `assignment`, `place`, `has(user, permission)`;
  - `isOwn`, `isBelow`, `canSee`;
  - `can(user, module ability)`, through a per-module ability map.
- Each module has a small `XxxAccess` holding its ability map, e.g. `EventsAccess::ABILITIES`.
- `BudgetAccess` keeps working unchanged. It is not rewritten in this round, so the budget tests and the Budgets session are untouched.
- **In-app notifications:**
  - Laravel's `notifications` table, holding `App\Notifications\*` on the database channel.
  - Each notification's `data` is `{kind: invitation|registration|report|message|reminder, title, body, url, icon, colour, place}`.
  - API (any signed-in user, own notifications only):
    - `GET /notifications?unread=&kind=` (paged, plus the unread count);
    - `POST /notifications/{id}/read`;
    - `POST /notifications/read-all`.
- **The header bell becomes real** (`assets/js/utils/notifications.js`, loaded by `includes/header.php`):
  - an unread count badge, the latest 8, "Mark all read", and a link to all;
  - it polls every 60 s while the tab is visible.
- **The Notifications page** (`/notifications`, every signed-in user):
  - KPI cards: unread, this week, invitations and reports;
  - filter by kind and read/unread;
  - each item opens what it's about and is marked read.

## Data Model (L1b)

### `activities`
| Column | Notes |
|---|---|
| id, uid | |
| kind | `event` (L1) or `initiative` (L2) |
| territory_id | The owner place: church, region or diocese |
| title, description | |
| type | Per owner level (see below) |
| audience | `everyone`, `youth`, `women`, `men`, `children`, `leaders`, `pastors` |
| starts_at, ends_at | datetime; `ends_at >= starts_at` |
| venue, capacity, coordinator, speakers, agenda | Optional |
| open_to | church: `own` or `region` (the other churches of its region). Region and diocese: `below` (every place below) or `selected` |
| registration | bool: places below register counts |
| register_by | date, nullable |
| fee_per_person | decimal, nullable |
| planned_income, planned_spend | decimal, nullable |
| status | `draft`, `published`, `completed`, `cancelled` |
| report_back | text, "how it went", after completion |
| created_by, updated_by, published_at, timestamps, soft deletes | Audited |

**Types:**
- **Church:** Sunday service, midweek, prayer meeting, youth kesha, revival, wedding, funeral, fundraising, special service, other.
- **Region and diocese:** conference, leadership meeting, revival, youth convention, women's, men's, prayer conference, worship night, outreach, training, fundraising, celebration, other.

### `activity_invitees` (only when `open_to = selected`)
`activity_id` and `territory_id` (a region or a church). Choosing a region includes all of its churches.

### `activity_registrations`
| Column | Notes |
|---|---|
| activity_id, territory_id | The registering place; unique together |
| youth, adults, children, leaders | Expected counts (ints ≥ 0) |
| names | Optional text |
| fee_due | `(youth + adults + children + leaders) × fee_per_person`, kept up to date |
| fee_paid | Decimal; the **organiser** records it |
| came_youth, came_adults, came_children, came_leaders | After the event; nullable |
| rating (1–5), comment | After the event; nullable |
| status | `registered` or `withdrawn` |
| registered_by, updated_by, timestamps | |

### Ties to what exists
- **`church_attendance_records.activity_id`** (nullable FK). It's set when a church records attendance at its own event from the event page, through the existing attendance form preset with the event's date and name.
- **`budget_entries.activity_id`** (nullable FK). **Record income / Record an expense** on the event page opens the usual Record window with the event set. The event's **Money** tab sums those entries against `planned_income` and `planned_spend`.

## Visibility
A place **P** sees:
- its **own** activities (any status);
- **invitations**: published activities owned by an ancestor of P where one of these holds:
  - `open_to = below`;
  - `open_to = selected` and P, or one of P's ancestors below the owner, is an invitee;
  - P is a church, and the owner is a church in the same region with `open_to = region`. This is the one sideways case, chosen by the organising church.
- **below** (region and diocese, with `events.below.read`): published, completed and cancelled activities owned by places under P, view only.

## API Contract (L1b)
All routes are under `auth:sanctum` and the acting role. Responses are `{success, status, message, data}`.

| Method | Path | Notes | Permission |
|---|---|---|---|
| GET | `/activities?kind=event&year=&scope=own\|invited\|below` | List, with counts (registrations, expected, came, money) | `{L}.events.events.read` (`below` scope needs `events.below.read`) |
| GET | `/activities/overview?kind=event&year=` | KPI figures: upcoming, people expected, came this year, money raised, by month | read |
| GET | `/activities/{id}` | Detail, my registration (if invited), `can` flags | read, and visible |
| POST | `/activities` | Create a draft for the acting place | `events.events.manage` |
| PUT | `/activities/{id}` | Own place only; not when completed or cancelled | manage |
| POST | `/activities/{id}/publish` | Notifies the invited places' leaders (holders of `events.events.register`) | manage |
| POST | `/activities/{id}/complete` | Body `report_back` | manage |
| POST | `/activities/{id}/cancel` | Notifies the registered places | manage |
| GET | `/activities/{id}/registrations` | The owner sees all registrations, grouped by region, with totals | manage or read on own |
| POST | `/activities/{id}/register` | The acting place registers counts (invited, published, before `register_by`) | `events.events.register` |
| PUT | `/registrations/{id}` | The registering place changes counts or came/rating; the organiser sets `fee_paid` | register (own) / manage (organiser) |
| POST | `/registrations/{id}/withdraw` | | register |
| GET | `/activities/{id}/money` | Entries tagged with the event, with totals | read on own |
| GET | `/activities/{id}/history` | Audit sentences in the budget-log style | read on own |

**Reports:**
- `activity.summary`: one event, covering who's coming, came, money and how it went;
- `activity.year`: the year's events, with places taking part.

Scopes are church, region and diocese; the param is `activity_id`, added to both report whitelists.

## Permission Rules
Per level `{L}` (church, region, diocese):
- `{L}.events.events.read`, `{L}.events.events.manage`, `{L}.events.events.register`, `{L}.events.below.read` (region and diocese).

| Grant | Who |
|---|---|
| Everything | Senior Pastor, Regional Overseer, Bishop |
| read, manage, register | Associate Pastor, Church Secretary, Church Administrator, Regional Secretary, Regional Coordinator, Diocese Secretary, Diocese Administrator |
| read, register | Youth Pastor, Youth Leader, the ministry leaders |
| read | Treasurers, Committee members, Council members, Elders, Deacons |
| below.read | The region and diocese roles above with read |

Menu (`ActivitiesAccessSeeder`, DatabaseSeeder phase 31):
- An **Events** page per level in `{L}-programs`:
  - church `/church/events/`
  - region `/region/events/`
  - diocese `/diocese/events/`
- It reuses the placeholder modules: diocese "Diocese Events Management" (M14) and region "Regional Programs" (M22). Each is renamed **Events**, re-pointed, and its unbuilt sub-pages are switched off.
- A church Events module is created in `church-programs`.

### L1b as built
- **Code:**
  - `App\Services\Activities\Activities` holds `invitations()`, `below()`, `relation()`, `reach()`, `leadersWith()`, the `notify*` methods, `totals()`, `money()` and `overview()`;
  - `ActivitiesController`;
  - `EventsAccess` (the ability map over `PlaceAccess`);
  - `ActivitiesAccessSeeder` (phase 31).
- **A place it doesn't reach gets 404, not 403**, so it can't tell the event exists.
- **Registering** is `updateOrCreate` on (activity, place): a second registration changes the first. `fee_due` follows the counts and the event's fee; changing the fee updates every registration.
- **Notifications:**
  - publishing tells the leaders holding `events.events.register` at the places it reaches;
  - a registration or a change of numbers tells the organiser's managers;
  - cancelling a published event tells the places that registered.
- **Ties:**
  - `POST /attendance` accepts `activity_id`, which must be the church's own (not cancelled) event;
  - `POST /budget-entries` accepts `activity_id`, which must be an event of the budget's place;
  - `/reports` accepts `activity_id` for `activity.summary`: the organiser's own event, or one of a place below.

## Pages (L1c)
- **Events** (`/{L}/events/`):
  - **Toolbar:** year buttons, **New event**, Export.
  - **Section tabs with live figures:** **Ours** · **Invitations (n new)** · **Places below** (the last one for region and diocese).
  - **KPI cards:** upcoming · people expected · came this year · money raised (deltas and sparklines).
  - **Hero:** "Events this year" by month, coloured by type.
  - **The list:** event cards with a coloured date block, type chip, status pill, and "312 coming from 24 churches". It has a filter bar.
- **New / edit event:** a stepper with a live preview card:
  1. What and when
  2. Where and who
  3. Registration and money
  4. Check and publish
- **Event page** (`/{L}/events/event?id=`):
  - **Header:** date block, status, open-to chips.
  - **Actions:** Publish, Complete, Cancel, Record money, Export.
  - **Tabs:**
    - **Details**;
    - **Who's coming**: per place grouped by region, with expected, fees and came, a totals strip and a donut;
    - **Attendance** (church events);
    - **Money**;
    - **How it went**: report-back, plus each place's rating and comment;
    - **History**.
  - **For an invited place:** a **Register** card with number steppers, names and the fee worked out. Afterwards, "How many came" and a rating.

### L1c as built
- **Files:** shared bodies in `includes/events/` (`context.php`, `page.php`, `body-{list,form,event}.php`) with thin wrappers `{church,region,diocese}/events/{index,new,event}.php`; scripts in `assets/js/pages/events/` (`api.js`, `ui.js`, `list.js`, `form.js`, `event.js`); styles in the Events block at the end of `styles.css`.
- **List:** KPI cards carry a plain sub-line rather than a delta (the overview has no last-year figures yet). The hero is events by month in one colour; with none it shows an empty state instead of a blank chart. The list splits into "Coming up" and "Already happened"; search, status and kind filter in place and stay in the URL with the year and tab.
- **Form:** the Budget form's stepper (`.intake-*`) with a "How places will see it" preview. Dates are typed as local time and sent as a UTC instant (the API stores UTC). Registration and fee only show when the event reaches other places.
- **Event page:**
  - Attendance is a **Record attendance** button (church, once it has started) that opens the Attendance window tagged with the event, not a tab.
  - **Record money** opens the Budgets window tagged with the event, on the organiser's budget in use **on the event's day, else today's** (`money.budget_in_use`). With neither, the Money tab says to start a budget.
  - An invited place sees Details plus a Register card (first on a phone), then "How many came" with a 1-5 rating once it has started. The places above see Details and Who's coming.
  - The organiser records a fee paid per place from Who's coming.
- **Export** passes `activity_id` through `report-center.js` (`data-activity-id`).

## Initiatives (L2)
An initiative is an `activities` row with `kind = initiative`. It uses the same visibility, open-to rules, registrations, money and history as an event; what differs is below.

### Data
- **`activities`** gains:
  - `frequency`: `once`, `weekly`, `fortnightly`, `monthly` or `quarterly` (initiatives only; null for events);
  - `meeting_day` (0 = Sunday ... 6 = Saturday, for weekly and fortnightly), `meeting_time` (time);
  - `certificate` (bool): those who finish get a certificate or acknowledgement.
  - `starts_at` / `ends_at` are the first and last day of the programme. `coordinator` is shown as **Facilitator** and `capacity` as **Places for (people)**.
- **`activity_sessions`:** `activity_id`, `number`, `held_on` (date), `topic`, `status` (`planned`, `held`, `cancelled`), `youth`, `adults`, `children`, `leaders` (attendance, nullable until held), `notes`, `updated_by`, timestamps. Audited.
- **`activity_registrations`** gains `completed` (int, nullable): how many from that place finished, recorded by the organiser at the end.

**Types (initiatives):**
- **Church:** Bible study, discipleship class, prayer group, youth programme, children's programme, women's fellowship, men's fellowship, outreach, training, welfare, other.
- **Region and diocese:** pastors' training, leadership training, discipleship, Bible study, youth programme, women's programme, men's programme, evangelism, outreach, welfare, other.

### Sessions
- **Generated** when an initiative is created, and again when its dates, frequency or meeting day change:
  - `once`: one session on the start day;
  - `weekly` / `fortnightly`: every 7 / 14 days from the first meeting day on or after the start;
  - `monthly` / `quarterly`: the start's day of the month, every 1 / 3 months (the last day when a month is shorter).
  - At most 104 sessions. Regenerating keeps every session that is held or has attendance, and replaces only the untouched planned ones.
- **Editable** by the organiser: topic, date, notes, status, and attendance (youth, adults, children, leaders). Saving attendance marks the session held. A session can be added, and an untouched planned one removed.

### API (L2)
The L1b routes take `kind=initiative`. The permission checks follow the activity's kind (`initiatives.*` for initiatives). Added:

| Method | Path | Notes | Permission |
|---|---|---|---|
| GET | `/activities/{id}/sessions` | Sessions in date order, with totals | read, and visible |
| POST | `/activities/{id}/sessions` | Add one: `held_on`, `topic` | manage (own) |
| PUT | `/sessions/{id}` | `topic`, `held_on`, `status`, `notes`, attendance counts | manage (own) |
| DELETE | `/sessions/{id}` | Only a planned session without attendance | manage (own) |

`PUT /registrations/{id}` also takes `completed` from the organiser. For an initiative, places can join until `register_by`, or else until its last day.

**Overview** (`kind=initiative`): active (published, not ended), places taking part, sessions held, attendance rate (attendance at held sessions against the people expected per session; the average per session when nobody registered), by month.

**Reports:** `activity.summary` covers an initiative too: its sessions, attendance per session, attendance rate and how many finished.

### Permissions and menu (L2)
- Per level: `{L}.initiatives.initiatives.read`, `.manage`, `.register`, and `{L}.initiatives.below.read` (region and diocese). The grants are the same as for Events.
- `ActivitiesAccessSeeder` seeds both modules. **Initiatives** is a page per level in `{L}-programs` at `/{L}/initiatives/`. The diocese reuses the placeholder "Diocese Initiatives Management" (M13), and its unbuilt sub-pages are switched off. Region and church get a new module.

### Pages (L2)
The Events pages, shared, with the kind set by the wrapper (`{L}/initiatives/{index,new,initiative}.php`):
- **List:** KPI cards (active, places taking part, sessions held, attendance rate), the same tabs, and cards with a progress bar of sessions held.
- **Form:** step 1 adds how often it meets (frequency, meeting day and time) and the certificate switch, and the preview lists the first sessions.
- **Initiative page:** tabs **Details**, **Sessions** (a timeline; record attendance in place), **Taking part** (as Who's coming, plus "finished"), **Progress** (attendance per session chart), **Money**, **How it went** and **History**.

## Acceptance Criteria

### L1a: foundation
- [x] `PlaceAccess` gives the same answers as `BudgetAccess` for the acting place, own, below and global admin.
- [x] Notifications: a user lists only their own; unread count; mark one read; mark all read; another user's notification gets 404.
- [x] The bell shows the unread count and the latest items. The Notifications page filters by kind and read state.

### L1b: Events backend
- [x] A church pastor creates and publishes an event. A Treasurer (read only) can't create one (403).
- [x] A diocese event `open_to = below` is an invitation to every region and church. `selected` with region A reaches A's churches, not B's.
- [x] A church event `open_to = region` reaches the other churches of its region only.
- [x] Publishing notifies the leaders with `events.events.register` at the invited places (database notification with the event's URL).
- [x] An invited church registers counts. `fee_due` is worked out. A second registration updates the first. After `register_by`, registering is refused. A church that isn't invited gets 403.
- [x] The organiser sees registrations grouped by region with totals, and records `fee_paid`. A registering church can't set `fee_paid`.
- [x] Nobody edits another place's event. A church can't see a region's draft.
- [x] Attendance recorded from the event page carries `activity_id`. Budget entries tagged with the event are summed on its Money tab.
- [x] `activity.summary` and `activity.year` build for each scope.
- [x] `ActivitiesAccessSeeder` is idempotent: pages and grants as listed, and the old placeholder sub-pages switched off.

### L1c: Events pages
- [x] Each level's Events page shows the tabs, KPI cards, hero and list. The stepper creates and publishes an event. An invited church registers from the event page. The organiser sees it under Who's coming.
- [x] No console errors. Light and dark. 390 px works.

### L2: Initiatives
- [ ] An initiative's sessions are generated from its frequency (once, weekly, fortnightly, monthly, quarterly) between its start and end, at most 104; changing the schedule keeps held sessions and replaces untouched planned ones.
- [ ] The organiser edits a session, records attendance (it becomes held), adds one, and removes an untouched planned one; nobody else can.
- [ ] Initiative permissions are `initiatives.*`: an events-only role can't create an initiative, and an initiatives-only role can't create an event.
- [ ] Places below join with counts until `register_by` or the last day; the organiser records how many finished.
- [ ] The overview gives active, places taking part, sessions held and attendance rate. `activity.summary` builds for an initiative.
- [ ] The seeder adds an Initiatives page per level, reuses "Diocese Initiatives Management", and stays idempotent.
- [ ] Each level's Initiatives pages work end to end; no console errors; light and dark; 390 px.
