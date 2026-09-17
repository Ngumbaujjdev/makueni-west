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
 * Cards (2026-09-17): now DemographicsUI.renderSolidStatCard() like every
 * other Demographics page - was still the older pale-tint renderWidgetCard,
 * the one page in this module never migrated when the rest moved over.
 * Chart stays a plain single-color column - one metric per tab, nothing to
 * split, so the Growth Analytics stacked/mixed treatment doesn't apply here.
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

  /** Solid-icon cards, matching every other Demographics page - the backend already sends {label, value, icon, color} per card, just mapped through the shared renderer instead of the pale-tint renderWidgetCard. */
  function renderStatCards(containerId, stats) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = stats.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${DemographicsUI.renderSolidStatCard(c)}</div>`).join("");
  }

  function renderTab(metric) {
    renderedTabs.add(metric);

    const widget = widgetsData?.spiritual?.find((s) => s.metric === metric);
    if (!widget) return;

    renderStatCards(`${metric}CardsRow`, widget.stats);

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
