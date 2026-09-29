/**
 * ============================================================================
 * UI HELPERS - DEMOGRAPHICS & ATTENDANCE
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Small library of pure render/DOM helpers shared by every Demographics and
 * Attendance page, so page-specific JS files stay focused on their own
 * logic instead of each re-implementing badges/stat-cards/loading states.
 *
 * Design rule (root CLAUDE.md): no muted/washed-out text - captions here
 * use `text-body fw-semibold`, never `text-muted`, deliberately deviating
 * from that one detail of the Budget module's precedent.
 *
 * Dependencies: none (pure functions + DOM), Toast (assets/js/utils/toast.js)
 * for callers, not used internally here.
 * ============================================================================
 */

const DemographicsUI = (function () {
  "use strict";

  // ==========================================================================
  // USER TERRITORY RESOLUTION
  //
  // The PHP-rendered USER_TERRITORY comes from $_SESSION['current_role'],
  // populated by authentication/ajax/sync-session.php - a fire-and-forget
  // call from login.js that silently continues even if it fails (only a
  // console.warn, no visible error). When that sync hasn't landed yet, the
  // PHP session's territory_id is empty even though the *correct* data was
  // already written to localStorage by login.js before it ever attempted
  // the PHP sync. Rather than trust the PHP round-trip, fall back to
  // localStorage directly instead of showing an error or waiting on
  // auth-helpers.js's next 30s-throttled background refresh.
  // ==========================================================================

  function resolveUserTerritory(phpTerritory) {
    if (phpTerritory && phpTerritory.id) {
      return phpTerritory;
    }

    try {
      const cached = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (!cached) return phpTerritory;

      const id = cached.territory_id ?? cached.territory?.id ?? null;
      const name = cached.territory_name ?? cached.territory?.name ?? phpTerritory?.name;

      if (id) {
        return { id, name };
      }
    } catch (e) {
      console.warn("Could not resolve territory from localStorage fallback", e);
    }

    return phpTerritory;
  }

  // ==========================================================================
  // STATUS BADGES
  // ==========================================================================

  const STATUS_BADGES = {
    draft: { color: "secondary", label: "Draft", icon: "ri-draft-line" },
    submitted: { color: "primary", label: "Submitted", icon: "ri-send-plane-line" },
    approved: { color: "success", label: "Approved", icon: "ri-checkbox-circle-line" },
    flagged: { color: "warning", label: "Flagged", icon: "ri-flag-line", textDark: true },
    changes_requested: { color: "danger", label: "Changes Requested", icon: "ri-edit-line" },
    not_submitted: { color: "secondary", label: "Not Submitted", icon: "ri-close-circle-line" },
  };

  /** Shared status -> {color, icon, label} lookup, reused by renderStatusBadge() and the Recent Submissions row avatar so both stay in sync off one map. */
  function statusMeta(status) {
    return STATUS_BADGES[status] || { color: "secondary", label: status || "Unknown", icon: "ri-question-line" };
  }

  function renderStatusBadge(status) {
    const cfg = statusMeta(status);
    const cls = `bg-${cfg.color}${cfg.textDark ? " text-dark" : ""}`;
    return `<span class="badge ${cls}"><i class="${cfg.icon} me-1"></i>${cfg.label}</span>`;
  }

  // ==========================================================================
  // STAT CARDS
  // ==========================================================================

  // Stat cards use two designs taken from the YNEX template's own
  // dashboards (the template is this app's markup source - see CLAUDE.md):
  //   - compact card: Jobs dashboard (html/index-3.html "TOTAL EMPLOYERS"):
  //     solid icon tile left, value + uppercase label, trend top-right.
  //   - tile card: Courses dashboard (html/index-10.html "YTD Earnings"):
  //     larger icon tile, value, label + trend on one line, optional link.
  // Icons are RemixIcon (the template demos use SVG/Tabler), and the
  // label drops the template's op-7 fade (CLAUDE.md: no washed-out text).

  function iconTextClass(color) {
    return color === "secondary" || color === "warning" ? "text-dark" : "text-white";
  }

  /** {diff}|{direction, percent}|null -> the template's coloured trend text. */
  function trendMarkup(trend) {
    if (!trend) return "";
    if (trend.text) {
      return `<span class="fw-semibold text-${trend.color} text-nowrap"><i class="${trend.icon} me-1 align-middle"></i>${trend.text}</span>`;
    }
    return "";
  }

  function renderCompactCard({ icon, label, value, color = "primary", trend = null, caption = "" }) {
    return `
      <div class="card custom-card">
        <div class="card-body">
          <div class="d-flex align-items-top">
            <div class="me-3">
              <span class="avatar avatar-md bg-${color} ${iconTextClass(color)}"><i class="${icon} fs-20"></i></span>
            </div>
            <div class="flex-fill" style="min-width: 0;">
              <div class="d-flex mb-1 align-items-top justify-content-between gap-2">
                <h5 class="fw-semibold mb-0 lh-1 fs-20 text-break">${value}</h5>
                ${trendMarkup(trend)}
              </div>
              <p class="mb-0 fs-11 text-muted fw-semibold text-uppercase">${label}</p>
              ${caption ? `<p class="mb-0 mt-1 fs-12 text-muted">${caption}</p>` : ""}
            </div>
          </div>
        </div>
      </div>`;
  }

  function renderTileCard({ icon, label, value, color = "primary", trend = null, sublabel = "", link = null }) {
    return `
      <div class="card custom-card">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-top gap-2">
            <div class="me-1">
              <span class="avatar avatar-lg bg-${color} ${iconTextClass(color)}"><i class="${icon} fs-20"></i></span>
            </div>
            <div class="flex-fill" style="min-width: 0;">
              <h5 class="d-block fw-semibold fs-18 mb-1">${value}</h5>
              <div class="d-flex justify-content-between align-items-center gap-2">
                <div class="text-muted fs-12">${label}</div>
                ${trendMarkup(trend)}
              </div>
              ${sublabel ? `<div class="fs-12 fw-semibold mt-1">${sublabel}</div>` : ""}
              ${link ? `<a href="${link.href}" class="text-primary fs-12 fw-semibold">${link.text}<i class="ri-arrow-right-line ms-1 align-middle"></i></a>` : ""}
            </div>
          </div>
        </div>
      </div>`;
  }

  /**
   * @param {object} opts {icon, label, value, trend, color}
   *   trend: optional plain-text caption (e.g. "12 this month").
   */
  /**
   * trend: optional plain-text caption. delta: optional periodDelta() result
   * - shown as a coloured change top-right with its "vs last month" caption.
   */
  function renderStatCard({ icon, label, value, trend = null, color = "primary", delta = null }) {
    if (delta) {
      return renderCompactCard({ icon, label, value, color, trend: deltaToTrend(delta), caption: trend || delta.caption });
    }
    return renderCompactCard({ icon, label, value, color, caption: trend || "" });
  }

  /**
   * Renders a full `row g-3` of stat cards into a container - the shared
   * wrapper every attendance/gathering-types list page uses instead of
   * each hand-rolling its own `col-xl-3` grid markup.
   * @param {string} containerId
   * @param {object[]} cards - array of renderStatCard() opts
   */
  /**
   * A row of stat cards, all in the same Analytics layout (renderSparkCard)
   * so they read as one set at equal height - with a sparkline where the
   * card has a `series`, and its delta/`trend` text in the foot.
   */
  function renderStatCardsRow(containerId, cards) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.classList.add("stat-cards-row");
    container.innerHTML = cards
      .map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${renderSparkCard({ ...c, sub: c.sub || c.trend || "" })}</div>`)
      .join("");
    mountSparklines(container);
  }

  // ==========================================================================
  // PERIOD ANALYSIS - "this month vs last month" deltas and monthly
  // sparkline series, computed client-side from records the page has
  // already loaded (GET /attendance and GET /demographics return every
  // record for the church, so no extra request is needed).
  // ==========================================================================

  const MONTH_SHORT = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

  function monthIndex(date) {
    return date.getFullYear() * 12 + date.getMonth();
  }

  /** Rows falling in the month `offset` months before now (0 = this month). */
  function rowsInMonth(rows, offset = 0, dateField = "service_date", now = new Date()) {
    const target = monthIndex(now) - offset;
    return rows.filter((r) => {
      const d = new Date(r[dateField]);
      return !isNaN(d) && monthIndex(d) === target;
    });
  }

  function monthLabel(offset = 0, now = new Date()) {
    const idx = monthIndex(now) - offset;
    return MONTH_SHORT[((idx % 12) + 12) % 12];
  }

  /**
   * Last `months` calendar months (oldest first), each summed with
   * `value(row)` (default: count). Returns {labels, data}.
   */
  function monthlySeries(rows, { dateField = "service_date", value = () => 1, months = 6, now = new Date() } = {}) {
    const end = monthIndex(now);
    const labels = [];
    const data = [];
    for (let k = end - months + 1; k <= end; k++) {
      labels.push(MONTH_SHORT[((k % 12) + 12) % 12]);
      data.push(0);
    }
    rows.forEach((r) => {
      const d = new Date(r[dateField]);
      if (isNaN(d)) return;
      const pos = monthIndex(d) - (end - months + 1);
      if (pos >= 0 && pos < months) data[pos] += value(r);
    });
    return { labels, data };
  }

  /**
   * {dir: up|down|flat, text, caption} comparing two period values, or null
   * when there's nothing to compare against. percent=false shows the raw
   * difference (better for small counts like "3 gatherings").
   */
  function periodDelta(current, previous, { percent = true, prevLabel = "last month" } = {}) {
    if (previous == null || current == null) return null;
    const caption = `vs ${prevLabel}`;
    if (current === previous) return { dir: "flat", text: "No change", caption };
    if (previous === 0) return { dir: "up", text: "New", caption };
    const diff = current - previous;
    const text = percent ? `${Math.abs(Math.round((diff / previous) * 100))}%` : `${Math.abs(diff)}`;
    return { dir: diff > 0 ? "up" : "down", text: `${diff > 0 ? "+" : "-"}${text}`, caption };
  }

  function deltaToTrend(delta) {
    if (!delta) return null;
    const map = {
      up: { color: "success", icon: "ri-arrow-up-s-fill" },
      down: { color: "danger", icon: "ri-arrow-down-s-fill" },
      flat: { color: "primary", icon: "ri-subtract-line" },
    };
    return { text: delta.text, ...map[delta.dir] };
  }

  /** Solid delta pill + caption, e.g. [▲ +12%] vs Aug. */
  function deltaBadge(delta) {
    if (!delta) return "";
    const t = deltaToTrend(delta);
    return `<span class="stat-delta is-${delta.dir}"><i class="${t.icon}"></i>${delta.text}</span><span class="stat-delta-caption">${delta.caption}</span>`;
  }

  /**
   * Analytics-style card (template html/index-6.html "Total Users" card):
   * label + solid icon tile, big value, solid delta pill with caption, and
   * a sparkline of recent periods along the bottom edge. Call
   * mountSparklines(container) after inserting the markup.
   * @param {object} opts {icon, label, value, color, delta, series: {labels, data}, sub}
   */
  function renderSparkCard({ icon, label, value, color = "primary", delta = null, series = null, sub = "", link = null }) {
    // CRM dashboard KPI card (template html/index.php "Total Customers"):
    // icon circle, label + value, a small sparkline on the right, and a
    // footer with "View ... ->" on the left and the change on the right.
    const spark = series
      ? `<div class="kpi-spark" data-spark='${JSON.stringify(series).replace(/'/g, "&#39;")}' data-spark-color="${color}" data-spark-height="36"></div>`
      : "";
    const isNumber = /^[-\d.,:%\sKES]+$|^\d+ of \d+$/.test(String(value));
    const deltaHtml = delta
      ? `<span class="kpi-delta is-${delta.dir}"><i class="ri-arrow-${delta.dir === "down" ? "down" : delta.dir === "up" ? "up" : "right"}-${delta.dir === "flat" ? "line" : "s-fill"}"></i>${delta.text}</span>`
      : "";
    const captionParts = [delta ? delta.caption : "", sub].filter(Boolean).join(" · ");
    const linkHtml = link
      ? `<a href="${link.href}" class="kpi-link text-${color === "secondary" || color === "warning" ? "dark" : color}">${link.text}<i class="ri-arrow-right-line ms-1"></i></a>`
      : "";
    // Compact: [icon] label ........ change
    //          value                 sparkline
    //          caption / link
    return `
      <div class="card custom-card kpi-card2">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2">
            <span class="kpi-icon bg-${color} ${iconTextClass(color)}"><i class="${icon}"></i></span>
            <span class="kpi-label flex-fill">${label}</span>
            ${deltaHtml}
          </div>
          <div class="d-flex align-items-end justify-content-between gap-2 mt-2">
            <div style="min-width: 0;">
              <div class="kpi-value${isNumber ? "" : " is-text"}">${value}</div>
              ${captionParts ? `<div class="kpi-caption">${captionParts}</div>` : ""}
              ${linkHtml}
            </div>
            ${spark}
          </div>
        </div>
      </div>`;
  }

  function cssColor(name, alpha = 1) {
    const rgb = getComputedStyle(document.documentElement).getPropertyValue(`--${name}-rgb`).trim();
    // Plain rgb() at full opacity - an rgba() colour makes ApexCharts ignore
    // its own fill opacity (area/sparkline fills rendered fully solid).
    if (!rgb) return name;
    return alpha === 1 ? `rgb(${rgb})` : `rgba(${rgb}, ${alpha})`;
  }

  /** Any rgb()/hex colour with the given alpha. */
  function withAlpha(color, alpha) {
    const c = String(color || "");
    if (c.startsWith("#")) return hexToRgba(c, alpha);
    const m = c.match(/rgba?\(([^)]+)\)/);
    if (!m) return c;
    const [r, g, b] = m[1].split(",").map((x) => x.trim());
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  /** Renders every not-yet-mounted [data-spark] element inside root. */
  function mountSparklines(root = document) {
    if (typeof ApexCharts === "undefined") return;
    root.querySelectorAll("[data-spark]:not([data-spark-mounted])").forEach((el) => {
      el.setAttribute("data-spark-mounted", "1");
      let series;
      try {
        series = JSON.parse(el.getAttribute("data-spark"));
      } catch (e) {
        return;
      }
      const color = cssColor(el.getAttribute("data-spark-color") || "primary");
      new ApexCharts(el, {
        chart: {
          type: "area",
          height: parseInt(el.getAttribute("data-spark-height"), 10) || 56,
          sparkline: { enabled: true },
          animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") },
        },
        series: [{ name: "", data: series.data }],
        labels: series.labels,
        // Transparency lives in the fill colour - see renderTrendChart().
        stroke: { width: 2, curve: "smooth", colors: [color] },
        fill: { type: "solid" },
        colors: [withAlpha(color, 0.16)],
        tooltip: { x: { show: true }, y: { title: { formatter: () => "" } }, marker: { show: false } },
      }).render();
    });
  }


  /** Chart text colour that follows the theme (was a hardcoded dark grey that vanished in dark mode). */
  function chartTextColor() {
    return getComputedStyle(document.documentElement).getPropertyValue("--default-text-color").trim() || "#333335";
  }

  // ==========================================================================
  // SEGMENTED CONTROL - one pill-shaped group of options (year, range,
  // Overview/History) instead of a lone dropdown or loose buttons.
  // ==========================================================================

  /** @param {object[]} options [{value, label}] */
  function renderSegmented(id, options, value, { ariaLabel = "" } = {}) {
    return `
      <div class="seg-control" id="${id}" role="tablist" aria-label="${ariaLabel}">
        ${options
          .map(
            (o) =>
              `<button type="button" class="seg-btn${String(o.value) === String(value) ? " active" : ""}" data-value="${o.value}" role="tab" aria-selected="${String(o.value) === String(value)}">${o.label}</button>`,
          )
          .join("")}
      </div>`;
  }

  /** Calls onChange(value) when a segment is picked; returns a setter. */
  function wireSegmented(id, onChange) {
    const el = document.getElementById(id);
    if (!el) return () => {};
    const set = (value) =>
      el.querySelectorAll(".seg-btn").forEach((b) => {
        const on = b.dataset.value === String(value);
        b.classList.toggle("active", on);
        b.setAttribute("aria-selected", on);
      });
    el.addEventListener("click", (e) => {
      const btn = e.target.closest(".seg-btn");
      if (!btn || btn.classList.contains("active")) return;
      set(btn.dataset.value);
      onChange(btn.dataset.value);
    });
    return set;
  }

  // ==========================================================================
  // RING DONUT - template CRM dashboard "Leads By Source": thin ring, total
  // in the centre, legend row underneath (dot, label, value, %).
  // ==========================================================================

  /**
   * @param {string} containerId
   * @param {object} opts {labels, series, colors: theme colour names, centerLabel}
   * @returns ApexCharts instance (or null)
   */
  function renderRingDonut(containerId, { labels, series, colors = null, centerLabel = "Total", focus = -1 } = {}) {
    const container = document.getElementById(containerId);
    if (!container || typeof ApexCharts === "undefined") return null;
    const names = colors || ["primary", "secondary", "success", "purple", "pink", "danger"];
    const total = series.reduce((a, b) => a + (Number(b) || 0), 0);
    const pctText = (v) => {
      const pct = total ? Math.round((v / total) * 100) : 0;
      return pct === 0 && v > 0 ? "<1%" : `${pct}%`;
    };
    const focused = focus >= 0 && focus < labels.length;

    container.innerHTML = `
      <div class="ring-donut${focused ? " has-focus" : ""}">
        <div class="ring-donut-chart"></div>
        <div class="ring-donut-legend">
          ${labels
            .map((l, i) => {
              const v = Number(series[i]) || 0;
              return `
                <div class="ring-donut-item${i === focus ? " is-focus" : ""}">
                  <div class="ring-donut-name"><span class="count-dot bg-${names[i % names.length]}"></span>${l}</div>
                  <div class="ring-donut-value">${v.toLocaleString()} <span>${pctText(v)}</span></div>
                </div>`;
            })
            .join("")}
        </div>
      </div>`;

    const text = chartTextColor();
    const chart = new ApexCharts(container.querySelector(".ring-donut-chart"), {
      chart: { type: "donut", height: 240, animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") } },
      series: series.map((v) => Number(v) || 0),
      labels,
      colors: labels.map((_, i) => cssColor(names[i % names.length])),
      stroke: { width: 0 },
      legend: { show: false },
      dataLabels: { enabled: false },
      plotOptions: {
        pie: {
          expandOnClick: false,
          donut: {
            size: "82%",
            labels: {
              show: true,
              name: { show: true, fontSize: "13px", color: text, offsetY: -4 },
              value: { show: true, fontSize: "22px", fontWeight: 700, color: text, offsetY: 6 },
              total: {
                show: true,
                showAlways: true,
                label: focused ? `${labels[focus]} · ${pctText(Number(series[focus]) || 0)}` : centerLabel,
                fontSize: "13px",
                color: text,
                formatter: () => (focused ? Number(series[focus]) || 0 : total).toLocaleString(),
              },
            },
          },
        },
      },
    });
    chart.render();
    return chart;
  }

  // ==========================================================================
  // COMPOSITION CARD - template CRM dashboard "Deals Status": a big number
  // with its change, a segmented colour bar, and a dotted list with counts.
  // ==========================================================================

  /**
   * @param {string} containerId - a card-body (or any container)
   * @param {object} opts {total, totalLabel, delta (periodDelta/trend-like {dir,text,caption}), items: [{label, value, color}]}
   */
  function renderCompositionCard(containerId, { total, totalLabel = "", delta = null, items = [] } = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const sum = items.reduce((a, it) => a + (Number(it.value) || 0), 0);
    container.innerHTML = `
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="composition-total">${total != null ? Number(total).toLocaleString() : "-"}</span>
        ${delta ? `<span class="soft-chip soft-${delta.dir === "down" ? "danger" : delta.dir === "up" ? "success" : "primary"}"><i class="ri-arrow-${delta.dir === "down" ? "down" : delta.dir === "up" ? "up" : "right"}-line me-1"></i>${delta.text}</span>` : ""}
        <span class="kpi-caption">${delta ? delta.caption : totalLabel}</span>
      </div>
      <div class="count-bar composition-bar mb-3" aria-hidden="true">
        ${items.map((it) => `<span class="bg-${it.color}" style="width: ${sum ? ((Number(it.value) || 0) / sum) * 100 : 0}%;"></span>`).join("")}
      </div>
      <ul class="composition-list">
        ${items
          .map(
            (it) => `
          <li>
            <span class="composition-name"><span class="count-dot bg-${it.color}"></span>${it.label}</span>
            <span class="composition-value">${(Number(it.value) || 0).toLocaleString()} <span>${sum ? Math.round(((Number(it.value) || 0) / sum) * 100) : 0}%</span></span>
          </li>`,
          )
          .join("")}
      </ul>`;
  }

  // ==========================================================================
  // DEMOGRAPHIC METRICS - one registry for every figure a submission holds,
  // shared by Growth Analytics, the metric detail page and "View trend" links.
  // ==========================================================================

  // `parts` - metrics recorded by gender. The metric page shows them as a
  // stacked chart with a card and a table column per part.
  const num0 = (v) => Number(v) || 0;
  const DEMOGRAPHIC_METRICS = {
    total_members: {
      label: "Total members",
      icon: "ri-team-line",
      color: "primary",
      get: (r) => r.total_members,
      parts: [
        { key: "male_count", label: "Male", icon: "ri-men-line", color: "info" },
        { key: "female_count", label: "Female", icon: "ri-women-line", color: "pink" },
      ],
    },
    youth: { label: "Youth (13-35)", icon: "ri-user-star-line", color: "success", get: (r) => r.youth_count },
    womens_fellowship: { label: "Women's fellowship", icon: "ri-women-line", color: "pink", get: (r) => r.womens_fellowship_count },
    mens_fellowship: { label: "Men's fellowship", icon: "ri-men-line", color: "info", get: (r) => r.mens_fellowship_count },
    sunday_school: {
      label: "Sunday school",
      icon: "ri-book-read-line",
      color: "purple",
      get: (r) => (r.sunday_school_male_count == null && r.sunday_school_female_count == null ? null : num0(r.sunday_school_male_count) + num0(r.sunday_school_female_count)),
      parts: [
        { key: "sunday_school_male_count", label: "Boys", icon: "ri-men-line", color: "info" },
        { key: "sunday_school_female_count", label: "Girls", icon: "ri-women-line", color: "pink" },
      ],
    },
    seniors: { label: "Seniors", icon: "ri-user-heart-line", color: "secondary", get: (r) => r.seniors_count },
    new_members: { label: "New members", icon: "ri-user-add-line", color: "success", get: (r) => r.new_members_count, flow: true },
    departures: { label: "Departures", icon: "ri-user-unfollow-line", color: "danger", get: (r) => r.transferred_out_count, flow: true },
    baptisms: { label: "Baptisms", icon: "ri-drop-line", color: "primary", get: (r) => r.baptisms_count, flow: true },
    communion: { label: "Holy Communion", icon: "ri-cup-line", color: "secondary", get: (r) => r.communion_participants_count, flow: true },
    conversions: { label: "Conversions", icon: "ri-heart-line", color: "purple", get: (r) => r.conversions_count, flow: true },
  };

  /** Link to a metric's detail page. */
  function metricUrl(key) {
    return `${window.mwdBaseUrl || ""}/church/demographics-growth/metric?key=${encodeURIComponent(key)}`;
  }

  /** Short period label for chart axes ("Jan 2026", "H1 2026", "Year 2026"). */
  function shortPeriodLabel(row) {
    if (row.fiscal_month) return `${row.fiscal_month.short_name || row.fiscal_month.name} ${row.fiscal_year?.year || ""}`.trim();
    return demographicPeriodLabel(row);
  }

  // ==========================================================================
  // WIDGET DASHBOARD COMPONENTS (Attendance Reports tabbed dashboard)
  //
  // Cards + a small chart-type library modeled literally on index-1.html's
  // "Total Sales" card and "Earnings" chart (the pale bg-{color}-transparent
  // icon tint, two-column row layout, distributed-shaded bars, and a small
  // legend row above the chart) - additive alongside renderStatCard/
  // renderStatCardsRow above, which stay untouched for the pages already
  // using them. Alpha/opacity convention followed throughout (confirmed
  // consistent across the template): 0.05 for gridlines, 0.1 for card
  // tints, 0.2-1.0 for distributed bar shading, 1.0 for solid strokes.
  // Charts default to a single brand color rather than forcing a
  // two-color scheme - only the donut (genuinely categorical slices)
  // needs more than one.
  // ==========================================================================

  const BRAND_COLORS = {
    primary: "#2CA4BF",
    secondary: "#F2BE22",
    warning: "#F2BE22",
    danger: "#F23535",
    success: "#26bf94",
    // Matches --info-rgb (44, 164, 191) in styles.css, which is the same
    // value as the diocese teal - this just mirrors the CSS, not a new color.
    info: "#2CA4BF",
    // Diocese black (CLAUDE.md Brand Tokens: --diocese-black) - the neutral
    // choice for chart elements that aren't a semantic success/danger/etc.
    // color, e.g. a "combined total" reference line, or axis label text.
    dark: "#0D0D0D",
  };

  function brandHex(color) {
    return BRAND_COLORS[color] || color;
  }

  function hexToRgba(hex, alpha) {
    const clean = (hex || "").replace("#", "");
    const bigint = parseInt(clean, 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  /**
   * @param {object} opts {icon, label, value, trend, color}
   * @param {object|null} [opts.trend] {direction: 'up'|'down', percent, label}
   *   Rendered as green/red "+N%" top-right, with its label as a caption.
   */
  function renderWidgetCard({ icon, label, value, trend = null, color = "primary" }) {
    const t = trend
      ? {
          text: `${trend.direction === "up" ? "+" : "-"}${trend.percent}%`,
          color: trend.direction === "up" ? "success" : "danger",
          icon: trend.direction === "up" ? "ri-arrow-up-s-fill" : "ri-arrow-down-s-fill",
        }
      : null;
    return renderCompactCard({ icon, label, value, color, trend: t, caption: trend ? trend.label : "" });
  }

  /**
   * 4-up grid on wide screens (collapsing to 2-up/1-up on smaller
   * breakpoints), so all 4 stat cards sit in one row.
   * @param {string} containerId
   * @param {object[]} cards - renderWidgetCard() opts
   */
  function renderWidgetCardsRow(containerId, cards) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = cards
      .map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${renderWidgetCard(c)}</div>`)
      .join("");
  }

  // ==========================================================================
  // SOLID-ICON KPI CARD (View Submission, Growth Overview)
  //
  // Promoted from view-submission.js once Growth Overview needed the exact
  // same card a second time - label + solid bg-${color} icon avatar (not
  // renderWidgetCard's pale tint, which stays as-is for Attendance Reports),
  // a large bold value, and an absolute-diff trend badge. trendFor() is
  // null-safe on purpose: a genuine 0 in the previous period must still
  // produce a real trend, not be mistaken for "no data to compare."
  // ==========================================================================

  /**
   * null when there's no previous value to compare against - callers just
   * skip the trend. Checks for null/undefined explicitly (not truthiness) so
   * a genuine 0 in the previous period still produces a real trend.
   */
  function trendFor(current, previousValue) {
    if (previousValue == null) return null;
    const diff = current - previousValue;
    // pct is null when the previous value was 0 (a % change is undefined).
    return { diff, pct: previousValue ? Math.round((diff / previousValue) * 100) : null };
  }

  /**
   * @param {object} opts {icon, label, value, sublabel, color, trend, link}
   *   trend: {diff} from trendFor() - green/red "+N" beside the label (or a
   *   neutral "No change"). sublabel renders alongside it when given (e.g.
   *   Sunday School's "N Male · N Female"). link: optional {href, text}.
   */
  function renderSolidStatCard({ icon, label, value, sublabel = "", color = "primary", trend = null, link = null }) {
    let t = null;
    if (trend) {
      if (trend.diff === 0) {
        t = { text: "No change", color: "primary", icon: "ri-subtract-line" };
      } else {
        const up = trend.diff > 0;
        const pct = trend.pct != null ? ` (${Math.abs(trend.pct)}%)` : "";
        t = {
          text: `${up ? "+" : "-"}${Math.abs(trend.diff)}${pct}`,
          color: up ? "success" : "danger",
          icon: up ? "ri-arrow-up-s-fill" : "ri-arrow-down-s-fill",
        };
      }
    }
    return renderTileCard({ icon, label, value, color, trend: t, sublabel, link });
  }

  /**
   * General trend chart wrapper - area or column, single series or a
   * handful, one brand color by default (not a forced two-color scheme).
   * @param {object} opts {categories, series, type: 'area'|'column', color}
   */
  /**
   * @param {string[]} [colors] - genuinely distinct hex per series, for a
   *   real multi-series comparison (e.g. Male vs Female). Omit for the
   *   single-hue cases (area/bar) - falls back to the one color derived
   *   from `color`, exactly as before. Passing multiple series without
   *   `colors` would render every line in the same hue, which is the bug
   *   this param exists to prevent.
   */
  function renderTrendChart(containerId, { categories, series, type = "area", color = "primary", colors = null, stacked = false } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;
    const hex = brandHex(color);
    // "area"/"line"/"mixed"/"heatmap" each get their own real ApexCharts
    // type - every other value ("bar", "column", or anything else already
    // passed by existing callers) keeps mapping to the distributed-bar
    // branch below, exactly as before this function only supported "area"
    // vs everything-else. A "mixed" chart's container type is "line" per
    // ApexCharts' own convention for combo charts (confirmed against the
    // template's assets/js/apexcharts-mixed.js) - each series then carries
    // its own "column"/"line" type.
    const chartType = type === "area" ? "area" : type === "line" || type === "mixed" ? "line" : type === "heatmap" ? "heatmap" : "bar";

    const options = {
      chart: { type: chartType, height: 300, toolbar: { show: false }, foreColor: chartTextColor() },
      series,
      xaxis: {
        categories,
        // Full-contrast, bold axis labels - the template's default
        // foreColor above is a legible-enough mid-gray for most chart
        // text, but the year/category labels specifically read as too
        // faint against this app's own no-muted-text Design Rule.
        labels: { style: { colors: chartTextColor(), fontWeight: 600 } },
      },
      colors: colors || [hex],
      // People and events are whole numbers - no "0.7" axis ticks.
      yaxis: { forceNiceScale: true, labels: { formatter: (v) => (v == null ? v : Math.round(v).toLocaleString()) } },
      dataLabels: { enabled: false },
      grid: { borderColor: hexToRgba(hex, 0.05) },
      legend: { show: series.length > 1, position: "bottom" },
    };

    if (type === "area") {
      // ApexCharts 3.42 ignores fill.opacity on area charts (the fill came
      // out fully solid), so the transparency goes into the fill colour and
      // the line and markers keep the solid one.
      const solid = options.colors;
      options.colors = solid.map((c) => withAlpha(c, 0.18));
      options.stroke = { curve: "smooth", width: 2.5, colors: solid };
      options.markers = { size: 4, colors: solid, strokeWidth: 0 };
      options.fill = { type: "solid" };
    } else if (type === "line") {
      // Real multi-line comparison (e.g. Male vs Female) - smooth strokes,
      // no fill, so two overlapping series stay readable instead of
      // muddying together the way two translucent areas would.
      options.stroke = { curve: "smooth", width: 2 };
    } else if (type === "mixed") {
      // Stacked columns (shows composition, e.g. Male+Female share of a
      // combined total) with a Total line overlaid - each series in
      // `series` carries its own `type: "column"`/`"line"`, mirroring
      // apexcharts-mixed.js's bar+line combo. `stroke.width` is an array
      // matching series count (thin for bars, thicker for the line), same
      // convention that file uses.
      options.chart.stacked = stacked;
      options.stroke = { width: series.map((s) => (s.type === "line" ? 3 : 1)) };
    } else if (type === "heatmap") {
      // One base color, auto-shaded light-to-dark across the data's value
      // range (ApexCharts' own default shadeIntensity behavior for a
      // single-color heatmap - confirmed against the template's own
      // assets/js/apexcharts-heatmap.js, which does the same: one hex in
      // `colors`, no manually-built colorScale.ranges needed). Each series
      // is one row (e.g. a category), with {x, y} points across columns
      // (e.g. fiscal years) - a data-encoding intensity scale, not a
      // decorative gradient, so it's exempt from the no-gradients Design
      // Rule the same way the donut chart's categorical colors already are.
      options.legend.show = false;
    } else {
      // Distributed, shaded bars (index-1.html's Earnings chart pattern) -
      // one series, still one hue, just varying opacity per bar instead of
      // a flat fill - richer without a forced second color.
      const pointCount = (series[0]?.data || []).length;
      options.colors = colors || Array.from({ length: pointCount }, (_, i) => hexToRgba(hex, 0.3 + (0.7 * i) / Math.max(1, pointCount - 1)));
      options.plotOptions = { bar: { columnWidth: "45%", borderRadius: 6, distributed: !colors } };
      options.legend.show = false;
    }

    const chart = new ApexCharts(el, options);
    chart.render();
    return chart;
  }

  /**
   * Recent-activity timeline - the exact `timeline-widget`/
   * `timeline-widget-list` classes already defined in styles.css
   * (index-8.html's "Upcoming Events" widget), not new CSS: a day-number +
   * weekday date column connected to a title + time/badge subtitle.
   * @param {string} containerId
   * @param {object[]} items - [{day, weekday, title, time, badgeLabel, badgeColor}]
   */
  function renderTimeline(containerId, items) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!items || items.length === 0) {
      container.innerHTML = `<p class="text-body fw-semibold mb-0">No records for this period</p>`;
      return;
    }

    container.innerHTML = `
      <ul class="list-unstyled timeline-widget mb-0 my-3">
        ${items
          .map(
            (it) => `
          <li class="timeline-widget-list">
            <div class="d-flex align-items-top">
              <div class="me-5 text-center">
                <span class="d-block fs-20 fw-semibold text-primary">${it.day}</span>
                <span class="d-block fs-12 text-body">${it.weekday}</span>
              </div>
              <div class="flex-fill">
                <p class="mb-1 timeline-widget-content">${it.title}</p>
                <p class="mb-0 fs-12 lh-1 text-body">
                  ${it.time || ""}<span class="badge bg-${it.badgeColor || "primary"}-transparent ms-2">${it.badgeLabel}</span>
                </p>
              </div>
            </div>
          </li>`,
          )
          .join("")}
      </ul>`;
  }

  /**
   * Small pill-badge legend/key row - reused for the radial gauge's
   * Recorded/Missed key and the Ministry donut's per-type key (replacing
   * ApexCharts' own native legend so we control the pill styling).
   * @param {string} containerId
   * @param {object[]} items - [{label, value, color}]
   */
  function renderPillLegend(containerId, items) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!items || items.length === 0) {
      container.innerHTML = "";
      return;
    }

    // Soft chips (tinted, not solid) - these are supporting facts, not
    // status signals; see CLAUDE.md's colour-balance rule.
    container.innerHTML = `
      <div class="d-flex flex-wrap gap-2">
        ${items
          .map(
            (it) =>
              `<span class="soft-chip soft-${it.color || "primary"}">${it.label}${
                it.value !== undefined && it.value !== null ? ` <b>${it.value}</b>` : ""
              }</span>`,
          )
          .join("")}
      </div>`;
  }

  /**
   * Category-share donut - wraps the same ApexCharts donut config already
   * proven on Demographics' gender-split chart, reused here instead of a
   * new one-off config. Genuinely needs distinct colors per slice
   * (categorical), the one chart type exempt from the single-color default.
   * Native legend is off by default - callers render their own key via
   * renderPillLegend() instead, for pill-styled consistency.
   *
   * `centerTotal` turns on ApexCharts' native donut-total label - the
   * index-8.html "Jobs Summary" pattern (a big centered "Total N" instead
   * of a bare ring), used for the Sunday Coverage widget's
   * Recorded/Missed donut.
   * @param {object} opts {labels, series, colors, showLegend, centerTotal: {label, value}}
   */
  function renderDonutChart(containerId, { labels, series, colors = null, showLegend = false, centerTotal = null } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;
    const palette = colors || [BRAND_COLORS.primary, BRAND_COLORS.secondary, BRAND_COLORS.success, BRAND_COLORS.danger];

    const options = {
      chart: { type: "donut", height: 260, foreColor: chartTextColor() },
      series,
      labels,
      colors: palette,
      legend: { show: showLegend, position: "bottom" },
      dataLabels: { enabled: false },
    };

    if (centerTotal) {
      options.plotOptions = {
        pie: {
          donut: {
            size: "70%",
            labels: {
              show: true,
              name: { show: true, fontSize: "13px", color: chartTextColor() },
              value: { show: true, fontSize: "18px", color: chartTextColor() },
              total: {
                show: true,
                showAlways: true,
                label: centerTotal.label,
                fontSize: "20px",
                fontWeight: 600,
                color: chartTextColor(),
                formatter: () => centerTotal.value,
              },
            },
          },
        },
      };
    }

    const chart = new ApexCharts(el, options);
    chart.render();
    return chart;
  }

  /**
   * Mixed bar+line combo (e.g. Times Held vs. Average Attendance per
   * gathering type) - both series share one brand color, differentiated by
   * shape (solid bars vs. a line+markers) rather than by a second color.
   */
  function renderComboChart(containerId, { categories, barData, lineData, barLabel = "Times Held", lineLabel = "Average Attendance", color = "primary" } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;
    const hex = brandHex(color);

    const chart = new ApexCharts(el, {
      chart: { height: 320, type: "line", toolbar: { show: false }, foreColor: chartTextColor() },
      series: [
        { name: barLabel, type: "column", data: barData },
        { name: lineLabel, type: "line", data: lineData },
      ],
      stroke: { width: [0, 3], curve: "smooth" },
      colors: [hex, hex],
      plotOptions: { bar: { columnWidth: "45%", borderRadius: 6 } },
      xaxis: { categories },
      dataLabels: { enabled: false },
      legend: { position: "bottom" },
      grid: { borderColor: hexToRgba(hex, 0.05) },
    });
    chart.render();
    return chart;
  }

  /**
   * Small highlighted callout for the plain-English `insights` sentences
   * the backend computes (AttendanceReportWidgetService) - this is what
   * makes a tab read as analysis rather than a pile of numbers.
   * @param {string} containerId
   * @param {string[]} sentences
   */
  function renderInsightCallout(containerId, sentences) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!sentences || sentences.length === 0) {
      container.innerHTML = "";
      return;
    }

    container.innerHTML = `
      <div class="alert alert-primary bg-primary-transparent border-0 mb-3">
        <div class="d-flex align-items-start gap-2">
          <i class="ri-lightbulb-flash-line fs-18 mt-1"></i>
          <div>
            ${sentences.map((s) => `<div class="fw-semibold">${s}</div>`).join("")}
          </div>
        </div>
      </div>`;
  }

  // ==========================================================================
  // BUTTON LOADING STATE
  // ==========================================================================

  function setButtonLoading(btnEl, loadingText) {
    if (!btnEl) return;
    btnEl.dataset.originalHtml = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = `<i class="ri-loader-4-line ri-spin me-1"></i>${loadingText}`;
  }

  function restoreButton(btnEl) {
    if (!btnEl) return;
    btnEl.disabled = false;
    if (btnEl.dataset.originalHtml) {
      btnEl.innerHTML = btnEl.dataset.originalHtml;
    }
  }

  // ==========================================================================
  // TABLE LOADING / EMPTY STATES
  // ==========================================================================

  /**
   * Skeleton rows (the same column count as the table) instead of a lone
   * spinner, so the table keeps its shape while data loads. `message` is
   * kept for screen readers.
   */
  function renderTableLoading(colspan, message = "Loading...", rows = 5) {
    const widths = ["70%", "55%", "40%", "60%", "30%", "50%"];
    const row = (r) => `
      <tr class="skel-row" aria-hidden="true">
        ${Array.from({ length: colspan }, (_, c) => `<td><span class="skel skel-line" style="width: ${widths[(r + c) % widths.length]};"></span>${c === 0 ? '<span class="skel skel-line skel-line-sm mt-2" style="width: 40%;"></span>' : ""}</td>`).join("")}
      </tr>`;
    return `<tr class="visually-hidden"><td colspan="${colspan}">${message}</td></tr>` + Array.from({ length: rows }, (_, r) => row(r)).join("");
  }

  /** Skeleton stat cards - same outline as renderSparkCard, for a card row that's still loading. */
  function skeletonCards(count = 4, colClass = "col-xl-3 col-lg-6 col-md-6") {
    const card = `
      <div class="${colClass}">
        <div class="card custom-card" aria-hidden="true">
          <div class="card-body">
            <div class="d-flex justify-content-between gap-2">
              <div class="flex-fill">
                <span class="skel skel-line" style="width: 55%;"></span>
                <span class="skel skel-title mt-2"></span>
                <span class="skel skel-line skel-line-sm mt-3" style="width: 45%;"></span>
              </div>
              <span class="skel skel-tile"></span>
            </div>
          </div>
        </div>
      </div>`;
    return Array.from({ length: count }, () => card).join("");
  }

  /**
   * On page load, fill anything still empty with a skeleton - stat card
   * rows (ids ending "CardsRow"), table bodies and chart containers - so a
   * page never shows blank areas while its data loads. The page's own
   * render replaces them. Runs before page scripts' DOMContentLoaded
   * handlers (this file loads first), and only touches empty containers.
   */
  function fillLoadingSkeletons() {
    const isEmpty = (el) => el.children.length === 0 && !el.textContent.trim();

    document.querySelectorAll('[id$="CardsRow"]').forEach((row) => {
      if (isEmpty(row)) {
        const cols = row.id === "statCardsRow" && row.closest(".col-xl-8, .col-xl-9") ? 3 : 4;
        row.innerHTML = skeletonCards(cols);
      }
    });

    document.querySelectorAll(".app-content table tbody").forEach((tbody) => {
      if (!isEmpty(tbody)) return;
      const cols = tbody.closest("table")?.querySelectorAll("thead th").length || 4;
      tbody.innerHTML = renderTableLoading(cols);
    });

    document.querySelectorAll('.app-content [id$="Chart"], .app-content [id$="Gauge"], .app-content [id$="Heatmap"]').forEach((el) => {
      el.classList.add("skel-chart");
    });
  }

  document.addEventListener("DOMContentLoaded", fillLoadingSkeletons);

  function renderTableEmpty(colspan, message = "No records found", icon = "ri-inbox-line") {
    return `
      <tr>
        <td colspan="${colspan}" class="text-center py-5">
          <i class="${icon} fs-30 text-primary mb-2 d-block"></i>
          <p class="text-body fw-semibold mb-0">${message}</p>
        </td>
      </tr>`;
  }

  // ==========================================================================
  // NUMBER STEPPER
  //
  // Renders a +/- stepper control around a numeric <input>, per the PWA
  // design doc's explicit "number steppers" call-out for count fields.
  // ==========================================================================

  function numberStepperHtml(fieldId, { label, min = 0, max = 99999, value = "", required = false } = {}) {
    return `
      <label for="${fieldId}" class="form-label">${label}${required ? ' <span class="text-danger">*</span>' : ""}</label>
      <div class="input-group stepper-group">
        <button class="btn btn-outline-primary stepper-btn" type="button" data-stepper-target="${fieldId}" data-stepper-dir="-1">
          <i class="ri-subtract-line"></i>
        </button>
        <input type="number" class="form-control text-center" id="${fieldId}" name="${fieldId}"
               min="${min}" max="${max}" value="${value}" ${required ? "required" : ""}>
        <button class="btn btn-outline-primary stepper-btn" type="button" data-stepper-target="${fieldId}" data-stepper-dir="1">
          <i class="ri-add-line"></i>
        </button>
      </div>`;
  }

  /** Call once after inserting stepper HTML into the DOM to wire up +/- clicks. */
  function initSteppers(containerEl, onChange = null) {
    containerEl.querySelectorAll(".stepper-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const input = document.getElementById(btn.dataset.stepperTarget);
        if (!input) return;
        const dir = parseInt(btn.dataset.stepperDir, 10);
        const min = input.min !== "" ? parseInt(input.min, 10) : -Infinity;
        const max = input.max !== "" ? parseInt(input.max, 10) : Infinity;
        const current = parseInt(input.value, 10) || 0;
        const next = Math.min(max, Math.max(min, current + dir));
        input.value = next;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        if (onChange) onChange(input);
      });
    });
  }

  // ==========================================================================
  // LIST TABLE - SEARCH/FILTER/PAGINATION (DataTables)
  //
  // One shared init function instead of every list page hand-rolling the
  // jQuery DataTables setup + pagination/search styling block that
  // assets/js/pages/budget-settings/budget-type.js originally proved out
  // per-page. Callers just need a real <table id="..."> with a <thead>.
  // ==========================================================================

  const _dataTables = {};

  /**
   * @param {string} tableId - id of the <table> element (must already be in the DOM with rows rendered)
   * @param {object} options
   *   searchPlaceholder: string
   *   pageLength: number (default 10)
   *   order: DataTables order array, default [[0, 'asc']]
   *   nonSortableColumns: number[] - zero-based column indexes to disable sorting on (e.g. an Actions column)
   *   hideDefaultSearch: boolean - true when a custom renderFilterToolbar() is used instead
   *     of DataTables' own built-in search box (avoids showing two search inputs)
   */
  function initListDataTable(tableId, options = {}) {
    if (typeof $ === "undefined" || !$.fn || !$.fn.DataTable) {
      console.warn(`DataTables not loaded - skipping init for #${tableId}`);
      return null;
    }

    const tableEl = document.getElementById(tableId);
    const tbody = tableEl ? tableEl.tBodies[0] : null;

    // Callers write the new rows into <tbody>, then call this. Destroying the
    // previous DataTable puts back the rows it had cached (DataTables 1.12
    // re-appends its stored row nodes), which silently replaced the fresh
    // rows with stale ones after every save/toggle. Capture the new rows
    // first, destroy, then restore them.
    const freshRows = tbody ? tbody.innerHTML : "";
    if ($.fn.DataTable.isDataTable(`#${tableId}`)) {
      $(`#${tableId}`).DataTable().destroy();
      delete _dataTables[tableId];
      if (tbody) tbody.innerHTML = freshRows;
    }

    // A single full-width loading/empty row isn't table data - initialising
    // DataTables over it triggers its "incorrect column count" warning.
    const firstRow = tbody ? tbody.rows[0] : null;
    if (!firstRow || (tbody.rows.length === 1 && firstRow.cells.length === 1 && firstRow.cells[0].colSpan > 1)) {
      return null;
    }

    const {
      searchPlaceholder = "Search...",
      pageLength = 10,
      order = [[0, "asc"]],
      nonSortableColumns = [],
      hideDefaultSearch = false,
      noun = "records",
    } = options;

    // Footer: rows-per-page + "Showing x-y of z" on the left, pages on the
    // right (same arrangement as the v1-events-backend list pages).
    const footer = '<"list-footer"<"list-footer-start"li>p>';
    const dom = hideDefaultSearch ? `t${footer}` : `<"list-footer list-footer-top"lf>t${footer}`;

    const instance = $(`#${tableId}`).DataTable({
      responsive: true,
      pageLength,
      lengthMenu: [
        [10, 25, 50, 100],
        [10, 25, 50, 100],
      ],
      order,
      columnDefs: nonSortableColumns.length ? [{ orderable: false, targets: nonSortableColumns }] : [],
      language: {
        search: "_INPUT_",
        searchPlaceholder,
        lengthMenu: "Rows _MENU_",
        info: `Showing <b>_START_–_END_</b> of <b>_TOTAL_</b> ${noun}`,
        infoEmpty: `No ${noun}`,
        infoFiltered: "",
        zeroRecords: `
          <div class="list-empty">
            <span class="list-empty-icon bg-primary text-white"><i class="ri-search-eye-line"></i></span>
            <div class="fw-semibold mt-2">No ${noun} match your filters</div>
            <div class="fs-12 text-muted">Try a different search, or reset the filters.</div>
          </div>`,
        paginate: {
          first: '<i class="ri-skip-back-mini-line"></i>',
          last: '<i class="ri-skip-forward-mini-line"></i>',
          next: '<i class="ri-arrow-right-s-line"></i>',
          previous: '<i class="ri-arrow-left-s-line"></i>',
        },
      },
      dom,
      initComplete: function () {
        $(`#${tableId}_wrapper .dataTables_filter input`).addClass("form-control form-control-sm").attr("placeholder", searchPlaceholder);
        $(`#${tableId}_wrapper .dataTables_length select`).addClass("form-select form-select-sm");
      },
      drawCallback: function () {
        $(`#${tableId}_wrapper .dataTables_length select`).addClass("form-select form-select-sm");
      },
    });

    _dataTables[tableId] = instance;
    return instance;
  }

  // ==========================================================================
  // FILTER TOOLBAR (search + dropdown filters, above a DataTable)
  //
  // Replaces DataTables' own bare search box with a proper toolbar (search +
  // N dropdown filters + a clear button) - built once here, called
  // identically from every list page instead of each hand-rolling its own
  // filter row.
  // ==========================================================================

  const DATE_RANGES = [
    { value: "", label: "All time" },
    { value: "this", label: "This month" },
    { value: "last", label: "Last month" },
    { value: "3m", label: "Last 3 months" },
  ];

  /**
   * Filter bar above a list table (same arrangement as the v1-events-backend
   * list pages): search with an icon, dropdown filters, optional date-range
   * pills, a live result count and a Reset that only shows once something
   * is filtered.
   * @param {string} containerId - id of an empty container element to render into
   * @param {object} config
   *   searchPlaceholder: string
   *   filters: [{ id, label (shown as the "All X" default option), options: [{value,label}] }]
   *   dateRange: boolean - show "All time / This month / Last month / Last 3 months" pills
   *     (rows need a data-date="YYYY-MM-DD" attribute)
   */
  function renderFilterToolbar(containerId, { searchPlaceholder = "Search...", filters = [], dateRange = false } = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const selects = filters
      .map(
        (f) => `
        <select class="form-select list-filter" id="${f.id}" aria-label="${f.label}">
          <option value="">${f.label}</option>
          ${f.options.map((o) => `<option value="${o.value}" data-color="${o.color || optionColor(o.label)}">${o.label}</option>`).join("")}
        </select>`,
      )
      .join("");

    const ranges = dateRange
      ? `<div class="list-range" role="group" aria-label="Date range">
          ${DATE_RANGES.map((r) => `<button type="button" class="list-range-btn${r.value === "" ? " active" : ""}" data-range="${r.value}">${r.label}</button>`).join("")}
        </div>`
      : "";

    container.innerHTML = `
      <div class="list-filterbar">
        <div class="list-search">
          <i class="ri-search-line"></i>
          <input type="search" class="form-control" id="${containerId}Search" placeholder="${searchPlaceholder}" autocomplete="off">
        </div>
        ${selects}
        ${ranges}
        <div class="list-filterbar-end">
          <span class="list-count" id="${containerId}Count"></span>
          <button type="button" class="list-reset d-none" id="${containerId}Clear">
            <i class="ri-refresh-line"></i><span>Reset</span>
          </button>
        </div>
      </div>`;
  }

  // ==========================================================================
  // SELECT2
  // ==========================================================================

  const EXTRA_OPTION_COLORS = { active: "success", inactive: "danger", "not submitted": "secondary" };

  /** Dot colour for a filter option: statuses keep their badge colour, anything else gets a stable colour from the palette. */
  function optionColor(label) {
    const key = String(label || "").toLowerCase();
    const status = Object.values(STATUS_BADGES).find((s) => s.label.toLowerCase() === key);
    return status ? status.color : EXTRA_OPTION_COLORS[key] || colorFor(label);
  }

  function selectOptionMarkup(option) {
    const el = option.element;
    if (!el || !option.id) return option.text;
    const color = el.dataset.color;
    const icon = el.dataset.icon;
    const wrap = document.createElement("span");
    wrap.className = "s2-option";
    if (icon) wrap.innerHTML = `<span class="s2-option-icon bg-${color || "primary"} ${iconTextClass(color)}"><i class="${icon}"></i></span>`;
    else if (color) wrap.innerHTML = `<span class="s2-option-dot bg-${color}"></span>`;
    wrap.appendChild(document.createTextNode(option.text));
    return wrap;
  }

  /**
   * Turns a <select> into a styled Select2 dropdown (styles: "Select2" in the
   * design system v2 section of styles.css). Options can carry data-color
   * (a dot) or data-icon + data-color (an icon tile). Select2 only fires
   * jQuery events, so a native "change" is re-dispatched and plain
   * addEventListener("change") handlers keep working. Falls back to the plain
   * select when Select2 isn't loaded on the page.
   * @param {HTMLSelectElement|string} el - element or id
   * @param {object} opts - any Select2 option, plus {search: bool} to force the search box on/off
   */
  function enhanceSelect(el, opts = {}) {
    const select = typeof el === "string" ? document.getElementById(el) : el;
    if (!select || typeof $ === "undefined" || !$.fn.select2) return select;
    const $select = $(select);
    if ($select.data("select2")) $select.select2("destroy");

    const { search, ...rest } = opts;
    const showSearch = search ?? select.options.length > 8;
    const modal = select.closest(".modal");
    $select.select2({
      width: "100%",
      minimumResultsForSearch: showSearch ? 0 : Infinity,
      dropdownParent: modal ? $(modal) : $(document.body),
      templateResult: selectOptionMarkup,
      templateSelection: selectOptionMarkup,
      ...rest,
    });
    $select.next(".select2-container").toggleClass("is-set", !!select.value);
    $select.off(".mwd").on("select2:select.mwd select2:clear.mwd", () => {
      $select.next(".select2-container").toggleClass("is-set", !!select.value);
      select.dispatchEvent(new Event("change"));
    });
    return select;
  }

  /** After setting select.value in code, refresh the Select2 display (no-op for a plain select). */
  function syncSelect(el) {
    const select = typeof el === "string" ? document.getElementById(el) : el;
    if (!select || typeof $ === "undefined" || !$(select).data("select2")) return;
    $(select).trigger("change.select2");
    $(select).next(".select2-container").toggleClass("is-set", !!select.value);
  }

  // Active date range per table, read by one shared DataTables row filter.
  const _dateRanges = {};
  let _dateFilterRegistered = false;

  function rowInRange(dateStr, range, now = new Date()) {
    if (!range) return true;
    const d = new Date(dateStr);
    if (isNaN(d)) return true;
    const diff = monthIndex(now) - monthIndex(d);
    if (range === "this") return diff === 0;
    if (range === "last") return diff === 1;
    if (range === "3m") return diff >= 0 && diff <= 2;
    return true;
  }

  function registerDateFilter() {
    if (_dateFilterRegistered || typeof $ === "undefined" || !$.fn.dataTable) return;
    _dateFilterRegistered = true;
    $.fn.dataTable.ext.search.push((settings, data, dataIndex) => {
      const range = _dateRanges[settings.nTable.id];
      if (!range) return true;
      const tr = settings.aoData[dataIndex] && settings.aoData[dataIndex].nTr;
      return tr && tr.dataset.date ? rowInRange(tr.dataset.date, range) : true;
    });
  }

  /**
   * Wires a renderFilterToolbar() container to a DataTables instance. All
   * filtering happens in place - nothing reloads the page. Filter state is
   * mirrored into the URL (history.replaceState) so a refresh or a shared
   * link keeps it.
   * @param {string} containerId - same id passed to renderFilterToolbar()
   * @param {object|null} table - initListDataTable()'s return value (null when the list is empty)
   * @param {object[]} filters - each {id, columnIndex, exact} (matches the cell's data-search, if set)
   * @param {object} opts {noun: "records", urlSync: true - set false when a page has several toolbars}
   */
  function wireFilterToolbar(containerId, table, filters = [], { noun = "records", urlSync = true } = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const searchInput = document.getElementById(`${containerId}Search`);
    const clearBtn = document.getElementById(`${containerId}Clear`);
    const countEl = document.getElementById(`${containerId}Count`);
    const rangeBtns = [...container.querySelectorAll(".list-range-btn")];
    const tableId = table ? table.table().node().id : null;

    const currentRange = () => (rangeBtns.find((b) => b.classList.contains("active")) || {}).dataset?.range || "";

    function updateUi() {
      let active = !!(searchInput && searchInput.value.trim()) || !!currentRange();
      filters.forEach((f) => {
        const select = document.getElementById(f.id);
        if (!select) return;
        select.classList.toggle("is-set", !!select.value);
        if (select.value) active = true;
      });
      if (clearBtn) clearBtn.classList.toggle("d-none", !active);
      if (countEl) {
        const shown = table ? table.page.info().recordsDisplay : 0;
        const total = table ? table.page.info().recordsTotal : 0;
        countEl.textContent = active ? `${shown} of ${total} ${noun}` : `${total} ${noun}`;
      }
    }

    function syncUrl() {
      if (!urlSync) return;
      const params = new URLSearchParams(window.location.search);
      const set = (key, value) => (value ? params.set(key, value) : params.delete(key));
      set("q", searchInput ? searchInput.value.trim() : "");
      filters.forEach((f) => set(f.id, document.getElementById(f.id)?.value || ""));
      set("range", currentRange());
      const qs = params.toString();
      history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
    }

    function applyColumnFilter(f) {
      const select = document.getElementById(f.id);
      if (!select || !table) return;
      // exact: true anchors the search as a regex (^value$) - needed for
      // columns like Status where "Active" would otherwise also match
      // "Inactive" as a plain substring.
      const escaped = select.value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      const value = f.exact && select.value ? `^${escaped}$` : select.value;
      table.column(f.columnIndex).search(value, !!f.exact, false);
    }

    function apply() {
      if (table) {
        table.search(searchInput ? searchInput.value : "");
        filters.forEach(applyColumnFilter);
        if (tableId) _dateRanges[tableId] = currentRange();
        table.draw();
      }
      updateUi();
      syncUrl();
    }

    // Restore state from the URL (e.g. after a refresh).
    const params = new URLSearchParams(urlSync ? window.location.search : "");
    if (searchInput && params.get("q")) searchInput.value = params.get("q");
    filters.forEach((f) => {
      const select = document.getElementById(f.id);
      const value = params.get(f.id);
      if (select && value && [...select.options].some((o) => o.value === value)) select.value = value;
    });
    if (params.get("range")) {
      rangeBtns.forEach((b) => b.classList.toggle("active", b.dataset.range === params.get("range")));
    }
    filters.forEach((f) => enhanceSelect(f.id, { search: false, dropdownAutoWidth: true }));

    registerDateFilter();

    let debounce = null;
    if (searchInput) {
      searchInput.addEventListener("input", () => {
        clearTimeout(debounce);
        debounce = setTimeout(apply, 250);
      });
    }
    filters.forEach((f) => document.getElementById(f.id)?.addEventListener("change", apply));
    rangeBtns.forEach((btn) =>
      btn.addEventListener("click", () => {
        rangeBtns.forEach((b) => b.classList.toggle("active", b === btn));
        apply();
      }),
    );
    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        if (searchInput) searchInput.value = "";
        filters.forEach((f) => {
          const select = document.getElementById(f.id);
          if (select) select.value = "";
          syncSelect(select);
        });
        rangeBtns.forEach((b) => b.classList.toggle("active", b.dataset.range === ""));
        if (table) table.columns().search("");
        apply();
      });
    }

    apply();
  }

  // ==========================================================================
  // LIST ROW HELPERS - a stable colour per name (so "Tuesday Fellowship" is
  // always the same colour), avatar tiles and solid pills.
  // ==========================================================================

  const ROW_COLORS = ["primary", "success", "purple", "secondary", "danger", "pink", "info"];

  function colorFor(key) {
    const str = String(key || "");
    let hash = 0;
    for (let i = 0; i < str.length; i++) hash = (hash * 31 + str.charCodeAt(i)) >>> 0;
    return ROW_COLORS[hash % ROW_COLORS.length];
  }

  function avatarTile(icon, color) {
    return `<span class="avatar avatar-sm avatar-rounded bg-${color} ${iconTextClass(color)} flex-shrink-0"><i class="${icon}"></i></span>`;
  }

  function pill(text, color = "primary", icon = "") {
    return `<span class="badge bg-${color} ${iconTextClass(color)} list-pill">${icon ? `<i class="${icon} me-1"></i>` : ""}${text}</span>`;
  }

  /** Briefly highlight a just-saved row (tr[data-row-id]) so the change is easy to spot. */
  function flashRow(id) {
    if (id == null) return;
    const row = document.querySelector(`tr[data-row-id="${id}"]`);
    if (!row) return;
    row.classList.remove("list-row-flash");
    void row.offsetWidth; // restart the animation if it's already running
    row.classList.add("list-row-flash");
    row.scrollIntoView({ block: "nearest", behavior: "smooth" });
    setTimeout(() => row.classList.remove("list-row-flash"), 1800);
  }

  /** Small solid change pill for table cells, e.g. [▲ 4] (blank when no change/no previous). */
  function changePill(current, previous) {
    if (previous == null || current == null || current === previous) return "";
    const up = current > previous;
    return `<span class="stat-delta is-${up ? "up" : "down"} ms-2" title="vs previous"><i class="ri-arrow-${up ? "up" : "down"}-s-fill"></i>${Math.abs(current - previous)}</span>`;
  }

  // ==========================================================================
  // DEMOGRAPHICS SUBMISSIONS TABLE
  //
  // Shared between church/demographics-growth/index.php's History segment
  // and demographics-tracking.php's recent-submissions list - one render
  // function, two call sites, per the reusable-component principle.
  // ==========================================================================

  /**
   * A submission's period label, whichever cadence it was recorded at -
   * month, half-year, or (both null) a whole fiscal year. Never assumes
   * fiscal_month is present, since demographics_mode can be half_yearly/yearly.
   */
  function demographicPeriodLabel(row) {
    if (row.fiscal_month?.name) return `${row.fiscal_month.name} ${row.fiscal_year?.year || ""}`.trim();
    if (row.fiscal_semi_annual?.name) return row.fiscal_semi_annual.name;
    return row.fiscal_year?.year ? `Year ${row.fiscal_year.year}` : "-";
  }

  /**
   * Newest-first sort across any mix of monthly/half-yearly/yearly rows -
   * fiscal_year.year desc, then the month the period ends in desc
   * (fiscal_month.number for monthly rows, fiscal_semi_annual.number * 6 for
   * half-yearly, 12 for yearly). Sorting by fiscal_month.number
   * alone (an earlier, duplicated inline comparator) always evaluated to 0
   * for half-yearly rows, so H1 and H2 of the same year didn't reliably
   * order against each other - this is the one correct version, shared by
   * every page that needs "most recent submission first."
   */
  function sortSubmissionsNewestFirst(rows) {
    // Compare by the month each period ends in (H1 = 6, H2 = 12), so a church
    // that switched cadence still sorts correctly: March 2026 comes before
    // H2 2026, not after it. Yearly rows count as the end of the year.
    const periodNumber = (r) => (r.fiscal_month?.number ? r.fiscal_month.number : r.fiscal_semi_annual?.number ? r.fiscal_semi_annual.number * 6 : 12);
    return [...rows].sort((a, b) => {
      const ay = a.fiscal_year?.year || 0;
      const by = b.fiscal_year?.year || 0;
      if (ay !== by) return by - ay;
      return periodNumber(b) - periodNumber(a);
    });
  }

  /** "Mar 14, 2026" from a row's created_at - blank (not "Invalid Date") if the field is missing or unparseable. */
  function formatSubmittedDate(createdAt) {
    if (!createdAt) return "";
    const date = new Date(createdAt);
    if (Number.isNaN(date.getTime())) return "";
    return date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
  }

  /**
   * Change-direction pill next to Total Members - same bg-{color}-transparent
   * + arrow-icon convention as renderWidgetCard()'s trend badge, comparing
   * this row's total against the next-older row in `rows` (the array is
   * always passed in newest-first, per both call sites' sort). Returns ""
   * for the oldest row (nothing to compare against) or a zero change, so the
   * row doesn't get a pointless "no change" pill.
   */
  function renderMembersTrend(rows, index) {
    const current = rows[index].total_members;
    const previous = rows[index + 1] ? rows[index + 1].total_members : null;
    return changePill(current, previous);
  }

  function renderSubmissionsRows(rows, { onEdit = null, onView = null } = {}) {
    if (!rows || rows.length === 0) {
      return renderTableEmpty(4, "No submissions yet", "ri-file-list-3-line");
    }

    return rows
      .map((row, index) => {
        const period = demographicPeriodLabel(row);
        const canEdit = row.status === "draft" || row.status === "changes_requested";
        const meta = statusMeta(row.status);
        const submittedOn = formatSubmittedDate(row.created_at);

        const viewBtn = onView
          ? `<button type="button" class="btn btn-sm btn-outline-primary" onclick="${onView}(${row.id})">
               <i class="ri-eye-line me-1"></i>View
             </button>`
          : "";
        const editBtn = canEdit && onEdit
          ? `<button type="button" class="btn btn-sm btn-primary" onclick="${onEdit}(${row.id})">
               <i class="ri-edit-line me-1"></i>Edit
             </button>`
          : "";

        return `
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="avatar avatar-sm avatar-rounded bg-${meta.color} text-white flex-shrink-0">
                  <i class="${meta.icon}"></i>
                </span>
                <div>
                  <span class="d-block fw-semibold">${period}</span>
                  ${submittedOn ? `<span class="d-block fs-11 text-body fw-semibold">Submitted ${submittedOn}</span>` : ""}
                </div>
              </div>
            </td>
            <td data-order="${row.total_members ?? 0}"><span class="fw-bold fs-15">${row.total_members ?? "-"}</span>${renderMembersTrend(rows, index)}</td>
            <td data-search="${meta.label}">${renderStatusBadge(row.status)}</td>
            <td class="text-end"><div class="d-flex justify-content-end gap-1">${viewBtn}${editBtn}</div></td>
          </tr>`;
      })
      .join("");
  }

  // ==========================================================================
  // COMPLETENESS BAR
  //
  // Static shell is rendered server-side (includes/ui-helpers-templates.php
  // renderCompletenessBar()) since it's a single fixed instance per page -
  // this just updates it at runtime as the user fills the form.
  // ==========================================================================

  function updateCompletenessBar(containerId, filledCount, totalFields) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const pct = totalFields > 0 ? Math.round((filledCount / totalFields) * 100) : 0;
    const bar = container.querySelector("[data-completeness-bar]");
    const label = container.querySelector("[data-completeness-label]");
    if (bar) bar.style.width = `${pct}%`;
    if (label) label.textContent = `${pct}%`;
  }

  // ==========================================================================
  // PUBLIC API
  // ==========================================================================

  return {
    resolveUserTerritory,
    renderStatusBadge,
    brandHex,
    renderCompactCard,
    renderTileCard,
    colorFor,
    avatarTile,
    pill,
    changePill,
    flashRow,
    skeletonCards,
    renderSparkCard,
    mountSparklines,
    chartTextColor,
    cssColor,
    renderSegmented,
    wireSegmented,
    renderRingDonut,
    renderCompositionCard,
    DEMOGRAPHIC_METRICS,
    metricUrl,
    shortPeriodLabel,
    monthlySeries,
    rowsInMonth,
    monthLabel,
    periodDelta,
    deltaBadge,
    renderStatCard,
    renderStatCardsRow,
    renderWidgetCard,
    renderWidgetCardsRow,
    renderSolidStatCard,
    trendFor,
    renderTrendChart,
    renderTimeline,
    renderPillLegend,
    renderDonutChart,
    renderComboChart,
    renderInsightCallout,
    setButtonLoading,
    restoreButton,
    renderTableLoading,
    renderTableEmpty,
    initListDataTable,
    renderFilterToolbar,
    wireFilterToolbar,
    enhanceSelect,
    syncSelect,
    numberStepperHtml,
    initSteppers,
    renderSubmissionsRows,
    demographicPeriodLabel,
    sortSubmissionsNewestFirst,
    updateCompletenessBar,
  };
})();
