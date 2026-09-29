/**
 * ============================================================================
 * PAGE - VIEW SUBMISSION (church/demographics-growth/view-submission.php?id=...)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * A read-only report for one demographics submission, in the same layout
 * language as the rest of the Demographics pages: summary card (period,
 * status, dates, actions) -> 4 KPI cards -> membership breakdown +
 * gender ring donut -> changes & sacraments + leadership.
 *
 * Changes compare with the previous *approved* submission (a draft's
 * numbers aren't final). Data: GET /demographics/{id}, GET /demographics
 * (to find the previous one) and GET /churches/{id}/clergy-summary.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts
 * ============================================================================
 */

const DemographicsViewSubmission = (function () {
  "use strict";

  const UI = DemographicsUI;
  const M = UI.DEMOGRAPHIC_METRICS;

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));

    const result = await DemographicsAPIHandler.getDemographic(DEMOGRAPHIC_ID);
    if (!result.success) {
      Toast.error(result.message || "Could not load that submission");
      window.location.href = "demographics-tracking.php";
      return;
    }

    const record = result.data;
    const previous = await loadPrevious(record);

    renderHeader(record, previous);
    renderKpis(record, previous);
    renderComposition(record, previous);
    renderGender(record);
    renderChanges(record, previous);
    loadLeadership(record, previous);
  }

  async function loadPrevious(record) {
    const result = await DemographicsAPIHandler.getDemographics(USER_TERRITORY.id);
    if (!result.success || !result.data) return null;
    const rows = UI.sortSubmissionsNewestFirst(result.data);
    const index = rows.findIndex((r) => r.id === record.id);
    return index === -1 ? null : rows.slice(index + 1).find((r) => r.status === "approved") || null;
  }

  function prevLabel(previous) {
    return previous ? UI.demographicPeriodLabel(previous) : "";
  }

  function renderHeader(record, previous) {
    const editable = record.status === "draft" || record.status === "changes_requested";
    const facts = [
      record.submitted_at ? `<span><i class="ri-send-plane-line me-1"></i>Submitted ${new Date(record.submitted_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })}</span>` : "",
      record.reviewer ? `<span><i class="ri-user-star-line me-1"></i>Reviewed by ${record.reviewer.firstname || ""} ${record.reviewer.lastname || ""}</span>` : "",
      previous ? `<span><i class="ri-arrow-left-right-line me-1"></i>Compared with ${prevLabel(previous)}</span>` : "",
    ].filter(Boolean);

    document.getElementById("submissionHeaderCard").innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3" style="min-width: 0;">
          <span class="kpi-icon bg-primary text-white" style="width: 3rem; height: 3rem; font-size: 1.4rem;"><i class="ri-file-chart-2-line"></i></span>
          <div style="min-width: 0;">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <h4 class="fw-bold mb-0">${UI.demographicPeriodLabel(record)}</h4>
              ${UI.renderStatusBadge(record.status)}
            </div>
            ${facts.length ? `<div class="d-flex flex-wrap gap-3 mt-1 fs-12 text-muted">${facts.join("")}</div>` : ""}
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a href="demographics-tracking.php" class="btn btn-light"><i class="ri-arrow-left-line me-1"></i>Back</a>
          ${
            typeof CAN_EXPORT_REPORTS !== "undefined" && CAN_EXPORT_REPORTS
              ? `<button type="button" class="btn btn-outline-primary" id="exportReportBtn" data-report-key="demographics.submission" data-demographic-id="${record.id}" data-submission-label="${UI.demographicPeriodLabel(record)}"><i class="ri-download-2-line me-1"></i>Export</button>`
              : ""
          }
          ${editable ? `<a href="demographics-tracking.php?id=${record.id}" class="btn btn-primary"><i class="ri-edit-line me-1"></i>Edit</a>` : ""}
        </div>
      </div>
      ${record.review_notes ? `<div class="alert alert-warning bg-warning-transparent mt-3 mb-0 py-2"><i class="ri-chat-quote-line me-2"></i>${record.review_notes}</div>` : ""}`;
  }

  function delta(record, previous, key) {
    const v = M[key].get(record);
    const p = previous ? M[key].get(previous) : null;
    if (v == null || p == null) return null;
    return UI.periodDelta(Number(v), Number(p), { percent: !M[key].flow, prevLabel: prevLabel(previous) });
  }

  function renderKpis(record, previous) {
    UI.renderStatCardsRow(
      "statCardsRow",
      ["total_members", "new_members", "baptisms", "sunday_school"].map((key) => ({
        icon: M[key].icon,
        label: M[key].label,
        value: Number(M[key].get(record) || 0).toLocaleString(),
        color: M[key].color,
        delta: delta(record, previous, key),
        link: { href: UI.metricUrl(key), text: "Full history" },
      })),
    );
  }

  function renderComposition(record, previous) {
    UI.renderCompositionCard("compositionCard", {
      total: record.total_members,
      totalLabel: "total members",
      delta: delta(record, previous, "total_members"),
      items: ["youth", "womens_fellowship", "mens_fellowship", "sunday_school", "seniors"].map((k) => ({
        label: M[k].label,
        value: M[k].get(record),
        color: M[k].color,
      })),
    });
  }

  function renderGender(record) {
    const el = document.getElementById("genderDonut");
    if (!record.male_count && !record.female_count) {
      el.innerHTML = `
        <div class="list-empty">
          <span class="list-empty-icon bg-secondary text-dark"><i class="ri-pie-chart-line"></i></span>
          <div class="fw-semibold mt-2">No gender split on this submission</div>
        </div>`;
      return;
    }
    UI.renderRingDonut("genderDonut", {
      labels: ["Male", "Female"],
      series: [record.male_count || 0, record.female_count || 0],
      colors: ["primary", "pink"],
      centerLabel: "Members",
    });
  }

  function renderChanges(record, previous) {
    if (previous) document.getElementById("changesSubtitle").textContent = `Recorded this period, with the change from ${prevLabel(previous)}`;
    const keys = ["new_members", "departures", "baptisms", "communion", "conversions"];
    document.getElementById("changesCard").innerHTML = `
      <ul class="composition-list">
        ${keys
          .map((k) => {
            const v = Number(M[k].get(record) || 0);
            const p = previous ? M[k].get(previous) : null;
            return `
              <li>
                <a href="${UI.metricUrl(k)}" class="composition-name text-reset">
                  <span class="kpi-icon bg-${M[k].color} ${M[k].color === "secondary" ? "text-dark" : "text-white"} me-2"><i class="${M[k].icon}"></i></span>${M[k].label}
                </a>
                <span class="composition-value">${v.toLocaleString()}${p != null ? UI.changePill(v, Number(p)) : ""}</span>
              </li>`;
          })
          .join("")}
      </ul>`;
  }

  async function loadLeadership(record, previous) {
    const card = document.getElementById("leadershipCard");
    const result = await DemographicsAPIHandler.getClergySummary(USER_TERRITORY.id);
    const counts = result.success ? result.data.counts || {} : {};
    const total = result.success ? result.data.total ?? 0 : 0;
    const teachers = Number(record.sunday_school_teachers_count || 0);
    const prevTeachers = previous ? previous.sunday_school_teachers_count : null;

    const roles = Object.keys(counts);
    card.innerHTML = `
      <ul class="composition-list">
        ${roles.length
          ? roles
              .map(
                (role) => `
          <li>
            <span class="composition-name"><span class="kpi-icon bg-primary text-white me-2"><i class="ri-user-star-line"></i></span>${role}</span>
            <span class="composition-value">${counts[role]}</span>
          </li>`,
              )
              .join("")
          : '<li><span class="composition-name">No pastors on record</span><span></span></li>'}
        <li>
          <span class="composition-name"><span class="kpi-icon bg-purple text-white me-2"><i class="ri-book-read-line"></i></span>Sunday school teachers</span>
          <span class="composition-value">${teachers.toLocaleString()}${prevTeachers != null ? UI.changePill(teachers, Number(prevTeachers)) : ""}</span>
        </li>
      </ul>
      ${roles.length ? `<div class="mt-3"><span class="soft-chip soft-primary">${total} pastor${total === 1 ? "" : "s"} in total</span></div>` : ""}`;
  }

  return { init };
})();

window.DemographicsViewSubmission = DemographicsViewSubmission;
