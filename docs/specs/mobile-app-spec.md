# Mobile App (PWA) Spec: what the API adds for the phone app

**Status: M1-M13 built (P3-P7, 2026-10-19 to 23).** The app itself is a separate repo, `Ngumbaujjdev/makueni-west-app` (`/Applications/MAMP/htdocs/makueni-west-app`). Its plan, screen map and gap analysis are in that repo's `docs/`. This spec lists only the **backend** changes the app needs. Each one is built here in its own PR, before the app screen that uses it.

The app uses the existing API for almost everything: demographics, attendance, people, visitors, activities, messages, monthly reports, budgets, accounting, remittances with M-Pesa, approvals, notifications, chat and calendar. The additions below cover only what it cannot do today. **Nothing is faked on the phone:** a missing piece is added here first.

| # | Addition | App phase |
|---|---|---|
| M1 | Diocese code on sign-in | P3 |
| M2 | Sign in by phone number | P3 |
| M3 | A named `app` token, and `auth/me` | P3 |
| M4 | CORS for the app's origin | P3 / P8 |
| M5 | `GET app/home`: one summary per role | P4 / P5 |
| M6 | Service counts: men / women and converts (agreed 2026-10-19) | P4 |
| M7 | Church event types: crusade and conference (agreed 2026-10-19; the app reads types from the API) | P4 |
| M8 | Reporting compliance for the places below | P5 |
| M9 | Review notes on a demographics submission | P5 |
| M10 | Comparison of the places below | P5 |
| M11 | Idempotency keys on counting POSTs | P6 |
| M12 | Web push | P7 |
| M13 | Money from the phone: shares not sent twice, once-only money saves, the giving page's M-Pesa card | P7 fixes |

## Data Model

