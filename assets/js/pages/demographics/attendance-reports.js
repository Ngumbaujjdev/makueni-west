/**
 * ============================================================================
 * PAGE - ATTENDANCE REPORTS (church/attendance/reports.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Tabbed widget-dashboard: one tab per gathering category (Sunday Service /
 * Ministry Gatherings / Special Events - the same 3 categories that already
 * back services.php/ministries.php/events.php), plus a cross-tab summary
 * strip combining all 3. Stat cards, coverage math, trend/breakdown data all
 * come from the server (GET /attendance-reports/widgets -
 * AttendanceReportWidgetService) - the frontend only fetches raw records
 * (GET /attendance) for the per-tab records tables.
 *
 * Each tab's charts/sparklines are only ever built while its pane is
 * visible - ApexCharts mis-sizes itself inside a display:none container,
 * the same issue already solved for FullCalendar in the Ministry/Events
 * calendar toggle work. Tabs are rendered lazily on first `shown.bs.tab`;
 * a filter (year/month) change destroys everything and re-renders only the
 * currently active tab, leaving the others to lazy-render again next time
 * they're shown.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery + DataTables, Bootstrap tabs
 * ============================================================================
 */

const AttendanceReports = (function () {
  "use strict";

  let categoryBySlug = {};
  let allRecords = [];
  let widgetsByTab = { sunday: null, ministry: null, events: null };
  const renderedTabs = new Set();
  const charts = {};

  const TAB_CATEGORY_SLUG = { sunday: "sunday_service", ministry: "ministry_gathering", events: "special_event" };

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const categoriesResult = await DemographicsAPIHandler.getGatheringCategories();
    (categoriesResult.success ? categoriesResult.data || [] : []).forEach((c) => {
      categoryBySlug[c.slug] = c;
    });

    wireTabs();
    await loadFiscalYears();
  }

  // ==========================================================================
  // TABS
  // ==========================================================================

  function wireTabs() {
    ["sunday", "ministry", "events"].forEach((tab) => {
      document.getElementById(`tab-${tab}-btn`).addEventListener("shown.bs.tab", () => {
        if (!renderedTabs.has(tab)) renderTab(tab);
      });
    });
  }

  function activeTab() {
    const active = document.querySelector("#reportTabs .nav-link.active");
    return active ? active.id.replace("tab-", "").replace("-btn", "") : "sunday";
  }

  // ==========================================================================
  // FISCAL PERIOD FILTERS
  // ==========================================================================

  async function loadFiscalYears() {
    const select = document.getElementById("reportFiscalYear");
    const result = await DemographicsAPIHandler.getFiscalYears();

    if (!result.success || !result.data || result.data.length === 0) {
      select.innerHTML = '<option value="">No fiscal years configured</option>';
      return;
    }

    const years = result.data.sort((a, b) => b.year - a.year);
    select.innerHTML = years.map((y) => `<option value="${y.id}">${y.year}</option>`).join("");

    const currentYear = new Date().getFullYear();
    const defaultYear = years.find((y) => y.year === currentYear) || years[0];
    select.value = defaultYear.id;
    select.addEventListener("change", () => onYearChange(select.value));

    await onYearChange(defaultYear.id);
  }

  async function onYearChange(fiscalYearId) {
    await loadFiscalMonths(fiscalYearId);
    await loadPeriodData();
  }

  async function loadFiscalMonths(fiscalYearId) {
    const select = document.getElementById("reportFiscalMonth");
    const result = await DemographicsAPIHandler.getFiscalMonthsForYear(fiscalYearId);
    const months = result.success ? result.data || [] : [];

    select.innerHTML =
      '<option value="">All months</option>' + months.map((m) => `<option value="${m.id}">${m.name || m.short_name}</option>`).join("");
    select.value = "";
    select.onchange = () => loadPeriodData();
  }

  function currentFilters() {
    const fiscalYearId = document.getElementById("reportFiscalYear").value;
    const fiscalMonthId = document.getElementById("reportFiscalMonth").value;
    const filters = { fiscal_year_id: fiscalYearId };
    if (fiscalMonthId) filters.fiscal_month_id = fiscalMonthId;
    return filters;
  }

  // ==========================================================================
  // DATA LOADING (one records fetch + 4 widget fetches per filter change)
  // ==========================================================================

  async function loadPeriodData() {
    const filters = currentFilters();
    if (!filters.fiscal_year_id) return;

    destroyAllCharts();
    renderedTabs.clear();

    const [recordsResult, summaryResult, sundayResult, ministryResult, eventsResult] = await Promise.all([
      DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, filters),
      DemographicsAPIHandler.getAttendanceReportWidgets(USER_TERRITORY.id, filters),
      fetchTabWidgets("sunday", filters),
      fetchTabWidgets("ministry", filters),
      fetchTabWidgets("events", filters),
    ]);

    allRecords = recordsResult.success ? recordsResult.data || [] : [];
    widgetsByTab = { sunday: sundayResult, ministry: ministryResult, events: eventsResult };

    renderSummaryCards(summaryResult.success ? summaryResult.data : null);
    renderTab(activeTab());
  }

  async function fetchTabWidgets(tab, filters) {
    const category = categoryBySlug[TAB_CATEGORY_SLUG[tab]];
    if (!category) return null;
    const result = await DemographicsAPIHandler.getAttendanceReportWidgets(USER_TERRITORY.id, {
      ...filters,
      gathering_category_id: category.id,
    });
    return result.success ? result.data : null;
  }

  function recordsForTab(tab) {
    const category = categoryBySlug[TAB_CATEGORY_SLUG[tab]];
    if (!category) return [];
    return allRecords.filter((r) => r.gathering_category_id === category.id);
  }

  function totalFor(row) {
    return (row.adults_count || 0) + (row.youth_count || 0) + (row.children_male_count || 0) + (row.children_female_count || 0);
  }

  // ==========================================================================
  // CROSS-TAB SUMMARY STRIP
  // ==========================================================================

  function renderSummaryCards(data) {
    if (!data) {
      document.getElementById("summaryCardsRow").innerHTML = "";
      return;
    }
    DemographicsUI.renderWidgetCardsRow(
      "summaryCardsRow",
      data.stats.map((s) => ({ ...statToCardOpts(s) })),
    );
  }

  function statToCardOpts(s) {
    return { icon: s.icon, label: s.label, value: s.value, color: s.color, sparkline: s.sparkline };
  }

  // ==========================================================================
  // TAB RENDERING (lazy - only while the pane is visible)
  // ==========================================================================

  function renderTab(tab) {
    const data = widgetsByTab[tab];
    if (!data) return;

    renderedTabs.add(tab);

    if (tab === "sunday") renderSundayTab(data);
    else if (tab === "ministry") renderBreakdownTab("ministry", data);
    else renderBreakdownTab("events", data);
  }

  function renderSundayTab(data) {
    DemographicsUI.renderWidgetCardsRow("sundayCardsRow", data.stats.map(statToCardOpts));

    charts.sundayCoverageGauge = DemographicsUI.renderRadialGauge("sundayCoverageGauge", {
      label: "Coverage",
      percentage: data.coverage.percentage,
      color: "primary",
    });

    charts.sundayTrendChart = DemographicsUI.renderTrendChart("sundayTrendChart", {
      categories: data.chart.categories,
      series: data.chart.series,
      type: "area",
      color: "primary",
    });

    const rows = recordsForTab("sunday");
    renderRecordsTable("sunday", rows, false);
  }

  function renderBreakdownTab(tab, data) {
    const containerPrefix = tab === "ministry" ? "ministry" : "events";
    DemographicsUI.renderWidgetCardsRow(`${containerPrefix}CardsRow`, data.stats.map(statToCardOpts));

    const held = data.breakdown.filter((b) => b.times_held > 0);

    if (tab === "ministry") {
      charts.ministryDonutChart = held.length
        ? DemographicsUI.renderDonutChart("ministryDonutChart", {
            labels: held.map((b) => b.name),
            series: held.map((b) => b.total_attendance),
          })
        : null;

      charts.ministryComparisonChart = DemographicsUI.renderTrendChart("ministryComparisonChart", {
        categories: data.breakdown.map((b) => b.name),
        series: [{ name: "Total Attendance", data: data.breakdown.map((b) => b.total_attendance) }],
        type: "column",
        color: "success",
      });
    } else {
      charts.eventsComboChart = DemographicsUI.renderComboChart("eventsComboChart", {
        categories: data.breakdown.map((b) => b.name),
        barData: data.breakdown.map((b) => b.times_held),
        lineData: data.breakdown.map((b) => b.average_attendance),
        color: "secondary",
      });
    }

    renderBreakdownTable(containerPrefix, data.breakdown);

    const rows = recordsForTab(tab);
    renderRecordsTable(containerPrefix, rows, true);
  }

  function renderBreakdownTable(prefix, breakdown) {
    const tbody = document.getElementById(`${prefix}BreakdownTableBody`);

    if (!breakdown || breakdown.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(5, "No gathering types configured yet", "ri-list-check-2");
      return;
    }

    tbody.innerHTML = breakdown
      .map(
        (b) => `
        <tr>
          <td class="fw-semibold"><i class="${b.icon || "ri-calendar-event-line"} me-2 text-primary"></i>${escapeHtml(b.name)}</td>
          <td>${b.times_held ? b.times_held : '<span class="badge bg-secondary">Not held this year</span>'}</td>
          <td>${b.total_attendance}</td>
          <td>${b.average_attendance}</td>
          <td>${b.last_held ? escapeHtml(b.last_held) : "-"}</td>
        </tr>`,
      )
      .join("");
  }

  // ==========================================================================
  // RECORDS TABLE (per tab)
  // ==========================================================================

  function renderRecordsTable(prefix, rows, showGatheringColumn) {
    const tbody = document.getElementById(`${prefix}RecordsTableBody`);
    const colspan = showGatheringColumn ? 4 : 3;

    if (rows.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(colspan, "No records for this period", "ri-calendar-line");
    } else {
      tbody.innerHTML = [...rows]
        .sort((a, b) => new Date(b.service_date) - new Date(a.service_date))
        .map((r) => {
          const date = new Date(r.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
          const middleCell = showGatheringColumn
            ? `<td><i class="${r.gathering_type?.icon || "ri-calendar-event-line"} me-1 text-primary"></i>${escapeHtml(r.gathering_type?.name || r.event_name || "-")}</td>`
            : "";

          return `
            <tr>
              <td class="fw-semibold">${date}</td>
              ${middleCell}
              <td>${totalFor(r)}</td>
              <td>${escapeHtml(r.notes || "-")}</td>
            </tr>`;
        })
        .join("");
    }

    const tableId = `${prefix}RecordsTable`;
    DemographicsUI.initListDataTable(tableId, {
      searchPlaceholder: "Search records...",
      order: [[0, "desc"]],
      hideDefaultSearch: true,
    });

    const table = $(`#${tableId}`).DataTable();
    DemographicsUI.renderFilterToolbar(`${prefix}FilterToolbar`, { searchPlaceholder: "Search records..." });
    DemographicsUI.wireFilterToolbar(`${prefix}FilterToolbar`, table, []);
  }

  // ==========================================================================
  // HELPERS
  // ==========================================================================

  function destroyAllCharts() {
    Object.keys(charts).forEach((key) => {
      if (charts[key] && typeof charts[key].destroy === "function") charts[key].destroy();
      delete charts[key];
    });
  }

  function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return "";
    return unsafe
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  return { init };
})();

window.AttendanceReports = AttendanceReports;
