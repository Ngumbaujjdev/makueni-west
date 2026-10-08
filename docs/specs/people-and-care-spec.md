# People & Care Spec

**Status:** Planned (2026-10-08). Built in phases P0–P5.

This spec covers five church modules:

| Phase | Module | What it is |
|---|---|---|
| P0 | Foundation | Shared access, menu, settings group and clean-up |
| P1 | Members | The church's private member register |
| P2 | Visitors | Visitors and their follow-up |
| P3 | Pastoral care | Visits, counselling, hospital, prayer and concerns |
| P4 | Ministries | Youth, women, men, children, music, prayer and the church's own |
| P5 | Facilities | Rooms and bookings, equipment, repairs and duty rotas |

The modules replace the empty placeholder pages under `church/{member-management,visitor-management,pastoral-care,ministry-coordination,facility-management}/` and their old, switched-off menu rows (`ChurchSystemSeeder`).

## Decisions (user, 2026-10-08)

1. **Church-private.**
   - A church keeps names and phone numbers for the people it cares for.
   - Only that church's own leaders, with the module permission, ever read them.
   - Regions and the diocese see **totals only**. This follows the original Final Documentation: "Diocese/Region/Subregion NEVER sees individual member names - only aggregated statistics shared upward".
2. **A private member register is built.**
   - This replaces the ROADMAP rule "no individual member/congregant registry" (`docs/ROADMAP.md`).
   - **Demographics stays as it is:** monthly counts the church types in. The register only *suggests* numbers to it.
3. **Who uses it:** churches record. Regions and the diocese get read-only totals pages for Members, Visitors, Pastoral care and Ministries. Facilities stay a church matter.
4. **Order:** each phase ships as a backend PR and a frontend PR, merged before the next phase starts.

## Privacy rules (all phases)

- **No names upward.**
  - Every named endpoint answers only for the acting role's **own church** (`PlaceAccess::place()` with `PlaceAccess::isOwn`). A region or diocese role, or a global admin naming a church, gets 403 there.
  - Each module has a separate `.../totals` endpoint for region and diocese. It returns counts only, and no `name`, `phone`, `email`, `note` or `address` key appears anywhere in its JSON.
  - **Exception:** global admins (`hasGlobalAccess()`) may read a church's named data only with `?territory_id=` **and** the `{level}.members.members.read` permission, for support. Every such read is audited.
- **Encrypted at rest** (Laravel `encrypted` cast): `national_id`, `address`, `notes`, care `note`, care contact `note`, `next_of_kin_phone`.
- **Phone numbers** are stored normalised (`App\Support\Phone::kenyaMobile`), so the church can text and find duplicates. They are not encrypted.
- **Confidential care.**
  - A care record marked `confidential`, and every `counselling` record, shows its note only to its author and anyone holding `church.pastoral.care.confidential` (Senior Pastor by default).
  - Everyone else gets `note: null` and `confidential: true`.
- **Consent.** A visitor's `consent_contact` must be true before any SMS button sends to them; the API refuses with 422 otherwise.
- **Export** needs `church.members.members.export`. It is audited (`people.export`) and is never available above the church.
- **Leaving the register:**
  - *Archive* hides a person from lists. It is reversible.
  - *Remove personal details* (anonymise) clears names, phones, email, address, ID, notes, photo and next of kin, and sets `first_name` to "Removed" and `last_name` to "person".
    - The row stays, so counts and history stay true.
    - It can't be undone.
    - It needs `members.manage`.
- **Retention.** `people.retention_visitor_months` (Settings → Visitors, default 0 = never). A daily `people:retention` command anonymises visitors with no visit in that many months.
- **Auditing.** Every model is `Auditable`. Each detail page has a History tab drawn from the audits; secrets and encrypted fields show as "changed", never the value.

---

## P0 - Foundation

### Data / code

