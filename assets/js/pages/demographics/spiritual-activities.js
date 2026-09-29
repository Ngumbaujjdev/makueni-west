/**
 * ============================================================================
 * PAGE - SPIRITUAL ACTIVITIES (church/demographics-growth/spiritual-activities.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Year switch -> 4 KPI cards (year total, change vs the previous period,
 * per-period sparkline) -> chart card with tabs (All | Baptisms | Holy Communion
 * | Conversions | Departures) + this year's mix donut -> a period table with
 * filters, sorting and a totals row. "All" is the grouped column chart with
 * toggle chips; an activity tab shows that activity alone with its year
 * total / best period / average and highlights its slice in the donut.
 * Tabs re-render only the chart card (no refetch); the tab is kept in the
 * URL (?tab=baptisms).
 *
 * Data: GET /demographics-reports/widgets (`data.months`, one row per
 * period; null figures = nothing approved for that period).
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery DataTables
 * ============================================================================
 */

const SpiritualActivities = (function () {
  "use strict";

  const UI = DemographicsUI;
  const ACTIVITIES = [
    { field: "baptisms_count", metric: "baptisms", label: "Baptisms", icon: "ri-drop-line", color: "primary" },
    { field: "communion_participants_count", metric: "communion", label: "Holy Communion", icon: "ri-cup-line", color: "secondary" },
    { field: "conversions_count", metric: "conversions", label: "Conversions", icon: "ri-heart-line", color: "purple" },
    { field: "transferred_out_count", metric: "departures", label: "Departures", icon: "ri-user-unfollow-line", color: "danger" },
  ];

  let years = [];
  let chart = null;
  let donut = null;
  let months = [];
  let tab = "all";

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const result = await DemographicsAPIHandler.getFiscalYears();
    const thisYear = new Date().getFullYear();
    years = (result.success ? result.data || [] : []).filter((y) => y.year <= thisYear).sort((a, b) => b.year - a.year).slice(0, 5);
    if (years.length === 0) {
      document.getElementById("activityTableBody").innerHTML = UI.renderTableEmpty(6, "No fiscal years configured", "ri-calendar-line");
      return;
    }

    const requested = new URLSearchParams(window.location.search).get("tab");
    tab = ACTIVITIES.some((a) => a.metric === requested) ? requested : "all";
    renderTabs();

    const current = years.find((y) => y.year === thisYear) || years[0];
    document.getElementById("yearSwitchWrap").innerHTML = UI.renderSegmented(
      "yearSwitch",
      [...years].reverse().map((y) => ({ value: y.id, label: y.year })),
      current.id,
      { ariaLabel: "Fiscal year" },
    );
    UI.wireSegmented("yearSwitch", load);
    load(current.id);
  }

  const num = (v) => (v == null || v === "" ? null : Number(v));

  async function load(fiscalYearId) {
    UI.syncExportButton({ fiscalYearId });
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards(4);
    const result = await DemographicsAPIHandler.getDemographicsReportWidgets(USER_TERRITORY.id, { fiscal_year_id: fiscalYearId });
    if (!result.success) Toast.error(result.message || "Could not load activities");
    months = result.success ? result.data?.months || [] : [];
    const year = years.find((y) => String(y.id) === String(fiscalYearId))?.year;
    const reported = months.filter((m) => m.status === "approved");
    document.getElementById("activitySubtitle").textContent = `${year}: ${reported.length} of ${months.length} period${months.length === 1 ? "" : "s"} reported`;

    renderKpis(months);
    renderChart(months); // also draws the donut, focused on the open tab
    renderTable(months);
  }

  function renderTabs() {
    const tabs = [{ metric: "all", label: "All", icon: "ri-apps-2-line" }, ...ACTIVITIES];
    const el = document.getElementById("activityTabs");
    el.innerHTML = tabs
      .map(
        (t) => `
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link${t.metric === tab ? " active" : ""}" role="tab" aria-selected="${t.metric === tab}" data-tab="${t.metric}">
            ${t.icon ? `<i class="${t.icon} me-1"></i>` : ""}${t.label}
          </button>
        </li>`,
      )
      .join("");
    // On narrow screens the strip scrolls - bring the open tab into view.
    const active = el.querySelector(".nav-link.active");
    if (active && el.scrollWidth > el.clientWidth) {
      const offset = active.getBoundingClientRect().left - el.getBoundingClientRect().left;
      el.scrollLeft = offset - (el.clientWidth - active.offsetWidth) / 2;
    }
    el.querySelectorAll("[data-tab]").forEach((btn) =>
      btn.addEventListener("click", () => {
        if (btn.dataset.tab === tab) return;
        tab = btn.dataset.tab;
        el.querySelectorAll("[data-tab]").forEach((b) => {
          b.classList.toggle("active", b === btn);
          b.setAttribute("aria-selected", b === btn);
        });
        const params = new URLSearchParams(window.location.search);
        if (tab === "all") params.delete("tab");
        else params.set("tab", tab);
        const qs = params.toString();
        history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
        renderChart(months);
      }),
    );
  }

  function renderKpis(months) {
    const reported = months.filter((m) => m.status === "approved");
    const latest = reported[reported.length - 1];
    const previous = reported[reported.length - 2];
    const labels = months.map((m) => m.month);

    UI.renderStatCardsRow(
      "statCardsRow",
      ACTIVITIES.map((a) => ({
        icon: a.icon,
        label: `${a.label} this year`,
        value: months.reduce((s, m) => s + (num(m[a.field]) || 0), 0).toLocaleString(),
        color: a.color,
        delta:
          latest && previous ? UI.periodDelta(num(latest[a.field]) || 0, num(previous[a.field]) || 0, { percent: false, prevLabel: previous.month }) : null,
        series: months.length > 1 ? { labels, data: months.map((m) => num(m[a.field]) || 0) } : null,
        link: { href: UI.metricUrl(a.metric), text: "Full history" },
      })),
    );
  }

  function chartBase(height = 320) {
    return {
      chart: { type: "bar", height, toolbar: { show: false }, foreColor: UI.chartTextColor(), animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") } },
      xaxis: { categories: months.map((m) => m.month), labels: { rotate: 0, hideOverlappingLabels: true } },
      yaxis: { forceNiceScale: true, labels: { formatter: (v) => Math.round(v) } },
      dataLabels: { enabled: false },
      legend: { show: false },
      grid: { borderColor: "rgba(125,125,125,0.15)", strokeDashArray: 4 },
      responsive: [{ breakpoint: 576, options: { chart: { height: 260 } } }],
    };
  }

  function renderChart(months) {
    const el = document.getElementById("activityChart");
    const chipsEl = document.getElementById("activityChips");
    if (chart) chart.destroy();
    chart = null;
    const activity = ACTIVITIES.find((a) => a.metric === tab);
    renderDonut(months);

    document.getElementById("activityChartTitle").textContent = activity ? activity.label : "Per period";
    document.getElementById("activityChartSubtitle").textContent = activity ? "Recorded in each period this year" : "Tap an activity to show or hide it";

    if (months.length === 0) {
      chipsEl.innerHTML = "";
      el.innerHTML = '<p class="text-muted mb-0">No periods configured for this year.</p>';
      return;
    }

    el.innerHTML = "";
    if (activity) renderSingle(activity, el, chipsEl);
    else renderAll(el, chipsEl);
  }

  function renderAll(el, chipsEl) {
    chart = new ApexCharts(el, {
      ...chartBase(),
      series: ACTIVITIES.map((a) => ({ name: a.label, data: months.map((m) => num(m[a.field]) || 0) })),
      colors: ACTIVITIES.map((a) => UI.cssColor(a.color)),
      plotOptions: { bar: { columnWidth: "55%", borderRadius: 4 } },
      tooltip: { shared: true, intersect: false },
    });
    chart.render();

    chipsEl.innerHTML = ACTIVITIES.map(
      (a) =>
        `<button type="button" class="soft-chip soft-${a.color} chip-toggle is-on" data-series="${a.label}" aria-pressed="true"><span class="count-dot bg-${a.color}"></span>${a.label}</button>`,
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

  function renderSingle(activity, el, chipsEl) {
    const values = months.map((m) => num(m[activity.field]));
    const reported = months.map((m, i) => ({ month: m.month, v: values[i] })).filter((r) => r.v != null);
    const total = reported.reduce((s, r) => s + r.v, 0);
    const best = reported.reduce((b, r) => (b == null || r.v > b.v ? r : b), null);
    const avg = reported.length ? Math.round((total / reported.length) * 10) / 10 : null;
    const perLabel = months.length === 1 ? "year" : months.length === 2 ? "half" : "month";

    chipsEl.innerHTML = `
      <span class="soft-chip soft-${activity.color}">Year total · <b>${total.toLocaleString()}</b></span>
      <span class="soft-chip soft-primary">Best period · <b>${best && best.v > 0 ? `${best.month} (${best.v.toLocaleString()})` : "-"}</b></span>
      <span class="soft-chip soft-purple">Average per ${perLabel} · <b>${avg == null ? "-" : avg.toLocaleString()}</b></span>
      <a href="${UI.metricUrl(activity.metric)}" class="activity-history-link ms-auto">Full history <i class="ri-arrow-right-line"></i></a>`;

    chart = new ApexCharts(el, {
      ...chartBase(),
      series: [{ name: activity.label, data: values.map((v) => v || 0) }],
      colors: [UI.cssColor(activity.color)],
      plotOptions: { bar: { columnWidth: months.length > 6 ? "45%" : "30%", borderRadius: 5 } },
      tooltip: { y: { formatter: (v, { dataPointIndex }) => (values[dataPointIndex] == null ? "Not reported" : v.toLocaleString()) } },
    });
    chart.render();
  }

  function renderDonut(months) {
    if (donut) donut.destroy();
    donut = null;
    const totals = ACTIVITIES.map((a) => months.reduce((s, m) => s + (num(m[a.field]) || 0), 0));
    const el = document.getElementById("activityDonut");
    if (totals.every((t) => t === 0)) {
      el.innerHTML = `
        <div class="list-empty">
          <span class="list-empty-icon bg-primary text-white"><i class="ri-pie-chart-line"></i></span>
          <div class="fw-semibold mt-2">Nothing recorded this year yet</div>
        </div>`;
      return;
    }
    donut = UI.renderRingDonut("activityDonut", {
      labels: ACTIVITIES.map((a) => a.label),
      series: totals,
      colors: ACTIVITIES.map((a) => a.color),
      centerLabel: "Recorded",
      focus: ACTIVITIES.findIndex((a) => a.metric === tab),
    });
  }

  function renderTable(months) {
    const tbody = document.getElementById("activityTableBody");
    const tfoot = document.getElementById("activityTableFoot");
    if (window.$ && $.fn.DataTable && $.fn.DataTable.isDataTable("#activityTable")) $("#activityTable").DataTable().destroy();

    if (months.length === 0) {
      tbody.innerHTML = UI.renderTableEmpty(6, "No periods configured for this year", "ri-calendar-line");
      tfoot.innerHTML = "";
    } else {
      tbody.innerHTML = months
        .map((m, i) => {
          const statusLabel = m.status === "approved" ? "Approved" : "Not submitted";
          return `
            <tr>
              <td class="fw-semibold" data-order="${i}">${m.month}</td>
              <td data-search="${statusLabel}">${UI.renderStatusBadge(m.status)}</td>
              ${ACTIVITIES.map((a) => {
                const v = num(m[a.field]);
                return v == null ? '<td class="text-end text-muted" data-order="-1">-</td>' : `<td class="text-end" data-order="${v}">${v.toLocaleString()}</td>`;
              }).join("")}
            </tr>`;
        })
        .join("");
      tfoot.innerHTML = `
        <tr class="stats-foot">
          <td>Year total</td>
          <td></td>
          ${ACTIVITIES.map((a) => `<td class="text-end">${months.reduce((s, m) => s + (num(m[a.field]) || 0), 0).toLocaleString()}</td>`).join("")}
        </tr>`;
    }

    UI.renderFilterToolbar("activityFilterToolbar", {
      searchPlaceholder: "Search periods...",
      filters: [
        {
          id: "activityStatusFilter",
          label: "All statuses",
          options: [
            { value: "Approved", label: "Approved" },
            { value: "Not submitted", label: "Not submitted" },
          ],
        },
      ],
    });
    const table = UI.initListDataTable("activityTable", { order: [[0, "asc"]], pageLength: 25, hideDefaultSearch: true, noun: "periods" });
    UI.wireFilterToolbar("activityFilterToolbar", table, [{ id: "activityStatusFilter", columnIndex: 1, exact: true }], { noun: "periods", urlSync: false });
  }

  return { init };
})();

window.SpiritualActivities = SpiritualActivities;
