/**
 * ============================================================================
 * UI HELPERS - DEMOGRAPHICS & ATTENDANCE
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Small library of pure render/DOM helpers shared by every Demographics and
 * Attendance page, so page-specific JS files stay focused on their own
 * logic instead of each re-implementing badges/stat-cards/loading states.
 *
 * Design rule (root CLAUDE.md): no muted/washed-out text - captions here
 * use `text-body fw-semibold`, never `text-muted`, deliberately deviating
 * from that one detail of the Budget module's precedent.
 *
 * Dependencies: none (pure functions + DOM), Toast (assets/js/utils/toast.js)
 * for callers, not used internally here.
 * ============================================================================
 */

const DemographicsUI = (function () {
  "use strict";

  // ==========================================================================
  // USER TERRITORY RESOLUTION
  //
  // The PHP-rendered USER_TERRITORY comes from $_SESSION['current_role'],
  // populated by authentication/ajax/sync-session.php - a fire-and-forget
  // call from login.js that silently continues even if it fails (only a
  // console.warn, no visible error). When that sync hasn't landed yet, the
  // PHP session's territory_id is empty even though the *correct* data was
  // already written to localStorage by login.js before it ever attempted
  // the PHP sync. Rather than trust the PHP round-trip, fall back to
  // localStorage directly instead of showing an error or waiting on
  // auth-helpers.js's next 30s-throttled background refresh.
  // ==========================================================================

  function resolveUserTerritory(phpTerritory) {
    if (phpTerritory && phpTerritory.id) {
      return phpTerritory;
    }

    try {
      const cached = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (!cached) return phpTerritory;

      const id = cached.territory_id ?? cached.territory?.id ?? null;
      const name = cached.territory_name ?? cached.territory?.name ?? phpTerritory?.name;

      if (id) {
        return { id, name };
      }
    } catch (e) {
      console.warn("Could not resolve territory from localStorage fallback", e);
    }

    return phpTerritory;
  }

  // ==========================================================================
  // STATUS BADGES
  // ==========================================================================

  const STATUS_BADGES = {
    draft: { cls: "bg-secondary", label: "Draft", icon: "ri-draft-line" },
    submitted: { cls: "bg-primary", label: "Submitted", icon: "ri-send-plane-line" },
    approved: { cls: "bg-success", label: "Approved", icon: "ri-checkbox-circle-line" },
    flagged: { cls: "bg-warning text-dark", label: "Flagged", icon: "ri-flag-line" },
    changes_requested: { cls: "bg-danger", label: "Changes Requested", icon: "ri-edit-line" },
  };

  function renderStatusBadge(status) {
    const cfg = STATUS_BADGES[status] || { cls: "bg-secondary", label: status || "Unknown", icon: "ri-question-line" };
    return `<span class="badge ${cfg.cls}"><i class="${cfg.icon} me-1"></i>${cfg.label}</span>`;
  }

  // ==========================================================================
  // STAT CARDS
  // ==========================================================================

  /**
   * @param {object} opts {icon, label, value, trend, color}
   *   color: bootstrap color name (primary/success/warning/danger/secondary) -
   *   used as a solid icon background, not the washed-out `-transparent`
   *   variant (root CLAUDE.md's no-muted-color rule explicitly covers stat
   *   cards).
   */
  function renderStatCard({ icon, label, value, trend = null, color = "primary" }) {
    const trendHtml = trend
      ? `<span class="fs-12 text-body fw-semibold">${trend}</span>`
      : "";
    return `
      <div class="card custom-card">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between">
            <div>
              <span class="d-block mb-1 text-body fw-semibold">${label}</span>
              <h3 class="fw-semibold mb-1">${value}</h3>
              ${trendHtml}
            </div>
            <div class="ms-2">
              <span class="avatar avatar-md avatar-rounded bg-${color} text-white">
                <i class="${icon} fs-20"></i>
              </span>
            </div>
          </div>
        </div>
      </div>`;
  }

  /**
   * Renders a full `row g-3` of stat cards into a container - the shared
   * wrapper every attendance/gathering-types list page uses instead of
   * each hand-rolling its own `col-xl-3` grid markup.
   * @param {string} containerId
   * @param {object[]} cards - array of renderStatCard() opts
   */
  function renderStatCardsRow(containerId, cards) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = cards
      .map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${renderStatCard(c)}</div>`)
      .join("");
  }

  // ==========================================================================
  // DETAIL FIELDS (read-only view modals/panels)
  // ==========================================================================

  /**
   * A single labeled field for read-only detail views (modals, summary
   * panels) - icon-in-circle + label, value rendered either as plain text
   * or a solid color pill, so detail views aren't a wall of identical
   * black-on-white text (root CLAUDE.md design rule).
   * @param {object} opts {icon, label, value, color, pill, size}
   *   color: bootstrap color name, drives both the icon circle and (if pill) the badge
   *   pill: true renders value as a `badge bg-${color}` pill; false renders plain fw-semibold text
   *   size: "lg" bumps the value to h4 for hero fields (e.g. Total Attendance)
   */
  function renderDetailField({ icon, label, value, color = "primary", pill = false, size = null }) {
    const valueHtml = pill
      ? `<span class="badge bg-${color} fs-13">${value}</span>`
      : size === "lg"
        ? `<h4 class="fw-semibold mb-0">${value}</h4>`
        : `<p class="mb-0 fw-semibold">${value}</p>`;
    return `
      <div class="d-flex align-items-start gap-2">
        <span class="avatar avatar-sm avatar-rounded bg-${color} text-white flex-shrink-0">
          <i class="${icon} fs-14"></i>
        </span>
        <div>
          <span class="d-block mb-1 text-body fw-semibold fs-12">${label}</span>
          ${valueHtml}
        </div>
      </div>`;
  }

  // ==========================================================================
  // BUTTON LOADING STATE
  // ==========================================================================

  function setButtonLoading(btnEl, loadingText) {
    if (!btnEl) return;
    btnEl.dataset.originalHtml = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = `<i class="ri-loader-4-line ri-spin me-1"></i>${loadingText}`;
  }

  function restoreButton(btnEl) {
    if (!btnEl) return;
    btnEl.disabled = false;
    if (btnEl.dataset.originalHtml) {
      btnEl.innerHTML = btnEl.dataset.originalHtml;
    }
  }

  // ==========================================================================
  // TABLE LOADING / EMPTY STATES
  // ==========================================================================

  function renderTableLoading(colspan, message = "Loading...") {
    return `
      <tr>
        <td colspan="${colspan}" class="text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="mt-2 text-body fw-semibold mb-0">${message}</p>
        </td>
      </tr>`;
  }

  function renderTableEmpty(colspan, message = "No records found", icon = "ri-inbox-line") {
    return `
      <tr>
        <td colspan="${colspan}" class="text-center py-5">
          <i class="${icon} fs-30 text-primary mb-2 d-block"></i>
          <p class="text-body fw-semibold mb-0">${message}</p>
        </td>
      </tr>`;
  }

  // ==========================================================================
  // NUMBER STEPPER
  //
  // Renders a +/- stepper control around a numeric <input>, per the PWA
  // design doc's explicit "number steppers" call-out for count fields.
  // ==========================================================================

  function numberStepperHtml(fieldId, { label, min = 0, max = 99999, value = "", required = false, hint = null } = {}) {
    return `
      <label for="${fieldId}" class="form-label">${label}${required ? ' <span class="text-danger">*</span>' : ""}</label>
      <div class="input-group stepper-group">
        <button class="btn btn-outline-primary stepper-btn" type="button" data-stepper-target="${fieldId}" data-stepper-dir="-1">
          <i class="ri-subtract-line"></i>
        </button>
        <input type="number" class="form-control text-center" id="${fieldId}" name="${fieldId}"
               min="${min}" max="${max}" value="${value}" ${required ? "required" : ""}>
        <button class="btn btn-outline-primary stepper-btn" type="button" data-stepper-target="${fieldId}" data-stepper-dir="1">
          <i class="ri-add-line"></i>
        </button>
      </div>
      ${hint ? `<div class="form-text">${hint}</div>` : ""}`;
  }

  /** Call once after inserting stepper HTML into the DOM to wire up +/- clicks. */
  function initSteppers(containerEl, onChange = null) {
    containerEl.querySelectorAll(".stepper-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const input = document.getElementById(btn.dataset.stepperTarget);
        if (!input) return;
        const dir = parseInt(btn.dataset.stepperDir, 10);
        const min = input.min !== "" ? parseInt(input.min, 10) : -Infinity;
        const max = input.max !== "" ? parseInt(input.max, 10) : Infinity;
        const current = parseInt(input.value, 10) || 0;
        const next = Math.min(max, Math.max(min, current + dir));
        input.value = next;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        if (onChange) onChange(input);
      });
    });
  }

  // ==========================================================================
  // FISCAL PERIOD - "HAS THIS MONTH ENDED"
  //
  // A month's real totals can't be known until it's actually over - a
  // church can't report "August's" membership/attendance while August is
  // still in progress. Shared here (not duplicated per page) since both
  // the Demographics tracking form (disables in-progress months in its
  // dropdown) and the Attendance overview page (which month's submission
  // status to nag about) need the exact same answer - they'd otherwise
  // disagree, e.g. the overview page prompting to "start August" while the
  // tracking form won't actually let August be selected.
  // ==========================================================================

  function monthHasEnded(year, monthNumber) {
    const now = new Date();
    const currentYear = now.getFullYear();
    const currentMonthNumber = now.getMonth() + 1;
    if (year < currentYear) return true;
    if (year > currentYear) return false;
    return monthNumber < currentMonthNumber;
  }

  /** The period a "this month's submission" prompt should actually refer
   * to - the most recently *completed* month, not the calendar-current
   * (still in progress) one. */
  function mostRecentlyEndedPeriod() {
    const now = new Date();
    const currentMonthNumber = now.getMonth() + 1;
    const currentYear = now.getFullYear();
    return currentMonthNumber === 1
      ? { year: currentYear - 1, month: 12 }
      : { year: currentYear, month: currentMonthNumber - 1 };
  }

  // ==========================================================================
  // LIST TABLE - SEARCH/FILTER/PAGINATION (DataTables)
  //
  // One shared init function instead of every list page hand-rolling the
  // jQuery DataTables setup + pagination/search styling block that
  // assets/js/pages/budget-settings/budget-type.js originally proved out
  // per-page. Callers just need a real <table id="..."> with a <thead>.
  // ==========================================================================

  const _dataTables = {};

  /**
   * @param {string} tableId - id of the <table> element (must already be in the DOM with rows rendered)
   * @param {object} options
   *   searchPlaceholder: string
   *   pageLength: number (default 10)
   *   order: DataTables order array, default [[0, 'asc']]
   *   nonSortableColumns: number[] - zero-based column indexes to disable sorting on (e.g. an Actions column)
   *   hideDefaultSearch: boolean - true when a custom renderFilterToolbar() is used instead
   *     of DataTables' own built-in search box (avoids showing two search inputs)
   */
  function initListDataTable(tableId, options = {}) {
    if (typeof $ === "undefined" || !$.fn || !$.fn.DataTable) {
      console.warn(`DataTables not loaded - skipping init for #${tableId}`);
      return null;
    }

    if ($.fn.DataTable.isDataTable(`#${tableId}`)) {
      $(`#${tableId}`).DataTable().destroy();
      delete _dataTables[tableId];
    }

    const {
      searchPlaceholder = "Search...",
      pageLength = 10,
      order = [[0, "asc"]],
      nonSortableColumns = [],
      hideDefaultSearch = false,
    } = options;

    const dom = hideDefaultSearch
      ? '<"row"<"col-sm-12"tr>>' + '<"row"<"col-sm-12 col-md-3"l><"col-sm-12 col-md-4"i><"col-sm-12 col-md-5"p>>'
      : '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
        '<"row"<"col-sm-12"tr>>' +
        '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>';

    const instance = $(`#${tableId}`).DataTable({
      responsive: true,
      pageLength,
      lengthMenu: [
        [10, 25, 50, 100],
        [10, 25, 50, 100],
      ],
      order,
      columnDefs: nonSortableColumns.length ? [{ orderable: false, targets: nonSortableColumns }] : [],
      language: {
        search: "_INPUT_",
        searchPlaceholder,
        lengthMenu: "Show _MENU_ entries",
        info: "Showing _START_ to _END_ of _TOTAL_ entries",
        infoEmpty: "No entries available",
        infoFiltered: "(filtered from _MAX_ total entries)",
        zeroRecords: "No matching records found",
        paginate: {
          first: '<i class="ri-skip-back-mini-line"></i>',
          last: '<i class="ri-skip-forward-mini-line"></i>',
          next: '<i class="ri-arrow-right-s-line"></i>',
          previous: '<i class="ri-arrow-left-s-line"></i>',
        },
      },
      dom,
      initComplete: function () {
        $(`#${tableId}_wrapper .dataTables_filter input`)
          .addClass("form-control form-control-sm")
          .attr("placeholder", searchPlaceholder);
        $(`#${tableId}_wrapper .dataTables_length select`).addClass("form-select form-select-sm");
        $(`#${tableId}_wrapper .dataTables_filter label`).prepend('<i class="ri-search-line me-2 text-primary"></i>');
      },
      drawCallback: function () {
        $(`#${tableId}_wrapper .dataTables_filter input`).addClass("form-control form-control-sm");
        $(`#${tableId}_wrapper .dataTables_length select`).addClass("form-select form-select-sm");
        $(`#${tableId}_wrapper .paginate_button`).addClass("btn btn-sm");
        $(`#${tableId}_wrapper .paginate_button.current`).addClass("btn-primary");
        $(`#${tableId}_wrapper .paginate_button:not(.current)`).addClass("btn-light border");
      },
    });

    _dataTables[tableId] = instance;
    return instance;
  }

  // ==========================================================================
  // FILTER TOOLBAR (search + dropdown filters, above a DataTable)
  //
  // Replaces DataTables' own bare search box with a proper toolbar (search +
  // N dropdown filters + a clear button) - built once here, called
  // identically from every list page instead of each hand-rolling its own
  // filter row.
  // ==========================================================================

  /**
   * @param {string} containerId - id of an empty container element to render into
   * @param {object} config
   *   searchPlaceholder: string
   *   filters: [{ id, label (shown as the "All X" default option), options: [{value,label}] }]
   */
  function renderFilterToolbar(containerId, { searchPlaceholder = "Search...", filters = [] } = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const selects = filters
      .map(
        (f) => `
        <select class="form-select" id="${f.id}" style="max-width: 200px;">
          <option value="">${f.label}</option>
          ${f.options.map((o) => `<option value="${o.value}">${o.label}</option>`).join("")}
        </select>`,
      )
      .join("");

    container.innerHTML = `
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="flex-grow-1" style="min-width: 220px;">
          <input type="text" class="form-control" id="${containerId}Search" placeholder="${searchPlaceholder}">
        </div>
        ${selects}
        <button type="button" class="btn btn-light border" id="${containerId}Clear" title="Clear filters">
          <i class="ri-close-line"></i>
        </button>
      </div>`;
  }

  /**
   * Wires a renderFilterToolbar() container to a DataTables instance -
   * global text search plus per-column dropdown filters.
   * @param {string} containerId - same id passed to renderFilterToolbar()
   * @param {object} table - the DataTables API instance (initListDataTable()'s return value)
   * @param {object[]} filters - same array passed to renderFilterToolbar(), each with a columnIndex
   */
  function wireFilterToolbar(containerId, table, filters = []) {
    if (!table) return;

    const searchInput = document.getElementById(`${containerId}Search`);
    if (searchInput) {
      searchInput.addEventListener("input", () => table.search(searchInput.value).draw());
    }

    filters.forEach((f) => {
      const select = document.getElementById(f.id);
      if (!select) return;
      select.addEventListener("change", () => {
        // exact: true anchors the search as a regex (^value$) - needed for
        // columns like Status where "Active" would otherwise also match
        // "Inactive" as a plain substring.
        const value = f.exact && select.value ? `^${select.value}$` : select.value;
        table.column(f.columnIndex).search(value, !!f.exact, false).draw();
      });
    });

    const clearBtn = document.getElementById(`${containerId}Clear`);
    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        if (searchInput) searchInput.value = "";
        filters.forEach((f) => {
          const select = document.getElementById(f.id);
          if (select) select.value = "";
        });
        table.search("").columns().search("").draw();
      });
    }
  }

  // ==========================================================================
  // DEMOGRAPHICS SUBMISSIONS TABLE
  //
  // Shared between church/demographics-growth/index.php's History segment
  // and demographics-tracking.php's recent-submissions list - one render
  // function, two call sites, per the reusable-component principle.
  // ==========================================================================

  function renderSubmissionsRows(rows, { onEdit = null, onView = null } = {}) {
    if (!rows || rows.length === 0) {
      return renderTableEmpty(4, "No submissions yet", "ri-file-list-3-line");
    }

    registerViewableSubmissions(rows);

    return rows
      .map((row) => {
        const period = `${row.fiscal_month?.name || ""} ${row.fiscal_year?.year || ""}`.trim();
        const canEdit = row.status === "draft" || row.status === "changes_requested";
        const viewBtn = onView
          ? `<button type="button" class="btn btn-sm btn-light border me-1" onclick="${onView}(${row.id})" title="View Details">
               <i class="ri-eye-line"></i>
             </button>`
          : "";
        const editBtn = canEdit && onEdit
          ? `<button type="button" class="btn btn-sm btn-primary" onclick="${onEdit}(${row.id})">
               <i class="ri-edit-line me-1"></i>Edit
             </button>`
          : `<span class="fs-12 text-body fw-semibold">Locked</span>`;

        return `
          <tr>
            <td class="fw-semibold">${period}</td>
            <td>${row.total_members ?? "-"}</td>
            <td>${renderStatusBadge(row.status)}</td>
            <td class="text-end">${viewBtn}${editBtn}</td>
          </tr>`;
      })
      .join("");
  }

  // ==========================================================================
  // DEMOGRAPHICS SUBMISSION - VIEW DETAILS MODAL
  //
  // Read-only detail view for a single ChurchDemographic submission, shared
  // between the same two call sites as renderSubmissionsRows() above. Uses
  // renderDetailField() throughout per root CLAUDE.md's icon+pill rule for
  // detail views - the list endpoint already returns every field needed
  // (index()/show() both load the full model), so no extra API call.
  // ==========================================================================

  const SUBMISSION_FIELD_META = {
    male_count: { label: "Male", icon: "ri-user-line", color: "primary" },
    female_count: { label: "Female", icon: "ri-women-line", color: "secondary" },
    youth_count: { label: "Youth (13-35)", icon: "ri-user-star-line", color: "success" },
    seniors_count: { label: "Seniors (60+)", icon: "ri-user-heart-line", color: "warning" },
    womens_fellowship_count: { label: "Women's Fellowship", icon: "ri-group-line", color: "secondary" },
    mens_fellowship_count: { label: "Men's Fellowship", icon: "ri-group-2-line", color: "info" },
    sunday_school_male_count: { label: "Sunday School (M)", icon: "ri-men-line", color: "primary" },
    sunday_school_female_count: { label: "Sunday School (F)", icon: "ri-women-line", color: "secondary" },
    new_members_count: { label: "New Members", icon: "ri-user-add-line", color: "success" },
    transferred_out_count: { label: "Transferred Out", icon: "ri-user-unfollow-line", color: "danger" },
    baptisms_count: { label: "Baptisms", icon: "ri-drop-line", color: "primary" },
    communion_participants_count: { label: "Communion Participants", icon: "ri-cup-line", color: "warning" },
    conversions_count: { label: "New Conversions", icon: "ri-heart-line", color: "success" },
  };

  const SUBMISSION_VIEW_MODAL_ID = "demographicsSubmissionViewModal";
  let viewableSubmissions = {};

  function registerViewableSubmissions(rows) {
    viewableSubmissions = Object.fromEntries((rows || []).map((r) => [r.id, r]));
  }

  function ensureSubmissionViewModalMounted() {
    if (document.getElementById(SUBMISSION_VIEW_MODAL_ID)) return;

    const modalHtml = `
      <div class="modal fade" id="${SUBMISSION_VIEW_MODAL_ID}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="ri-eye-line me-2"></i>Demographics Submission</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="demographicsSubmissionViewBody"></div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>`;

    const container = document.createElement("div");
    container.innerHTML = modalHtml;
    document.body.appendChild(container.firstElementChild);
  }

  function openSubmissionViewModalById(id) {
    const record = viewableSubmissions[id];
    if (record) openSubmissionViewModal(record);
  }

  function openSubmissionViewModal(record) {
    ensureSubmissionViewModalMounted();

    const period = `${record.fiscal_month?.name || ""} ${record.fiscal_year?.year || ""}`.trim();
    const submitted = record.submitted_at ? new Date(record.submitted_at).toLocaleString() : "Not submitted yet";
    const reviewed = record.reviewed_at ? new Date(record.reviewed_at).toLocaleString() : "-";
    const reviewerName = record.reviewer ? `${record.reviewer.firstname || ""} ${record.reviewer.lastname || ""}`.trim() : "-";

    const breakdownFields = [
      "male_count", "female_count", "youth_count", "seniors_count",
      "womens_fellowship_count", "mens_fellowship_count",
      "sunday_school_male_count", "sunday_school_female_count",
      "new_members_count", "transferred_out_count",
      "baptisms_count", "communion_participants_count", "conversions_count",
    ];

    const breakdownHtml = breakdownFields
      .map((f) => {
        const meta = SUBMISSION_FIELD_META[f];
        return `<div class="col-6 col-md-3">${renderDetailField({ ...meta, value: record[f] ?? 0, pill: true })}</div>`;
      })
      .join("");

    document.getElementById("demographicsSubmissionViewBody").innerHTML = `
      <div class="row g-3">
        <div class="col-md-6">
          ${renderDetailField({ icon: "ri-calendar-line", label: "Period", value: period || "-", color: "primary" })}
        </div>
        <div class="col-md-6">
          <span class="d-block mb-1 text-body fw-semibold fs-12">Status</span>
          ${renderStatusBadge(record.status)}
        </div>
        <div class="col-12">
          ${renderDetailField({ icon: "ri-team-line", label: "Total Members", value: record.total_members ?? 0, color: "primary", size: "lg" })}
        </div>
        ${breakdownHtml}
        ${record.review_notes ? `
        <div class="col-12">
          ${renderDetailField({ icon: "ri-file-text-line", label: "Reviewer Notes", value: record.review_notes, color: "danger" })}
        </div>` : ""}
        <div class="col-md-4">
          ${renderDetailField({ icon: "ri-send-plane-line", label: "Submitted", value: `<span class="fs-13">${submitted}</span>`, color: "secondary" })}
        </div>
        <div class="col-md-4">
          ${renderDetailField({ icon: "ri-shield-check-line", label: "Reviewed By", value: reviewerName, color: "secondary" })}
        </div>
        <div class="col-md-4">
          ${renderDetailField({ icon: "ri-history-line", label: "Reviewed At", value: `<span class="fs-13">${reviewed}</span>`, color: "secondary" })}
        </div>
      </div>`;

    new bootstrap.Modal(document.getElementById(SUBMISSION_VIEW_MODAL_ID)).show();
  }

  // ==========================================================================
  // COMPLETENESS BAR
  //
  // Static shell is rendered server-side (includes/ui-helpers-templates.php
  // renderCompletenessBar()) since it's a single fixed instance per page -
  // this just updates it at runtime as the user fills the form.
  // ==========================================================================

  function updateCompletenessBar(containerId, filledCount, totalFields) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const pct = totalFields > 0 ? Math.round((filledCount / totalFields) * 100) : 0;
    const bar = container.querySelector("[data-completeness-bar]");
    const label = container.querySelector("[data-completeness-label]");
    if (bar) bar.style.width = `${pct}%`;
    if (label) label.textContent = `${pct}%`;
  }

  // ==========================================================================
  // PUBLIC API
  // ==========================================================================

  return {
    resolveUserTerritory,
    renderStatusBadge,
    renderStatCard,
    renderStatCardsRow,
    renderDetailField,
    setButtonLoading,
    restoreButton,
    renderTableLoading,
    renderTableEmpty,
    initListDataTable,
    renderFilterToolbar,
    wireFilterToolbar,
    monthHasEnded,
    mostRecentlyEndedPeriod,
    numberStepperHtml,
    initSteppers,
    renderSubmissionsRows,
    registerViewableSubmissions,
    openSubmissionViewModal,
    openSubmissionViewModalById,
    updateCompletenessBar,
  };
})();
