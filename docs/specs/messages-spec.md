# Messages Spec

Part of the **Church life** plan (2026-10-05), L5. A church, region or the diocese sends a message (by SMS, email, or just in the app) to its own leaders and to the leaders of the places below it, plus numbers or emails typed in. Everyone with a login gets it in their **Inbox** and the bell, and can **reply**. Nothing goes upwards except a reply.

**Status:** planned 2026-10-05; L5a (backend) done the same day.
- **L5a:** data, recipients, sending, scheduling, the inbox, replies, saved messages, permissions and menu (this PR).
- **L5b:** the pages.

## Principles
- **One sender, not two.** Every SMS and email goes through `App\Services\Messaging\PlaceMessenger` (Settings S6b) with kind `broadcast`: the place's own account or the diocese's, its signature and reply-to, and the **Settings → Message log** with a preview. Each recipient keeps the ids of its log rows.
- **Down, never up or sideways.** You can reach your own place and the places below it (`PlaceAccess::descendantIds`). Picked places that aren't below are refused.
- **Plain words.** "Send", "Inbox", "Reply", "Saved messages".
- **Leaders with logins, plus typed numbers.** No member list is stored (decided with the owner).

## Data Model

### `message_batches`
| Column | Notes |
|---|---|
| id, uid | |
| territory_id | The sending place |
| channel | `app` (Inbox only), `sms`, `email`, `both` (SMS and email). Everyone with a login also gets it in the Inbox |
| subject | Email subject and Inbox title (≤ 120); defaults to the first line |
| body | ≤ 1600 for SMS, ≤ 10000 otherwise. `{name}`, `{place}`, `{sender}` are filled in per person |
| audience | JSON: what was picked (below), shown again on the Sent page |
| summary | Plain words of who it went to: "Senior Pastors of 24 churches in Sultan Hamud Region" |
| recipient_count, sent_count, failed_count | |
| status | `scheduled`, `sending`, `sent`, `cancelled` |
| scheduled_at, sent_at | |
| created_by, timestamps | Audited |

