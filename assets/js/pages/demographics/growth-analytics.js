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
 * Chart segment picker (2026-09-16, v3): the chart isn't locked to Total
 * Members - a "Gender"/"Sunday School" toggle swaps it to a genuine
 * comparison (SEGMENTS map), each with its own latest-value cards below it
 * (renderSegmentBreakdownCards). DemographicsUI.renderTrendChart() gained
 * an optional `colors` array for this - without it, a multi-series chart
 * would render every line in the same single derived hue.
 *
 * Stacked mixed chart (2026-09-16, v4): a segment's two categories now
 * render as stacked columns (bar height = the combined total, each
 * segment's share visible within it) with a synthetic "Total" line
 * overlaid - shows the actual split, not just two crossing trends.
 * DemographicsUI.renderTrendChart() gained a "mixed" type + `stacked` flag
 * for this, modeled on the template's own apexcharts-mixed.js (bar+line
 * combo) and apexcharts-column.js (stacked:true) examples.
 *
 * Sunday School Teachers segment (2026-09-17, v5): a single-series segment
 * (sunday_school_teachers_count is a lone manual counter, no Male/Female
 * pair to stack) - renders as its own simple trend line instead of the
 * stacked-columns-plus-Total treatment above, which only makes sense for
 * 2+ categories. Clergy (Pastors/Associate Pastors) deliberately excluded
 * from this chart - that data comes from GET /churches/{id}/clergy-summary
 * (user_territory_assignments, "live, not stored" per the module spec),
 * so it has no fiscal-year history to plot as a trend; it already shows
 * live on View Submission's "Pastors & Assistant Pastors" card.
 *
 * Youth/Fellowship/Seniors segments (2026-09-17, v6): same two patterns as
 * v5/v3 above, just more of them - Youth and Seniors are single-series
 * (their own sub-counts, no pair to stack), Fellowship is a Men's/Women's
 * pair (stacked columns + Total line, same as Gender/Sunday School).
 *
 * Comparison table (2026-09-17, v7): "comparative" from the ClickUp scope
 * for this page - every tracked category as rows, fiscal years as columns,
 * same range-filtered data the chart already renders from (renderComparisonTable).
 * Church-tier only (no cross-church data ever reaches this page), so this
 * compares the church's own categories/years against each other, not
 * against other churches.
 *
 * Heatmap (2026-09-17, v8): "heatmap" from the same ClickUp scope - same
 * categories/years as the comparison table, color intensity instead of raw
 * numbers (renderComparisonHeatmap). DemographicsUI.renderTrendChart()
 * gained a "heatmap" type for this, modeled on the template's own
 * assets/js/apexcharts-heatmap.js (one base color, ApexCharts auto-shades
 * light-to-dark across the value range - not a manually-built color scale).
 * Total Members excluded from the heatmap - it would dominate the shading
 * scale and wash out every sub-category next to it.
 *
 * Growth projection (2026-09-17, v9): "forecasting" from the same ClickUp
 * scope - one computed sentence appended to the hero card's insight line,
 * not a new chart or card. Average year-over-year change across the
 * currently-selected range, projected one step further - deliberately
 * simple (no real statistical model), explicitly "projected"/"~" language
 * rather than presented as a real number. Respects the 3/5/All range
 * buttons like everything else on this page, so switching ranges shows a
 * recent-trend vs. all-time-trend projection, not always the same number.
 *
 * Comparison table polish (2026-09-17, v10): the plain black-text table
 * from v7 read as boring - each row now gets the same icon + color already
 * established for it in SEGMENTS (Male=ri-men-line/primary, etc.), and
 * each value gets a small trend arrow against the previous column via
 * DemographicsUI.trendFor() - the same helper the hero card's own trend
 * badge already uses. table-bordered added in the PHP for visible grid
 * lines. Deliberately not a DataTables.js integration (search/sort/
 * pagination) - a materially bigger feature than a styling pass.
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
  /** Which chart breakdown is showing - "total" is the single-line default. */
  let currentSegment = "total";

  /** Two-way comparisons the chart can swap to - each a pair of raw ChurchDemographic columns (already present on every row from GET /demographics, no backend change needed). Male-coded entries always use "primary", female-coded always "secondary" - one consistent mapping across both segments, not per-chart reassignment. */
  const SEGMENTS = {
    gender: {
      series: [
        { key: "male_count", name: "Male", color: "primary", icon: "ri-men-line" },
        { key: "female_count", name: "Female", color: "secondary", icon: "ri-women-line" },
      ],
    },
    sunday_school: {
      series: [
        { key: "sunday_school_male_count", name: "Sunday School (Male)", color: "primary", icon: "ri-men-line" },
        { key: "sunday_school_female_count", name: "Sunday School (Female)", color: "secondary", icon: "ri-women-line" },
      ],
    },
    // Single-series segment - a sub-count like Youth/Seniors/Fellowship
    // (manual counter, no other data source per the module spec), not a
    // pair that splits a total, so it gets its own single trend line
    // rather than the stacked-columns-plus-Total treatment above.
    sunday_school_teachers: {
      series: [
        { key: "sunday_school_teachers_count", name: "Sunday School Teachers", color: "primary", icon: "ri-book-open-line" },
      ],
    },
    youth: {
      series: [
        { key: "youth_count", name: "Youth", color: "primary", icon: "ri-run-line" },
      ],
    },
    fellowship: {
      series: [
        { key: "mens_fellowship_count", name: "Men's Fellowship", color: "primary", icon: "ri-men-line" },
        { key: "womens_fellowship_count", name: "Women's Fellowship", color: "secondary", icon: "ri-women-line" },
      ],
    },
    seniors: {
      series: [
        { key: "seniors_count", name: "Seniors", color: "primary", icon: "ri-walk-line" },
      ],
    },
  };

  /** Every tracked category, for the year-by-year comparison table - same fields SEGMENTS above draws from, just flattened into one list instead of grouped into chart-friendly pairs. */
  const COMPARISON_CATEGORIES = [
    { key: "total_members", label: "Total Members", emphasize: true, icon: "ri-team-line", color: "primary" },
    { key: "male_count", label: "Male", icon: "ri-men-line", color: "primary" },
    { key: "female_count", label: "Female", icon: "ri-women-line", color: "secondary" },
    { key: "youth_count", label: "Youth", icon: "ri-run-line", color: "primary" },
    { key: "mens_fellowship_count", label: "Men's Fellowship", icon: "ri-men-line", color: "primary" },
    { key: "womens_fellowship_count", label: "Women's Fellowship", icon: "ri-women-line", color: "secondary" },
    { key: "sunday_school_male_count", label: "Sunday School (Male)", icon: "ri-men-line", color: "primary" },
    { key: "sunday_school_female_count", label: "Sunday School (Female)", icon: "ri-women-line", color: "secondary" },
    { key: "sunday_school_teachers_count", label: "Sunday School Teachers", icon: "ri-book-open-line", color: "primary" },
    { key: "seniors_count", label: "Seniors", icon: "ri-walk-line", color: "primary" },
  ];

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));
    wireRangeButtons();
    wireSegmentButtons();
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

  function wireSegmentButtons() {
    document.querySelectorAll("#chartSegmentSelect button").forEach((btn) => {
      btn.addEventListener("click", () => {
        document.querySelectorAll("#chartSegmentSelect button").forEach((b) => {
          b.classList.remove("btn-primary", "active");
          b.classList.add("btn-outline-primary");
        });
        btn.classList.remove("btn-outline-primary");
        btn.classList.add("btn-primary", "active");
        currentSegment = btn.dataset.segment;
        render();
      });
    });
  }

  function render() {
    const rows = currentRangeRows();
    renderHero(rows);
    renderDrivers(rows);
    renderSegmentBreakdownCards(rows);
    renderChart(rows);
    renderComparisonTable(rows);
    renderComparisonHeatmap(rows);
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

    let projectionHtml = "";
    if (rows.length >= 2) {
      // Average year-over-year change across the currently-selected range
      // (telescopes to (latest - first) / (n - 1) - the sum of every
      // consecutive step collapses to just the endpoints) - projecting one
      // step further at that same average rate. Deliberately simple (no
      // real statistical model) and clearly labeled as an estimate, not a
      // real number, matching this codebase's existing "no fabricated
      // precision" convention (e.g. DemographicsReportWidgetService's own
      // "never a fabricated 0" rule for missing data).
      const avgChange = (latest.total_members - first.total_members) / (rows.length - 1);
      const nextYear = (latest.fiscal_year?.year ?? 0) + 1;
      const projected = Math.round(latest.total_members + avgChange);
      if (latest.fiscal_year?.year) {
        projectionHtml = `<p class="fs-14 text-body fw-semibold mb-0 mt-2"><i class="ri-route-line text-primary me-1"></i>At the current rate, membership is projected to reach ~${projected} by ${nextYear}.</p>`;
      }
    }

    container.innerHTML = `
      <div class="d-flex align-items-center gap-2 mb-3">
        <span class="avatar avatar-rounded avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-team-line"></i></span>
        <h6 class="fw-semibold mb-0">Total Members</h6>
      </div>
      <h1 class="fw-bold mb-2 display-6">${latest.total_members}</h1>
      ${trendHtml}
      ${insightHtml}
      ${projectionHtml}`;
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

  /** Below the chart when a segment (not "total") is selected - the current segment's two categories as latest-value cards, reusing the shared stat-card renderer rather than new markup. */
  function renderSegmentBreakdownCards(rows) {
    const container = document.getElementById("segmentBreakdownRow");
    if (!container) return;

    const segment = SEGMENTS[currentSegment];
    if (!segment || rows.length === 0) {
      container.innerHTML = "";
      return;
    }

    const latest = rows[rows.length - 1];
    const previous = rows.length > 1 ? rows[rows.length - 2] : null;

    const cards = segment.series.map((s) => ({
      icon: s.icon,
      label: s.name,
      value: latest[s.key] ?? 0,
      color: s.color,
      trend: previous ? DemographicsUI.trendFor(latest[s.key] ?? 0, previous[s.key] ?? 0) : null,
    }));

    // A 2-series segment (Gender/Sunday School) keeps the existing half-width
    // pair; a 1-series segment (Sunday School Teachers) uses a narrower
    // column so a lone card doesn't stretch oddly wide.
    const colClass = segment.series.length === 1 ? "col-xl-4 col-lg-6 col-md-6" : "col-xl-6 col-lg-6 col-md-6";
    container.innerHTML = cards.map((c) => `<div class="${colClass}">${DemographicsUI.renderSolidStatCard(c)}</div>`).join("");
  }

  /** Comparative: every tracked category as rows, fiscal years as columns - the same range-filtered rows the chart/hero/drivers already use, just laid out for side-by-side reading instead of a trend line. */
  function renderComparisonTable(rows) {
    const head = document.getElementById("comparisonTableHead");
    const body = document.getElementById("comparisonTableBody");
    if (!head || !body) return;

    if (rows.length === 0) {
      head.innerHTML = '<th class="fw-semibold text-dark">Category</th>';
      body.innerHTML = DemographicsUI.renderTableEmpty(1, "No approved submissions yet for this range", "ri-table-line");
      return;
    }

    const yearLabel = (r) => (r.fiscal_year?.year ? String(r.fiscal_year.year) : DemographicsUI.demographicPeriodLabel(r));

    head.innerHTML = '<th class="fw-semibold text-dark">Category</th>' + rows.map((r) => `<th class="fw-semibold text-dark text-end">${yearLabel(r)}</th>`).join("");

    body.innerHTML = COMPARISON_CATEGORIES.map((cat) => {
      const cells = rows.map((r, i) => {
        const value = r[cat.key] ?? 0;
        // Trend arrow against the previous column - nothing on the first
        // column (no prior year to compare against), same DemographicsUI.trendFor()
        // the hero card's own trend badge already uses.
        const previous = i > 0 ? rows[i - 1] : null;
        const trend = previous ? DemographicsUI.trendFor(value, previous[cat.key] ?? 0) : null;
        let arrowHtml = "";
        if (trend && trend.diff !== 0) {
          const arrowColor = trend.diff > 0 ? "success" : "danger";
          const arrow = trend.diff > 0 ? "ri-arrow-up-line" : "ri-arrow-down-line";
          arrowHtml = ` <i class="${arrow} text-${arrowColor} fs-12"></i>`;
        }
        return `<td class="text-end${cat.emphasize ? " fw-semibold" : ""}">${value}${arrowHtml}</td>`;
      }).join("");

      return `
        <tr>
          <td class="fw-semibold">
            <i class="${cat.icon} text-${cat.color} me-2"></i>${cat.label}
          </td>
          ${cells}
        </tr>`;
    }).join("");
  }

  /** Same rows/categories as the comparison table, as color-intensity instead of raw numbers - excludes "Total Members" (would dominate the scale and wash out every sub-category next to it). */
  function renderComparisonHeatmap(rows) {
    const el = document.getElementById("growthHeatmap");
    if (!el) return;
    el.innerHTML = "";

    if (rows.length === 0) {
      el.innerHTML = '<p class="text-center text-body fw-semibold py-4 mb-0">No approved submissions yet for this range</p>';
      return;
    }

    const yearLabel = (r) => (r.fiscal_year?.year ? String(r.fiscal_year.year) : DemographicsUI.demographicPeriodLabel(r));
    const categories = rows.map(yearLabel);

    const series = COMPARISON_CATEGORIES.filter((cat) => !cat.emphasize).map((cat) => ({
      name: cat.label,
      data: rows.map((r, i) => ({ x: categories[i], y: r[cat.key] ?? 0 })),
    }));

    DemographicsUI.renderTrendChart("growthHeatmap", {
      categories,
      series,
      type: "heatmap",
      color: "primary",
    });
  }

  function renderChart(rows) {
    const el = document.getElementById("growthChart");
    if (!el) return;
    // Clear first - ApexCharts doesn't replace a prior instance in the same
    // container on its own, and this chart re-renders every time the Quick
    // Select range or the segment changes.
    el.innerHTML = "";

    if (rows.length < 2) {
      el.innerHTML = '<p class="text-center text-body fw-semibold py-4 mb-0">Not enough approved years yet to show a trend</p>';
      return;
    }

    const categories = rows.map((r) => (r.fiscal_year?.year ? String(r.fiscal_year.year) : DemographicsUI.demographicPeriodLabel(r)));
    const segment = SEGMENTS[currentSegment];

    if (!segment) {
      const values = rows.map((r) => r.total_members);
      DemographicsUI.renderTrendChart("growthChart", {
        categories,
        series: [{ name: "Total Members", data: values }],
        type: "area",
        color: "primary",
      });
      return;
    }

    if (segment.series.length === 1) {
      // Nothing to stack or overlay a Total line onto with a single
      // category (e.g. Sunday School Teachers) - just its own trend line.
      const s = segment.series[0];
      DemographicsUI.renderTrendChart("growthChart", {
        categories,
        series: [{ name: s.name, data: rows.map((r) => r[s.key] ?? 0) }],
        type: "line",
        color: s.color,
      });
      return;
    }

    // Stacked columns (Male + Female share of the combined total) with a
    // Total line overlaid - shows the split, not just two crossing trends.
    const columnSeries = segment.series.map((s) => ({ name: s.name, type: "column", data: rows.map((r) => r[s.key] ?? 0) }));
    const totalSeries = { name: "Total", type: "line", data: rows.map((r) => segment.series.reduce((sum, s) => sum + (r[s.key] ?? 0), 0)) };

    DemographicsUI.renderTrendChart("growthChart", {
      categories,
      series: [...columnSeries, totalSeries],
      type: "mixed",
      stacked: true,
      colors: [...segment.series.map((s) => DemographicsUI.brandHex(s.color)), DemographicsUI.brandHex("dark")],
    });
  }

  return { init };
})();

window.GrowthAnalytics = GrowthAnalytics;
