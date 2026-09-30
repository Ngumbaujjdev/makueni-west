/**
 * ============================================================================
 * PAGE - SUNDAY SERVICES (church/attendance/services.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Every Sunday of the chosen year as a list - recorded ones with their
 * counts, and the ones nobody recorded shown as "Not recorded" with a
 * Record button, so gaps are obvious instead of hidden. A Calendar view
 * (FullCalendar) shows the same Sundays by month, with missed ones tinted.
 * "Record next missing Sunday" goes straight to the newest gap.
 *
 * View and year live in the URL (?view=calendar&year=2026); the table's own
 * filters are synced by wireFilterToolbar. Nothing reloads the page.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, AttendanceFormShared,
 * Toast, ApexCharts, jQuery + DataTables + Select2, FullCalendar v5
 * ============================================================================
 */

const AttendanceServices = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AttendanceFormShared;
  const CATEGORY_SLUG = "sunday_service";

  let category = null;
  let allRows = [];
  let entryMode = "weekly_and_monthly";
  let calendar = null;
  let trendChart = null;
  let view = "list";
  let year = new Date().getFullYear();

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const params = new URLSearchParams(window.location.search);
    view = params.get("view") === "calendar" ? "calendar" : "list";
    year = Number(params.get("year")) || year;

    const categoriesResult = await DemographicsAPIHandler.getGatheringCategories();
    category = categoriesResult.success ? (categoriesResult.data || []).find((c) => c.slug === CATEGORY_SLUG) : null;
    if (!category) {
      Toast.error("Could not load gathering categories");
      return;
    }

    await Promise.all([loadEntryMode(), loadRecords()]);
    renderYearSelect();
    renderAll();
    wireView();

    document.getElementById("recordNextBtn")?.addEventListener("click", () => {
      const next = A.nextMissingSunday(allRows);
      openEntry(null, next || A.isoDate(A.sundayOnOrBefore()));
    });
  }

  async function loadEntryMode() {
    const result = await DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id);
    if (result.success) entryMode = result.data.attendance_mode;
    document.getElementById("entryModeBanner").innerHTML =
      entryMode === "monthly_only"
        ? `<div class="att-banner mb-3">
             <span class="att-banner-icon bg-secondary text-dark"><i class="ri-information-line"></i></span>
             <div><strong>Weekly entry is off for this church</strong><span>Only the monthly Demographics form is needed. Turn it back on from the Attendance overview.</span></div>
             <a href="${AppConfig.FRONTEND_BASE_URL}/church/attendance" class="btn btn-sm btn-light ms-auto">Change</a>
           </div>`
        : "";
  }

  async function loadRecords() {
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { gathering_category_id: category.id });
    allRows = result.success ? result.data || [] : [];
    if (!result.success) Toast.error(result.message || "Couldn't load Sunday attendance");
  }

  function renderAll() {
    renderStats();
    renderRecordButton();
    renderList();
    renderSideCharts();
    if (calendar) refreshCalendar();
  }

  // ==========================================================================
  // YEAR + VIEW
  // ==========================================================================

  function yearsAvailable() {
    const now = new Date().getFullYear();
    const first = allRows.length ? Math.min(...allRows.map((r) => A.parseIso(A.recordIso(r)).getFullYear())) : now;
    const years = [];
    for (let y = now; y >= Math.min(first, now); y--) years.push(y);
    return years;
  }

  function renderYearSelect() {
    const select = document.getElementById("sundayYear");
    const years = yearsAvailable();
    if (!years.includes(year)) year = years[0];
    select.innerHTML = years.map((y) => `<option value="${y}">${y}</option>`).join("");
    select.value = String(year);
    UI.enhanceSelect(select, { search: false, dropdownAutoWidth: true });
    select.addEventListener("change", () => {
      year = Number(select.value);
      syncUrl();
      renderList();
      if (calendar) calendar.gotoDate(year === new Date().getFullYear() ? new Date() : `${year}-12-01`);
    });
  }

  function wireView() {
    const set = UI.wireSegmented("viewSwitch", (value) => {
      view = value;
      applyView();
      syncUrl();
    });
    set(view);
    applyView();
  }

  function applyView() {
    document.getElementById("listView").hidden = view !== "list";
    document.getElementById("calendarView").hidden = view !== "calendar";
    if (view === "calendar") {
      if (!calendar) renderCalendar();
      else calendar.updateSize();
    }
  }

  function syncUrl() {
    const params = new URLSearchParams(window.location.search);
    view === "calendar" ? params.set("view", "calendar") : params.delete("view");
    year === new Date().getFullYear() ? params.delete("year") : params.set("year", year);
    const qs = params.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
  }

  // ==========================================================================
  // SUNDAYS OF THE YEAR
  // ==========================================================================

  /** Sundays of `year`, from the church's first record (gaps before it aren't "missing") to the coming Sunday. */
  function sundaysOfYear(y) {
    const firstIso = allRows.length ? allRows.map(A.recordIso).sort()[0] : null;
    let start = new Date(y, 0, 1);
    if (firstIso && A.parseIso(firstIso) > start) start = A.parseIso(firstIso);
    const comingSunday = A.sundayOnOrBefore();
    comingSunday.setDate(comingSunday.getDate() + 7);
    const end = new Date(Math.min(new Date(y, 11, 31), comingSunday));
    if (end < start) return [];
    return A.sundayRows(allRows, start, end);
  }

  function renderRecordButton() {
    const btn = document.getElementById("recordNextBtn");
    if (!btn) return;
    const next = A.nextMissingSunday(allRows);
    btn.innerHTML = next
      ? `<i class="ri-add-line me-1"></i>Record ${A.shortDate(next)}`
      : `<i class="ri-add-line me-1"></i>Record Sunday`;
    btn.title = next ? "The newest Sunday that hasn't been recorded" : "";
  }

  function renderList() {
    const tbody = document.getElementById("sundayTableBody");
    const sundays = sundaysOfYear(year);
    const recorded = sundays.filter((s) => s.status === "recorded");
    const missing = sundays.filter((s) => s.status === "missing");
    document.getElementById("sundayListSubtitle").textContent = sundays.length
      ? `${recorded.length} recorded${missing.length ? ` · ${missing.length} not recorded` : " · none missed"} in ${year}`
      : `Nothing to show for ${year}`;

    tbody.innerHTML = sundays.length
      ? sundays
          .map((s, i) => {
            const meta = { recorded: ["Recorded", "success"], missing: ["Not recorded", "danger"], upcoming: ["Upcoming", "secondary"] }[s.status];
            const r = s.record;
            const total = r ? A.recordTotal(r) : null;
            const previous = sundays.slice(i + 1).find((x) => x.record);
            const cell = (key) => (r ? (Number(r[key]) || 0).toLocaleString() : "-");
            const monthName = A.formatDate(s.iso, { month: "long", year: "numeric" });
            const action =
              s.status === "recorded"
                ? CAN_WRITE_ATTENDANCE
                  ? `<button type="button" class="btn btn-sm btn-primary-light" data-edit="${r.id}" title="Edit" aria-label="Edit ${A.shortDate(s.iso)}"><i class="ri-edit-line"></i></button>`
                  : ""
                : s.status === "missing" && CAN_WRITE_ATTENDANCE
                  ? `<button type="button" class="btn btn-sm btn-primary" data-record="${s.iso}"><i class="ri-add-line me-1"></i>Record</button>`
                  : "";
            return `
              <tr data-row-id="${r ? r.id : ""}" data-date="${s.iso}" class="${s.status === "missing" ? "att-row-missing" : ""}">
                <td data-order="${s.iso}" data-search="${A.formatDate(s.iso, { weekday: "short", day: "numeric", month: "short" })} ${monthName}">
                  <div class="fw-semibold">${A.formatDate(s.iso, { day: "numeric", month: "short", year: "numeric" })}</div>
                  <div class="fs-12">${monthName.split(" ")[0]}</div>
                </td>
                <td data-search="${meta[0]}">${UI.pill(meta[0], meta[1])}</td>
                <td class="d-none d-md-table-cell" data-order="${r ? r.adults_count || 0 : -1}">${cell("adults_count")}</td>
                <td class="d-none d-md-table-cell" data-order="${r ? r.youth_count || 0 : -1}">${cell("youth_count")}</td>
                <td class="d-none d-md-table-cell" data-order="${r ? (r.children_male_count || 0) + (r.children_female_count || 0) : -1}">
                  ${r ? `${((r.children_male_count || 0) + (r.children_female_count || 0)).toLocaleString()}<div class="fs-12">${r.children_male_count || 0} boys · ${r.children_female_count || 0} girls</div>` : "-"}
                </td>
                <td data-order="${total ?? -1}">${r ? `<span class="fw-bold fs-15">${total.toLocaleString()}</span>${UI.changePill(total, previous ? A.recordTotal(previous.record) : null)}` : "-"}</td>
                <td class="text-end">${action}</td>
              </tr>`;
          })
          .join("")
      : UI.renderTableEmpty(7, `No Sundays to show for ${year}`, "ri-sun-line");

    tbody.querySelectorAll("[data-edit]").forEach((b) => b.addEventListener("click", () => openEntry(allRows.find((r) => r.id === Number(b.dataset.edit)))));
    tbody.querySelectorAll("[data-record]").forEach((b) => b.addEventListener("click", () => openEntry(null, b.dataset.record)));

    const months = [...new Set(sundays.map((s) => A.formatDate(s.iso, { month: "long" })))];
    UI.renderFilterToolbar("sundayFilterToolbar", {
      searchPlaceholder: "Search Sundays...",
      filters: [
        { id: "sundayMonthFilter", label: "All months", options: months.map((m) => ({ value: m, label: m, color: "primary" })) },
        {
          id: "sundayStatusFilter",
          label: "All statuses",
          options: [
            { value: "Recorded", label: "Recorded", color: "success" },
            { value: "Not recorded", label: "Not recorded", color: "danger" },
            { value: "Upcoming", label: "Upcoming", color: "secondary" },
          ],
        },
      ],
    });
    const table = UI.initListDataTable("sundayTable", { order: [[0, "desc"]], nonSortableColumns: [6], hideDefaultSearch: true, noun: "Sundays", pageLength: 25 });
    UI.wireFilterToolbar(
      "sundayFilterToolbar",
      table,
      [
        { id: "sundayMonthFilter", columnIndex: 0 },
        { id: "sundayStatusFilter", columnIndex: 1, exact: true },
      ],
      { noun: "Sundays" },
    );
  }

  // ==========================================================================
  // STATS + SIDE CHARTS
  // ==========================================================================

  function renderStats() {
    const prevLabel = UI.monthLabel(1);
    const thisMonth = UI.rowsInMonth(allRows, 0);
    const lastMonth = UI.rowsInMonth(allRows, 1);
    const avg = (list) => (list.length ? Math.round(list.reduce((acc, r) => acc + A.recordTotal(r), 0) / list.length) : 0);
    const sums = UI.monthlySeries(allRows, { value: A.recordTotal });
    const counts = UI.monthlySeries(allRows);
    const avgSeries = { labels: sums.labels, data: sums.data.map((v, i) => (counts.data[i] ? Math.round(v / counts.data[i]) : 0)) };

    const now = new Date();
    const monthSundays = A.sundayRows(allRows, new Date(now.getFullYear(), now.getMonth(), 1), now);
    const missing = monthSundays.filter((s) => s.status === "missing").length;

    const thisYear = allRows.filter((r) => A.parseIso(A.recordIso(r)).getFullYear() === now.getFullYear());
    const best = [...thisYear].sort((a, b) => A.recordTotal(b) - A.recordTotal(a))[0];
    const boys = thisYear.length ? Math.round(thisYear.reduce((s, r) => s + (r.children_male_count || 0), 0) / thisYear.length) : 0;
    const girls = thisYear.length ? Math.round(thisYear.reduce((s, r) => s + (r.children_female_count || 0), 0) / thisYear.length) : 0;

    UI.renderStatCardsRow("statCardsRow", [
      thisMonth.length
        ? {
            icon: "ri-team-line",
            label: "Avg per Sunday",
            value: avg(thisMonth).toLocaleString(),
            color: "primary",
            delta: lastMonth.length ? UI.periodDelta(avg(thisMonth), avg(lastMonth), { prevLabel }) : null,
            series: avgSeries,
          }
        : {
            // No Sunday yet this month: show last month's, not "0, -100%".
            icon: "ri-team-line",
            label: "Avg per Sunday",
            value: lastMonth.length ? avg(lastMonth).toLocaleString() : "-",
            color: "primary",
            series: avgSeries,
            sub: lastMonth.length ? `${prevLabel} - none recorded this month yet` : "None recorded this month yet",
          },
      {
        icon: "ri-calendar-check-line",
        label: "Sundays recorded",
        value: `${monthSundays.length - missing} of ${monthSundays.length}`,
        color: missing ? "danger" : "success",
        sub: missing ? `${missing} not recorded this month` : "All caught up this month",
      },
      {
        icon: "ri-trophy-line",
        label: `Best Sunday ${now.getFullYear()}`,
        value: best ? A.recordTotal(best).toLocaleString() : "-",
        color: "secondary",
        sub: best ? A.formatDate(A.recordIso(best), { weekday: "short", day: "numeric", month: "short" }) : "Nothing recorded yet",
      },
      {
        icon: "ri-parent-line",
        label: "Children per Sunday",
        value: (boys + girls).toLocaleString(),
        color: "pink",
        sub: thisYear.length ? `${boys} boys · ${girls} girls on average` : "Nothing recorded yet",
      },
    ]);
  }

  function renderSideCharts() {
    const recent = [...allRows].sort((a, b) => A.recordIso(a).localeCompare(A.recordIso(b))).slice(-12);
    const trendEl = document.getElementById("sundayTrendChart");
    if (trendChart) trendChart.destroy();
    trendChart = null;
    if (!recent.length) {
      trendEl.innerHTML = '<div class="att-empty">Record a Sunday to see the trend</div>';
    } else {
      trendEl.innerHTML = "";
      trendChart = UI.renderTrendChart("sundayTrendChart", {
        type: "area",
        stacked: true,
        categories: recent.map((r) => A.formatDate(A.recordIso(r), { day: "numeric", month: "short" })),
        series: A.GROUPS.map((g) => ({ name: g.label, data: recent.map((r) => Number(r[g.key]) || 0) })),
        colors: A.GROUPS.map((g) => UI.cssColor(g.color)),
      });
    }

    const y = new Date().getFullYear();
    const thisYear = allRows.filter((r) => A.parseIso(A.recordIso(r)).getFullYear() === y);
    const base = thisYear.length ? thisYear : allRows;
    document.getElementById("whoAttendsSubtitle").textContent = thisYear.length ? `Average Sunday in ${y}` : "Average Sunday";
    if (!base.length) {
      document.getElementById("whoAttendsDonut").innerHTML = '<div class="att-empty">Nothing recorded yet</div>';
      return;
    }
    UI.renderRingDonut("whoAttendsDonut", {
      labels: A.GROUPS.map((g) => g.label),
      series: A.GROUPS.map((g) => Math.round(base.reduce((s, r) => s + (Number(r[g.key]) || 0), 0) / base.length)),
      colors: A.GROUPS.map((g) => g.color),
      centerLabel: "Avg Sunday",
    });
  }

  // ==========================================================================
  // CALENDAR VIEW
  // ==========================================================================

  function eventSource() {
    return allRows.map((r) => ({
      id: String(r.id),
      title: `${A.recordTotal(r).toLocaleString()} attended`,
      start: A.recordIso(r),
      allDay: true,
      backgroundColor: UI.cssColor("primary"),
      borderColor: UI.cssColor("primary"),
      extendedProps: { record: r },
    }));
  }

  function missingSet() {
    return new Set(yearsAvailable().flatMap((y) => sundaysOfYear(y).filter((s) => s.status === "missing").map((s) => s.iso)));
  }

  function renderCalendar() {
    let missing = missingSet();
    calendar = new FullCalendar.Calendar(document.getElementById("attendanceCalendar"), {
      initialView: "dayGridMonth",
      initialDate: year === new Date().getFullYear() ? undefined : `${year}-12-01`,
      headerToolbar: { left: "prev,next today", center: "title", right: "" },
      height: "auto",
      events: eventSource(),
      dayCellClassNames: (arg) => {
        if (arg.date.getDay() !== 0) return [];
        return missing.has(A.isoDate(arg.date)) ? ["fc-sunday-highlight", "fc-sunday-missing"] : ["fc-sunday-highlight"];
      },
      dateClick: (info) => {
        if (new Date(`${info.dateStr}T00:00:00`).getDay() !== 0) {
          Toast.info("Attendance is recorded for Sundays - pick a Sunday");
          return;
        }
        openEntry(allRows.find((r) => A.recordIso(r) === info.dateStr) || null, info.dateStr);
      },
      eventClick: (info) => openEntry(info.event.extendedProps.record),
    });
    calendar.render();
    calendar._refreshMissing = () => {
      missing = missingSet();
    };
  }

  function refreshCalendar() {
    calendar._refreshMissing();
    calendar.removeAllEventSources();
    calendar.addEventSource(eventSource());
    calendar.render();
  }

  // ==========================================================================
  // ENTRY
  // ==========================================================================

  function openEntry(record, defaultDate = null) {
    if (!CAN_WRITE_ATTENDANCE) return;
    if (!record && entryMode === "monthly_only") {
      Toast.info("Weekly entry is off for this church - use the monthly Demographics form instead.");
      return;
    }
    A.openEntryModal({
      gatheringCategoryId: category.id,
      isWeekly: true,
      territoryId: USER_TERRITORY.id,
      record: record || null,
      defaultDate,
      records: allRows,
      onSaved: async (saved) => {
        await loadRecords();
        renderAll();
        UI.flashRow(saved?.id);
      },
    });
  }

  return { init };
})();

window.AttendanceServices = AttendanceServices;