- **`App\Support\PeopleAccess`.** One ability map per module, in the `ActivityAccess` shape:
  - `members`: `read` `members.members.read`, `manage` `members.members.manage`, `export` `members.members.export`, `below` `members.below.read`
  - `visitors`: `read` `visitors.visitors.read`, `manage` `visitors.visitors.manage`, `below` `visitors.below.read`
  - `pastoral`: `read` `pastoral.care.read`, `manage` `pastoral.care.manage`, `confidential` `pastoral.care.confidential`, `below` `pastoral.below.read`
  - `ministries`: `read` `ministries.ministries.read`, `manage` `ministries.ministries.manage`, `below` `ministries.below.read`
  - `facilities`: `read` `facilities.facilities.read`, `manage` `facilities.facilities.manage`, `book` `facilities.facilities.book`

  It also provides `canNamed($user, $place, $module, $ability)`, which is true only for the own church (plus the global-admin support exception above), and `canTotals($user, $place, $module)`.
- **`PlaceNotification::KINDS`** gains `care`, `followup` and `booking`.
- **phpunit:** new suites `People` (`tests/Feature/People`) and `Facilities` (`tests/Feature/Facilities`), each excluded from `Feature`. Both are listed in the root and backend `CLAUDE.md`.

### Menu and permissions: `PeopleCareAccessSeeder` (idempotent, BudgetsAccessSeeder pattern)

- **Reuses the placeholder modules** under group `church-members`. Facilities go under `church-programs`. Each is renamed and its path set:

  | Old module | New name | Path |
  |---|---|---|
  | Member Management / Members (id 26) | Members | `/church/members/` |
  | Visitor Management / Visitors (35) | Visitors | `/church/visitors/` |
  | Pastoral Care (36) | Pastoral care | `/church/pastoral-care/` |
  | Ministry Coordination / Ministries (31) | Ministries | `/church/ministries/` |
  | Facility Management (33) | Facilities | `/church/facilities/` |

- **Old submodules and permissions.** Old submodules (`/members/registration` …) are deleted. Old permissions (`membermanagement.*`, `pastoralcare.*`, `visitormanagement.*`, `ministrycoordination.*`, `facilitymanagement.*`) are deleted once nothing grants them.
- **One switch per module.** The seeder has a constant `LIVE = ['members' => false, ...]`.
  - A module is `is_active` only when its phase's pages are merged; each phase flips its own flag.
  - While a flag is off, the module row stays inactive. `MuteNonDemographicsChurchModulesSeeder` keeps them muted until then.
- **Region and diocese totals pages:** module "Church care" in `{level}-overview`, with submodules Members, Visitors, Pastoral care and Ministries at `/{level}/people/{members,visitors,care,ministries}.php`. Each is activated with its phase.
- **Grants** (church):

  | Role | Members | Visitors | Pastoral | Ministries | Facilities |
  |---|---|---|---|---|---|
  | Senior Pastor | read manage export | read manage | read manage confidential | read manage | read manage book |
  | Associate Pastor | read manage | read manage | read manage | read manage | read manage book |
  | Church Administrator | read manage export | read manage | - | read manage | read manage book |
  | Church Secretary | read manage | read manage | - | read | read book |
  | Elder | read | read manage | read manage | read | read book |
  | Deacon | read | read manage | read | read | read book |
  | Youth Pastor, Youth Leader, Women's / Men's / Children's Ministry Leader | read | read | - | read (manage own ministry, P4) | read book |
  | Church Treasurer | - | - | - | read | read |
  | Church Committee Member | read | read | - | read | read |

- **Grants** (region): Regional Overseer, Regional Secretary and Regional Coordinator get `below` for members, visitors, pastoral and ministries.
- **Grants** (diocese): Bishop, Diocese Secretary and Diocese Administrator get the same.

### Settings

- A new group `people` ("People & care") in `config/settings.php`.
- Each phase adds its section (see below).
- Sections are church-level unless stated. A field marked "lockable" can be locked by the diocese.

### Clean-up

