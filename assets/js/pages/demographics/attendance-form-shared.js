/**
 * ============================================================================
 * SHARED - ATTENDANCE ENTRY MODAL + LIST TABLE
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One entry modal + one list-table renderer, reused by all three Attendance
 * entry pages (services.php's calendar, ministries.php, events.php) -
 * avoids three near-duplicate 300-line files for what's really one form
 * shape parameterized by service_type.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, Bootstrap 5
 * ============================================================================
 */

const AttendanceFormShared = (function () {
  "use strict";

  const MODAL_ID = "attendanceEntryModal";
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
  }

  /**
   * @param {object} config
   *   serviceType: 'sunday_service'|'ministry_gathering'|'special_event'
   *   territoryId: number
   *   eventNameRequired: boolean
   *   record: existing record to edit, or null to create
   *   onSaved: callback(savedRecord) - called after a successful save
   *   defaultDate: 'YYYY-MM-DD' - pre-filled date when creating (e.g. a clicked calendar day)
   */
  function openEntryModal(config) {
    ensureModalMounted();
    currentConfig = config;
    currentRecordId = config.record ? config.record.id : null;

    document.getElementById("attendanceModalTitle").textContent = config.record ? "Edit Attendance" : "Record Attendance";

    const eventGroup = document.getElementById("attendanceEventNameGroup");
    eventGroup.style.display = config.eventNameRequired ? "" : "none";

    const record = config.record || {};
    document.getElementById("attendanceServiceDate").value = record.service_date
      ? record.service_date.substring(0, 10)
      : config.defaultDate || "";
    document.getElementById("attendanceEventName").value = record.event_name || "";
    document.getElementById("attendanceAdults").value = record.adults_count ?? "";
    document.getElementById("attendanceYouth").value = record.youth_count ?? "";
    document.getElementById("attendanceChildrenMale").value = record.children_male_count ?? "";
    document.getElementById("attendanceChildrenFemale").value = record.children_female_count ?? "";
    document.getElementById("attendanceNotes").value = record.notes || "";

    const modalEl = document.getElementById(MODAL_ID);
    new bootstrap.Modal(modalEl).show();
  }

  async function handleModalSave() {
    const dateVal = document.getElementById("attendanceServiceDate").value;
    if (!dateVal) {
      Toast.warning("Please select a date");
      return;
    }

    if (currentConfig.eventNameRequired && !document.getElementById("attendanceEventName").value.trim()) {
      Toast.warning("Please enter an event name");
      return;
    }

    const payload = {
      territory_id: currentConfig.territoryId,
      service_date: dateVal,
      service_type: currentConfig.serviceType,
      event_name: document.getElementById("attendanceEventName").value.trim() || null,
      adults_count: numOrNull("attendanceAdults"),
      youth_count: numOrNull("attendanceYouth"),
      children_male_count: numOrNull("attendanceChildrenMale"),
      children_female_count: numOrNull("attendanceChildrenFemale"),
      notes: document.getElementById("attendanceNotes").value.trim() || null,
    };

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
        return `
          <tr>
            <td class="fw-semibold">${date}</td>
            <td>${row.event_name || "-"}</td>
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

  return { openEntryModal, renderListRows };
})();

window.AttendanceFormShared = AttendanceFormShared;
