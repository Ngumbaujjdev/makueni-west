/**
 * ============================================================================
 * PAGE - CHURCH DEMOGRAPHICS & GROWTH LANDING (Overview / History)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Segmented [Overview] [History] landing page for
 * church/demographics-growth/index.php, per the PWA design reference's
 * pastor-demographics.html screen.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts
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

    renderOverview(allRows);
    renderHistory(allRows);
  }

  function renderOverview(rows) {
    const latest = rows[0] || null;
    const previous = rows[1] || null;

    renderStatCards(latest, previous);
    renderGenderDonut(latest);
    renderComplianceCard(latest);
  }

  function renderEmptyOverview() {
    renderStatCards(null, null);
    renderGenderDonut(null);
    renderComplianceCard(null);
  }

  function delta(current, prev) {
    if (current === null || current === undefined) return null;
    if (prev === null || prev === undefined) return null;
    const diff = current - prev;
    if (diff === 0) return null;
    const sign = diff > 0 ? "+" : "";
    return `${sign}${diff} vs last submission`;
  }

  function renderStatCards(latest, previous) {
    const container = document.getElementById("statCardsRow");
    const cards = [
      { icon: "ri-team-line", label: "Total Members", field: "total_members", color: "primary" },
      { icon: "ri-user-star-line", label: "Youth (13-35)", field: "youth_count", color: "success" },
      { icon: "ri-women-line", label: "Women's Fellowship", field: "womens_fellowship_count", color: "warning" },
      { icon: "ri-men-line", label: "Men's Fellowship", field: "mens_fellowship_count", color: "secondary" },
      { icon: "ri-graduation-cap-line", label: "Sunday School", field: null, color: "primary" },
      { icon: "ri-user-heart-line", label: "Seniors", field: "seniors_count", color: "success" },
    ];

    container.innerHTML = cards
      .map((c) => {
        const value = c.field
          ? latest?.[c.field] ?? "-"
          : latest
            ? (latest.sunday_school_male_count ?? 0) + (latest.sunday_school_female_count ?? 0)
            : "-";
        const prevValue = c.field ? previous?.[c.field] : previous
          ? (previous.sunday_school_male_count ?? 0) + (previous.sunday_school_female_count ?? 0)
          : null;
        const trend = latest ? delta(value, prevValue) : null;
        return `<div class="col-xl-2 col-lg-4 col-md-6">${DemographicsUI.renderStatCard({
          icon: c.icon,
          label: c.label,
          value,
          trend,
          color: c.color,
        })}</div>`;
      })
      .join("");
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

  function renderComplianceCard(latest) {
    const el = document.getElementById("complianceCard");

    if (!latest) {
      el.innerHTML = `
        <div class="text-center py-3">
          <i class="ri-file-warning-line fs-30 text-warning mb-2 d-block"></i>
          <p class="fw-semibold text-body mb-2">No submission recorded yet</p>
          ${CAN_ENTER_DEMOGRAPHICS ? '<a href="demographics-tracking.php" class="btn btn-primary btn-sm">Start This Month\'s Entry</a>' : ""}
        </div>`;
      return;
    }

    const period = `${latest.fiscal_month?.name || ""} ${latest.fiscal_year?.year || ""}`.trim();

    el.innerHTML = `
      <div class="row g-3">
        <div class="col-md-6">
          <span class="d-block mb-1 text-body fw-semibold">Last Submission</span>
          <strong class="fs-16">${period}</strong>
        </div>
        <div class="col-md-6">
          <span class="d-block mb-1 text-body fw-semibold">Status</span>
          ${DemographicsUI.renderStatusBadge(latest.status)}
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
    });
  }

  function goToEdit(id) {
    window.location.href = `demographics-tracking.php?id=${id}`;
  }

  return { init, goToEdit };
})();

window.DemographicsOverview = DemographicsOverview;
