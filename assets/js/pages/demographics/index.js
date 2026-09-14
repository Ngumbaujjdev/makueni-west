/**
 * ============================================================================
 * PAGE - CHURCH DEMOGRAPHICS & GROWTH LANDING (Overview / History)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Segmented [Overview] [History] landing page for
 * church/demographics-growth/index.php, per the PWA design reference's
 * pastor-demographics.html screen. Overview is a snapshot of one selected
 * fiscal year's latest submission (with a real trend badge vs. whatever
 * was submitted right before it, year boundary or not) - the full
 * multi-year/multi-metric drill-down lives on the separate Growth Analytics
 * page, not here. Cadence-agnostic throughout (monthly/half_yearly/yearly)
 * via DemographicsUI.sortSubmissionsNewestFirst()/demographicPeriodLabel().
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * Bootstrap modal
 * ============================================================================
 */

const DemographicsOverview = (function () {
  "use strict";

  let allRows = [];
  /** monthly/half_yearly/yearly - read once at load, before Overview first renders, so the Compliance card's period count is never wrong on first paint. */
  let currentMode = "monthly";

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));
    wireSegments();
    await loadDemographicsMode();
    loadAll();
  }

  function wireSegments() {
    const overviewBtn = document.getElementById("segmentOverviewBtn");
    const historyBtn = document.getElementById("segmentHistoryBtn");
    const overviewPane = document.getElementById("segmentOverview");
    const historyPane = document.getElementById("segmentHistory");

    overviewBtn.addEventListener("click", () => {
      overviewBtn.classList.add("btn-primary", "active");
      overviewBtn.classList.remove("btn-outline-primary");
      historyBtn.classList.add("btn-outline-primary");
      historyBtn.classList.remove("btn-primary", "active");
      overviewPane.style.display = "";
      historyPane.style.display = "none";
    });

    historyBtn.addEventListener("click", () => {
      historyBtn.classList.add("btn-primary", "active");
      historyBtn.classList.remove("btn-outline-primary");
      overviewBtn.classList.add("btn-outline-primary");
      overviewBtn.classList.remove("btn-primary", "active");
      historyPane.style.display = "";
      overviewPane.style.display = "none";
    });
  }

  async function loadAll() {
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const result = await DemographicsAPIHandler.getDemographics(USER_TERRITORY.id);

    if (!result.success) {
      Toast.error(result.message || "Failed to load demographics data");
      renderEmptyOverview();
      renderHistory([]);
      return;
    }

    allRows = DemographicsUI.sortSubmissionsNewestFirst(result.data || []);

    await loadFiscalYears();
    renderGrowthTrend();
    renderHistory(allRows);
  }

  async function loadFiscalYears() {
    const select = document.getElementById("reportFiscalYear");
    const result = await DemographicsAPIHandler.getFiscalYears();

    if (!result.success || !result.data || result.data.length === 0) {
      select.innerHTML = '<option value="">No fiscal years configured</option>';
      renderOverviewForYear(null);
      return;
    }

    const years = result.data.sort((a, b) => b.year - a.year);
    select.innerHTML = years.map((y) => `<option value="${y.id}">${y.year}</option>`).join("");

    const currentYear = new Date().getFullYear();
    const defaultYear = years.find((y) => y.year === currentYear) || years[0];
    select.value = defaultYear.id;

    select.addEventListener("change", () => renderOverviewForYear(select.value));
    renderOverviewForYear(select.value);
  }

  function renderOverviewForYear(fiscalYearId) {
    const yearRows = fiscalYearId ? allRows.filter((r) => String(r.fiscal_year_id) === String(fiscalYearId)) : [];
    const latest = yearRows[0] || null;

    // "vs last submission" compares to whatever was submitted right before
    // it chronologically, regardless of fiscal-year boundary (a January
    // submission's previous one is naturally last December's) - a
    // different question from Attendance's deliberate non-wrapping
    // fiscal-month comparison, which was about adjacency *within* one
    // selected year.
    const latestIndex = latest ? allRows.indexOf(latest) : -1;
    const previous = latestIndex >= 0 ? allRows[latestIndex + 1] || null : null;

    renderStatCards(latest, previous);
    renderGenderDonut(latest);
    renderComplianceCard(latest, yearRows.length);
  }

  function renderEmptyOverview() {
    renderStatCards(null, null);
    renderGenderDonut(null);
    renderComplianceCard(null, 0);
  }

  function trend(current, previous) {
    if (current === null || current === undefined || previous === null || previous === undefined || previous === 0) return null;
    const percent = Math.round(((current - previous) / previous) * 1000) / 10;
    return { direction: percent >= 0 ? "up" : "down", percent: Math.abs(percent), label: "vs last submission" };
  }

  /**
   * Solid-icon KPI cards (DemographicsUI.renderSolidStatCard, promoted from
   * View Submission) with an absolute-diff trend badge - one consistent
   * trend language across every Demographics surface instead of this page's
   * former percent-based badge, which also went silent whenever the
   * previous value was 0 (a real risk here: Women's/Men's Fellowship and
   * Sunday School can legitimately start at 0 for a newer congregation).
   * 3-up (not renderWidgetCard's 4-up) so 6 cards fill two even rows.
   */
  function renderStatCards(latest, previous) {
    const container = document.getElementById("statCardsRow");
    if (!container) return;

    const cards = [
      { icon: "ri-team-line", label: "Total Members", field: "total_members", color: "primary" },
      { icon: "ri-user-star-line", label: "Youth (13-35)", field: "youth_count", color: "success" },
      { icon: "ri-women-line", label: "Women's Fellowship", field: "womens_fellowship_count", color: "warning" },
      { icon: "ri-men-line", label: "Men's Fellowship", field: "mens_fellowship_count", color: "secondary" },
      { icon: "ri-graduation-cap-line", label: "Sunday School", field: null, color: "primary" },
      { icon: "ri-user-heart-line", label: "Seniors", field: "seniors_count", color: "success" },
    ];

    const sundaySchoolTotal = (row) => (row ? (row.sunday_school_male_count ?? 0) + (row.sunday_school_female_count ?? 0) : null);
    // Sunday School is the only headline card built from two sub-fields -
    // a bare combined number loses the split entirely, so it gets a
    // "N Male · N Female" sublabel alongside its trend badge (the only
    // other card here that needs one; the rest are already atomic counts,
    // and Total Members' own split is already shown by the Gender Split
    // donut right below, so repeating it there would just be clutter).
    const sundaySchoolSublabel = (row) => (row ? `${row.sunday_school_male_count ?? 0} Male &middot; ${row.sunday_school_female_count ?? 0} Female` : "");

    container.innerHTML = cards
      .map((c) => {
        const isSundaySchool = c.field === null;
        const rawValue = c.field ? latest?.[c.field] ?? null : latest ? sundaySchoolTotal(latest) : null;
        const prevValue = c.field ? previous?.[c.field] ?? null : previous ? sundaySchoolTotal(previous) : null;
        const opts = {
          icon: c.icon,
          label: c.label,
          value: rawValue ?? "-",
          color: c.color,
          trend: rawValue != null ? DemographicsUI.trendFor(rawValue, prevValue) : null,
          sublabel: isSundaySchool ? sundaySchoolSublabel(latest) : "",
        };
        return `<div class="col-xl-4 col-lg-6 col-md-6">${DemographicsUI.renderSolidStatCard(opts)}</div>`;
      })
      .join("");
  }

  /**
   * Short chart-axis label - "Jan 2026" for a monthly row (short_name, not
   * demographicPeriodLabel()'s full "January 2026", which would crowd a
   * years-long x-axis), "H1 2026"/"Year 2026" for half-yearly/yearly rows
   * via demographicPeriodLabel() (already compact - fiscal_semi_annual.name
   * is short by design).
   */
  function chartPeriodLabel(row) {
    if (row.fiscal_month) return `${row.fiscal_month.short_name || row.fiscal_month.name} ${row.fiscal_year?.year || ""}`.trim();
    return DemographicsUI.demographicPeriodLabel(row);
  }

  /**
   * Whole-history membership trend - deliberately not scoped to the
   * fiscal-year select above (a single year rarely has more than a
   * couple of submissions; growth only reads as a trend across years).
   * Legend/stat-column facts are chosen to avoid `total_members` itself
   * ever being averaged/summed across periods the way attendance counts
   * are - an "average membership across 3 years" would mix different
   * points in the church's growth into one number, the same
   * misrepresentation round 10's attendance fix avoided.
   */
  function renderGrowthTrend() {
    const legendEl = document.getElementById("growthChartLegend");
    const chartEl = document.getElementById("growthTrendChart");
    const columnsEl = document.getElementById("growthStatColumns");

    // Only approved submissions count toward this trend, same rule every
    // other rollup in this module already follows (DemographicsGrowthService,
    // DemographicsReportWidgetService) - a draft's provisional numbers
    // haven't been finalized yet.
    const rows = [...allRows].reverse().filter((r) => r.status === "approved" && r.total_members !== null && r.total_members !== undefined);

    if (rows.length < 2) {
      legendEl.innerHTML = "";
      chartEl.innerHTML = '<p class="text-center text-body fw-semibold py-4 mb-0">Not enough approved submission history yet to show a growth trend</p>';
      columnsEl.innerHTML = "";
      return;
    }

    const categories = rows.map((r) => chartPeriodLabel(r));
    const values = rows.map((r) => r.total_members);

    const first = values[0];
    const latest = values[values.length - 1];
    const peakIndex = values.indexOf(Math.max(...values));
    const growth = trend(latest, first);

    DemographicsUI.renderPillLegend("growthChartLegend", [
      { label: "Latest", value: latest, color: "primary" },
      { label: "Peak", value: `${categories[peakIndex]} (${values[peakIndex]})`, color: "warning" },
      growth ? { label: "Growth", value: `${growth.direction === "up" ? "+" : "-"}${growth.percent}% since first submission`, color: growth.direction === "up" ? "success" : "danger" } : null,
    ].filter(Boolean));

    DemographicsUI.renderTrendChart("growthTrendChart", {
      categories,
      series: [{ name: "Total Members", data: values }],
      type: "area",
      color: "primary",
    });

    const average = Math.round(values.reduce((a, v) => a + v, 0) / values.length);
    const yearsTracked = new Set(rows.map((r) => r.fiscal_year_id)).size;

    DemographicsUI.renderPillLegend("growthStatColumns", [
      { label: "Average Membership", value: average, color: "primary" },
      { label: "Submissions Logged", value: rows.length, color: "warning" },
      { label: "Years Tracked", value: yearsTracked, color: "success" },
    ]);
  }

  function renderGenderDonut(latest) {
    const el = document.getElementById("genderDonutChart");
    el.innerHTML = "";

    if (!latest || (!latest.male_count && !latest.female_count)) {
      el.innerHTML = '<p class="text-center text-body fw-semibold py-4 mb-0">No gender-split data submitted yet</p>';
      return;
    }

    const chart = new ApexCharts(el, {
      chart: { type: "donut", height: 260 },
      series: [latest.male_count || 0, latest.female_count || 0],
      labels: ["Male", "Female"],
      colors: ["#2CA4BF", "#F2BE22"],
      legend: { position: "bottom" },
      dataLabels: { enabled: true },
    });
    chart.render();
  }

  function renderComplianceCard(latest, submissionsThisYear) {
    const el = document.getElementById("complianceCard");
    const periodsPerYear = PERIODS_PER_YEAR[currentMode] || 12;

    if (!latest) {
      el.innerHTML = `
        <div class="text-center py-3">
          <i class="ri-file-warning-line fs-30 text-warning mb-2 d-block"></i>
          <p class="fw-semibold text-body mb-2">No submission recorded for this year</p>
          ${CAN_ENTER_DEMOGRAPHICS ? '<a href="demographics-tracking.php" class="btn btn-primary btn-sm">Start This Period\'s Entry</a>' : ""}
        </div>`;
      return;
    }

    const period = DemographicsUI.demographicPeriodLabel(latest);

    el.innerHTML = `
      <div class="row g-3">
        <div class="col-md-4">
          <span class="d-block mb-1 text-body fw-semibold">Last Submission</span>
          <strong class="fs-16">${period}</strong>
        </div>
        <div class="col-md-4">
          <span class="d-block mb-1 text-body fw-semibold">Status</span>
          ${DemographicsUI.renderStatusBadge(latest.status)}
        </div>
        <div class="col-md-4">
          <span class="d-block mb-1 text-body fw-semibold">Submissions This Year</span>
          <strong class="fs-16">${submissionsThisYear} of ${periodsPerYear}</strong>
        </div>
        ${latest.review_notes ? `
        <div class="col-md-12">
          <span class="d-block mb-1 text-body fw-semibold">Reviewer Notes</span>
          <div class="alert alert-warning mb-0 py-2">${latest.review_notes}</div>
        </div>` : ""}
      </div>`;
  }

  // ==========================================================================
  // RECORDING CADENCE (demographics_mode - monthly/half_yearly/yearly)
  //
  // Read-only here - actually changing it happens on its own Settings page
  // (church/settings/demographics-settings/recording-cadence.php), linked
  // from this card. Single write path, avoids two controls drifting out of
  // sync with each other.
  // ==========================================================================

  const MODE_LABELS = {
    monthly: "Monthly",
    half_yearly: "Half-Yearly (H1/H2)",
    yearly: "Yearly",
  };

  const MODE_DESCRIPTIONS = {
    monthly: "This church submits one demographics report every fiscal month.",
    half_yearly: "This church submits one demographics report every half-year (H1/H2).",
    yearly: "This church submits one demographics report per fiscal year.",
  };

  /** How many periods a church is expected to submit per fiscal year at each cadence - drives the Compliance card's "X of N" target. */
  const PERIODS_PER_YEAR = {
    monthly: 12,
    half_yearly: 2,
    yearly: 1,
  };

  /**
   * An identity block (icon avatar + mode name + what it means), not a
   * numeric stat - renderSolidStatCard's big-bold-number shape doesn't fit
   * a setting with no value to report, so this gets its own small treatment
   * using the same solid-icon visual language instead of the bare two-line
   * text block this card used to be.
   */
  async function loadDemographicsMode() {
    const card = document.getElementById("demographicsModeCard");
    const result = await DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id);

    if (!result.success) {
      card.innerHTML = '<p class="text-body fw-semibold mb-0">Could not load recording cadence</p>';
      return;
    }

    currentMode = result.data.demographics_mode;
    card.innerHTML = `
      <div class="d-flex align-items-start gap-3">
        <span class="avatar avatar-md avatar-rounded bg-primary text-white flex-shrink-0">
          <i class="ri-calendar-2-line fs-18"></i>
        </span>
        <div>
          <h5 class="fw-semibold mb-1">${MODE_LABELS[currentMode] || currentMode}</h5>
          <p class="text-body fs-12 mb-0">${MODE_DESCRIPTIONS[currentMode] || ""}</p>
        </div>
      </div>`;
  }

  function renderHistory(rows) {
    const tbody = document.getElementById("historyTableBody");
    tbody.innerHTML = DemographicsUI.renderSubmissionsRows(rows, {
      onEdit: "DemographicsOverview.goToEdit",
      onView: "DemographicsOverview.viewRow",
    });
  }

  function goToEdit(id) {
    window.location.href = `demographics-tracking.php?id=${id}`;
  }

  // Navigates to the dashboard-style submission page (same one Demographics
  // Tracking's Recent Submissions table already uses) instead of a plain
  // label/value modal - one real detail view, not two.
  function viewRow(id) {
    window.location.href = `view-submission.php?id=${id}`;
  }

  return { init, goToEdit, viewRow };
})();

window.DemographicsOverview = DemographicsOverview;
