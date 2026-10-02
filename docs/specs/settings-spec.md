# Settings Spec

One **Settings** page for each level: church, region and diocese. Each place fills in its own profile, service times, team, finance details and communication there, and the module settings (Budgets, Attendance, Demographics) live under it. The diocese's global admins also get the system settings: email and SMS, health, security, documents, access control, the audit log and maintenance.

**Status:** planned 2026-10-02, being built in phases.
- **S0:** lock down the access-control APIs.
- **S1:** the hub, Overview, Profile and Service times.
- **S2:** Leadership & team.
- **S3:** the module settings move in. This waits for Budgets phase 6.
- **S4:** the diocese's system settings.
- **S5:** finance details and communication.
- **Later:** message templates, notification switches, and leaders without a login.

## Principles

- **Built once, used by every level.** This works the way Budgets does:
  - shared page bodies live in `includes/settings/*`;
  - each level has a five-line wrapper;
  - the acting place comes from the `X-Assignment-Id` header (`App\Support\BudgetAccess`).
- **Settings flow down.** A place uses its own value if it has one. Otherwise it uses the value of the nearest place above it (subregion, then region, then diocese), then the system value, then the built-in default.
- **A place above can lock a setting.** When the diocese or a region locks a setting, nobody below can change it. The screen says "Locked by the diocese".
- **Only differences are saved.** A place's own row exists only while its value differs from what it would otherwise inherit. "Reset" deletes the row.
- **Secrets never leave the server.**
  - Passwords and API keys are stored encrypted.
  - The screen only shows "Set" or "Not set".
  - Leaving the box blank keeps the saved value.
- **Every field has a reader.** A field ships in the same PR as the code that uses it, and its `used_by` says which code that is. There are no settings that nothing reads.
- **No empty pages.** Settings for modules that aren't built yet (Events, Initiatives and so on) are added when those modules are built.
- **Plain words.**

| On screen | Stored as |
|---|---|
| Profile | `territories` columns (name, address, phone…) |
| Service times | `territories.metadata.service_times` |
| From the diocese / From {region} | value inherited from a place above |
| Locked by the diocese | `settings.is_locked` on a place above |
| Changed | own value differs from the default |
| Leadership & team | `user_territory_assignments` at this place |

## Data Model

### `settings` (S1)
- `id`.
- `territory_id`: nullable, foreign key to `territories`, cascades on delete. NULL means the **system** level.
- `scope_id`: generated column `IFNULL(territory_id, 0)`. A unique index treats every NULL as different, so this column is what keeps one system row per key.
- `key`: varchar(120), dotted, for example `finance.mpesa_paybill` or `mail.host`.
- `value`: longText, nullable. JSON-encoded, or `Crypt::encryptString` for secrets.
- `is_locked`: bool, default false. Only meaningful on a place above church.
- `updated_by`: nullable, foreign key to `users`.
- timestamps.
- Indexes: `UNIQUE(scope_id, key)` and `INDEX(key)`.

`App\Models\Setting` is **not** Auditable. The service writes one audit row per save itself, with secrets masked.

### `territories` additions (S1)
- `website`: varchar(255), nullable.
- `logo_path`: varchar(255), nullable. The file is stored on the `public` disk as `logos/{territory_id}.webp` and re-encoded on the server, at most 2 MB.
- `sub_county`: varchar(100), nullable.
- `phone` widens from 20 to 30 characters.
- `county` is checked against `App\Support\Kenya::COUNTIES` (47) on save. Existing free-text values that don't match stay as they are; the screen flags them "Not in the county list".
- Service times stay in `metadata.service_times`, because `Church::getServiceTimes()` already reads them there:
  - each entry is `{name, day 0–6, start "HH:MM", end "HH:MM"|null, gathering_type_id|null, language|null}`;
  - at most 20 entries.

### `message_logs` (S4)
- `id`.
- `channel`: `mail` | `sms`.
- `to`, and `subject` (nullable).
- `status`: `sent` | `failed` | `logged`. `logged` means the log driver wrote it instead of sending it.
- `provider_ref` and `error` (both nullable).
- `territory_id` and `sent_by` (both nullable).
- `created_at`.
- Written by the `SendSms` job and by a mail `MessageSent` listener. It powers Health's "last sent".

