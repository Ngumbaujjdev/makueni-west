/**
 * ============================================================================
 * PAGE - CHURCH DEMOGRAPHICS & GROWTH LANDING (Overview / History)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Segmented [Overview] [History] landing page for
 * church/demographics-growth/index.php, per the PWA design reference's
 * pastor-demographics.html screen. Overview is a snapshot of one selected
 * fiscal year's latest submission (with a real percent trend badge vs.
 * whatever was submitted right before it, year boundary or not) - the full
 * multi-year/multi-metric drill-down lives on the separate Growth Analytics
 * page, not here.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * Bootstrap modal
 * ============================================================================
 */

const DemographicsOverview = (function () {
  "use strict";

  let allRows = [];

  function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));
    wireSegments();
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

    allRows = (result.data || []).sort((a, b) => {
      const ay = a.fiscal_year?.year || 0;
      const by = b.fiscal_year?.year || 0;
      if (ay !== by) return by - ay;
      return (b.fiscal_month?.number || 0) - (a.fiscal_month?.number || 0);
    });

    await loadFiscalYears();
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

  function renderStatCards(latest, previous) {
    const cards = [
      { icon: "ri-team-line", label: "Total Members", field: "total_members", color: "primary" },
      { icon: "ri-user-star-line", label: "Youth (13-35)", field: "youth_count", color: "success" },
      { icon: "ri-women-line", label: "Women's Fellowship", field: "womens_fellowship_count", color: "warning" },
      { icon: "ri-men-line", label: "Men's Fellowship", field: "mens_fellowship_count", color: "secondary" },
      { icon: "ri-graduation-cap-line", label: "Sunday School", field: null, color: "primary" },
      { icon: "ri-user-heart-line", label: "Seniors", field: "seniors_count", color: "success" },
    ];

    const sundaySchoolTotal = (row) => (row ? (row.sunday_school_male_count ?? 0) + (row.sunday_school_female_count ?? 0) : null);

    const cardOpts = cards.map((c) => {
      const value = c.field ? latest?.[c.field] ?? "-" : latest ? sundaySchoolTotal(latest) : "-";
      const prevValue = c.field ? previous?.[c.field] ?? null : previous ? sundaySchoolTotal(previous) : null;
      return {
        icon: c.icon,
        label: c.label,
        value,
        color: c.color,
        trend: latest ? trend(value, prevValue) : null,
      };
    });

    DemographicsUI.renderWidgetCardsRow("statCardsRow", cardOpts);
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

    if (!latest) {
      el.innerHTML = `
        <div class="text-center py-3">
          <i class="ri-file-warning-line fs-30 text-warning mb-2 d-block"></i>
          <p class="fw-semibold text-body mb-2">No submission recorded for this year</p>
          ${CAN_ENTER_DEMOGRAPHICS ? '<a href="demographics-tracking.php" class="btn btn-primary btn-sm">Start This Month\'s Entry</a>' : ""}
        </div>`;
      return;
    }

    const period = `${latest.fiscal_month?.name || ""} ${latest.fiscal_year?.year || ""}`.trim();

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
          <strong class="fs-16">${submissionsThisYear} of 12</strong>
        </div>
        ${latest.review_notes ? `
        <div class="col-md-12">
          <span class="d-block mb-1 text-body fw-semibold">Reviewer Notes</span>
          <div class="alert alert-warning mb-0 py-2">${latest.review_notes}</div>
        </div>` : ""}
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

  function viewRow(id) {
    const row = allRows.find((r) => r.id === id);
    if (!row) return;

    document.getElementById("demographicDetailModalBody").innerHTML = DemographicsUI.renderDemographicDetailTable(row);
    window.bootstrap.Modal.getOrCreateInstance(document.getElementById("demographicDetailModal")).show();
  }

  return { init, goToEdit, viewRow };
})();

window.DemographicsOverview = DemographicsOverview;