- Delete the 0-byte placeholder folders `church/{member-management,visitor-management,pastoral-care,ministry-coordination,facility-management}/`. `pastoral-care` is rebuilt in P3.
- `includes/sidebar.php` `getDashboardUrl()` sends church users to `/church/dashboard/`, not the empty member-management page.

### P0 acceptance criteria

- [ ] `PeopleAccess::canNamed` is false for a region or diocese role on a church below, and true for the church's own Senior Pastor.
- [ ] The seeder runs twice without duplicates. After it runs, no permission named `membermanagement.%` / `pastoralcare.%` / `visitormanagement.%` / `ministrycoordination.%` / `facilitymanagement.%` exists, and the five modules have the new names and paths, still inactive.
- [ ] The People and Facilities suites exist and run.

---

## P1 - Members

### Data model

**`people`.** Territory-scoped like `Activity`: a `territory_id` that is always a church. (As built, there is no `territory_type` column - the church is the only level.)

| Column | Type | Notes |
|---|---|---|
| id, territory_id | | the church |
| first_name, last_name | string(80) | required |
| other_names | string(80) null | |
| gender | enum male/female null | |
| date_of_birth | date null | |
| phone | string(20) null | normalised +2547…; index (territory_id, phone) |
| email | string(160) null | |
| address | text null, **encrypted** | |
| national_id | text null, **encrypted** | |
| marital_status | enum single/married/widowed/divorced/other null | |
| occupation | string(120) null | |
| photo_path | string null | private disk, served through the API |
| status | enum visitor/member/inactive/transferred_out/deceased | default member |
| joined_on | date null | |
| how_joined | enum conversion/transfer/baptism/birth/other null | |
| previous_church | string(160) null | |
| saved_on | date null | |
| baptised_on | date null | |
| next_of_kin_name | string(120) null | |
| next_of_kin_phone | text null, **encrypted** | |
| notes | text null, **encrypted** | |
| archived_at | timestamp null | |
| anonymised_at | timestamp null | |
| created_by, updated_by | user ids | |
| timestamps, deleted_at | | soft deletes |

Visitor columns are added in P2.

**`person_transfers`:**

| Column | Notes |
|---|---|
| id, person_id | |
| direction | in / out |
| other_church_id | null; a church in the system |
| other_church_name | null; typed |
| on | date |
| reason | text null |
| notified | bool |
| created_by, timestamps | |

**Ministries:** until P4, `people.ministries` is not stored; the profile's Ministries tab says "Ministries come soon". (P4 adds `ministry_members`.)

### API (all under `auth:sanctum`; named = own church only)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /people/overview | members.read | KPI figures (active, new this month and last, baptised share, leaving this year), a 12-month joins/leaves series, birthdays this month (count) |
| GET | /people | members.read | `status[] gender age_band ministry baptised joined_year q page per_page`. Rows: id, name, initials, phone, age, status, joined_on, ministries[] |
| GET | /people/check | members.read | `phone`, `name`. Returns possible duplicates `{id,name,phone,status}` |
| POST | /people | members.manage | create; 422 on validation; duplicate phone allowed only with `confirm_duplicate: true` |
| GET | /people/{id} | members.read | full record (decrypted), age, journey events, transfers, counts of care records (P3) |
| PUT | /people/{id} | members.manage | update |
| POST | /people/{id}/photo · DELETE | members.manage | image only, ≤2 MB, re-encoded to webp |
| GET | /people/{id}/photo | members.read | streams the photo |
| POST | /people/{id}/archive · /restore | members.manage | |
| POST | /people/{id}/anonymise | members.manage | needs `confirm: "REMOVE"` |
| GET | /people/{id}/history | members.read | audits, encrypted fields masked |
| GET | /people/transfers | members.read | `direction year` |
| POST | /people/{id}/transfer-out | members.manage | `{other_church_id?|other_church_name?, on, reason?, notify}`. Sets status transferred_out. `notify` sends a `PlaceNotification` (kind `care`) to the other church's managers with the name and phone |
| POST | /people/transfer-in | members.manage | a person body plus `{other_church_id?|other_church_name?, on}`. Creates a member with `how_joined=transfer` |
| GET | /people/insights | members.read | age/gender pyramid, by ministry (P4), joins vs leaves per month, birthdays this month (names), not contacted in N days (P3) |
| GET | /people/export | members.export | xlsx through the reports pipeline (`members.directory`) |
| GET | /people/register-counts | members.read | the register in Demographics' words (total, male, female, youth, new and baptised this month) - the hint beside the Demographics form |
| GET | /people/totals | members.below | per church below: `{church, keeps_register, active, new_this_month, transfers_in, transfers_out, baptised}`. Counts only |