### Registry (code, not a table)
**`App\Support\Settings\SettingsRegistry`**

`SECTIONS` maps each section key to:
- `label`, `icon`
- `colour`: one of the sidebar's six (primary, secondary, success, danger, purple, pink)
- `group`: Our place, Money, Messages, Ministry or System
- `levels`: which of church, region and diocese show it
- `kind`: `form` (generic fields), `custom` (its own screen) or `link` (an existing page shown inside the hub)
- `permission`: the slug used in its permission names

`FIELDS` maps each field key to:
- `section`, `card`, `label`, `type`, `rules`
- `default`: read from `config/settings.php`, which reads `.env`
- `levels`: where the field can be edited
- `lockable`, `secret`, `help`, `used_by`
- `span`: 6 or 12 columns
- `options`
- `config`: the Laravel config key it overrides at runtime, if any

### What each level sees

| Group | Section (kind) | Church | Region | Diocese |
|---|---|---|---|---|
| — | Overview (custom) | ✓ setup checklist | ✓ + churches missing details | ✓ + Health |
| Our place | Profile (custom) | ✓ | ✓ | ✓ |
| | Service times (custom) | ✓ | — | ✓ |
| | Leadership & team (custom) | ✓ | ✓ | ✓ |
| Money | Finance details (form) | ✓ | ✓ | ✓ |
| | Budgets (link → Budget Settings) | ✓ | ✓ | ✓ |
| Messages | Communication (form) | reply-to, display name, SMS signature | same | email server, SMS gateway, sender ID |
| Ministry | Attendance: gathering types (link) | ✓ | — | — |
| | Demographics: recording cadence (link) | ✓ | — | — |
| System | Security, Documents & PDF (form); Access control, Audit log, Maintenance (custom) | — | — | ✓, global admins |

Subregions inherit settings but get no Settings page of their own.

### Fields by phase

**S4, diocese system:**

*Email:*
- `mail.mailer`
- `mail.host`, `mail.port`, `mail.scheme`
- `mail.username`
- `mail.password` (secret)
- `mail.from_address`, `mail.from_name`

*SMS:*
- `sms.driver` (`log` | `africastalking`)
- `sms.username`
- `sms.api_key` (secret)
- `sms.sender_id`
- `sms.sandbox`

*Security:*
- `security.session_minutes`
- `security.password_min`
- `security.pin_login`

*Documents & PDF:*
- `documents.primary_colour`, `documents.accent_colour`
- `documents.footer_line`
- `documents.show_qr`

*Maintenance:*
- `maintenance.notice_text`
- `maintenance.notice_tone`
- `maintenance.notice_until`

**S5, every level:**

*Finance details:*
- `finance.currency` (the diocese can lock it)
- `finance.payment_methods` (list)
- `finance.mpesa_paybill`, `finance.mpesa_account`, `finance.mpesa_till`
- `finance.bank_name`, `finance.bank_branch`, `finance.bank_account_name`, `finance.bank_account_number`
- `finance.receipt_footer`

*Communication:*
- `messages.reply_to`
- `messages.display_name`
- `messages.sms_signature`

## Resolution

`Settings::resolve($key, ?Territory $place)` builds the chain `[place, parents… (via parent_territory_id, at most 6), system]` and returns `{value, source: own|inherited|default, from: {type, name}, locked_by: {type, name}|null, changed}`. It decides the value in this order:
1. Walk the chain from the top down. The first **locked** row above the place wins.
2. Otherwise use the nearest row, starting with the place itself and going up.
3. Otherwise use the `config/settings.php` default.

Caching:
- All the rows for a chain are fetched in one query and cached as `settings:v{version}:t{territory_id}`.
- Every write bumps `version`, so every place below refreshes at once.

**Applying settings to the running app** (`Settings::applyToConfig()`, S4):
- At boot it copies the system and diocese `mail.*`, `sms.*` and `security.*` values onto Laravel config, then calls `forgetMailers()`.
- It's wrapped in try/catch, so a missing table, `composer install` or `config:cache` never breaks boot.
- Queue workers re-apply the values before a job when the version has changed (`Queue::before`).

## API Contract

