/**
 * PAGE - MINISTRY ATTENDANCE (church/attendance/ministries.php)
 * Thin wrapper around AttendanceFormShared, filtered to the Ministry
 * Gathering category (resolved by slug, not a hardcoded id).
 */

const AttendanceMinistries = (function () {
  "use strict";

  const CATEGORY_SLUG = "ministry_gathering";
  let categoryId = null;
  let allRows = [];

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const categoriesResult = await DemographicsAPIHandler.getGatheringCategories();
    const category = categoriesResult.success ? (categoriesResult.data || []).find((c) => c.slug === CATEGORY_SLUG) : null;

    if (!category) {
      Toast.error("Could not load gathering categories");
      return;
    }

    categoryId = category.id;

    if (CAN_WRITE_ATTENDANCE) {
      document.getElementById("addEntryBtn").addEventListener("click", () => openModal(null));
    }

    loadList();
  }

  async function loadList() {
    const tbody = document.getElementById("attendanceTableBody");
    tbody.innerHTML = DemographicsUI.renderTableLoading(5, "Loading ministry attendance...");

    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { gathering_category_id: categoryId });

    if (!result.success) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(5, "Could not load records");
      return;
    }

    allRows = (result.data || []).sort((a, b) => new Date(b.service_date) - new Date(a.service_date));
    tbody.innerHTML = AttendanceFormShared.renderListRows(allRows, { onEdit: "AttendanceMinistries.editRow" });
  }

  function openModal(record) {
    AttendanceFormShared.openEntryModal({
      gatheringCategoryId: categoryId,
      isWeekly: false,
      territoryId: USER_TERRITORY.id,
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

window.AttendanceMinistries = AttendanceMinistries;
