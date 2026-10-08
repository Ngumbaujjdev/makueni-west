/**
 * ============================================================================
 * REPORT CENTER - export modal + header monitor (every page)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * The frontend for docs/specs/reports-spec.md, used by any module:
 *
 *   ReportCenter.open({ territoryId, reportKey, module, params: { fiscal_year_id, year, month, years, demographic_id, metric, gathering_type_id }, locked, title })
 *
 * `module` (demographics | attendance) keeps "Choose a different report" to
 * that module's reports. `year` (a plain year like 2026) is turned into the
 * fiscal year id; `month` (1-12) narrows a year to one month for reports
 * that take it.
 *
 * From a page's Export button (data-lock="1") the modal is locked to that
 * page's report: only the period and format are chosen, with a "Choose a
 * different report" link. The Reports page opens it unlocked.
 *
 * Modal (includes/report-modal.php), with a Choose / Preview / Download step
 * indicator in its header:
 *   choose  - report (grouped reports show as one card with option pills),
 *             period (years + All time, or 1/3/5/All), PDF/Excel toggle
 *   preview - what will be in the file: figures, each table's first rows and
 *             whether it has totals, the insights
 *   working - a progress ring and a checklist of build stages. Each stage
 *             stays visible for a moment even when the build takes under a
 *             second, so you can see what happened.
 *   done    - the file, with real download progress (streamed, Content-Length)
 *
 * Monitor (the Reports icon in includes/header.php): recent runs with live
 * status and downloads. It only polls while something is building, and
 * carries on across pages (a localStorage flag).
 *
 * Loaded from header.php, before Bootstrap and Toast - everything that
 * needs them waits for DOMContentLoaded.
 *
 * Dependencies: AppConfig, Constants, bootstrap (Modal), Toast (optional)
 * ============================================================================
 */