All routes are under `/api/settings`, inside `auth:sanctum`, with a FormRequest on every write. Responses use `successResponse`/`errorResponse`. Only global admins may pass `?territory_id=`; everyone else gets their acting place from `X-Assignment-Id`.

| Method | Path | Body / query | Returns | Needs |
|---|---|---|---|---|
| GET | `/settings/sections` | — | the rail: groups → sections `{key,label,icon,colour,kind,url,attention}` plus `can` per section | `{level}.settings.hub.overview.read` |
| GET | `/settings/sections/{section}` | — | cards → fields `{key,label,type,value,source,from,locked_by,changed,secret_set,options,help,used_by,lockable}` | `.{section}.read` |
| PUT | `/settings/sections/{section}` | `{values:{key:value}, locks:{key:bool}, reset:[key]}` | the section again | `.{section}.update`; 422 for keys not editable at this level or locked above ("Set by the diocese") |
| GET / PUT | `/settings/profile` | name, description, established_date, phone, email, website, address, town, sub_county, county, postal_code, latitude, longitude | profile + completeness % | `.profile.read` / `.update` (code is read-only) |
| POST / DELETE | `/settings/profile/logo` | image (png, jpg or webp, ≤ 2 MB) | `{logo_url}` | `.profile.update` |
| GET / PUT | `/settings/service-times` | `{times:[…]}` (≤ 20) | the list | `.servicetimes.read` / `.update` |
| GET | `/settings/team` | — | people `{assignment_id, user, role, assignment_type, is_active, last_login_at}` + `grantable` roles | `.team.read` |
| POST | `/settings/team` | firstname, lastname, phone, email?, role_id, assignment_type | person + `{employee_code, temporary_password}` (shown once) | `.team.manage` + role grantable |
| PUT | `/settings/team/{assignment}` | role_id?, is_active? | person | `.team.manage` + role grantable; not yourself |
| DELETE | `/settings/team/{assignment}` | — | 204 | `.team.manage`; not yourself, not the last manager |
| POST | `/settings/team/{assignment}/reset-access` | — | `{employee_code, temporary_password}` | `.team.manage` |
| GET | `/settings/health` | — | tiles `{key,label,status ok\|check,detail,actions}` + counts + app | `diocese.settings.hub.system.read` |
| POST | `/settings/test/email`, `/settings/test/sms` | `{to}` | `{ok, error?}` (runs immediately, throttle 5/min) | `diocese.settings.hub.system.update` |
| POST | `/settings/maintenance/{retry-failed\|forget-failed\|clear-cache\|prune-reports}` | — | `{ok, message}` | `diocese.settings.hub.system.update` |
| GET | `/settings/audit` | section, user_id, from, to, page | paged changes | `.overview.read` (own place; the diocese also sees system rows) |
| GET | `/settings/reference` | — | counties, weekdays, the place's gathering types | `.overview.read` |

**Team rules:**
- A new person is created through `App\Actions\Users\CreateUserWithAssignment`, which `UserController@store` also uses. They get:
  - a unique 6-digit employee code;
  - a random 10-character temporary password (no longer `Diocese@{year}`);
  - `must_change_password = true`.
- The code and temporary password come back once and are never stored in plain text.
- Someone who already exists, matched by phone or email, gets a new assignment rather than a second account.

## Permission Rules

**S0: the access-control APIs.** Today these only need a login. They will need permissions; global admins always pass.

| Routes | Read needs | Write needs |
|---|---|---|
| `/roles/*` | `diocesesettings.systemadministration.rolemanagement.read` | `.rolemanagement.update` |
| `/permissions/*` | `.permissions.read` | `.permissions.update` |
| `/modules/*` (except `/modules/for-role`) | `.modules.read` | `.modules.update` |
| `/module-groups/*` | `.modulegroups.read` | `.modulegroups.update` |
| `/users/*`, `/user-assignments/*` | `.usermanagement.read` | `.usermanagement.update` |
| `PUT /churches/{id}`, `PUT /territories/{id}` | — | own place + `{level}.settings.hub.profile.update`, or user management |

- `/modules/for-role` stays open to every logged-in user, because login builds the sidebar from it.
- A user may always read and update **themselves** through `/users/{self}` (the profile page).

**Settings hub permissions** (S1+), `{level}.settings.hub.{section}.{read|update}` with `territory_scope = {level}`:

