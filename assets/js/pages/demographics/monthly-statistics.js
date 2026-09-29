/**
 * ============================================================================
 * PAGE - MONTHLY STATISTICS (church/demographics-growth/monthly-statistics.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Toolbar (year switch) -> 4 KPI cards -> one period table with grouped
 * column headers, filters, sorting and a year-summary footer.
 *
 * Data: GET /demographics-reports/widgets for the chosen fiscal year
 * (DemographicsReportWidgetService). `data.months` is one row per period -
 * Jan-Dec for a monthly church, H1/H2 for half-yearly, one row for yearly -
 * and a period with no approved submission has null figures, shown as "-"
 * (never a made-up 0).
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery DataTables
 * ============================================================================
 */

const MonthlyStatistics = (function () {
  "use strict";

  const UI = DemographicsUI;

  // Table column order (must match the <thead> in monthly-statistics.php).
  const COLUMNS = [
    "total_members", "male_count", "female_count", "youth_count", "seniors_count",
    "mens_fellowship_count", "womens_fellowship_count", "sunday_school_male_count", "sunday_school_female_count",
    "new_members_count", "transferred_out_count", "baptisms_count", "communion_participants_count", "conversions_count",
  ];
  // Headcounts are a snapshot (the footer shows the latest); these are
  // things that happened during a period (the footer shows the year total).
  const FLOW_COLUMNS = new Set(["new_members_count", "transferred_out_count", "baptisms_count", "communion_participants_count", "conversions_count"]);

  let years = [];

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
      document.getElementById("statCardsRow").innerHTML = "";
      document.getElementById("monthlyStatsBody").innerHTML = UI.renderTableEmpty(16, "No fiscal years configured", "ri-calendar-line");
      return;
    }

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

  async function load(fiscalYearId) {
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards(4);
    document.getElementById("monthlyStatsBody").innerHTML = UI.renderTableLoading(16);
    document.getElementById("monthlyStatsFoot").innerHTML = "";

    const result = await DemographicsAPIHandler.getDemographicsReportWidgets(USER_TERRITORY.id, { fiscal_year_id: fiscalYearId });
    if (!result.success) Toast.error(result.message || "Could not load statistics");
    const months = result.success ? result.data?.months || [] : [];
    const year = years.find((y) => String(y.id) === String(fiscalYearId))?.year;

    const reported = months.filter((m) => m.total_members != null);
    document.getElementById("statsSubtitle").textContent = `${year}: ${reported.length} of ${months.length} period${months.length === 1 ? "" : "s"} reported`;

    renderKpis(months);
    renderTable(months);
  }

  function num(v) {
    return v == null || v === "" ? null : Number(v);
  }

  function renderKpis(months) {
    const reported = months.filter((m) => m.total_members != null);
    const latest = reported[reported.length - 1];
    const previous = reported[reported.length - 2];
    const labels = months.map((m) => m.month);
    const series = (key) => ({ labels, data: months.map((m) => num(m[key]) || 0) });
    const sum = (key) => months.reduce((a, m) => a + (num(m[key]) || 0), 0);
    // Year totals compared period to period isn't meaningful mid-year, so the
    // flow cards compare the latest reported period with the one before.
    const lastPair = (key) =>
      latest && previous ? UI.periodDelta(num(latest[key]) || 0, num(previous[key]) || 0, { percent: false, prevLabel: previous.month }) : null;

    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-team-line",
        label: latest ? `Members (${latest.month})` : "Total members",
        value: latest ? Number(latest.total_members).toLocaleString() : "-",
        color: "primary",
        delta: latest && previous ? UI.periodDelta(num(latest.total_members), num(previous.total_members), { prevLabel: previous.month }) : null,
        sub: latest ? "" : "Nothing reported yet",
        series: series("total_members"),
      },
      {
        icon: "ri-user-add-line",
        label: "New members this year",
        value: sum("new_members_count").toLocaleString(),
        color: "success",
        delta: lastPair("new_members_count"),
        series: series("new_members_count"),
      },
      {
        icon: "ri-drop-line",
        label: "Baptisms this year",
        value: sum("baptisms_count").toLocaleString(),
        color: "purple",
        delta: lastPair("baptisms_count"),
        series: series("baptisms_count"),
      },
      {
        icon: "ri-user-unfollow-line",
        label: "Departures this year",
        value: sum("transferred_out_count").toLocaleString(),
        color: "danger",
        delta: lastPair("transferred_out_count"),
        series: series("transferred_out_count"),
      },
    ]);
  }

  function cell(value, key) {
    const v = num(value);
    if (v == null) return `<td class="text-end text-muted" data-order="-1">-</td>`;
    const strong = key === "total_members" ? " fw-bold" : "";
    return `<td class="text-end${strong}" data-order="${v}">${v.toLocaleString()}</td>`;
  }

  function renderTable(months) {
    const tbody = document.getElementById("monthlyStatsBody");
    const tfoot = document.getElementById("monthlyStatsFoot");

    if (months.length === 0) {
      tbody.innerHTML = UI.renderTableEmpty(16, "No periods configured for this year", "ri-calendar-line");
      UI.initListDataTable("monthlyStatsTable", {});
      UI.renderFilterToolbar("statsFilterToolbar", { searchPlaceholder: "Search periods..." });
      UI.wireFilterToolbar("statsFilterToolbar", null, [], { noun: "periods", urlSync: false });
      return;
    }

    tbody.innerHTML = months
      .map((m, i) => {
        const statusLabel = m.status === "approved" ? "Approved" : "Not submitted";
        return `
          <tr>
            <td class="stats-sticky fw-semibold" data-order="${i}">${m.month}</td>
            <td data-search="${statusLabel}">${UI.renderStatusBadge(m.status)}</td>
            ${COLUMNS.map((k) => cell(m[k], k)).join("")}
          </tr>`;
      })
      .join("");

    // Year summary: headcounts show the latest reported period, flows the year total.
    const reported = months.filter((m) => m.total_members != null);
    const latest = reported[reported.length - 1];
    tfoot.innerHTML = `
      <tr class="stats-foot">
        <td class="stats-sticky">Year summary</td>
        <td><span class="soft-chip soft-primary">${reported.length} of ${months.length}</span></td>
        ${COLUMNS.map((k) => {
          const v = FLOW_COLUMNS.has(k) ? months.reduce((a, m) => a + (num(m[k]) || 0), 0) : latest ? num(latest[k]) : null;
          return `<td class="text-end">${v == null ? "-" : v.toLocaleString()}</td>`;
        }).join("")}
      </tr>`;

    UI.renderFilterToolbar("statsFilterToolbar", {
      searchPlaceholder: "Search periods...",
      filters: [
        {
          id: "statsStatusFilter",
          label: "All statuses",
          options: [
            { value: "Approved", label: "Approved" },
            { value: "Not submitted", label: "Not submitted" },
          ],
        },
      ],
    });
    const table = UI.initListDataTable("monthlyStatsTable", { order: [[0, "asc"]], pageLength: 25, hideDefaultSearch: true, noun: "periods" });
    UI.wireFilterToolbar("statsFilterToolbar", table, [{ id: "statsStatusFilter", columnIndex: 1, exact: true }], {
      noun: "periods",
      urlSync: false,
    });
  }

  return { init };
})();

window.MonthlyStatistics = MonthlyStatistics;