**Age bands:** Children 0-12, Youth 13-35, Adults 36-59, Seniors 60+. These are the Demographics bands.

### Pages (`includes/members/*`, thin wrappers in `church/members/`)

- **`index.php`, the list:**
  - Spark cards: active members (series), new this month (change vs last month), baptised share, leaving this year.
  - Filter toolbar (`renderFilterToolbar` + `wireFilterToolbar`, kept in the URL) and an `initListDataTable` table: initials avatar, name, phone, age, ministries, status pill, joined.
  - "Private to our church" chip.
  - Buttons: + Add member, Export (with the permission).
- **`new.php`** (create and `?id=` edit), an intake stepper like Record demographics:
  - 1 Who
  - 2 Contact
  - 3 Church life
  - 4 Check
  - A live member-card preview on the right.
  - The duplicate check runs on phone blur and name.
  - The form saves without reloading and leaves to the profile.
- **`member.php?id=`, the profile:**
  - Hero: photo or initials, name, status, age, member since, ministry chips. Actions: Edit, Send SMS (Messages composer with this number), Transfer, More (Archive, Remove personal details).
  - Tabs: Overview (facts, plus the Spiritual journey timeline: saved, baptised, joined, transfers), Care (P3), Ministries (P4), History.
- **`transfers.php`:** spark cards (in and out this year), an in/out list, and Transfer in / Transfer out windows.
- **`insights.php`:** pyramid, joins vs leaves trend, birthdays this month (with a Send birthday SMS button), the register vs Demographics.
- **Region and diocese `people/members.php`:** spark cards and a per-church table from `/people/totals`. No names.
- **Demographics hint:** the entry form shows "From your register: N" beside Total members, Male/Female, Youth, New members and Baptisms, with a "Use" link. It never fills itself in.

### Settings → Members (`members`, kind form, church)

| Field | Type | Default |
|---|---|---|
| `members.require_dob` | bool | off |
| `members.require_national_id` | bool | off |
| `members.birthday_template` | text | "Happy birthday {first_name}! We thank God for you. From your family at {church}." |

`members.require_dob` and `members.require_national_id` are lockable. As built there is no automatic birthday SMS: Insights lists the month's birthdays, each with a button that opens the Messages composer with the number and this text filled in.

### P1 as built (2026-10-08)
- **Menu pages:**
  - Members (`/church/members/`, holding `read` and `export`);
  - Add member (`new.php`, `manage`);
  - Transfers (`transfers.php`) and Insights (`insights.php`), each with its own read permission (`church.members.transfers.read`, `church.members.insights.read`), granted to everyone who reads the register. The menu shows a page through its permission.
- **Off the menu:** the member page (`member.php?id=`).
- **Region and diocese:** "Church care › Members" (`/{level}/people/members.php`).
- **Export:** the `members.directory` report (PDF/Excel) through the report centre, church scope, with the export permission.
- **Messages:** the composer takes a `typed=` number (Send SMS from a member, a birthday). "Members" as a Messages audience moves to P2, together with visitor SMS.
- **Audits:** they keep the encrypted fields as "(private)" (`Person::transformAudit`). The History tab says "changed notes", never the value.
- **Tests:** `tests/Feature/People/MembersTest.php` (6) and `FoundationTest.php` (2).

### P1 acceptance criteria

