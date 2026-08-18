/**
 * PAGE - SPECIAL EVENTS ATTENDANCE (church/attendance/events.php)
 * Thin wrapper around AttendanceFormShared, filtered to service_type=special_event.
 */

const AttendanceEvents = (function () {
  "use strict";

  const SERVICE_TYPE = "special_event";
  let allRows = [];

  function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    if (CAN_WRITE_ATTENDANCE) {
      document.getElementById("addEntryBtn").addEventListener("click", () => openModal(null));
    }

    loadList();
  }

  async function loadList() {
    const tbody = document.getElementById("attendanceTableBody");
    tbody.innerHTML = DemographicsUI.renderTableLoading(5, "Loading special event attendance...");

    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { service_type: SERVICE_TYPE });

    if (!result.success) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(5, "Could not load records");
      return;
    }

    allRows = (result.data || []).sort((a, b) => new Date(b.service_date) - new Date(a.service_date));
    tbody.innerHTML = AttendanceFormShared.renderListRows(allRows, { onEdit: "AttendanceEvents.editRow" });
  }

  function openModal(record) {
    AttendanceFormShared.openEntryModal({
      serviceType: SERVICE_TYPE,
      territoryId: USER_TERRITORY.id,
      eventNameRequired: true,
      record,
      onSaved: loadList,
    });
  }

  function editRow(id) {
    const record = allRows.find((r) => r.id === id);
    if (record) openModal(record);
  }

  return { init, editRow };
})();

window.AttendanceEvents = AttendanceEvents;
