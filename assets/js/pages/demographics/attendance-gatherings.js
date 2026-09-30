/**
 * ============================================================================
 * PAGE - MINISTRIES / SPECIAL EVENTS (church/attendance/ministries.php, events.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One script for both pages (they were two near-identical files), set up by
 * the page's GATHERING_PAGE config: {slug, noun, plural, categoryLabel, icon}.
 *
 *   - KPI cards (this month vs last month, with sparklines)
 *   - a card per ministry/event: times met this year, average, last met and
 *     a status (Active / Quiet 60+ days / Not held yet). Clicking one filters
 *     the records table to it.
 *   - the records table with search, gathering and date-range filters.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, AttendanceFormShared,
 * Toast, jQuery + DataTables + Select2, ApexCharts
 * ============================================================================
 */

const AttendanceGatherings = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AttendanceFormShared;
  const PAGE = window.GATHERING_PAGE;

  let category = null;
  let types = [];
  let allRows = [];
  let summaries = [];

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const categoriesResult = await DemographicsAPIHandler.getGatheringCategories();
    category = categoriesResult.success ? (categoriesResult.data || []).find((c) => c.slug === PAGE.slug) : null;
    if (!category) {
      Toast.error("Could not load gathering categories");
      return;
    }

    const typesResult = await DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, { gathering_category_id: category.id });
    types = typesResult.success ? typesResult.data || [] : [];

    document.getElementById("addEntryBtn")?.addEventListener("click", () => openModal(null));
    await loadList();
  }

  async function loadList() {
    const tbody = document.getElementById("attendanceTableBody");
    if (!allRows.length) tbody.innerHTML = UI.renderTableLoading(5, `Loading ${PAGE.plural}...`);

    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { gathering_category_id: category.id });
    if (!result.success) {
      tbody.innerHTML = UI.renderTableEmpty(5, "Couldn't load the records");
      Toast.error(result.message || "Couldn't load the records");
      return;
    }

    allRows = (result.data || []).sort((a, b) => A.recordIso(b).localeCompare(A.recordIso(a)));
    summaries = A.summarizeGatherings(allRows, types);

    A.renderGatheringStats("statCardsRow", allRows, { noun: PAGE.noun, plural: PAGE.plural });
    renderCards();
    renderTable();
  }

  function activeFilter() {
    return document.getElementById("gatheringFilter")?.value || new URLSearchParams(window.location.search).get("gatheringFilter") || "";
  }

  function renderCards() {
    const quiet = summaries.filter((g) => g.status.key === "quiet").length;
    document.getElementById("gatheringCardsSubtitle").textContent = summaries.length
      ? `${summaries.length} ${summaries.length === 1 ? PAGE.noun.toLowerCase() : PAGE.pluralNoun}${quiet ? ` · ${quiet} quiet for ${60}+ days` : ""} · tap one to see its records`
      : "";
    A.renderGatheringCards("gatheringCards", summaries, {
      active: activeFilter(),
      noun: PAGE.noun.toLowerCase(),
      onPick: (g) => {
        const select = document.getElementById("gatheringFilter");
        if (!select) return;
        select.value = select.value === g.name ? "" : g.name;
        UI.syncSelect(select);
        select.dispatchEvent(new Event("change"));
        renderCards();
        if (select.value) document.getElementById("recordsCard").scrollIntoView({ behavior: "smooth", block: "start" });
      },
    });
  }

  function renderTable() {
    const tbody = document.getElementById("attendanceTableBody");
    tbody.innerHTML = A.renderListRows(allRows, { onEdit: CAN_WRITE_ATTENDANCE ? "AttendanceGatherings.editRow" : null });

    UI.renderFilterToolbar("filterToolbar", {
      searchPlaceholder: `Search ${PAGE.plural} or notes...`,
      dateRange: true,
      filters: [
        {
          id: "gatheringFilter",
          label: `All ${PAGE.pluralNoun}`,
          options: summaries.filter((g) => g.times).map((g) => ({ value: g.name, label: g.name, color: g.color })),
        },
      ],
    });
    const table = UI.initListDataTable("attendanceTable", { order: [[0, "desc"]], nonSortableColumns: [4], hideDefaultSearch: true, noun: PAGE.plural });
    UI.wireFilterToolbar("filterToolbar", table, [{ id: "gatheringFilter", columnIndex: 1, exact: true }], { noun: PAGE.plural });
    // Keep the highlighted card - and Export - in step with the filter.
    document.getElementById("gatheringFilter")?.addEventListener("change", () => {
      document.querySelectorAll("#gatheringCards .gathering-card").forEach((c) => c.classList.toggle("is-active", c.dataset.gathering === activeFilter()));
      syncExport();
    });
    syncExport();
  }

  /** With one ministry picked, Export gives that ministry's report; otherwise all of them. */
  function syncExport() {
    const g = summaries.find((x) => x.name === activeFilter());
    const typeId = g && g.key.startsWith("t") ? g.key.substring(1) : "";
    UI.syncExportButton({ gatheringTypeId: typeId, reportTitle: typeId ? g.name : "" });
  }

  function openModal(record) {
    if (!CAN_WRITE_ATTENDANCE) return;
    A.openEntryModal({
      gatheringCategoryId: category.id,
      isWeekly: false,
      territoryId: USER_TERRITORY.id,
      record,
      records: allRows,
      types,
      icon: PAGE.icon,
      categoryLabel: PAGE.categoryLabel,
      onSaved: async (saved) => {
        await loadList();
        UI.flashRow(saved?.id);
      },
    });
  }

  function editRow(id) {
    const record = allRows.find((r) => r.id === id);
    if (record) openModal(record);
  }

  return { init, editRow };
})();

window.AttendanceGatherings = AttendanceGatherings;
