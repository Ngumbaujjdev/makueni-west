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
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }
    loadStats();
    loadEntryMode();
  }

  async function loadStats() {
    const container = document.getElementById("statCardsRow");
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id);

    if (!result.success) {
      Toast.error(result.message || "Failed to load attendance data");
      return;
    }

    const rows = result.data || [];
    const now = new Date();
    const thisMonth = rows.filter((r) => {
      const d = new Date(r.service_date);
      return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    });

    const sundayServices = thisMonth.filter((r) => r.service_type === "sunday_service");
    const totalAttendance = (r) => (r.adults_count || 0) + (r.youth_count || 0) + (r.children_male_count || 0) + (r.children_female_count || 0);
    const avgSunday = sundayServices.length
      ? Math.round(sundayServices.reduce((sum, r) => sum + totalAttendance(r), 0) / sundayServices.length)
      : 0;
    const lastRecord = rows.sort((a, b) => new Date(b.service_date) - new Date(a.service_date))[0];

    const cards = [
      { icon: "ri-calendar-check-line", label: "Sunday Services This Month", value: sundayServices.length, color: "primary" },
      { icon: "ri-group-line", label: "Avg. Sunday Attendance", value: avgSunday, color: "success" },
      { icon: "ri-file-list-3-line", label: "Total Records This Month", value: thisMonth.length, color: "warning" },
      {
        icon: "ri-time-line",
        label: "Last Recorded",
        value: lastRecord ? new Date(lastRecord.service_date).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "-",
        color: "secondary",
      },
    ];

    container.innerHTML = cards
      .map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${DemographicsUI.renderStatCard(c)}</div>`)
      .join("");
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
    const result = await DemographicsAPIHandler.updateEntryMode(USER_TERRITORY.id, mode);

    if (!result.success) {
      Toast.error(result.message || "Failed to update entry mode");
      return;
    }

    Toast.success("Entry mode updated");
  }

  return { init };
})();

window.AttendanceOverview = AttendanceOverview;
