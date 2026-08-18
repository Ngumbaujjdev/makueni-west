/**
 * ============================================================================
 * PAGE - SUNDAY SERVICE ATTENDANCE (church/attendance/services.php)
 * ============================================================================
 * FullCalendar month view - click a Sunday to record that week's attendance,
 * click an existing event to edit it. Respects the church's entry-mode
 * setting (weekly_and_monthly vs monthly_only). Sunday columns get a
 * persistent subtle teal tint (see renderCalendar's dayCellClassNames) so
 * the weekly cadence reads even before any event exists yet.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, AttendanceFormShared,
 * Toast, FullCalendar v5 (assets/libs/fullcalendar/main.min.js)
 * ============================================================================
 */

const AttendanceServices = (function () {
  "use strict";

  const CATEGORY_SLUG = "sunday_service";
  let categoryId = null;
  let calendar = null;
  let allRows = [];
  let entryMode = "weekly_and_monthly";

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

    await loadEntryMode();
    await loadRecords();
    renderCalendar();
    renderRecentList();
  }

  async function loadEntryMode() {
    const result = await DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id);
    if (result.success) {
      entryMode = result.data.attendance_mode;
    }

    const banner = document.getElementById("entryModeBanner");
    banner.innerHTML =
      entryMode === "monthly_only"
        ? `<div class="alert alert-info mb-3">
             <i class="ri-information-line me-2"></i>
             Weekly entry is off for this church - Sunday attendance isn't required, only the monthly Demographics form.
             <a href="${AppConfig.FRONTEND_BASE_URL}/church/attendance" class="alert-link">Change this in Attendance settings</a>.
           </div>`
        : "";
  }

  async function loadRecords() {
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { gathering_category_id: categoryId });
    allRows = result.success ? result.data || [] : [];
  }

  function totalFor(row) {
    return (row.adults_count || 0) + (row.youth_count || 0) + (row.children_male_count || 0) + (row.children_female_count || 0);
  }

  function buildEventSource() {
    return allRows.map((r) => ({
      id: String(r.id),
      title: `${totalFor(r)} attended`,
      start: r.service_date.substring(0, 10),
      allDay: true,
      backgroundColor: "#2CA4BF",
      borderColor: "#2CA4BF",
      extendedProps: { record: r },
    }));
  }

  function renderCalendar() {
    const el = document.getElementById("attendanceCalendar");

    calendar = new FullCalendar.Calendar(el, {
      initialView: "dayGridMonth",
      headerToolbar: { left: "prev,next today", center: "title", right: "" },
      height: "auto",
      events: buildEventSource(),
      // Sunday-highlight UX: tint every Sunday cell so the weekly cadence
      // reads before any event dot appears, not just after data exists.
      dayCellClassNames: (arg) => (arg.date.getDay() === 0 ? ["fc-sunday-highlight"] : []),
      dateClick: (info) => {
        if (!CAN_WRITE_ATTENDANCE) return;

        if (entryMode === "monthly_only") {
          Toast.info("Weekly entry is off for this church - use the monthly Demographics form instead.");
          return;
        }

        if (new Date(info.dateStr + "T00:00:00").getDay() !== 0) {
          Toast.warning("Please select a Sunday");
          return;
        }

        const existing = allRows.find((r) => r.service_date.substring(0, 10) === info.dateStr);
        openEntry(existing || null, info.dateStr);
      },
      eventClick: (info) => {
        if (!CAN_WRITE_ATTENDANCE) return;
        openEntry(info.event.extendedProps.record, null);
      },
    });

    calendar.render();
  }

  async function refreshCalendar() {
    await loadRecords();
    calendar.removeAllEventSources();
    calendar.addEventSource(buildEventSource());
    renderRecentList();
  }

  function openEntry(record, defaultDate) {
    AttendanceFormShared.openEntryModal({
      gatheringCategoryId: categoryId,
      isWeekly: true,
      territoryId: USER_TERRITORY.id,
      record,
      defaultDate,
      onSaved: refreshCalendar,
    });
  }

  function renderRecentList() {
    const tbody = document.getElementById("recentSundaysBody");
    const recent = [...allRows].sort((a, b) => new Date(b.service_date) - new Date(a.service_date)).slice(0, 8);

    if (recent.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(2, "No Sundays recorded yet", "ri-calendar-2-line");
      return;
    }

    tbody.innerHTML = recent
      .map((r) => {
        const date = new Date(r.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short" });
        return `
          <tr style="cursor: pointer;" onclick="AttendanceServices.editRow(${r.id})">
            <td class="fw-semibold">${date}</td>
            <td class="text-end">${totalFor(r)} <i class="ri-edit-line ms-1 text-primary"></i></td>
          </tr>`;
      })
      .join("");
  }

  function editRow(id) {
    const record = allRows.find((r) => r.id === id);
    if (record) openEntry(record, null);
  }

  return { init, editRow };
})();

window.AttendanceServices = AttendanceServices;