- [ ] A church's Senior Pastor creates, reads, updates, archives and anonymises a person. Each step is audited, and History never shows encrypted values.
- [ ] Church B, the region and the diocese get 403 on church A's `/people`, `/people/{id}` and `/people/{id}/photo`.
- [ ] `/people/totals` for the region lists its churches' counts, and no row contains `name`, `phone`, `email`, `address` or `notes`.
- [ ] `national_id`, `address`, `notes` and `next_of_kin_phone` are not plain text in the database.
- [ ] Creating with a phone that already exists without `confirm_duplicate` gives 422 naming the existing person.
- [ ] Export without `members.export` is 403.
- [ ] Transfer out sets `transferred_out`, records the transfer, and notifies the receiving church's managers only when asked to.
- [ ] Anonymise clears every personal field and keeps the row and its transfers.
- [ ] Filters `status`, `age_band` and `q` return the right rows.

---

## P2 - Visitors

### Data model

**Additions to `people`:**

| Column | Type | Notes |
|---|---|---|
| first_visit_on | date null | |
| last_visit_on | date null | |
| visits | int | default 0 |
| how_heard | string(60) null | from the Settings options |
| consent_contact | bool | default false |
| wants_visit | bool | default false |
| stage | enum new/contacted/returning/regular/member null | visitors only |
| assigned_to | user id null | |

**`visitor_visits`:** id, person_id, gathering_type_id null, on (date), first_time (bool), created_by.

**`visitor_followups`:**

| Column | Notes |
|---|---|
| id, person_id | |
| type | call / sms / visit / met |
| outcome | reached / no_answer / will_come / not_interested / other |
| note | text null, **encrypted** |
| done_by, done_on | |
| next_on | date null |
| timestamps | |

### API

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /visitors/overview | visitors.read | this month, first-timers, % followed up within N days, became members (rate), due follow-ups for me |
| GET | /visitors | visitors.read | `stage[] how_heard assigned month q` |
| POST | /visitors/batch | visitors.manage | Sunday entry: `{on, gathering_type_id?, rows:[{name, phone?, first_time, how_heard?, wants_visit, prayer_request?, consent_contact}], welcome_sms}`. A phone that matches an existing person adds a visit instead of a new person. Answers `{created, returning, sms_sent}` |
| GET | /visitors/{id} | visitors.read | the person (visitor fields), visits, follow-ups |
| PUT | /visitors/{id} | visitors.manage | |
| POST | /visitors/{id}/stage | visitors.manage | `{stage}` |
| POST | /visitors/{id}/followups | visitors.manage | log one |
| POST | /visitors/{id}/sms | visitors.manage | `{text}`. 422 without consent; `PlaceMessenger::sms` kind `visitor`, logged |
| POST | /visitors/{id}/become-member | visitors.manage + members.manage | status member, `how_joined=conversion` by default, stage member |
| GET | /visitors/insights | visitors.read | how heard, funnel by stage, per month |
| GET | /visitors/totals | visitors.below | per church: visitors and first-timers this month, became members this year, rate |

A prayer request or "wants a visit" creates a care record (P3). Before P3, it is stored on the visit and listed on the visitor page.

### Pages (`church/visitors/`)

- **`index.php`:**
  - Spark cards.
  - A Board / List switch: the board is the YNEX `task-kanban-board` pattern with drag between stages; the list is the toolbar plus a table.
  - A "My follow-ups" strip, with overdue ones in red.
- **`new.php`:** quick Sunday entry. Pick a date and gathering, then add rows fast (Enter adds a row). A returning person shows "Returning - 3rd visit". There's a welcome-SMS switch.
- **`visitor.php?id=`:**
  - Hero: stage pill, first visit, visits, assigned. Actions: Log follow-up, Send SMS, Became a member, Assign.
  - Tabs: Follow-up (timeline), Visits, Details, History.
- **`insights.php`:** how heard (donut), funnel, per month.
- **Region and diocese `people/visitors.php`:** totals only.

