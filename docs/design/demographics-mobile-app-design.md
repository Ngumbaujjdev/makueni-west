# Demographics Mobile PWA — Design Reference

**Status: reference only. Not being built now.** Saved here verbatim (the original Claude Design prompt) because it's a rich, concrete product spec — real field lists, a real approval workflow, real screen flows across all four roles — that's directly useful for designing the Demographics *backend* now, even though the actual PWA build is deferred to later. See `../ROADMAP.md` for how this feeds into current priorities.

## Why this matters now, even though the PWA itself isn't being built

This prompt is the most concrete statement of what Demographics data actually needs to capture and how it flows through the org hierarchy. It should directly inform `../specs/demographics-module-spec.md`'s Data Model and Permission Rules — see that file for the reconciled version. Three things from this design changed decisions made earlier:

1. **Field list**: replaces the earlier guessed field set (age brackets, households, weddings/funerals) with what's actually specified here — total members, youth (13–35), women's/men's fellowship, Sunday school, seniors, gender split, new members, transfers out, baptisms, communion participants, conversions.
2. **Approval workflow, not pure read-only**: Subregion Overseer reviews a church's submission and can Approve / Flag / Request changes (screen 17, `subregion-church-review.html`) — a real status-transition action, not just a passive summary view. Region and Diocese remain summary/analytics-only (no review action at those levels in this design).
3. **Multi-tenancy**: the login flow (screen 3) has a "Diocese code" field, explicitly built so one app can serve multiple dioceses. This directly informed the decision (see `../ROADMAP.md` → Multi-Tenancy) that the backend should become genuinely multi-diocese-capable — not just single-diocese with unused scaffolding for it.

## Original prompt (verbatim)

> Paste everything below the line into Claude Design. It is scoped to **one vertical slice** —
> the Church Activity & Demographics module — shown across all four user roles
> (Pastor → Subregion Overseer → Regional Overseer → Diocese/Bishop). Once this slice is
> approved, reuse the exact same design system for the next slices (Financial/Tithe,
> Monthly Reporting, Events).

Build a complete, multi-screen **MOBILE app** mockup for the **Makueni West Diocese
Management System** — a church administration platform for **Christian Church
International (CCI)**. This is the **native mobile app** (phone form factor) and this
mockup covers **only the Church Activity & Demographics module**, shown end-to-end
across the four organizational roles.

The full system is a hierarchy: **Diocese → Region → Subregion → Church**. Demographic
data is entered at the Church level by the Pastor, then flows upward — reviewed at
Subregion, consolidated at Region, and analysed diocese-wide by the Bishop's office.
This mockup should make that upward flow visible and tangible.

### OUTPUT FORMAT

Create **SEPARATE, LINKED .html files** — one screen per file — each rendered inside a
**mobile phone frame** (roughly 390px wide × 844px tall, rounded corners, subtle bezel,
status bar strip at top with time / signal / battery). All files share a common
`styles.css` and `app.js`. Use real `<a href="filename.html">` links so the whole
prototype is click-through-able in a browser by opening `splash.html`.

Because this is mobile, DO NOT use a desktop sidebar. Use:
- A **bottom tab bar** (fixed, 4–5 tabs, icons + short labels, active tab in CCI red).
- A **top app bar** (56px: back/menu icon left, screen title, notification bell + avatar right).
- A **floating action button (FAB)** in CCI red for the primary "add / update" action.
- **Bottom sheets** (slide up from bottom) instead of centre modals for actions/forms.
- **Segmented controls** for in-screen tab switching (e.g. Overview / History).

