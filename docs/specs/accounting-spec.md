# Accounting Spec

Planned 2026-10-09. **A0 and A1 built 2026-10-09** (spec, chart, menu; ledger,
receipts, payment vouchers, transfers, journals, cash & bank, cashbook, trial
balance) - specified in full below. Later phases are outlined at the end and get
their full sections when they are built.

**What it is.** Accounting holds the real money of every church, region and
diocese: what came in, what went out, and where it is now (cash at hand, the
bank, M-Pesa). **Budgets** is the plan; **Accounting** is what really
happened. They share one set of books: budget actuals come from the ledger.

**How it works.** Standard double entry, with simple forms on top. Treasurers
fill in the documents they already know, and the system posts the balanced
journal behind each one:

| Document | Posts |
|---|---|
| Official receipt | Dr cash/bank/M-Pesa, Cr income (or another credit account) |
| Payment voucher (PV) | Dr expense/payable/advance, Cr cash/bank/M-Pesa; posted when it is paid |
| Transfer / bank deposit | Dr one cash account, Cr another |
| Journal voucher | Any balanced lines (opening balances, corrections); accountant only |

**One engine for every level.** Church, region and diocese all use the same
tables, services, API and page bodies. A level is only *whose books* an entry
is in (`territory_id`). Which features a level shows is configuration
(`config/accounting.php`), not code.

## Data Model

Every money amount is `decimal(15,2)`, in KES.