### Settings → Visitors (`visitors`, form, church)

| Field | Default |
|---|---|
| `visitors.how_heard` (list, one per line) | "Invited by a friend, Walked in, Event, Radio or online, Other" |
| `visitors.followup_days` | 3 |
| `visitors.welcome_sms` | off |
| `visitors.welcome_template` | — |
| `visitors.default_assignee` | user |
| `people.retention_visitor_months` | 0; lockable |

### P2 acceptance criteria

- [ ] Batch entry creates new visitors and adds a visit to a known phone without duplicating the person.
- [ ] SMS to a visitor without consent is 422. With consent it is sent and logged with kind `visitor`.
- [ ] Became a member turns the same row into a member, and they appear in `/people`.
- [ ] The region's `/visitors/totals` has counts and no names. Church B is refused church A's visitors.
- [ ] The retention command anonymises visitors past the setting and leaves members alone.

---

## P3 - Pastoral care

### Data model

**`care_records`:**

| Column | Notes |
|---|---|
| id, territory_type, territory_id | |
| person_id | null |
| person_name | string null; when the person isn't in the register |
| type | home_visit / hospital_visit / counselling / prayer_request / phone_call / bereavement / concern |
| priority | low / normal / high |
| status | open / ongoing / closed / answered |
| on | date |
| place | string null; the hospital or place |
| ward | string null |
| admitted_on, discharged_on | date null |
| note | **encrypted** null |
| confidential | bool; always true for counselling |
| next_on | date null |
| outcome | text null, **encrypted** |
| testimony | text null; for answered prayer, only when the author ticks "may be shared" |
| created_by, timestamps, deleted_at | |

**`care_record_users`:** who went (record_id, user_id).

**`care_contacts`:** id, record_id, on, type, note (**encrypted**), by, next_on.

### API

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /care/overview | pastoral.read | visits this month and last, open cases, prayer open/answered, in hospital now, needs care (open by priority, then members not contacted in N days), by type per month, visits by leader |
| GET | /care | pastoral.read | `type[] status[] leader month q` |
| POST | /care | pastoral.manage | |
| GET | /care/{id} | pastoral.read | the note is null unless author or `confidential` |
| PUT | /care/{id} | pastoral.manage | author or confidential holder only, for confidential records |
| POST | /care/{id}/contacts | pastoral.manage | |
| POST | /care/{id}/close | pastoral.manage | `{outcome, status: closed|answered, testimony?, share_testimony}` |
| GET | /care/hospital | pastoral.read | admitted, not discharged; last visit date |
| GET | /care/prayer | pastoral.read | open and answered prayer requests (confidential ones hidden) |
| GET | /people/{id}/care | pastoral.read | for the profile tab |
| GET | /care/totals | pastoral.below | per church: visits by type this month and year, open cases, hospital visits. No names or notes |

**Hooks:**
- `MonthlyFigures` gains `pastoral: {visits, by_type}` for the month. The report form shows "12 from Pastoral care", and `pastoral_visits` defaults to it while still editable.
- Answered prayer with `share_testimony` is offered in the report's Testimonies box.
- `LifeFeed` gains source `care`: `next_on` dates, ours only.

### Pages (`church/pastoral-care/`)

- **`index.php`:** spark cards, the Needs care list, the by-type stacked chart, and visits by leader.
- **`log.php`:** toolbar and table.
- **Record care window:** available on every pastoral page and the member profile. It has type cards, person (Select2 register search or typed name), date, who went, note, confidential, next step.
- **`case.php?id=`:**
  - Hero: person, type, priority, status, opened.
  - Tabs: Timeline (contacts, plus Add contact), Details, History.
  - Close with outcome.
- **`hospital.php`:** the admitted list, red when the last visit is more than `pastoral.hospital_days` ago.
- **`prayer.php`:** open and answered cards, with Answered + testimony.
- **Region and diocese `people/care.php`:** totals only.

### Settings → Pastoral care (`pastoral`, form, church)