const ReportCenter = (function () {
  "use strict";

  const ACTIVE_FLAG = "mwd_reports_active";
  const FORMAT_LABEL = { pdf: "PDF", xlsx: "Excel" };
  const FORMAT_HINT = {
    pdf: "For printing and sharing, with a QR code that proves it's genuine.",
    xlsx: "For working with the numbers - one sheet per table.",
  };
  const TONE = {
    good: { cls: "success", label: "Going well", icon: "ri-checkbox-circle-line" },
    watch: { cls: "secondary", label: "Keep an eye on", icon: "ri-eye-line" },
    concern: { cls: "danger", label: "Needs attention", icon: "ri-error-warning-line" },
  };
  /** Icon tile colour per report, from the category palette. */
  const COLORS = {
    "demographics.summary": "primary",
    "demographics.monthly": "purple",
    "demographics.spiritual": "success",
    "demographics.baptisms": "primary",
    "demographics.holy_communion": "secondary",
    "demographics.conversions": "purple",
    "demographics.departures": "danger",
    "demographics.growth": "info",
    "demographics.submission": "pink",
    "demographics.metric": "primary",
    "attendance.summary": "primary",
    "attendance.sunday": "secondary",
    "attendance.ministries": "success",
    "attendance.events": "purple",
    "attendance.children": "pink",
    "facilities.assets": "success",
    "facilities.loans": "purple",
    "budget.summary": "primary",
    "budget.spending": "success",
    "budget.statement": "purple",
    "budget.lines": "warning",
    "budget.year": "info",
    "budget.compare": "pink",
    "budget.exceptions": "danger",
    "budget.line": "purple",
    "budget.rollup": "pink",
    "budget.contributions": "purple",
  };
  const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
  const GROUP_SHORT = { "demographics.spiritual": "All four" };
  const STAGES = [
    { key: "queued", label: "Waiting in the queue", at: 0 },
    { key: "collect", label: "Collecting the figures", at: 15 },
    { key: "insights", label: "Working out insights", at: 45 },
    { key: "draw", label: "Drawing the PDF", xlsx: "Building the workbook", at: 70 },
    { key: "ready", label: "Ready", at: 100 },
  ];
  const STAGE_DWELL = 420; // ms each stage stays on screen at least
  const RING = 2 * Math.PI * 52;

  const state = {
    territoryId: null,
    catalogue: null,
    catalogueFor: null,
    years: null,
    reportKey: null,
    module: null,
    locked: false,
    lockedTitle: null,
    params: {},
    format: "pdf",
    run: null,
    queuedSince: null,
    pollTimer: null,
    trayTimer: null,
    lastRuns: {},
    shownStage: 0,
    targetStage: 0,
    stageTimer: null,
    finished: null,
  };

  const $ = (id) => document.getElementById(id);
  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (color) => (color === "secondary" || color === "warning" ? "text-dark" : "text-white");

  // ==========================================================================
  // API
  // ==========================================================================

  function headers(json = true) {
    const h = { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
    if (json) h["Content-Type"] = "application/json";
    // Budget reports follow the role the user is acting in, like the budget pages.
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* no role cached - the API uses the primary one */
    }
    return h;
  }

  async function api(method, path, body) {
    try {
      const res = await fetch(`${AppConfig.API_BASE_URL}${path}`, { method, headers: headers(), body: body ? JSON.stringify(body) : undefined });
      const data = await res.json().catch(() => ({}));
      return { ok: res.ok && data.success !== false, status: res.status, data: data.data, message: data.message };
    } catch (e) {
      return { ok: false, status: 0, message: "Couldn't reach the server. Check your connection and try again." };
    }
  }

  /**
   * Downloads a run's file with real progress (the API sends Content-Length;
   * without it onProgress gets null and callers show a spinner instead).
   */
  async function fetchFile(run, onProgress = () => {}) {
    const res = await fetch(`${AppConfig.API_BASE_URL}/reports/runs/${run.uuid}/download`, { headers: headers(false) });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.message || "Couldn't download the file.");
    }
    const total = Number(res.headers.get("Content-Length")) || run.file_size || 0;
    if (!res.body || !res.body.getReader) {
      onProgress(null);
      return res.blob();
    }
    const reader = res.body.getReader();
    const chunks = [];
    let received = 0;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(value);
      received += value.length;
      onProgress(total ? Math.min(100, Math.round((received / total) * 100)) : null);
    }
    onProgress(100);
    return new Blob(chunks, { type: run.format === "pdf" ? "application/pdf" : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
  }

  function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
  }

  /** Download with progress shown on `button` (a Download button, or a tray icon button). */
  async function download(run, button = null, { compact = false } = {}) {
    if (!run) return;
    const original = button ? button.innerHTML : null;
    const paint = (pct) => {
      if (!button) return;
      if (compact) {
        button.innerHTML = miniRing(pct);
      } else {
        button.classList.add("is-downloading");
        button.innerHTML = `<span class="rp-btn-fill" style="width: ${pct ?? 100}%"></span><span class="rp-btn-label">${pct == null ? '<span class="spinner-border spinner-border-sm me-1"></span>Downloading' : `Downloading ${pct}%`}</span>`;
      }
      const bar = $("rpDlBar");
      if (bar && !compact) {
        bar.hidden = false;
        bar.querySelector("i").style.width = `${pct ?? 100}%`;
      }
    };
    try {
      if (button) button.disabled = true;
      paint(0);
      const blob = await fetchFile(run, paint);
      saveBlob(blob, run.file_name || `report.${run.format}`);
      if (button) {
        button.classList.remove("is-downloading");
        button.classList.add("is-saved");
        button.innerHTML = compact ? '<i class="ri-check-line"></i>' : '<i class="ri-check-line me-1"></i>Saved';
      }
    } catch (e) {
      toast("error", e.message);
    } finally {
      if (button) {
        setTimeout(() => {
          button.disabled = false;
          button.classList.remove("is-saved", "is-downloading");
          button.innerHTML = original;
        }, 2200);
      }
    }
  }

  async function openPdf(run, button) {
    // Open the tab first (a popup opened after an await gets blocked).
    const tab = window.open("", "_blank");
    const original = button?.innerHTML;
    try {
      if (button) button.disabled = true;
      const blob = await fetchFile(run, (pct) => {
        if (button) button.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${pct == null ? "Opening" : `${pct}%`}`;
      });
      const url = URL.createObjectURL(new Blob([blob], { type: "application/pdf" }));
      if (tab) tab.location.href = url;
      else window.location.href = url;
    } catch (e) {
      if (tab) tab.close();
      toast("error", e.message);
    } finally {
      if (button) {
        button.disabled = false;
        button.innerHTML = original;
      }
    }
  }

  function miniRing(pct) {
    const c = 2 * Math.PI * 8;
    const offset = pct == null ? c * 0.75 : c - (c * pct) / 100;
    return `<svg class="rp-mini-ring${pct == null ? " is-spinning" : ""}" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" class="rp-ring-track"></circle><circle cx="10" cy="10" r="8" class="rp-ring-bar" style="stroke-dasharray: ${c}; stroke-dashoffset: ${offset}"></circle></svg>`;
  }

  function toast(type, message) {
    if (window.Toast && typeof Toast[type] === "function") Toast[type](message);
  }

  // ==========================================================================
  // MODAL
  // ==========================================================================

  function modal() {
    return bootstrap.Modal.getOrCreateInstance($("reportModal"));
  }

  function showStep(step) {
    document.querySelectorAll("#reportModal .rp-step").forEach((el) => {
      el.hidden = el.dataset.rpStep !== step;
    });
    const dot = { choose: "choose", preview: "preview", working: "download", done: "download", error: "download" }[step];
    const order = ["choose", "preview", "download"];
    document.querySelectorAll("#rpStepper [data-step-dot]").forEach((li) => {
      const i = order.indexOf(li.dataset.stepDot);
      li.classList.toggle("is-on", li.dataset.stepDot === dot);
      li.classList.toggle("is-done", i < order.indexOf(dot) || (step === "done" && li.dataset.stepDot === "download"));
    });

    const buttons = {
      choose: `
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="rpToPreview">Preview<i class="ri-arrow-right-line ms-1"></i></button>`,
      preview: `
        <button type="button" class="btn btn-light" id="rpBack"><i class="ri-arrow-left-line me-1"></i>Back</button>
        <button type="button" class="btn btn-success" id="rpGenerate"><i class="ri-file-download-line me-1"></i>Generate ${FORMAT_LABEL[state.format]}</button>`,
      working: `<button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep working</button>`,
      done: `
        <button type="button" class="btn btn-light" id="rpAnother"><i class="ri-add-line me-1"></i>Make another</button>
        ${state.run?.format === "pdf" ? '<button type="button" class="btn btn-outline-primary" id="rpOpen"><i class="ri-external-link-line me-1"></i>Open</button>' : ""}
        <button type="button" class="btn btn-primary rp-dl-btn" id="rpDownload"><i class="ri-download-2-line me-1"></i>Download</button>`,
      error: `
        <button type="button" class="btn btn-light" id="rpBack"><i class="ri-arrow-left-line me-1"></i>Back</button>
        <button type="button" class="btn btn-primary" id="rpRetry"><i class="ri-refresh-line me-1"></i>Try again</button>`,
    };
    $("rpFooter").innerHTML = buttons[step];
    $("rpSummary").hidden = step !== "choose";
    $("rpToPreview")?.addEventListener("click", preview);
    $("rpBack")?.addEventListener("click", () => showStep("choose"));
    $("rpGenerate")?.addEventListener("click", generate);
    $("rpRetry")?.addEventListener("click", generate);
    $("rpAnother")?.addEventListener("click", () => {
      $("reportModalScope").textContent = "PDF or Excel, with insights and recommendations";
      showStep("choose");
    });
    $("rpDownload")?.addEventListener("click", (e) => download(state.run, e.currentTarget));
    $("rpOpen")?.addEventListener("click", (e) => openPdf(state.run, e.currentTarget));
  }

  /**
   * Opens the export modal.
   * @param {object} opts {territoryId, reportKey, params: {fiscal_year_id, years, demographic_id}}
   */
  async function open(opts = {}) {
    state.territoryId = opts.territoryId;
    state.reportKey = opts.reportKey || null;
    state.module = opts.module || (opts.reportKey ? String(opts.reportKey).split(".")[0] : null);
    state.locked = !!opts.locked && !!opts.reportKey;
    state.lockedTitle = opts.title || null;
    state.params = { ...(opts.params || {}) };
    state.format = opts.format || "pdf";
    setFormat(state.format);
    $("reportModalScope").textContent = "PDF or Excel, with insights and recommendations";
    $("reportModalTitle").textContent = state.locked && state.lockedTitle ? `Export · ${state.lockedTitle}` : "Export a report";

    $("rpReports").innerHTML = '<span class="skel" style="height: 4rem;"></span><span class="skel" style="height: 4rem;"></span><span class="skel" style="height: 4rem;"></span>';
    $("rpPeriod").innerHTML = "";
    showStep("choose");
    modal().show();

    if (state.catalogueFor !== state.territoryId) {
      const res = await api("GET", `/reports/catalogue?territory_id=${encodeURIComponent(state.territoryId)}`);
      if (!res.ok) {
        $("rpError").textContent = res.message || "Couldn't load the reports.";
        showStep("error");
        return;
      }
      state.catalogue = res.data || [];
      state.catalogueFor = state.territoryId;
    }
    if (!state.years) {
      const res = await api("GET", "/fiscal-years");
      const thisYear = new Date().getFullYear();
      state.years = (res.ok ? res.data || [] : []).filter((y) => y.year <= thisYear).sort((a, b) => a.year - b.year).slice(-5);
    }
    // A plain year (e.g. from a page's year filter) becomes its fiscal year id.
    if (state.params.year && !state.params.fiscal_year_id) {
      state.params.fiscal_year_id = state.params.year === "all" ? "all" : state.years.find((y) => String(y.year) === String(state.params.year))?.id;
      delete state.params.year;
    }
    if (!state.reportKey || !reports().some((r) => r.key === state.reportKey)) state.reportKey = reports().find((r) => !r.locked_only)?.key;
    renderChoose();
  }

  function current() {
    return state.catalogue.find((r) => r.key === state.reportKey);
  }

  /** The catalogue, narrowed to the module the modal was opened for. */
  function reports() {
    return (state.catalogue || []).filter((r) => !state.module || !r.module || r.module === state.module);
  }

  /** The chosen report's title - a metric report is named after the page's metric. */
  function reportTitle(report) {
    return state.locked && state.lockedTitle && report.key === state.reportKey ? state.lockedTitle : report.title;
  }

  function setFormat(format) {
    state.format = format;
    document.querySelectorAll('#reportModal input[name="rp_format"]').forEach((r) => {
      r.checked = r.value === format;
    });
    $("rpFormatHint").textContent = FORMAT_HINT[format];
    updateSummary();
  }

  function iconTile(key, icon, size = "") {
    const color = COLORS[key] || "primary";
    return `<span class="rp-tile-icon ${size} bg-${color} ${textOn(color)}"><i class="${esc(icon)}"></i></span>`;
  }

  function renderChoose() {
    const lockedBox = $("rpLocked");
    const listWrap = $("rpReportsWrap");
    const report = current();
    if (state.locked && report) {
      // Opened from a page's Export: that page's report, just period + format.
      lockedBox.innerHTML = `
        ${iconTile(report.key, report.icon)}
        <span class="rp-report-text">
          <span class="rp-locked-label">You're exporting</span>
          <strong>${esc(reportTitle(report))}</strong>
          <small>${esc(report.key === "demographics.metric" ? "This figure over time, from this page." : state.params.gathering_type_id ? "This one only - its meetings and who came." : report.description)}</small>
        </span>
        <button type="button" class="rp-edit" id="rpUnlock"><i class="ri-list-check me-1"></i>Choose a different report</button>`;
      lockedBox.hidden = false;
      listWrap.hidden = true;
      $("reportModalTitle").textContent = `Export · ${reportTitle(report)}`;
      $("rpUnlock").addEventListener("click", () => {
        state.locked = false;
        if (report.locked_only) state.reportKey = reports().find((r) => !r.locked_only)?.key;
        delete state.params.gathering_type_id;
        $("reportModalTitle").textContent = "Export a report";
        renderChoose();
      });
      renderPeriod();
      return;
    }
    lockedBox.hidden = true;
    listWrap.hidden = false;
    const needsSubmission = (r) => r.inputs.includes("submission") && !state.params.demographic_id;
    // Grouped reports (Spiritual activities + its four activity reports)
    // render as one card with pills; the rest as rows.
    const groups = [];
    const cards = [];
    reports().forEach((r) => {
      if (r.locked_only) return; // reached from its own page (e.g. a metric's report)
      if (r.group) {
        let g = groups.find((x) => x.name === r.group);
        if (!g) {
          g = { name: r.group, reports: [] };
          groups.push(g);
          cards.push({ group: g });
        }
        g.reports.push(r);
      } else {
        cards.push({ report: r });
      }
    });

    $("rpReports").innerHTML = cards
      .map(({ report: r, group: g }) => {
        if (r) {
          const off = needsSubmission(r);
          return `
            <label class="rp-report${off ? " is-disabled" : ""}${r.key === state.reportKey ? " is-on" : ""}" title="${off ? "Open a submission to export it" : ""}">
              <input type="radio" name="rp_report" value="${r.key}" ${r.key === state.reportKey ? "checked" : ""} ${off ? "disabled" : ""}>
              ${iconTile(r.key, r.icon)}
              <span class="rp-report-text"><strong>${esc(r.title)}</strong><small>${off ? "Open a submission, then export it from there." : esc(r.description)}</small></span>
              <i class="ri-checkbox-circle-fill rp-report-tick"></i>
            </label>`;
        }
        const active = g.reports.some((x) => x.key === state.reportKey);
        const lead = g.reports[0];
        return `
          <div class="rp-report rp-group${active ? " is-on" : ""}">
            ${iconTile(lead.key, lead.icon)}
            <span class="rp-report-text">
              <strong>${esc(g.name)}</strong>
              <small>All four together, or one activity on its own.</small>
              <span class="rp-pills">
                ${g.reports
                  .map(
                    (x) => `
                  <label class="rp-pill${x.key === state.reportKey ? " is-on" : ""}">
                    <input type="radio" name="rp_report" value="${x.key}" ${x.key === state.reportKey ? "checked" : ""}>
                    <span><span class="count-dot bg-${COLORS[x.key] || "primary"}"></span>${esc(GROUP_SHORT[x.key] || x.title)}</span>
                  </label>`,
                  )
                  .join("")}
              </span>
            </span>
            <i class="ri-checkbox-circle-fill rp-report-tick"></i>
          </div>`;
      })
      .join("");

    $("rpReports").querySelectorAll('input[name="rp_report"]').forEach((input) =>
      input.addEventListener("change", () => {
        state.reportKey = input.value;
        $("rpReports").querySelectorAll(".rp-report").forEach((card) => card.classList.toggle("is-on", !!card.querySelector("input:checked")));
        $("rpReports").querySelectorAll(".rp-pill").forEach((pill) => pill.classList.toggle("is-on", !!pill.querySelector("input:checked")));
        renderPeriod();
      }),
    );
    renderPeriod();
  }

  function segmented(name, options, value) {
    return `<div class="seg-control" role="radiogroup">${options
      .map((o) => `<button type="button" class="seg-btn${String(o.value) === String(value) ? " active" : ""}" data-${name}="${o.value}">${esc(o.label)}</button>`)
      .join("")}</div>`;
  }

  function renderPeriod() {
    const report = current();
    const wrap = $("rpPeriod");
    if (!report) return;

    if (report.inputs.includes("fiscal_month") && state.params.from && state.params.to) {
      // The page is showing a range of months: export that range.
      $("rpPeriodTitle").textContent = "Period";
      wrap.innerHTML = `
        <span class="soft-chip soft-purple rp-period-chip"><i class="ri-calendar-2-line"></i>${esc(rangeLabel())}</span>
        <button type="button" class="rp-edit mt-2" id="rpUseYear"><i class="ri-calendar-line me-1"></i>Use a year instead</button>
        ${gatheringChip(report)}`;
      $("rpUseYear").addEventListener("click", () => {
        delete state.params.from;
        delete state.params.to;
        renderPeriod();
      });
      updateSummary();
      return;
    }
    if (report.inputs.includes("fiscal_year")) {
      $("rpPeriodTitle").textContent = "Period";
      const thisYear = new Date().getFullYear();
      if (!state.params.fiscal_year_id) state.params.fiscal_year_id = (state.years.find((y) => y.year === thisYear) || state.years[state.years.length - 1])?.id;
      wrap.innerHTML =
        // Budget reports cover a month or a year - never all time.
        segmented("year", [...state.years.map((y) => ({ value: y.id, label: y.year })), ...(report.module === "budget" ? [] : [{ value: "all", label: "All time" }])], state.params.fiscal_year_id) +
        (report.inputs.includes("fiscal_month") ? '<div class="rp-month" id="rpMonthWrap"><select id="rpMonth" aria-label="Month"></select></div>' : "") +
        gatheringChip(report);
      wrap.querySelectorAll("[data-year]").forEach((btn) =>
        btn.addEventListener("click", () => {
          state.params.fiscal_year_id = btn.dataset.year === "all" ? "all" : Number(btn.dataset.year);
          wrap.querySelectorAll("[data-year]").forEach((b) => b.classList.toggle("active", b === btn));
          renderMonths();
          updateSummary();
        }),
      );
      renderMonths();
    } else if (report.inputs.includes("years")) {
      $("rpPeriodTitle").textContent = "Range";
      state.params.years = state.params.years || "3";
      wrap.innerHTML = segmented("range", [{ value: "1", label: "1 year" }, { value: "3", label: "3 years" }, { value: "5", label: "5 years" }, { value: "all", label: "All time" }], state.params.years);
      wrap.querySelectorAll("[data-range]").forEach((btn) =>
        btn.addEventListener("click", () => {
          state.params.years = btn.dataset.range;
          wrap.querySelectorAll("[data-range]").forEach((b) => b.classList.toggle("active", b === btn));
          updateSummary();
        }),
      );
    } else if (report.inputs.includes("budget")) {
      $("rpPeriodTitle").textContent = report.inputs.includes("line") ? "Budget line" : "Budget";
      wrap.innerHTML = `<span class="soft-chip soft-purple rp-period-chip"><i class="ri-wallet-3-line"></i>${esc(state.lockedTitle || "The budget you're viewing")}</span>`;
    } else if (!report.inputs.length) {
      // A list as it stands today (the member directory, the visitors) - no period to pick.
      $("rpPeriodTitle").textContent = "Covers";
      wrap.innerHTML = `<span class="soft-chip soft-primary rp-period-chip"><i class="ri-calendar-check-line"></i>${report.module === "facilities" ? "Everything" : "Everyone"}, as at today</span>`;
    } else {
      $("rpPeriodTitle").textContent = "Submission";
      wrap.innerHTML = `<span class="soft-chip soft-primary rp-period-chip"><i class="ri-file-list-3-line"></i>${esc(state.params.submission_label || "The submission you're viewing")}</span>`;
    }
    updateSummary();
  }

  /** Month picker for reports that take one: "Whole year" or a month of the chosen year (up to now). Hidden for all time. */
  function renderMonths() {
    const select = $("rpMonth");
    if (!select) return;
    const year = state.years.find((y) => String(y.id) === String(state.params.fiscal_year_id))?.year;
    const now = new Date();
    // Budgets are planned ahead, so every month of the year; elsewhere up to now.
    const last = current()?.module === "budget" || Number(year) !== now.getFullYear() ? 12 : now.getMonth() + 1;
    if (state.params.month && Number(state.params.month) > last) delete state.params.month;
    select.innerHTML = '<option value="">Whole year</option>' + MONTHS.slice(0, last).map((m, i) => `<option value="${i + 1}">${m}</option>`).join("");
    select.value = state.params.month ? String(state.params.month) : "";
    $("rpMonthWrap").hidden = state.params.fiscal_year_id === "all";
    // Select2 where the page has it (DemographicsUI is a top-level const, not a window property).
    if (typeof DemographicsUI !== "undefined" && window.jQuery && jQuery.fn.select2) DemographicsUI.enhanceSelect(select, { search: false });
    select.onchange = () => {
      state.params.month = select.value ? Number(select.value) : null;
      updateSummary();
    };
  }

  /** The one ministry / event a report is narrowed to (from the page's filter). */
  function gatheringChip(report) {
    if (!report.inputs.includes("gathering_type") || !state.params.gathering_type_id) return "";
    return `<span class="soft-chip soft-success rp-period-chip mt-2"><i class="ri-group-line"></i>Only ${esc(state.lockedTitle || "this gathering")}</span>`;
  }

  function rangeLabel() {
    const short = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const fmt = (v) => `${short[Number(String(v).slice(5)) - 1]} ${String(v).slice(0, 4)}`;
    return state.params.from === state.params.to ? fmt(state.params.from) : `${fmt(state.params.from)} - ${fmt(state.params.to)}`;
  }

  function periodText() {
    const report = current();
    if (!report) return "";
    if (report.inputs.includes("fiscal_month") && state.params.from && state.params.to) return rangeLabel();
    if (report.inputs.includes("fiscal_year")) {
      if (state.params.fiscal_year_id === "all") return "All time";
      const year = String(state.years?.find((y) => String(y.id) === String(state.params.fiscal_year_id))?.year || "");
      return report.inputs.includes("fiscal_month") && state.params.month ? `${MONTHS[state.params.month - 1]} ${year}` : year;
    }
    if (report.inputs.includes("years")) return state.params.years === "all" ? "All time" : `Last ${state.params.years} year${state.params.years === "1" ? "" : "s"}`;
    if (report.inputs.includes("budget")) return state.lockedTitle || "This budget";
    if (!report.inputs.length) return "As at today";
    return state.params.submission_label || "This submission";
  }

  function updateSummary() {
    const report = state.catalogue && current();
    const el = $("rpSummary");
    if (!el || !report) return;
    el.innerHTML = `${iconTile(report.key, report.icon, "is-sm")}<span><b>${esc(reportTitle(report))}</b> · ${esc(periodText())} · ${FORMAT_LABEL[state.format]}</span>`;
  }

  function requestBody() {
    const report = current();
    const body = { report_key: state.reportKey, territory_id: state.territoryId };
    if (report.inputs.includes("fiscal_year")) body.fiscal_year_id = state.params.fiscal_year_id;
    if (report.inputs.includes("years")) body.years = state.params.years;
    if (report.inputs.includes("submission")) body.demographic_id = state.params.demographic_id;
    if (report.inputs.includes("budget")) body.budget_id = state.params.budget_id;
    if (report.inputs.includes("line")) body.line_id = state.params.line_id;
    if (report.inputs.includes("metric")) body.metric = state.params.metric;
    if (report.inputs.includes("fiscal_month") && state.params.from && state.params.to) {
      body.from = state.params.from;
      body.to = state.params.to;
      delete body.fiscal_year_id;
    } else if (report.inputs.includes("fiscal_month") && state.params.month && state.params.fiscal_year_id !== "all") body.month = state.params.month;
    if (report.inputs.includes("gathering_type") && state.params.gathering_type_id) body.gathering_type_id = state.params.gathering_type_id;
    return body;
  }

  // --------------------------------------------------------------- preview

  async function preview() {
    const btn = $("rpToPreview");
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparing preview';

    const res = await api("POST", "/reports/preview", requestBody());
    if (!res.ok) {
      $("rpError").textContent = res.message || "Couldn't build the preview.";
      showStep("error");
      return;
    }
    const report = current();
    $("rpSummaryBar").innerHTML = `
      ${iconTile(report.key, report.icon, "is-sm")}
      <span class="rp-summarybar-text"><b>${esc(res.data.title)}</b><span>${esc(res.data.period_label)} · ${FORMAT_LABEL[state.format]}</span></span>
      <button type="button" class="rp-edit" id="rpEdit"><i class="ri-pencil-line me-1"></i>Change</button>`;
    $("rpEdit").addEventListener("click", () => showStep("choose"));
    $("rpPreview").innerHTML = renderPreview(res.data);
    $("reportModalScope").textContent = res.data.scope_label;
    showStep("preview");
  }

  function renderPreview(d) {
    const tiles = d.tiles.map((t) => `<div class="rp-tile"><span>${esc(t.label)}</span><strong>${esc(t.value)}</strong></div>`).join("");

    const sections = d.sections
      .map((s) => {
        const align = (i) => (s.columns[i]?.align === "R" ? "text-end" : "");
        const head = s.columns.map((c, i) => `<th class="${align(i)}">${esc(c.header)}</th>`).join("");
        const rows = s.rows.map((r) => `<tr>${r.map((v, i) => `<td class="${align(i)}">${esc(v)}</td>`).join("")}</tr>`).join("");
        const totals = s.has_totals ? `<tr class="rp-totals">${s.totals.map((v, i) => `<td class="${align(i)}">${i > 0 && v === "-" ? "" : esc(v)}</td>`).join("")}</tr>` : "";
        const more = s.row_count > s.rows.length ? `<div class="rp-more">+ ${s.row_count - s.rows.length} more row${s.row_count - s.rows.length === 1 ? "" : "s"} in the file</div>` : "";
        return `
          <div class="rp-section">
            <div class="rp-section-head">
              <strong>${esc(s.heading)}</strong>
              <span class="soft-chip soft-primary">${s.row_count} row${s.row_count === 1 ? "" : "s"}</span>
              <span class="soft-chip soft-${s.has_totals ? "success" : "secondary"}">${s.has_totals ? "With totals" : "No totals"}</span>
            </div>
            ${s.row_count ? `<div class="table-responsive"><table class="table table-sm rp-table mb-0"><thead><tr>${head}</tr></thead><tbody>${rows}${totals}</tbody></table></div>${more}` : '<div class="rp-more">Nothing to show for this period.</div>'}
          </div>`;
      })
      .join("");

    const recs = d.insights.filter((i) => i.recommendation).length;
    const insights = d.insights.length
      ? `
        <div class="rp-section">
          <div class="rp-section-head">
            <strong>What we noticed</strong>
            <span class="soft-chip soft-primary">${d.insights.length} insight${d.insights.length === 1 ? "" : "s"}</span>
            ${recs ? `<span class="soft-chip soft-purple">${recs} recommendation${recs === 1 ? "" : "s"}</span>` : ""}
          </div>
          <ul class="rp-insights">${d.insights
            .map((i) => {
              const t = TONE[i.tone] || TONE.watch;
              return `<li class="is-${i.tone}"><i class="${t.icon}"></i><span><b>${esc(i.title)}</b><small>${esc(t.label)}</small></span></li>`;
            })
            .join("")}</ul>
        </div>`
      : `<div class="rp-note"><i class="ri-information-line"></i>No insights for this period, so the file won't have an insights part.</div>`;

    return `
      ${tiles ? `<div class="rp-tiles">${tiles}</div>` : ""}
      ${sections}
      ${insights}
      <div class="rp-note"><i class="${state.format === "pdf" ? "ri-qr-code-line" : "ri-file-excel-2-line"}"></i>${
        state.format === "pdf"
          ? "One orientation for the whole report - landscape when a table needs the width - and a QR code on every page that proves it's genuine."
          : "A Summary sheet, one sheet per table (totals as real formulas) and an Insights sheet."
      }</div>`;
  }

  // --------------------------------------------------------------- generate

  async function generate() {
    const btn = $("rpGenerate") || $("rpRetry");
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Starting';
    }
    const res = await api("POST", "/reports", { ...requestBody(), format: state.format });
    if (!res.ok) {
      $("rpError").textContent = res.message || "Couldn't start the report.";
      showStep("error");
      return;
    }
    state.run = res.data;
    state.queuedSince = Date.now();
    state.shownStage = 0;
    state.targetStage = 0;
    state.finished = null;
    renderStages();
    setRing(0);
    $("rpWorkingTitle").textContent = `Building ${res.data.title || "your report"}`;
    setActive(true);
    showStep("working");
    pollRun();
    refreshTray();
  }

  function stageIndex(run) {
    if (run.status === "ready") return STAGES.length - 1;
    if (run.status === "queued") return 0;
    let index = 1;
    STAGES.forEach((s, i) => {
      if (i > 0 && i < STAGES.length - 1 && (run.progress || 0) >= s.at) index = i;
    });
    return index;
  }

  function renderStages() {
    const waiting = state.shownStage === 0 && Date.now() - state.queuedSince > 20000;
    $("rpStages").innerHTML = STAGES.map((s, i) => {
      const label = i === 0 && waiting ? "Waiting for the report worker…" : state.format === "xlsx" && s.xlsx ? s.xlsx : s.label;
      const cls = i < state.shownStage || (i === state.shownStage && i === STAGES.length - 1) ? "is-done" : i === state.shownStage ? "is-active" : "";
      const icon = cls === "is-done" ? "ri-checkbox-circle-fill" : cls === "is-active" ? "ri-loader-4-line" : "ri-checkbox-blank-circle-line";
      return `<li class="${cls}"><i class="${icon}"></i>${label}</li>`;
    }).join("");
  }

  function setRing(pct) {
    const bar = $("rpRingBar");
    bar.style.strokeDasharray = RING;
    bar.style.strokeDashoffset = RING - (RING * pct) / 100;
    $("rpPct").textContent = `${pct}%`;
  }

  /**
   * Walks the checklist one stage at a time towards the run's real stage,
   * so a build that finishes in under a second still shows each step.
   */
  function advanceStages() {
    if (state.stageTimer) return;
    const step = () => {
      if (state.shownStage < state.targetStage) {
        state.shownStage += 1;
        renderStages();
        setRing(STAGES[state.shownStage].at);
        state.stageTimer = setTimeout(step, STAGE_DWELL);
        return;
      }
      state.stageTimer = null;
      if (state.finished && state.shownStage === STAGES.length - 1) {
        const run = state.finished;
        state.finished = null;
        setTimeout(() => showDone(run), 350);
      }
    };
    state.stageTimer = setTimeout(step, STAGE_DWELL);
  }

  function pollRun() {
    clearTimeout(state.pollTimer);
    if (!state.run) return;
    state.pollTimer = setTimeout(async () => {
      const res = await api("GET", `/reports/runs/${state.run.uuid}`);
      if (!res.ok) return pollRun();
      state.run = res.data;
      if (res.data.status === "failed") {
        $("rpError").textContent = res.data.error || "The report couldn't be built.";
        showStep("error");
        refreshTray();
        return;
      }
      state.targetStage = Math.max(state.targetStage, stageIndex(res.data));
      if (res.data.status === "ready") state.finished = res.data;
      else pollRun();
      if (state.shownStage === 0) renderStages(); // refresh the "waiting for the worker" hint
      advanceStages();
    }, state.run.status === "queued" ? 900 : 500);
  }

  function showDone(run) {
    state.lastRuns[run.uuid] = "ready"; // the modal announced it; the tray shouldn't toast it too
    $("rpDoneText").textContent = `${run.title} · ${run.period_label || ""}`;
    $("rpFile").innerHTML = fileCard(run);
    showStep("done");
    refreshTray();
  }

  function fileCard(run) {
    const pdf = run.format === "pdf";
    return `
      <div class="rp-file-card">
        <span class="rp-tile-icon bg-${pdf ? "danger" : "success"} text-white"><i class="${pdf ? "ri-file-pdf-line" : "ri-file-excel-2-line"}"></i></span>
        <span class="rp-file-text">
          <strong>${esc(run.file_name)}</strong>
          <small>${FORMAT_LABEL[run.format]} · ${size(run.file_size)}${run.verification_code ? ` · Code <b class="rp-code">${esc(run.verification_code)}</b>` : ""}</small>
          <span class="rp-progress rp-progress-sm" id="rpDlBar" hidden><i style="width: 0%"></i></span>
        </span>
      </div>`;
  }

  function size(bytes) {
    if (!bytes) return "";
    return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }

  // ==========================================================================
  // HEADER MONITOR
  // ==========================================================================

  function setActive(on) {
    try {
      if (on) localStorage.setItem(ACTIVE_FLAG, "1");
      else localStorage.removeItem(ACTIVE_FLAG);
    } catch (e) {
      /* private mode - the tray just won't poll across pages */
    }
  }

  function isActiveFlag() {
    try {
      return localStorage.getItem(ACTIVE_FLAG) === "1";
    } catch (e) {
      return false;
    }
  }

  const STATUS = {
    queued: { cls: "secondary", label: "Queued" },
    running: { cls: "primary", label: "Building" },
    ready: { cls: "success", label: "Ready" },
    failed: { cls: "danger", label: "Failed" },
  };

  async function refreshTray() {
    clearTimeout(state.trayTimer);
    const list = $("reportTrayList");
    if (!list) return;
    const res = await api("GET", "/reports/runs");
    if (!res.ok) return;
    const runs = res.data || [];
    // Pages that list runs (the Reports page) re-render from this.
    document.dispatchEvent(new CustomEvent("reports:changed", { detail: runs }));

    // Announce runs that finished since the last look (other pages included).
    runs.forEach((r) => {
      const before = state.lastRuns[r.uuid];
      if (before && before !== "ready" && r.status === "ready") toast("success", `${r.title} is ready - open Reports at the top to download it.`);
      if (before && before !== "failed" && r.status === "failed") toast("error", `${r.title} couldn't be built.`);
      state.lastRuns[r.uuid] = r.status;
    });

    const active = runs.filter((r) => r.status === "queued" || r.status === "running").length;
    const badge = $("reportTrayBadge");
    badge.hidden = active === 0;
    badge.textContent = active;
    setActive(active > 0);

    list.innerHTML = runs.length
      ? runs
          .slice(0, 8)
          .map((r) => {
            const s = STATUS[r.status] || STATUS.queued;
            const pdf = r.format === "pdf";
            const building = r.status === "running" || r.status === "queued";
            const actions =
              r.status === "ready" && !r.expired
                ? `<button type="button" class="report-tray-btn" data-tray-download="${r.uuid}" title="Download"><i class="ri-download-2-line"></i></button>`
                : "";
            return `
              <div class="report-tray-item">
                <span class="rp-tile-icon is-sm bg-${pdf ? "danger" : "success"} text-white"><i class="${pdf ? "ri-file-pdf-line" : "ri-file-excel-2-line"}"></i></span>
                <span class="report-tray-text">
                  <strong>${esc(r.title || "Report")}</strong>
                  <small>${esc(r.period_label || r.scope_label || "")}</small>
                  ${building ? `<span class="rp-progress rp-progress-sm"><i style="width: ${Math.max(4, r.progress)}%"></i></span>` : ""}
                </span>
                <span class="badge bg-${s.cls}${s.cls === "secondary" ? " text-dark" : ""}">${r.expired ? "Expired" : s.label}</span>
                ${actions}
              </div>`;
          })
          .join("")
      : '<div class="report-tray-empty">Reports you generate show up here.</div>';

    list.querySelectorAll("[data-tray-download]").forEach((btn) =>
      btn.addEventListener("click", () => download(runs.find((r) => r.uuid === btn.dataset.trayDownload), btn, { compact: true })),
    );

    if (active > 0) state.trayTimer = setTimeout(refreshTray, 2500);
  }

  function init() {
    if (!$("reportModal")) return;
    document.querySelectorAll('#reportModal input[name="rp_format"]').forEach((r) => r.addEventListener("change", () => setFormat(r.value)));
    $("reportModal").addEventListener("hidden.bs.modal", () => {
      clearTimeout(state.pollTimer);
      if (state.run && (state.run.status === "queued" || state.run.status === "running")) refreshTray();
    });
    $("reportTrayToggle")?.addEventListener("show.bs.dropdown", refreshTray);
    if (isActiveFlag()) refreshTray();

    // Any element with data-report-key opens the modal - pages only add markup.
    document.addEventListener("click", (e) => {
      const trigger = e.target.closest("[data-report-key]");
      if (!trigger) return;
      e.preventDefault();
      const params = {};
      ["fiscal_year_id", "year", "month", "from", "to", "years", "demographic_id", "submission_label", "metric", "gathering_type_id", "budget_id", "line_id", "activity_id", "report_id"].forEach((k) => {
        const v = trigger.dataset[k.replace(/_([a-z])/g, (_, c) => c.toUpperCase())];
        if (v) params[k] = /^\d+$/.test(v) ? Number(v) : v;
      });
      const territoryId = trigger.dataset.territoryId || (typeof USER_TERRITORY !== "undefined" ? USER_TERRITORY.id : null);
      open({ territoryId, reportKey: trigger.dataset.reportKey, module: trigger.dataset.module, params, locked: trigger.dataset.lock === "1", title: trigger.dataset.reportTitle });
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();

  return { open, refreshTray, download };
})();

window.ReportCenter = ReportCenter;
