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

    renderStats(allRows);
    DemographicsUI.initListDataTable("ministryAttendanceTable", {
      searchPlaceholder: "Search ministry gatherings...",
      order: [[0, "desc"]],
      nonSortableColumns: [4],
    });
  }

  function renderStats(rows) {
    const now = new Date();
    const thisMonth = rows.filter((r) => {
      const d = new Date(r.service_date);
      return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    });
    const distinctTypes = new Set(rows.map((r) => r.gathering_type_id).filter(Boolean)).size;
    const mostRecent = rows[0];

    DemographicsUI.renderStatCardsRow("statCardsRow", [
      { icon: "ri-file-list-3-line", label: "Total Records", value: rows.length, color: "primary" },
      { icon: "ri-calendar-check-line", label: "This Month", value: thisMonth.length, color: "success" },
      {
        icon: "ri-time-line",
        label: "Most Recent",
        value: mostRecent ? new Date(mostRecent.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "-",
        color: "warning",
      },
      { icon: "ri-group-line", label: "Gathering Types Used", value: distinctTypes, color: "secondary" },
    ]);
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
