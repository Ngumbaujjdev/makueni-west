/**
 * ============================================================================
 * REPORT CENTER - export modal + header monitor (every page)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * The frontend for docs/specs/reports-spec.md, used by any module:
 *
 *   ReportCenter.open({ territoryId, reportKey, params: { fiscal_year_id, years, demographic_id } })
 *
 * Modal (includes/report-modal.php): choose (report, period, PDF/Excel) ->
 * preview (what will be in the file: tiles, each table's rows and whether it
 * has totals, the insights) -> working (a queued run, polled for its stage
 * and progress) -> done (download / open) | error (retry).
 *
 * Monitor (the Reports icon in includes/header.php): recent runs with live
 * status. It only polls while a run is queued or running, and it keeps
 * polling on other pages (a localStorage flag), so a report started here
 * shows up as ready wherever you are.
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
  const TONE = {
    good: { cls: "success", label: "Going well", icon: "ri-checkbox-circle-line" },
    watch: { cls: "secondary", label: "Keep an eye on", icon: "ri-eye-line" },
    concern: { cls: "danger", label: "Needs attention", icon: "ri-error-warning-line" },
  };

  const state = {
    territoryId: null,
    catalogue: null,
    catalogueFor: null,
    years: null,
    reportKey: null,
    params: {},
    format: "pdf",
    run: null,
    queuedSince: null,
    pollTimer: null,
    trayTimer: null,
    lastRuns: {},
  };

  const $ = (id) => document.getElementById(id);
  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  // ==========================================================================
  // API
  // ==========================================================================

  function headers(json = true) {
    const h = { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
    if (json) h["Content-Type"] = "application/json";
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

  async function fetchFile(run) {
    const res = await fetch(`${AppConfig.API_BASE_URL}/reports/runs/${run.uuid}/download`, { headers: headers(false) });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.message || "Couldn't download the file.");
    }
    return res.blob();
  }

  async function download(run) {
    try {
      const blob = await fetchFile(run);
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = run.file_name || `report.${run.format}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
    } catch (e) {
      toast("error", e.message);
    }
  }

  async function openPdf(run) {
    // Open the tab first (a popup opened after an await gets blocked).
    const tab = window.open("", "_blank");
    try {
      const blob = await fetchFile(run);
      const url = URL.createObjectURL(new Blob([blob], { type: "application/pdf" }));
      if (tab) tab.location.href = url;
      else window.location.href = url;
    } catch (e) {
      if (tab) tab.close();
      toast("error", e.message);
    }
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
    const footer = $("rpFooter");
    const buttons = {
      choose: `
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="rpToPreview"><i class="ri-eye-line me-1"></i>Preview</button>`,
      preview: `
        <button type="button" class="btn btn-light me-auto" id="rpBack"><i class="ri-arrow-left-line me-1"></i>Back</button>
        <button type="button" class="btn btn-success" id="rpGenerate"><i class="ri-file-download-line me-1"></i>Generate ${FORMAT_LABEL[state.format]}</button>`,
      working: `<button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep working</button>`,
      done: `
        <button type="button" class="btn btn-light me-auto" id="rpAnother"><i class="ri-add-line me-1"></i>Make another</button>
        ${state.run?.format === "pdf" ? '<button type="button" class="btn btn-outline-primary" id="rpOpen"><i class="ri-external-link-line me-1"></i>Open</button>' : ""}
        <button type="button" class="btn btn-primary" id="rpDownload"><i class="ri-download-2-line me-1"></i>Download</button>`,
      error: `
        <button type="button" class="btn btn-light me-auto" id="rpBack"><i class="ri-arrow-left-line me-1"></i>Back</button>
        <button type="button" class="btn btn-primary" id="rpRetry"><i class="ri-refresh-line me-1"></i>Try again</button>`,
    };
    footer.innerHTML = buttons[step];
    $("rpToPreview")?.addEventListener("click", preview);
    $("rpBack")?.addEventListener("click", () => showStep("choose"));
    $("rpGenerate")?.addEventListener("click", generate);
    $("rpRetry")?.addEventListener("click", generate);
    $("rpAnother")?.addEventListener("click", () => showStep("choose"));
    $("rpDownload")?.addEventListener("click", () => download(state.run));
    $("rpOpen")?.addEventListener("click", () => openPdf(state.run));
  }

  /**
   * Opens the export modal.
   * @param {object} opts {territoryId, reportKey, params: {fiscal_year_id, years, demographic_id}}
   */
  async function open(opts = {}) {
    state.territoryId = opts.territoryId;
    state.reportKey = opts.reportKey || null;
    state.params = { ...(opts.params || {}) };
    state.format = opts.format || "pdf";
    document.querySelectorAll('#reportModal input[name="rp_format"]').forEach((r) => {
      r.checked = r.value === state.format;
    });

    $("rpReports").innerHTML = '<span class="skel" style="height: 4.2rem;"></span><span class="skel" style="height: 4.2rem;"></span>';
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
    if (!state.reportKey || !state.catalogue.some((r) => r.key === state.reportKey)) state.reportKey = state.catalogue[0]?.key;
    renderChoose();
  }

  function current() {
    return state.catalogue.find((r) => r.key === state.reportKey);
  }

  function renderChoose() {
    $("rpReports").innerHTML = state.catalogue
      .map((r) => {
        const needsSubmission = r.inputs.includes("submission") && !state.params.demographic_id;
        return `
          <label class="rp-report${needsSubmission ? " is-disabled" : ""}" title="${needsSubmission ? "Open a submission to export it" : ""}">
            <input type="radio" name="rp_report" value="${r.key}" ${r.key === state.reportKey ? "checked" : ""} ${needsSubmission ? "disabled" : ""}>
            <span class="rp-report-icon"><i class="${esc(r.icon)}"></i></span>
            <span class="rp-report-text"><strong>${esc(r.title)}</strong><small>${needsSubmission ? "Open a submission, then export it from there." : esc(r.description)}</small></span>
            <i class="ri-check-line rp-report-tick"></i>
          </label>`;
      })
      .join("");
    $("rpReports").querySelectorAll('input[name="rp_report"]').forEach((input) =>
      input.addEventListener("change", () => {
        state.reportKey = input.value;
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

    if (report.inputs.includes("fiscal_year")) {
      $("rpPeriodTitle").textContent = "Fiscal year";
      const thisYear = new Date().getFullYear();
      if (!state.params.fiscal_year_id) state.params.fiscal_year_id = (state.years.find((y) => y.year === thisYear) || state.years[state.years.length - 1])?.id;
      wrap.innerHTML = segmented("year", state.years.map((y) => ({ value: y.id, label: y.year })), state.params.fiscal_year_id);
      wrap.querySelectorAll("[data-year]").forEach((btn) =>
        btn.addEventListener("click", () => {
          state.params.fiscal_year_id = Number(btn.dataset.year);
          wrap.querySelectorAll("[data-year]").forEach((b) => b.classList.toggle("active", b === btn));
        }),
      );
    } else if (report.inputs.includes("years")) {
      $("rpPeriodTitle").textContent = "Range";
      state.params.years = state.params.years || "3";
      wrap.innerHTML = segmented("range", [{ value: "1", label: "1 year" }, { value: "3", label: "3 years" }, { value: "5", label: "5 years" }, { value: "all", label: "All" }], state.params.years);
      wrap.querySelectorAll("[data-range]").forEach((btn) =>
        btn.addEventListener("click", () => {
          state.params.years = btn.dataset.range;
          wrap.querySelectorAll("[data-range]").forEach((b) => b.classList.toggle("active", b === btn));
        }),
      );
    } else {
      $("rpPeriodTitle").textContent = "Submission";
      wrap.innerHTML = `<span class="soft-chip soft-primary"><i class="ri-file-list-3-line"></i>${esc(state.params.submission_label || "The submission you're viewing")}</span>`;
    }
  }

  function requestBody() {
    const report = current();
    const body = { report_key: state.reportKey, territory_id: state.territoryId };
    if (report.inputs.includes("fiscal_year")) body.fiscal_year_id = state.params.fiscal_year_id;
    if (report.inputs.includes("years")) body.years = state.params.years;
    if (report.inputs.includes("submission")) body.demographic_id = state.params.demographic_id;
    return body;
  }

  // --------------------------------------------------------------- preview

  async function preview() {
    state.format = document.querySelector('#reportModal input[name="rp_format"]:checked')?.value || "pdf";
    const btn = $("rpToPreview");
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparing preview';

    const res = await api("POST", "/reports/preview", requestBody());
    if (!res.ok) {
      $("rpError").textContent = res.message || "Couldn't build the preview.";
      showStep("error");
      return;
    }
    $("rpPreview").innerHTML = renderPreview(res.data);
    $("reportModalScope").textContent = `${res.data.period_label} · ${res.data.scope_label}`;
    showStep("preview");
  }

  function renderPreview(d) {
    const tiles = d.tiles
      .map((t) => `<div class="rp-tile"><span>${esc(t.label)}</span><strong>${esc(t.value)}</strong></div>`)
      .join("");

    const sections = d.sections
      .map((s) => {
        const head = s.columns.map((c) => `<th class="${c.align === "R" ? "text-end" : ""}">${esc(c.header)}</th>`).join("");
        const rows = s.rows
          .map((r) => `<tr>${r.map((v, i) => `<td class="${s.columns[i]?.align === "R" ? "text-end" : ""}">${esc(v)}</td>`).join("")}</tr>`)
          .join("");
        const totals = s.has_totals
          ? `<tr class="rp-totals">${s.totals.map((v, i) => `<td class="${s.columns[i]?.align === "R" ? "text-end" : ""}">${i > 0 && v === "-" ? "" : esc(v)}</td>`).join("")}</tr>`
          : "";
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
              return `<li><span class="soft-chip soft-${t.cls}"><i class="${t.icon}"></i>${t.label}</span><span>${esc(i.title)}</span></li>`;
            })
            .join("")}</ul>
        </div>`
      : `<div class="rp-more mt-2">This report has no insights for this period, so the file won't have an insights part.</div>`;

    return `
      <div class="rp-preview-head">
        <span class="rp-kicker">${esc(d.kicker)}</span>
        <h5>${esc(d.title)}</h5>
        <span class="rp-scope">${esc(d.period_label)} · ${esc(d.scope_label)}</span>
      </div>
      ${tiles ? `<div class="rp-tiles">${tiles}</div>` : ""}
      ${sections}
      ${insights}
      <div class="rp-format-note"><i class="${state.format === "pdf" ? "ri-qr-code-line" : "ri-file-excel-2-line"}"></i>${
        state.format === "pdf"
          ? "The PDF lays out each table portrait or landscape to fit, and every page carries a QR code that proves it's genuine."
          : "The Excel file has a Summary sheet, one sheet per table (totals as real formulas) and an Insights sheet."
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
    setActive(true);
    showWorking(res.data);
    showStep("working");
    pollRun();
    refreshTray();
  }

  function showWorking(run) {
    const pct = Math.max(4, run.progress || 0);
    $("rpBar").style.width = `${pct}%`;
    $("rpPct").textContent = `${run.progress || 0}%`;
    $("rpWorkingTitle").textContent = `Building ${run.title || "your report"}`;
    const waiting = run.status === "queued" && Date.now() - state.queuedSince > 20000;
    $("rpStage").textContent = waiting ? "Waiting for the report worker to pick this up…" : run.stage || "Working";
  }

  function pollRun() {
    clearTimeout(state.pollTimer);
    if (!state.run) return;
    state.pollTimer = setTimeout(async () => {
      const res = await api("GET", `/reports/runs/${state.run.uuid}`);
      if (!res.ok) return pollRun();
      state.run = res.data;
      if (res.data.status === "ready") return showDone(res.data);
      if (res.data.status === "failed") {
        $("rpError").textContent = res.data.error || "The report couldn't be built.";
        showStep("error");
        refreshTray();
        return;
      }
      showWorking(res.data);
      pollRun();
    }, state.run.status === "queued" ? 1500 : 800);
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
        <span class="rp-format-icon bg-${pdf ? "danger" : "success"} text-white"><i class="${pdf ? "ri-file-pdf-line" : "ri-file-excel-2-line"}"></i></span>
        <span class="rp-file-text">
          <strong>${esc(run.file_name)}</strong>
          <small>${FORMAT_LABEL[run.format]} · ${size(run.file_size)}${run.verification_code ? ` · Code <b>${esc(run.verification_code)}</b>` : ""}</small>
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
            const actions =
              r.status === "ready" && !r.expired
                ? `<button type="button" class="report-tray-btn" data-tray-download="${r.uuid}" title="Download"><i class="ri-download-2-line"></i></button>`
                : "";
            return `
              <div class="report-tray-item">
                <span class="rp-format-icon bg-${pdf ? "danger" : "success"} text-white"><i class="${pdf ? "ri-file-pdf-line" : "ri-file-excel-2-line"}"></i></span>
                <span class="report-tray-text">
                  <strong>${esc(r.title || "Report")}</strong>
                  <small>${esc(r.period_label || r.scope_label || "")}</small>
                  ${
                    r.status === "running" || r.status === "queued"
                      ? `<span class="rp-progress rp-progress-sm"><i style="width: ${Math.max(4, r.progress)}%"></i></span>`
                      : ""
                  }
                </span>
                <span class="badge bg-${s.cls}${s.cls === "secondary" ? " text-dark" : ""}">${r.expired ? "Expired" : s.label}</span>
                ${actions}
              </div>`;
          })
          .join("")
      : '<div class="report-tray-empty">Reports you generate show up here.</div>';

    list.querySelectorAll("[data-tray-download]").forEach((btn) =>
      btn.addEventListener("click", () => download(runs.find((r) => r.uuid === btn.dataset.trayDownload))),
    );

    if (active > 0) state.trayTimer = setTimeout(refreshTray, 2500);
  }

  function init() {
    if (!$("reportModal")) return;
    document.querySelectorAll('#reportModal input[name="rp_format"]').forEach((r) =>
      r.addEventListener("change", () => {
        state.format = r.value;
      }),
    );
    $("reportModal").addEventListener("hidden.bs.modal", () => {
      clearTimeout(state.pollTimer);
      $("reportModalScope").textContent = "PDF or Excel, with insights and recommendations";
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
      ["fiscal_year_id", "years", "demographic_id", "submission_label"].forEach((k) => {
        const v = trigger.dataset[k.replace(/_([a-z])/g, (_, c) => c.toUpperCase())];
        if (v) params[k] = /^\d+$/.test(v) ? Number(v) : v;
      });
      const territoryId = trigger.dataset.territoryId || window.USER_TERRITORY?.id || (typeof USER_TERRITORY !== "undefined" ? USER_TERRITORY.id : null);
      open({ territoryId, reportKey: trigger.dataset.reportKey, params });
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();

  return { open, refreshTray, download };
})();

window.ReportCenter = ReportCenter;
