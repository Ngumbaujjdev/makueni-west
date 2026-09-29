/**
 * ============================================================================
 * PAGE - CHURCH DEMOGRAPHICS & GROWTH OVERVIEW (church/demographics-growth/index.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Laid out like the template's CRM dashboard (html/index.php):
 *   toolbar (Overview | History, year, Update) ->
 *   4 KPI cards (sparkline + change vs the previous submission) ->
 *   membership growth chart + gender ring donut ->
 *   membership breakdown (composition card) + reporting status.
 * History is the submissions table with the shared filter bar + sorting.
 *
 * Everything comes from GET /demographics (every submission for the church)
 * plus the church's recording cadence and the fiscal-year list. Only
 * approved submissions feed trends/sparklines; the KPIs show the latest
 * submission in the chosen year. Cadence-agnostic (monthly / half-yearly /
 * yearly) via DemographicsUI.demographicPeriodLabel().
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery DataTables (history table)
 * ============================================================================
 */

const DemographicsOverview = (function () {
  "use strict";

  const UI = DemographicsUI;
  let allRows = []; // newest first
  let approvedOldestFirst = [];
  let currentMode = "monthly";
  let years = [];
  let selectedYearId = null;
  let charts = {};

  const MODE_LABELS = { monthly: "Monthly", half_yearly: "Half-yearly (H1 / H2)", yearly: "Yearly" };
  const PERIODS_PER_YEAR = { monthly: 12, half_yearly: 2, yearly: 1 };
  const PERIOD_NOUN = { monthly: "month", half_yearly: "half", yearly: "year" };
  const PERIOD_PLURAL = { monthly: "months", half_yearly: "halves", yearly: "years" };

  // The four headline numbers. Each links to its own trend page.
  const KPIS = [
    { key: "total_members", metric: "total_members", label: "Total members", icon: "ri-team-line", color: "primary" },
    { key: "youth_count", metric: "youth", label: "Youth (13-35)", icon: "ri-user-star-line", color: "success" },
    { key: "new_members_count", metric: "new_members", label: "New members", icon: "ri-user-add-line", color: "purple" },
    { key: "baptisms_count", metric: "baptisms", label: "Baptisms", icon: "ri-drop-line", color: "secondary" },
  ];

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    wireViewSwitch();

    const [modeResult, rowsResult, yearsResult] = await Promise.all([
      DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id),
      DemographicsAPIHandler.getDemographics(USER_TERRITORY.id),
      DemographicsAPIHandler.getFiscalYears(),
    ]);

    if (modeResult.success) currentMode = modeResult.data.demographics_mode || "monthly";
    applyCadenceLabels();

    if (!rowsResult.success) Toast.error(rowsResult.message || "Failed to load demographics data");
    allRows = UI.sortSubmissionsNewestFirst(rowsResult.success ? rowsResult.data || [] : []);
    approvedOldestFirst = [...allRows].reverse().filter((r) => r.status === "approved" && r.total_members != null);

    setupYears(yearsResult.success ? yearsResult.data || [] : []);
    renderGrowthChart();
    renderHistory();
    renderOverview();
  }

  // --------------------------------------------------------------------------
  // Toolbar
  // --------------------------------------------------------------------------

  function wireViewSwitch() {
    UI.wireSegmented("viewSwitch", (view) => {
      document.getElementById("segmentOverview").style.display = view === "overview" ? "" : "none";
      document.getElementById("segmentHistory").style.display = view === "history" ? "" : "none";
      document.getElementById("yearSwitchWrap").style.display = view === "overview" ? "" : "none";
    });
  }

  function applyCadenceLabels() {
    const noun = PERIOD_NOUN[currentMode] || "month";
    const btnLabel = document.getElementById("updateDataBtnLabel");
    if (btnLabel) btnLabel.textContent = noun;
    document.getElementById("cadenceLabel").textContent = `Recorded ${MODE_LABELS[currentMode] ? MODE_LABELS[currentMode].toLowerCase() : currentMode}`;
  }

  /** Year switch: only years that have happened (no empty future years), newest first. */
  function setupYears(fiscalYears) {
    const thisYear = new Date().getFullYear();
    years = fiscalYears.filter((y) => y.year <= thisYear).sort((a, b) => b.year - a.year).slice(0, 5);
    const wrap = document.getElementById("yearSwitchWrap");

    if (years.length === 0) {
      wrap.innerHTML = "";
      return;
    }

    const current = years.find((y) => y.year === thisYear) || years[0];
    selectedYearId = String(current.id);
    wrap.innerHTML = UI.renderSegmented(
      "yearSwitch",
      [...years].reverse().map((y) => ({ value: y.id, label: y.year })),
      selectedYearId,
      { ariaLabel: "Fiscal year" },
    );
    UI.syncExportButton({ fiscalYearId: selectedYearId });
    UI.wireSegmented("yearSwitch", (value) => {
      selectedYearId = value;
      UI.syncExportButton({ fiscalYearId: value });
      renderOverview();
    });
  }

  // --------------------------------------------------------------------------
  // Overview
  // --------------------------------------------------------------------------

  function chartLabel(row) {
    if (row.fiscal_month) return `${row.fiscal_month.short_name || row.fiscal_month.name} ${row.fiscal_year?.year || ""}`.trim();
    return UI.demographicPeriodLabel(row);
  }

  function renderOverview() {
    const yearRows = selectedYearId ? allRows.filter((r) => String(r.fiscal_year_id) === String(selectedYearId)) : allRows;
    const latest = yearRows[0] || null;
    // Compare against the previous *approved* submission - a draft's
    // provisional numbers would make the change meaningless.
    const previous = latest ? allRows.slice(allRows.indexOf(latest) + 1).find((r) => r.status === "approved") || null : null;
    const yearLabel = years.find((y) => String(y.id) === String(selectedYearId))?.year;

    document.getElementById("overviewSubtitle").textContent = latest
      ? `Latest submission in ${yearLabel || "this year"}: ${UI.demographicPeriodLabel(latest)}`
      : `No submission recorded in ${yearLabel || "this year"} yet`;

    renderKpis(latest, previous);
    renderGender(latest);
    renderComposition(latest, previous);
    renderReporting(latest, yearRows.length);
  }

  function renderKpis(latest, previous) {
    const prevLabel = previous ? UI.demographicPeriodLabel(previous) : "";

    UI.renderStatCardsRow(
      "statCardsRow",
      KPIS.map((k) => {
        const value = latest ? latest[k.key] : null;
        const prev = previous ? previous[k.key] : null;
        const series = approvedOldestFirst.slice(-8);
        return {
          icon: k.icon,
          label: k.label,
          value: value == null ? "-" : Number(value).toLocaleString(),
          color: k.color,
          delta: value != null && prev != null ? UI.periodDelta(Number(value), Number(prev), { prevLabel }) : null,
          series: series.length > 1 ? { labels: series.map(chartLabel), data: series.map((r) => Number(r[k.key]) || 0) } : null,
          link: { href: UI.metricUrl(k.metric), text: "View trend" },
        };
      }),
    );
  }

  function renderGrowthChart() {
    const chips = document.getElementById("growthChips");
    const chartEl = document.getElementById("growthTrendChart");
    const rows = approvedOldestFirst;

    if (rows.length < 2) {
      chips.innerHTML = "";
      chartEl.innerHTML = `
        <div class="list-empty">
          <span class="list-empty-icon bg-primary text-white"><i class="ri-line-chart-line"></i></span>
          <div class="fw-semibold mt-2">Not enough history yet</div>
          <div class="fs-12 text-muted">The trend appears once two submissions have been approved.</div>
        </div>`;
      return;
    }

    const values = rows.map((r) => Number(r.total_members));
    const first = values[0];
    const last = values[values.length - 1];
    const peak = Math.max(...values);
    const growthPct = first ? Math.round(((last - first) / first) * 1000) / 10 : null;

    UI.renderPillLegend("growthChips", [
      { label: "Latest", value: last.toLocaleString(), color: "primary" },
      { label: "Peak", value: peak.toLocaleString(), color: "secondary" },
      growthPct != null ? { label: "Growth", value: `${growthPct > 0 ? "+" : ""}${growthPct}%`, color: growthPct >= 0 ? "success" : "danger" } : null,
    ].filter(Boolean));

    if (charts.growth) charts.growth.destroy();
    chartEl.innerHTML = "";
    charts.growth = UI.renderTrendChart("growthTrendChart", {
      categories: rows.map(chartLabel),
      series: [{ name: "Total members", data: values }],
      type: "area",
      color: "primary",
    });
  }

  function renderGender(latest) {
    const el = document.getElementById("genderDonut");
    document.getElementById("genderSubtitle").textContent = latest ? UI.demographicPeriodLabel(latest) : "Latest submission";
    if (charts.gender) charts.gender.destroy();
    charts.gender = null;

    if (!latest || (!latest.male_count && !latest.female_count)) {
      el.innerHTML = `
        <div class="list-empty">
          <span class="list-empty-icon bg-secondary text-dark"><i class="ri-pie-chart-line"></i></span>
          <div class="fw-semibold mt-2">No gender split recorded</div>
        </div>`;
      return;
    }

    charts.gender = UI.renderRingDonut("genderDonut", {
      labels: ["Male", "Female"],
      series: [latest.male_count || 0, latest.female_count || 0],
      colors: ["primary", "pink"],
      centerLabel: "Members",
    });
  }

  function renderComposition(latest, previous) {
    const el = document.getElementById("compositionCard");
    if (!latest) {
      el.innerHTML = '<p class="text-muted mb-0">No submission for this year yet.</p>';
      return;
    }
    const ss = (r) => (Number(r.sunday_school_male_count) || 0) + (Number(r.sunday_school_female_count) || 0);
    UI.renderCompositionCard("compositionCard", {
      total: latest.total_members,
      totalLabel: "total members",
      delta:
        previous && previous.total_members != null
          ? UI.periodDelta(Number(latest.total_members), Number(previous.total_members), { prevLabel: UI.demographicPeriodLabel(previous) })
          : null,
      items: [
        { label: "Youth (13-35)", value: latest.youth_count, color: "success" },
        { label: "Women's fellowship", value: latest.womens_fellowship_count, color: "pink" },
        { label: "Men's fellowship", value: latest.mens_fellowship_count, color: "primary" },
        { label: "Sunday school", value: ss(latest), color: "purple" },
        { label: "Seniors", value: latest.seniors_count, color: "secondary" },
      ],
    });
  }

  function renderReporting(latest, submittedThisYear) {
    const el = document.getElementById("reportingCard");
    const expected = PERIODS_PER_YEAR[currentMode] || 12;
    const done = Math.min(submittedThisYear, expected);
    const pct = Math.round((done / expected) * 100);
    const onTrack = done >= expected;

    el.innerHTML = `
      <div class="d-flex align-items-end justify-content-between mb-2">
        <div>
          <div class="composition-total">${done} <span class="fs-14 fw-semibold text-muted">of ${expected}</span></div>
          <div class="kpi-caption mt-1">${PERIOD_PLURAL[currentMode] || "periods"} submitted this year</div>
        </div>
        <span class="soft-chip soft-${onTrack ? "success" : "secondary"}">${onTrack ? "Complete" : `${expected - done} to go`}</span>
      </div>
      <div class="progress mb-3" style="height: 0.55rem;" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar bg-${onTrack ? "success" : "primary"}" style="width: ${pct}%;"></div>
      </div>
      <ul class="composition-list">
        <li><span class="composition-name">Last submission</span><span class="composition-value">${latest ? UI.demographicPeriodLabel(latest) : "-"}</span></li>
        <li><span class="composition-name">Status</span><span>${latest ? UI.renderStatusBadge(latest.status) : "-"}</span></li>
        <li><span class="composition-name">Recording cadence</span><span class="composition-value">${MODE_LABELS[currentMode] || currentMode}</span></li>
      </ul>
      ${latest && latest.review_notes ? `<div class="alert alert-warning bg-warning-transparent mt-3 mb-0 py-2"><i class="ri-chat-quote-line me-1"></i>${latest.review_notes}</div>` : ""}
      ${!latest && CAN_ENTER_DEMOGRAPHICS ? '<a href="demographics-tracking.php" class="btn btn-primary btn-sm mt-3"><i class="ri-add-line me-1"></i>Start this period\'s entry</a>' : ""}`;
  }

  // --------------------------------------------------------------------------
  // History
  // --------------------------------------------------------------------------

  function renderHistory() {
    document.getElementById("historyTableBody").innerHTML = UI.renderSubmissionsRows(allRows, {
      onEdit: "DemographicsOverview.goToEdit",
      onView: "DemographicsOverview.viewRow",
    });

    UI.renderFilterToolbar("historyFilterToolbar", {
      searchPlaceholder: "Search periods...",
      filters: [
        {
          id: "historyStatusFilter",
          label: "All statuses",
          options: ["Draft", "Submitted", "Approved", "Flagged", "Changes Requested"].map((s) => ({ value: s, label: s })),
        },
      ],
    });
    const table = UI.initListDataTable("historyTable", { order: [], nonSortableColumns: [3], hideDefaultSearch: true, noun: "submissions" });
    UI.wireFilterToolbar("historyFilterToolbar", table, [{ id: "historyStatusFilter", columnIndex: 2, exact: true }], {
      noun: "submissions",
      urlSync: false,
    });
  }

  function goToEdit(id) {
    window.location.href = `demographics-tracking.php?id=${id}`;
  }

  function viewRow(id) {
    window.location.href = `view-submission.php?id=${id}`;
  }

  return { init, goToEdit, viewRow };
})();

window.DemographicsOverview = DemographicsOverview;
