# YNEX Component Reference

The repo root carries 172 static HTML files from the YNEX Bootstrap 5 admin template — demo dashboards, component galleries, and full app-page mockups. Per `CLAUDE.md`'s "Building New UI Components" process, these stay the markup source for new pages; this doc is the lookup so that "check the template first" means opening one file, not scanning 172.

Compiled 2026-09-16 by reading the 11 dashboard variants, the 10 most relevant component-gallery pages, and the 10 most relevant realistic app pages (~30 of the 172 files). Not exhaustive — if a pattern you need isn't listed here, it's still worth checking `cards.html`/`widgets.html` directly before building something new.

## Pattern → file lookup

| Pattern | Where to look |
|---|---|
| Icon-avatar KPI card (icon + number + trend) | `index-1.html`, `index-3.html` (compact 6-across), `index-4/7/8/10.html`, `widgets.html` |
| Hero number + delta + sparkline | `index-6.html`, `crypto-currency-exchange.html` |
| Segmented time-range switcher above a chart | `index-2/4/8/9/10.html` (`btn-group`) |
| Stacked avatar group ("+N" overflow) | `index-5/7/11.html`, `avatars.html` (`avatar-list-stacked`) |
| Progress-bar table cell (label + bar + %) | `index-6/7/10.html` |
| Segmented multi-color progress bar + breakdown list | `index-3.html` "Acquisitions" — most complete example |
| Two-segment stacked bar (e.g. allocated vs. spent) | `crypto-marketcap.html` (`progress-stacked`) |
| Donut chart + legend or footer stat | `index-5/6/8.html` |
| Dated timeline / event list | `index-5/8/11.html`, `timeline.html` |
| Ranked/leaderboard list | `index-1/2/4/5/10.html`, `listgroup.html` (numbered + trailing badge) |
| Comparison table with up/down arrows | `index-2/4/9.html`, `crypto-marketcap.html` |
| Tabbed content switcher (tabs vs. pills — distinct visually) | `navs_tabs.html` (full gallery), `index-1/2/5/9.html` (in context) |
| Vertical icon-pills settings layout | `navs_tabs.html` "Vertical Tab Style-1" |
| Kanban board (columns + cards + modal) | `task-kanban-board.html`, `crm-deals.html` |
| Record detail page (metadata strip + activity feed + sidebar) | `projects-overview.html` |
| KPI sidebar for a financial module | `invoice-list.html` |
| Card-footer pagination | `index-1/2/3/6/8/9.html` |
| Initials avatar / online-status dot | `avatars.html` |
| Soft/transparent status badge (vivid, not washed-out) | `badge.html` |

## Standout full-page references for this project's modules

- **`invoice-list.html`** — a KPI sidebar (icon + count-up number + trend delta, 4 stacked rows) plus a client/amount/status-badge/due-date table. Best match in the template for a **Budget module** summary + transactions page.
- **`projects-overview.html`** — a detail card with a metadata strip (manager/dates/assignees/status badges), a comment/activity timeline, and a sidebar (team list, checklist, documents). Strong template for a **church/region detail page**.
- **`task-kanban-board.html`** — full 5-column kanban with rich task cards and an "Add Task" modal. Directly reusable for a **demographics-submission or budget-approval review board**.

## The 11 dashboard demos (`index-1.html` … `index-11.html`)

Each is the same component kit, themed differently. Page content in every file starts at `<!-- Start::app-content -->`; major widget groups are bounded by `<!-- Start::row-N -->` / `<!-- End::row-N -->` comments — the fastest way to jump to a section.

| File | Theme | Standout patterns |
|---|---|---|
| `index-1.html` | Ecommerce | Icon-avatar KPI card w/ trend sentence; tabbed order table; ranked "Top Countries" list |
| `index-2.html` | Crypto | Coin card w/ inline sparkline; key/value stat list; comparison table w/ sparkline + arrow |
| `index-3.html` | Jobs | Dense 6-across KPI strip; stat strip w/ vertical dividers; segmented progress bar + breakdown list |
| `index-4.html` | NFT | Hero card w/ illustration; rich auction/listing card; leaderboard w/ progress bar + value |
| `index-5.html` | Sales | Category-icon transaction list; dated activity timeline; donut + legend list |
| `index-6.html` | Analytics | Mini stat card w/ inline sparkline; comparison table w/ progress-bar column |
| `index-7.html` | Projects | Per-row mini radial-progress list; "Projects Summary" row (avatars + fraction + progress bar + badge + date) |
| `index-8.html` | HRM | Donut + colored-legend footer; "Upcoming Events" day-block timeline; inline dropdown status selector |
| `index-9.html` | Stocks | Nav-pills + stat strip combo in one card header; watchlist w/ favorite toggle; textbook up/down comparison table |
| `index-10.html` | Courses | Icon-category shortcut grid; KPI card w/ colored "View All →" link; progress-bar table cell |
| `index-11.html` | Personal | Progress-toward-goal card; 2×2 dashed-divider stat grid; circular-progress profile avatar |

