/**
 * ============================================================================
 * SHARED - ATTENDANCE ENTRY MODAL + LIST TABLE
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One entry modal + one list-table renderer, reused by all three Attendance
 * entry pages (services.php's calendar, ministries.php, events.php) -
 * avoids three near-duplicate 300-line files for what's really one form
 * shape parameterized by gathering category.
 *
 * Non-Sunday categories (Ministry Gathering, Special Event) let the user
 * pick from that church's own configured gathering_types (e.g. "Kesha",
 * "Tuesday Fellowship") instead of typing a free-text name every time -
 * an "Other" fallback still allows a genuine one-off. Sunday Service
 * (isWeekly) skips this entirely, same as before.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, Bootstrap 5
 * ============================================================================
 */

const AttendanceFormShared = (function () {
  "use strict";

  const MODAL_ID = "attendanceEntryModal";
  const OTHER_VALUE = "__other__";
  let currentConfig = null;
  let currentRecordId = null;
  // Choices.js instance wrapping #attendanceGatheringType - built once when
  // the modal first mounts (assets/libs/choices.js, the same searchable-
  // dropdown library assets/js/pages/budget-management/create-budget.js
  // already uses; "Select2" was asked for but isn't actually vendored in
  // this codebase). allowHTML lets each option show its gathering type's
  // icon next to its name, so a user can tell Kesha from Tuesday
  // Fellowship at a glance in the list, not just by reading the label.
  let gatheringTypeChoices = null;

  const COUNT_FIELDS = ["attendanceAdults", "attendanceYouth", "attendanceChildrenMale", "attendanceChildrenFemale"];

  // Backend validation field -> input id, for showing 422 errors inline.
  const FIELD_INPUTS = {
    service_date: "attendanceServiceDate",
    gathering_type_id: "attendanceGatheringType",
    event_name: "attendanceEventName",
    adults_count: "attendanceAdults",
    youth_count: "attendanceYouth",
    children_male_count: "attendanceChildrenMale",
    children_female_count: "attendanceChildrenFemale",
    notes: "attendanceNotes",
  };

  function categoryLabel() {
    if (!currentConfig) return "";
    if (currentConfig.isWeekly) return "Sunday service";
    return currentConfig.categoryLabel || "Gathering";
  }

  function formatLongDate(iso) {
    if (!iso) return "";
    const d = new Date(`${iso}T00:00:00`);
    return isNaN(d) ? "" : d.toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "short", year: "numeric" });
  }

  function updateDateHint() {
    const iso = document.getElementById("attendanceServiceDate").value;
    const hint = document.getElementById("attendanceDateHint");
    const subtitle = document.getElementById("attendanceModalSubtitle");
    const pretty = formatLongDate(iso);
    const d = iso ? new Date(`${iso}T00:00:00`) : null;
    const notSunday = currentConfig && currentConfig.isWeekly && d && d.getDay() !== 0;
    hint.innerHTML = !pretty
      ? "The Sunday or gathering date this count is for."
      : notSunday
        ? `<span class="text-danger fw-semibold"><i class="ri-error-warning-line me-1"></i>${pretty} isn't a Sunday</span>`
        : `<i class="ri-calendar-line me-1"></i>${pretty}`;
    subtitle.textContent = [categoryLabel(), pretty].filter(Boolean).join(" · ");
    document.getElementById("attendanceServiceDate").classList.remove("is-invalid");
  }

  function countValue(id) {
    return parseInt(document.getElementById(id).value, 10) || 0;
  }

  function updateTotal() {
    const values = COUNT_FIELDS.map(countValue);
    const total = values.reduce((a, b) => a + b, 0);
    document.getElementById("attendanceTotal").textContent = total.toLocaleString();
    document.querySelectorAll("#attendanceTotalBar > span").forEach((seg) => {
      const v = countValue(seg.dataset.part);
      seg.style.width = total ? `${(v / total) * 100}%` : "0";
    });
    if (total > 0) hideError("attendanceCountsError");
  }

  function showError(id) {
    const el = document.getElementById(id);
    if (el) el.style.setProperty("display", "block", "important");
  }

  function hideError(id) {
    const el = document.getElementById(id);
    if (el) el.style.setProperty("display", "none", "important");
  }

  function clearErrors() {
    document.querySelectorAll(`#${MODAL_ID} .is-invalid`).forEach((el) => el.classList.remove("is-invalid"));
    ["attendanceGatheringTypeError", "attendanceCountsError"].forEach(hideError);
  }

  /** Mark fields invalid from a backend 422 `errors` map. */
  function showBackendErrors(errors) {
    Object.entries(errors || {}).forEach(([field, messages]) => {
      const input = document.getElementById(FIELD_INPUTS[field]);
      if (!input) return;
      input.classList.add("is-invalid");
      const feedback = input.parentElement.querySelector(".invalid-feedback") || input.closest("[class*=col]")?.querySelector(".invalid-feedback");
      if (feedback) {
        feedback.textContent = Array.isArray(messages) ? messages[0] : messages;
        feedback.style.setProperty("display", "block", "important");
      }
    });
  }

  function ensureModalMounted() {
    if (document.getElementById(MODAL_ID)) return;

    const count = (id, label, color) => `
      <div class="col-6">
        ${DemographicsUI.numberStepperHtml(id, { label: `<span class="count-dot bg-${color}"></span>${label}` })}
      </div>`;

    const modalHtml = `
      <div class="modal fade app-modal" id="${MODAL_ID}" tabindex="-1" aria-hidden="true" aria-labelledby="attendanceModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon"><i class="ri-calendar-check-line"></i></span>
              <div class="flex-fill" style="min-width: 0;">
                <h5 class="modal-title" id="attendanceModalTitle">Record attendance</h5>
                <div class="app-modal-subtitle" id="attendanceModalSubtitle"></div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="app-modal-section">
                <div class="row g-3">
                  <div class="col-12" id="attendanceDateCol">
                    <label for="attendanceServiceDate" class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="attendanceServiceDate" required>
                    <div class="invalid-feedback">Pick the date of this gathering.</div>
                    <div class="field-hint mt-1" id="attendanceDateHint">The Sunday or gathering date this count is for.</div>
                  </div>
                  <div class="col-12" id="attendanceGatheringTypeGroup" style="display: none;">
                    <label for="attendanceGatheringType" class="form-label">Gathering <span class="text-danger">*</span></label>
                    <select class="form-select" id="attendanceGatheringType">
                      <option value="">Loading gathering types...</option>
                    </select>
                    <div class="invalid-feedback d-block" id="attendanceGatheringTypeError" style="display: none !important;">Choose which gathering this was.</div>
                  </div>
                  <div class="col-12" id="attendanceEventNameGroup" style="display: none;">
                    <label for="attendanceEventName" class="form-label">Event name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="attendanceEventName" placeholder="e.g. Diocese Youth Camp 2026">
                    <div class="invalid-feedback">Give this event a name.</div>
                  </div>
                </div>
              </div>

              <div class="app-modal-section mb-0">
                <div class="app-modal-section-title">Who attended</div>
                <div class="row g-3">
                  ${count("attendanceAdults", "Adults", "primary")}
                  ${count("attendanceYouth", "Youth", "success")}
                  ${count("attendanceChildrenMale", "Children (male)", "purple")}
                  ${count("attendanceChildrenFemale", "Children (female)", "pink")}
                </div>
                <div class="count-total">
                  <div>
                    <div class="count-total-label">Total</div>
                    <div class="count-total-value" id="attendanceTotal">0</div>
                  </div>
                  <div class="count-bar" id="attendanceTotalBar" aria-hidden="true">
                    <span class="bg-primary" data-part="attendanceAdults"></span>
                    <span class="bg-success" data-part="attendanceYouth"></span>
                    <span class="bg-purple" data-part="attendanceChildrenMale"></span>
                    <span class="bg-pink" data-part="attendanceChildrenFemale"></span>
                  </div>
                </div>
                <div class="invalid-feedback d-block" id="attendanceCountsError" style="display: none !important;">Enter at least one count.</div>
              </div>

              <div class="mt-3">
                <label for="attendanceNotes" class="form-label">Notes</label>
                <textarea class="form-control" id="attendanceNotes" rows="2" placeholder="Anything worth remembering about this gathering..."></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" id="attendanceModalSaveBtn">
                <i class="ri-check-line me-1"></i>Save
              </button>
            </div>

            <div class="app-modal-state is-busy-view" role="status">
              <div class="app-modal-spinner"></div>
              <div class="fw-semibold">Saving...</div>
            </div>
            <div class="app-modal-state is-done-view">
              <div class="app-modal-tick"><i class="ri-check-line"></i></div>
              <div class="fs-5 fw-bold" id="attendanceDoneTitle">Attendance saved</div>
              <div class="app-modal-facts" id="attendanceDoneFacts"></div>
              <div class="d-flex flex-wrap justify-content-center gap-2">
                <button type="button" class="btn btn-light" id="attendanceAddAnotherBtn"><i class="ri-add-line me-1"></i>Add another</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
              </div>
            </div>
          </div>
        </div>
      </div>`;

    const container = document.createElement("div");
    container.innerHTML = modalHtml;
    document.body.appendChild(container.firstElementChild);

    const modalEl = document.getElementById(MODAL_ID);
    DemographicsUI.initSteppers(modalEl);
    document.getElementById("attendanceModalSaveBtn").addEventListener("click", handleModalSave);
    COUNT_FIELDS.forEach((id) => document.getElementById(id).addEventListener("input", updateTotal));
    document.getElementById("attendanceServiceDate").addEventListener("change", updateDateHint);
    // Add another: same day is a sensible default for ministries/events
    // (several can happen on one date); a Sunday only has one service.
    document.getElementById("attendanceAddAnotherBtn").addEventListener("click", () => {
      const lastDate = document.getElementById("attendanceServiceDate").value;
      openEntryModal({ ...currentConfig, record: null, defaultDate: currentConfig.isWeekly ? "" : lastDate });
    });
    // Reset the busy/success views whenever the modal closes.
    modalEl.addEventListener("hidden.bs.modal", () => modalEl.classList.remove("is-busy", "is-done"));

    const selectEl = document.getElementById("attendanceGatheringType");
    if (typeof Choices !== "undefined") {
      gatheringTypeChoices = new Choices(selectEl, {
        searchEnabled: true,
        searchPlaceholderValue: "Search gatherings...",
        itemSelectText: "",
        allowHTML: true,
        placeholder: true,
        placeholderValue: "Select a gathering",
        shouldSort: false,
      });
    }
    selectEl.addEventListener("change", handleGatheringTypeChange);
  }

  function handleGatheringTypeChange() {
    const select = document.getElementById("attendanceGatheringType");
    if (select.value) hideError("attendanceGatheringTypeError");
    const eventGroup = document.getElementById("attendanceEventNameGroup");
    eventGroup.style.display = select.value === OTHER_VALUE ? "" : "none";
  }

  /**
   * @param {object} config
   *   gatheringCategoryId: number - resolved id of Sunday Service/Ministry
   *     Gathering/Special Event, looked up by the caller via
   *     DemographicsAPIHandler.getGatheringCategories()
   *   isWeekly: boolean - true for Sunday Service, skips the gathering
   *     type/event name fields entirely
   *   territoryId: number
   *   record: existing record to edit, or null to create
   *   onSaved: callback(savedRecord) - called after a successful save
   *   defaultDate: 'YYYY-MM-DD' - pre-filled date when creating (e.g. a clicked calendar day)
   */
  async function openEntryModal(config) {
    ensureModalMounted();
    currentConfig = config;
    currentRecordId = config.record ? config.record.id : null;

    const modalEl = document.getElementById(MODAL_ID);
    modalEl.classList.remove("is-busy", "is-done");
    clearErrors();
    document.getElementById("attendanceModalTitle").textContent = config.record ? "Edit attendance" : "Record attendance";

    const record = config.record || {};
    document.getElementById("attendanceServiceDate").value = record.service_date
      ? record.service_date.substring(0, 10)
      : config.defaultDate || "";
    document.getElementById("attendanceAdults").value = record.adults_count ?? "";
    document.getElementById("attendanceYouth").value = record.youth_count ?? "";
    document.getElementById("attendanceChildrenMale").value = record.children_male_count ?? "";
    document.getElementById("attendanceChildrenFemale").value = record.children_female_count ?? "";
    document.getElementById("attendanceNotes").value = record.notes || "";
    updateTotal();
    updateDateHint();

    const typeGroup = document.getElementById("attendanceGatheringTypeGroup");
    const eventGroup = document.getElementById("attendanceEventNameGroup");
    const eventInput = document.getElementById("attendanceEventName");

    if (config.isWeekly) {
      typeGroup.style.display = "none";
      eventGroup.style.display = "none";
      eventInput.value = "";
    } else {
      typeGroup.style.display = "";
      eventGroup.style.display = record.gathering_type_id ? "none" : record.event_name ? "" : "none";
      eventInput.value = record.event_name || "";

      if (gatheringTypeChoices) {
        gatheringTypeChoices.disable();
        gatheringTypeChoices.clearChoices();
        gatheringTypeChoices.setChoices(
          [{ value: "", label: "Loading gathering types...", disabled: true, selected: true }],
          "value",
          "label",
          true,
        );
      }

      bootstrap.Modal.getOrCreateInstance(modalEl).show();

      // No saving until the gathering list has loaded - otherwise a quick
      // Save on a slow connection fails with "choose a gathering".
      const saveBtn = document.getElementById("attendanceModalSaveBtn");
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';
      await populateGatheringTypeSelect(config.territoryId, config.gatheringCategoryId, record.gathering_type_id || null, !!config.record);
      saveBtn.disabled = false;
      saveBtn.innerHTML = '<i class="ri-check-line me-1"></i>Save';
      return;
    }

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  async function populateGatheringTypeSelect(territoryId, gatheringCategoryId, selectedTypeId, isEdit = false) {
    const select = document.getElementById("attendanceGatheringType");
    const result = await DemographicsAPIHandler.getGatheringTypes(territoryId, { gathering_category_id: gatheringCategoryId });

    const types = result.success ? result.data || [] : [];
    const choices = types.map((t) => ({
      value: String(t.id),
      // allowHTML: true (set on the Choices instance) renders this as
      // markup, not escaped text - the icon-per-gathering-type "template"
      // so a user recognizes Kesha vs. Tuesday Fellowship at a glance.
      label: `<i class="${escapeHtml(t.icon || "ri-calendar-event-line")} me-2"></i>${escapeHtml(t.name)}`,
      customProperties: { plainName: t.name },
    }));

    choices.push({ value: OTHER_VALUE, label: '<i class="ri-more-line me-2"></i>Other (type your own)' });

    if (types.length === 0) {
      choices.unshift({
        value: "",
        label: "No gathering types configured yet - use Other, or add some in Gathering Types",
        disabled: true,
      });
    }

    // New entries start on the first real gathering type (not "Other");
    // edits keep what was saved (a type, or Other for a named event).
    const initial = selectedTypeId ? String(selectedTypeId) : !isEdit && types.length ? String(types[0].id) : OTHER_VALUE;

    if (gatheringTypeChoices) {
      gatheringTypeChoices.enable();
      gatheringTypeChoices.clearChoices();
      gatheringTypeChoices.setChoices(choices, "value", "label", true);
      gatheringTypeChoices.setChoiceByValue(initial);
    } else {
      // Choices.js failed to load (e.g. script tag missing on this page) -
      // fall back to the plain <select> so entry still works.
      select.innerHTML = choices
        .map((c) => `<option value="${c.value}" ${c.disabled ? "disabled" : ""}>${c.label.replace(/<[^>]+>/g, "")}</option>`)
        .join("");
      select.value = initial;
    }

    handleGatheringTypeChange();
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function handleModalSave() {
    clearErrors();
    let valid = true;

    const dateInput = document.getElementById("attendanceServiceDate");
    const dateVal = dateInput.value;
    if (!dateVal) {
      dateInput.classList.add("is-invalid");
      valid = false;
    }

    const payload = {
      territory_id: currentConfig.territoryId,
      service_date: dateVal,
      gathering_category_id: currentConfig.gatheringCategoryId,
      gathering_type_id: null,
      event_name: null,
      adults_count: numOrNull("attendanceAdults"),
      youth_count: numOrNull("attendanceYouth"),
      children_male_count: numOrNull("attendanceChildrenMale"),
      children_female_count: numOrNull("attendanceChildrenFemale"),
      notes: document.getElementById("attendanceNotes").value.trim() || null,
    };

    let gatheringName = categoryLabel();
    if (!currentConfig.isWeekly) {
      const selectedValue = document.getElementById("attendanceGatheringType").value;

      if (!selectedValue) {
        showError("attendanceGatheringTypeError");
        valid = false;
      } else if (selectedValue === OTHER_VALUE) {
        const eventInput = document.getElementById("attendanceEventName");
        const eventName = eventInput.value.trim();
        if (!eventName) {
          eventInput.classList.add("is-invalid");
          valid = false;
        }
        payload.event_name = eventName;
        gatheringName = eventName || gatheringName;
      } else {
        payload.gathering_type_id = parseInt(selectedValue, 10);
        const selected = gatheringTypeChoices ? gatheringTypeChoices.getValue() : null;
        gatheringName = selected?.customProperties?.plainName || gatheringName;
      }
    }

    const total = COUNT_FIELDS.map(countValue).reduce((a, b) => a + b, 0);
    if (total === 0) {
      showError("attendanceCountsError");
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

    if (!result.success) {
      modalEl.classList.remove("is-busy");
      showBackendErrors(result.errors);
      Toast.error(result.message || "Failed to save attendance record");
      return;
    }

    const summary = `${gatheringName}, ${formatLongDate(dateVal)} - ${total.toLocaleString()} ${total === 1 ? "person" : "people"}`;
    if (currentConfig.onSaved) currentConfig.onSaved(result.data);

    if (isEdit) {
      modalEl.classList.remove("is-busy");
      bootstrap.Modal.getInstance(modalEl).hide();
      Toast.success(`Updated - ${summary}`);
      return;
    }

    // New entry: show a success view with a summary and "Add another".
    document.getElementById("attendanceDoneTitle").textContent = "Attendance recorded";
    document.getElementById("attendanceDoneFacts").innerHTML = [
      DemographicsUI.pill(escapeHtml(gatheringName), "primary", "ri-calendar-check-line"),
      DemographicsUI.pill(formatLongDate(dateVal), "purple", "ri-calendar-line"),
      DemographicsUI.pill(`${total.toLocaleString()} attended`, "success", "ri-group-line"),
    ].join("");
    modalEl.classList.remove("is-busy");
    modalEl.classList.add("is-done");
    Toast.success(`Saved - ${summary}`);
  }

  function numOrNull(id) {
    const val = document.getElementById(id).value;
    return val === "" ? null : parseInt(val, 10);
  }

  // ==========================================================================
  // LIST TABLE (ministries.php / events.php)
  // ==========================================================================

  /**
   * Rows for a gathering list (Ministries / Special Events). `rows` must be
   * newest-first - each row's change pill compares it with the previous
   * gathering of the same ministry/event further down the list.
   * Cells carry data-order/data-search so sorting and the Ministry filter
   * work on the raw value, not the decorated markup.
   */
  function renderListRows(rows, { onEdit } = {}) {
    const UI = DemographicsUI;
    if (!rows || rows.length === 0) {
      return UI.renderTableEmpty(5, "No records yet - add your first entry", "ri-calendar-line");
    }

    return rows
      .map((row, index) => {
        const d = new Date(row.service_date);
        const date = d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
        const weekday = d.toLocaleDateString("en-GB", { weekday: "long" });
        const iso = String(row.service_date).substring(0, 10);
        const total = recordTotal(row);
        const icon = row.gathering_type?.icon || row.gathering_category?.icon || "ri-calendar-event-line";
        const label = row.gathering_type?.name || row.event_name || "-";
        const color = UI.colorFor(label);

        const sameKey = (r) => (r.gathering_type_id || r.event_name) === (row.gathering_type_id || row.event_name);
        const previous = rows.slice(index + 1).find(sameKey);
        const children = (row.children_male_count || 0) + (row.children_female_count || 0);
        const breakdown = [
          row.adults_count ? `${row.adults_count} adults` : "",
          row.youth_count ? `${row.youth_count} youth` : "",
          children ? `${children} children` : "",
        ]
          .filter(Boolean)
          .join(" · ");

        const notes = row.notes ? escapeHtml(row.notes) : "";
        const editBtn = onEdit
          ? `<button type="button" class="btn btn-sm btn-primary-light" onclick="${onEdit}(${row.id})" title="Edit entry" aria-label="Edit entry">
               <i class="ri-edit-line"></i>
             </button>`
          : "";

        return `
          <tr data-date="${iso}" data-row-id="${row.id}">
            <td data-order="${iso}">
              <div class="fw-semibold">${date}</div>
              <div class="fs-12 text-muted">${weekday}</div>
            </td>
            <td data-search="${escapeHtml(label)}">
              <div class="d-flex align-items-center gap-2">
                ${UI.avatarTile(icon, color)}
                <span class="fw-semibold">${escapeHtml(label)}</span>
              </div>
            </td>
            <td data-order="${total}">
              <span class="fw-bold fs-15">${total.toLocaleString()}</span>${UI.changePill(total, previous ? recordTotal(previous) : null)}
              ${breakdown ? `<div class="fs-12 text-muted">${breakdown}</div>` : ""}
            </td>
            <td class="list-notes">${notes ? `<span title="${notes}">${notes}</span>` : '<span class="text-muted">-</span>'}</td>
            <td class="text-end">${editBtn}</td>
          </tr>`;
      })
      .join("");
  }

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
    return (row.adults_count || 0) + (row.youth_count || 0) + (row.children_male_count || 0) + (row.children_female_count || 0);
  }

  /**
   * Stat cards for a gathering list page (Ministries / Special Events), each
   * with a this-month vs last-month comparison computed from `rows` (every
   * record for this category, already loaded). Mixed colours on purpose.
   * @param {string} containerId
   * @param {object[]} rows
   * @param {object} labels {noun: "Ministry"|"Event", plural: "gatherings"|"events"}
   */
  function renderGatheringStats(containerId, rows, { noun = "Ministry", plural = "gatherings" } = {}) {
    const UI = DemographicsUI;
    const thisMonth = UI.rowsInMonth(rows, 0);
    const lastMonth = UI.rowsInMonth(rows, 1);
    const prevLabel = UI.monthLabel(1);
    const sum = (list) => list.reduce((acc, r) => acc + recordTotal(r), 0);
    const avg = (list) => (list.length ? Math.round(sum(list) / list.length) : 0);

    // Most active type this month (by attendance), falling back to all-time.
    const byType = {};
    (thisMonth.length ? thisMonth : rows).forEach((r) => {
      const name = r.gathering_type?.name || r.event_name || "Other";
      byType[name] = (byType[name] || 0) + recordTotal(r);
    });
    const top = Object.entries(byType).sort((a, b) => b[1] - a[1])[0];

    UI.renderStatCardsRow(containerId, [
      {
        icon: "ri-group-line",
        label: `Attendance This Month`,
        value: sum(thisMonth).toLocaleString(),
        color: "primary",
        delta: UI.periodDelta(sum(thisMonth), sum(lastMonth), { prevLabel }),
        series: UI.monthlySeries(rows, { value: recordTotal }),
      },
      {
        icon: "ri-calendar-check-line",
        label: `${plural.charAt(0).toUpperCase() + plural.slice(1)} This Month`,
        value: thisMonth.length,
        color: "success",
        delta: UI.periodDelta(thisMonth.length, lastMonth.length, { percent: false, prevLabel }),
      },
      {
        icon: "ri-bar-chart-2-line",
        label: `Avg per ${noun === "Ministry" ? "Gathering" : "Event"}`,
        value: avg(thisMonth),
        color: "purple",
        delta: UI.periodDelta(avg(thisMonth), avg(lastMonth), { prevLabel }),
      },
      {
        icon: "ri-trophy-line",
        label: `Most Active ${noun}`,
        value: top ? escapeHtml(top[0]) : "-",
        color: "secondary",
        trend: top ? `${top[1].toLocaleString()} attended${thisMonth.length ? " this month" : ""}` : `No ${plural} yet`,
      },
    ]);
  }

  return { openEntryModal, renderListRows, renderGatheringStats, recordTotal };
})();

window.AttendanceFormShared = AttendanceFormShared;
