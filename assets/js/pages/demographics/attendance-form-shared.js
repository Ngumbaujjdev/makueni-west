/**
 * ============================================================================
 * SHARED - ATTENDANCE (entry form, Sunday status, gathering summaries)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Used by every Attendance page (Overview, Sunday Services, Ministries,
 * Special Events):
 *   - openEntryModal(): the one "Record attendance" form. Recent Sundays as
 *     chips (recorded / not recorded), the gathering as a Select2 with icons,
 *     four number tiles, and a live preview comparing the count with last
 *     time and with the usual. Picking a Sunday that's already recorded
 *     switches the form to editing that record, so a week is never counted
 *     twice (the API refuses a second record for the same Sunday too).
 *   - sundayRows(): every Sunday in a range with its record or "missing".
 *   - summarizeGatherings() + renderGatheringCards(): one card per ministry
 *     or event with times met, average, last met and a status.
 *   - renderListRows() / renderGatheringStats(): the gatherings table + KPIs.
 *
 * Attendance is counted in four groups (Adults, Youth, Boys, Girls) - the
 * same four everywhere, in the same colours (GROUPS).
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, Bootstrap 5,
 * jQuery + Select2 (optional - falls back to a plain select)
 * ============================================================================
 */

const AttendanceFormShared = (function () {
  "use strict";

  const MODAL_ID = "attendanceEntryModal";
  const OTHER_VALUE = "__other__";
  /** A gathering that hasn't met in this many days reads as "Quiet". */
  const QUIET_DAYS = 60;

  /** The four counts, in order, with their colour and field. */
  const GROUPS = [
    { key: "adults_count", id: "attendanceAdults", label: "Adults", one: "adult", many: "adults", icon: "ri-user-line", color: "primary" },
    { key: "youth_count", id: "attendanceYouth", label: "Youth", one: "youth", many: "youth", icon: "ri-user-star-line", color: "success" },
    { key: "children_male_count", id: "attendanceChildrenMale", label: "Boys", one: "boy", many: "boys", icon: "ri-men-line", color: "purple" },
    { key: "children_female_count", id: "attendanceChildrenFemale", label: "Girls", one: "girl", many: "girls", icon: "ri-women-line", color: "pink" },
  ];

  let currentConfig = null;
  let currentRecordId = null;
  let selectedDate = "";
  let loadedTypes = [];

  // ==========================================================================
  // SMALL HELPERS
  // ==========================================================================

  function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return "";
    return unsafe
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function recordTotal(row) {
    return GROUPS.reduce((acc, g) => acc + (Number(row?.[g.key]) || 0), 0);
  }

  /** "2026-09-14" for a Date, in local time (toISOString would shift a day in UTC+3). */
  function isoDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  }

  function parseIso(iso) {
    return new Date(`${String(iso).substring(0, 10)}T00:00:00`);
  }

  function recordIso(row) {
    return String(row.service_date).substring(0, 10);
  }

  function formatDate(iso, opts = { day: "numeric", month: "short", year: "numeric" }) {
    if (!iso) return "";
    const d = parseIso(iso);
    return isNaN(d) ? "" : d.toLocaleDateString("en-GB", opts);
  }

  const longDate = (iso) => formatDate(iso, { weekday: "long", day: "numeric", month: "short", year: "numeric" });
  const shortDate = (iso) => formatDate(iso, { weekday: "short", day: "numeric", month: "short" });

  function todayIso() {
    return isoDate(new Date());
  }

  /** The most recent Sunday on or before `date` (today, if today is a Sunday). */
  function sundayOnOrBefore(date = new Date()) {
    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    d.setDate(d.getDate() - d.getDay());
    return d;
  }

  /** Sunday dates (ISO) from `start` to `end`, oldest first. */
  function sundaysBetween(start, end) {
    const out = [];
    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate());
    d.setDate(d.getDate() + ((7 - d.getDay()) % 7));
    for (; d <= end; d.setDate(d.getDate() + 7)) out.push(isoDate(d));
    return out;
  }

  /** Which gathering a record belongs to: its configured type, or the name typed for a one-off. */
  function gatheringKey(row) {
    return row.gathering_type_id ? `t${row.gathering_type_id}` : `n${String(row.event_name || "").trim().toLowerCase()}`;
  }

  function gatheringName(row) {
    return row.gathering_type?.name || row.event_name || "Sunday service";
  }

  function daysSince(iso) {
    return Math.floor((parseIso(todayIso()) - parseIso(iso)) / 86400000);
  }

  /** {label, color} for a gathering from the date it last met. */
  function gatheringStatus(lastIso) {
    if (!lastIso) return { key: "never", label: "Not held yet", color: "secondary" };
    return daysSince(lastIso) > QUIET_DAYS ? { key: "quiet", label: `Quiet ${QUIET_DAYS}+ days`, color: "danger" } : { key: "active", label: "Active", color: "success" };
  }

  // ==========================================================================
  // SUNDAYS
  // ==========================================================================

  /**
   * One entry per Sunday from `start` to `end` (newest first): its record,
   * or status "missing" (past, not recorded) / "upcoming" (in the future).
   * @param {object[]} sundayRecords - Sunday service records
   */
  function sundayRows(sundayRecords, start, end) {
    const byDate = new Map(sundayRecords.map((r) => [recordIso(r), r]));
    const today = todayIso();
    return sundaysBetween(start, end)
      .reverse()
      .map((iso) => {
        const record = byDate.get(iso) || null;
        const status = record ? "recorded" : iso > today ? "upcoming" : "missing";
        return { iso, record, status };
      });
  }

  /** The newest Sunday up to today that hasn't been recorded, looking back `weeks` weeks. */
  function nextMissingSunday(sundayRecords, weeks = 8) {
    const end = sundayOnOrBefore();
    const start = new Date(end);
    start.setDate(start.getDate() - (weeks - 1) * 7);
    const earliest = sundayRecords.length ? sundayRecords.map(recordIso).sort()[0] : null;
    return sundayRows(sundayRecords, start, end).find((s) => s.status === "missing" && (!earliest || s.iso >= earliest))?.iso || null;
  }

  const SUNDAY_STATUS = {
    recorded: { label: "Recorded", color: "success", icon: "ri-checkbox-circle-fill" },
    missing: { label: "Not recorded", color: "danger", icon: "ri-error-warning-fill" },
    upcoming: { label: "Upcoming", color: "secondary", icon: "ri-time-line" },
  };

  /**
   * Sunday chips (reuses the intake page's .period-chip look): recorded ones
   * show their total, missing ones say so, upcoming ones are disabled.
   * @param {object[]} rows - sundayRows() output
   * @param {object} opts {selected: iso, showToday: bool, allowRecorded: bool}
   */
  function sundayChipsHtml(rows, { selected = "", allowUpcoming = false } = {}) {
    const today = todayIso();
    return rows
      .map((s) => {
        const meta = SUNDAY_STATUS[s.status];
        const text = s.status === "recorded" ? `${recordTotal(s.record).toLocaleString()} attended` : meta.label;
        const disabled = s.status === "upcoming" && !allowUpcoming;
        return `
          <button type="button" class="period-chip att-sunday-chip is-${s.status}${s.iso === selected ? " is-selected" : ""}" data-sunday="${s.iso}" ${disabled ? "disabled" : ""}>
            ${s.iso === today ? '<span class="period-chip-now">Today</span>' : ""}
            <span class="period-chip-name">${shortDate(s.iso)}</span>
            <span class="period-chip-status"><i class="${meta.icon}"></i>${text}</span>
          </button>`;
      })
      .join("");
  }

  // ==========================================================================
  // GATHERING SUMMARIES (Ministries / Special Events)
  // ==========================================================================

  /**
   * One summary per gathering: every configured type (even ones never held)
   * plus any one-off named events. Sorted: most met first, then by name.
   * @param {object[]} records - this category's records
   * @param {object[]} types - this category's configured gathering types
   */
  function summarizeGatherings(records, types = []) {
    const year = new Date().getFullYear();
    const map = new Map();
    types
      .filter((t) => t.is_active !== false)
      .forEach((t) => map.set(`t${t.id}`, { key: `t${t.id}`, name: t.name, icon: t.icon || "ri-calendar-event-line", rows: [] }));
    records.forEach((r) => {
      const key = gatheringKey(r);
      if (!map.has(key)) map.set(key, { key, name: gatheringName(r), icon: r.gathering_type?.icon || r.gathering_category?.icon || "ri-calendar-event-line", rows: [] });
      map.get(key).rows.push(r);
    });

    return [...map.values()]
      .map((g) => {
        const rows = [...g.rows].sort((a, b) => recordIso(b).localeCompare(recordIso(a)));
        const thisYear = rows.filter((r) => parseIso(recordIso(r)).getFullYear() === year);
        const totals = rows.map(recordTotal);
        const last = rows[0] ? recordIso(rows[0]) : null;
        return {
          ...g,
          rows,
          color: DemographicsUI.colorFor(g.name),
          times: rows.length,
          timesThisYear: thisYear.length,
          average: rows.length ? Math.round(totals.reduce((a, b) => a + b, 0) / rows.length) : 0,
          peak: totals.length ? Math.max(...totals) : 0,
          last,
          lastTotal: rows[0] ? recordTotal(rows[0]) : null,
          status: gatheringStatus(last),
          series: DemographicsUI.monthlySeries(rows, { value: recordTotal }),
        };
      })
      .sort((a, b) => b.timesThisYear - a.timesThisYear || b.times - a.times || a.name.localeCompare(b.name));
  }

  /**
   * A grid of gathering cards. Clicking one calls onPick(summary) - the page
   * filters its table to that gathering.
   * @param {string} containerId
   * @param {object[]} summaries - summarizeGatherings() output
   * @param {object} opts {onPick, active: name, limit: 8, noun: "ministry"}
   */
  function renderGatheringCards(containerId, summaries, { onPick = null, active = "", limit = 8, noun = "ministry" } = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (!summaries.length) {
      container.innerHTML = `
        <div class="col-12">
          <div class="list-empty">
            <span class="list-empty-icon bg-primary text-white"><i class="ri-calendar-event-line"></i></span>
            <div class="fw-semibold mt-2">No ${noun === "event" ? "events" : "ministries"} yet</div>
            <div class="fs-12">Add them under Gathering types, or record one with "Other".</div>
          </div>
        </div>`;
      return;
    }

    const expanded = container.dataset.expanded === "1";
    const shown = expanded ? summaries : summaries.slice(0, limit);
    const UI = DemographicsUI;
    container.innerHTML =
      shown
        .map(
          (g) => `
        <div class="col-xxl-3 col-xl-4 col-md-6">
          <button type="button" class="gathering-card${g.name === active ? " is-active" : ""}" data-gathering="${escapeHtml(g.name)}">
            <span class="gathering-card-top">
              ${UI.avatarTile(escapeHtml(g.icon), g.color)}
              <span class="gathering-card-name">${escapeHtml(g.name)}</span>
            </span>
            <span class="gathering-card-stats">
              <span><b>${g.timesThisYear}×</b>this year</span>
              <span><b>${g.average ? g.average.toLocaleString() : "-"}</b>average</span>
              <span class="gathering-card-status">${UI.pill(g.status.label, g.status.color)}</span>
            </span>
            <span class="gathering-card-foot">
              <span>${g.last ? `Last met ${formatDate(g.last, { day: "numeric", month: "short" })} · ${g.lastTotal.toLocaleString()}` : "Not held yet"}</span>
              ${g.times ? `<span class="gathering-card-spark" data-spark='${JSON.stringify(g.series).replace(/'/g, "&#39;")}' data-spark-color="${g.color}" data-spark-height="30"></span>` : ""}
            </span>
          </button>
        </div>`,
        )
        .join("") +
      (summaries.length > limit
        ? `<div class="col-12"><button type="button" class="btn btn-sm btn-light gathering-more">${expanded ? "Show fewer" : `Show all ${summaries.length}`}</button></div>`
        : "");

    UI.mountSparklines(container);
    container.querySelectorAll(".gathering-card").forEach((btn) =>
      btn.addEventListener("click", () => onPick && onPick(summaries.find((g) => g.name === btn.dataset.gathering))),
    );
    container.querySelector(".gathering-more")?.addEventListener("click", () => {
      container.dataset.expanded = expanded ? "0" : "1";
      renderGatheringCards(containerId, summaries, { onPick, active, limit, noun });
    });
  }

  // ==========================================================================
  // ENTRY MODAL
  // ==========================================================================

  function numberTileHtml(g) {
    return `
      <div class="col-6">
        <div class="num-tile" data-tile="${g.id}">
          <label class="num-tile-label" for="${g.id}">
            <span class="num-tile-icon bg-${g.color} text-white"><i class="${g.icon}"></i></span><span>${g.label}</span>
          </label>
          <div class="num-tile-control">
            <button class="num-tile-btn stepper-btn" type="button" data-stepper-target="${g.id}" data-stepper-dir="-1" aria-label="Fewer ${g.label}"><i class="ri-subtract-line"></i></button>
            <input type="number" class="form-control num-tile-input" id="${g.id}" min="0" max="99999" inputmode="numeric" placeholder="0">
            <button class="num-tile-btn stepper-btn" type="button" data-stepper-target="${g.id}" data-stepper-dir="1" aria-label="More ${g.label}"><i class="ri-add-line"></i></button>
          </div>
          <div class="num-tile-foot"><span class="num-tile-last" data-last-for="${g.id}"></span></div>
        </div>
      </div>`;
  }

  function ensureModalMounted() {
    if (document.getElementById(MODAL_ID)) return;

    const modalHtml = `
      <div class="modal fade app-modal att-modal" id="${MODAL_ID}" tabindex="-1" aria-hidden="true" aria-labelledby="attendanceModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon" id="attendanceModalIcon"><i class="ri-calendar-check-line"></i></span>
              <div class="flex-fill" style="min-width: 0;">
                <h5 class="modal-title" id="attendanceModalTitle">Record attendance</h5>
                <div class="app-modal-subtitle" id="attendanceModalSubtitle"></div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="att-entry">
                <div class="att-entry-main">
                  <section class="att-entry-section">
                    <div class="att-entry-title"><span>1</span>When</div>
                    <div id="attendanceSundayPicker" class="period-grid att-sunday-grid"></div>
                    <div id="attendanceDateRow" class="att-date-row">
                      <input type="date" class="form-control" id="attendanceServiceDate" max="">
                      <div class="list-range att-date-quick" id="attendanceDateQuick" role="group" aria-label="Quick dates"></div>
                    </div>
                    <div class="invalid-feedback" id="attendanceDateError">Pick the date of this gathering.</div>
                    <div class="att-date-fixed" id="attendanceDateFixed" hidden></div>
                    <div class="att-notice" id="attendanceNotice" hidden></div>
                  </section>

                  <section class="att-entry-section" id="attendanceGatheringSection" hidden>
                    <div class="att-entry-title"><span>2</span>Which gathering</div>
                    <select class="form-select" id="attendanceGatheringType" aria-label="Gathering"></select>
                    <div class="invalid-feedback" id="attendanceGatheringTypeError">Choose which gathering this was.</div>
                    <div id="attendanceEventNameGroup" class="mt-2" hidden>
                      <input type="text" class="form-control" id="attendanceEventName" placeholder="Name it, e.g. Diocese Youth Camp 2026" maxlength="255">
                      <div class="invalid-feedback">Give this gathering a name.</div>
                    </div>
                  </section>

                  <section class="att-entry-section">
                    <div class="att-entry-title justify-content-between">
                      <span class="d-inline-flex align-items-center gap-2"><span id="attendanceCountsStep">3</span>Who attended</span>
                      <button type="button" class="btn btn-sm btn-outline-primary" id="attendanceCopyLastBtn" hidden><i class="ri-file-copy-line me-1"></i><span>Same as last time</span></button>
                    </div>
                    <div class="row g-2">${GROUPS.map(numberTileHtml).join("")}</div>
                    <div class="invalid-feedback" id="attendanceCountsError">Enter at least one count.</div>
                  </section>

                  <section class="att-entry-section mb-0">
                    <label for="attendanceNotes" class="form-label mb-1">Notes <span class="fw-normal">(optional)</span></label>
                    <textarea class="form-control" id="attendanceNotes" rows="2" placeholder="e.g. Heavy rain, Harvest Sunday, visiting preacher..."></textarea>
                  </section>
                </div>

                <aside class="att-entry-preview" aria-live="polite">
                  <div class="att-preview-label">Preview</div>
                  <div class="att-preview-what" id="attendancePreviewWhat">-</div>
                  <div class="att-preview-total"><span id="attendanceTotal">0</span><small>people</small></div>
                  <div class="count-bar att-preview-bar" id="attendanceTotalBar" aria-hidden="true">
                    ${GROUPS.map((g) => `<span class="bg-${g.color}" data-part="${g.id}"></span>`).join("")}
                  </div>
                  <ul class="att-preview-list" id="attendancePreviewList"></ul>
                  <div class="att-preview-compare" id="attendancePreviewCompare"></div>
                </aside>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" id="attendanceModalSaveBtn"><i class="ri-check-line me-1"></i><span>Save</span></button>
            </div>

            <div class="app-modal-state is-busy-view" role="status">
              <div class="app-modal-spinner"></div>
              <div class="fw-semibold">Saving...</div>
            </div>
            <div class="app-modal-state is-done-view">
              <div class="app-modal-tick"><i class="ri-check-line"></i></div>
              <div class="fs-5 fw-bold" id="attendanceDoneTitle">Attendance saved</div>
              <div class="app-modal-facts" id="attendanceDoneFacts"></div>
              <div class="d-flex flex-wrap justify-content-center gap-2" id="attendanceDoneActions"></div>
            </div>
          </div>
        </div>
      </div>`;

    const container = document.createElement("div");
    container.innerHTML = modalHtml;
    document.body.appendChild(container.firstElementChild);

    const modalEl = document.getElementById(MODAL_ID);
    DemographicsUI.initSteppers(modalEl);
    GROUPS.forEach((g) => document.getElementById(g.id).addEventListener("input", updatePreview));
    document.getElementById("attendanceModalSaveBtn").addEventListener("click", handleModalSave);
    document.getElementById("attendanceServiceDate").addEventListener("change", (e) => selectDate(e.target.value));
    document.getElementById("attendanceCopyLastBtn").addEventListener("click", copyLastTime);
    document.getElementById("attendanceEventName").addEventListener("input", () => {
      document.getElementById("attendanceEventName").classList.remove("is-invalid");
      refreshContext();
    });
    document.getElementById("attendanceSundayPicker").addEventListener("click", (e) => {
      const chip = e.target.closest("[data-sunday]");
      if (chip && !chip.disabled) selectDate(chip.dataset.sunday);
    });
    document.getElementById("attendanceDateQuick").addEventListener("click", (e) => {
      const btn = e.target.closest("[data-date]");
      if (btn) selectDate(btn.dataset.date);
    });
    document.getElementById("attendanceNotice").addEventListener("click", (e) => {
      const btn = e.target.closest("[data-edit-record]");
      if (btn) switchToRecord(Number(btn.dataset.editRecord));
    });
    const select = document.getElementById("attendanceGatheringType");
    select.addEventListener("change", () => {
      document.getElementById("attendanceGatheringTypeError").style.display = "";
      document.getElementById("attendanceEventNameGroup").hidden = select.value !== OTHER_VALUE;
      refreshContext();
    });
    modalEl.addEventListener("hidden.bs.modal", () => modalEl.classList.remove("is-busy", "is-done"));
  }

  function categoryLabel() {
    if (!currentConfig) return "";
    return currentConfig.isWeekly ? "Sunday service" : currentConfig.categoryLabel || "Gathering";
  }

  const records = () => currentConfig?.records || [];

  function countValue(id) {
    return parseInt(document.getElementById(id).value, 10) || 0;
  }

  function setCounts(source) {
    GROUPS.forEach((g) => {
      const v = source ? source[g.key] : null;
      document.getElementById(g.id).value = v == null ? "" : v;
    });
    updatePreview();
  }

  /**
   * @param {object} config
   *   gatheringCategoryId: number
   *   isWeekly: boolean - Sunday service (no gathering type, one per Sunday)
   *   territoryId: number
   *   record: record to edit, or null to create
   *   defaultDate: 'YYYY-MM-DD' to preselect when creating
   *   records: this category's records (for "last time", duplicates, missing Sundays)
   *   types: this category's gathering types (fetched when omitted)
   *   categoryLabel: "Ministry gathering" | "Special event"
   *   onSaved(savedRecord)
   */
  async function openEntryModal(config) {
    ensureModalMounted();
    currentConfig = { records: [], ...config };
    currentRecordId = config.record ? config.record.id : null;

    const modalEl = document.getElementById(MODAL_ID);
    modalEl.classList.remove("is-busy", "is-done");
    clearErrors();

    const record = config.record || {};
    setCounts(config.record || null);
    document.getElementById("attendanceNotes").value = record.notes || "";
    document.getElementById("attendanceModalIcon").innerHTML = `<i class="${config.isWeekly ? "ri-sun-line" : config.icon || "ri-group-line"}"></i>`;
    document.getElementById("attendanceGatheringSection").hidden = !!config.isWeekly;
    document.getElementById("attendanceCountsStep").textContent = config.isWeekly ? "2" : "3";

    const initialDate = config.record ? recordIso(config.record) : config.defaultDate || (config.isWeekly ? nextMissingSunday(records()) || isoDate(sundayOnOrBefore()) : todayIso());

    // Editing: the date is fixed (the API doesn't move a record).
    const editing = !!config.record;
    document.getElementById("attendanceSundayPicker").hidden = editing || !config.isWeekly;
    document.getElementById("attendanceDateRow").hidden = editing || config.isWeekly;
    document.getElementById("attendanceDateFixed").hidden = !editing;
    document.getElementById("attendanceDateFixed").innerHTML = `<i class="ri-calendar-line"></i>${longDate(initialDate)}<span>The date can't be changed once saved</span>`;
    document.getElementById("attendanceServiceDate").max = todayIso();

    if (!config.isWeekly) {
      document.getElementById("attendanceEventName").value = record.gathering_type_id ? "" : record.event_name || "";
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      const saveBtn = document.getElementById("attendanceModalSaveBtn");
      saveBtn.disabled = true;
      await populateGatheringTypes(config, record);
      saveBtn.disabled = false;
    }

    selectDate(initialDate, { keepCounts: true });
    setModeText();
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    setTimeout(() => document.getElementById(GROUPS[0].id).focus({ preventScroll: true }), 350);
  }

  async function populateGatheringTypes(config, record) {
    const select = document.getElementById("attendanceGatheringType");
    let types = config.types;
    if (!types) {
      const result = await DemographicsAPIHandler.getGatheringTypes(config.territoryId, { gathering_category_id: config.gatheringCategoryId });
      types = result.success ? result.data || [] : [];
    }
    loadedTypes = types.filter((t) => t.is_active !== false || t.id === record.gathering_type_id);

    select.innerHTML =
      loadedTypes
        .map((t) => `<option value="${t.id}" data-icon="${escapeHtml(t.icon || "ri-calendar-event-line")}" data-color="${DemographicsUI.colorFor(t.name)}">${escapeHtml(t.name)}</option>`)
        .join("") + `<option value="${OTHER_VALUE}" data-icon="ri-edit-line" data-color="secondary">Other (type a name)</option>`;

    // New entries start on the most used gathering; edits keep what was saved.
    const mostUsed = summarizeGatherings(records(), loadedTypes).find((g) => g.key.startsWith("t") && g.times > 0);
    select.value = record.gathering_type_id
      ? String(record.gathering_type_id)
      : record.event_name
        ? OTHER_VALUE
        : mostUsed
          ? mostUsed.key.substring(1)
          : loadedTypes[0]
            ? String(loadedTypes[0].id)
            : OTHER_VALUE;
    DemographicsUI.enhanceSelect(select, { search: loadedTypes.length > 6 });
    DemographicsUI.syncSelect(select);
    document.getElementById("attendanceEventNameGroup").hidden = select.value !== OTHER_VALUE;
  }

  /** The gathering currently chosen, as {typeId, name} - or null when not chosen yet. */
  function currentGathering() {
    if (currentConfig.isWeekly) return { typeId: null, name: "Sunday service" };
    const value = document.getElementById("attendanceGatheringType").value;
    if (!value) return null;
    if (value === OTHER_VALUE) {
      const name = document.getElementById("attendanceEventName").value.trim();
      return name ? { typeId: null, name } : null;
    }
    const type = loadedTypes.find((t) => String(t.id) === value);
    return { typeId: Number(value), name: type?.name || "Gathering" };
  }

  function sameGathering(row, g) {
    if (!g) return false;
    if (currentConfig.isWeekly) return true;
    return g.typeId ? row.gathering_type_id === g.typeId : !row.gathering_type_id && String(row.event_name || "").trim().toLowerCase() === g.name.toLowerCase();
  }

  /** This gathering's records before the selected date, newest first (excluding the one being edited). */
  function history() {
    const g = currentGathering();
    return records()
      .filter((r) => r.id !== currentRecordId && sameGathering(r, g) && (!selectedDate || recordIso(r) < selectedDate))
      .sort((a, b) => recordIso(b).localeCompare(recordIso(a)));
  }

  /** A record of this gathering on the selected date (other than the one being edited). */
  function duplicateOnDate() {
    const g = currentGathering();
    return records().find((r) => r.id !== currentRecordId && recordIso(r) === selectedDate && sameGathering(r, g)) || null;
  }

  function selectDate(iso, { keepCounts = false } = {}) {
    const wasEditingExisting = !currentConfig.record && currentRecordId;
    selectedDate = iso || "";
    document.getElementById("attendanceServiceDate").value = selectedDate;
    document.getElementById("attendanceServiceDate").classList.remove("is-invalid");
    document.getElementById("attendanceDateError").style.display = "";

    // Sundays: picking one that's already recorded edits that record.
    if (currentConfig.isWeekly && !currentConfig.record) {
      const existing = records().find((r) => recordIso(r) === selectedDate);
      if (existing) {
        currentRecordId = existing.id;
        setCounts(existing);
        document.getElementById("attendanceNotes").value = existing.notes || "";
      } else {
        currentRecordId = null;
        if (wasEditingExisting && !keepCounts) {
          setCounts(null);
          document.getElementById("attendanceNotes").value = "";
        }
      }
      renderSundayPicker();
    }

    if (!currentConfig.isWeekly && !currentConfig.record) renderQuickDates();
    setModeText();
    refreshContext();
  }

  function renderSundayPicker() {
    const end = sundayOnOrBefore();
    const start = new Date(end);
    start.setDate(start.getDate() - 4 * 7);
    const rows = sundayRows(records(), start, end);
    // A Sunday picked further back (via "Other date") still shows as a chip.
    if (selectedDate && !rows.some((s) => s.iso === selectedDate)) {
      const record = records().find((r) => recordIso(r) === selectedDate) || null;
      rows.push({ iso: selectedDate, record, status: record ? "recorded" : "missing" });
    }
    document.getElementById("attendanceSundayPicker").innerHTML =
      sundayChipsHtml(rows, { selected: selectedDate }) +
      `<label class="period-chip att-sunday-other">
         <span class="period-chip-name"><i class="ri-calendar-line me-1"></i>Other Sunday</span>
         <input type="date" class="att-sunday-other-input" max="${todayIso()}" aria-label="Pick another Sunday">
       </label>`;
    const other = document.querySelector("#attendanceSundayPicker .att-sunday-other-input");
    other.addEventListener("change", () => {
      if (!other.value) return;
      if (parseIso(other.value).getDay() !== 0) {
        Toast.warning(`${longDate(other.value)} isn't a Sunday`);
        other.value = "";
        return;
      }
      selectDate(other.value);
    });
  }

  function renderQuickDates() {
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    const quick = [
      { iso: todayIso(), label: "Today" },
      { iso: isoDate(yesterday), label: "Yesterday" },
      { iso: isoDate(sundayOnOrBefore(today)), label: "Last Sunday" },
    ].filter((q, i, arr) => arr.findIndex((x) => x.iso === q.iso) === i);
    document.getElementById("attendanceDateQuick").innerHTML = quick
      .map((q) => `<button type="button" class="list-range-btn${q.iso === selectedDate ? " active" : ""}" data-date="${q.iso}">${q.label}</button>`)
      .join("");
  }

  function setModeText() {
    const editing = !!currentRecordId;
    document.getElementById("attendanceModalTitle").textContent = editing ? "Edit attendance" : "Record attendance";
    document.querySelector("#attendanceModalSaveBtn span").textContent = editing ? "Save changes" : "Save";
    document.getElementById("attendanceModalSubtitle").textContent = [categoryLabel(), selectedDate ? longDate(selectedDate) : ""].filter(Boolean).join(" · ");
  }

  /** Everything that depends on the date + gathering: notices, "last time" hints, the copy button, the preview. */
  function refreshContext() {
    const notice = document.getElementById("attendanceNotice");
    const d = selectedDate ? parseIso(selectedDate) : null;
    const messages = [];

    if (currentConfig.isWeekly && !currentConfig.record && currentRecordId) {
      messages.push({ tone: "primary", icon: "ri-edit-line", text: "This Sunday is already recorded - you're updating it." });
    }
    if (currentConfig.isWeekly && d && d.getDay() !== 0) {
      messages.push({ tone: "danger", icon: "ri-error-warning-line", text: `${longDate(selectedDate)} isn't a Sunday.` });
    }
    if (selectedDate && selectedDate > todayIso()) {
      messages.push({ tone: "warning", icon: "ri-time-line", text: "That date is in the future." });
    }
    const dup = !currentConfig.isWeekly ? duplicateOnDate() : null;
    if (dup) {
      messages.push({
        tone: "warning",
        icon: "ri-file-copy-2-line",
        text: `${escapeHtml(gatheringName(dup))} on ${shortDate(selectedDate)} is already recorded (${recordTotal(dup)} attended).`,
        action: `<button type="button" class="btn btn-sm btn-light" data-edit-record="${dup.id}">Edit that one</button>`,
      });
    }
    notice.hidden = !messages.length;
    notice.innerHTML = messages
      .map((m) => `<div class="att-notice-item is-${m.tone}"><i class="${m.icon}"></i><span>${m.text}</span>${m.action || ""}</div>`)
      .join("");

    const last = history()[0] || null;
    GROUPS.forEach((g) => {
      const el = document.querySelector(`#${MODAL_ID} [data-last-for="${g.id}"]`);
      el.innerHTML = last ? `Last time <b>${(Number(last[g.key]) || 0).toLocaleString()}</b>` : "";
    });
    const copyBtn = document.getElementById("attendanceCopyLastBtn");
    copyBtn.hidden = !last || !!currentRecordId;
    if (last) copyBtn.querySelector("span").textContent = `Same as ${currentConfig.isWeekly ? "last Sunday" : "last time"} (${recordTotal(last).toLocaleString()})`;

    updatePreview();
  }

  function copyLastTime() {
    const last = history()[0];
    if (!last) return;
    setCounts(last);
    Toast.info(`Copied ${shortDate(recordIso(last))} - change anything that was different`);
  }

  /** Switch the form to editing an existing record (from a duplicate notice or a 422). */
  function switchToRecord(id) {
    const record = records().find((r) => r.id === id);
    if (!record) return;
    openEntryModal({ ...currentConfig, record, defaultDate: null });
  }

  function updatePreview() {
    const values = GROUPS.map((g) => countValue(g.id));
    const total = values.reduce((a, b) => a + b, 0);
    document.getElementById("attendanceTotal").textContent = total.toLocaleString();
    document.querySelectorAll("#attendanceTotalBar > span").forEach((seg, i) => {
      seg.style.width = total ? `${(values[i] / total) * 100}%` : "0";
    });
    document.getElementById("attendancePreviewList").innerHTML = GROUPS.map(
      (g, i) => `
        <li>
          <span><span class="count-dot bg-${g.color}"></span>${g.label}</span>
          <b>${values[i].toLocaleString()}<small>${total ? Math.round((values[i] / total) * 100) : 0}%</small></b>
        </li>`,
    ).join("");
    if (total > 0) document.getElementById("attendanceCountsError").style.display = "";

    const g = currentConfig ? currentGathering() : null;
    document.getElementById("attendancePreviewWhat").textContent = [g ? g.name : categoryLabel(), selectedDate ? shortDate(selectedDate) : ""].filter(Boolean).join(" · ");

    const past = currentConfig ? history() : [];
    const compare = document.getElementById("attendancePreviewCompare");
    if (!past.length) {
      compare.innerHTML = `<div class="att-compare-row"><span>First time recorded</span><b>-</b></div>`;
      return;
    }
    const last = recordTotal(past[0]);
    const recent = past.slice(0, 8).map(recordTotal);
    const usual = Math.round(recent.reduce((a, b) => a + b, 0) / recent.length);
    const delta = total ? DemographicsUI.periodDelta(total, last, { prevLabel: "last time" }) : null;
    const lastLabel = "Last recorded";
    let warn = "";
    if (total && usual && total > usual * 2) warn = "That's more than double the usual - double-check the numbers.";
    else if (total && usual && total < usual / 3) warn = "That's far below the usual - double-check the numbers.";
    compare.innerHTML = `
      <div class="att-compare-row"><span>${lastLabel} <small>${shortDate(recordIso(past[0]))}</small></span><b>${last.toLocaleString()}${delta ? `<span class="stat-delta is-${delta.dir}">${delta.text}</span>` : ""}</b></div>
      <div class="att-compare-row"><span>Usual <small>avg of last ${recent.length}</small></span><b>${usual.toLocaleString()}</b></div>
      ${warn ? `<div class="att-compare-warn"><i class="ri-alert-line"></i>${warn}</div>` : ""}`;
  }

  function clearErrors() {
    document.querySelectorAll(`#${MODAL_ID} .is-invalid`).forEach((el) => el.classList.remove("is-invalid"));
    document.querySelectorAll(`#${MODAL_ID} .num-tile.has-error`).forEach((el) => el.classList.remove("has-error"));
    ["attendanceGatheringTypeError", "attendanceCountsError", "attendanceDateError"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.style.display = "";
    });
  }

  const showError = (id) => (document.getElementById(id).style.display = "block");

  /** Mark fields invalid from a backend 422 `errors` map. */
  function showBackendErrors(errors) {
    const FIELD_INPUTS = { event_name: "attendanceEventName", notes: "attendanceNotes" };
    Object.entries(errors || {}).forEach(([field, messages]) => {
      const message = Array.isArray(messages) ? messages[0] : messages;
      if (field === "service_date") {
        document.getElementById("attendanceDateError").textContent = message;
        showError("attendanceDateError");
        return;
      }
      if (field === "gathering_type_id") {
        document.getElementById("attendanceGatheringTypeError").textContent = message;
        showError("attendanceGatheringTypeError");
        return;
      }
      const group = GROUPS.find((g) => g.key === field);
      const input = document.getElementById(group ? group.id : FIELD_INPUTS[field]);
      if (input) input.classList.add("is-invalid");
    });
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function handleModalSave() {
    clearErrors();
    let valid = true;

    if (!selectedDate) {
      document.getElementById("attendanceServiceDate").classList.add("is-invalid");
      document.getElementById("attendanceDateError").textContent = "Pick the date of this gathering.";
      showError("attendanceDateError");
      valid = false;
    } else if (currentConfig.isWeekly && parseIso(selectedDate).getDay() !== 0) {
      document.getElementById("attendanceDateError").textContent = "Pick a Sunday.";
      showError("attendanceDateError");
      valid = false;
    }

    const payload = {
      territory_id: currentConfig.territoryId,
      service_date: selectedDate,
      gathering_category_id: currentConfig.gatheringCategoryId,
      gathering_type_id: null,
      event_name: null,
      notes: document.getElementById("attendanceNotes").value.trim() || null,
    };
    GROUPS.forEach((g) => {
      const v = document.getElementById(g.id).value;
      payload[g.key] = v === "" ? null : parseInt(v, 10);
    });

    let name = categoryLabel();
    if (!currentConfig.isWeekly) {
      const value = document.getElementById("attendanceGatheringType").value;
      if (!value) {
        showError("attendanceGatheringTypeError");
        valid = false;
      } else if (value === OTHER_VALUE) {
        const eventInput = document.getElementById("attendanceEventName");
        payload.event_name = eventInput.value.trim();
        if (!payload.event_name) {
          eventInput.classList.add("is-invalid");
          valid = false;
        }
        name = payload.event_name || name;
      } else {
        payload.gathering_type_id = parseInt(value, 10);
        name = currentGathering()?.name || name;
      }
    }

    const total = GROUPS.reduce((acc, g) => acc + (payload[g.key] || 0), 0);
    if (total === 0) {
      showError("attendanceCountsError");
      document.querySelectorAll(`#${MODAL_ID} .num-tile`).forEach((t) => t.classList.add("has-error"));
      valid = false;
    }

    if (!valid) {
      Toast.warning("Please fix the highlighted fields");
      return;
    }

    const modalEl = document.getElementById(MODAL_ID);
    const isEdit = !!currentRecordId;
    modalEl.classList.add("is-busy");

    // Keep "Saving..." on screen long enough to read, even on a fast save.
    const [result] = await Promise.all([
      isEdit ? DemographicsAPIHandler.updateAttendance(currentRecordId, payload) : DemographicsAPIHandler.createAttendance(payload),
      sleep(450),
    ]);
    modalEl.classList.remove("is-busy");

    if (!result.success) {
      // Someone else recorded this Sunday meanwhile: reload, and the form
      // switches to editing their record (the typed counts are kept).
      if (result.existingId) {
        const typed = Object.fromEntries(GROUPS.map((g) => [g.key, payload[g.key]]));
        const fresh = await DemographicsAPIHandler.getAttendance(currentConfig.territoryId, { gathering_category_id: currentConfig.gatheringCategoryId });
        if (fresh.success) currentConfig.records = fresh.data || [];
        selectDate(selectedDate);
        setCounts(typed);
        if (currentConfig.onSaved) currentConfig.onSaved(null);
        Toast.warning("Someone already recorded this Sunday - check the numbers and save to update it");
        return;
      }
      showBackendErrors(result.errors);
      Toast.error(result.message || "Couldn't save the attendance");
      return;
    }

    const saved = result.data;
    // Keep the form's own list current, so "next missing Sunday" skips this one.
    currentConfig.records = [...records().filter((r) => r.id !== saved.id), saved];
    const summary = `${name}, ${longDate(selectedDate)} - ${total.toLocaleString()} ${total === 1 ? "person" : "people"}`;
    if (currentConfig.onSaved) currentConfig.onSaved(saved);

    if (isEdit && currentConfig.record) {
      bootstrap.Modal.getInstance(modalEl).hide();
      Toast.success(`Updated - ${summary}`);
      return;
    }

    document.getElementById("attendanceDoneTitle").textContent = isEdit ? "Attendance updated" : "Attendance recorded";
    document.getElementById("attendanceDoneFacts").innerHTML = [
      DemographicsUI.pill(escapeHtml(name), "primary", currentConfig.isWeekly ? "ri-sun-line" : "ri-group-line"),
      DemographicsUI.pill(shortDate(selectedDate), "purple", "ri-calendar-line"),
      DemographicsUI.pill(`${total.toLocaleString()} attended`, "success", "ri-team-line"),
    ].join("");

    const next = currentConfig.isWeekly ? nextMissingSunday(records()) : null;
    const actions = document.getElementById("attendanceDoneActions");
    actions.innerHTML = [
      next ? `<button type="button" class="btn btn-primary" data-done="next"><i class="ri-arrow-right-line me-1"></i>Record ${shortDate(next)}</button>` : "",
      !currentConfig.isWeekly ? '<button type="button" class="btn btn-light" data-done="another"><i class="ri-add-line me-1"></i>Add another</button>' : "",
      `<button type="button" class="btn ${next ? "btn-light" : "btn-primary"}" data-bs-dismiss="modal">Done</button>`,
    ].join("");
    actions.querySelector('[data-done="next"]')?.addEventListener("click", () => openEntryModal({ ...currentConfig, record: null, defaultDate: next }));
    actions.querySelector('[data-done="another"]')?.addEventListener("click", () => openEntryModal({ ...currentConfig, record: null, defaultDate: selectedDate }));
    modalEl.classList.add("is-done");
    Toast.success(`Saved - ${summary}`);
  }

  // ==========================================================================
  // LIST TABLE (ministries.php / events.php)
  // ==========================================================================

  /**
   * Rows for a gathering list. `rows` must be newest-first - each row's
   * change pill compares it with the previous meeting of the same gathering
   * further down the list. Cells carry data-order/data-search so sorting and
   * the gathering filter work on the raw value, not the decorated markup.
   */
  function renderListRows(rows, { onEdit } = {}) {
    const UI = DemographicsUI;
    if (!rows || rows.length === 0) {
      return UI.renderTableEmpty(5, "Nothing recorded yet - add your first entry", "ri-calendar-line");
    }

    return rows
      .map((row, index) => {
        const iso = recordIso(row);
        const total = recordTotal(row);
        const icon = row.gathering_type?.icon || row.gathering_category?.icon || "ri-calendar-event-line";
        const label = gatheringName(row);
        const previous = rows.slice(index + 1).find((r) => gatheringKey(r) === gatheringKey(row));
        const breakdown = GROUPS.map((g) => (row[g.key] ? `<span><span class="count-dot bg-${g.color}"></span>${row[g.key]} ${Number(row[g.key]) === 1 ? g.one : g.many}</span>` : "")).filter(Boolean).join("");
        const notes = row.notes ? escapeHtml(row.notes) : "";
        const editBtn = onEdit
          ? `<button type="button" class="btn btn-sm btn-primary-light" onclick="${onEdit}(${row.id})" title="Edit entry" aria-label="Edit entry"><i class="ri-edit-line"></i></button>`
          : "";

        return `
          <tr data-date="${iso}" data-row-id="${row.id}">
            <td data-order="${iso}">
              <div class="fw-semibold">${formatDate(iso)}</div>
              <div class="fs-12">${formatDate(iso, { weekday: "long" })}</div>
            </td>
            <td data-search="${escapeHtml(label)}">
              <div class="d-flex align-items-center gap-2">
                ${UI.avatarTile(escapeHtml(icon), UI.colorFor(label))}
                <span class="fw-semibold">${escapeHtml(label)}</span>
              </div>
            </td>
            <td data-order="${total}">
              <span class="fw-bold fs-15">${total.toLocaleString()}</span>${UI.changePill(total, previous ? recordTotal(previous) : null)}
              ${breakdown ? `<div class="att-breakdown">${breakdown}</div>` : ""}
            </td>
            <td class="list-notes d-none d-lg-table-cell">${notes ? `<span title="${notes}">${notes}</span>` : "-"}</td>
            <td class="text-end">${editBtn}</td>
          </tr>`;
      })
      .join("");
  }

  /**
   * KPI cards for a gathering list page (Ministries / Special Events), each
   * comparing this month with last month from `rows` (every record for this
   * category, already loaded).
   */
  function renderGatheringStats(containerId, rows, { noun = "Ministry", plural = "gatherings" } = {}) {
    const UI = DemographicsUI;
    const thisMonth = UI.rowsInMonth(rows, 0);
    const lastMonth = UI.rowsInMonth(rows, 1);
    const prevLabel = UI.monthLabel(1);
    const sum = (list) => list.reduce((acc, r) => acc + recordTotal(r), 0);
    const avg = (list) => (list.length ? Math.round(sum(list) / list.length) : 0);
    const top = summarizeGatherings(thisMonth.length ? thisMonth : rows).sort((a, b) => b.rows.reduce((s, r) => s + recordTotal(r), 0) - a.rows.reduce((s, r) => s + recordTotal(r), 0))[0];
    const Plural = plural.charAt(0).toUpperCase() + plural.slice(1);

    // Early in a month nothing may be recorded yet: an average of nothing
    // isn't 0, and "-100%" would read like nobody came.
    const none = !thisMonth.length;
    UI.renderStatCardsRow(containerId, [
      {
        icon: "ri-team-line",
        label: "Attendance this month",
        value: sum(thisMonth).toLocaleString(),
        color: "primary",
        delta: none ? null : UI.periodDelta(sum(thisMonth), sum(lastMonth), { prevLabel }),
        series: UI.monthlySeries(rows, { value: recordTotal }),
        sub: none ? `None recorded yet · ${prevLabel}: ${sum(lastMonth).toLocaleString()}` : "",
      },
      {
        icon: "ri-calendar-check-line",
        label: `${Plural} this month`,
        value: thisMonth.length,
        color: "success",
        delta: none ? null : UI.periodDelta(thisMonth.length, lastMonth.length, { percent: false, prevLabel }),
        series: UI.monthlySeries(rows),
        sub: none ? `${prevLabel}: ${lastMonth.length}` : "",
      },
      {
        icon: "ri-bar-chart-2-line",
        label: `Avg per ${noun === "Ministry" ? "gathering" : "event"}`,
        value: none ? "-" : avg(thisMonth),
        color: "purple",
        delta: none || !lastMonth.length ? null : UI.periodDelta(avg(thisMonth), avg(lastMonth), { prevLabel }),
        sub: none ? (lastMonth.length ? `${prevLabel}: ${avg(lastMonth)}` : "Nothing recorded yet") : "",
      },
      {
        icon: "ri-trophy-line",
        label: `Most attended ${noun.toLowerCase()}`,
        value: top ? escapeHtml(top.name) : "-",
        color: "secondary",
        trend: top ? `${top.rows.reduce((s, r) => s + recordTotal(r), 0).toLocaleString()} attended${thisMonth.length ? " this month" : ""}` : `No ${plural} yet`,
      },
    ]);
  }

  return {
    GROUPS,
    openEntryModal,
    renderListRows,
    renderGatheringStats,
    renderGatheringCards,
    summarizeGatherings,
    sundayRows,
    sundayChipsHtml,
    nextMissingSunday,
    sundayOnOrBefore,
    sundaysBetween,
    gatheringStatus,
    recordTotal,
    recordIso,
    isoDate,
    parseIso,
    formatDate,
    shortDate,
    todayIso,
    escapeHtml,
  };
})();

window.AttendanceFormShared = AttendanceFormShared;
