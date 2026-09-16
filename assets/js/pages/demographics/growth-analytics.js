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
 * Layout is an inverted-pyramid hierarchy (research via Firecrawl into
 * dashboard-design patterns, 2026-09-16) rather than this module's usual
 * N-equal-cards-then-chart shape: one dominant hero number (renderHero,
 * Tier 1) with its own range control and folded-in insight sentence, two
 * secondary driver rows beside it (renderDrivers, Tier 2), and the chart
 * as the detail layer below (Tier 3) - still plain Bootstrap 5 card/grid/
 * avatar markup throughout, just arranged by importance instead of by
 * equal-weight cards.
 *
 * The hero and driver-row markup (2026-09-16, v2) is pulled directly from
 * two named files in docs/design/ynex-component-reference.md rather than
 * improvised: the hero number's identity row + size follow
 * crypto-currency-exchange.html's row-2 KPI card, and each driver row
 * follows invoice-list.html's dashed-divider `svg-icon-background` KPI
 * list (icon SVGs there swapped for this app's own `<i class="ri-...">`
 * convention - same container class, simpler markup).
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
    renderHero(rows);
    renderDrivers(rows);
    renderChart(rows);
  }

  /** Tier-1: the single dominant number - big, full-contrast, with its own trend badge and a folded-in plain-English insight sentence right underneath it (no separate callout box to jump to). */
  function renderHero(rows) {
    const container = document.getElementById("heroCard");
    if (!container) return;

    if (rows.length === 0) {
      container.innerHTML = '<p class="text-body fw-semibold mb-0">No approved submissions yet for this range.</p>';
      return;
    }

    const first = rows[0];
    const latest = rows[rows.length - 1];
    const previous = rows.length > 1 ? rows[rows.length - 2] : null;
    const trend = previous ? DemographicsUI.trendFor(latest.total_members, previous.total_members) : null;

    let trendHtml = "";
    if (trend) {
      if (trend.diff === 0) {
        trendHtml = `<span class="badge bg-primary-transparent text-primary fs-12"><i class="ri-subtract-line"></i> No change vs last period</span>`;
      } else {
        const trendColor = trend.diff > 0 ? "success" : "danger";
        const arrow = trend.diff > 0 ? "ri-arrow-up-line" : "ri-arrow-down-line";
        const sign = trend.diff > 0 ? "+" : "-";
        trendHtml = `<span class="badge bg-${trendColor}-transparent text-${trendColor} fs-12"><i class="${arrow}"></i> ${sign}${Math.abs(trend.diff)} vs last period</span>`;
      }
    }

    let insightHtml = "";
    if (rows.length >= 2) {
      const change = latest.total_members - first.total_members;
      const startYear = first.fiscal_year?.year;
      const endYear = latest.fiscal_year?.year;
      const sentence = change === 0
        ? `Membership stayed steady at ${latest.total_members} between ${startYear} and ${endYear}.`
        : `Grew from ${first.total_members} to ${latest.total_members} members between ${startYear} and ${endYear}.`;
      insightHtml = `<p class="fs-14 text-body fw-semibold mb-0 mt-3"><i class="ri-lightbulb-line text-warning me-1"></i>${sentence}</p>`;
    }

    container.innerHTML = `
      <div class="d-flex align-items-center gap-2 mb-3">
        <span class="avatar avatar-rounded avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-team-line"></i></span>
        <h6 class="fw-semibold mb-0">Total Members</h6>
      </div>
      <h1 class="fw-bold mb-2 display-6">${latest.total_members}</h1>
      ${trendHtml}
      ${insightHtml}`;
  }

  /** Tier-2: secondary driver metrics, demoted by construction - list rows inside one card, not their own full stat cards. */
  function renderDrivers(rows) {
    const container = document.getElementById("driverStats");
    if (!container) return;

    if (rows.length === 0) {
      container.innerHTML = '<li class="fs-13 text-body fw-semibold p-3">No data yet</li>';
      return;
    }

    const first = rows[0];
    const peak = rows.reduce((max, r) => (r.total_members > max.total_members ? r : max), rows[0]);
    const change = rows.length >= 2 ? rows[rows.length - 1].total_members - first.total_members : null;
    const changePercent = change !== null && first.total_members ? Math.round((change / first.total_members) * 1000) / 10 : null;

    const drivers = [
      {
        icon: "ri-line-chart-line",
        color: change === null || change >= 0 ? "success" : "danger",
        label: first.fiscal_year?.year ? `Growth Since ${first.fiscal_year.year}` : "Growth",
        value: change === null ? "-" : `${change >= 0 ? "+" : ""}${change}`,
        sublabel: changePercent !== null ? `${changePercent >= 0 ? "+" : ""}${changePercent}%` : "",
      },
      {
        icon: "ri-trophy-line",
        color: "warning",
        label: "Best Year",
        value: peak.fiscal_year?.year ?? "-",
        sublabel: `${peak.total_members} members`,
      },
    ];

    container.innerHTML = drivers
      .map((d, i) => `
        <li class="d-flex align-items-top p-3${i < drivers.length - 1 ? " border-bottom border-block-end-dashed" : ""}">
          <div class="svg-icon-background bg-${d.color}-transparent me-3 flex-shrink-0">
            <i class="${d.icon} fs-16 text-${d.color}"></i>
          </div>
          <div class="flex-fill">
            <h6 class="mb-1 fs-12 text-body fw-semibold">${d.label}</h6>
            <h4 class="fs-18 fw-semibold mb-1">${d.value}</h4>
            <p class="text-muted fs-11 mb-0 lh-1">${d.sublabel}</p>
          </div>
        </li>`)
      .join("");
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
