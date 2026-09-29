/**
 * ============================================================================
 * PAGE - GROWTH ANALYTICS (church/demographics-growth/growth-analytics.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Toolbar (1Y | 3Y | 5Y | All) -> 4 KPI cards -> "Membership by category"
 * line chart with toggle chips + composition ring donut -> year-over-year
 * table (search + sorting). Each metric links to its own detail page
 * (metric.php) for the full history - this page stays an overview.
 *
 * Data: GET /demographics (every submission for the church). Only approved
 * submissions count; ranges are by fiscal year.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery DataTables
 * ============================================================================
 */

const GrowthAnalytics = (function () {
  "use strict";

  const UI = DemographicsUI;
  const M = UI.DEMOGRAPHIC_METRICS;
  const CATEGORY_KEYS = ["youth", "womens_fellowship", "mens_fellowship", "sunday_school", "seniors"];
  const TABLE_KEYS = ["total_members", ...CATEGORY_KEYS, "new_members", "departures", "baptisms", "communion", "conversions"];

  let approved = []; // oldest first
  let range = "3";
  let chart = null;
  let donut = null;

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const result = await DemographicsAPIHandler.getDemographics(USER_TERRITORY.id);
    if (!result.success) Toast.error(result.message || "Failed to load demographics");
    approved = UI.sortSubmissionsNewestFirst(result.success ? result.data || [] : [])
      .reverse()
      .filter((r) => r.status === "approved" && r.total_members != null);

    UI.wireSegmented("rangeSwitch", (value) => {
      range = value;
      UI.syncExportButton({ years: value });
      render();
    });
    render();
  }

  function rowsInRange() {
    if (range === "all") return approved;
    const years = [...new Set(approved.map((r) => r.fiscal_year?.year).filter(Boolean))].sort((a, b) => a - b);
    const keep = new Set(years.slice(-Number(range)));
    return approved.filter((r) => keep.has(r.fiscal_year?.year));
  }

  function render() {
    const rows = rowsInRange();
    const first = rows[0];
    const latest = rows[rows.length - 1];
    document.getElementById("analyticsSubtitle").textContent = rows.length
      ? `${rows.length} approved submission${rows.length === 1 ? "" : "s"} · ${UI.demographicPeriodLabel(first)} to ${UI.demographicPeriodLabel(latest)}`
      : "No approved submissions yet";

    renderKpis(rows);
    renderCategoryChart(rows);
    renderComposition(latest);
    renderYearTable(rows);
  }

  function renderKpis(rows) {
    if (rows.length === 0) {
      document.getElementById("statCardsRow").innerHTML = "";
      return;
    }
    const first = rows[0];
    const latest = rows[rows.length - 1];
    const totals = rows.map((r) => Number(r.total_members));
    const peakIndex = totals.indexOf(Math.max(...totals));
    const labels = rows.map(UI.shortPeriodLabel);
    const growthPct = totals[0] ? Math.round(((totals[totals.length - 1] - totals[0]) / totals[0]) * 1000) / 10 : null;
    const newMembers = rows.reduce((a, r) => a + (Number(r.new_members_count) || 0), 0);

    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: M.total_members.icon,
        label: "Members now",
        value: Number(latest.total_members).toLocaleString(),
        color: "primary",
        delta: rows.length > 1 ? UI.periodDelta(totals[totals.length - 1], totals[0], { prevLabel: UI.shortPeriodLabel(first) }) : null,
        series: rows.length > 1 ? { labels, data: totals } : null,
        link: { href: UI.metricUrl("total_members"), text: "Full history" },
      },
      {
        icon: "ri-line-chart-line",
        label: "Growth over this range",
        value: growthPct == null ? "-" : `${growthPct > 0 ? "+" : ""}${growthPct}%`,
        color: growthPct != null && growthPct < 0 ? "danger" : "success",
        sub: `${totals[0].toLocaleString()} → ${totals[totals.length - 1].toLocaleString()} members`,
      },
      {
        icon: "ri-trophy-line",
        label: "Best period",
        value: totals[peakIndex].toLocaleString(),
        color: "secondary",
        sub: UI.demographicPeriodLabel(rows[peakIndex]),
      },
      {
        icon: M.new_members.icon,
        label: "New members",
        value: newMembers.toLocaleString(),
        color: "purple",
        sub: "across this range",
        series: rows.length > 1 ? { labels, data: rows.map((r) => Number(r.new_members_count) || 0) } : null,
        link: { href: UI.metricUrl("new_members"), text: "Full history" },
      },
    ]);
  }

  function renderCategoryChart(rows) {
    const chipsEl = document.getElementById("categoryChips");
    const el = document.getElementById("categoryChart");
    if (chart) chart.destroy();
    chart = null;

    if (rows.length < 2) {
      chipsEl.innerHTML = "";
      el.innerHTML = `
        <div class="list-empty">
          <span class="list-empty-icon bg-primary text-white"><i class="ri-line-chart-line"></i></span>
          <div class="fw-semibold mt-2">Not enough history in this range</div>
          <div class="fs-12 text-muted">Pick a longer range, or check back after the next approved submission.</div>
        </div>`;
      return;
    }

    el.innerHTML = "";
    const series = CATEGORY_KEYS.map((k) => ({ name: M[k].label, data: rows.map((r) => Number(M[k].get(r)) || 0) }));
    chart = new ApexCharts(el, {
      chart: { type: "line", height: 320, toolbar: { show: false }, foreColor: UI.chartTextColor(), animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") } },
      series,
      colors: CATEGORY_KEYS.map((k) => UI.cssColor(M[k].color)),
      xaxis: { categories: rows.map(UI.shortPeriodLabel), labels: { rotate: 0, hideOverlappingLabels: true } },
      stroke: { width: 3, curve: "smooth" },
      markers: { size: 4 },
      legend: { show: false },
      grid: { borderColor: "rgba(125,125,125,0.15)", strokeDashArray: 4 },
      tooltip: { shared: true, intersect: false },
      responsive: [{ breakpoint: 576, options: { chart: { height: 260 }, markers: { size: 2 } } }],
    });
    chart.render();

    chipsEl.innerHTML = CATEGORY_KEYS.map(
      (k) =>
        `<button type="button" class="soft-chip soft-${M[k].color} chip-toggle is-on" data-series="${M[k].label}" aria-pressed="true"><span class="count-dot bg-${M[k].color}"></span>${M[k].label}</button>`,
    ).join("");
    chipsEl.querySelectorAll(".chip-toggle").forEach((btn) =>
      btn.addEventListener("click", () => {
        chart.toggleSeries(btn.dataset.series);
        const on = !btn.classList.contains("is-on");
        btn.classList.toggle("is-on", on);
        btn.setAttribute("aria-pressed", on);
      }),
    );
  }

  function renderComposition(latest) {
    document.getElementById("compositionSubtitle").textContent = latest ? UI.demographicPeriodLabel(latest) : "Latest submission";
    if (donut) donut.destroy();
    donut = null;
    const el = document.getElementById("compositionDonut");
    if (!latest) {
      el.innerHTML = '<p class="text-muted mb-0">No approved submission yet.</p>';
      return;
    }
    donut = UI.renderRingDonut("compositionDonut", {
      labels: CATEGORY_KEYS.map((k) => M[k].label.replace(" (13-35)", "")),
      series: CATEGORY_KEYS.map((k) => Number(M[k].get(latest)) || 0),
      colors: CATEGORY_KEYS.map((k) => M[k].color),
      centerLabel: "In groups",
    });
  }

  /** Latest approved submission in each fiscal year of the range. */
  function perYear(rows) {
    const byYear = new Map();
    rows.forEach((r) => byYear.set(r.fiscal_year?.year, r)); // rows are oldest-first, so the last one wins
    return [...byYear.entries()].filter(([y]) => y != null).sort((a, b) => a[0] - b[0]);
  }

  function renderYearTable(rows) {
    const years = perYear(rows);
    const head = document.getElementById("yoyHead");
    const body = document.getElementById("yoyBody");

    // The columns change with the range, so the old DataTable has to go
    // before the header is rewritten.
    if (window.$ && $.fn.DataTable && $.fn.DataTable.isDataTable("#yoyTable")) $("#yoyTable").DataTable().destroy();

    head.innerHTML = `<tr><th class="stats-sticky">Metric</th>${years.map(([y]) => `<th class="text-end">${y}</th>`).join("")}</tr>`;

    if (years.length === 0) {
      body.innerHTML = UI.renderTableEmpty(2, "No approved submissions yet", "ri-table-line");
    } else {
      body.innerHTML = TABLE_KEYS.map((k) => {
        const cells = years.map(([, row], i) => {
          const v = M[k].get(row);
          const prev = i > 0 ? M[k].get(years[i - 1][1]) : null;
          if (v == null) return `<td class="text-end text-muted" data-order="-1">-</td>`;
          return `<td class="text-end" data-order="${v}"><span class="fw-semibold">${Number(v).toLocaleString()}</span>${prev != null ? UI.changePill(Number(v), Number(prev)) : ""}</td>`;
        });
        return `
          <tr>
            <td class="stats-sticky" data-search="${M[k].label}" data-order="${M[k].label}">
              <a href="${UI.metricUrl(k)}" class="d-inline-flex align-items-center gap-2 fw-semibold text-reset">
                <span class="kpi-icon bg-${M[k].color} ${M[k].color === "secondary" ? "text-dark" : "text-white"}"><i class="${M[k].icon}"></i></span>${M[k].label}
              </a>
            </td>
            ${cells.join("")}
          </tr>`;
      }).join("");
    }

    UI.renderFilterToolbar("yoyFilterToolbar", { searchPlaceholder: "Search metrics..." });
    const table = UI.initListDataTable("yoyTable", { order: [], pageLength: 25, hideDefaultSearch: true, noun: "metrics" });
    UI.wireFilterToolbar("yoyFilterToolbar", table, [], { noun: "metrics", urlSync: false });
  }

  return { init };
})();

window.GrowthAnalytics = GrowthAnalytics;
