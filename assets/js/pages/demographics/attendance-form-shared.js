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

  function ensureModalMounted() {
    if (document.getElementById(MODAL_ID)) return;

    const modalHtml = `
      <div class="modal fade" id="${MODAL_ID}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="attendanceModalTitle">Record Attendance</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label for="attendanceServiceDate" class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="attendanceServiceDate" required>
              </div>
              <div class="mb-3" id="attendanceGatheringTypeGroup" style="display: none;">
                <label for="attendanceGatheringType" class="form-label">Gathering <span class="text-danger">*</span></label>
                <select class="form-select" id="attendanceGatheringType" disabled>
                  <option value="">Loading gathering types...</option>
                </select>
              </div>
              <div class="mb-3" id="attendanceEventNameGroup" style="display: none;">
                <label for="attendanceEventName" class="form-label">Event Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="attendanceEventName">
              </div>
              <div class="row gy-3">
                <div class="col-md-6">${DemographicsUI.numberStepperHtml("attendanceAdults", { label: "Adults" })}</div>
                <div class="col-md-6">${DemographicsUI.numberStepperHtml("attendanceYouth", { label: "Youth" })}</div>
                <div class="col-md-6">${DemographicsUI.numberStepperHtml("attendanceChildrenMale", { label: "Children (Male)" })}</div>
                <div class="col-md-6">${DemographicsUI.numberStepperHtml("attendanceChildrenFemale", { label: "Children (Female)" })}</div>
              </div>
              <div class="mt-3">
                <label for="attendanceNotes" class="form-label">Notes</label>
                <textarea class="form-control" id="attendanceNotes" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" id="attendanceModalSaveBtn">
                <i class="ri-save-line me-1"></i>Save
              </button>
            </div>
          </div>
        </div>
      </div>`;

    const container = document.createElement("div");
    container.innerHTML = modalHtml;
    document.body.appendChild(container.firstElementChild);

    DemographicsUI.initSteppers(document.getElementById(MODAL_ID));
    document.getElementById("attendanceModalSaveBtn").addEventListener("click", handleModalSave);
    document.getElementById("attendanceGatheringType").addEventListener("change", handleGatheringTypeChange);
  }

  function handleGatheringTypeChange() {
    const select = document.getElementById("attendanceGatheringType");
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

    document.getElementById("attendanceModalTitle").textContent = config.record ? "Edit Attendance" : "Record Attendance";

    const record = config.record || {};
    document.getElementById("attendanceServiceDate").value = record.service_date
      ? record.service_date.substring(0, 10)
      : config.defaultDate || "";
    document.getElementById("attendanceAdults").value = record.adults_count ?? "";
    document.getElementById("attendanceYouth").value = record.youth_count ?? "";
    document.getElementById("attendanceChildrenMale").value = record.children_male_count ?? "";
    document.getElementById("attendanceChildrenFemale").value = record.children_female_count ?? "";
    document.getElementById("attendanceNotes").value = record.notes || "";

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

      const select = document.getElementById("attendanceGatheringType");
      select.disabled = true;
      select.innerHTML = '<option value="">Loading gathering types...</option>';

      const modalEl = document.getElementById(MODAL_ID);
      new bootstrap.Modal(modalEl).show();

      await populateGatheringTypeSelect(config.territoryId, config.gatheringCategoryId, record.gathering_type_id || null);
      return;
    }

    const modalEl = document.getElementById(MODAL_ID);
    new bootstrap.Modal(modalEl).show();
  }

  async function populateGatheringTypeSelect(territoryId, gatheringCategoryId, selectedTypeId) {
    const select = document.getElementById("attendanceGatheringType");
    const result = await DemographicsAPIHandler.getGatheringTypes(territoryId, { gathering_category_id: gatheringCategoryId });

    const types = result.success ? result.data || [] : [];
    const options = types.map((t) => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join("");
    const otherOption = `<option value="${OTHER_VALUE}">Other (type your own)</option>`;
    const hint = types.length === 0
      ? `<option value="" disabled>No gathering types configured yet - use Other, or add some in Gathering Types</option>`
      : "";

    select.innerHTML = hint + options + otherOption;
    select.disabled = false;
    select.value = selectedTypeId ? String(selectedTypeId) : OTHER_VALUE;
    handleGatheringTypeChange();
  }

  async function handleModalSave() {
    const dateVal = document.getElementById("attendanceServiceDate").value;
    if (!dateVal) {
      Toast.warning("Please select a date");
      return;
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

    if (!currentConfig.isWeekly) {
      const selectedValue = document.getElementById("attendanceGatheringType").value;

      if (!selectedValue) {
        Toast.warning("Please select a gathering");
        return;
      }

      if (selectedValue === OTHER_VALUE) {
        const eventName = document.getElementById("attendanceEventName").value.trim();
        if (!eventName) {
          Toast.warning("Please enter an event name");
          return;
        }
        payload.event_name = eventName;
      } else {
        payload.gathering_type_id = parseInt(selectedValue, 10);
      }
    }

    const btn = document.getElementById("attendanceModalSaveBtn");
    DemographicsUI.setButtonLoading(btn, "Saving...");

    const result = currentRecordId
      ? await DemographicsAPIHandler.updateAttendance(currentRecordId, payload)
      : await DemographicsAPIHandler.createAttendance(payload);

    DemographicsUI.restoreButton(btn);

    if (!result.success) {
      Toast.error(result.message || "Failed to save attendance record");
      return;
    }

    Toast.success(currentRecordId ? "Attendance updated" : "Attendance recorded");
    bootstrap.Modal.getInstance(document.getElementById(MODAL_ID)).hide();

    if (currentConfig.onSaved) currentConfig.onSaved(result.data);
  }

  function numOrNull(id) {
    const val = document.getElementById(id).value;
    return val === "" ? null : parseInt(val, 10);
  }

  // ==========================================================================
  // LIST TABLE (ministries.php / events.php)
  // ==========================================================================

  function renderListRows(rows, { onEdit } = {}) {
    if (!rows || rows.length === 0) {
      return DemographicsUI.renderTableEmpty(5, "No records yet", "ri-calendar-line");
    }

    return rows
      .map((row) => {
        const date = new Date(row.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
        const total = (row.adults_count || 0) + (row.youth_count || 0) + (row.children_male_count || 0) + (row.children_female_count || 0);
        const icon = row.gathering_type?.icon || row.gathering_category?.icon || "ri-calendar-event-line";
        const label = row.gathering_type?.name || row.event_name || "-";
        return `
          <tr>
            <td class="fw-semibold">${date}</td>
            <td><i class="${icon} me-1 text-primary"></i>${escapeHtml(label)}</td>
            <td>${total}</td>
            <td>${row.notes || "-"}</td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-primary-light" onclick="${onEdit}(${row.id})">
                <i class="ri-edit-line me-1"></i>Edit
              </button>
            </td>
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

  return { openEntryModal, renderListRows };
})();

window.AttendanceFormShared = AttendanceFormShared;
