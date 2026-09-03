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
  // WIDGET DASHBOARD COMPONENTS (Attendance Reports tabbed dashboard)
  //
  // Bigger, richer cards + a small chart-type library modeled on
  // church/dashboard/index.php's card markup and the chart patterns
  // surveyed across the template's other demo dashboards (index-6/index-9)
  // - additive alongside renderStatCard/renderStatCardsRow above, which
  // stay untouched for the pages already using them. Alpha/opacity
  // convention followed throughout (confirmed consistent across the
  // template): 0.05 for gridlines, 0.1 for card tints, 0.2-0.3 for chart
  // fills, 1.0 for solid strokes/bars. Charts default to a single brand
  // color rather than forcing a two-color scheme - only the donut
  // (genuinely categorical slices) needs more than one.
  // ==========================================================================

  const BRAND_COLORS = {
    primary: "#2CA4BF",
    secondary: "#F2BE22",
    warning: "#F2BE22",
    danger: "#F23535",
    success: "#26bf94",
  };

  function brandHex(color) {
    return BRAND_COLORS[color] || color;
  }

  function hexToRgba(hex, alpha) {
    const clean = (hex || "").replace("#", "");
    const bigint = parseInt(clean, 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  /**
   * @param {object} opts {icon, label, value, trend, color, sparklineId}
   *   sparklineId: id of an empty <div> slot for renderSparkline() to fill
   *   after this card's HTML is in the DOM - renderWidgetCardsRow() wires
   *   this automatically when a card carries a `sparkline` array.
   */
  function renderWidgetCard({ icon, label, value, trend = null, color = "primary", sparklineId = null }) {
    const trendHtml = trend ? `<span class="fs-12 fw-semibold d-block mt-1">${trend}</span>` : "";
    const sparklineHtml = sparklineId ? `<div class="ms-auto" id="${sparklineId}" style="min-width: 90px;"></div>` : "";

    return `
      <div class="card custom-card">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <span class="rounded p-3 bg-${color}-transparent flex-shrink-0">
              <i class="${icon} fs-20 text-${color}"></i>
            </span>
            <div class="flex-grow-1">
              <span class="d-block mb-1 text-body fw-semibold">${label}</span>
              <span class="fs-20 fw-semibold lh-1 d-block">${value}</span>
              ${trendHtml}
            </div>
            ${sparklineHtml}
          </div>
        </div>
      </div>`;
  }

  /**
   * Bigger 2-up grid (matches church/dashboard/index.php's wider card
   * footprint) instead of renderStatCardsRow's 4-up compact grid. Cards
   * carrying a non-empty `sparkline` array get their mini trend chart
   * rendered automatically once the row's HTML is in the DOM.
   * @param {string} containerId
   * @param {object[]} cards - renderWidgetCard() opts, each optionally with a `sparkline: number[]`
   */
  function renderWidgetCardsRow(containerId, cards) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const withIds = cards.map((c, i) => ({
      ...c,
      sparklineId: c.sparkline && c.sparkline.length ? `${containerId}Spark${i}` : null,
    }));

    container.innerHTML = withIds
      .map((c) => `<div class="col-xl-6 col-lg-6 col-md-6 col-sm-6">${renderWidgetCard(c)}</div>`)
      .join("");

    withIds.forEach((c) => {
      if (c.sparklineId) renderSparkline(c.sparklineId, c.sparkline, brandHex(c.color));
    });
  }

  /** Tiny axis-less ApexCharts line, used as the embedded mini-chart on a widget card. */
  function renderSparkline(containerId, data, color = BRAND_COLORS.primary) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined" || !data || data.length === 0) return null;

    const chart = new ApexCharts(el, {
      chart: { type: "line", height: 40, sparkline: { enabled: true } },
      series: [{ data }],
      stroke: { width: 2, curve: "smooth" },
      colors: [brandHex(color)],
      tooltip: { enabled: false },
    });
    chart.render();
    return chart;
  }

  /**
   * General trend chart wrapper - area or column, single series or a
   * handful, one brand color by default (not a forced two-color scheme).
   * @param {object} opts {categories, series, type: 'area'|'column', color}
   */
  function renderTrendChart(containerId, { categories, series, type = "area", color = "primary" } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;
    const hex = brandHex(color);

    const options = {
      chart: { type: type === "area" ? "area" : "bar", height: 300, toolbar: { show: false } },
      series,
      xaxis: { categories },
      colors: [hex],
      dataLabels: { enabled: false },
      grid: { borderColor: hexToRgba(hex, 0.05) },
      legend: { show: series.length > 1, position: "bottom" },
    };

    if (type === "area") {
      options.stroke = { curve: "smooth", width: 2 };
      options.fill = { type: "solid", opacity: 0.25 };
    } else {
      options.plotOptions = { bar: { columnWidth: "45%", borderRadius: 6 } };
    }

    const chart = new ApexCharts(el, options);
    chart.render();
    return chart;
  }

  /** Single-value gauge (ApexCharts radialBar) - used for the Sunday Coverage stat. */
  function renderRadialGauge(containerId, { label, percentage, color = "primary" } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;

    const chart = new ApexCharts(el, {
      chart: { type: "radialBar", height: 220 },
      series: [Math.min(100, Math.max(0, percentage))],
      labels: [label],
      colors: [brandHex(color)],
      plotOptions: {
        radialBar: {
          hollow: { size: "60%" },
          dataLabels: {
            value: { fontSize: "22px", fontWeight: 600, formatter: (v) => `${v}%` },
            name: { fontSize: "13px", offsetY: 6 },
          },
        },
      },
    });
    chart.render();
    return chart;
  }

  /**
   * Category-share donut - wraps the same ApexCharts donut config already
   * proven on Demographics' gender-split chart, reused here instead of a
   * new one-off config. Genuinely needs distinct colors per slice
   * (categorical), the one chart type exempt from the single-color default.
   */
  function renderDonutChart(containerId, { labels, series, colors = null } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;
    const palette = colors || [BRAND_COLORS.primary, BRAND_COLORS.secondary, BRAND_COLORS.success, BRAND_COLORS.danger];

    const chart = new ApexCharts(el, {
      chart: { type: "donut", height: 260 },
      series,
      labels,
      colors: palette,
      legend: { position: "bottom" },
      dataLabels: { enabled: false },
    });
    chart.render();
    return chart;
  }

  /**
   * Mixed bar+line combo (e.g. Times Held vs. Average Attendance per
   * gathering type) - both series share one brand color, differentiated by
   * shape (solid bars vs. a line+markers) rather than by a second color.
   */
  function renderComboChart(containerId, { categories, barData, lineData, barLabel = "Times Held", lineLabel = "Average Attendance", color = "primary" } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;
    const hex = brandHex(color);

    const chart = new ApexCharts(el, {
      chart: { height: 320, type: "line", toolbar: { show: false } },
      series: [
        { name: barLabel, type: "column", data: barData },
        { name: lineLabel, type: "line", data: lineData },
      ],
      stroke: { width: [0, 3], curve: "smooth" },
      colors: [hex, hex],
      plotOptions: { bar: { columnWidth: "45%", borderRadius: 6 } },
      xaxis: { categories },
      dataLabels: { enabled: false },
      legend: { position: "bottom" },
      grid: { borderColor: hexToRgba(hex, 0.05) },
    });
    chart.render();
    return chart;
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

  function numberStepperHtml(fieldId, { label, min = 0, max = 99999, value = "", required = false } = {}) {
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
      </div>`;
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

  function renderSubmissionsRows(rows, { onEdit = null } = {}) {
    if (!rows || rows.length === 0) {
      return renderTableEmpty(4, "No submissions yet", "ri-file-list-3-line");
    }

    return rows
      .map((row) => {
        const period = `${row.fiscal_month?.name || ""} ${row.fiscal_year?.year || ""}`.trim();
        const canEdit = row.status === "draft" || row.status === "changes_requested";
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
            <td class="text-end">${editBtn}</td>
          </tr>`;
      })
      .join("");
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
    renderWidgetCard,
    renderWidgetCardsRow,
    renderSparkline,
    renderTrendChart,
    renderRadialGauge,
    renderDonutChart,
    renderComboChart,
    setButtonLoading,
    restoreButton,
    renderTableLoading,
    renderTableEmpty,
    initListDataTable,
    renderFilterToolbar,
    wireFilterToolbar,
    numberStepperHtml,
    initSteppers,
    renderSubmissionsRows,
    updateCompletenessBar,
  };
})();
