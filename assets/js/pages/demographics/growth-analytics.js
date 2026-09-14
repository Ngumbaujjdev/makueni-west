/**
 * ============================================================================
 * PAGE - GROWTH ANALYTICS (multi-year membership trend, kept deliberately simple)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Growth Overview is a single fiscal year's snapshot; this is the
 * multi-year view that page's own comments always pointed at - but kept to
 * one plain number per year (Total Members) rather than a multi-metric
 * breakdown, so it reads at a glance instead of needing analytical
 * literacy. Built entirely from GET /demographics?territory_id= (already
 * used everywhere in this module) - no new backend.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, ApexCharts
 * ============================================================================
 */

const GrowthAnalytics = (function () {
  "use strict";

  /** Every approved submission with a total_members value, newest-first. */
  let approvedRows = [];
  /** Years back from the latest to show - null means all time. */
  let currentRangeYears = null;

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));
    wireRangeButtons();
    await loadData();
    render();
  }

  async function loadData() {
    const result = await DemographicsAPIHandler.getDemographics(USER_TERRITORY.id);
    const rows = result.success && result.data ? result.data : [];
    // Same rule every rollup in this module already follows - a draft's
    // provisional numbers haven't been finalized yet.
    const approved = rows.filter((r) => r.status === "approved" && r.total_members !== null && r.total_members !== undefined);
    approvedRows = DemographicsUI.sortSubmissionsNewestFirst(approved);
  }

  /** One row per fiscal year (the latest approved submission that year), oldest-to-newest - a church on monthly cadence has many submissions per year, but a yearly trend needs one point per year, not twelve. */
  function yearlyRows() {
    const seenYears = new Set();
    const latestPerYear = [];
    approvedRows.forEach((r) => {
      if (!seenYears.has(r.fiscal_year_id)) {
        seenYears.add(r.fiscal_year_id);
        latestPerYear.push(r);
      }
    });
    return latestPerYear.reverse();
  }

  function currentRangeRows() {
    const yearly = yearlyRows();
    return currentRangeYears == null ? yearly : yearly.slice(-currentRangeYears);
  }

  function wireRangeButtons() {
    document.querySelectorAll("#rangeQuickSelect button").forEach((btn) => {
      btn.addEventListener("click", () => {
        document.querySelectorAll("#rangeQuickSelect button").forEach((b) => {
          b.classList.remove("btn-primary", "active");
          b.classList.add("btn-outline-primary");
        });
        btn.classList.remove("btn-outline-primary");
        btn.classList.add("btn-primary", "active");
        currentRangeYears = btn.dataset.range === "all" ? null : parseInt(btn.dataset.range, 10);
        render();
      });
    });
  }

  function render() {
    const rows = currentRangeRows();
    renderStatCards(rows);
    renderInsight(rows);
    renderChart(rows);
  }

  function renderStatCards(rows) {
    const container = document.getElementById("statCardsRow");
    if (!container) return;

    if (rows.length === 0) {
      container.innerHTML = '<div class="col-12"><p class="text-body fw-semibold mb-0">No approved submissions yet for this range.</p></div>';
      return;
    }

    const first = rows[0];
    const latest = rows[rows.length - 1];
    const previous = rows.length > 1 ? rows[rows.length - 2] : null;
    const peak = rows.reduce((max, r) => (r.total_members > max.total_members ? r : max), rows[0]);
    const change = latest.total_members - first.total_members;
    const changePercent = first.total_members ? Math.round((change / first.total_members) * 1000) / 10 : null;

    const cards = [
      {
        icon: "ri-team-line",
        label: "Total Members Now",
        value: latest.total_members,
        color: "primary",
        trend: previous ? DemographicsUI.trendFor(latest.total_members, previous.total_members) : null,
      },
      {
        icon: "ri-line-chart-line",
        label: first.fiscal_year?.year ? `Growth Since ${first.fiscal_year.year}` : "Growth",
        value: `${change >= 0 ? "+" : ""}${change}`,
        color: change >= 0 ? "success" : "danger",
        sublabel: changePercent !== null ? `${changePercent >= 0 ? "+" : ""}${changePercent}%` : "",
      },
      {
        icon: "ri-trophy-line",
        label: "Best Year",
        value: peak.fiscal_year?.year ?? "-",
        color: "warning",
        sublabel: `${peak.total_members} members`,
      },
    ];

    container.innerHTML = cards.map((c) => `<div class="col-xl-4 col-lg-6 col-md-6">${DemographicsUI.renderSolidStatCard(c)}</div>`).join("");
  }

  function renderInsight(rows) {
    if (rows.length < 2) {
      DemographicsUI.renderInsightCallout("insightCallout", []);
      return;
    }

    const first = rows[0];
    const latest = rows[rows.length - 1];
    const change = latest.total_members - first.total_members;
    const startYear = first.fiscal_year?.year;
    const endYear = latest.fiscal_year?.year;

    const sentence = change === 0
      ? `Your church's membership stayed steady at ${latest.total_members} between ${startYear} and ${endYear}.`
      : `Your church ${change > 0 ? "grew" : "declined"} from ${first.total_members} to ${latest.total_members} members between ${startYear} and ${endYear}.`;

    DemographicsUI.renderInsightCallout("insightCallout", [sentence]);
  }

  function renderChart(rows) {
    const el = document.getElementById("growthChart");
    if (!el) return;
    // Clear first - ApexCharts doesn't replace a prior instance in the same
    // container on its own, and this chart re-renders every time the Quick
    // Select range changes.
    el.innerHTML = "";

    if (rows.length < 2) {
      el.innerHTML = '<p class="text-center text-body fw-semibold py-4 mb-0">Not enough approved years yet to show a trend</p>';
      return;
    }

    const categories = rows.map((r) => (r.fiscal_year?.year ? String(r.fiscal_year.year) : DemographicsUI.demographicPeriodLabel(r)));
    const values = rows.map((r) => r.total_members);

    DemographicsUI.renderTrendChart("growthChart", {
      categories,
      series: [{ name: "Total Members", data: values }],
      type: "area",
      color: "primary",
    });
  }

  return { init };
})();

window.GrowthAnalytics = GrowthAnalytics;
