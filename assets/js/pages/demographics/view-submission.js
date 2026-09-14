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
 * Cards use a local solid-icon renderer (renderSolidStatCard below), not
 * DemographicsUI.renderWidgetCard's pale-transparent icon tint - the "no
 * muted/washed-out" Design Rule this page was drifting from. Kept local
 * rather than changed in ui-helpers.js since renderWidgetCard is also used,
 * unchanged, on Attendance Reports/Growth Overview.
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
    const previous = await loadPreviousRecord(record);

    renderHeader(record);
    renderStats(record, previous);
    renderCharts(record);
    renderActivityStats(record, previous);
    loadLeadership(record, previous);
  }

  /**
   * The submission immediately before this one (same newest-first ordering
   * used by demographics-tracking.js/index.js) - lets the stat cards show a
   * real period-over-period trend instead of a bare number. Returns null for
   * the oldest/only submission, which callers treat as "no trend to show."
   */
  async function loadPreviousRecord(record) {
    const result = await DemographicsAPIHandler.getDemographics(USER_TERRITORY.id);
    if (!result.success || !result.data) return null;

    const rows = result.data.sort((a, b) => {
      const ay = a.fiscal_year?.year || 0;
      const by = b.fiscal_year?.year || 0;
      if (ay !== by) return by - ay;
      return (b.fiscal_month?.number || 0) - (a.fiscal_month?.number || 0);
    });

    const index = rows.findIndex((r) => r.id === record.id);
    return index !== -1 && rows[index + 1] ? rows[index + 1] : null;
  }

  /**
   * @param {object} opts {icon, label, value, sublabel, color, trend}
   *   Label + solid bg-${color} icon avatar (top row), a large bold value,
   *   then either a green/red trend badge (`trend: {diff}` - same
   *   bg-${color}-transparent arrow-pill convention as renderWidgetCard()/
   *   the Recent Submissions table) or a plain sublabel when there's no
   *   period to compare against.
   */
  function renderSolidStatCard({ icon, label, value, sublabel = "", color = "primary", trend = null }) {
    let trendHtml = "";
    if (trend) {
      if (trend.diff === 0) {
        trendHtml = `<span class="fs-12 text-body fw-semibold">No change vs last period</span>`;
      } else {
        const trendColor = trend.diff > 0 ? "success" : "danger";
        const arrow = trend.diff > 0 ? "ri-arrow-up-line" : "ri-arrow-down-line";
        const sign = trend.diff > 0 ? "+" : "-";
        trendHtml = `<span class="badge bg-${trendColor}-transparent text-${trendColor} fs-11"><i class="${arrow}"></i> ${sign}${Math.abs(trend.diff)} vs last period</span>`;
      }
    } else if (sublabel) {
      trendHtml = `<span class="fs-12 text-body fw-semibold">${sublabel}</span>`;
    }

    return `
      <div class="card custom-card">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between mb-2">
            <span class="fs-13 fw-semibold text-body">${label}</span>
            <span class="avatar avatar-sm avatar-rounded bg-${color} text-white flex-shrink-0">
              <i class="${icon} fs-16"></i>
            </span>
          </div>
          <h2 class="fw-bold mb-1">${value}</h2>
          ${trendHtml}
        </div>
      </div>`;
  }

  /**
   * Per-role breakdown list, modeled on index-1.html's "Recent Orders"
   * widget (card-header title + card-body > list-unstyled of icon/title/
   * trailing-value rows) - adapted to this app's solid-icon + no-muted-text
   * convention instead of the template's pale avatar image + text-muted.
   * Replaces a single lumped "N pastors" number with one row per role, so
   * "1 Senior Pastor, 3 Associate Pastor" is actually legible instead of a
   * squashed text line.
   */
  function renderClergyListCard(counts, total) {
    const roleNames = Object.keys(counts);

    const rows = roleNames.length
      ? roleNames
          .map(
            (role, i) => `
        <li class="d-flex align-items-center${i < roleNames.length - 1 ? " mb-3" : ""}">
          <span class="avatar avatar-sm avatar-rounded bg-primary text-white flex-shrink-0 me-2">
            <i class="ri-user-star-line"></i>
          </span>
          <span class="flex-fill fw-semibold">${role}</span>
          <span class="badge bg-primary-transparent text-primary fs-13 fw-semibold">${counts[role]}</span>
        </li>`,
          )
          .join("")
      : `<li class="fs-13 text-body fw-semibold">No pastors on record</li>`;

    return `
      <div class="card custom-card h-100">
        <div class="card-header justify-content-between">
          <div class="card-title">Pastors & Assistant Pastors</div>
          ${roleNames.length ? `<span class="badge bg-primary-transparent text-primary fw-semibold">${total} total</span>` : ""}
        </div>
        <div class="card-body">
          <ul class="list-unstyled mb-0">${rows}</ul>
        </div>
      </div>`;
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
          <span class="avatar avatar-lg avatar-rounded bg-primary">
            <i class="ri-file-chart-2-line fs-22 text-white"></i>
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

  /**
   * null when there's no previous value to compare against - callers just
   * skip the trend. Checks for null/undefined explicitly (not truthiness) so
   * a genuine 0 in the previous period (e.g. 0 baptisms last period) still
   * produces a real trend instead of being mistaken for "no data."
   */
  function trendFor(current, previousValue) {
    return previousValue == null ? null : { diff: current - previousValue };
  }

  function renderStats(record, previous) {
    const sundaySchoolCount = (record.sunday_school_male_count ?? 0) + (record.sunday_school_female_count ?? 0);
    const prevSundaySchoolCount = previous ? (previous.sunday_school_male_count ?? 0) + (previous.sunday_school_female_count ?? 0) : null;
    const container = document.getElementById("statCardsRow");
    if (!container) return;

    const cards = [
      { icon: "ri-team-line", label: "Total Members", value: record.total_members ?? 0, color: "primary", trend: trendFor(record.total_members ?? 0, previous?.total_members) },
      { icon: "ri-user-add-line", label: "New Members", value: record.new_members_count ?? 0, color: "success", trend: trendFor(record.new_members_count ?? 0, previous?.new_members_count) },
      { icon: "ri-drop-line", label: "Baptisms", value: record.baptisms_count ?? 0, color: "info", trend: trendFor(record.baptisms_count ?? 0, previous?.baptisms_count) },
      { icon: "ri-book-read-line", label: "Sunday School", value: sundaySchoolCount, color: "warning", trend: trendFor(sundaySchoolCount, prevSundaySchoolCount) },
    ];
    container.innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${renderSolidStatCard(c)}</div>`).join("");
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

  function renderActivityStats(record, previous) {
    const container = document.getElementById("activityStatsRow");
    if (!container) return;

    const cards = [
      { icon: "ri-user-add-line", label: "New Members", value: record.new_members_count ?? 0, color: "success", trend: trendFor(record.new_members_count ?? 0, previous?.new_members_count) },
      { icon: "ri-user-unfollow-line", label: "Transferred Out", value: record.transferred_out_count ?? 0, color: "secondary", trend: trendFor(record.transferred_out_count ?? 0, previous?.transferred_out_count) },
      { icon: "ri-drop-line", label: "Baptisms", value: record.baptisms_count ?? 0, color: "info", trend: trendFor(record.baptisms_count ?? 0, previous?.baptisms_count) },
      { icon: "ri-cup-line", label: "Communion", value: record.communion_participants_count ?? 0, color: "primary", trend: trendFor(record.communion_participants_count ?? 0, previous?.communion_participants_count) },
      { icon: "ri-heart-line", label: "New Conversions", value: record.conversions_count ?? 0, color: "warning", trend: trendFor(record.conversions_count ?? 0, previous?.conversions_count) },
    ];

    // Manual .col wrapping (not a fixed 4-per-row) so all 5 cards sit evenly
    // in one row via the container's own row-cols-xl-5.
    container.innerHTML = cards.map((c) => `<div class="col">${renderSolidStatCard(c)}</div>`).join("");
  }

  async function loadLeadership(record, previous) {
    const card = document.getElementById("leadershipCard");
    const result = await DemographicsAPIHandler.getClergySummary(USER_TERRITORY.id);
    const counts = result.success ? result.data.counts || {} : {};
    const clergyTotal = result.success ? result.data.total ?? 0 : 0;

    const teachersCard = renderSolidStatCard({
      icon: "ri-book-read-line",
      label: "Sunday School Teachers",
      value: record.sunday_school_teachers_count ?? 0,
      color: "warning",
      trend: trendFor(record.sunday_school_teachers_count ?? 0, previous?.sunday_school_teachers_count),
    });

    card.innerHTML = `
      <div class="row g-3">
        <div class="col-xl-6">${renderClergyListCard(counts, clergyTotal)}</div>
        <div class="col-xl-6">${teachersCard}</div>
      </div>`;
  }

  return { init };
})();

window.DemographicsViewSubmission = DemographicsViewSubmission;
