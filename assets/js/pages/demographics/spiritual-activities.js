/**
 * ============================================================================
 * PAGE - SPIRITUAL ACTIVITIES (church/demographics-growth/spiritual-activities.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Tabbed report: Baptisms / Communion / New Converts / Departures. All 4
 * tabs' data arrives in one DemographicsReportWidgetService response (one
 * ChurchDemographic row per church per month - there's no per-tab query to
 * make), so a fiscal-year change refetches once and every tab renders from
 * the same payload. Tabs render lazily on first `shown.bs.tab`, matching
 * attendance-reports.js's own pattern.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, ApexCharts,
 * Bootstrap tabs
 * ============================================================================
 */

const SpiritualActivities = (function () {
  "use strict";

  const METRICS = ["baptisms_count", "communion_participants_count", "conversions_count", "transferred_out_count"];

  let widgetsData = null;
  const charts = {};
  const renderedTabs = new Set();

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    wireTabs();
    await loadFiscalYears();
  }

  function wireTabs() {
    METRICS.forEach((metric) => {
      document.getElementById(`tab-${metric}-btn`).addEventListener("shown.bs.tab", () => {
        if (!renderedTabs.has(metric)) renderTab(metric);
      });
    });
  }

  function activeMetric() {
    const active = document.querySelector("#reportTabs .nav-link.active");
    return active ? active.id.replace("tab-", "").replace("-btn", "") : METRICS[0];
  }

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

    select.addEventListener("change", loadWidgets);
    await loadWidgets();
  }

  async function loadWidgets() {
    const fiscalYearId = document.getElementById("reportFiscalYear").value;
    if (!fiscalYearId) return;

    const result = await DemographicsAPIHandler.getDemographicsReportWidgets(USER_TERRITORY.id, { fiscal_year_id: fiscalYearId });
    widgetsData = result.success ? result.data : null;

    destroyAllCharts();
    renderedTabs.clear();
    renderTab(activeMetric());
  }

  function renderTab(metric) {
    renderedTabs.add(metric);

    const widget = widgetsData?.spiritual?.find((s) => s.metric === metric);
    if (!widget) return;

    DemographicsUI.renderWidgetCardsRow(`${metric}CardsRow`, widget.stats);

    charts[metric] = DemographicsUI.renderTrendChart(`${metric}Chart`, {
      categories: widget.chart.categories,
      series: widget.chart.series,
      type: "column",
      color: widget.color,
    });
  }

  function destroyAllCharts() {
    Object.keys(charts).forEach((key) => {
      if (charts[key] && typeof charts[key].destroy === "function") charts[key].destroy();
      delete charts[key];
    });
  }

  return { init };
})();

window.SpiritualActivities = SpiritualActivities;