| Field | Default | Notes |
|---|---|---|
| `pastoral.types_enabled` | all on | multiselect |
| `pastoral.not_contacted_days` | 60 | |
| `pastoral.hospital_days` | 7 | |
| `pastoral.counselling_confidential` | on | locked on, shown as info |

### P3 acceptance criteria

- [ ] A counselling note is null for an Associate Pastor who isn't the author, and present for the author and the Senior Pastor.
- [ ] `/care/totals` has no names or notes. Other churches are refused.
- [ ] The monthly report's figures carry `pastoral.visits` for the month, and the form defaults `pastoral_visits` from it.
- [ ] Hospital list: discharged people drop off, and "last visited" uses the newest contact or visit.
- [ ] Calendar `sources[]=care` returns next steps for our church only.

---

## P4 - Ministries

### Data model

**`ministries`:**

| Column | Notes |
|---|---|
| id, territory_type, territory_id | |
| name | |
| kind | youth / women / men / children / music / prayer / other |
| icon, colour | |
| meets_day | 0-6 null |
| meets_time | null |
| gathering_type_id | null; links Attendance |
| active | |
| order | |
| timestamps | |

**`ministry_leaders`:** id, ministry_id, user_id null, person_id null, role (leader / assistant / secretary).

**`ministry_members`:** ministry_id, person_id, joined_on. Primary key on (ministry_id, person_id).

**`ministry_notes`:** id, ministry_id, month (YYYY-MM), body, created_by.

The standard six are created for a church the first time its ministries are read:
- Youth
- Women
- Men
- Children & Sunday school
- Music & choir
- Prayer

### API

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /ministries/overview | ministries.read | count, people serving, gatherings this month, average attendance, per ministry: members, leaders, next meeting, a 6-month attendance series from the linked gathering type |
| GET | /ministries | ministries.read | |
| POST | /ministries | ministries.manage | |
| GET | /ministries/{id} | ministries.read | |
| PUT | /ministries/{id} | ministries.manage, or a leader of it | |
| GET | /ministries/{id}/members | ministries.read | |
| POST | /ministries/{id}/members | ministries.manage or leader | `{person_ids[]}` |
| DELETE | /ministries/{id}/members/{person} | ministries.manage or leader | |
| PUT | /ministries/{id}/leaders | ministries.manage | |
| GET | /ministries/{id}/gatherings | ministries.read | attendance records of its gathering type |
| GET | /ministries/{id}/activities | ministries.read | events and initiatives with a matching type or audience |
| GET | /ministries/{id}/notes | ministries.read | |
| PUT | /ministries/{id}/notes/{month} | ministries.manage or leader | |
| GET | /ministries/totals | ministries.below | per church: ministries, people serving, gatherings and attendance this month |

`/people` gains `ministries[]`, and the `ministry` filter works.

### Pages (`church/ministries/`)

- **`index.php`:** spark cards and a card per ministry (icon, leader, members, next meeting, attendance sparkline).
- **`ministry.php?id=`:**
  - Hero.
  - Tabs: Members (roster, add from the register), Gatherings (trend and table), Activities, Plans (monthly notes), History.
- **`insights.php`:** members per ministry, people serving in more than one, attendance per ministry.
- **Region and diocese `people/ministries.php`:** totals only.
- **Settings → Ministries** (`ministries`, kind custom, church): a list editor like Service times.
  - Fields: name, kind, icon, colour, meets day and time, gathering type, leaders, active.
  - The diocese section `ministries_standard` sets the starting list for new churches.

### P4 acceptance criteria

- [ ] The standard six appear once per church and are not duplicated.
- [ ] A Youth Leader who leads Youth can add and remove its members, and can't change Women's.
- [ ] The gatherings tab returns the linked gathering type's attendance.
- [ ] `/ministries/totals` has counts only.

---

## P5 - Facilities

### Data model

All tables are church-scoped (`territory_type`, `territory_id`).