### `accounting_accounts` - the chart of accounts
| Column | Notes |
|---|---|
| `id` | |
| `territory_id` | null = the diocese's standard account, shared by every place; set = a place's own sub-account (its bank, its M-Pesa) |
| `parent_id` | the header it sits under (a place's bank sits under 1100 Bank accounts) |
| `code` | e.g. `4000`; a place's sub-accounts get `1100-01`, `1100-02`...; unique per (`territory_id`, `code`) |
| `name`, `description` | |
| `type` | `asset`, `liability`, `fund`, `income`, `expense` |
| `system_key` | nullable; how the engine finds an account (`cash_at_hand`, `other_income`, `due_to_diocese`...) |
| `cash_kind` | nullable: `cash`, `petty_cash`, `bank`, `mpesa`; the account holds money (it gets a cashbook) |
| `bank_name`, `branch`, `account_number`, `mpesa_number` | for a place's bank or M-Pesa account; the account number is shown masked |
| `is_header` | headers group accounts and can't be posted to |
| `is_active` | an account that has been used is switched off, never deleted |
| `created_by`, timestamps | |

**The standard chart** is defined in `App\Services\Accounting\Chart::STANDARD`.
It's written by `Chart::ensureStandard()`, which is idempotent and is called by
`AccountingChartSeeder` and on first use, so tests and new installs always
have it:

- **1xxx Assets:** 1000 Cash and bank (header) · 1010 Cash at hand · 1020 Petty
  cash · 1100 Bank accounts (header) · 1150 M-Pesa accounts (header) · 1200
  Staff advances · 1300 Due from places below · 1400 Other receivables · 1500
  Fixed assets
- **2xxx Liabilities:** 2100 Suppliers payable · 2200 Due to the diocese · 2210
  Due to the region · 2300 Payroll deductions payable · 2310 Net pay payable ·
  2400 Money held for others
- **3xxx Funds:** 3000 General fund · 3100 Building fund · 3200 KYS (Kingdom
  Youth Summit) fund · 3300 Conference fund
- **4xxx Income:** one account per standard income budget line (4000 Tithes, 4010
  Offerings, ...), plus 4900 Other income
- **5xxx Expenses:** one account per standard expense budget line (5000
  Salaries & wages, ...), plus 5800 Bank & M-Pesa charges, 5950 Cash shortage
  / over, 5990 Other expenses

### `accounting_funds`
`id`, `code` (GEN, BLD, KYS, CONF), `name`, `is_restricted`,
`equity_account_id` (its 3xxx account), `is_active`.

Every journal line carries a fund; it defaults to General. Restricted money
(the building fund, KYS) stays traceable to its fund in every report.

### `budget_lines.account_id` (new, nullable)
The income or expense account a budget line posts to. Standard lines are mapped
by slug (`Chart::LINE_ACCOUNTS`). A place's own lines with no mapping fall back
to 4900 Other income or 5990 Other expenses.

### `journals`
| Column | Notes |
|---|---|
| `territory_id` | whose books |
| `number` | `{SHORT}/{PREFIX}/{YEAR}/{000001}`, e.g. `SHR-001/RCT/2026/000012`; unique per place |
| `doc_type` | `receipt`, `payment`, `transfer`, `journal`, `reversal` |
| `date`, `narration` | |
| `party_name`, `party_phone` | who paid us / who we paid |
| `method` | `cash`, `mpesa`, `bank`, `cheque` (nullable) |
| `reference` | M-Pesa code, cheque no, bank slip no |
| `amount` | the document total (the sum of the debits) |
| `source_type`, `source_id` | what created it: `budget_entry`, `payment_voucher`; null for direct entries |
| `status` | `posted` or `reversed` |
| `reverses_id`, `reversed_by_id` | links to the journal it reverses / the journal that reversed it |
| `posted_by`, `posted_at`, timestamps | |

There are **no updates and no deletes**: a posted journal is fixed. Receipts and
attachments go in the `attachments` medialibrary collection.

### `journal_lines`
`journal_id`, `territory_id` and `date` (copied from the journal so ledger
queries are fast), `line_no`, `account_id`, `fund_id`, `budget_line_id`
(nullable), `debit`, `credit` (one of the two is 0), `memo`,
`for_territory_id` (nullable; the other place in a between-levels entry).

### `payment_vouchers` + `payment_voucher_lines`
- **The voucher:** `territory_id`, `number` (assigned when prepared,
  `.../PV/...`), `date`, `payee_name`, `payee_phone`, `pay_from_account_id`
  (a cash-kind account), `narration`, `amount`, `status`, `method` and
  `reference` (filled in when paid), `prepared_by/at`, `authorised_by/at`,
  `authorise_note`, `rejected_by/at`, `reject_reason`, `paid_by/at`,
  `journal_id`, `cancelled_by/at`.
- **`status`:** `prepared` → `authorised` → `paid`; or `rejected`; or
  `cancelled` (only before it is paid).
- **The lines:** `account_id` (the debit: an expense, payable or advance
  account), `fund_id`, `budget_line_id` (nullable), `description`, `amount`.
- **Attachments:** `supporting` (the invoice or quote) and `proof` (the payee's
  receipt or signature, added when paid).

### `accounting_periods`
`territory_id`, `year`, `month`, `status` (`open` or `closed`),
`closed_by/at`, `reopened_by/at`, `reopen_reason`.

Rows are created when needed. **A1 only enforces it:** nothing posts into a
closed month. Closing a month arrives in A2.

### `accounting_sequences`
`territory_id`, `doc_type`, `year`, `last_number`. Unique; incremented under
`lockForUpdate`, so two receipts never share a number.

### `budget_entries.journal_id` (new, nullable) - the bridge
Budgets keeps its simple entries, and every entry is in the ledger:

- **Budgets → Accounting:**
  - recording a budget entry posts a receipt or payment journal (`source_type =
    budget_entry`): cash/bank/M-Pesa comes from its `method` (none = Cash at
    hand), the income or expense account from its line's `account_id`;
  - changing the entry **reverses** the old journal and posts a new one;
  - removing it reverses; bringing it back posts again.
- **Accounting → Budgets:** a receipt or paid PV line on a budget line, dated
  inside a budget In use, adds a budget entry linked to the journal, so budget
  actuals stay right.
  - Such an entry can't be changed or removed in Budgets (*"This came from
    receipt X - reverse it in Accounting"*);
  - reversing the journal removes the entry.
- **Existing entries** get their journals from `php artisan
  accounting:backfill-budget-entries` (idempotent).

## Engine (services, `app/Services/Accounting/`)
- `Chart`: the standard chart; `ensureStandard()`; `cashAccounts(place)`;
  `accountFor(systemKey)`; `forBudgetLine(line)`; `placeAccount(place, kind)`,
  which finds or creates the place's "Bank" / "M-Pesa" sub-account.
- `Ledger`:
  - `post(Territory $place, array $header, array $lines, User $by)` checks
    that every account can be used by this place, that there are at least two
    lines, that the debits equal the credits (to the cent) and that the period
    is open, then numbers the journal and saves it in one transaction;
  - `reverse(Journal, User, reason)`;
  - `balance(place, account, ?date)`;
  - `trialBalance(place, date)`.
- `Numbering`: `next(place, docType, year)`.
- `Receipts`, `PaymentVouchers`, `Transfers`, `JournalVouchers`: one service
  per document. They build the lines and call `Ledger::post`.
- `Cashbook`: the rows for one cash-kind account between two dates, with the
  opening balance and a running balance.
- `BudgetBridge`: the two-way link above.

## API Contract

All routes are under `/api/accounting`, behind `auth:sanctum`.

- **The place:** the acting role's place (the `X-Assignment-Id` header). With
  `?territory_id=` a user may read a place below them (needs `below`).
- **Replies:** `{success, status, message, data}`, as the other modules.

| Method | Path | Ability | What |
|---|---|---|---|
| GET | `/places` | read (own) | our books first, then - with `below` - the regions and churches under us, for the "Whose books" picker |
| GET | `/overview` | read | cash position (each cash account's balance), this month's money in and out, by fund, the latest documents, PVs waiting |
| GET | `/options` | read | the accounts that can be used by kind (cash, income, expense, other), funds, budget lines, methods |
| GET | `/accounts` | read | the chart with this place's sub-accounts and balances |
| POST | `/accounts` | accounts | add a bank / M-Pesa / cash account for this place |
| PUT | `/accounts/{id}` | accounts | rename, bank details, active (own sub-accounts only) |
| POST | `/chart` · PUT `/chart/{id}` | chart (diocese) | add or change a standard account |
| GET | `/cashbook?account_id=&from=&to=` | read | opening balance, rows with a running balance, closing balance |
| GET | `/trial-balance?date=` | read | every account's debit or credit balance, the two totals and `balanced` |
| GET | `/journals?type=&from=&to=&q=` | read | journals (documents), newest first |
| GET | `/journals/{id}` | read | one journal with lines, attachments and its reversal |
| POST | `/receipts` | receipt | `{date, account_id (cash-kind), party_name, party_phone?, method?, reference?, narration?, lines: [{account_id, fund_id?, budget_line_id?, amount, memo?}]}` |
| POST | `/transfers` | receipt | `{date, from_account_id, to_account_id, amount, reference?, narration?}` |
| POST | `/journal-vouchers` | journal | `{date, narration, lines: [{account_id, fund_id?, debit, credit, memo?}]}` |
| POST | `/journals/{id}/reverse` | journal, or the poster's own document ability | `{reason}`; posts the mirror journal dated today (or `date`) |
| POST · DELETE | `/journals/{id}/attachments` | record | add or remove a receipt photo or PDF |
| GET | `/journals/{id}/attachments/{media}` | read | the file |
| GET | `/payment-vouchers?status=` | read | PVs |
| POST | `/payment-vouchers` | prepare | prepare one: `{date, payee_name, payee_phone?, pay_from_account_id, narration, lines: [{account_id, fund_id?, budget_line_id?, description, amount}]}` |
| GET | `/payment-vouchers/{id}` | read | |
| PUT | `/payment-vouchers/{id}` | prepare | change while prepared or rejected (it goes back to prepared) |
| POST | `/payment-vouchers/{id}/authorise` | authorise | not by the person who prepared it |
| POST | `/payment-vouchers/{id}/reject` | authorise | `{reason}` |
| POST | `/payment-vouchers/{id}/pay` | pay | `{paid_on, method, reference}`; posts the journal |
| POST | `/payment-vouchers/{id}/reverse` | journal | `{reason}`; reverses the payment's journal, the voucher goes back to authorised |
| POST | `/payment-vouchers/{id}/cancel` | prepare | before it is paid |
| POST · DELETE | `/payment-vouchers/{id}/attachments` | prepare / pay | |

## Permission Rules

Permissions are `{level}.accounting.<area>.<action>`. They are checked through
`App\Support\AccountingAccess` (the `PlaceAccess` pattern: the acting role, your
own place to write, the places below to read).

| Ability | Permission | Menu page |
|---|---|---|
| read | `accounting.books.read` | Overview, Cash & bank, Cashbook, Documents |
| receipt | `accounting.receipts.create` | Receipts |
| prepare | `accounting.payments.prepare` | Payments |
| authorise | `accounting.payments.authorise` | Payments |
| pay | `accounting.payments.pay` | Payments |
| journal | `accounting.journals.post` | Journals |
| accounts | `accounting.accounts.manage` | Cash & bank |
| chart | `accounting.chart.manage` (diocese only) | Chart of accounts |
| below | `accounting.below.read` (region and diocese) | (read the places below) |

**Grants (`AccountingAccessSeeder`):**

| Level | Role | Abilities |
|---|---|---|
| church | Church Treasurer | read, receipt, prepare, pay, accounts, journal |
| church | Senior Pastor, Associate Pastor | read, authorise |
| church | Church Administrator | read, receipt, prepare |
| church | Church Secretary | read, prepare |
| church | Church Committee Member | read |
| region | Regional Treasurer | read, receipt, prepare, pay, accounts, journal, below |
| region | Regional Overseer | read, authorise, below |
| region | Regional Secretary | read, prepare, below |
| region | Regional Coordinator, Regional Committee Member | read, below |
| diocese | Diocese Finance Officer | read, receipt, prepare, pay, accounts, journal, chart, below |
| diocese | Diocese Treasurer | read, receipt, prepare, pay, accounts, journal, below |
| diocese | Bishop | read, authorise, below |
| diocese | Diocese Administrator, Diocese Secretary | read, prepare, below |
| diocese | Diocese Council Member | read, below |

**Rules that hold for everyone, global admins included:**
- **Separation of duties:** whoever prepared a PV can't authorise it.
- **Writing:** documents are written only into your own place's books.
- **Reading below:** places below are read-only.
- **Fixed once posted:** nothing posted is edited or deleted, only reversed.

**The menu:**
- The FINANCE group gets an **Accounting** module next to Budgets, at every level.
- Pages: Overview, Cash & bank, Cashbook, Receipts, Payments, Journals, plus
  Chart of accounts at the diocese only.
- Paths are `/{level}/accounting/*.php`: thin wrappers around
  `includes/accounting/body-*.php`.

## Acceptance Criteria (A0 + A1)
- [x] The standard chart is created once; running `ensureStandard()` again
      adds nothing. Every standard budget line maps to an account.
- [x] A journal whose debits don't equal its credits is refused (422), and so
      is one with fewer than two lines, a header account, another place's
      sub-account, an inactive account, a future date or a closed period.
- [x] A receipt of 1,500 into Cash at hand on Tithes posts Dr 1010 1,500 / Cr
      4000 1,500. Cash at hand's balance goes up by 1,500, and the receipt gets
      the next number for that place and year.
- [x] Two places' numbers are independent; numbers never repeat.
- [x] A transfer from Cash at hand to the bank leaves the total cash unchanged
      and posts no income or expense.
- [x] PV: prepared → authorised by another person → paid posts Dr expense / Cr
      bank. The preparer can't authorise. A rejected PV can be fixed and
      authorised later. A paid PV can't be cancelled.
- [x] Reversing a journal posts the mirror lines. The original shows "reversed",
      the balances return to where they were, and it can't be reversed twice.
- [x] Cashbook: opening balance + in − out = closing balance, with a running
      balance per row, in date order.
- [x] Trial balance: the total of the debits = the total of the credits for a
      place after every kind of document.
- [x] Bridge:
  - recording a budget entry posts its journal;
  - changing it reverses and reposts;
  - removing it reverses;
  - a receipt on a budget line inside a budget In use adds a budget entry, and
    that entry can't be changed in Budgets;
  - the backfill gives every existing entry a journal exactly once.
- [x] Permissions:
  - a role without a permission is refused;
  - a church treasurer can't post into another church's books;
  - a region can read a church below it with `below` but can't write there;
  - a church can't read its region's books.
- [x] The existing Budgets and Facilities tests still pass.

## As built (A0 + A1)
- **Pages** (`includes/accounting/`, one body per page; `{church,region,diocese}/accounting/*.php` wrappers):
  Overview (cards with sparklines, where the money is, 12 months in/out, by fund, top income and
  spending, latest documents, three first steps for empty books), Cash & bank (money account cards,
  the chart by type with balances), Cashbook (account tiles, period, brought/carried forward, running
  balance, print, CSV), Receipts, Payment vouchers (opens on what waits for you), Journals (with the
  trial balance), All documents, and Chart of accounts (diocese).
- **Windows** (`assets/js/pages/accounting/windows.js`): write a receipt (several lines and funds,
  live preview, printable official receipt), prepare a voucher, move money, post a journal / opening
  balances (with "put the difference in the General fund"), add an account; view a document (lines,
  files, reverse) and a voucher (steps, authorise, send back, pay, reverse payment, files).
- A region or the diocese switches "Whose books" in place (`?territory_id=`); write buttons hide there.
- `php artisan accounting:backfill-budget-entries` posts budget entries recorded before Accounting -
  run it once on each database (it is safe to run again).

## A2 - Reconciliation (built 2026-10-09)

Proving the books right, the same for every level.

### Data
- `cash_counts`: place, account (cash or petty cash), `counted_on`, `denominations` (1000/500/200/100/50 notes; 40/20/10/5/1 coins), `counted_total`, `book_balance` on that date, `difference`, `reason`, `is_surprise`, `status` balanced | waiting | approved | rejected, `counted_by`, `approved_by/at`, `reject_reason`, `journal_id` (the adjustment).
- `bank_reconciliations`: place, account (bank or M-Pesa), `statement_date`, `statement_balance`, `book_balance`, `in_transit`, `unpresented`, `difference`, `status` draft | submitted | approved | returned, `prepared_by/at`, `approved_by/at`, `return_reason`, `notes`.
- `bank_statement_lines`: an imported statement's lines - date, description, reference, money in/out, balance, `matched_line_id`, `status` unmatched | matched | added | ignored.
- `journal_lines.cleared_on` + `reconciliation_id`: a book line on the statement.
- `accounting_place_accounts`: a place's settings for one money account - `imprest_float`, `custodian_id`, `statement_mapping` (the CSV columns, remembered). Separate from the account because standard accounts like Petty cash are shared by every place.
- `journals.doc_type` gains `petty_cash` (numbered `PCV`); `payment_vouchers.purpose` payment | imprest_topup (a top-up posts as a transfer).

### Rules
- **Cash count**: equal to the book (on its date) -> balanced, nothing posted. Different -> a reason is required and it waits; someone other than the counter (authorise ability) approves it, which posts the difference (short: Dr 5950 / Cr cash; over: Dr cash / Cr 5950), or sends it back to count again. One waiting count per account.
- **Reconciliation**: `statement + in transit (uncleared book debits up to the date) - unpresented (uncleared book credits) - book balance = difference`. Ticking a book line clears it; an imported statement auto-matches one to one (same amount and direction within 7 days, a matching reference first); a statement line the books lack is added to the books (receipt or payment, already matched); lines can be matched by hand, unmatched or left out. Submit only at a zero difference; someone other than the preparer signs off (refused if the books changed since) or sends it back. A date already signed off can't be reconciled again; uncleared lines over 30 days are flagged.
- **Petty cash**: a fixed float and custodian; petty cash vouchers never spend more than is in the box; Top up prepares a payment voucher for exactly float - balance (one waiting at a time), authorised and paid as usual.
- **Month-end close**: a month closes when every money account that moved or held money in it has a balanced/approved count (cash) or a signed-off reconciliation dated in it (bank, M-Pesa), nothing waits, the month has ended and the months before it (from the first posting) are closed. Warnings: authorised vouchers unpaid, petty cash below its float. Reopen: the level above (`accounting.periods.reopen`), or the diocese its own, with a reason; the latest closed month first.
- **Board**: every region and church below, each money account's balance, last check and months behind, waiting items, last closed month; state ok | due | late | none.

### API (all under `/api/accounting`)
`GET reconciliation` · `GET reconciliation-board` · `POST cash-counts` · `POST cash-counts/{id}/approve|reject` · `POST reconciliations` · `GET|PUT|DELETE reconciliations/{id}` · `POST reconciliations/{id}/tick|statement|submit|approve|return` · `POST reconciliations/{id}/statement/{line}/match|unmatch|ignore|add` · `GET|PUT petty-cash` · `POST petty-cash/spend|top-up` · `GET periods?year=` · `POST periods/close|reopen`.

### Permissions
`accounting.reconcile.do` (treasurers, finance officers), `accounting.pettycash.spend` (treasurers, church administrator and secretary), `accounting.periods.close` (treasurers, finance officers), `accounting.periods.reopen` (regional treasurer and overseer, diocese finance officer and treasurer). Approving a difference and signing off use `accounting.payments.authorise`. New pages: Reconciliation and Month-end close (every level); Reconcile opens from Reconciliation.

## A3 - Sunday collections (built 2026-10-09)

Churches only (the page and permissions exist at church level).

### Data
- `collections`: place, `date`, `title`, `attendance_record_id` / `gathering_type_id` (that day's attendance, optional), `cash_account_id` (default Cash at hand), `mpesa_account_id` (the church's M-Pesa, made on first use), `denominations`, `cash_total`, `mpesa_total`, `total`, `witnesses` (names, e.g. ushers without accounts), `notes`, `status` counted | returned | posted | reversed, `counted_by`, `confirmed_by/at`, `return_reason`, `journal_id` (the receipt), `banking_journal_id` (the deposit).
- `collection_lines`: `label`, `account_id` (income, or money held for others), `fund_id`, `cash_amount`, `mpesa_amount`. Presets: Offering (4010, General), Tithe (4000, General), Thanksgiving (4020, General), Building (4020, Building fund), KYS (4020, KYS fund); any other income account and fund can be added.

### Rules
- Whoever records the count can change it until it is confirmed. If the notes and coins are counted, they must add up to the cash entered.
- A second person (never the counter) confirms it: one official receipt is posted - Dr cash, Dr M-Pesa, Cr each kind with its fund - and Budgets follow through the bridge. Or they send it back with a reason; it is fixed and waits again. A count never confirmed can be deleted.
- Bank it (treasurer): a transfer from where the cash was kept to the bank or M-Pesa, linked to the collection, with the deposit slip attached; once. Reversing that transfer lets it be banked again.
- A confirmed collection is reversed from Collections (whoever keeps the books), only before it is banked; its receipt can't be reversed from the documents.
- Month-end close: collections waiting or sent back in the month block it; confirmed cash not banked is a warning.
- Ushers and elders who count or confirm see Collections, not the rest of the books.

### API (under `/api/accounting`)
`GET collections` · `GET collections/options?date=` · `POST collections` · `GET|PUT|DELETE collections/{id}` · `POST collections/{id}/confirm|return|bank|reverse`. The Overview gains `collections` (last service by kind, this month, waiting, not banked) for churches.

### Permissions
`church.accounting.collections.record` (Church Treasurer, Church Administrator, Church Secretary, Usher Coordinator, Deacon, Elder), `church.accounting.collections.confirm` (Church Treasurer, Senior Pastor, Associate Pastor, Elder), `church.accounting.collections.read` (with either, and with reading the books). Banking needs `receipts.create`; reversing needs `journals.post`.

## A4 - Approvals and requisitions (built 2026-10-09)

One approval engine (`app/Approval/`) for every level and every kind of document; requisitions and payment vouchers are its first two subjects. Ported from erp-server, trimmed to churches, with its known bugs fixed.

### Data
- Definitions: `approval_workflows` (`subject_type` requisition | payment_voucher | `*`, `level` church | region | diocese or null for all, `applies_when` conditions e.g. amount bands, `match_priority`, `version`, active) → `approval_stages` (`sequence`, `type` single | all | quorum, `quorum`, `on_reject` terminate | return_previous | continue, `on_empty` block | skip | escalate, `sla_hours`, `escalate_after_hours`, `escalate_to`) → `approval_steps` (`resolver_type` role_here | role_above | permission_here | user, `resolver_config`).
- Runtime: `approval_requests` (subject morph, place, requester, context, status pending | approved | rejected | returned | cancelled, the workflow's name) → `approval_request_stages` (a frozen `rule_snapshot`, so editing a rule never changes a request already running; status incl. blocked with a reason) → `approval_assignments` (due, reminded, escalated, superseded, delegated_from, escalated_from) → `approval_decisions` (approve | reject | return, with comment) and `approval_events` (the timeline). `approval_delegations`: who acts for whom, between dates, optionally for one kind of document.
- `requisitions`: place, number `REQ`, requester, `kind` payment | purchase | advance, purpose, amount, needed_by, expense account, budget line, fund, payee, status draft | submitted | approved | returned | rejected | paid | cancelled, `payment_voucher_id`; quotes and papers as attachments.
- `staff_advances` (the person, amount, issued, due, retired, returned, status open | part_retired | retired) and `advance_retirements`.
- `payment_vouchers.requisition_id`; PV purpose gains `advance`.

### Rules
- Who approves is worked out from the requesting place: holders of a role here, the nearest region or diocese above's holders, holders of a permission here, or named people. The requester is always removed. A stage left empty skips, escalates to the place above, or blocks (the request shows why and can be retried after someone is assigned).
- The rule chosen is the most specific match: a rule for this level beats one for all levels, then the higher priority, then the newest. Conditions are strict - a missing amount never matches "up to 50,000".
- Deciding locks the request row, so two approvers deciding at once complete a stage once. Return and Reject need a comment.
- Notices are sent only after the decision is saved: the bell at once, SMS and email by a queued job, each switchable in Settings (`approvals.notify_sms`, `approvals.notify_email`).
- `approvals:escalate` (hourly): a reminder once a step is due, then, after the grace, the person named in `escalate_to` is added; on an "all" stage they replace the late approver, who is marked superseded.
- Delegation is one hop, never to the requester, and ends on its date.
- Default rules (seeded once by key; the diocese edits them on Approval rules): church - Senior Pastor up to 50,000, + a Church Committee Member to 200,000, + the Regional Overseer above; region - Regional Overseer, + Regional Treasurer, + Diocese Finance Officer; diocese - Diocese Finance Officer, + the Bishop above 50,000. SLA 48 hours, escalate after 24 more.
- A requisition: Ask → approve → **Make the payment**, which prepares a payment voucher already authorised (nobody approves twice), then it is paid as any PV. An advance is paid to 1200 Staff advances; it is retired with receipts and any change (Dr expenses, Dr cash / Cr 1200). Someone with an advance overdue (`approvals.advance_days`, default 14) can't ask for another.
- A payment voucher not from a requisition is routed when prepared; approved → authorised, rejected or returned → rejected with the comment. If no rule matches, A1's rule stands (anyone with authorise, never the preparer). Changing a voucher cancels its request and routes it again.
- Approvers above the place see the document's details and files through the approval itself, not the place's books.

### API
`GET /api/approvals` (tabs waiting | mine | decided) · `GET /api/approvals/requests/{id}` (+ `/files/{media}`) · `POST /api/approvals/requests/{id}/approve|reject|return|cancel|retry` · `GET|POST /api/approvals/delegations`, `DELETE .../{id}` · `GET /api/approvals/people` · `GET|POST /api/approvals/workflows`, `PUT|DELETE .../{id}`.
Under `/api/accounting`: `GET|POST requisitions` · `GET requisitions/options` · `GET|PUT requisitions/{id}` · `POST requisitions/{id}/approve|reject|return|cancel|pay` · requisition attachments · `POST advances/{id}/retire`.

### Permissions
`accounting.approvals.read` and `accounting.requisitions.create` for every role at a level; `accounting.requisitions.read` with reading the books; `diocese.accounting.approvalrules.manage` (Diocese Finance Officer). Approving needs no permission - being assigned is what lets someone act. Paying needs `payments.pay`.

## A5 - Procurement (built 2026-10-09)

Standard buying - quotations, a local purchase order (LPO), goods received (GRN), the supplier's invoice matched three ways, then payment - but only for bigger purchases. Small ones stay a requisition paid straight away. Every level.

### Thresholds (Settings > Procurement, diocese)
- `procurement.one_quote_limit` (default 50,000): a purchase requisition up to this can be paid straight away (A4) or ordered; above it, it must go through an order.
- `procurement.quotes_needed` (default 3): quotations needed before an order above the limit.

### Data
- `suppliers`: place, name (unique in the place), phone, email, KRA PIN, how to pay them, notes, active. Removed only if never used; otherwise switched off.
- `quotations`: on a requisition - supplier, amount, notes, the quote file, `chosen` with `chosen_reason` (needed when it isn't the cheapest).
- `purchase_orders`: place, number `LPO`, requisition (one order per requisition), supplier, date, deliver_by, notes, amount, status issued | part_received | received | closed | cancelled, issued_by, closed/cancelled by and reason.
- `purchase_order_lines`: description, quantity, unit_price, amount, account (expense, or 1500 Fixed assets for an asset), fund, budget line, `is_asset`, `received_qty`, `billed_qty`.
- `goods_received` (+ lines): number `GRN`, the order, date, received_by, notes, photo or delivery note; per line the quantity and, for an asset at a church, the Facilities equipment item it made.
- `supplier_invoices` (+ lines): the bill - number `BILL` (its journal's), supplier, order, the supplier's own invoice number, date, due date, amount, status posted | paid | reversed, journal, payment voucher, the invoice file; per line the order line, quantity, unit price, amount.
- `requisitions.status` gains `ordered`; `payment_vouchers.purpose` gains `bill` and `supplier_invoice_id`; `journals.doc_type` gains `bill`.

### Rules
- Quotations are added to a purchase requisition by the person asking or whoever buys (`procurement.manage`), until it is ordered; one is chosen, with a reason if it isn't the cheapest.
- **Raise the order** (whoever buys): the requisition is an approved purchase; above the limit it has the quotations needed and a chosen one. The order goes to the chosen supplier (or one picked, at or below the limit). Its lines can't add up to more than was approved. The requisition becomes ordered. Nobody approves again - the requisition's approval carries over.
- **Goods received:** the quantity received per line, never more than is still to come; the order becomes part-received or received. At a church, an asset line creates the equipment in Facilities (name, quantity, value, supplier, date). A delivery can be undone while nothing on it is billed (its equipment goes too).
- **The bill (3-way match):** per line, the quantity billed can't exceed what was received and not yet billed, and the price can't be above the order's. Posting it gives Dr each line's account (expense or 1500) / Cr **2100 Suppliers payable**, with the supplier as the party; Budgets count it then, on the line's budget line. An unpaid bill can be reversed (its quantities free up again).
- **Pay the bill** (whoever prepares payments): a payment voucher already authorised - Dr 2100 / Cr the bank, cash or M-Pesa - paid as any voucher. Paying it marks the bill paid; reversing that payment, or cancelling the voucher, opens it again.
- An order is cancelled only while nothing is received (the requisition goes back to approved); a part-received order can be closed (nothing more is expected). When the order is received or closed and every bill on it is paid, the requisition is paid.

### API (under `/api/accounting`)
`GET procurement` · `GET procurement/options` · `GET|POST procurement/suppliers`, `PUT|DELETE procurement/suppliers/{id}` · `POST requisitions/{id}/quotes`, `DELETE requisitions/{id}/quotes/{quote}`, `POST requisitions/{id}/quotes/{quote}/choose`, `GET requisitions/{id}/quotes/{quote}/file` · `POST requisitions/{id}/order` · `GET procurement/orders/{id}` · `POST procurement/orders/{id}/receive|bill|close|cancel` · `POST procurement/deliveries/{id}/undo` · `POST procurement/bills/{id}/pay|reverse`, `GET procurement/bills/{id}/file`.

### Permissions
`{level}.accounting.procurement.manage` (Church Treasurer, Church Administrator; Regional Treasurer, Regional Secretary; Diocese Finance Officer, Diocese Treasurer, Diocese Administrator) and `{level}.accounting.procurement.read` (with reading the books). Paying a bill needs `payments.prepare` (to make the voucher) and `payments.pay`.

## A6 - Remittances between levels (built 2026-10-10)

Money between a church, its region and the diocese, on a cash basis in both sets of books: what is due is worked out from the books, sending it is a payment voucher like any other, and the receiving place confirms it into its own books. Nobody writes in another place's books.

### What is due
- The deductions that apply to a place (Budgets > Deductions, e.g. "Diocese share: 10% of Tithes received, every church") and are owned by another place - that place is who it is owed to.
- Per month: a % of the money actually received in the books that month on the accounts of the rule's lines (or on all income except 4100 and 4110 - money from other places is never charged again), or the fixed monthly amount (from the month the rule was made, or the place's first entry if later).
- Sent = what the place's remittances for that rule and month add up to, once their voucher is paid. Owed = due - sent. Nothing is posted for what is due; it is worked out each time.

### Data
- `remittances`: number `REM` (in the sender's books), `from_territory_id`, `to_territory_id`, `kind` share | support, `budget_deduction_id` (a share), purpose, amount, status waiting | sent | queried | confirmed | cancelled; the sending side - `payment_voucher_id`, `sent_journal_id`, `sent_on`, method, reference; the receiving side - `into_account_id`, `received_on`, `received_journal_id`, confirmed_by/at; `query_reason` / queried_by / at and the sender's `answer`.
- `remittance_lines`: the month (`YYYY-MM`) and the amount, with what was due then (a share), so one payment can cover several months.
- `payment_vouchers.purpose` gains `remittance`; `payment_vouchers.remittance_id`.

### Rules
- **Send the share** (whoever prepares payments): pick the rule, the months and amounts (owed is filled in), and where it is paid from. It makes a payment voucher - Dr the rule's paid-through account (e.g. 5700 Diocesan tithe) on its budget line, so Budgets' "sent" keeps working / Cr bank - approved like any payment. Paying the voucher sends the remittance: it is in transit, and the receiving place's treasurers get a bell.
- **Send support down** (region or diocese, to a place below it): an amount, what it is for and the expense it is charged to (5600 Charitable activities by default) - the same voucher route.
- **Confirm received** (the receiving place, whoever writes receipts): the account it reached and the date (not before it was sent). It posts the receiving place's receipt - Dr that account / Cr 4100 Church contributions (a share) or 4110 Diocesan allocations (support) - with the sending place on the line (`for_territory_id`), so the receiver's books show who sent what. Budgets follow through the bridge.
- **Query** it ("not on our statement") with a reason; the sender answers and it is in transit again. A confirmation can be undone (its receipt is reversed) by the receiving place; its receipt can't be reversed from All documents.
- The sender's payment can't be reversed once the receiver has confirmed it; reversing it before then puts the remittance back to waiting. Cancelling the voucher cancels the remittance.
- **Month-end close** warns about: remittances sent and not confirmed, ones queried, money from others to confirm, a share still owed for the month - and (from A4 and A5) advances overdue and supplier bills past due.

### Pages
`remittances.php` at every level: **What we owe** (each rule by month: due, sent, confirmed, owed - with Send the share, and our remittances with where they stand), **Coming in** (to confirm, queried, confirmed), and for a region or the diocese **Places below** (per place for the year: due, sent, confirmed, in transit, owed, late) with each place's **statement** by month (print).

### API (under `/api/accounting`)
`GET remittances` · `GET remittances/options` · `POST remittances` · `GET remittances/{id}` · `POST remittances/{id}/confirm|query|answer|unconfirm` · `GET remittances/board` · `GET remittances/statement?place=&year=`.

### Permissions
`{level}.accounting.remittances.read` (with reading the books). Sending needs `payments.prepare` (the voucher, then approval and paying as usual); confirming, querying and undoing need `receipts.create` at the receiving place; the board and statements of places below need `below.read`.

## A7 - Payroll (built 2026-10-10)

A simple register of the people a place pays, a monthly run, approval through the engine, and the standard postings. Every level.

**No statutory deductions (changed 2026-10-10):** the churches don't deduct PAYE, NSSF, SHIF or the Housing Levy, so payroll doesn't work them out at all. A payslip is pay (basic + allowances) less any other deduction - a SACCO, a loan, recovering an advance.

### Data
- `employees`: place, optional user, name, phone, email, position, start and end dates, how they are paid (M-Pesa, bank, cash) and to what, basic pay, allowances (name + amount), active. ID number and KRA PIN are stored encrypted, kept out of the audit trail and only ever returned masked (`•••• 5678`).
- `payroll_runs`: place, `month` (one run per place and month), status draft | submitted | returned | posted | paid | cancelled, totals (pay, other deductions, net), `journal_id` (the posting), prepared/approved by.
- `payslips`: per person - a snapshot of name, position and how paid; basic, allowances, pay, the other deduction with its note, net; the payment reference (e.g. the M-Pesa code).
- `payroll_payments`: the staff's net pay paid out of a run, with its payment voucher.
- Journal type `payroll` (number `PRL`); `payment_vouchers.purpose` gains `payroll`.

### Rules
- **Start the month** (whoever manages payroll): a draft run with a payslip for everyone active in that month. In the draft, pay, allowances and the other deduction can be changed (never more than the pay); Recalculate reads everyone's pay again and keeps their other deduction. People can be added or taken off.
- **Submit**: through the approval rules (the context amount is the pay); with no rule, anyone who authorises payments here but the person who prepared it. Returned or rejected, it is a draft again.
- **Approved = posted**: Dr 5000 Salaries & wages (pay), on the salaries budget line / Cr 2310 Net pay payable (net) / Cr 2300 Payroll deductions payable (other deductions, when any). Budgets count it then.
- **Pay the staff**: one payment voucher already authorised - Dr 2310 / Cr the account paid from - with a line per person; paid as any voucher. The run is paid when it is. Each person's reference can be recorded on their payslip. Reversing that payment, or cancelling its voucher, opens it again.
- **Other deductions** (a SACCO, a loan) are held in 2300 and paid on with an ordinary payment voucher.
- A posted run can't be changed; a mistake is corrected next month or with a journal.
- **Outputs**: printable payslips and the run register (CSV).

### API (under `/api/accounting/payroll`)
`GET /` · `POST employees` · `PUT employees/{id}` · `POST runs {month}` · `GET runs/{id}` · `PUT runs/{id}/payslips/{payslip}` · `POST runs/{id}/payslips` (add a person) · `DELETE runs/{id}/payslips/{payslip}` · `POST runs/{id}/recalculate|submit|approve|reject|return|cancel` · `POST runs/{id}/pay {pay_from_account_id}` · `PUT runs/{id}/references`.

### Permissions
`{level}.accounting.payroll.manage` (Church Treasurer; Regional Treasurer; Diocese Finance Officer, Diocese Treasurer) and `{level}.accounting.payroll.read` (with manage; and Senior Pastor, Regional Overseer, Bishop). Salaries are not in the general read bundle. Paying needs `payments.prepare`. Approving needs no permission - being assigned is what lets someone act.

## A8 - The diocese M-Pesa paybill (built 2026-10-10)

One diocese paybill (Safaricom Daraja C2B) that members of every church pay into, with the church's code as the account number. The diocese holds the money and settles each church monthly, netting the diocese share. "Ask to pay" sends an M-Pesa prompt (STK) to a member's phone.

### Setup (Settings > Paybill, diocese)
- Sandbox or live, the shortcode, the Daraja app's consumer key and secret and the STK passkey (secrets, encrypted), a callback key made for us (part of the callback address, so only Safaricom knows it), whether to accept callbacks only from Safaricom's published addresses, the purpose used when an account number has none (Offering), and whether to SMS a thank-you to the giver.
- **Register the URLs** (diocese finance officer) sends the confirmation and validation addresses to Safaricom (`ResponseType: Completed`, so a payment completes even if we can't be reached). In sandbox, **Simulate a payment** sends a test C2B payment.
- The paybill is a money account in the diocese books (M-Pesa, `1150-xx`, its shortcode as the number), made on first use; it is reconciled monthly like any other with the portal statement (A2) - every receipt carries the M-Pesa code as its reference, so the import matches them.

### The account number
`{place code}{purpose}`: the place's short code without the dash (`SHR027` for CCI-MWD-SHR-027, `SHR` for a region, `MWD` for the diocese), optionally followed by a purpose - `T`/`TITHE` (4000 Tithes), `OFF`/`O` (4010 Offerings; shown as `OFF`, since a lone O reads as a zero), `TH`/`THANKS` (4020 Thanksgiving), `B`/`BLD` (4020, Building fund), `KYS`/`K` (4020, KYS fund). Spaces, dashes and case don't matter; a short number is padded (`SHR27` = `SHR027`).

### Data
- `mpesa_payments`: `trans_id` (the M-Pesa code, unique), `kind` c2b | stk, shortcode, amount, phone (as Safaricom sends it - masked in production), payer name, `bill_ref` (as typed), `paid_at`, the place it was for, the purpose (account + fund), status posted | to_sort | returned, the diocese and place journals, sorted by/at with a note, and the raw payload.
- `mpesa_requests`: each "Ask to pay" (STK) - the place, account number, amount, phone, who asked, Safaricom's checkout id (unique), status pending | paid | failed, the result, the payment it became.
- `payment_events`: every callback as it arrived - provider, kind, whether the key and address checked out, the raw payload, handled | ignored | failed with the error. Nothing is lost and anything can be looked into.
- `paybill_settlements`: a month's settlement for a place - held, share netted, net paid, its remittance (the net) and the share remittance, the netting journals, status prepared | paid | cancelled.
- New standard accounts: **1310 Held by the diocese for us** (asset, the place's side), **2410 Paybill payments to sort** (liability, the diocese's side). 2400 Held for others is the diocese's side of what it holds for places.
- `Remittance` gains kind **settlement** (diocese to a place; confirming it posts Dr bank / Cr 1310).

### Rules
- **A payment** (C2B confirmation, or the STK callback for an "Ask to pay") is recorded once per M-Pesa code - a repeat is acknowledged and ignored; Safaricom always gets `ResultCode 0` back (rejecting would lose a giver's money).
  - **For a place**: diocese books Dr paybill / Cr 2400 Held for others (with the place on the line); the place's books Dr 1310 / Cr the purpose's income and fund, on its budget line - **the church sees its giving the same day**. Both have the M-Pesa code as reference and the payer as party.
  - **For the diocese** (`MWD`): Dr paybill / Cr the purpose's income.
  - **No place matched, or it couldn't be posted** (e.g. that month is closed): Dr paybill / Cr 2410 (when it can be posted) and it waits **To sort**.
- **Sorting** (whoever runs the paybill): give it to a place and purpose (Dr 2410 / Cr 2400 + the place's receipt), make it diocese income (Dr 2410 / Cr income), or mark it returned to the payer (a payment voucher Dr 2410 / Cr paybill, paid as usual).
- **Ask to pay** (the paybill page; a church treasurer for its own church): an STK prompt with the account number filled in. Its callback becomes the payment; a failure or cancel is kept on the request only. If Safaricom also sends the C2B confirmation for it, the M-Pesa code makes it one payment.
- **Settle the month** (whoever runs the paybill): for each place, what the diocese holds for it up to the month's end, less the diocese share it still owes (capped at what is held), is paid to it.
  - The share is netted at once: diocese Dr 2400 / Cr 4100 Church contributions (with the place); place Dr 5700 Diocesan tithe (on its budget line) / Cr 1310; and a **confirmed** share remittance for those months, so Remittances shows it sent.
  - The net is a remittance of kind settlement with a payment voucher (Dr 2400 / Cr the bank) approved and paid as usual; the place confirms it reached its account (Dr bank / Cr 1310) or queries it, as in A6.
  - A settlement not yet paid can be cancelled: the netting is reversed and the voucher cancelled.
- **Month-end close** warns about payments still to sort (diocese) and paybill money held for places not settled for an earlier month.

### API
Public (no sign-in; the callback key is in the path, wrong key = 404): `POST /api/payments/daraja/{key}/confirmation`, `POST /api/payments/daraja/{key}/validation`, `POST /api/payments/daraja/{key}/stk`.
Under `/api/accounting/paybill`: `GET /` (payments, filters) · `GET to-sort` · `POST payments/{id}/sort` · `POST ask` (STK) · `GET requests/{id}` · `GET settlements?month=` · `POST settlements {month, places}` · `POST settlements/{id}/cancel` · `POST setup/register` · `POST setup/simulate` (sandbox) · `GET mine` (a place: its paybill giving, held balance, account numbers).

### Permissions
`diocese.accounting.paybill.manage` (Diocese Finance Officer, Diocese Treasurer): sort, settle, register, ask to pay for anyone. `{level}.accounting.paybill.read` with reading the books (a region or church sees its own paybill giving). A church treasurer asks to pay for their own church with `receipts.create`.

## Later phases (outline - specified when built)
- **A2 Reconciliation:** built 2026-10-09, see "A2 - Reconciliation" above.
- **A3 Sunday collections:** built 2026-10-09, see "A3 - Sunday collections" above.
- **A4 Approvals engine + requisitions:** built 2026-10-09, see "A4 - Approvals and requisitions" above.
- **A5 Procurement by threshold:** built 2026-10-09, see "A5 - Procurement" above.
- **A6 Between levels:** built 2026-10-10, see "A6 - Remittances between levels" above.
- **A7 Payroll:** built 2026-10-10, see "A7 - Payroll" above.
- **A8 The diocese M-Pesa paybill:** built 2026-10-10, see "A8 - The diocese M-Pesa paybill" above.
- **A9 Financial statements:** I&E, financial position, receipts & payments,
  changes in funds, consolidation, year-end close, the audit pack.
- **A10 Church gateways:** Paystack subaccounts, PayHero, churches' own Daraja,
  giving pages.

## Demo data (2026-10-10)
`php artisan db:seed --class=AccountingDemoSeeder` fills **CCI SULTAN HAMUD**'s books from **1 January 2025 to today**, so the pages make sense with data. `--class=AccountingDemoRemoveSeeder` takes it all away again.

**What it records:**
- opening balances, then every Sunday's collection (counted, confirmed, banked, with a little cash kept back);
- monthly bills paid by voucher (Kenya Power, water, internet, cleaning, transport);
- hall hire, two harambees and gifts;
- a repair and a youth camp advance (paid, then accounted for with the change returned);
- a sound mixer bought on an order with three quotes, which appears in Facilities › Equipment;
- three staff paid monthly;
- the diocese's 10% share sent every month and confirmed by the diocese;
- each month counted, reconciled and **closed up to August 2026**.

**Left waiting**, so Approvals has something:
- a voucher;
- a requisition;
- this month's payroll;
- September's share not yet confirmed.

**How it keeps clear of real data:**
- **Through the services:** everything goes through the Accounting services and the approval rules, so every journal balances.
- **No messages:** jobs, mail and notifications are faked, so nobody is texted or emailed.
- **Budgets are untouched:** while it writes, no budget counts as "in use", so nothing is copied into the 2025 or January–March 2026 budgets.
- **The leaders' role start dates** (they were added in August 2026) are moved back while it writes, so the approval rules find them, then put back. They are kept in the church's metadata (`accounting_demo_roles`) first, so even a crash is put right on the next run or removal.
- **Removal:** what it made is recorded as id ranges in the church's metadata (`accounting_demo`), and the remover deletes only those rows at those places.
