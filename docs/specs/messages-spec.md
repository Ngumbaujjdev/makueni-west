# Messages Spec

Part of the **Church life** plan (2026-10-05), L5. A church, region or the diocese sends a message (by SMS, email, or just in the app) to its own leaders and to the leaders of the places below it, plus numbers or emails typed in. Everyone with a login gets it in their **Inbox** and the bell, and can **reply**. Nothing goes upwards except a reply.

**Status:** planned 2026-10-05; L5a (backend) and L5b (pages) done the same day.
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
`territory_id`, `name` (≤ 80), `channel`, `subject`, `body`, `shared_below` (bool), `copied_from_id` (nullable, null on delete), `created_by`, timestamps; index (`territory_id`, `channel`). The Settings spec lists templates as "Later"; this module takes them.
- **Shared (L5c, 2026-10-08).** The diocese, or a region, marks a template `shared_below`. Every place below sees it, but only the owner can edit or remove it. A church can't share, because nothing is below it.
- **Copies.** A place copies a shared template: `copied_from_id` links back, and its display name (`comms.display_name`, else its name) replaces `{sender}`. **Reset** puts the shared text back. A copy is per place: copying again returns the same copy.

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
| GET | `/messages/templates` | Ours, plus those shared from above; each has `source` (ours / shared), `owner`, `copied_from` and `our_copy_id` | send |
| POST/PUT/DELETE | `/messages/templates[/{id}]` | Our own (`shared_below` above church level only) | send |
| POST | `/messages/templates/{id}/copy` · `/{id}/reset` | Make our copy of a shared one · put our copy back to the shared text | send |
| POST | `/messages/templates/preview` | `{subject, body}` gives back the real branded email (`emails.place-message`), the SMS with our signature and its parts, the From line and the sender ID, all written to a sample person. Nothing is sent or logged. Throttled 60/min. | send |
| POST | `/messages/templates/test` | `{channel: email\|sms, subject, body}` sends it to my own email or phone through `PlaceMessenger` (kind `test`, subject `[Test] …`). Throttled 5/min. | send |

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
- **Send a message** (`/{L}/messages/new.php`) is the way in for senders (2026-10-05). `{L}.messages.messages.send` is linked to it; read and inbox stay on Messages. Each permission is linked to the page it opens, so the menu shows the way in only to roles that can use it.

The placeholders' unbuilt sub-pages are switched off.

