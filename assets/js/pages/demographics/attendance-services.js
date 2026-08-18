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

    const addBtn = document.getElementById("addAttendanceBtn");
    if (addBtn) {
      addBtn.addEventListener("click", () => {
        if (entryMode === "monthly_only") {
          Toast.info("Weekly entry is off for this church - use the monthly Demographics form instead.");
          return;
        }
        const dateStr = mostRecentSundayOnOrBefore(new Date());
        const existing = allRows.find((r) => r.service_date.substring(0, 10) === dateStr);
        openEntry(existing || null, dateStr);
      });
    }
  }

  /** Discoverable entry point for the "Add Attendance" button - not everyone
   * knows to click a Sunday on the calendar, so this defaults to the most
   * recent Sunday (today, if today is a Sunday) instead of requiring one. */
  function mostRecentSundayOnOrBefore(date) {
    const d = new Date(date);
    const day = d.getDay();
    d.setDate(d.getDate() - day);
    return d.toISOString().substring(0, 10);
  }

  async function loadEntryMode() {
    const result = await DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id);
    if (result.success) {
      entryMode = result.data.attendance_mode;
    }

    const banner = document.getElementById("entryModeBanner");
    banner.innerHTML =
      entryMode === "monthly_only"
        ? `<div class="alert alert-info bg-info-transparent border-0 mb-3">
             <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
               <div>
                 <i class="ri-information-line me-2"></i>
                 Weekly entry is off for this church - Sunday attendance isn't required here. Record the monthly totals instead.
               </div>
               <div class="d-flex gap-2 flex-shrink-0">
                 <a href="${AppConfig.FRONTEND_BASE_URL}/church/demographics-growth/demographics-tracking" class="btn btn-primary btn-sm">
                   <i class="ri-file-list-3-line me-1"></i>Go to Monthly Form
                 </a>
                 <a href="${AppConfig.FRONTEND_BASE_URL}/church/attendance" class="btn btn-light btn-sm border">Change This</a>
               </div>
             </div>
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

  /** "3 days ago" / "2 weeks ago" style caption for the timeline - a plain
   * date is enough on the calendar, but a timeline reads better with a
   * sense of recency. */
  function timeAgo(dateStr) {
    const days = Math.floor((new Date() - new Date(dateStr + "T00:00:00")) / 86400000);
    if (days <= 0) return "Today";
    if (days === 1) return "Yesterday";
    if (days < 7) return `${days} days ago`;
    const weeks = Math.floor(days / 7);
    return weeks === 1 ? "1 week ago" : `${weeks} weeks ago`;
  }

  function renderRecentList() {
    const container = document.getElementById("recentSundaysTimeline");
    const recent = [...allRows].sort((a, b) => new Date(b.service_date) - new Date(a.service_date)).slice(0, 8);

    if (recent.length === 0) {
      container.innerHTML = `
        <li class="text-center py-4">
          <i class="ri-calendar-2-line fs-30 text-primary mb-2 d-block"></i>
          <p class="text-body fw-semibold mb-0">No Sundays recorded yet</p>
        </li>`;
      return;
    }

    container.innerHTML = recent
      .map((r) => {
        const date = new Date(r.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
        return `
          <li style="cursor: pointer;" onclick="AttendanceServices.editRow(${r.id})">
            <div class="d-flex align-items-top">
              <div class="me-3 flex-shrink-0">
                <span class="avatar avatar-md bg-primary text-white avatar-rounded">
                  <i class="ri-calendar-check-line fs-18"></i>
                </span>
              </div>
              <div class="flex-fill">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <h6 class="fw-semibold mb-0">${date}</h6>
                  <span class="fs-11 text-body fw-semibold">${timeAgo(r.service_date.substring(0, 10))}</span>
                </div>
                <p class="mb-0 fs-13 text-body fw-semibold">
                  ${totalFor(r)} attended <i class="ri-edit-line ms-1 text-primary"></i>
                </p>
              </div>
            </div>
          </li>`;
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
