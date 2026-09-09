/**
 * ============================================================================
 * PAGE - VIEW SUBMISSION (read-only dashboard for one demographics record)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Separate from demographics-tracking.php's locked-wizard "View" (still used
 * for Edit) - this is a proper report view: stat cards + charts instead of
 * the entry form. Built entirely from what GET /demographics/{id}
 * (DemographicsAPIHandler.getDemographic) already returns - no new backend.
 *
 * Card style is renderWidgetCard/renderWidgetCardsRow throughout - the same
 * pale-icon-tint "index-1.html Total Sales card" pattern already used on
 * Attendance Reports and Growth Overview, not the plainer renderStatCard.
 * ============================================================================
 */

const DemographicsViewSubmission = (function () {
  "use strict";

  const CHART_HEIGHT = 280;

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    const result = await DemographicsAPIHandler.getDemographic(DEMOGRAPHIC_ID);

    if (!result.success) {
      Toast.error(result.message || "Could not load that submission");
      window.location.href = "demographics-tracking.php";
      return;
    }

    const record = result.data;
    renderHeader(record);
    renderStats(record);
    renderCharts(record);
    renderActivityStats(record);
    loadLeadership(record);
  }

  function renderHeader(record) {
    const period = DemographicsUI.demographicPeriodLabel(record);
    const backBtn = `<a href="demographics-tracking.php" class="btn btn-outline-primary btn-sm me-2"><i class="ri-arrow-left-line me-1"></i>Back</a>`;
    const isEditable = record.status === "draft" || record.status === "changes_requested";
    const editBtn = isEditable
      ? `<a href="demographics-tracking.php?id=${record.id}" class="btn btn-primary btn-sm"><i class="ri-edit-line me-1"></i>Edit</a>`
      : "";

    const chips = [
      record.submitted_at
        ? `<span class="fs-12 text-body"><i class="ri-send-plane-line me-1"></i>${new Date(record.submitted_at).toLocaleDateString()}</span>`
        : "",
      record.reviewer
        ? `<span class="fs-12 text-body"><i class="ri-user-star-line me-1"></i>${record.reviewer.firstname || ""} ${record.reviewer.lastname || ""}</span>`
        : "",
    ]
      .filter(Boolean)
      .join('<span class="text-body mx-1">&middot;</span>');

    document.getElementById("submissionHeaderCard").innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
          <span class="avatar avatar-lg avatar-rounded bg-primary-transparent">
            <i class="ri-file-chart-2-line fs-22 text-primary"></i>
          </span>
          <div>
            <div class="d-flex align-items-center gap-2">
              <h4 class="fw-semibold mb-0">${period}</h4>
              ${DemographicsUI.renderStatusBadge(record.status)}
            </div>
            ${chips ? `<div class="mt-1">${chips}</div>` : ""}
          </div>
        </div>
        <div>${backBtn}${editBtn}</div>
      </div>
      ${record.review_notes ? `
      <div class="alert alert-warning mt-3 mb-0 py-2">
        <i class="ri-message-2-line me-2"></i>${record.review_notes}
      </div>` : ""}`;
  }

  function renderStats(record) {
    const sundaySchoolCount = (record.sunday_school_male_count ?? 0) + (record.sunday_school_female_count ?? 0);

    DemographicsUI.renderWidgetCardsRow("statCardsRow", [
      { icon: "ri-team-line", label: "Total Members", value: record.total_members ?? 0, color: "primary" },
      { icon: "ri-user-add-line", label: "New Members", value: record.new_members_count ?? 0, color: "success" },
      { icon: "ri-drop-line", label: "Baptisms", value: record.baptisms_count ?? 0, color: "info" },
      { icon: "ri-book-read-line", label: "Sunday School", value: sundaySchoolCount, color: "warning" },
    ]);
  }

  function renderCharts(record) {
    const donutEl = document.getElementById("genderDonutChart");
    if (!record.male_count && !record.female_count) {
      donutEl.innerHTML = '<p class="text-center text-body fw-semibold py-5 mb-0">No gender-split data on this submission</p>';
    } else {
      new ApexCharts(donutEl, {
        chart: { type: "donut", height: CHART_HEIGHT },
        series: [record.male_count || 0, record.female_count || 0],
        labels: ["Male", "Female"],
        colors: ["#2CA4BF", "#F2BE22"],
        legend: { position: "bottom" },
        dataLabels: { enabled: true },
      }).render();
    }

    const compositionEl = document.getElementById("compositionChart");
    const categories = ["Youth", "Women's Fellowship", "Men's Fellowship", "Sunday School (Male)", "Sunday School (Female)", "Seniors"];
    const data = [
      record.youth_count ?? 0,
      record.womens_fellowship_count ?? 0,
      record.mens_fellowship_count ?? 0,
      record.sunday_school_male_count ?? 0,
      record.sunday_school_female_count ?? 0,
      record.seniors_count ?? 0,
    ];
    // Horizontal, not vertical (renderTrendChart's shape) - full category
    // labels sit on the y-axis instead of being squeezed under thin columns,
    // and it fills the card height evenly next to the donut.
    new ApexCharts(compositionEl, {
      chart: { type: "bar", height: CHART_HEIGHT, toolbar: { show: false }, foreColor: "#333335" },
      series: [{ name: "Count", data }],
      xaxis: { categories },
      colors: data.map((_, i) => `rgba(44, 164, 191, ${(0.35 + (0.65 * i) / (data.length - 1)).toFixed(2)})`),
      plotOptions: { bar: { horizontal: true, distributed: true, borderRadius: 4, barHeight: "60%" } },
      dataLabels: { enabled: true },
      legend: { show: false },
      grid: { borderColor: "rgba(44, 164, 191, 0.08)" },
    }).render();
  }

  function renderActivityStats(record) {
    const container = document.getElementById("activityStatsRow");
    if (!container) return;

    const cards = [
      { icon: "ri-user-add-line", label: "New Members", value: record.new_members_count ?? 0, color: "success" },
      { icon: "ri-user-unfollow-line", label: "Transferred Out", value: record.transferred_out_count ?? 0, color: "secondary" },
      { icon: "ri-drop-line", label: "Baptisms", value: record.baptisms_count ?? 0, color: "info" },
      { icon: "ri-cup-line", label: "Communion", value: record.communion_participants_count ?? 0, color: "primary" },
      { icon: "ri-heart-line", label: "New Conversions", value: record.conversions_count ?? 0, color: "warning" },
    ];

    // Manual .col wrapping (not renderWidgetCardsRow's fixed 4-per-row) so
    // all 5 cards sit evenly in one row via the container's own row-cols-xl-5.
    container.innerHTML = cards.map((c) => `<div class="col">${DemographicsUI.renderWidgetCard(c)}</div>`).join("");
  }

  async function loadLeadership(record) {
    const card = document.getElementById("leadershipCard");
    const result = await DemographicsAPIHandler.getClergySummary(USER_TERRITORY.id);
    const clergyTotal = result.success ? result.data.total ?? 0 : 0;

    card.innerHTML = `
      <div class="row g-3">
        <div class="col-xl-6">${DemographicsUI.renderWidgetCard({
          icon: "ri-shield-user-line",
          label: "Pastors & Assistant Pastors",
          value: clergyTotal,
          color: "primary",
        })}</div>
        <div class="col-xl-6">${DemographicsUI.renderWidgetCard({
          icon: "ri-book-read-line",
          label: "Sunday School Teachers",
          value: record.sunday_school_teachers_count ?? 0,
          color: "warning",
        })}</div>
      </div>`;
  }

  return { init };
})();

window.DemographicsViewSubmission = DemographicsViewSubmission;