### L5a as built
- **Code:** `App\Services\Messages\Audience` (who it reaches), `App\Services\Messages\Broadcaster` (SMS parts, `{name}` / `{place}` / `{sender}`, delivery, retry), the `SendMessageBatch` job (the `default` queue), `messages:send-scheduled` (every minute, claims a batch before sending so a second run can't double it), `MessagesController`, `MessagesAccess`, `MessagesAccessSeeder` (phase 34).
- **The bell rings once** per person (`message_recipients.notified_at`), not again on a retry.
- **Emails** use the Settings email layout (`emails.place-message`): the subject as the heading, the body's paragraphs.
- **Typed entries** are split on new lines, commas and semicolons (spaces inside a number are fine). A typed number or email that belongs to someone becomes that person, Inbox included.
- **The summary** counts the places picked ("Senior Pastors of 2 churches in Region A"), whether or not each has someone in that role.
- Tests: the new `Communications` suite (`tests/Feature/Communications/MessagesTest.php`, 7).

### L5b as built
- **Pages:** `includes/messages/` (`context.php`, `page.php`, `body-{index,new,message}.php`) with wrappers `{church,region,diocese}/messages/{index,new,message}.php`; scripts in `assets/js/pages/messages/` (`api.js`, `ui.js`, `index.js`, `new.js`, `message.js`).
- **Messages page:** section tabs **Inbox** (everyone), **Sent** and **Saved messages** (those who send). The Inbox reads in place, chat-style, with a reply box; `?open=` opens one (the bell links there).
- **Messages page: our own inbox** (2026-10-08). It replaced the copy of the template's chat, which the owner found dated. It is built in the Demographics look:
  - **Section tabs** with live figures: Inbox (unread or count), Sent (this month), Saved.
  - **One card** sized to the screen:
    - **The list:** search, "from" chips for the Inbox, Unread / Earlier groups. Rows show a solid icon tile, the sender and time, the subject (one line) and a snippet (two lines, clamped), so long text never breaks the layout.
    - **The reading pane:**
      - **Inbox:** the message as a letter, your replies as a timeline, and a reply box with saved-message chips.
      - **Sent:** the letter, replies, and "Who it went to" with read ticks.
      - **Saved:** the letter, the phone preview, and Use / Edit.
  - **On a phone** the list fills the screen and a message opens over it, with Back.
  - One colour (primary) for chips and fields; coloured placeholders.
- **Send a message is v1-events' "Send campaign" composer** (2026-10-05), as a page card:
  - **Compose (left):** channel cards (In the app / SMS / Email / SMS and email); saved messages as template chips (with "Write my own"; it asks before replacing typed text); subject and message; placeholder chips that insert at the cursor; the SMS counter ("80 / 160 · 1 SMS"); and **Send a test**, which sends to your own phone or email through the typed-in route, subject marked [TEST].
  - **Who gets it (right):** role chips, the places below, typed-in contacts; the live counter (in the app / SMS / emails, and notes for people with no phone or email); **How it reads** (v1's template-writer phone); and Send now / Schedule.
  - **Review & send** opens v1's window: review (summary rows) → sending → done (result tiles, "Who it went to") or error.
  - The saved-message editor has v1's writer layout: the fields beside the phone preview.
- **Send a message:**
  - who: role chips here; below: None / All / By subregion or region / Pick places, the roles there, and the places' own contacts; typed entries;
  - the message: the channel, a part counter, `{name}` / `{place}` / `{sender}`, and "Use a saved message";
  - a live preview (reach, by SMS / email / app, names, a phone bubble and an email card);
  - Send now or Schedule, with a confirmation naming who it goes to.
  
  It opens filled in from the URL (`scope`, `places`, `roles`, `levels`, `channel`, `subject`, `body`, `template`).
- **"Invite by message"** sits on a published event or initiative of a region or the diocese that reaches other places. A church has none: it can't message sideways. **"Remind them"** sits on the late notice in the reports below. Both need `.messages.send`.
- **Without a subject,** the Inbox title is the start of the message as the person reads it, with their name filled in.
- **The Settings message log** names the new kinds: "Message" and "Report reminder".
- **A queue worker** started before this code exists must be restarted (`php artisan queue:restart`) to know the new job.

### L5c as built (2026-10-08): templates and the Communication workspace
- **Settings → Communication** became five tabs on one row, each showing a live figure. The open tab is in the URL (`&tab=`), and the rail's sub-links open the tabs:
  - **Overview:** email and SMS as they apply now, this month's figures, and four ways in.
  - **Templates:** the library and the writer (below).
  - **Campaigns:** "Send a campaign" opens the composer, followed by the latest campaigns as cards (reached bar, replies, status).
  - **Sending:** the settings form (how we send, own account, "How our messages look" beside one large live preview, Check it works).
  - **Log:** the message log.
- **The library**, at a church or region:
  - It is grouped into "From the diocese" and "Ours" (at the diocese: "Shared with every church and region" and "For the diocese only").
  - All / Email / SMS filters and search.
  - Each card shows a small look at how it lands, its tags (channel, owner, "Copied from", "You have a copy") and its actions: Preview, Use (the composer with `?template=`), Make our copy, Edit, Reset and Remove.
- **Preview and writer:**
  - Preview has On a phone / As an email and Desktop / Mobile. The email is the server's own render in a sandboxed frame, so it shows exactly what goes out. Send this to me sends a test.
  - The writer runs in three steps: Write, Details, Check. It has placeholder chips that insert at the cursor, a warning for placeholders that won't be filled in, the SMS counter, the channel cards, Share (region and diocese) and the live preview beside it.
- **The composer** gets our templates plus the shared ones we haven't copied, so a copied one never shows twice.
- **The branded email** keeps single line breaks inside a paragraph (`nl2br`), so "God bless,⏎{sender}" lands on two lines.
- **The demo seeder** adds 4 shared diocese templates: Monthly report reminder, Event invitation, Welcome to the church and Prayer request.

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
- [x] Inbox, Send a message (who, message, live count and preview), Sent with each message's page, Saved messages.
- [x] "Invite by message" on an event or initiative, and "Remind" on the reports below, open the composer filled in.
- [x] No console errors; light and dark; 390 px.

### L5c: templates and the Communication workspace
- [x] A church sees the diocese's shared templates and can't edit or remove them; a church can't share.
- [x] Make our copy puts our name in for `{sender}`; copying again gives the same copy; Reset brings the shared text back; only a copy can be reset.
- [x] The composer lists our copy, not the shared one twice.
- [x] The preview returns the branded email with the place's name and the signed SMS, and logs nothing.
- [x] Send this to me goes to my own email or phone; a missing contact is refused.
- [x] A region's shared templates reach only its own churches; the diocese never sees a church's own; without send, nothing.
- [x] Five tabs, `&tab=` kept on refresh, save and Discard; no overflow and no console errors at 1440, 820 and 390, as a church and as the diocese.
