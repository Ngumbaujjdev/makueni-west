/**
 * ============================================================================
 * PAGE - ATTENDANCE LANDING (Overview + Entry Mode toggle)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast
 * ============================================================================
 */

const AttendanceOverview = (function () {
  "use strict";

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }
    loadStats();
    loadEntryMode();
  }

  async function loadStats() {
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id);

    if (!result.success) {
      Toast.error(result.message || "Failed to load attendance data");
      return;
    }

    const UI = DemographicsUI;
    const rows = (result.data || []).sort((a, b) => new Date(b.service_date) - new Date(a.service_date));
    const recordTotal = (r) => (r.adults_count || 0) + (r.youth_count || 0) + (r.children_male_count || 0) + (r.children_female_count || 0);
    const sundays = rows.filter((r) => r.gathering_category?.slug === "sunday_service");
    const prevLabel = UI.monthLabel(1);

    const sundaysThis = UI.rowsInMonth(sundays, 0);
    const sundaysLast = UI.rowsInMonth(sundays, 1);
    const avg = (list) => (list.length ? Math.round(list.reduce((acc, r) => acc + recordTotal(r), 0) / list.length) : 0);

    // Average Sunday attendance per month, for the sparkline.
    const sundaySums = UI.monthlySeries(sundays, { value: recordTotal });
    const sundayCounts = UI.monthlySeries(sundays);
    const avgSeries = {
      labels: sundaySums.labels,
      data: sundaySums.data.map((v, i) => (sundayCounts.data[i] ? Math.round(v / sundayCounts.data[i]) : 0)),
    };

    const recordsThis = UI.rowsInMonth(rows, 0);
    const recordsLast = UI.rowsInMonth(rows, 1);
    const lastRecord = rows[0];
    const daysAgo = lastRecord ? Math.floor((Date.now() - new Date(lastRecord.service_date)) / 86400000) : null;

    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-group-line",
        label: "Avg. Sunday Attendance",
        value: avg(sundaysThis),
        color: "primary",
        delta: UI.periodDelta(avg(sundaysThis), avg(sundaysLast), { prevLabel }),
        series: avgSeries,
      },
      {
        icon: "ri-calendar-check-line",
        label: "Sundays Recorded",
        value: sundaysThis.length,
        color: "success",
        delta: UI.periodDelta(sundaysThis.length, sundaysLast.length, { percent: false, prevLabel }),
      },
      {
        icon: "ri-file-list-3-line",
        label: "Records This Month",
        value: recordsThis.length,
        color: "purple",
        delta: UI.periodDelta(recordsThis.length, recordsLast.length, { percent: false, prevLabel }),
      },
      {
        icon: "ri-time-line",
        label: "Last Recorded",
        value: lastRecord ? new Date(lastRecord.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "-",
        // Red when nothing has been recorded for over two weeks.
        color: daysAgo != null && daysAgo > 14 ? "danger" : "secondary",
        trend: daysAgo == null ? "Nothing recorded yet" : daysAgo === 0 ? "Today" : `${daysAgo} day${daysAgo === 1 ? "" : "s"} ago`,
      },
    ]);
  }

  async function loadEntryMode() {
    const card = document.getElementById("entryModeCard");
    const result = await DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id);

    if (!result.success) {
      card.innerHTML = '<p class="text-body fw-semibold mb-0">Could not load entry mode</p>';
      return;
    }

    renderEntryModeToggle(result.data.attendance_mode);
  }

  function renderEntryModeToggle(currentMode) {
    const card = document.getElementById("entryModeCard");
    card.innerHTML = `
      <p class="text-body fw-semibold mb-3">How does this church record attendance?</p>
      <div class="form-check mb-2">
        <input class="form-check-input" type="radio" name="entryMode" id="modeWeekly" value="weekly_and_monthly"
               ${currentMode === "weekly_and_monthly" ? "checked" : ""} ${!CAN_ENTER_ATTENDANCE ? "disabled" : ""}>
        <label class="form-check-label" for="modeWeekly">
          <strong>Weekly + Monthly</strong>
          <span class="d-block fs-12 text-body">Record every Sunday service plus the monthly summary</span>
        </label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="entryMode" id="modeMonthly" value="monthly_only"
               ${currentMode === "monthly_only" ? "checked" : ""} ${!CAN_ENTER_ATTENDANCE ? "disabled" : ""}>
        <label class="form-check-label" for="modeMonthly">
          <strong>Monthly Only</strong>
          <span class="d-block fs-12 text-body">For churches with limited connectivity - skip weekly entry</span>
        </label>
      </div>`;

    if (CAN_ENTER_ATTENDANCE) {
      card.querySelectorAll('input[name="entryMode"]').forEach((radio) => {
        radio.addEventListener("change", () => handleModeChange(radio.value));
      });
    }
  }

  async function handleModeChange(mode) {
    const result = await DemographicsAPIHandler.updateEntryMode(USER_TERRITORY.id, { attendance_mode: mode });

    if (!result.success) {
      Toast.error(result.message || "Failed to update entry mode");
      return;
    }

    Toast.success("Entry mode updated");
  }

  return { init };
})();

window.AttendanceOverview = AttendanceOverview;
