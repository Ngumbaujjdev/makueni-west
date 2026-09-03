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

  // Same-hue solid + faded pair (index-8.html's Jobs Summary donut pattern) - one brand color, not a forced second color.
  const BRAND_PRIMARY_SOLID = "#2CA4BF";
  const BRAND_PRIMARY_FADED = "rgba(44, 164, 191, 0.4)";

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
    wireClock();
    await loadFiscalYears();
  }

  // ==========================================================================
  // CLOCK + PERIOD SUMMARY (compact filter strip)
  // ==========================================================================

  function wireClock() {
    const timeEl = document.getElementById("reportClockTime");
    const dateEl = document.getElementById("reportClockDate");
    if (!timeEl || !dateEl) return;

    const tick = () => {
      const now = new Date();
      timeEl.textContent = now.toLocaleTimeString();
      dateEl.textContent = `${now.toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" })} · ${periodLabel()}`;
    };
    tick();
    setInterval(tick, 1000);
  }

  function periodLabel() {
    const yearSelect = document.getElementById("reportFiscalYear");
    const monthSelect = document.getElementById("reportFiscalMonth");
    if (!yearSelect || !yearSelect.options.length || !yearSelect.value) return "-";
    const yearLabel = yearSelect.options[yearSelect.selectedIndex]?.text || "-";
    const monthLabel = monthSelect.value ? monthSelect.options[monthSelect.selectedIndex]?.text : "Jan - Dec";
    return `FY ${yearLabel} · ${monthLabel}`;
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
    select.innerHTML = years.map((y) => `<option value="${y.id}" data-year="${y.year}">${y.year}</option>`).join("");

    const currentYear = new Date().getFullYear();
    const defaultYear = years.find((y) => y.year === currentYear) || years[0];
    select.value = defaultYear.id;
    select.addEventListener("change", () => onYearChange(select.value));

    wirePeriodPicker();
    await onYearChange(defaultYear.id);
  }

  async function onYearChange(fiscalYearId) {
    await loadFiscalMonths(fiscalYearId);
    await loadPeriodData();
    updatePeriodPickerLabel();
  }

  async function loadFiscalMonths(fiscalYearId) {
    const select = document.getElementById("reportFiscalMonth");
    const result = await DemographicsAPIHandler.getFiscalMonthsForYear(fiscalYearId);
    const months = result.success ? result.data || [] : [];

    select.innerHTML =
      '<option value="">All months</option>' +
      months.map((m) => `<option value="${m.id}" data-number="${m.number}">${m.name || m.short_name}</option>`).join("");
    select.value = "";
    select.onchange = () => {
      loadPeriodData();
      updatePeriodPickerLabel();
    };
  }

  function currentFilters() {
    const fiscalYearId = document.getElementById("reportFiscalYear").value;
    const fiscalMonthId = document.getElementById("reportFiscalMonth").value;
    const filters = { fiscal_year_id: fiscalYearId };
    if (fiscalMonthId) filters.fiscal_month_id = fiscalMonthId;
    return filters;
  }

  // ==========================================================================
  // POPOVER PERIOD PICKER (Quick Select + Custom Year/Month, Bootstrap dropdown)
  // ==========================================================================

  function wirePeriodPicker() {
    const menu = document.getElementById("periodPickerMenu");
    if (!menu || menu.dataset.wired) return;
    menu.dataset.wired = "true";

    menu.querySelectorAll("[data-quick]").forEach((btn) => {
      btn.addEventListener("click", () => applyQuickSelect(btn.dataset.quick));
    });

    document.getElementById("periodApplyBtn")?.addEventListener("click", async () => {
      await loadPeriodData();
      updatePeriodPickerLabel();
      closePeriodPicker();
    });

    document.getElementById("periodClearBtn")?.addEventListener("click", async () => {
      document.getElementById("reportFiscalMonth").value = "";
      await loadPeriodData();
      updatePeriodPickerLabel();
      closePeriodPicker();
    });
  }

  function closePeriodPicker() {
    const toggle = document.getElementById("periodPickerBtn");
    const instance = toggle && window.bootstrap ? window.bootstrap.Dropdown.getOrCreateInstance(toggle) : null;
    if (instance) instance.hide();
  }

  async function applyQuickSelect(key) {
    const now = new Date();
    let targetYear = now.getFullYear();
    let targetMonth = now.getMonth() + 1; // 1-12
    let clearMonth = false;

    if (key === "last_month") {
      targetMonth -= 1;
      if (targetMonth < 1) {
        targetMonth = 12;
        targetYear -= 1;
      }
    } else if (key === "this_year") {
      clearMonth = true;
    } else if (key === "last_year") {
      targetYear -= 1;
      clearMonth = true;
    } else if (key === "all_time") {
      const yearSelect = document.getElementById("reportFiscalYear");
      const oldest = [...yearSelect.options].sort((a, b) => Number(a.dataset.year) - Number(b.dataset.year))[0];
      if (oldest) targetYear = Number(oldest.dataset.year);
      clearMonth = true;
    }
    // "this_month" falls through with the defaults set above.

    const yearSelect = document.getElementById("reportFiscalYear");
    const yearOption = [...yearSelect.options].find((o) => Number(o.dataset.year) === targetYear);
    if (!yearOption) return;

    yearSelect.value = yearOption.value;
    await loadFiscalMonths(yearOption.value);

    if (!clearMonth) {
      const monthSelect = document.getElementById("reportFiscalMonth");
      const monthOption = [...monthSelect.options].find((o) => Number(o.dataset.number) === targetMonth);
      if (monthOption) monthSelect.value = monthOption.value;
    }

    await loadPeriodData();
    updatePeriodPickerLabel();
    closePeriodPicker();
  }

  function updatePeriodPickerLabel() {
    const el = document.getElementById("periodPickerLabel");
    if (el) el.textContent = periodLabel();
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
    return { icon: s.icon, label: s.label, value: s.value, color: s.color };
  }

  /** "Total / Best / Latest" legend row for a time-series chart (Sunday trend, Ministry comparison). */
  function chartLegendItems(categories, data, colors = ["primary", "warning", "success"]) {
    if (!data || data.length === 0) return [];
    const total = data.reduce((a, b) => a + b, 0);
    const maxIdx = data.indexOf(Math.max(...data));
    const nonZero = data.map((v, i) => ({ v, i })).filter((x) => x.v > 0);
    const latest = nonZero.length ? nonZero[nonZero.length - 1] : null;

    return [
      { label: "Total", value: total, color: colors[0] },
      { label: "Best", value: `${categories[maxIdx]} (${data[maxIdx]})`, color: colors[1] },
      latest ? { label: "Latest", value: `${categories[latest.i]} (${latest.v})`, color: colors[2] } : null,
    ].filter(Boolean);
  }

  /** Legend row for the Special Events combo chart - doesn't fit the time-series shape, so a bespoke summary. */
  function eventsLegendItems(breakdown) {
    if (!breakdown || breakdown.length === 0) return [];
    const mostHeld = [...breakdown].sort((a, b) => b.times_held - a.times_held)[0];
    const highestAvg = [...breakdown].sort((a, b) => b.average_attendance - a.average_attendance)[0];

    return [
      { label: "Types Tracked", value: breakdown.length, color: "primary" },
      { label: "Most Held", value: `${mostHeld.name} (${mostHeld.times_held}x)`, color: "warning" },
      { label: "Highest Avg", value: `${highestAvg.name} (${highestAvg.average_attendance})`, color: "success" },
    ];
  }

  /** Last 5 records for a tab's timeline card (renderTimeline), newest first. */
  function buildRecentItems(rows, tab) {
    return [...rows]
      .sort((a, b) => new Date(b.service_date) - new Date(a.service_date))
      .slice(0, 5)
      .map((r) => {
        const d = new Date(r.service_date);
        const day = d.toLocaleDateString("en-GB", { day: "numeric" });
        const weekday = d.toLocaleDateString("en-GB", { weekday: "short" });

        if (tab === "sunday") {
          return { day, weekday, title: "Sunday Service", badgeLabel: `${totalFor(r)} attendees`, badgeColor: "primary" };
        }

        return {
          day,
          weekday,
          title: r.gathering_type?.name || r.event_name || "-",
          badgeLabel: `${totalFor(r)} attendees`,
          badgeColor: tab === "ministry" ? "success" : "secondary",
        };
      });
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
    DemographicsUI.renderInsightCallout("sundayInsights", data.insights);

    const missed = Math.max(0, data.coverage.elapsed - data.coverage.recorded);
    charts.sundayCoverageGauge = DemographicsUI.renderDonutChart("sundayCoverageGauge", {
      labels: ["Recorded", "Missed"],
      series: [data.coverage.recorded, missed],
      colors: [BRAND_PRIMARY_SOLID, BRAND_PRIMARY_FADED],
      centerTotal: { label: "Total", value: data.coverage.elapsed },
    });

    DemographicsUI.renderPillLegend("sundayCoverageLegend", [
      { label: "Recorded", value: data.coverage.recorded, color: "primary" },
      { label: "Missed", value: missed, color: "secondary" },
    ]);

    const coverageCaptionEl = document.getElementById("sundayCoverageCaption");
    if (coverageCaptionEl) {
      coverageCaptionEl.textContent = `${data.coverage.recorded} of ${data.coverage.elapsed} expected Sundays have been recorded this period (${data.coverage.percentage}%).`;
    }

    DemographicsUI.renderChartLegendRow("sundayChartLegend", chartLegendItems(data.chart.categories, data.chart.series[0].data));

    charts.sundayTrendChart = DemographicsUI.renderTrendChart("sundayTrendChart", {
      categories: data.chart.categories,
      series: data.chart.series,
      type: "area",
      color: "primary",
    });

    DemographicsUI.renderStatColumns("sundayStatColumns", data.stat_columns);

    const rows = recordsForTab("sunday");
    DemographicsUI.renderTimeline("sundayRecentList", buildRecentItems(rows, "sunday"));
    renderRecordsTable("sunday", rows, false);
  }

  function renderBreakdownTab(tab, data) {
    const containerPrefix = tab === "ministry" ? "ministry" : "events";
    DemographicsUI.renderWidgetCardsRow(`${containerPrefix}CardsRow`, data.stats.map(statToCardOpts));
    DemographicsUI.renderInsightCallout(`${containerPrefix}Insights`, data.insights);

    const held = data.breakdown.filter((b) => b.times_held > 0);

    if (tab === "ministry") {
      charts.ministryDonutChart = held.length
        ? DemographicsUI.renderDonutChart("ministryDonutChart", {
            labels: held.map((b) => b.name),
            series: held.map((b) => b.total_attendance),
          })
        : null;

      DemographicsUI.renderPillLegend(
        "ministryDonutLegend",
        held.map((b, i) => ({ label: b.name, value: b.total_attendance, color: ["primary", "secondary", "success", "danger"][i % 4] })),
      );

      DemographicsUI.renderChartLegendRow(
        "ministryChartLegend",
        chartLegendItems(
          data.breakdown.map((b) => b.name),
          data.breakdown.map((b) => b.total_attendance),
          ["success", "warning", "primary"],
        ),
      );

      charts.ministryComparisonChart = DemographicsUI.renderTrendChart("ministryComparisonChart", {
        categories: data.breakdown.map((b) => b.name),
        series: [{ name: "Total Attendance", data: data.breakdown.map((b) => b.total_attendance) }],
        type: "column",
        color: "success",
      });
    } else {
      DemographicsUI.renderChartLegendRow("eventsChartLegend", eventsLegendItems(data.breakdown));

      charts.eventsComboChart = DemographicsUI.renderComboChart("eventsComboChart", {
        categories: data.breakdown.map((b) => b.name),
        barData: data.breakdown.map((b) => b.times_held),
        lineData: data.breakdown.map((b) => b.average_attendance),
        color: "secondary",
      });
    }

    renderBreakdownTable(containerPrefix, data.breakdown);

    const rows = recordsForTab(tab);
    DemographicsUI.renderTimeline(`${containerPrefix}RecentList`, buildRecentItems(rows, tab));
    renderRecordsTable(containerPrefix, rows, true);
  }

  const STATUS_BADGE = {
    on_track: { cls: "bg-success", label: "On Track" },
    inactive: { cls: "bg-warning text-dark", label: "Inactive" },
    never_held: { cls: "bg-secondary", label: "Not Held" },
  };

  const STATUS_AVATAR_COLOR = {
    on_track: "success",
    inactive: "warning",
    never_held: "secondary",
  };

  /**
   * Ranked "Top Selling Products"-style list: rank #, icon avatar colored
   * by status, and right-aligned Times Held/Total Attendance/Status
   * columns - already sorted by total attendance descending server-side.
   */
  function renderBreakdownTable(prefix, breakdown) {
    const tbody = document.getElementById(`${prefix}BreakdownTableBody`);

    if (!breakdown || breakdown.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(5, "No gathering types configured yet", "ri-list-check-2");
      return;
    }

    tbody.innerHTML = breakdown
      .map((b, i) => {
        const badge = STATUS_BADGE[b.status] || STATUS_BADGE.never_held;
        const avatarColor = STATUS_AVATAR_COLOR[b.status] || "secondary";
        const lastHeldTitle = b.last_held ? `Last held ${b.last_held}` : "Never held this period";

        return `
        <tr>
          <td class="fw-semibold text-body">${i + 1}</td>
          <td>
            <div class="d-flex align-items-center gap-2" title="${escapeHtml(lastHeldTitle)}">
              <span class="avatar avatar-md avatar-rounded bg-${avatarColor}">
                <i class="${b.icon || "ri-calendar-event-line"} text-white"></i>
              </span>
              <span class="fw-semibold">${escapeHtml(b.name)}</span>
            </div>
          </td>
          <td class="text-end">${b.times_held}</td>
          <td class="text-end fw-semibold">${b.total_attendance}</td>
          <td class="text-end"><span class="badge rounded-pill ${badge.cls}">${badge.label}</span></td>
        </tr>`;
      })
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
          const icon = r.gathering_type?.icon || "ri-calendar-event-line";
          const middleCell = showGatheringColumn
            ? `<td><span class="badge rounded-pill bg-primary-transparent text-primary">${escapeHtml(r.gathering_type?.name || r.event_name || "-")}</span></td>`
            : "";

          return `
            <tr>
              <td class="fw-semibold">
                <div class="d-flex align-items-center gap-2">
                  <span class="avatar avatar-md avatar-rounded bg-primary-transparent"><i class="${icon} text-primary"></i></span>
                  ${date}
                </div>
              </td>
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
    wireSortByDropdown(prefix, tableId, showGatheringColumn ? 2 : 1);
  }

  /**
   * index-8.html's Bills Summary "Sort By" dropdown pattern, wired to the
   * table's own DataTables order() API. Listeners are attached once
   * (dataset.wired guard) but always look up the *current* DataTables
   * instance by id at click time, since renderRecordsTable() destroys and
   * recreates the instance on every filter/period change - closing over a
   * table reference captured at wire-time would go stale after that.
   */
  function wireSortByDropdown(prefix, tableId, totalColumnIndex) {
    const container = document.getElementById(`${prefix}SortDropdown`);
    if (!container || container.dataset.wired) return;
    container.dataset.wired = "true";

    container.querySelectorAll("[data-sort]").forEach((item) => {
      item.addEventListener("click", () => {
        const table = $(`#${tableId}`).DataTable();
        if (item.dataset.sort === "date-asc") table.order([0, "asc"]).draw();
        else if (item.dataset.sort === "total-desc") table.order([totalColumnIndex, "desc"]).draw();
        else table.order([0, "desc"]).draw();
      });
    });
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