**`rooms`:** id, name, capacity null, bookable, notes, active, order.

**`room_bookings`:**

| Column | Notes |
|---|---|
| id, room_id | |
| starts_at, ends_at | UTC |
| purpose | |
| booked_by | |
| ministry_id | null |
| activity_id | null |
| repeat | none / weekly |
| repeat_until | null |
| status | booked / cancelled |
| timestamps | |

**`equipment`:**

| Column | Notes |
|---|---|
| id, name, category | |
| room_id | null |
| quantity | |
| condition | good / fair / poor / broken |
| bought_on, value | null |
| serial | null |
| notes | |
| timestamps, deleted_at | |

**`equipment_loans`:** id, equipment_id, to_name, to_person_id null, out_on, due_on, returned_on, note.

**`maintenance_jobs`:**

| Column | Notes |
|---|---|
| id | |
| equipment_id / room_id | null |
| title, detail | |
| reported_by | |
| status | reported / in_progress / done |
| cost | null |
| done_on | null |
| expense_transaction_id | null |
| timestamps | |

**`duty_rota`:** id, on (date), service (string), duty (cleaning / security / ushering / other), person_id null, name null.

### API

**Overview**

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /facilities/overview | facilities.read | bookings this week, open repairs, equipment count, poor or broken items, today's and this week's bookings, this Sunday's duty |

**Rooms and bookings**

| Method | Path | Permission | Notes |
|---|---|---|---|
| CRUD | /rooms | facilities.manage | from Settings → Facilities |
| GET | /bookings | facilities.read | `from to room` |
| POST | /bookings | facilities.book | 409 with the clashing booking when it overlaps; repeats expand |
| PUT / DELETE | /bookings/{id} | the booker or facilities.manage | |

**Equipment and loans**

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /equipment | facilities.read | |
| POST / PUT / DELETE | /equipment | facilities.manage | |
| GET | /equipment/{id} | facilities.read | loans and jobs |
| POST | /equipment/{id}/loans | facilities.manage | |
| POST | /loans/{id}/return | facilities.manage | |

**Repairs**

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /maintenance | facilities.read | |
| POST / PUT | /maintenance | facilities.manage | `status` moves |
| POST | /maintenance/{id}/expense | facilities.manage | returns the Budgets record-money prefill (no money is written here) |

**Duty rota**

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | /rota | facilities.read | `from to` |
| PUT | /rota | facilities.manage | |

**Hooks:**
- `LifeFeed` source `bookings`, ours only.
- The event form can pick a room, which creates the booking.
- `facilities:duty-reminders` runs daily and sends an SMS the day before to rota people with phones, when the setting is on.

### Pages (`church/facilities/`)

- **`index.php`:** spark cards, a today-and-this-week agenda, and this Sunday's duty.
- **`bookings.php`:** FullCalendar week view by room, a Book a room window, and the clash message.
- **`equipment.php`** (toolbar and table) and **`item.php?id=`**:
  - Hero: condition and room.
  - Tabs: Loans, Repairs, History.
- **`repairs.php`:** a board of Reported, In progress and Done.
- **`rota.php`:** a week/month grid, and assigning people from the register or by typed name.

### Settings → Facilities (`facilities`, custom, church)

- **Rooms list:** name, capacity, bookable.
- **Equipment categories.**
- **Booking:**
  - `facilities.open_from` (default 06:00) and `facilities.open_to` (default 22:00)
  - `facilities.min_notice_hours` (default 0)
- **Duty reminders:**
  - `facilities.duty_reminder` (default off)
  - `facilities.duty_reminder_time` (default 18:00)

### P5 acceptance criteria

- [ ] An overlapping booking in the same room is 409 and names the clash. A different room is fine.
- [ ] A weekly booking expands until `repeat_until`, and clashes are checked on every date.
- [ ] Only the booker or a facilities manager can cancel.
- [ ] Bookings show on the calendar under `sources[]=bookings` for our church only.
- [ ] Another church is refused every facilities route.
