# Mobile App (PWA) Spec: what the API adds for the phone app

**Status: M1-M4 built (2026-10-19, P3); the rest planned.** The app itself is a separate repo, `Ngumbaujjdev/makueni-west-app` (`/Applications/MAMP/htdocs/makueni-west-app`). Its plan, screen map and gap analysis are in that repo's `docs/`. This spec lists only the **backend** changes the app needs. Each one is built here in its own PR, before the app screen that uses it.

The app uses the existing API for almost everything: demographics, attendance, people, visitors, activities, messages, monthly reports, budgets, accounting, remittances with M-Pesa, approvals, notifications, chat and calendar. The additions below cover only what it cannot do today. **Nothing is faked on the phone:** a missing piece is added here first.

| # | Addition | App phase |
|---|---|---|
| M1 | Diocese code on sign-in | P3 |
| M2 | Sign in by phone number | P3 |
| M3 | A named `app` token, and `auth/me` | P3 |
| M4 | CORS for the app's origin | P3 / P8 |
| M5 | `GET app/home`: one summary per role | P4 / P5 |
| M6 | Service counts: men / women and converts (**if the user agrees**) | P4 |
| M7 | Church event types: crusade (and conference, **if agreed**) | P4 |
| M8 | Reporting compliance for the places below | P5 |
| M9 | Review notes on a demographics submission | P5 |
| M10 | Comparison of the places below | P5 |
| M11 | Idempotency keys on counting POSTs | P6 |
| M12 | Web push | P7 |

## Data Model

