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
  let calendar = null;

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

    wireViewToggle();
    loadList();
  }

  // ==========================================================================
  // LIST / CALENDAR TOGGLE
  // ==========================================================================

  function wireViewToggle() {
    document.getElementById("viewListBtn").addEventListener("click", () => switchView("list"));
    document.getElementById("viewCalendarBtn").addEventListener("click", () => switchView("calendar"));
  }

  function switchView(view) {
    const listBtn = document.getElementById("viewListBtn");
    const calendarBtn = document.getElementById("viewCalendarBtn");
    const listBody = document.getElementById("listViewBody");
    const calendarBody = document.getElementById("calendarViewBody");

    listBtn.classList.toggle("btn-primary", view === "list");
    listBtn.classList.toggle("btn-outline-primary", view !== "list");
    calendarBtn.classList.toggle("btn-primary", view === "calendar");
    calendarBtn.classList.toggle("btn-outline-primary", view !== "calendar");
    listBody.style.display = view === "list" ? "" : "none";
    calendarBody.style.display = view === "calendar" ? "" : "none";

    // Built lazily on first switch, not on page load - FullCalendar sizes
    // itself wrong if initialized inside a display:none container.
    if (view === "calendar" && !calendar) {
      calendar = AttendanceFormShared.renderCalendar("attendanceCalendar", allRows, {
        titleFn: (r) => `${r.gathering_type?.name || r.event_name || "Event"} - ${AttendanceFormShared.totalFor(r)}`,
        onDateClick: CAN_WRITE_ATTENDANCE ? (dateStr) => openModal(null, dateStr) : undefined,
        onEventClick: CAN_WRITE_ATTENDANCE ? (record) => openModal(record) : undefined,
      });
    }
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

    if (calendar) {
      AttendanceFormShared.refreshCalendarEvents(calendar, allRows, (r) => `${r.gathering_type?.name || r.event_name || "Event"} - ${AttendanceFormShared.totalFor(r)}`);
    }

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
      { icon: "ri-star-line", label: "Event Types Used", value: distinctTypes, color: "secondary" },
    ]);
  }

  function openModal(record, defaultDate) {
    AttendanceFormShared.openEntryModal({
      gatheringCategoryId: categoryId,
      isWeekly: false,
      territoryId: USER_TERRITORY.id,
      record,
      defaultDate,
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