| Level | update (and team.manage) | read |
|---|---|---|
| church | Senior Pastor, Church Administrator | Associate Pastor, Youth Pastor, Church Secretary, Church Treasurer (finance read + update) |
| region | Regional Overseer | Regional Secretary, Regional Treasurer (finance read + update) |
| diocese | Bishop, Diocese Administrator | Diocese Secretary, Diocese Treasurer (finance read + update), Diocese Council Member |
| system (`diocese.settings.hub.system.*`) | Global Administrator | Global Administrator |

**Who can be added to a team:** only roles of the place's own level that sit strictly below the person adding them. The order, top first, is in `SettingsAccess::TEAM_ROLES`:
- **church:** Senior Pastor, Church Administrator, Associate Pastor, Youth Pastor, Church Secretary, Church Treasurer, Elder, Deacon, Church Committee Member, then the ministry leaders (Youth Leader, Women's / Men's / Children's Ministry Leader, Music Director, Worship Leader, Sunday School Teacher, Choir Director, Usher Coordinator, Prayer Group Leader).
- **region:** Regional Overseer, Regional Secretary, Regional Treasurer, Regional Coordinator, Regional Committee Member.
- **diocese:** Bishop, Diocese Administrator, Diocese Secretary, Diocese Treasurer, Diocese Finance Officer, Diocese Council Member.

Global admins can grant any role at any place.

**Locking:** only a region or the diocese can lock, and only fields marked `lockable`.

## Pages

**Files:**
- Wrappers: `church/settings/index.php`, `region/settings/index.php` and `diocese/settings/index.php`. The diocese file is 0 bytes today.
- Shared body: `includes/settings/hub.php`.
- Context: `includes/settings/context.php`, with `settingsPageContext($level, $section)`, `settingsPageStyles()` and `settingsPageScripts()`, copied from `includes/budget/context.php`.
- `includes/settings/shell-start.php` / `shell-end.php` hold the rail and the panel column. The existing full-page settings screens include them around their content, so Budgets, Gathering Types and Recording Cadence show inside the hub without being rewritten (S3).
- JS: `assets/js/pages/settings/{api,rail,hub,fields}.js` and `sections/*.js`.

**Menu:** `SettingsHubSeeder` adds one "{Level} settings" module, with a single "All settings" page, in each level's Settings group. In S3, the separate Attendance, Demographics and Budget Settings menu items are switched off; their URLs keep working.

## Look

- **Rail (≥ 992px):** a sticky white card on the left, 264px wide.
  - Group labels are small uppercase dark text.
  - Each item has a solid coloured icon tile (`avatar avatar-sm bg-{colour}`). No two neighbours share a colour.
  - The active item is a solid primary pill, with its tile turned white.
  - A red dot marks anything that needs attention.
  - Under the active item, its cards are listed as sub-links that highlight as you scroll.
- **Below 992px:** one scrollable row of chips, using `.section-tabs` scroller behaviour.
- **Panel:** a section header (large icon tile, title, one sentence), then the cards with fields in `row g-3`.
- **Field chips:**
  - `soft-chip soft-warning` "Changed"
  - `soft-chip soft-primary` "From the diocese"
  - solid `badge bg-secondary` "Locked by the diocese", with a lock icon and a disabled input
  - for secrets: soft "Set" / "Not set"
  - an (i) "used on" tooltip
- **Lock switch:** "Lock for the places below", shown at region and diocese only.
- **Sticky save bar:** at the bottom of the panel, shown only when something changed.
  - It reads "3 unsaved changes", with Discard and Save (Save shows a spinner while saving).
  - Leaving with unsaved changes asks first.
  - Sections switch without reloading the page, and `?section=` stays in the URL.
- **Overview:**
  - KPI cards in different colours: profile complete, team members, last change.
  - The setup checklist.
  - At the diocese, Health tiles with solid status pills (Working / Check) and action buttons, plus "What's running".
- **Profile:** Identity, Contact and Location cards, with a county Select2, a Leaflet map pin and a FilePond logo, next to a sticky "how others see us" preview card.
- **Team:** a DataTable with initials avatars and role pills. Adding someone opens an `.app-modal`; its done state shows the code and temporary password with copy buttons.
- **Audit log:** a filter toolbar and the YNEX `.crm-recent-activity` timeline.
- **Loading:** skeletons on first load; spinners only inside buttons.

## Acceptance Criteria

### S0: access-control APIs
- [ ] A church pastor's token gets 403 on `GET /roles`, `POST /roles`, `PUT /roles/{id}/permissions`, `POST /permissions`, `POST /modules`, `POST /module-groups`, `POST /users` and `POST /user-assignments`.
- [ ] A global admin gets 2xx on each of them.
- [ ] A diocese user holding `.usermanagement.read` gets 200 on `GET /users`, and 403 on `PUT /roles/{id}/permissions`.
- [ ] Any logged-in user gets 200 on `GET /modules/for-role`, and on `GET` / `PUT /users/{self}`.
- [ ] `PUT /churches/{id}` by a pastor of another church gets 403.

### S1: hub, profile, service times
- [ ] With no rows, `resolve()` returns the config default with `source = default`.
- [ ] A diocese row is inherited by a church, with `source = inherited` and `from` = the diocese.
- [ ] A church's own row beats the diocese row, unless the diocese row is locked, in which case the diocese row wins and `locked_by` is set.
- [ ] Saving a value equal to the inherited one deletes the place's own row. `reset` deletes it too.
- [ ] Saving a key that is locked above returns 422 "Set by the diocese".
- [ ] Saving a key that isn't editable at the place's level returns 422.
- [ ] A secret is stored encrypted, is never in a response (only `secret_set`), and is masked in the audit row. A blank value keeps the saved one.
- [ ] After a diocese write, a church's next `resolve()` sees the new value straight away (cache version bumped).
- [ ] The app boots and `GET /settings/sections` works when the `settings` table is missing; defaults are used.
- [ ] `GET /settings/sections` for a church lists only church sections, grouped, and `can` matches the role.
- [ ] A Church Secretary can read the Profile but gets 403 on `PUT /settings/profile`.
- [ ] A county not in the list returns 422. The returned completeness % counts phone, email, address, county, map pin and logo.
- [ ] Service times: more than 20 returns 422; a bad time returns 422; saved times come back in order.
- [ ] A user from another church can't read or write this church's settings.
- [ ] Only global admins can use `?territory_id=`.

### S2: Leadership & team
- [ ] A Senior Pastor adds a Church Secretary. The response holds an employee code and a temporary password; the user has `must_change_password = true`; `GET /settings/team` never returns the password.
- [ ] A Church Administrator trying to add a Senior Pastor gets 422, and so does a region-level role.
- [ ] Nobody can change their own role or remove themselves (422). The last manager can't be removed (422).
- [ ] Adding someone whose phone already exists gives that user a new assignment, not a second user.
- [ ] `reset-access` issues a new temporary password, and the old one stops working.
- [ ] Adding, role changes and removals appear in the audit log.

### S3: module settings in the hub
- [ ] Gathering Types, Recording Cadence and Budget Settings show the settings rail with their item active.
- [ ] The separate settings menu items are switched off, and their URLs still load.

### S4: diocese system settings
- [ ] Saved mail settings override `.env` on the next request. A test email goes to `to` and adds a `message_logs` row.
- [ ] With `sms.driver = log`, a test SMS writes the log and a `logged` row. With Africa's Talking (faked HTTP), the right form fields are posted and a `sent` row is stored; a provider error comes back on screen and is stored as `failed`.
- [ ] Health returns Email, SMS, Queue, Scheduler and Storage tiles. Scheduler shows "check" when the heartbeat is more than 3 minutes old; Queue shows "check" when `failed_jobs` isn't empty.
- [ ] Retry failed re-queues failed jobs. A non-global user gets 403 on Health, the tests and Maintenance.
- [ ] The diocese's dead General Configuration, Security, Maintenance, Compliance and Notifications menu items are switched off.

### S5: finance details and communication
- [ ] A currency locked by the diocese shows as locked to a church and can't be saved by it.
- [ ] A church's M-Pesa paybill and receipt footer are returned by `GET /settings/sections/finance` and shown where `used_by` says they're used.
- [ ] A church's reply-to address is used as Reply-To on mail sent on its behalf.
