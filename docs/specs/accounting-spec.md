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

## Later phases (outline - specified when built)
- **A2 Reconciliation:** cash counts (by notes and coins; a difference needs a
  second person and posts to 5950); bank and M-Pesa reconciliation (statement
  import, auto-match, the standard reconciliation statement, sign-off); petty
  cash imprest; month-end close with a checklist; the trial balance page; the
  reconciliation board for the region and diocese.
- **A3 Sunday collections:** two counters, then receipts per fund; banking with
  a photo of the deposit slip.
- **A4 Approvals engine + requisitions:** the erp-server approval engine ported
  (workflows by amount band, delegation, escalation, SMS and email); requisition
  → PV; staff advances and their retirement. It replaces A1's single
  "authorise" step.
- **A5 Procurement by threshold:** quotes, LPO, GRN, invoice, 3-way match,
  suppliers, the fixed asset register.
- **A6 Between levels:** remittances accrued as Due to / Due from, paid, in
  transit and confirmed; statements and inter-level reconciliation.
- **A7 Payroll:** employees, monthly runs, Kenyan deductions with their rates in
  Settings, payslips.
- **A8 The diocese M-Pesa paybill (Daraja C2B):** the account number is the
  church code + purpose; a unique TransID; a To-sort queue; STK Pay now.
- **A9 Financial statements:** I&E, financial position, receipts & payments,
  changes in funds, consolidation, year-end close, the audit pack.
- **A10 Church gateways:** Paystack subaccounts, PayHero, churches' own Daraja,
  giving pages.
