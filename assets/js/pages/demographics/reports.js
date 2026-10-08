/**
 * ============================================================================
 * PAGE - REPORTS (church/demographics-growth/reports.php, church/attendance/reports.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One script for each module's Reports page - the page sets
 * window.REPORTS_PAGE = {module: "demographics" | "attendance" | "budget"}.
 *
 * A card per report (what's inside, Generate -> the shared export modal),
 * then "Your recent reports": every run with its status, verification code
 * and a download. The table re-renders from report-center.js's
 * "reports:changed" event, so a report finishing updates it in place - no
 * page reload, no second poller.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, ReportCenter, Toast,
 * jQuery DataTables
 * ============================================================================
 */

const DemographicsReports = (function () {
  "use strict";

  const UI = DemographicsUI;
  const MODULE = (window.REPORTS_PAGE && window.REPORTS_PAGE.module) || "demographics";

  /** What each report holds, shown on its card. */
  const INSIDE = {
    "demographics.summary": { color: "primary", chips: ["Membership", "Groups", "Changes & Holy Communion", "Insights"] },
    "demographics.monthly": { color: "purple", chips: ["Every figure", "Every period", "Year summary"] },
    "demographics.spiritual": { color: "success", chips: ["All four activities", "By period", "The year at a glance"] },
    "demographics.baptisms": { color: "primary", chips: ["By period", "Compared with membership", "Insights"] },
    "demographics.holy_communion": { color: "secondary", chips: ["By period", "Share of members", "Insights"] },
    "demographics.conversions": { color: "purple", chips: ["By period", "Compared with membership", "Insights"] },
    "demographics.departures": { color: "danger", chips: ["By period", "Against new members", "Insights"] },
    "demographics.growth": { color: "info", chips: ["Year over year", "1, 3 or 5 years", "Trends"] },
    "demographics.submission": { color: "pink", chips: ["One period", "Compared with the one before"] },
    "facilities.assets": { color: "success", chips: ["Every item", "A total for each kind", "Receipts and Budgets"] },
    "facilities.rooms": { color: "primary", chips: ["Room by room", "A column to tick", "On loan and in repair"] },
    "facilities.bought": { color: "purple", chips: ["Each month", "By kind", "Receipts and Budgets"] },
    "facilities.repairs": { color: "warning", chips: ["Fixed and their cost", "By item", "Still open"] },
    "facilities.loans": { color: "pink", chips: ["Out now", "Asks waiting", "Back in 90 days"] },
    "attendance.summary": { color: "primary", chips: ["Sundays by month", "Ministries", "Events", "Insights"] },
    "attendance.sunday": { color: "secondary", chips: ["Every Sunday", "Missed Sundays", "Month by month"] },
    "attendance.ministries": { color: "success", chips: ["Every ministry", "Every meeting", "Quiet ones"] },
    "attendance.events": { color: "purple", chips: ["Every event", "Who came"] },
    "attendance.children": { color: "pink", chips: ["Boys and girls", "Share of a Sunday", "Month by month"] },
    "budget.summary": { color: "primary", chips: ["Planned vs actual", "Line by line", "Month by month", "Insights"] },
    "budget.spending": { color: "success", chips: ["Every entry", "Income & Expenses", "Totals"] },
    "budget.lines": { color: "warning", chips: ["Every line", "% used", "Over or under", "Chart"] },
    "budget.year": { color: "info", chips: ["All 12 months", "Each month's budget", "Chart"] },
    "budget.compare": { color: "pink", chips: ["This and the one before", "Change in KES and %", "Chart"] },
    "budget.exceptions": { color: "danger", chips: ["Over-plan lines", "Unplanned money", "For review"] },
    "budget.rollup": { color: "pink", chips: ["Every church", "Who has a budget", "Still owed"] },
    "budget.contributions": { color: "purple", chips: ["Due", "Sent", "Still to send"] },
  };

  const STATUS = {
    queued: { color: "secondary", label: "Queued" },
    running: { color: "primary", label: "Building" },
    ready: { color: "success", label: "Ready" },
    failed: { color: "danger", label: "Failed" },
  };

  let runs = [];

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    document.getElementById("runsBody").innerHTML = UI.renderTableLoading(6);

    const res = await DemographicsAPIHandler.getReportCatalogue(USER_TERRITORY.id, MODULE);
    renderCatalogue(res.success ? res.data || [] : []);
    if (!res.success) Toast.error(res.message || "Couldn't load the reports");

    // Re-render only when a run's status changes, not on every progress tick,
    // so the filter bar isn't rebuilt under someone typing in it.
    let signature = null;
    document.addEventListener("reports:changed", (e) => {
      // Only this module's reports - the header monitor shows them all.
      runs = (e.detail || []).filter((r) => String(r.report_key || "").startsWith(`${MODULE}.`));
      const next = runs.map((r) => `${r.uuid}:${r.status}:${r.expired}`).join("|");
      if (next === signature) return;
      signature = next;
      renderRuns();
    });
    ReportCenter.refreshTray(); // loads the runs and fires reports:changed
  }

  function renderCatalogue(catalogue) {
    const wrap = document.getElementById("reportCatalogue");
    if (!catalogue.length) {
      wrap.innerHTML = `
        <div class="col-12">
          <div class="list-empty">
            <span class="list-empty-icon bg-primary text-white"><i class="ri-file-chart-line"></i></span>
            <div class="fw-semibold mt-2">No reports are available here yet</div>
          </div>
        </div>`;
      return;
    }
    // A metric's report is reached from that metric's page, not listed here.
    catalogue = catalogue.filter((r) => !r.locked_only);
    wrap.innerHTML = catalogue
      .map((r) => {
        const inside = INSIDE[r.key] || { color: "primary", chips: [] };
        const textClass = inside.color === "secondary" ? "text-dark" : "text-white";
        const submission = r.inputs.includes("submission");
        return `
          <div class="col-md-6 col-xl-4">
            <div class="card custom-card report-card h-100 mb-0">
              <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-start gap-3 mb-2">
                  <span class="avatar avatar-md bg-${inside.color} ${textClass} flex-shrink-0"><i class="${r.icon}"></i></span>
                  <div style="min-width: 0;">
                    <h6 class="fw-bold mb-1">${r.title}</h6>
                    <p class="fs-12 mb-0">${r.description}</p>
                  </div>
                </div>
                <div class="d-flex flex-wrap gap-1 my-2">
                  ${inside.chips.map((c) => `<span class="soft-chip soft-${inside.color}">${c}</span>`).join("")}
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-auto pt-2">
                  ${
                    submission
                      ? `<a href="index.php" class="btn btn-outline-primary btn-sm"><i class="ri-history-line me-1"></i>Pick a submission</a>
                         <span class="fs-11">Open one from History, then Export</span>`
                      : `<button type="button" class="btn btn-primary btn-sm" data-report-key="${r.key}" data-module="${MODULE}" data-territory-id="${USER_TERRITORY.id}"><i class="ri-file-download-line me-1"></i>Generate</button>
                         <span class="fs-11">PDF or Excel</span>`
                  }
                </div>
              </div>
            </div>
          </div>`;
      })
      .join("");
  }

  function formatDate(iso) {
    if (!iso) return "-";
    const d = new Date(iso);
    return d.toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
  }

  function renderRuns() {
    if (window.$ && $.fn.DataTable && $.fn.DataTable.isDataTable("#runsTable")) $("#runsTable").DataTable().destroy();
    const body = document.getElementById("runsBody");

    body.innerHTML = runs.length
      ? runs
          .map((r) => {
            const s = STATUS[r.status] || STATUS.queued;
            const pdf = r.format === "pdf";
            const label = r.expired ? "Expired" : s.label;
            const action =
              r.status === "ready" && !r.expired
                ? `<button type="button" class="btn btn-sm btn-primary" data-download="${r.uuid}"><i class="ri-download-2-line me-1"></i>Download</button>`
                : r.status === "failed"
                  ? `<span class="fs-12 text-danger">${r.error || "Couldn't be built"}</span>`
                  : r.expired
                    ? '<span class="fs-12">Generate it again</span>'
                    : '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>';
            return `
              <tr data-row-id="${r.uuid}">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    ${UI.avatarTile(pdf ? "ri-file-pdf-line" : "ri-file-excel-2-line", pdf ? "danger" : "success")}
                    <div style="min-width: 0;">
                      <div class="fw-semibold">${r.title || "Report"}</div>
                      <div class="fs-12">${r.period_label || r.scope_label || ""}</div>
                    </div>
                  </div>
                </td>
                <td data-search="${pdf ? "PDF" : "Excel"}">${pdf ? "PDF" : "Excel"}</td>
                <td data-search="${label}">${UI.pill(label, r.expired ? "secondary" : s.color)}</td>
                <td data-order="${r.created_at || ""}">${formatDate(r.finished_at || r.created_at)}</td>
                <td>${r.verification_code ? `<span class="soft-chip soft-primary rp-code">${r.verification_code}</span>` : "-"}</td>
                <td class="text-end">${action}</td>
              </tr>`;
          })
          .join("")
      : UI.renderTableEmpty(6, "No reports yet - generate one above", "ri-file-download-line");

    body.querySelectorAll("[data-download]").forEach((btn) =>
      btn.addEventListener("click", () => ReportCenter.download(runs.find((r) => r.uuid === btn.dataset.download))),
    );

    UI.renderFilterToolbar("runsFilterToolbar", {
      searchPlaceholder: "Search reports...",
      filters: [
        { id: "runsStatusFilter", label: "All statuses", options: ["Ready", "Building", "Queued", "Failed", "Expired"].map((v) => ({ value: v, label: v })) },
        { id: "runsFormatFilter", label: "All formats", options: [{ value: "PDF", label: "PDF", color: "danger" }, { value: "Excel", label: "Excel", color: "success" }] },
      ],
    });
    const table = UI.initListDataTable("runsTable", { order: [[3, "desc"]], nonSortableColumns: [5], hideDefaultSearch: true, noun: "reports" });
    UI.wireFilterToolbar(
      "runsFilterToolbar",
      table,
      [
        { id: "runsStatusFilter", columnIndex: 2, exact: true },
        { id: "runsFormatFilter", columnIndex: 1, exact: true },
      ],
      { noun: "reports" },
    );
  }

  return { init };
})();

window.DemographicsReports = DemographicsReports;
