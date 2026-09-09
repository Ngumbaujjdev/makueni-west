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
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts
 * ============================================================================
 */

const DemographicsViewSubmission = (function () {
  "use strict";

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
    // is_editable is a real Eloquent accessor on ChurchDemographic but isn't
    // in $appends, so it never actually serializes into the API response -
    // same status check applyEditLock() already does in demographics-tracking.js.
    const isEditable = record.status === "draft" || record.status === "changes_requested";
    const editBtn = isEditable
      ? `<a href="demographics-tracking.php?id=${record.id}" class="btn btn-primary btn-sm"><i class="ri-edit-line me-1"></i>Edit</a>`
      : "";

    document.getElementById("submissionHeaderCard").innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
          <h4 class="fw-semibold mb-1">${period}</h4>
          ${DemographicsUI.renderStatusBadge(record.status)}
        </div>
        <div>${backBtn}${editBtn}</div>
      </div>
      <div class="row g-3 mt-1">
        ${record.submitted_at ? `
        <div class="col-md-4">
          <span class="d-block mb-1 text-body fw-semibold">Submitted</span>
          <strong class="fs-14">${new Date(record.submitted_at).toLocaleDateString()}</strong>
        </div>` : ""}
        ${record.reviewer ? `
        <div class="col-md-4">
          <span class="d-block mb-1 text-body fw-semibold">Reviewed By</span>
          <strong class="fs-14">${record.reviewer.firstname || ""} ${record.reviewer.lastname || ""}</strong>
        </div>` : ""}
      </div>
      ${record.review_notes ? `
      <div class="alert alert-warning mt-3 mb-0">
        <i class="ri-message-2-line me-2"></i>${record.review_notes}
      </div>` : ""}`;
  }

  function renderStats(record) {
    // sunday_school_count is a real accessor on ChurchDemographic but, like
    // is_editable, isn't in $appends - computed here the same way, from the
    // two raw counts that do serialize.
    const sundaySchoolCount = (record.sunday_school_male_count ?? 0) + (record.sunday_school_female_count ?? 0);

    DemographicsUI.renderStatCardsRow("statCardsRow", [
      { icon: "ri-team-line", label: "Total Members", value: record.total_members ?? 0, color: "primary" },
      { icon: "ri-user-add-line", label: "New Members", value: record.new_members_count ?? 0, color: "success" },
      { icon: "ri-drop-line", label: "Baptisms", value: record.baptisms_count ?? 0, color: "info" },
      { icon: "ri-book-read-line", label: "Sunday School", value: sundaySchoolCount, color: "warning" },
    ]);
  }

  function renderCharts(record) {
    const donutEl = document.getElementById("genderDonutChart");
    if (!record.male_count && !record.female_count) {
      donutEl.innerHTML = '<p class="text-center text-body fw-semibold py-4 mb-0">No gender-split data on this submission</p>';
    } else {
      new ApexCharts(donutEl, {
        chart: { type: "donut", height: 260 },
        series: [record.male_count || 0, record.female_count || 0],
        labels: ["Male", "Female"],
        colors: ["#2CA4BF", "#F2BE22"],
        legend: { position: "bottom" },
        dataLabels: { enabled: true },
      }).render();
    }

    DemographicsUI.renderTrendChart("compositionChart", {
      type: "column",
      color: "primary",
      categories: ["Youth", "Women's Fellowship", "Men's Fellowship", "Sunday School (M)", "Sunday School (F)", "Seniors"],
      series: [
        {
          name: "Count",
          data: [
            record.youth_count ?? 0,
            record.womens_fellowship_count ?? 0,
            record.mens_fellowship_count ?? 0,
            record.sunday_school_male_count ?? 0,
            record.sunday_school_female_count ?? 0,
            record.seniors_count ?? 0,
          ],
        },
      ],
    });
  }

  function renderActivityStats(record) {
    DemographicsUI.renderStatCardsRow("activityStatsRow", [
      { icon: "ri-user-add-line", label: "New Members", value: record.new_members_count ?? 0, color: "success" },
      { icon: "ri-user-unfollow-line", label: "Transferred Out", value: record.transferred_out_count ?? 0, color: "secondary" },
      { icon: "ri-drop-line", label: "Baptisms", value: record.baptisms_count ?? 0, color: "info" },
      { icon: "ri-cup-line", label: "Communion Participants", value: record.communion_participants_count ?? 0, color: "primary" },
      { icon: "ri-heart-line", label: "New Conversions", value: record.conversions_count ?? 0, color: "warning" },
    ]);
  }

  async function loadLeadership(record) {
    const card = document.getElementById("leadershipCard");
    const result = await DemographicsAPIHandler.getClergySummary(USER_TERRITORY.id);

    const clergyCounts = result.success ? result.data.counts || {} : {};
    const roleNames = Object.keys(clergyCounts);
    const clergyText = roleNames.length
      ? roleNames.map((role) => `<strong>${clergyCounts[role]}</strong> ${role}`).join(" &middot; ")
      : "No pastors on record";

    card.innerHTML = `
      <div class="row g-3">
        <div class="col-md-6">
          <span class="d-block mb-1 text-body fw-semibold">Pastors & Assistant Pastors (current)</span>
          <div class="fs-14">${clergyText}</div>
        </div>
        <div class="col-md-6">
          <span class="d-block mb-1 text-body fw-semibold">Sunday School Teachers (this submission)</span>
          <h5 class="fw-semibold mb-0">${record.sunday_school_teachers_count ?? 0}</h5>
        </div>
      </div>`;
  }

  return { init };
})();

window.DemographicsViewSubmission = DemographicsViewSubmission;
