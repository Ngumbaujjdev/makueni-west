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

### A4 addendum - handing over, and who it went to (2026-10-10)
- **"Away? Hand over"** offers only people **at the same place** who take part in approvals there, and at the place **just above** only the roles the active rules pass work up to (e.g. the Regional Overseer) or who can authorise payments there - never a pastor of another church who sits at the diocese. Each shows role and place. Saving checks the same, and a hand-over to someone no longer allowed at a request's place is ignored when the request is assigned.
- **Sending** a voucher, requisition or payroll for approval says who it waits for: "sent for approval - waiting for Benson Manoo (Senior Pastor)", or that nobody holds the role yet.


## Entry by permission (2026-10-10)
- **Nothing about who records is hard-coded.** Every write action is a permission (e.g. "collections · record", "receipts · create", "accounts · manage", "payments · prepare") that the diocese admin gives to any role in Settings, Access control, Roles & permissions; it applies from the next page load.
- **A page that can't offer its write button says why:** "To do this here you need the *Record collections* permission", who holds it at this place (`GET /api/accounting/holders?permission=`), or which roles come with it - and, for an admin, a link to Roles & permissions.
- **The seeder gives each default once, ever** (`accounting_seeded_grants`): a default the admin took away is never put back by a later run.
- **A collection says which service it was for:** Sunday service, one of the church's own gatherings (Tuesday Fellowship, Kesha...), or Other (typed). Saved as `gathering_type_id` and the title.

## Payee details and detail pages (2026-10-10)
- **Where money goes**, in a shape a treasurer can pay from - the same on suppliers, people on the payroll, requisitions and payment vouchers (`payee` JSON): **M-Pesa** or **Airtel Money** (a phone), a **paybill** (number + account), a **till**, a **bank** (bank, branch, account number, account name) or **cash**. Checked by shape (`App\Support\PayTo`), said in one line ("Paybill 247247, account 0123456789"), shown with the real marks.
- A supplier's details are **copied onto the voucher** that pays its bill; a requisition's onto the voucher that pays it (not for an advance). A person's pay method and "pay to" follow their details. The voucher and requisition show "Pay by" (and in approvals).
- **Paying still happens outside the system** (the bank, M-Pesa): the voucher records it once paid. Sending money from the system is a later step.
- **Detail pages** (`GET /api/accounting/trail/{type}/{id}`) also for a **bill**, a **receipt** (any posted document), a **gift**, a **supplier** and a **person on the payroll**: what happened when, what it is chained to, and a summary (title, number, amount, status, facts, lines). A person's page only for whoever sees the payroll there.

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

## A6b - Paying a share by M-Pesa (built 2026-10-10)

The church's share to the diocese, paid with the M-Pesa prompt from the diocese paybill - sent and confirmed in both books in one step.