LOOK & FEEL REFERENCE: model the feel on the **mySafaricom** and **KPLC** apps — a home
screen that offers *a lot* of things but still feels **very simple**. That means: a clean
white home with a **grid of colourful rounded service tiles** (icon in a soft tinted chip
+ short label), one **hero card at the top** (like the M-PESA balance card — here it shows
the church's key number / this-month status + a primary action), and **short, shallow
flows** (tap a tile → one simple form → confirm → toast → back). No dense tables, no clutter,
big friendly numbers, plenty of white space. Every counting task (youth, tithe, income)
should feel like a 2–3 tap job, not a data-entry chore.

### BRANDING & DESIGN SYSTEM (derived from the CCI logo)

The CCI logo is a blue globe with a red cross, a white dove, and gold light-glints, with
"C.C.I — Christian Church International" in red beneath. Use the logo file (`CCI-Logo.png`)
in the splash screen and the top-left of the app bar. Pull the palette from it:

Font: **Inter** (Google Fonts) for UI. Optionally **Plus Jakarta Sans** for large headings.

- Primary red (cross / brand / primary actions): `#D81F2A`
- Sky blue (globe / secondary / info): `#2CA6DF`
- Gold / amber (divine light / highlights, warnings): `#F4A81D`
- Deep navy (dark surfaces, app bar on dark screens, headings): `#0D2440`
- Success green: `#2ECC71`
- Warning amber: `#F39C12`
- Critical / overdue red: `#E74C3C` (or reuse brand red)
- Background: `#F6F8FB`
- Card white: `#FFFFFF`
- Text primary: `#1A2233`
- Text secondary: `#6B7280`
- Border: `#E8ECF2`

Tone: warm, trustworthy, faith-appropriate — not a cold corporate dashboard. Rounded
cards (16px radius), generous touch targets (min 44px), soft shadows, calm spacing.
Think a well-designed banking or health app, adapted for a church context.

Status badge colours (consistent across every screen):
- green = compliant / submitted / approved / on-track
- amber = pending / due soon / in-review
- red = overdue / missing / flagged
- gray = not started / draft

> **Note on this palette vs. the production system**: this mockup's colors (`#D81F2A` red, `#2CA6DF` sky blue, `#F4A81D` gold, `#0D2440` navy) are close to but not identical to the production `diocese-teal`/`diocese-gold`/`diocese-red`/`diocese-black` tokens already in `assets/css/styles.css` (see root `CLAUDE.md` → Design Rules). Reconcile these when the PWA is actually built — don't treat this mockup's exact hex values as final without checking against the production tokens first.

### FILE LIST TO CREATE (22 screens + 2 shared files)

**SHARED:**
- `styles.css` (phone frame, app bar, bottom tabs, cards, stat tiles, badges, bars, donut/score circles via conic-gradient, sparklines, bottom sheets, toasts, forms, segmented controls, FAB)
- `app.js` (mock data, `showToast()`, bottom-sheet open/close, segmented-control toggle, live score recalculation helper, active-tab highlighting per screen)

**AUTH / SHELL (no bottom tabs):**
1. `splash.html` — CCI logo splash → auto/tap to role-select
2. `role-select.html` — choose your role: Pastor · Subregion · Region · Diocese/Bishop → login
3. `login.html` — mobile login: DIOCESE CODE + email/phone + password; routes to the chosen role's dashboard

**PASTOR / CHURCH role — bottom tabs: Home · Demographics · Finance · Reports · More**
4. `pastor-dashboard.html` (mySafaricom-style home: hero card + colourful tile grid)
5. `pastor-demographics.html` (overview: current counts, trends, history entry point)
6. `pastor-demographics-entry.html` (the monthly counting form — the core screen)
7. `pastor-spiritual-activities.html` (record baptisms / conversions / communion / transfers)
8. `pastor-demographics-history.html` (past submissions + month-over-month)

FINANCE & COUNTING screens (the "small counting things" — tithe, income, expenses, budget):
9. `pastor-finance.html` (finance home: this-month money summary + tiles: Tithe / Income / Expenses / Budget)
10. `pastor-tithe.html` (auto-calculated diocesan tithe + pay + status)
11. `pastor-income.html` (record weekly/monthly income — offerings, collections, etc.)
12. `pastor-expenses.html` (record church expenses)
13. `pastor-budget.html` (budget vs actual, utilization bars)
14. `pastor-report-submit.html` (submit monthly report — demographics + finance — + success state)

**SUBREGION OVERSEER — bottom tabs: Home · Churches · Analytics · Reports · More**
15. `subregion-dashboard.html` (rollup + submission compliance + churches needing attention)
16. `subregion-church-list.html` (churches with submission status)
17. `subregion-church-review.html` (review one church's demographics; approve / flag / request-fix)
18. `subregion-analytics.html` (compare churches in the subregion)

**REGIONAL OVERSEER — bottom tabs: Home · Subregions · Analytics · More**
19. `region-dashboard.html` (region rollup + subregion breakdown)
20. `region-compare.html` (compare subregions on demographics)

**DIOCESE / BISHOP — bottom tabs: Home · Regions · Analytics · More**
21. `diocese-dashboard.html` (diocese-wide demographics summary + region comparison)
22. `diocese-demographics-analytics.html` (breakdown charts, region heatmap, growth forecast)

### DETAILED SCREEN SPECIFICATIONS

**1. `splash.html`** — Deep-navy (`#0D2440`) full screen, subtle dot-grid. Centred CCI logo, "Makueni West Diocese" title, "Christian Church International" subtitle in gold, small loading dots. Tap anywhere → `role-select.html`.

**2. `role-select.html`** — Light background. Header "Who are you signing in as?". Four stacked role cards, each with icon, role name, one-line description, chevron. Tapping a card carries the chosen role through to login (query string, e.g. `login.html?role=pastor`):
- 🏫 Pastor / Church Admin — "Manage your church's demographics & reports"
- 🧭 Subregion Overseer — "Review churches in your subregion"
- 🗺️ Regional Overseer — "Oversee subregions in your region"
- ✝️ Diocese / Bishop's Office — "Diocese-wide oversight & analytics"

**3. `login.html`** — CCI logo top. Heading adapts to the chosen role, e.g. "Sign in as Pastor". Fields:
- **Diocese code** (FIRST field — this makes the app multi-tenant; a church/user belongs to a diocese). Short uppercase input, e.g. "MW-DIO" with helper text "Enter your diocese code" and a small "?" that explains where to find it. On a valid code, briefly show the diocese name + CCI logo confirmation (e.g. "✓ Makueni West Diocese") so the user knows they're in the right place.
- Email or phone field.
- Password field with show/hide eye toggle.
- "Remember me" checkbox + "Forgot password?" link.
- Full-width red "Sign in" button → on tap: brief spinner → `showToast("Welcome back!", "success")` → redirect to the chosen role's dashboard after ~800ms.
- Gray demo helper text below (e.g. "Demo: MW-DIO · pastor@cci.org · demo123").
- "← Back" to `role-select.html` (lets them pick a different role).

ROLE ROUTING (the login button reads the `?role=` value and redirects accordingly): `pastor → pastor-dashboard.html`, `subregion → subregion-dashboard.html`, `region → region-dashboard.html`, `diocese → diocese-dashboard.html`. The bottom-tab set on every authenticated screen also matches the role. Keep the diocese code visible (small chip in the app bar, e.g. "MW-DIO") on authenticated screens so the tenant context is always clear.

**4. `pastor-dashboard.html`** (mySafaricom-style HOME) — App bar: CCI logo left, church name centre, notification bell + avatar right, greeting + date. HERO CARD (navy/red, like M-PESA balance): the ONE key number for the month (Total members) + status pill ("Report due in 4 days") + primary button "Submit monthly report" → `pastor-report-submit.html`. SERVICE TILE GRID (3 across, colourful tinted chips): Demographics, Update counts, Baptisms, Tithe, Income, Expenses, Budget, Members (toast "Coming soon" if unbuilt), Reports. "This month at a glance" strip: 3–4 tiny stat pills. Bottom tabs active = Home.

**5. `pastor-demographics.html`** — Segmented control: [Overview] [History]. OVERVIEW: current counts list (Total members, Youth 13–35, Women's fellowship, Men's fellowship, Sunday school, Seniors), age-distribution bars, gender-split donut, month-vs-month deltas, compliance card (last submission, next deadline). Big red button: "Update this month's data" → `pastor-demographics-entry.html`. Bottom tabs active = Demographics.

**6. `pastor-demographics-entry.html`** (CORE SCREEN) — Monthly data-entry form. Month/year selector. Section "Membership counts": Total members, Youth group, Women's fellowship, Men's fellowship, Sunday school enrollment (number steppers). Section "This month's changes": New members added, Members transferred out. Section "Spiritual activities": Baptisms performed, Communion participants, New conversions. Live validation (e.g. "Youth count can't exceed total members"), completeness bar. Sticky bottom bar: [Save draft] (outline) + [Submit for approval] (red) → confirm sheet → toast "Demographics submitted for approval" → redirect to `pastor-demographics.html`.

**7. `pastor-spiritual-activities.html`** — Segments: Baptisms · Communion · Conversions · Transfers. Each: list of recorded entries (date + count + notes) + "＋ Add" bottom sheet (date, count, notes; transfers also have from/to church + reason).

**8. `pastor-demographics-history.html`** — List of past monthly submissions (month, total members, status badge). Tap to expand full numbers + month-over-month deltas. 12-month trend chart (CSS bars) at top.

**9. `pastor-finance.html`** (finance HOME) — Hero money card: this-month income/expenses/balance + tithe status pill. 4 tiles: Tithe, Record income, Record expense, Budget. Recent transactions list (last 4). Bottom tabs active = Finance.

**10. `pastor-tithe.html`** — Big card: diocesan tithe due, auto-calculated as a set % (e.g. 10%) of reported monthly income, with the math shown ("10% of KES 84,500 income"). Status pill (Paid/Pending/Overdue). "Pay tithe now" → confirm sheet (amount + method: M-PESA/Bank) → toast → status flips to Paid. Tithe history list below.

**11. `pastor-income.html`** — This month's income total. "＋ Record income" sheet (amount, source dropdown, date, note) → updates total + list, toast. List of entries. 4-week mini bar chart.

**12. `pastor-expenses.html`** — Mirror of income for outgoings (category dropdown: Utilities, Maintenance, Pastoral support, Ministry/programs, Other).

**13. `pastor-budget.html`** — Overall utilization card (% + progress bar, green/amber/red thresholds). Per-category rows: budgeted vs. spent, usage bar, remaining amount. Optional "Set budget" sheet.

**14. `pastor-report-submit.html`** — Compiled demographics + finance summary + completeness check. "Submit to Subregion Overseer" → success state (report ID, "Your subregion overseer will review it").

**15. `subregion-dashboard.html`** — Rollup tiles (total members, youth, baptisms this month, churches reporting X/Y). Submission-compliance donut. "Needs attention" list (overdue/flagged churches, red-bordered, "Review" → `subregion-church-review.html`). "Recent submissions" list.

**16. `subregion-church-list.html`** — Search + filter chips (All/Submitted/Pending/Overdue). List: name, pastor, total members, last submission date, status badge → `subregion-church-review.html`.

**17. `subregion-church-review.html`** — Church header card. Submitted numbers with month-over-month deltas + validation flags (e.g. "Youth up 40% — unusually high"). Sticky action bar: [Request changes] (outline) · [Flag] (amber) · [Approve] (green). Approve → toast "Approved & forwarded to region". Request changes → note sheet → toast "Sent back to pastor".

**18. `subregion-analytics.html`** — Bar charts: total members per church, youth participation rate per church. "Fastest growing"/"Declining" leaderboards. Baptism totals per church.

**19. `region-dashboard.html`** — Region rollup tiles. Subregion breakdown list (total members, submission %, trend arrow) → `region-compare.html`. Submission-compliance donut across subregions.

**20. `region-compare.html`** — Bar charts: members per subregion, youth participation rate per subregion. List: subregion, churches count, avg growth, trend arrow. "Best practice" highlight card.

**21. `diocese-dashboard.html`** — Diocese-wide tiles (total members, youth, fellowships, Sunday school, baptisms, conversions this month). 12-month growth trend. Region comparison bars. Compliance card. → `diocese-demographics-analytics.html`.

**22. `diocese-demographics-analytics.html`** — Period segments [This Month] [This Quarter] [This Year]. Summary tiles. Demographic breakdown donut. Baptism trend line (12 months). Region comparison (youth participation, fellowship ratios, Sunday school rate). Region heatmap (growth-coded). Growth forecast card. "Export report (PDF)" → toast.

### SHARED COMPONENTS

`.phone-frame` · `.status-bar` · `.app-bar` · `.bottom-tabs` · `.tab` (`.tab.active`) · `.fab` · `.stat-tile` · `.metric-number` · `.delta-up`/`.delta-down` · `.card` · `.badge` (`.badge-green`/`.badge-amber`/`.badge-red`/`.badge-gray`) · `.bar-chart`/`.bar-fill` · `.score-circle` (conic-gradient) · `.donut` · `.sparkline` · `.segmented`/`.segment.active` · `.bottom-sheet`/`.sheet-backdrop` · `.toast` (success/error/warning/info) · `.number-stepper` · `.list-row` · `.empty-state` · `.btn-primary` (red) · `.btn-outline` · `.btn-success` (green) · `.btn-danger`

### MOCK DATA

- Diocese: "Makueni West Diocese" (CCI). 3 regions, ~6 subregions, ~12 churches.
- Realistic Kamba/Kenyan church + place names (Kwa Kathoka CCI, Mbooni CCI, Kilungu CCI, Wote Central CCI, Kithembe CCI, Nunguni CCI…).
- Per church: total members, youth, women's/men's fellowship, Sunday school, seniors, baptisms this month, conversions, communion participants, new members, transfers, submission status, last submission date, 12-month trend arrays.
- `mockNotifications`: 4–5 (deadline reminders, approvals, flags, messages from region).
- Child totals sum to parent totals (internally consistent rollups).

### TECHNICAL REQUIREMENTS

Pure HTML/CSS/JS, no frameworks/build step/React. Google Fonts (Inter) via `<link>`. Real `<a href>` navigation. All charts pure CSS (bars, conic-gradient donuts) or inline SVG sparklines — no Chart.js/D3/canvas. Bottom sheets, toasts, segmented controls, number steppers, live score recalculation: vanilla JS. Every interactive element does something — no dead ends. Sized for phone (~390px), centred on a light page. Polished, warm, modern — banking/health-app quality, not a Bootstrap admin template. Subtle 0.2s transitions. Consistent status colours (green/amber/red/gray) everywhere.
