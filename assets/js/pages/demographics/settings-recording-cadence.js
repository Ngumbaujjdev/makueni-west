/**
 * ============================================================================
 * PAGE - DEMOGRAPHICS SETTINGS > RECORDING CADENCE
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * The mode picker itself (radio toggle backed by GET/PUT
 * churches/{id}/entry-mode) - moved here from a Growth Overview dashboard
 * card (2026-09-09) onto its own Settings page, mirroring Attendance
 * Settings > Gathering Types. Growth Overview now only displays the current
 * mode read-only and links here to change it - this page is the one place
 * that actually writes it.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast
 * ============================================================================
 */

const DemographicsSettingsRecordingCadence = (function () {
  "use strict";

  function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));
    loadDemographicsMode();
  }

  async function loadDemographicsMode() {
    const card = document.getElementById("demographicsModeCard");
    const result = await DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id);

    if (!result.success) {
      card.innerHTML = '<p class="text-body fw-semibold mb-0">Could not load recording cadence</p>';
      return;
    }

    renderDemographicsModeToggle(result.data.demographics_mode);
  }

  function renderDemographicsModeToggle(currentMode) {
    const card = document.getElementById("demographicsModeCard");
    const options = [
      { value: "monthly", label: "Monthly", hint: "One submission every fiscal month" },
      { value: "half_yearly", label: "Half-Yearly", hint: "One submission per half-year (H1/H2) - the default" },
      { value: "yearly", label: "Yearly", hint: "One submission per fiscal year" },
    ];

    card.innerHTML = `
      <p class="text-body fw-semibold mb-3">How often does this church record demographics?</p>
      ${options
        .map(
          (o, i) => `
        <div class="form-check ${i < options.length - 1 ? "mb-2" : ""}">
          <input class="form-check-input" type="radio" name="demographicsMode" id="demoMode_${o.value}" value="${o.value}"
                 ${currentMode === o.value ? "checked" : ""} ${!CAN_WRITE_RECORDING_CADENCE ? "disabled" : ""}>
          <label class="form-check-label" for="demoMode_${o.value}">
            <strong>${o.label}</strong>
            <span class="d-block fs-12 text-body">${o.hint}</span>
          </label>
        </div>`,
        )
        .join("")}`;

    if (CAN_WRITE_RECORDING_CADENCE) {
      card.querySelectorAll('input[name="demographicsMode"]').forEach((radio) => {
        radio.addEventListener("change", () => handleDemographicsModeChange(radio.value));
      });
    }
  }

  async function handleDemographicsModeChange(mode) {
    const result = await DemographicsAPIHandler.updateEntryMode(USER_TERRITORY.id, { demographics_mode: mode });

    if (!result.success) {
      Toast.error(result.message || "Failed to update recording cadence");
      return;
    }

    Toast.success("Recording cadence updated");
  }

  return { init };
})();

window.DemographicsSettingsRecordingCadence = DemographicsSettingsRecordingCadence;
