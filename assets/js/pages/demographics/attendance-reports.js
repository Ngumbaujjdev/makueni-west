/**
 * ============================================================================
 * PAGE - ATTENDANCE REPORTS (church/attendance/reports.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One page, two views of the same fiscal-year dataset (Dashboard / Table),
 * toggled the same way church/demographics-growth/index.php toggles
 * Overview/History - a single GET /attendance?fiscal_year_id= fetch per
 * year change feeds both views plus the category filter, no extra
 * round-trips per toggle/filter change.
 *
 * The Gathering Type Breakdown table is the actual point of this page -
 * it's the direct answer to "how much Kesha attendance this year, and
 * when did we not have any," the original problem that motivated building
 * configurable gathering types in the first place.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery + DataTables
 * ============================================================================
 */

const AttendanceReports = (function () {
  "use strict";

  let categories = [];
  let categoryById = {};
  let gatheringTypes = [];
  let allRows = [];
  let chart = null;
  let pendingSeries = null;
  let currentView = "dashboard";

  const CATEGORY_COLORS = ["#2CA4BF", "#26bf94", "#F2BE22"];
  const MONTH_NAMES = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    wireViewToggle();

    const categoriesResult = await DemographicsAPIHandler.getGatheringCategories();
    categories = categoriesResult.success ? categoriesResult.data || [] : [];
    categoryById = Object.fromEntries(categories.map((c) => [c.id, c]));

    const typesResult = await DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, { include_inactive: "true" });
    gatheringTypes = typesResult.success ? typesResult.data || [] : [];

    renderCategoryFilter();
    await loadFiscalYears();
  }

  // ==========================================================================
  // VIEW TOGGLE
  // ==========================================================================

  function wireViewToggle() {
    document.getElementById("viewDashboardBtn").addEventListener("click", () => switchView("dashboard"));
    document.getElementById("viewTableBtn").addEventListener("click", () => switchView("table"));
  }

  function switchView(view) {
    currentView = view;
    const dashBtn = document.getElementById("viewDashboardBtn");
    const tableBtn = document.getElementById("viewTableBtn");

    dashBtn.classList.toggle("btn-primary", view === "dashboard");
    dashBtn.classList.toggle("btn-outline-primary", view !== "dashboard");
    tableBtn.classList.toggle("btn-primary", view === "table");
    tableBtn.classList.toggle("btn-outline-primary", view !== "table");
    document.getElementById("dashboardView").style.display = view === "dashboard" ? "" : "none";
    document.getElementById("tableView").style.display = view === "table" ? "" : "none";

    // ApexCharts sizes itself wrong if built/updated while its container is
    // display:none - only ever touch the chart while Dashboard is visible.
    if (view === "dashboard" && pendingSeries) {
      applySeriesToChart(pendingSeries);
      pendingSeries = null;
    }
  }

  // ==========================================================================
  // FISCAL PERIOD + CATEGORY FILTER
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
    select.addEventListener("change", () => loadYearData(select.value));

    await loadYearData(defaultYear.id);
  }

  function renderCategoryFilter() {
    const select = document.getElementById("reportCategoryFilter");
    select.innerHTML = '<option value="">All Categories</option>' + categories.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join("");
    select.addEventListener("change", () => applyFilterAndRender());
  }

  async function loadYearData(fiscalYearId) {
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { fiscal_year_id: fiscalYearId });
    allRows = result.success ? result.data || [] : [];
    applyFilterAndRender();
  }

  function currentFilteredRows() {
    const categoryId = document.getElementById("reportCategoryFilter").value;
    return categoryId ? allRows.filter((r) => String(r.gathering_category_id) === categoryId) : allRows;
  }

  function applyFilterAndRender() {
    const rows = currentFilteredRows();
    renderStats(rows);
    renderTrendChart(rows);
    renderBreakdownTable(rows);
    renderTable(rows);
  }

  function totalFor(row) {
    return (row.adults_count || 0) + (row.youth_count || 0) + (row.children_male_count || 0) + (row.children_female_count || 0);
  }

  // ==========================================================================
  // STAT CARDS
  // ==========================================================================

  function renderStats(rows) {
    const totalAttendance = rows.reduce((sum, r) => sum + totalFor(r), 0);
    const avg = rows.length ? Math.round(totalAttendance / rows.length) : 0;
    const most = [...rows].sort((a, b) => totalFor(b) - totalFor(a))[0];

    DemographicsUI.renderStatCardsRow("statCardsRow", [
      { icon: "ri-calendar-check-line", label: "Gatherings Recorded", value: rows.length, color: "primary" },
      { icon: "ri-group-line", label: "Total Attendance", value: totalAttendance, color: "success" },
      { icon: "ri-bar-chart-line", label: "Average Attendance", value: avg, color: "warning" },
      {
        icon: "ri-trophy-line",
        label: "Most Attended",
        value: most ? `${totalFor(most)} on ${new Date(most.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short" })}` : "-",
        color: "secondary",
      },
    ]);
  }

  // ==========================================================================
  // MONTHLY TREND CHART
  // ==========================================================================

  function computeSeries(rows) {
    const categoryId = document.getElementById("reportCategoryFilter").value;

    if (categoryId) {
      const totals = new Array(12).fill(0);
      rows.forEach((r) => {
        totals[new Date(r.service_date).getMonth()] += totalFor(r);
      });
      return [{ name: categoryById[categoryId]?.name || "Attendance", data: totals }];
    }

    return categories.map((c) => {
      const totals = new Array(12).fill(0);
      rows.filter((r) => r.gathering_category_id === c.id).forEach((r) => {
        totals[new Date(r.service_date).getMonth()] += totalFor(r);
      });
      return { name: c.name, data: totals };
    });
  }

  function renderTrendChart(rows) {
    const series = computeSeries(rows);

    if (currentView !== "dashboard") {
      pendingSeries = series;
      return;
    }

    applySeriesToChart(series);
  }

  function applySeriesToChart(series) {
    if (chart) {
      chart.updateOptions({ series });
      return;
    }

    chart = new ApexCharts(document.getElementById("attendanceTrendChart"), {
      chart: { type: "bar", height: 320, stacked: true, toolbar: { show: false } },
      series,
      xaxis: { categories: MONTH_NAMES },
      colors: CATEGORY_COLORS,
      legend: { position: "bottom" },
      dataLabels: { enabled: false },
    });
    chart.render();
  }

  // ==========================================================================
  // GATHERING TYPE BREAKDOWN
  // ==========================================================================

  function renderBreakdownTable(rows) {
    const tbody = document.getElementById("breakdownTableBody");
    const categoryId = document.getElementById("reportCategoryFilter").value;
    const types = categoryId ? gatheringTypes.filter((t) => String(t.gathering_category_id) === categoryId) : gatheringTypes;

    if (types.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(6, "No gathering types configured yet", "ri-list-check-2");
      return;
    }

    tbody.innerHTML = types
      .map((t) => {
        const typeRows = rows.filter((r) => r.gathering_type_id === t.id);
        const total = typeRows.reduce((sum, r) => sum + totalFor(r), 0);
        const avg = typeRows.length ? Math.round(total / typeRows.length) : 0;
        const last = [...typeRows].sort((a, b) => new Date(b.service_date) - new Date(a.service_date))[0];
        const icon = t.icon || t.category?.icon || "ri-calendar-event-line";

        return `
          <tr>
            <td class="fw-semibold"><i class="${icon} me-2 text-primary"></i>${escapeHtml(t.name)}</td>
            <td><span class="badge bg-primary-transparent">${escapeHtml(t.category?.name || "-")}</span></td>
            <td>${typeRows.length ? typeRows.length : '<span class="badge bg-secondary">Not held this year</span>'}</td>
            <td>${total}</td>
            <td>${avg}</td>
            <td>${last ? new Date(last.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "-"}</td>
          </tr>`;
      })
      .join("");
  }

  // ==========================================================================
  // TABLE VIEW
  // ==========================================================================

  function renderTable(rows) {
    const tbody = document.getElementById("reportTableBody");

    if (rows.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(5, "No records for this period", "ri-calendar-line");
    } else {
      tbody.innerHTML = [...rows]
        .sort((a, b) => new Date(b.service_date) - new Date(a.service_date))
        .map((r) => {
          const date = new Date(r.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
          const catName = r.gathering_category?.name || categoryById[r.gathering_category_id]?.name || "-";
          const label = r.gathering_type?.name || r.event_name || catName;
          const icon = r.gathering_type?.icon || r.gathering_category?.icon || "ri-calendar-event-line";

          return `
            <tr>
              <td class="fw-semibold">${date}</td>
              <td><span class="badge bg-secondary-transparent">${escapeHtml(catName)}</span></td>
              <td><i class="${icon} me-1 text-primary"></i>${escapeHtml(label)}</td>
              <td>${totalFor(r)}</td>
              <td>${escapeHtml(r.notes || "-")}</td>
            </tr>`;
        })
        .join("");
    }

    DemographicsUI.initListDataTable("attendanceReportTable", {
      searchPlaceholder: "Search records...",
      order: [[0, "desc"]],
      nonSortableColumns: [],
      hideDefaultSearch: true,
    });

    // Category scoping is handled by the shared "Gathering" filter above the
    // view toggle (it drives the stat cards/chart too) - this toolbar is
    // just a search box over the already-scoped rows, not a second,
    // independent category filter that would only affect this one table.
    const table = $("#attendanceReportTable").DataTable();
    DemographicsUI.renderFilterToolbar("filterToolbar", { searchPlaceholder: "Search records..." });
    DemographicsUI.wireFilterToolbar("filterToolbar", table, []);
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
