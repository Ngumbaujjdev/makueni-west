/**
 * ============================================================================
 * PAGE - MONTHLY STATISTICS (church/demographics-growth/monthly-statistics.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Plain table companion to Spiritual Activities' charts - one row per
 * reporting period, straight from the same (now cadence-aware)
 * DemographicsReportWidgetService response (`data.months` - still called
 * "months" in the API response for backward compatibility, but its rows
 * are Jan-Dec for a monthly church, H1/H2 for half-yearly, or one row for
 * yearly). A period with no approved submission shows "-" on every numeric
 * column, never a fabricated 0 - the service itself already enforces this
 * (see its own docblock), this file just renders what it's given.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI
 * ============================================================================
 */

const MonthlyStatistics = (function () {
  "use strict";

  const COLUMNS = [
    "total_members", "male_count", "female_count", "youth_count",
    "mens_fellowship_count", "womens_fellowship_count",
    "sunday_school_male_count", "sunday_school_female_count", "seniors_count",
    "new_members_count", "transferred_out_count",
    "baptisms_count", "communion_participants_count", "conversions_count",
  ];

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    await loadFiscalYears();
  }

  async function loadFiscalYears() {
    const select = document.getElementById("reportFiscalYear");
    const result = await DemographicsAPIHandler.getFiscalYears();

    if (!result.success || !result.data || result.data.length === 0) {
      select.innerHTML = '<option value="">No fiscal years configured</option>';
      return;
    }

    const years = result.data.sort((a, b) => b.year - a.year);
    select.innerHTML = years.map((y) => `<option value="${y.id}">${y.year}</option>`).join("");

    const currentYear = new Date().getFullYear();
    const defaultYear = years.find((y) => y.year === currentYear) || years[0];
    select.value = defaultYear.id;

    select.addEventListener("change", loadWidgets);
    await loadWidgets();
  }

  async function loadWidgets() {
    const fiscalYearId = document.getElementById("reportFiscalYear").value;
    if (!fiscalYearId) return;

    const result = await DemographicsAPIHandler.getDemographicsReportWidgets(USER_TERRITORY.id, { fiscal_year_id: fiscalYearId });
    const data = result.success ? result.data : null;

    renderStatCards(data?.stats || []);
    renderTable(data?.months || []);
  }

  /** Solid-icon cards, matching every other Demographics page now - the backend already sends {label, value, icon, color} per card, just mapped through the shared renderer instead of the pale-tint renderWidgetCard. */
  function renderStatCards(stats) {
    const container = document.getElementById("statCardsRow");
    if (!container) return;
    container.innerHTML = stats.map((c) => `<div class="col-xl-4 col-lg-6 col-md-6">${DemographicsUI.renderSolidStatCard(c)}</div>`).join("");
  }

  function renderTable(months) {
    const tbody = document.getElementById("monthlyStatsBody");

    if (months.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(COLUMNS.length + 2, "No fiscal months configured", "ri-calendar-line");
      return;
    }

    tbody.innerHTML = months
      .map((m) => {
        // total_members is the headline number in a 14-column table - bold
        // like every other numeric-column convention this session
        // established, so it doesn't read at the same weight as everything
        // else next to it.
        const cells = COLUMNS.map((c) => `<td class="text-end${c === "total_members" ? " fw-semibold" : ""}">${m[c] ?? "-"}</td>`).join("");
        return `
          <tr>
            <td class="fw-semibold">${m.month}</td>
            <td>${DemographicsUI.renderStatusBadge(m.status)}</td>
            ${cells}
          </tr>`;
      })
      .join("");
  }

  return { init };
})();

window.MonthlyStatistics = MonthlyStatistics;