- **M3:** no table. Tokens are Sanctum personal access tokens named `app` (the web's stay `Api-Access`).
- **M6:** `church_attendance_records` gains `men_count`, `women_count`, `converts_count` (all nullable, unsigned int). When `men_count` and `women_count` are given, `adults_count` = their sum. Old rows are untouched.
- **M7:** `Activity::TYPES['church']` gains `crusade` and `conference`. No migration. The app lists whatever `activities/overview` returns in `types`, so later additions need no app change.
- **M11 (built):** `idempotency_keys`: `id`, `key` (≤64), `user_id`, `method`, `route`, `status_code`, `response_body`, `created_at`. Unique on (`key`, `user_id`). Rows older than 7 days are pruned daily (`idempotency-keys:prune`). Middleware `App\Http\Middleware\Idempotent` on `POST/PUT attendance`, `POST/PUT demographics`, `POST visitors/batch`: a repeat key replays the first answer (header `Idempotent-Replay: true`); only successful answers are kept; no header means no change.
- **M13 (built, 2026-10-23):** no table.
  - `GET accounting/remittances` → each `owing` rule and month gains `waiting`: shares already made but not paid yet.
  - `POST accounting/remittances` refuses a month whose amount goes more than a shilling beyond what is owed less what is waiting. So a second tap or a second person can't send the same month twice, while a whole-shilling resend of a share with cents still can.
  - `Idempotent` is added to `POST accounting/collections`, `POST accounting/payment-vouchers`, `POST accounting/remittances` and `POST accounting/remittances/{id}/mpesa`.
  - `GET accounting/giving` gains `paybill`: exactly the public giving page's M-Pesa card (own paybill/till or the diocese paybill, every account number).
- **M12 (built):** `push_subscriptions`: `id`, `user_id`, `endpoint` (unique), `p256dh`, `auth`, `user_agent`, `last_used_at`, timestamps. VAPID keys are diocese settings `push.vapid_public` / `push.vapid_private` (the private one encrypted), marked `system` so they never show on the Settings screen; `php artisan app:push-keys` makes them once and never replaces them. Library: `minishlink/web-push`.

## API Contract

Every response uses the existing envelope (`successResponse` / `errorResponse`). New app-only routes go under `/api/app/*`.

| Method and path | Request | Response | Auth |
|---|---|---|---|
| `POST auth/login` (M1, M2, M3) | `identifier` (email, username **or phone**), `password`, `diocese_code?`, `client?` (`app`) | As today. With `client=app`, the token is named `app`, and the person's other `app` tokens are revoked | Public, throttled |
| `POST auth/login-code` (M1, M3) | `employee_code`, `diocese_code?`, `client?` | As above | Public, throttled 10/min |
| `GET auth/me` (M3) | — | `{user, territorial_roles, current_role, permissions}` for the token and `X-Assignment-Id` | Token |
| `GET app/home` (M5) | `X-Assignment-Id` | Per place type:<br>• **Church (built):** `{place, members {total, change, period} (latest approved demographics), latest {youth, baptisms, conversions}, report (MonthlyReports::state: last month until sent, then this month), money {in, out, share {owed, status, to}} (only with accounting read)}`.<br>• **Subregion / region / diocese (built, `OverseerAppController`):** `{place, churches, totals {members, youth, baptisms, conversions, women, men, sunday_school, reporting}, growth {now, then, percent}, reporting {sent, due, late, percent, month} (M8), groups [{id, name, type, churches, members, reporting, growth}], late [{id, name, pastor, due_on}], recent [{church, label, members, at}]}`. Groups are the subregions/regions below that hold churches, plus the churches directly under the place - or the churches themselves when there are no such groups. | Token, the role's own place |
| `GET app/demographics` (P4) | — | `{place, mode (monthly\|half_yearly\|yearly), periods: [{label, year, fiscal_year_id, fiscal_month_id, fiscal_semi_annual_id, record}], history: [approved records, newest first]}`: this year's and last year's periods in the church's own cadence, each with what is recorded | Token, the acting role's church |
| `GET app/places?group=` (M8) | `group?` (a subregion/region id below; another place is refused) | `{place, month, items: [{id, name, code, group, pastor, members, change, latest {label, status, at}, report (MonthlyReports::state for last month)}]}`. Compliance is inside `app/home` | Places the role oversees |
| `GET app/review/{church}` (M9) | — | `{church {id, name, pastor}, record {id, label, status, review_notes}, previous_label, lines [{key, label, value, previous, change, percent}], notes [text], can {approve, flag, request_changes}}`. Notes: a membership group over the total, or a change of more than 25 %. `demographics/{id}/flag` and `request-changes` now accept an approved record, and `approve` a flagged one (submissions stay auto-approved) | A church below the acting role; actions need the subregion review permissions |
| `GET app/compare?period=month\|quarter\|year` (M10) | `period` | `{place, period, totals, growth, rows [{id, name, type, churches, members, youth, youth_share, baptisms, conversions, growth, reporting}], fastest, declining, trend [12 × {month, label, members}], forecast [3 × {month, label, members}]}`. A record counts from the start of its period; the forecast is a least-squares line through the trend | Places the role oversees |
| Header `Idempotency-Key` (M11) | On `POST attendance`, `POST demographics`, `PUT demographics/{id}`, `POST visitors/batch` | A repeat with the same key and user returns the first stored response and changes nothing | As the route |
| `POST app/push/subscribe` (M12) | `endpoint`, `keys.p256dh`, `keys.auth` | `{id}` | Token |
| `DELETE app/push/subscribe` (M12) | `endpoint` | `{}` | Token |
| `GET app/push/key` (M12) | — | `{public_key}` | Token |

- **M1:** the diocese code is the diocese territory's `code`. When it is given and does not match the diocese of any of the person's active places, the answer is 401 "Check the diocese code." It is checked only after the password is right, so a wrong password still says "Invalid credentials". Global admins pass any code.
- **M2:** a phone number is matched on `users.phone_key` (the last 9 digits). `phone_key` is unique, so two people can only share a number in old data; if they do, sign-in is refused (422) with "Sign in with your email or employee code". Built in `App\Support\AppSignIn`.
- **M4:** `config/cors.php` reads `APP_CLIENT_ORIGINS` (comma-separated). **Unset, any origin may call** (Laravel's default before this file), so every dev host keeps working; production sets the web's and the app's addresses. `Content-Disposition` is exposed for downloads.
- **Also in P3:** `auth/login` is throttled at 10 a minute (as `login-code` already was), `password-reset/request` and `request-employee-code` at 5 a minute, and `modules/for-role` follows `X-Assignment-Id` (else the primary role, as before).
- **M12 (built):** `PlaceNotification::via()` adds `WebPushChannel` for people with a subscription, so every bell notice also pushes; chat messages push to the other members (`Chats::send`), never the sender. The queued `SendWebPush` job sends `{title, body, url, kind}` through `App\Services\Push\PushSender` and forgets endpoints that answer 404/410. Also `GET app/giving/qr`: an SVG QR of the church's giving link, for roles that read the books.

## Permission Rules

- Nothing in the app gives more access than the web. Every route above is gated by the same `PlaceAccess` and permission checks as the web endpoint whose data it gathers. `app/home` never returns what that role could not read on the web.
- `app/home`, `app/places`, `app/review` and `app/compare` follow the acting role (`X-Assignment-Id`):
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
- [x] **M5:**
  - for each place type, `app/home` returns the documented keys;
  - a church role never sees another church's figures;
  - a subregion's figures equal the sum of its churches.
- [x] **M6:** men + women saved gives adults = their sum; the web's adults-only update still works.
- [x] **M7:** `crusade` and `conference` are church event types.
- [x] **M8:** with 4 churches and 3 reports sent, `percent` is 75 and the late church is listed.
- [x] **M9:** a submission with youth up 40 % on last month carries a note for `youth_count`.
- [x] **M10:** places are ranked by growth, and a role cannot compare places outside its own.
- [x] **M11:**
  - the same `Idempotency-Key` twice creates one attendance record and returns the same body;
  - another user's same key creates a separate record.
- [x] **M12:**
  - subscribing stores the endpoint once;
  - an assigned approval queues a push to the approver;
  - a 410 from the push service deletes the subscription.

Tests go in `tests/Feature/Mobile/` under a new `Mobile` suite added to `phpunit.xml`.