- **When:** a share remittance to the diocese that is waiting to be paid, whose voucher is **authorised** (the approval rules still apply - the prompt is only another way to pay it). Whole shillings only - M-Pesa takes no cents; choosing the prompt in "Send share" rounds up.
- **Who:** someone who pays vouchers at the church (`accounting.payments.pay`). The prompt goes to the phone they enter (the church's M-Pesa line, normally).
- **The prompt:** always through the **diocese** paybill (never a church's own paybill - the money is the diocese's), account number `{code}DS` (e.g. `SHR027DS`), for the remittance's amount.
- **Paid** (Safaricom's answer, once per M-Pesa code): the church's voucher is paid - Dr the share's account on its budget line / Cr where it is paid from, reference the M-Pesa code - and the diocese confirms it at once: Dr the diocese paybill / Cr 4100 church contributions. The remittance is **Confirmed**; Remittances shows the month's share sent. Both receipts carry the M-Pesa code, so the statement imports (A2) match them.
- **Not paid** (cancelled, wrong PIN, timed out): nothing changes; it can be prompted again or paid as usual.
- **The voucher changed meanwhile** (cancelled, already paid): the money is kept safe - **To sort** on the diocese paybill with a note - never lost.
- **Typed by hand** to `{code}DS` (no prompt): matched to that church's one waiting share with an authorised voucher of the same amount; anything else waits To sort ("a share payment - match it under Remittances").

API: `POST /api/accounting/remittances/{id}/mpesa {phone}` (followed with `GET paybill/requests/{id}`); remittance rows carry `can.pay_mpesa`.

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

## A10a - Online giving: Paystack and the giving page (built 2026-10-10)

Members give from a public page - `give.php?c=SHR027` - by M-Pesa (the prompt on their phone, through the diocese paybill of A8) or by card / M-Pesa on Paystack's page. Paystack money for a church settles to the church's own bank through a **Paystack subaccount**, with the **diocese share split off at source**. Borrowed from v1-events' Paystack work, without its known faults (webhooks never verified, no row lock, no unique reference, an unscheduled sweep, floats * 100, plaintext keys).

### Setup
- **Settings > Online giving** (diocese): Paystack test or live, its secret and public keys (secrets, encrypted), and whether to SMS/email a giver their receipt.
- **Gateways** (diocese finance officer): per church, a Paystack subaccount - the church's bank (from Paystack's Kenyan bank list, cached a day, one of each), account number and account name typed (Paystack can't look up a Kenyan account name) and which of the church's bank accounts it settles into. It is made on Paystack and stays **off until switched on**. A church without one still takes card gifts: they land in the diocese's Paystack account and are held and settled monthly like the paybill (A8).

### Data
- `payment_channels`: per place and provider - status pending | active | off, the Paystack `subaccount_code`, settlement bank code and name, account number and name, the place's bank account it settles into; (A10b) encrypted credentials and a callback key for PayHero or the place's own Daraja.
- `gifts`: reference `GFT-...` (unique), the place, purpose (account + fund), amount, giver name, phone, email, method mpesa | paystack, provider reference (unique), status pending | paid | failed | abandoned, Paystack's fee, the diocese share split off, net, the journals, the share remittance, the M-Pesa request (STK), paid_at, the raw verification.
- `paystack_settlements`: each Paystack payout recorded once (its id unique) - the place (or the diocese), amount, date, the transfer journal.
- New standard account **1170 Online payments clearing** (asset): Paystack money paid but not yet settled to the bank.

### Rules
- **M-Pesa** on the giving page is "Ask to pay" through the diocese paybill (A8), with the place's account number (`SHR027T`); the gift is paid when that payment is, and posts exactly as A8.
- **Paystack** (card, or M-Pesa on Paystack's page): a pending gift, then Paystack's hosted page. For a church with an active subaccount the payment goes to the subaccount, Paystack's fee is the church's (`bearer: subaccount`), and for a purpose the diocese share is charged on (e.g. tithe: 10% of tithes) that share is `transaction_charge` to the diocese.
- **Completing a gift** - the signed webhook (`charge.success`; HMAC-SHA512 of the body with the secret key), the return from Paystack's page, or the sweep - always verifies with Paystack, checks the amount and currency, and completes it once (the gift row locked, its status checked again):
  - church with a subaccount: church Dr 1170 (net) + Dr 5800 Bank charges (fee) + Dr the share's account, e.g. 5700 (the split, on its budget line) / Cr the purpose's income (gross, on its budget line); diocese Dr 1170 / Cr 4100 Church contributions (the split, with the church on the line); a **confirmed** share remittance, so Remittances shows it sent.
  - no subaccount: diocese Dr 1170 / Cr 2400 held for the church (what arrived, after Paystack's fee); church Dr 1310 (the same) + Dr 5800 Bank charges (the fee) / Cr the purpose's income (gross) - settled monthly with the paybill money (A8).
- **The sweep** (`payments:reconcile`, every 10 minutes): verifies Paystack gifts still pending after 10 minutes and completes them the same way; a gift pending for a day is abandoned.
- **Settlements** (`payments:settlements`, daily): each Paystack payout - a church's subaccount into its chosen bank account, the diocese's own into its bank - is posted once as a transfer Dr bank / Cr 1170.
- **The giver** gets an SMS (and an email when given) with the receipt number, from the church's sender.

### Pages
- Public `give.php?c={code}`: the church, purposes, amount, name, phone (and email for a card), "Pay with M-Pesa" or "Card or M-Pesa on Paystack"; a "check your phone" wait for M-Pesa; `give-thanks.php` after Paystack.
- `giving.php` (every level): the place's gifts, its giving link (to copy and share) and how its Paystack is set up.
- `gateways.php` (diocese): the churches' Paystack subaccounts - add, switch on/off - and the latest settlements.

### API
Public: `GET /api/give/{code}` · `POST /api/give/{code}` · `GET /api/give/status/{reference}` · `GET /api/give/callback?reference=` (redirects to the thanks page) · `POST /api/payments/paystack/webhook`.
Under `/api/accounting`: `GET giving` · `GET gateways` · `GET gateways/banks` · `POST gateways/channels` · `PUT gateways/channels/{id}` (switch on/off, settles into).

### Permissions
`{level}.accounting.giving.read` with reading the books; `diocese.accounting.gateways.manage` (Diocese Finance Officer, Diocese Treasurer).

### A10a addendum - who is giving (2026-10-10)
- The giving page asks for a **full name** and a **phone** every time (M-Pesa: the number the prompt goes to; card: for the SMS receipt) and an **email for card** (Paystack's receipt - never a made-up address). The server refuses a gift without them.
- It shows the real M-Pesa, Visa and Mastercard marks, a one-line summary of the gift before "Give", and can remember the giver's details on their own device. A church without a logo shows the CCI logo. The thanks page shows how it was paid.

## A10b - A church's own paybill: PayHero or its own Daraja (built 2026-10-10)

A church that has its own Safaricom paybill or till (from Safaricom, or a bank paybill) can take M-Pesa straight into its own books - no diocese holding, no monthly settlement - through **PayHero** (the paybill linked in its PayHero account as a payment channel) or its **own Daraja app**. The diocese finance officer sets it up on Gateways; the giving page then uses it for M-Pesa.

### Setup (Gateways, diocese finance officer)
- **PayHero**: the church's PayHero API username and password, and the payment channel id of its paybill/till (PayHero, Payment Channels). Stored encrypted on the church's channel.
- **Own Daraja**: the church's Daraja app - consumer key and secret, its paybill shortcode, the Lipa na M-Pesa passkey, sandbox or live. Stored encrypted; a callback key is made for it; **Register the addresses** sends them to Safaricom.
- Either way, the M-Pesa money account it lands in: one of the church's M-Pesa accounts, or a new one made for it (`1150-xx`, the paybill as its number). Off until switched on.

### Rules
- **The giving page** sends the M-Pesa prompt through the church's own channel when it has one switched on - its own Daraja first, then PayHero - and through the diocese paybill (A8) otherwise.
- **PayHero**: `POST https://backend.payhero.co.ke/api/v2/payments` (Basic auth; amount, phone, channel id, provider m-pesa, our gift reference as `external_reference`, our callback address). Its callback (`/api/payments/payhero/{key}`) is not signed, so the gift is completed only after asking PayHero for that payment's status, and once per M-Pesa code. A gift still waiting is checked by the 10-minute sweep the same way.
- **Own Daraja**: the same C2B (`/api/payments/daraja/{key}/confirmation`) and STK (`.../stk`) as A8, with the church's own key. A payment is recorded once per M-Pesa code and posted **straight into the church's books**: Dr its M-Pesa account / Cr the purpose's income and fund, on its budget line. The account number only needs the purpose (`T`, `OFF`, `BLD`...; the church's code in front is allowed); anything else counts as the default purpose. The church sends its share through Remittances (A6) as usual.
- PayHero money lands in the church's own paybill too, so a paid PayHero gift posts the same way (Dr its M-Pesa account / Cr income) - PayHero's charges come from its service wallet, not the gift.
- Payments typed straight into a PayHero-linked paybill (not through the giving page) are brought in with the monthly M-Pesa statement import (A2), as for any M-Pesa account.
- **Paybill or till**: the channel says which. A till takes no account number, so the giving page and the Paybill page show only the number; the page still asks what each gift is for.
- **PayHero's answer**: the status lookup (`GET /api/v2/transaction-status?reference=`) is QUEUED, SUCCESS or FAILED; only SUCCESS is paid, its M-Pesa code is PayHero's `provider_reference`, and the amount must match the prompt. Every callback is kept raw in `payment_events`.
- **The sweep** (`payments:reconcile`) runs even where Paystack isn't set up, so a PayHero gift is never left waiting.
- **The diocese Paybill page** leaves out a church's own-paybill payments - they are its own business - except one that couldn't be posted (e.g. a closed month) and waits To sort; sorting it can only put it into that church's books.
- **Keys** go in on Gateways and never come back out - the page only says each one is saved. A key left blank on a change is kept.

### Data
- `payment_channels` (A10a) holds the PayHero / Daraja credentials (encrypted) and callback key; `settles_into_id` is the church's M-Pesa account.
- `mpesa_payments.channel_id` and `mpesa_requests.channel_id`: whose paybill (null = the diocese paybill).

### API
Public: `POST /api/payments/payhero/{key}`; the Daraja routes of A8 take a church channel's key too.
Under `/api/accounting`: `POST gateways/channels` takes `provider` paystack | payhero | daraja with its fields; `POST gateways/channels/{id}/register` (own Daraja).

## A10c - Getting paid: payout details, payouts per place, refunds (built 2026-10-10)

How each place gets its card money, and seeing it arrive - borrowed from v1-events' client "Getting paid" and Settlements screens, but stored in the books instead of read live.

### Getting paid (the church asks, the diocese checks)
- On its **Online giving** page (tab "Getting paid") a place's treasurer (`accounting.accounts.manage` there) sees **how it gets paid**: card gifts - its own Paystack subaccount (settled to its bank) or held by the diocese and settled monthly; M-Pesa - its own paybill (A10b) or the diocese paybill.
- The treasurer **asks** for its own Paystack: the bank (Paystack's list), account number, account name exactly as the bank has it, and which of its bank accounts in the books it lands in. The request waits **"Being checked"**; it can be withdrawn while it waits.
- Changing the details of a subaccount already on is a new request; card gifts keep going to the old account until the diocese approves the change.
- The diocese finance officer sees the requests on **Gateways** (tab "Requests"): the details in full, **Approve** (Paystack makes - or updates - the subaccount; optionally switched on at once) or **Send back** with a reason the church sees. Paystack can't look up a Kenyan account name, so this check is by eye, like v1-events' "Mark as checked".
- The diocese can still set a church up itself on Gateways (A10a).

### Payouts
- Each Paystack payout (settlement) is recorded once with its status - pending, processing, success, failed - its gross, fees and net. A successful payout posts Dr the place's bank / Cr 1170 (A10a); a failed one is kept and flagged, never posted.
- **Which gifts it paid:** Paystack doesn't list the payments in a subaccount's payout, so they are matched by day (Lagos time): the unpaid gifts of the day two days before, else one, else three, else a run of up to five days (a weekend). Exact = **"Adds up"**; otherwise the nearest day is taken as **"Closest match"**. The church's part of a gift (after the fee and the diocese share) is matched to its payouts; the diocese's part (the share, a held gift, its own gifts) to the diocese's main-account payouts.
- **Payouts tab** on Online giving, every level: paid to our bank this year, the last payout, **on the way** (paid gifts not yet in a payout), the average; each payout with the days it covered, its amount, status and receipt in the books; **a window per payout** - its gifts, gross less Paystack's fee less the diocese share = to the bank. A place without its own Paystack sees the diocese's monthly settlements (A8) there instead.
- **Gateways, tab "Payouts":** every place - how it is paid, payouts and amount paid to its bank in the period, the diocese share split off, the last payout, on the way - each opening that place's payouts.

### Refunds and disputes (Paystack webhooks, signed)
- `refund.processed` for a whole gift: the gift's journals are reversed (both books; the budget entries go with them), a split-off share's remittance is cancelled (so Remittances shows it owed again), and the gift is **Refunded**. Once only.
- A part refund is recorded on the gift and flagged to adjust with a journal - it is never guessed.
- `charge.dispute.create` marks the gift **Disputed**; `charge.dispute.resolve` lost (merchant-accepted) is handled as a whole refund; won clears the mark.
- **Month-end warnings:** a failed Paystack payout, a gift still disputed, a part refund not adjusted.

### Data
- `payment_channels`: `request` (the details asked for, JSON), `requested_by/at`, `review_note`, `checked_by/at`; status `pending` = asked for, not made yet.
- `paystack_settlements`: `status`, `gross`, `fees`, `covers_from`, `covers_to`, `matched` (adds_up | closest | none).
- `gifts`: `settlement_id` (the place's payout), `main_settlement_id` (the diocese's), `refunded_amount`, `refunded_at`, `disputed_at`; status `refunded`.

### API (under /api/accounting)
`GET giving/payouts`, `GET giving/payouts/{id}`, `GET giving/payout-options`, `POST|DELETE giving/payout-request`; `POST gateways/channels/{id}/review` {decision: approve|return, note, switch_on}; `GET gateways/payouts?from&to`.

## A10d - When the answer doesn't come back, and testing on a computer (built 2026-10-10)

- **Asking M-Pesa:** a prompt (a gift, Ask to pay, a share paid by M-Pesa) whose callback hasn't come is asked about - Safaricom's STK query (or PayHero's status for a PayHero channel) - from the giving page's wait (after 25 seconds), the "waiting for the phone" check, and `payments:reconcile` (every 10 minutes: prompts older than 2 minutes; given up after an hour). At most once every 15 seconds a prompt.
  - Paid: recorded once, marked `Q-{checkout id}` until the callback brings the real M-Pesa code, which then replaces it on the payment, its journals, its voucher and remittance (A6b) and the gift - nothing is posted twice.
  - Refused, cancelled or timed out: kept on the prompt (and the gift). Still at the PIN ("being processed"), or Safaricom busy: it waits.
  - **2026-10-10 fix:** Safaricom also answers the query with `ResultCode 4999` "The transaction is still under processing" - that is still at the PIN, so it waits (it had been taken as refused and failed a gift after 44 seconds). A refusal is kept in plain words, from the query or the callback: 1032 "You cancelled the prompt.", 1037 "Your phone couldn't be reached - is it on?", 2001 "Wrong M-Pesa PIN.", 1 "Not enough M-Pesa balance."; any other code keeps Safaricom's text.
  - A paid prompt on the giving page goes to `give-thanks.php?ref=` - the giver's receipt (A10), the same as after Paystack.
- **Testing on a computer the internet can't reach:** a tunnel (`cloudflared tunnel --url http://localhost:8004`), then `php artisan payments:dev-tunnel {https address}` - points the sandbox paybill's callbacks at it, registers them with Safaricom, and shows the Paystack webhook address to set in Paystack's test dashboard. Refuses a live paybill. Without a tunnel, prompts still complete by asking.

## A10e - Transactions: every attempt to pay (built 2026-10-10)

One list of every attempt to pay, the way v1-events shows its transactions - failed ones included:
- **What's in it:** gifts from the giving page (M-Pesa or Paystack, whatever became of them), M-Pesa prompts that aren't gifts (Ask to pay, a share paid by M-Pesa), and paybill payments typed by hand. Each with when, who paid (name, phone, email), what for and where, how (M-Pesa or card, and the route - diocese paybill, own paybill, Paystack), the amount and fee, where it stands (paid, waiting, not paid, abandoned, refunded, to sort, returned), **why it failed**, the M-Pesa code or Paystack reference, and its receipt in the books.
- **Who sees what:** a church its own; a region itself and its churches; the diocese everything (and the paybill payments nobody's account number matched), with a place picker. Reading it needs the books (`accounting.transactions.read`).
- **The page** (Accounting, after Online giving): Paid, Went through (% of the finished ones), Not paid, Waiting; a period switch (Today, 7 days, 30 days, this month, this year); status and method pills, search (payer, phone, reference, M-Pesa code); a warning when payments have waited more than 10 minutes.
- **One transaction:** the payment, where the money went (gross, Paystack's fee, the diocese share, to the church), who paid, and **what happened when** - started, prompt sent, every answer from Safaricom/Paystack/PayHero as it arrived (`payment_events`), paid or not paid with the reason, the receipts in the books, a dispute or refund.
- **Check now / Check the waiting ones:** asks Paystack or M-Pesa again (`Giving::complete`, `Paybill::checkPrompt`), for whoever writes receipts there or runs the diocese paybill.

API (under `/api/accounting`): `GET transactions?from&to&status&method&source&q&place_id&page&per`, `GET transactions/{gift|prompt|paybill}/{id}`, `POST transactions/{source}/{id}/check`, `POST transactions/check-waiting`.

## A10f - "I paid by Pay Bill": checking a payment with Safaricom (built 2026-10-10)

- **The giving page has three choices:** the M-Pesa prompt, **Pay Bill** (yourself, from the M-Pesa menu) and card. Pay Bill shows the business number (or till) and the **account number for the purpose picked** (e.g. `SHR001OFF`) with Copy buttons and the phone steps, then **"I've paid - confirm it"**: the M-Pesa code, full name and the number paid from. The amount isn't asked - Safaricom's answer carries it.
- **Checking a code** (`POST /api/give/{code}/claim`, 5 a minute per address; `GET /api/give/claim/{id}?code=` - the code must match):
  - A code we already have (its callback came) is answered at once with its receipt.
  - Otherwise Safaricom is asked - Daraja **Transaction Status** (`/mpesa/transactionstatus/v1/query`, initiator + security credential). The answer comes to `/api/payments/daraja/{key}/status-result` (or `/status-timeout`), logged in `payment_events`.
  - A **completed** payment **into our paybill** with that code is recorded once (unique M-Pesa code) through the paybill's normal recording, for the place and purpose claimed - into both books like any paybill payment. Not found, not completed, or paid elsewhere: the claim says why.
  - Until an API operator is set (or when Safaricom can't be reached), the claim **waits for the treasurer** - shown on Transactions as "Claimed - to check", where it can be asked again.
- **The treasurer**: "Check an M-Pesa code" on Transactions (`POST /api/accounting/transactions/check-code {code, purpose, place_id?}`), the same check.
- **Lost callbacks** - `payments:pull` (hourly): Daraja **Pull Transactions** fetches the paybill's payments of the last 3 hours and records any we don't have (account-number matching as usual; the rest waits To sort). Live paybill only, once Safaricom approves Pull; `--register` does the one-time registration with the paybill's nominated phone.
- **Settings, Paybill, "Checking payments with Safaricom"**: the API operator's name, its **security credential** (generated on the Daraja portal) - or its password with Safaricom's certificate uploaded to `storage/app/daraja/{sandbox|production}.cer` - and the paybill's nominated phone. C2B registration falls back to v2 when v1 refuses the app.
- `payment_claims`: code, place, purpose, giver, amount, status (checking | waiting | confirmed | failed), result, Safaricom's conversation ids, the payment it became.

## A9 - Financial statements and the year-end close (built 2026-10-10)

**The statements** (`App\Services\Accounting\Statements`) are read straight from the journal lines, for one place's books or - **consolidated** - for a region or the diocese with every place below it:

| Kind | What it shows | Ties to |
|---|---|---|
| `ie` - Income and expenditure | Each income and expense account for a period, a column per fund, beside the same period last year; the surplus per fund | the funds statement's surplus |
| `position` - Financial position | What we own, what we owe, and each fund's balance (its own account plus income less spending not yet closed into it), at a date and a year earlier | net assets = total funds |
| `receipts-payments` - Receipts and payments | Cash, bank and M-Pesa at the start; for every document that moved money through one, its other lines (credits received, debits paid); at the end. Money moved between our own accounts is neither | start + received - paid = end |
| `funds` - Changes in funds | Each fund: at the start, income, spending, transfers & opening entries (postings straight to a fund's account), at the end | the position's funds |
| `trial-balance` | Every account at a date; `before_close` leaves out the closing journal of that date's year | debits = credits |

- **Income and expenditure never counts a closing journal** (or its reversal): closing moves the surplus into the funds; it isn't income or spending. Balances (position, funds, trial balance) count everything.
- **Consolidated - eliminations.** A line naming another place of the set (`for_territory_id`) is left out: the share sent up (5700 / 5710 at the church, 4100 at the place above), support sent down (the expense, 4110), and paybill money the diocese holds for a church (2400 at the diocese, 1310 at the church). The paying side of a remittance's voucher now names where it went (the receiving side always did); a migration tagged the past ones.
  - **Money on its way** - paid by one place, not yet confirmed by the other - is one line, "Money on its way between places" (a payment line in receipts and payments), so the statements still balance.
  - A place's own accounts (its bank, its M-Pesa) show under their standard header when consolidated.
  - Income and expenditure lists what was taken out ("eliminated").
- **Who:** any statement for whoever reads the place's books; **consolidated** for whoever reads the books below (`accounting.below.read`) - not a church.

**The year-end close** (`App\Services\Accounting\Years`, `accounting_years`):
- A year closes when it has ended, **every month from its first posting to December is closed**, and the year before (if it had postings) is closed.
- Closing posts one **closing journal** (`doc_type` `closing`, number `.../YEC/2025/000001`, `source_type` `accounting_year`) dated 31 December: each income and expense account emptied per fund, each fund's net into its own account (General 3000, Building 3100, KYS 3200, Conference 3300). It is posted into the closed December - the one journal that may be.
- **Reopen** - the level above, with a reason (`accounting.periods.reopen`, as for months): the closing journal is reversed on 31 December; the months stay closed. Later closed years are reopened first.
- A month of a closed year can't be reopened alone ("reopen the year first"); the closing journal can't be reversed by hand.
- Who closes: `accounting.periods.close` at the place.

**API** (under `/api/accounting`, `?territory_id=` for a place below):
- `GET statements/{ie|position|receipts-payments|funds|trial-balance}?from&to | at &consolidated=1 &before_close=1` - `from` defaults to 1 January of `to`'s year, `to` and `at` to today. Every reply carries `place`, `consolidated`, `places` (how many added together), `can.consolidate` and `year_closed`.
- `GET years` - `{place, can: {close, reopen}, years: [{year, status, surplus, closing_journal: {id, number}, closed_by, closed_at, reopened_at, reopen_reason, blockers: [..]}]}`.
- `POST years/{year}/close`; `POST years/{year}/reopen {reason}`.

**Reports** (PDF / Excel, inputs `dates` + `consolidated`, grouped `statements`): `accounting.statement.ie`, `.position` (at `date_to`), `.receipts-payments`, `.funds`, and **`accounting.statement.audit-pack`** - behind a cover, all four statements, the trial balance before the close, each month's close and each money account's last signed-off reconciliation, with signature boxes for the treasurer, chairperson and auditor. `accounting.trial_balance` gains `consolidated` and `before_close`.

**Menu:** Finance > Accounting > **Statements** (`statements.php`, after Month-end close), permission `{level}.accounting.statements.read` for whoever reads the books.

## Later phases (outline - specified when built)
- **A2 Reconciliation:** built 2026-10-09, see "A2 - Reconciliation" above.
- **A3 Sunday collections:** built 2026-10-09, see "A3 - Sunday collections" above.
- **A4 Approvals engine + requisitions:** built 2026-10-09, see "A4 - Approvals and requisitions" above.
- **A5 Procurement by threshold:** built 2026-10-09, see "A5 - Procurement" above.
- **A6 Between levels:** built 2026-10-10, see "A6 - Remittances between levels" above.
- **A7 Payroll:** built 2026-10-10, see "A7 - Payroll" above.
- **A8 The diocese M-Pesa paybill:** built 2026-10-10, see "A8 - The diocese M-Pesa paybill" above.
- **A9 Financial statements:** built 2026-10-10, see "A9 - Financial statements and the year-end close" above.
- **A10 Church gateways:** built 2026-10-10, see "A10a", "A10b" and "A10c" above.

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

## Redesign (2026-10-10)
The user found the pages bare. The redesign ships in five parts:
- **R0:** the shared look.
- **R1:** record pages with a journey.
- **R2:** an Approvals board.
- **R3:** accounts, and how they link to Budgets.
- **R4:** documents and reports on the diocese PDF engine (Settings › Documents & PDF).
- **R5:** a page-by-page polish.

**R0, the shared look:**
- **Amounts are always written in full:** `KES 2,225,662.00`, never "2.2m" or "37k". `AccountingUI.short()` now returns the full amount.
- **Card figures** come from `AccountingUI.figure()`: a small "KES" and the number in tabular figures. A card's sparkline sits under its figure.
- **Tables** have more padding, amounts on one line, and a row that opens the record.
- **View windows** get a hero strip: the amount, where it stands, and three facts. Each part is its own panel on a soft background.
- **The journey** (`AccountingUI.journey(steps)`) shows a record's steps as a lane: done green, now gold (pulsing), next grey, stopped red, blocked (nobody holds the role) dashed.
  - `approvalSteps(approval)` turns the approval engine's stages into steps.
  - `nextCard()` says what happens next in plain words. It is solid gold when it is the viewer's turn.
  - The voucher window uses all three: Prepared → each approval stage → Paid → In the books.

**R1, record pages:**
- **The page.** `record.php?type=voucher|requisition|payroll|order|remittance|collection&id=` is one page for every record that moves through steps. It shows:
  - the hero: kind, number, what it is for, the amount in full, the status and four facts;
  - "Where it stands": the journey plus "what happens next", with the button to act;
  - its lines (what it pays for, payslips, the items ordered, the months, what was given);
  - in the side column: Linked documents, Papers, and What happened.
- **Journeys:**
  - **Voucher:** prepared → each approval stage → paid → in the books.
  - **Requisition:** asked → stages → quotes → ordered → received → billed → paid (a purchase); → paid (a payment); → advance given → accounted for (an advance).
  - **Payroll:** started → submitted → stages → paid.
  - **Order:** requisition → ordered → received → billed → paid.
  - **Remittance:** owed → voucher → sent → confirmed (or queried).
  - **Collection:** counted → confirmed → in the books → banked.
- **Lists:** rows on Payment vouchers, Requisitions, Payroll, Procurement, Remittances and Collections open the record page. The `?voucher=` and other links still open the window. A step is taken in the same window the list uses; requisition decisions are made right on the page.
- **`GET accounting/trail/{type}/{id}`** (`App\Services\Accounting\Trail`) returns two things:
  - `events`, newest first, each with `at`, `who`, `text`, `icon`, `tone` and `note`. They come from the record's own who/when columns, the approval engine's events and decisions (the columns are left out when the engine ran), deliveries, bills, payments and files added. A plain date counts as the end of that day.
  - `links`: the documents chained to it, each with `type`, `id`, `label`, `number`, `status`, `status_label`, `amount`, `date` and `opens`.
  Whoever may read the books where the record sits can see it; a remittance can be seen from either side. Anyone else gets 404.

**R2: approvals board, account pages, lighter windows:**
- **Approvals:**
  - **"Who holds what":** a lane per person waited on, with the last seven days and each waiting request as a block from the day it was asked to today. Blocks are coloured by kind and show Your turn, Waiting, Overdue or Stuck. On a phone it becomes a list per person.
  - **The tab lists as an activity feed:** "Stephen Mutisya asks KES 12,000.00 for …". The oldest item waiting on you is a solid gold "Do first" card. Approve (with an optional note) and Send back work right in the list.
  - **"This month":** waiting, approved, sent back or rejected, and the average time to decide.
  - **"Recent activity".**
  - **Opening a request** goes to its record page, which now has Approve / Send back / Reject for vouchers and payroll too (through the approval engine). `?request=` links from the bell and SMS go there as well.
  - **New `GET approvals/board?territory_id=`** returns `open[]` (each with `record` {type, id} and `overdue`), `stats` and `activity[]` (built by `Trail::approvalSentence`). Every approval in the inbox now carries `record`.
- **Accounts:**
  - **`account.php?id=`:** each account's own page. It shows:
    - the balance in full;
    - money in and out this month (against last month) and this year, plus a 12-month chart;
    - the 15 latest movements, each opening its document;
    - the last reconciliation;
    - the budget lines that post to it, with planned and actual when a budget is in use.
  - "In" means whatever makes the account bigger: Spent for an expense, Received for income.
  - **`GET accounting/accounts/{id}`** (`Books::accountPage`). Cash & bank cards, chart rows and the Overview's "Where the money is" open it.
  - **Chart pill counts** follow "Only accounts with money".
- **Windows:** a white header with a coloured top line and icon; calmer text; colour only on the key data (amount by direction, status, people as initials, dates, accounts). Record pages follow the same rules.
- **Lists:**
  - The status column on Payment vouchers, Requisitions, Payroll, Procurement, Remittances and Collections is now a compact journey (`AccountingUI.mini`).
  - Payroll months get a month tile.
  - The remittance record page offers "Pay by M-Pesa" when the share can be paid that way (A6b).

**R2b: real payment logos, money in by channel, Airtel Money:**
- **Logos.** M-Pesa, Airtel and Visa/Mastercard are the official marks from Wikimedia Commons, kept in `assets/images/payments/` and checked to contain no scripts or links. They are shown through `AccountingUI.methodLogo(method, size)` and the `methodChip` pill. M-Pesa and Airtel Money accounts show their logo as their tile.
- **Airtel Money is a real method and account kind:**
  - `airtel` in `Journal::METHODS`, `BudgetEntry::METHODS`, `METHOD_RULE` and `AccountingAccount::CASH_KINDS`;
  - the standard header `1160 Airtel Money accounts`; a place adds its number like an M-Pesa account (1160-01, ...);
  - the migration `2026_10_27_100000_add_airtel_money` widens the enum columns. **Run it before this code goes live:** `Chart::ensureStandard` writes the new header on the next Accounting request.
- **Money in by channel** (`overview.channels`, `Books::channels`):
  - Receipts' lines into money accounts, split by channel: cash (incl. petty cash), M-Pesa, Airtel Money, bank, and card (`journal.method = card`). A Sunday collection splits into its cash and M-Pesa parts.
  - Each channel has this month, last month, this year, and six months for a sparkline. A channel shows when it has money this year or an account.
- **Document rows** (the Overview's Latest documents, and Receipts, Journals and All documents through `doc-list.js`):
  - a date tile and "n days ago";
  - the number with a solid type pill;
  - who, as initials;
  - How, with the logo;
  - the amount with its direction arrow;
  - Posted/Reversed and papers pills.
  
  A row whose journal comes from a voucher, collection or payroll opens that record's page.

**R2c: the navy band back; the account page as a statement:**
- **Windows:** the user rejected R2's white header with a coloured top line ("we don't allow border tops"). Accounting windows keep the navy band like every other window. The calm body and coloured key data stay (see CLAUDE.md › Accounting windows).
- **`account.php`:**
  - **Hero:** M-Pesa and Airtel accounts show their logo.
  - **"This year" cards:** they get a sparkline and a net / out-of-in bar, so all four cards end level.
  - **Beside the chart:** the right column is Budget lines plus "This year at a glance" (net, monthly averages, biggest month, movements this year, the last one), filling the height.
  - **"Money in and out" is a statement:**
    - grouped by day with the day's in and out;
    - each movement shows its direction, "Money in from … / Money out to …", the number, a type pill and the method logo, and what it was against;
    - the signed amount, and the balance after it;
    - All / in / out pills.
  - **Rows open** the voucher, collection or payroll page, or the journal.
- **`accounts/{id}` movements** add `direction`, `balance_after` (worked back from today's balance), `method`, `method_label`, `source`, `source_id`, `status`, `attachments`, `against` [{name, cash_kind}] and `year_count`.

**R4, every Accounting document and report as a diocese PDF:**
- **The engine.** All Accounting PDFs go through the report engine (`app/Reports/Accounting/*`, module `accounting`) and `DioceseReportPdf`: Settings › Documents & PDF letterhead, logo, QR verification code and page numbers. Every hand-made `window.print` / `document.write` pop-up is gone, except the owner's remittance statement for a place below. `ReportData` gains three optional fields:
  - `cover`: a cover page with the place's own logo, converted from WebP;
  - `signatures`: boxes to sign, with the footer saying "Signed copies are kept with the books";
  - `orientation`.
- **Params.** `ReportController` accepts `account_id`, `record_id` and `date_from` / `date_to` for reports whose inputs are `account` / `record` / `dates`. `Report::checkParams()` refuses a record or account that isn't the place's (422). `AccountingReport` reads for whoever may read the books (`AccountingAccess::canRead`).
- **Books:**
  - Cashbook (`accounting.cashbook`): a cover page, landscape, b/f, movements, c/f, signatures;
  - Trial balance (`accounting.trial_balance`): by kind, and whether it balances.
- **Registers:** receipts, payment vouchers, collections (church), remittances, each for a date range.
- **Documents** (locked, from their page):
  - official receipt;
  - payment voucher (lines, approvals and history, four signatures);
  - LPO;
  - payslips;
  - payroll register (no statutory deductions);
  - remittance advice;
  - collection sheet (with notes and coins counted);
  - bank reconciliation statement.
- **Reports page** (`reports.php`, menu after All documents, `accounting.reports.read` with the books' readers). Its groups:
  - Books and Registers cards, each with an account, a date range and Download PDF;
  - Documents, saying where each is downloaded from;
  - Statements (income & expenditure, balance sheet, funds, audit pack), shown as "coming with the year-end statements" (A9, makueni-west-6b's API).
- **Buttons:** `AccountingUI.pdf(key, params, title)` opens the export window locked to the report. It is used by the Cashbook, the record pages' hero, the journal and voucher windows, Procurement, Payroll, Collections and Reconcile.
- **Deploy:** run `AccountingAccessSeeder` (new page and permission) and `php artisan queue:restart`, so the reports worker loads the new report classes.

**R4b: sorting and filters everywhere; PDFs on the rows; exports never stall:**
- **`AccountingUI.tableKit(o)`.** It gives a table pills with counts, a search, a Sort menu and sortable headers, in place, with the state in the URL (a prefix keeps two tables on one page apart). It's used on:
  - Payroll (runs, people);
  - Procurement (to order, bills, suppliers);
  - Remittances (sent, received);
  - Requisitions (advances);
  - Reconciliation (history);
  - Cash & bank (chart);
  - Chart of accounts;
  - Cashbook (brought and carried forward now sit outside the table);
  - Journals (trial balance).

  Approvals gets kind pills, a search and a sort on its feed; the account page gets a search and a sort on its statement. The other lists already used `PeopleKit.listTable`.
- **`AccountingUI.pdfButton()`** puts a PDF button on the row itself:
  - receipts, and the voucher behind a payment, on Overview and documents;
  - payment vouchers;
  - payroll runs (payslips);
  - procurement orders (LPO);
  - collections (sheet);
  - remittances (advice);
  - reconciliations (statement).
- **Reports page › "My recent PDFs"** lists what the person exported from Accounting, from `GET reports/runs`, ready to download again.
- **`POST reports/runs/{uuid}/now`.** It builds a run that has waited at least 10 s (your own run, still queued) in the request, and the queued copy then does nothing. The export window offers "Build it now" after 15 s when no worker has picked the run up.

**A10 - the giver's receipt (2026-10-10):**
- The thanks page (`give-thanks.php`) shows the official receipt once the gift is paid. It contains:
  - received from (first name), for, paid by (with the M-Pesa or card logo and the M-Pesa code), date and references;
  - the lines and the total;
  - Download (PDF), Print, and Give again.
- `GET give/status/{reference}` adds, for a paid gift: `receipt_url`, `paid_at`, `mpesa_code` (the paybill code, or Paystack's `receipt_number` for M-Pesa through Paystack), `giver`, `phone` (masked) and `lines`.
- `GET give/receipt/{reference}` (public, 30 a minute) returns the receipt as a diocese PDF (`GiftReceipt`), built straight away because the giver has no login. A gift that isn't paid gets 404.
- The SMS ends with "Receipt: {frontend}/give-thanks?ref=…", and the email links to it too.
