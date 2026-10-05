/**
 * ============================================================================
 * CALENDAR - the CCI national calendar tab (diocese, global admins only)
 * ============================================================================
 * The year's CCI events in a table, with Add / Edit / Delete, a template and
 * Import: upload the year's calendar (CSV or Excel), see every row checked
 * - ready, a problem, or already on the calendar - then add the ready ones.
 * Nothing is saved until "Add" is pressed. docs/specs/calendar-spec.md.
 * ============================================================================
 */
const CalendarCci = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => CalendarEventModal.esc(s);

  let host = null;
  let kinds = {};
  let onChanged = null;
  let year = new Date().getFullYear();
  let events = [];

  const fmt = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
  const dates = (e) => (e.ends_on && e.ends_on !== e.starts_on ? `${fmt(e.starts_on)} - ${fmt(e.ends_on)}` : fmt(e.starts_on));

  function draw() {
    const thisYear = new Date().getFullYear();
    host.innerHTML = `
      <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
          <div>
            <div class="card-title">CCI national calendar ${year}</div>
            <span class="card-subtitle-text">Christian Church International's events - every church, region and the diocese sees them, marked as CCI calendar events</span>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            ${UI.renderSegmented("cciYear", [thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: y, label: String(y) })), year, { ariaLabel: "Year" })}
            <button type="button" class="btn btn-light border" id="cciTemplate"><i class="ri-file-download-line me-1"></i>Template</button>
            <button type="button" class="btn btn-primary" id="cciImport"><i class="ri-upload-cloud-2-line me-1"></i>Import</button>
            <button type="button" class="btn btn-danger" id="cciAdd"><i class="ri-add-line me-1"></i>Add CCI event</button>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0" id="cciTable">
              <thead><tr><th>Dates</th><th class="all">Event</th><th>Time</th><th>Where</th><th>Repeats</th><th>Added by</th><th class="text-end all"></th></tr></thead>
              <tbody>${
                events.length
                  ? events
                      .map(
                        (e) => `
                <tr data-row-id="${e.id}">
                  <td class="text-nowrap" data-order="${e.starts_on}">${esc(dates(e))}</td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <span class="avatar avatar-sm bg-danger text-white flex-shrink-0"><i class="${CalendarMeta.KIND_ICONS[e.kind] || "ri-calendar-line"}"></i></span>
                      <div style="min-width:0"><div class="fw-semibold">${esc(e.title)}</div><div class="fs-12">${esc(kinds[e.kind] || e.kind)}</div></div>
                    </div>
                  </td>
                  <td class="text-nowrap">${e.all_day ? "All day" : `${esc(e.start_time)}${e.end_time ? ` - ${esc(e.end_time)}` : ""}`}</td>
                  <td>${esc(e.location || "-")}</td>
                  <td>${e.repeats === "none" ? "-" : esc(CalendarMeta.REPEATS[e.repeats])}</td>
                  <td><span class="soft-chip soft-${e.source === "import" ? "purple" : "primary"}">${e.source === "import" ? "Imported" : "Added"}</span></td>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-icon btn-sm btn-primary" data-edit="${e.id}" title="Edit" aria-label="Edit ${esc(e.title)}"><i class="ri-edit-line"></i></button>
                    <button type="button" class="btn btn-icon btn-sm btn-danger" data-delete="${e.id}" title="Delete" aria-label="Delete ${esc(e.title)}"><i class="ri-delete-bin-6-line"></i></button>
                  </td>
                </tr>`,
                      )
                      .join("")
                  : UI.renderTableEmpty(7, `Nothing on the CCI calendar for ${year} yet - add an event or import the year's calendar`, "ri-government-line")
              }</tbody>
            </table>
          </div>
        </div>
      </div>`;
    if (events.length) UI.initListDataTable("cciTable", { order: [[0, "asc"]], nonSortableColumns: [6], noun: "events", pageLength: 25, searchPlaceholder: "Search the CCI calendar…" });
    UI.wireSegmented("cciYear", (y) => {
      year = Number(y);
      reload();
    });
    host.querySelector("#cciTemplate").addEventListener("click", async () => {
      if (!(await CalendarAPI.template())) Toast.error("The template couldn't be downloaded. Please try again.");
    });
    host.querySelector("#cciImport").addEventListener("click", openImport);
    host.querySelector("#cciAdd").addEventListener("click", () => CalendarEventModal.form(null, { kinds, level: "diocese", cci: true, date: `${year}-01-01` > new Date().toISOString().slice(0, 10) ? `${year}-01-01` : null, onSaved: changed }));
    host.querySelector("#cciTable").addEventListener("click", (ev) => {
      const edit = ev.target.closest("[data-edit]");
      const del = ev.target.closest("[data-delete]");
      const e = events.find((x) => String(x.id) === (edit || del)?.dataset[edit ? "edit" : "delete"]);
      if (!e) return;
      if (edit) CalendarEventModal.form({ ...e }, { kinds, level: "diocese", cci: true, onSaved: changed });
      if (del)
        Toast.confirm(
          `Delete "${esc(e.title)}" from the CCI calendar? Every church stops seeing it.`,
          async () => {
            const res = await CalendarAPI.remove(e.id);
            if (!res.ok) return Toast.error(res.message);
            Toast.success("Deleted from the CCI calendar.");
            changed();
          },
          null,
          { title: "Delete CCI event", confirmText: "Delete", cancelText: "Keep it", type: "error" },
        );
    });
    const fig = document.querySelector('[data-tab-figure="cci"]');
    if (fig) fig.textContent = `${events.length} event${events.length === 1 ? "" : "s"} in ${year}`;
  }

  function changed() {
    onChanged?.();
  }

  // ----------------------------------------------------------------- import

  function importModal() {
    let el = document.getElementById("cciImportModal");
    if (el) return el;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="cciImportModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="cciImportTitle">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon bg-danger"><i class="ri-upload-cloud-2-line"></i></span>
              <div class="flex-fill"><h5 class="modal-title" id="cciImportTitle">Import the CCI calendar</h5><div class="app-modal-subtitle">From a CSV or Excel file - every row is checked before anything is saved</div></div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="cciImportBody"></div>
            <div class="modal-footer" id="cciImportFoot"></div>
          </div>
        </div>
      </div>`,
    );
    return document.getElementById("cciImportModal");
  }

  function openImport() {
    const el = importModal();
    let file = null;
    el.querySelector("#cciImportBody").innerHTML = `
      <div class="cal-panel">
        <div class="cal-panel-head"><span class="avatar avatar-sm bg-primary text-white"><i class="ri-file-excel-2-line"></i></span><span class="fw-semibold">Choose the file</span></div>
        <input type="file" class="form-control" id="cciFile" accept=".csv,.xlsx,.xls">
        <div class="invalid-feedback d-block" id="cciFileError"></div>
        <div class="fs-13 mt-2">Use the template's columns: <b>title, kind, starts_on</b> are needed; ends_on, all_day, start_time, end_time, location, description, repeats and repeat_until are optional. Dates as 2026-08-14 or 14/08/2026. Kinds: ${Object.values(kinds).map(esc).join(", ")}.
          <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="cciImportTemplate">Download the template</button></div>
      </div>
      <div id="cciCheck" class="mt-3"></div>`;
    el.querySelector("#cciImportFoot").innerHTML = '<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="cciCheckBtn" disabled><i class="ri-search-eye-line me-1"></i>Check the file</button><button type="button" class="btn btn-danger" id="cciCommitBtn" hidden><i class="ri-check-line me-1"></i>Add</button>';
    bootstrap.Modal.getOrCreateInstance(el).show();
    const $ = (s) => el.querySelector(s);
    $("#cciImportTemplate").addEventListener("click", () => CalendarAPI.template());
    $("#cciFile").addEventListener("change", () => {
      file = $("#cciFile").files[0] || null;
      $("#cciCheckBtn").disabled = !file;
      $("#cciCommitBtn").hidden = true;
      $("#cciCheck").innerHTML = "";
      $("#cciFileError").textContent = "";
    });
    $("#cciCheckBtn").addEventListener("click", async (ev) => {
      const btn = ev.currentTarget;
      UI.setButtonLoading(btn, "Checking…");
      const res = await CalendarAPI.importCci(file, false);
      UI.restoreButton(btn);
      if (!res.ok) {
        $("#cciFileError").textContent = res.message;
        return;
      }
      drawCheck(el, res.data);
      const commit = $("#cciCommitBtn");
      commit.hidden = res.data.valid === 0;
      commit.innerHTML = `<i class="ri-check-line me-1"></i>Add ${res.data.valid} event${res.data.valid === 1 ? "" : "s"} to the CCI calendar`;
    });
    $("#cciCommitBtn").addEventListener("click", async (ev) => {
      const btn = ev.currentTarget;
      UI.setButtonLoading(btn, "Adding…");
      const res = await CalendarAPI.importCci(file, true);
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message);
      changed();
    });
  }

  function drawCheck(el, d) {
    const status = (r) =>
      r.errors.length
        ? `<span class="badge bg-danger">Problem</span><ul class="mb-0 mt-1 ps-3 fs-12">${r.errors.map((x) => `<li>${esc(x)}</li>`).join("")}</ul>`
        : r.duplicate
          ? '<span class="badge bg-secondary text-dark">Already on the calendar</span>'
          : '<span class="badge bg-success">Ready</span>';
    el.querySelector("#cciCheck").innerHTML = `
      <div class="d-flex flex-wrap gap-2 mb-2">
        <span class="soft-chip soft-success"><i class="ri-check-line me-1"></i>${d.valid} ready</span>
        <span class="soft-chip soft-danger"><i class="ri-error-warning-line me-1"></i>${d.invalid} with a problem</span>
        <span class="soft-chip soft-secondary"><i class="ri-file-copy-line me-1"></i>${d.duplicates} already there</span>
      </div>
      ${d.invalid ? '<div class="alert alert-warning py-2 fs-13">Rows with a problem are skipped. Fix them in the file and import it again - rows already added are never doubled.</div>' : ""}
      <div class="table-responsive cci-check-table">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Row</th><th>Event</th><th>Kind</th><th>Dates</th><th>Status</th></tr></thead>
          <tbody>${d.rows
            .map(
              (r) => `<tr class="${r.errors.length ? "cci-row-bad" : ""}">
                <td>${r.line}</td>
                <td class="fw-semibold">${esc(r.values.title || "(no title)")}</td>
                <td>${esc(kinds[r.values.kind] || r.values.kind || "-")}</td>
                <td class="text-nowrap">${esc(r.values.starts_on || "-")}${r.values.ends_on && r.values.ends_on !== r.values.starts_on ? ` - ${esc(r.values.ends_on)}` : ""}</td>
                <td>${status(r)}</td>
              </tr>`,
            )
            .join("")}</tbody>
        </table>
      </div>`;
  }

  async function reload() {
    const res = await CalendarAPI.cci(year);
    if (!res.ok) {
      host.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
      return;
    }
    events = res.data.events;
    draw();
  }

  return {
    async mount(container, opts) {
      host = container;
      kinds = opts.kinds;
      onChanged = () => {
        reload();
        opts.onChanged?.();
      };
      host.innerHTML = `<div class="row">${UI.skeletonCards(1, "col-12")}</div>`;
      await reload();
    },
    reload,
  };
})();

window.CalendarCci = CalendarCci;
