/**
 * PAGE - SPECIAL EVENTS ATTENDANCE (church/attendance/events.php)
 * Thin wrapper around AttendanceFormShared, filtered to the Special Event
 * category (resolved by slug, not a hardcoded id).
 */

const AttendanceEvents = (function () {
  "use strict";

  const CATEGORY_SLUG = "special_event";
  let categoryId = null;
  let allRows = [];
  let availableTypes = [];

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

    const typesResult = await DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, { gathering_category_id: categoryId });
    availableTypes = typesResult.success ? typesResult.data || [] : [];

    if (CAN_WRITE_ATTENDANCE) {
      document.getElementById("addEntryBtn").addEventListener("click", () => openModal(null));
    }

    loadList();
  }

  async function loadList() {
    const tbody = document.getElementById("attendanceTableBody");
    tbody.innerHTML = DemographicsUI.renderTableLoading(5, "Loading special event attendance...");

    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { gathering_category_id: categoryId });

    if (!result.success) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(5, "Could not load records");
      return;
    }

    allRows = (result.data || []).sort((a, b) => new Date(b.service_date) - new Date(a.service_date));
    tbody.innerHTML = AttendanceFormShared.renderListRows(allRows, { onEdit: "AttendanceEvents.editRow" });

    renderStats(allRows);

    DemographicsUI.renderFilterToolbar("filterToolbar", {
      searchPlaceholder: "Search by event or notes...",
      filters: [
        {
          id: "eventTypeFilter",
          label: "All Event Types",
          options: availableTypes.map((t) => ({ value: t.name, label: t.name })),
        },
      ],
    });

    const table = DemographicsUI.initListDataTable("specialEventsAttendanceTable", {
      searchPlaceholder: "Search special events...",
      order: [[0, "desc"]],
      nonSortableColumns: [4],
      hideDefaultSearch: true,
    });

    DemographicsUI.wireFilterToolbar("filterToolbar", table, [{ id: "eventTypeFilter", columnIndex: 1, exact: true }]);
  }

  function renderStats(rows) {
    AttendanceFormShared.renderGatheringStats("statCardsRow", rows, { noun: "Event", plural: "events" });
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

window.AttendanceEvents = AttendanceEvents;