- **M3:** no table. Tokens are Sanctum personal access tokens named `app` (the web's stay `Api-Access`).
- **M6, if agreed:** `church_attendance_records` gains `men_count`, `women_count`, `converts_count` (all nullable, unsigned int). When `men_count` and `women_count` are given, `adults_count` = their sum. Old rows are untouched.
- **M7:** `Activity::TYPES['church']` gains `crusade` (and `conference` if agreed). No migration.
- **M11:** `idempotency_keys`: `id`, `key` (uuid), `user_id`, `route`, `status_code`, `response_body` (json), `created_at`. Unique on (`key`, `user_id`). Rows older than 7 days are pruned daily.
- **M12:** `push_subscriptions`: `id`, `user_id`, `endpoint` (unique), `p256dh`, `auth`, `user_agent`, `last_used_at`, timestamps. VAPID keys are kept encrypted in Settings (system), like the other provider keys.

## API Contract

Every response uses the existing envelope (`successResponse` / `errorResponse`). New app-only routes go under `/api/app/*`.

| Method and path | Request | Response | Auth |
|---|---|---|---|
| `POST auth/login` (M1, M2, M3) | `identifier` (email, username **or phone**), `password`, `diocese_code?`, `client?` (`app`) | As today. With `client=app`, the token is named `app`, and the person's other `app` tokens are revoked | Public, throttled |
| `POST auth/login-code` (M1, M3) | `employee_code`, `diocese_code?`, `client?` | As above | Public, throttled 10/min |
| `GET auth/me` (M3) | — | `{user, territorial_roles, current_role, permissions}` for the token and `X-Assignment-Id` | Token |
| `GET app/home` (M5) | `X-Assignment-Id` | Per place type:<br>• **Church:** members and change; this month's youth, baptisms and income; the monthly report's state and due date; what is due to send up.<br>• **Subregion / region / diocese:** members, reporting % (M8), KPIs, late places, the latest submissions | Token, the role's own place |
| `GET app/compliance` (M8) | `territory_id?`, `year`, `month` | `{due, sent, late, percent, places: [{id, name, state, sent_at}]}` | Places the role oversees |
| `GET demographics/{id}` (M9) | — | Adds `notes: [{field, text, change_percent}]`: the existing `buildValidationWarnings` plus month-on-month changes over 25 % | As today |
| `GET app/compare` (M10) | `territory_id?`, `period` (month / quarter / year) | `{places: [{id, name, members, growth_percent, youth_share, baptisms}], fastest: [...], declining: [...]}` | Places the role oversees |
| Header `Idempotency-Key` (M11) | On `POST attendance`, `POST demographics`, `PUT demographics/{id}`, `POST visitors/batch` | A repeat with the same key and user returns the first stored response and changes nothing | As the route |
| `POST app/push/subscribe` (M12) | `endpoint`, `keys.p256dh`, `keys.auth` | `{id}` | Token |
| `DELETE app/push/subscribe` (M12) | `endpoint` | `{}` | Token |
| `GET app/push/key` (M12) | — | `{public_key}` | Token |

- **M1:** the diocese code is the diocese territory's `code`. When it is given and does not match the diocese of any of the person's active places, the answer is 401 "Check the diocese code." It is checked only after the password is right, so a wrong password still says "Invalid credentials". Global admins pass any code.
- **M2:** a phone number is matched on `users.phone_key` (the last 9 digits). `phone_key` is unique, so two people can only share a number in old data; if they do, sign-in is refused (422) with "Sign in with your email or employee code". Built in `App\Support\AppSignIn`.
- **M4:** `config/cors.php` reads `APP_CLIENT_ORIGINS` (comma-separated). **Unset, any origin may call** (Laravel's default before this file), so every dev host keeps working; production sets the web's and the app's addresses. `Content-Disposition` is exposed for downloads.
- **Also in P3:** `auth/login` is throttled at 10 a minute (as `login-code` already was), `password-reset/request` and `request-employee-code` at 5 a minute, and `modules/for-role` follows `X-Assignment-Id` (else the primary role, as before).
- **M12:** a push is sent wherever `Notices::send` and `PlaceNotification` already notify, through a queued job. It carries a title, a short body and the app route. Dead endpoints (404 / 410) are deleted.

## Permission Rules

- Nothing in the app gives more access than the web. Every route above is gated by the same `PlaceAccess` and permission checks as the web endpoint whose data it gathers. `app/home` never returns what that role could not read on the web.
- `app/home`, `app/compliance` and `app/compare` follow the acting role (`X-Assignment-Id`):
  - a church sees only itself;
  - a subregion sees its churches;
  - a region sees its subregions and churches;
  - the diocese sees everything.
- Names and phone numbers stay with the church (people-and-care rule). Overseer answers carry counts only.
- Push subscriptions belong to their user: one person can never list or remove another's.
- Super admins: `app/home` at the diocese, read-only, the same as the web's "Viewing only".

## Acceptance Criteria

- [x] **M1:**
  - sign-in with the right diocese code succeeds;
  - a wrong code returns 401 with "Check the diocese code";
  - no code behaves as today.
- [x] **M2:**
  - sign-in with a phone number in any format (`07..`, `+2547..`, `2547..`) finds the user;
  - a phone number shared by two users is refused.
- [x] **M3:**
  - a second app sign-in revokes the first app token but not a web token;
  - `auth/me` returns the roles and the acting role.
- [x] **M4:** a preflight from the app's origin passes (tested with no list set; a set list is Laravel's own CORS behaviour).
- [ ] **M5:**
  - for each place type, `app/home` returns the documented keys;
  - a church role never sees another church's figures;
  - a subregion's figures equal the sum of its churches.
- [ ] **M6** (if agreed): men + women saved gives adults = their sum; old records still read.
- [ ] **M7:** a church can create a `crusade` event.
- [ ] **M8:** with 4 churches and 3 reports sent, `percent` is 75 and the late church is listed.
- [ ] **M9:** a submission with youth up 40 % on last month carries a note for `youth_count`.
- [ ] **M10:** places are ranked by growth, and a role cannot compare places outside its own.
- [ ] **M11:**
  - the same `Idempotency-Key` twice creates one attendance record and returns the same body;
  - another user's same key creates a separate record.
- [ ] **M12:**
  - subscribing stores the endpoint once;
  - an assigned approval queues a push to the approver;
  - a 410 from the push service deletes the subscription.

Tests go in `tests/Feature/Mobile/` under a new `Mobile` suite added to `phpunit.xml`.