## Component-gallery pages

| File | Shows | Best variants |
|---|---|---|
| `widgets.html` | 8 rows of stat/KPI widgets — most relevant gallery for a dashboard | Icon+title+value+trend w/ single progress bar + "% of target"; 4-metric strip w/ dashed dividers |
| `cards.html` | 13 rows of base card layouts | Solid background-color cards (`card-bg-primary`…), colored-border cards — both flat, compliant |
| `badge.html` | Square, pill, soft/transparent, gradient, outline, positioned badges | `bg-{color}-transparent` soft badges (vivid, matches this app's existing status badges); plain dot badge for online/active status |
| `avatars.html` | Shapes, sizes, status dots, initials, stacked groups | Initials avatars (no photo needed); stacked group w/ "+N" overflow |
| `progress.html` | 7 rows of progress-bar variants | Stacked multi-segment bars; "Custom Progress-1" (titled bar, value badge on the end); "Custom Progress-5" (bordered breakdown panel) |
| `timeline.html` | 1 vertical activity/audit-feed layout | Date column + dot marker + avatar + text + badge, "Load More" footer |
| `ratings.html` | Star-rating widgets only — **not** progress-bar breakdowns | Low relevance; no review/rating concept in this app yet |
| `listgroup.html` | 5 rows of list-group variants | Solid-colored + contextual-tint rows; numbered list w/ trailing badge; checkbox/radio lists |
| `navs_tabs.html` | 13 rows — tabs and pills, visually distinct | Nav-tabs (underline/boxed, incl. header/footer-embedded); nav-pills (rounded, incl. w/ count badge); "Vertical Tab Style-1" full settings-page layout |

## Realistic app pages (beyond the top 3 above)

| File | What it's a template for | Notes |
|---|---|---|
| `crm-deals.html` | Sales pipeline | 6-stage progress row + 6-column kanban — adapts to a budget-approval or submission-status board |
| `crypto-marketcap.html` | Comparison/leaderboard page | Two-segment stacked bar per row; comparison table w/ rank, sparkline, trend arrow |
| `crypto-currency-exchange.html` | Hero-number cards | 8 identical big-number + delta + sparkline cards; hero banner confirmed flat color (not a gradient) in `styles.css` |
| `data-tables.html` | DataTables.js integration | Search/sort/paginate/export — filter/export toolbar is JS-rendered at runtime, not static markup |
| `tables.html`, `grid-tables.html`, `reviews.html` | Lower relevance | `tables.html`: trend-arrow cell + stacked-avatar-in-table worth lifting, rest is raw Bootstrap swatches. `grid-tables.html`: JS-rendered mounts, nothing static. `reviews.html`: testimonial cards, not a rating breakdown — skip. |

## Design-rule flags

`CLAUDE.md`'s Design Rules forbid gradients and muted/washed-out text on UI elements. These specific spots break that — copy around them, not from them:

| File | What to avoid | Rule broken |
|---|---|---|
| `widgets.html` | Row 5 — colored top-border stripe used with no informational meaning | "Card accents carry data, not decoration" |
| `badge.html` | Gradient badge row (square + pill) | No gradients on UI elements |
| `progress.html` | Gradient Progress row + Custom Progress-4 | No gradients on UI elements |
| `listgroup.html` | One "List with badges" example (`bg-info-gradient`) | No gradients on UI elements |
| `cards.html` | Decorative folder-illustration SVG w/ embedded `linearGradient` fills | Stock art, not a UI gradient class — low risk, but skip as a reference |

Everywhere else surveyed — all 11 dashboards, `avatars.html`, `timeline.html`, `ratings.html`, and every table/kanban/detail page — used solid Bootstrap color classes throughout, and `text-muted` only on secondary captions (timestamps, emails, helper text), never on primary numbers or body copy. That secondary-only discipline is worth carrying forward deliberately when reusing any of these, not just avoiding the gradients above.