### `message_recipients`
`message_batch_id`, `user_id` (null for a typed number or a place's own contact), `name`, `phone`, `email`, `place_id`, `role`, `sms_status` / `email_status` (`sent`, `logged`, `failed`, `skipped`, or null when not used), `error`, `log_ids` (JSON), `read_at`.

### `message_replies`
`message_batch_id`, `message_recipient_id`, `user_id`, `territory_id` (the replier's place), `body` (≤ 2000), timestamps. In the app only, to the sender.

### `message_templates` ("Saved messages")
`territory_id`, `name` (≤ 80), `channel`, `subject`, `body`, `created_by`, timestamps. The Settings spec lists templates as "Later"; this module takes them.

## Who you can send to
`audience` is resolved on the server:

```json
{
  "own": { "roles": ["Senior Pastor", "Church Treasurer"] },
  "below": { "scope": "all | groups | picked | none", "levels": ["church", "region"], "group_ids": [], "place_ids": [], "roles": ["*"], "place_contacts": false },
  "typed": ["0712 345 678", "secretary@example.com"]
}
```

- **`own`:** the people at our own place with those roles (`"*"` = everyone).
- **`below`** (region and diocese only): the places below at those levels.
  - `all` of them;
  - `groups`: those under some subregions (at a region) or regions (at the diocese);
  - `picked`: these places.
  - At each place, the people with those roles (`"*"` = every leader). With `place_contacts`, the place's own phone and email too.
- **`typed`:** phone numbers (made `+2547…`; others refused) and emails, for this message only.
- People are counted once; a typed number that belongs to someone is that person. At most 2000 recipients.

## Sending
- **Send now:** the batch is `sending`, and a queued `SendMessageBatch` job sends to each recipient through `PlaceMessenger`. Then the batch is `sent`, with sent and failed counts.
- **Schedule:** `scheduled_at` from 5 minutes to 90 days ahead. `messages:send-scheduled` (every minute) starts the due ones. A scheduled message can be **cancelled**.
- **Retry failed:** sends again only the channels that failed.
- **SMS parts** are worked out the usual way: 160 characters (153 per part) in plain GSM text, 70 (67) with other characters.
- Recipients with a login also get a bell notification (kind `message`).

## API Contract
All routes are under `auth:sanctum` and the acting role. Responses are `{success, status, message, data}`.

| Method | Path | Notes | Permission |
|---|---|---|---|
| GET | `/messages/options` | Roles at our place, the places below grouped (with counts), the roles below, saved messages | `{L}.messages.messages.send` |
| POST | `/messages/preview` | `{audience, channel, body}` → people, with phone, with email, app only, SMS parts, a sample of names, typed entries refused | send |
| POST | `/messages` | `{audience, channel, subject, body, send_at?}` → the batch (sending or scheduled) | send |
| GET | `/messages/sent?year=` | Our batches, with this month's figures (sent, delivered, failed, replies) | `.messages.read` |
| GET | `/messages/{id}` | One batch: recipients with status, replies | read (our own batch) |
| POST | `/messages/{id}/cancel` | A scheduled one | send |
| POST | `/messages/{id}/retry` | The failed ones | send |
| GET | `/messages/inbox` | Messages to me (any of my roles), newest first, with unread count | `{L}.messages.inbox.read` |
| POST | `/messages/inbox/{recipient}/read` | | my own |
| POST | `/messages/inbox/{recipient}/reply` | `{body}` → tells the sender | my own |
| GET/POST/PUT/DELETE | `/messages/templates[/{id}]` | Saved messages of our place | send |

## Permission Rules
Per level: `{L}.messages.messages.read`, `.messages.send`, and `{L}.messages.inbox.read`.

| Grant | Who |
|---|---|
| read, send | Senior Pastor, Associate Pastor, Church Secretary, Church Administrator, Regional Overseer, Regional Secretary, Regional Coordinator, Bishop, Diocese Secretary, Diocese Administrator |
| inbox | every role at the level |

**Menu** (`MessagesAccessSeeder`, DatabaseSeeder phase 34): a **Messages** page per level in `{L}-programs` at `/{L}/messages/`.
- diocese: reuses "Diocese Communications Hub" (M16);
- church: reuses "Communication" (M34), which the church mute seeder now leaves on;
- region: a new module.

The placeholders' unbuilt sub-pages are switched off.

### L5a as built
- **Code:** `App\Services\Messages\Audience` (who it reaches), `App\Services\Messages\Broadcaster` (SMS parts, `{name}` / `{place}` / `{sender}`, delivery, retry), the `SendMessageBatch` job (the `default` queue), `messages:send-scheduled` (every minute, claims a batch before sending so a second run can't double it), `MessagesController`, `MessagesAccess`, `MessagesAccessSeeder` (phase 34).
- **The bell rings once** per person (`message_recipients.notified_at`), not again on a retry.
- **Emails** use the Settings email layout (`emails.place-message`): the subject as the heading, the body's paragraphs.
- **Typed entries** are split on new lines, commas and semicolons (spaces inside a number are fine). A typed number or email that belongs to someone becomes that person, Inbox included.
- **The summary** counts the places picked ("Senior Pastors of 2 churches in Region A"), whether or not each has someone in that role.
- Tests: the new `Communications` suite (`tests/Feature/Communications/MessagesTest.php`, 7).

## Acceptance Criteria

### L5a: backend
- [x] Recipients per level: a church reaches its own people only; a region its own and its churches (by subregion or picked); the diocese regions and churches. Picked places that aren't below are refused.
- [x] Typed numbers are made `+2547…`; others are refused and listed. People are counted once.
- [x] Sending goes through `PlaceMessenger` (log driver): each recipient gets its statuses and log ids, and the batch its counts.
- [x] Schedule, the scheduled run and cancel work; retry sends only what failed.
- [x] The Inbox shows only my own messages; read and reply work, and the reply tells the sender.
- [x] Saved messages belong to their place.
- [x] The seeder adds Messages per level, reuses the placeholders and stays idempotent.

### L5b: pages
- [ ] Inbox, Send a message (who, message, live count and preview), Sent with each message's page, Saved messages.
- [ ] "Invite by message" on an event or initiative, and "Remind" on the reports below, open the composer filled in.
- [ ] No console errors; light and dark; 390 px.
