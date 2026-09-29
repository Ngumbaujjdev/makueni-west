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
    renderStats();

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

  function accentColor() {
    const rgb = getComputedStyle(document.documentElement).getPropertyValue("--primary-rgb").trim();
    return rgb ? `rgb(${rgb})` : "#2CA4BF";
  }

  function buildEventSource() {
    return allRows.map((r) => ({
      id: String(r.id),
      title: `${totalFor(r)} attended`,
      start: r.service_date.substring(0, 10),
      allDay: true,
      backgroundColor: accentColor(),
      borderColor: accentColor(),
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
    renderStats();
  }

  /** Sundays elapsed so far this month (including today, if it's a Sunday). */
  function sundaysElapsedThisMonth(now = new Date()) {
    let count = 0;
    for (let d = 1; d <= now.getDate(); d++) {
      if (new Date(now.getFullYear(), now.getMonth(), d).getDay() === 0) count++;
    }
    return count;
  }

  function renderStats() {
    const UI = DemographicsUI;
    const prevLabel = UI.monthLabel(1);
    const thisMonth = UI.rowsInMonth(allRows, 0);
    const lastMonth = UI.rowsInMonth(allRows, 1);
    const sum = (list) => list.reduce((acc, r) => acc + totalFor(r), 0);
    const avg = (list) => (list.length ? Math.round(sum(list) / list.length) : 0);
    const best = [...thisMonth].sort((a, b) => totalFor(b) - totalFor(a))[0];
    const elapsed = sundaysElapsedThisMonth();

    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-group-line",
        label: "Attendance This Month",
        value: sum(thisMonth).toLocaleString(),
        color: "primary",
        delta: UI.periodDelta(sum(thisMonth), sum(lastMonth), { prevLabel }),
        series: UI.monthlySeries(allRows, { value: totalFor }),
      },
      {
        icon: "ri-bar-chart-2-line",
        label: "Avg per Sunday",
        value: avg(thisMonth),
        color: "purple",
        delta: UI.periodDelta(avg(thisMonth), avg(lastMonth), { prevLabel }),
      },
      {
        icon: "ri-trophy-line",
        label: "Best Sunday",
        value: best ? totalFor(best) : "-",
        color: "secondary",
        trend: best ? new Date(best.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "None recorded this month",
      },
      {
        icon: "ri-calendar-check-line",
        label: "Sundays Recorded",
        value: `${thisMonth.length} of ${elapsed}`,
        // Red when a Sunday that has already happened is missing.
        color: thisMonth.length < elapsed ? "danger" : "success",
        trend: thisMonth.length < elapsed ? `${elapsed - thisMonth.length} missing` : "All caught up",
      },
    ]);
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
            <td class="fw-semibold">
              <div class="d-flex align-items-center gap-2">
                <span class="avatar avatar-sm avatar-rounded bg-primary">
                  <i class="ri-sun-line text-white"></i>
                </span>
                ${date}
              </div>
            </td>
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
