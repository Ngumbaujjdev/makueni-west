/**
 * ============================================================================
 * PAGE - ATTENDANCE OVERVIEW (church/attendance/index.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * The church's attendance at a glance, for the period picked on top (Year /
 * Month / All time - opens on this month, kept in the URL):
 *   - KPI cards (average Sunday, Sundays recorded, gatherings & events -
 *     for the period, against the one before - and the last entry)
 *   - the period's month of Sundays as chips - a missing one opens the form
 *     for that date, the quickest way to catch up
 *   - the 12 Sundays up to the end of the period, stacked by group, and who
 *     attends on an average Sunday of the period
 *   - ministries and events in the period, with a status each
 *   - how this church records (weekly + monthly, or monthly only)
 *
 * Period figures come from GET /attendance-reports/analytics (the same
 * numbers as Attendance Analytics and the PDF reports); the chart and the
 * chips use the records the page loads.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, AttendanceFormShared,
 * Toast, ApexCharts, jQuery + Select2
 * ============================================================================
 */

const AttendanceOverview = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AttendanceFormShared;
  const GROUP_COLORS = { adults_count: "primary", youth_count: "success", children_male_count: "purple", children_female_count: "pink" };

  let categories = {};
  let allRows = [];
  let types = [];
  let entryMode = "weekly_and_monthly";
  let trendChart = null;
  let whoChart = null;
  let picker = null;
  let analytics = null;

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const [cats, , typesResult, mode, yearsResult] = await Promise.all([
      DemographicsAPIHandler.getGatheringCategories(),
      loadRecords(),
      DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id),
      DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id),
      DemographicsAPIHandler.getFiscalYears(),
    ]);
    (cats.success ? cats.data || [] : []).forEach((c) => (categories[c.slug] = c));
    types = typesResult.success ? typesResult.data || [] : [];
    if (mode.success) entryMode = mode.data.attendance_mode;

    picker = UI.renderPeriodPicker({
      yearId: "periodYear",
      monthId: "periodMonth",
      monthWrapId: "periodMonthWrap",
      fromId: "periodFrom",
      toId: "periodTo",
      rangeWrapId: "periodRangeWrap",
      years: yearsResult.success ? yearsResult.data || [] : [],
      defaultMonth: true,
      onChange: loadPeriod,
    });
    renderEntryMode();
    await loadPeriod();

    document.getElementById("recordSundayBtn")?.addEventListener("click", () => recordSunday(A.nextMissingSunday(sundays()) || A.isoDate(A.sundayOnOrBefore())));
    document.getElementById("monthSundays").addEventListener("click", (e) => {
      const chip = e.target.closest("[data-sunday]");
      if (!chip || chip.disabled) return;
      const record = sundays().find((r) => A.recordIso(r) === chip.dataset.sunday);
      if (record) window.location.href = `${AppConfig.FRONTEND_BASE_URL}/church/attendance/record?id=${record.id}`;
      else recordSunday(chip.dataset.sunday);
    });
  }

  async function loadRecords() {
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id);
    if (!result.success) Toast.error(result.message || "Couldn't load attendance");
    allRows = (result.data || []).sort((a, b) => A.recordIso(b).localeCompare(A.recordIso(a)));
  }

  /** The period's figures from the API, then everything redrawn. */
  async function loadPeriod() {
    const body = document.getElementById("overviewBody");
    body.classList.add("is-loading");
    UI.syncExportButton(picker.exportParams());
    document.getElementById("periodLabel").textContent = picker.label();

    const res = await DemographicsAPIHandler.getAttendanceAnalytics(USER_TERRITORY.id, picker.filters());
    body.classList.remove("is-loading");
    if (!res.success) {
      Toast.error(res.message || "Couldn't load attendance for this period");
      return;
    }
    analytics = res.data;
    renderAll();
  }

  const sundays = () => allRows.filter((r) => r.gathering_category?.slug === "sunday_service");

  function renderAll() {
    renderStats();
    renderMonthSundays();
    renderCharts();
    renderGlance();
    const next = A.nextMissingSunday(sundays());
    const btn = document.getElementById("recordSundayBtn");
    if (btn) btn.innerHTML = `<i class="ri-add-line me-1"></i>${next ? `Record ${A.shortDate(next)}` : "Record Sunday"}`;
  }

  /** The last day of the period that has happened - "today" for a period still running. */
  function periodEnd() {
    const end = analytics.period.end;
    const today = A.todayIso();
    return end < today ? end : today;
  }

  // ==========================================================================
  // KPI CARDS
  // ==========================================================================

  function renderStats() {
    const s = analytics.summary;
    const c = s.coverage;
    const prev = analytics.period.previous_label;
    const label = picker.label();
    const missing = c.elapsed - c.recorded;
    const sums = UI.monthlySeries(sundays(), { value: A.recordTotal });
    const counts = UI.monthlySeries(sundays());
    const avgSeries = { labels: sums.labels, data: sums.data.map((v, i) => (counts.data[i] ? Math.round(v / counts.data[i]) : 0)) };
    const others = allRows.filter((r) => r.gathering_category?.slug !== "sunday_service");
    const last = allRows[0];
    const daysAgo = last ? Math.floor((A.parseIso(A.todayIso()) - A.parseIso(A.recordIso(last))) / 86400000) : null;

    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-team-line",
        label: "Average Sunday",
        value: s.sunday_average == null ? "-" : s.sunday_average.toLocaleString(),
        color: "primary",
        delta: s.sunday_average != null && s.previous_sunday_average ? UI.periodDelta(s.sunday_average, s.previous_sunday_average, { prevLabel: prev }) : null,
        series: avgSeries,
        sub: s.sunday_average == null ? `No Sundays recorded in ${label}` : "",
      },
      {
        icon: "ri-calendar-check-line",
        label: "Sundays recorded",
        value: c.elapsed ? `${c.recorded} of ${c.elapsed}` : "-",
        color: !c.elapsed ? "secondary" : missing ? "danger" : "success",
        sub: !c.elapsed ? `No Sundays yet in ${label}` : missing ? `${missing} not recorded · ${c.percentage}%` : "Every Sunday recorded",
      },
      {
        icon: "ri-group-line",
        label: "Gatherings & events",
        value: (s.gatherings_held || 0).toLocaleString(),
        color: "purple",
        delta: s.previous_gatherings_held != null ? UI.periodDelta(s.gatherings_held, s.previous_gatherings_held, { percent: false, prevLabel: prev }) : null,
        series: UI.monthlySeries(others),
        sub: prev ? "" : "Ministry meetings and special events",
      },
      {
        icon: "ri-time-line",
        label: "Last recorded",
        value: last ? A.formatDate(A.recordIso(last), { day: "numeric", month: "short" }) : "-",
        color: daysAgo != null && daysAgo > 14 ? "danger" : "secondary",
        sub: daysAgo == null ? "Nothing recorded yet" : `${daysAgo === 0 ? "Today" : `${daysAgo} day${daysAgo === 1 ? "" : "s"} ago`} · ${A.escapeHtml(last.gathering_type?.name || last.event_name || "Sunday service")}`,
      },
    ]);
  }

  // ==========================================================================
  // THE PERIOD'S SUNDAYS
  // ==========================================================================

  /** The month the chips show: the chosen month (or a range's last); else this month, or December of a past year. */
  function chipsMonth() {
    const { year, month, to } = picker.state();
    const now = new Date();
    if (to) return { y: Number(to.slice(0, 4)), m: Number(to.slice(5)) - 1 };
    if (year === "all" || Number(year) === now.getFullYear()) return month ? { y: now.getFullYear(), m: Number(month) - 1 } : { y: now.getFullYear(), m: now.getMonth() };
    return { y: Number(year), m: month ? Number(month) - 1 : 11 };
  }

  function renderMonthSundays() {
    const { y, m } = chipsMonth();
    const first = new Date(y, m, 1);
    const end = new Date(y, m + 1, 0);
    const rows = A.sundayRows(sundays(), first, end).reverse();
    const missing = rows.filter((s) => s.status === "missing").length;
    const name = first.toLocaleDateString("en-GB", { month: "long" });
    document.getElementById("monthSundaysTitle").textContent = y === new Date().getFullYear() ? `${name}'s Sundays` : `${name} ${y}'s Sundays`;
    document.getElementById("monthSundaysSub").textContent =
      entryMode === "monthly_only" ? "Weekly entry is off - only the monthly form is needed" : missing ? `${missing} not recorded - tap one to record it` : rows.some((s) => s.status === "recorded") ? "Every Sunday so far is recorded" : "No Sundays to record yet";
    document.getElementById("monthSundays").innerHTML = A.sundayChipsHtml(rows);
    if (!CAN_ENTER_ATTENDANCE || entryMode === "monthly_only") {
      document.querySelectorAll("#monthSundays .att-sunday-chip.is-missing").forEach((c) => (c.disabled = true));
    }
  }

  // ==========================================================================
  // CHARTS
  // ==========================================================================

  function renderCharts() {
    const end = periodEnd();
    const recent = sundays()
      .filter((r) => A.recordIso(r) <= end)
      .reverse()
      .slice(-12);
    document.getElementById("trendSubtitle").textContent = `The 12 Sundays recorded up to ${A.formatDate(end, { day: "numeric", month: "short", year: "numeric" })}, by group`;
    if (trendChart) trendChart.destroy();
    trendChart = null;
    const trendEl = document.getElementById("sundayTrendChart");
    const chips = document.getElementById("trendChips");
    if (!recent.length) {
      trendEl.innerHTML = '<div class="att-empty">No Sundays recorded up to this period</div>';
      chips.innerHTML = "";
    } else {
      trendEl.innerHTML = "";
      trendChart = UI.renderTrendChart("sundayTrendChart", {
        type: "area",
        stacked: true,
        categories: recent.map((r) => A.formatDate(A.recordIso(r), { day: "numeric", month: "short" })),
        series: A.GROUPS.map((g) => ({ name: g.label, data: recent.map((r) => Number(r[g.key]) || 0) })),
        colors: A.GROUPS.map((g) => UI.cssColor(g.color)),
      });
      const totals = recent.map(A.recordTotal);
      const avg = Math.round(totals.reduce((a, b) => a + b, 0) / totals.length);
      chips.innerHTML = `
        <span class="soft-chip soft-primary">Average <b>${avg.toLocaleString()}</b></span>
        <span class="soft-chip soft-success">Highest <b>${Math.max(...totals).toLocaleString()}</b></span>`;
    }

    // Destroy the old ring first - replacing it mid-animation left ApexCharts drawing into removed nodes.
    if (whoChart) whoChart.destroy();
    whoChart = null;
    const composition = analytics.sunday.composition;
    document.getElementById("whoAttendsSubtitle").textContent = `Average Sunday · ${picker.label()}`;
    if (!analytics.sunday.weekly.length) {
      document.getElementById("whoAttendsDonut").innerHTML = `<div class="att-empty">No Sundays recorded in ${A.escapeHtml(picker.label())}</div>`;
      return;
    }
    whoChart = UI.renderRingDonut("whoAttendsDonut", {
      labels: composition.map((g) => g.label),
      series: composition.map((g) => g.average),
      colors: composition.map((g) => GROUP_COLORS[g.key]),
      centerLabel: "Avg Sunday",
      centerValue: analytics.summary.sunday_average,
    });
  }

  // ==========================================================================
  // MINISTRIES & EVENTS IN THE PERIOD
  // ==========================================================================

  const STATUS = { active: ["Active", "success"], quiet: ["Quiet 60+ days", "danger"], never: ["Not held yet", "secondary"] };

  function renderGlance() {
    const base = `${AppConfig.FRONTEND_BASE_URL}/church/attendance`;
    const items = [
      ...analytics.ministries.items.map((g) => ({ ...g, page: "ministries" })),
      ...analytics.events.items.map((g) => ({ ...g, page: "events" })),
    ].sort((a, b) => b.times - a.times || b.total - a.total || a.name.localeCompare(b.name));
    const quiet = items.filter((g) => g.status === "quiet").length;
    const met = items.filter((g) => g.times > 0).length;
    const when = picker.state().year === "all" ? "since records began" : `in ${picker.label()}`;
    document.getElementById("glanceSub").textContent = `${met} met ${when}${quiet ? ` · ${quiet} quiet for 60+ days` : ""}`;
    const el = document.getElementById("glanceList");
    if (!items.length) {
      el.innerHTML = `<div class="att-empty">No ministries or events yet - add them under Gathering types</div>`;
      return;
    }
    el.innerHTML = items
      .slice(0, 6)
      .map((g) => {
        const [label, color] = STATUS[g.status] || STATUS.never;
        const lastText = g.last ? ` · last ${A.formatDate(g.last, { day: "numeric", month: "short" })}` : "";
        return `
          <a class="att-glance-item" href="${base}/gathering?${g.type_id ? `type=${g.type_id}` : `name=${encodeURIComponent(g.name)}`}">
            ${UI.avatarTile(A.escapeHtml(g.icon || (g.page === "events" ? "ri-star-line" : "ri-group-line")), UI.colorFor(g.name))}
            <span class="att-glance-text">
              <strong>${A.escapeHtml(g.name)}</strong>
              <small>${g.times ? `Met ${g.times}× · avg ${g.average.toLocaleString()}` : "Didn't meet in this period"}${lastText}</small>
            </span>
            ${UI.pill(label, color)}
          </a>`;
      })
      .join("");
  }

  // ==========================================================================
  // ENTRY MODE
  // ==========================================================================

  function renderEntryMode() {
    const wrap = document.getElementById("entryModeSwitch");
    wrap.innerHTML = UI.renderSegmented(
      "entryModeSeg",
      [
        { value: "weekly_and_monthly", label: '<i class="ri-calendar-check-line me-1"></i>Weekly + monthly' },
        { value: "monthly_only", label: '<i class="ri-calendar-line me-1"></i>Monthly only' },
      ],
      entryMode,
      { ariaLabel: "How this church records attendance" },
    );
    describeMode();
    if (!CAN_ENTER_ATTENDANCE) {
      wrap.querySelectorAll(".seg-btn").forEach((b) => (b.disabled = true));
      return;
    }
    const set = UI.wireSegmented("entryModeSeg", async (mode) => {
      const previous = entryMode;
      entryMode = mode;
      describeMode();
      const result = await DemographicsAPIHandler.updateEntryMode(USER_TERRITORY.id, { attendance_mode: mode });
      if (!result.success) {
        entryMode = previous;
        set(previous);
        describeMode();
        Toast.error(result.message || "Couldn't change how attendance is recorded");
        return;
      }
      Toast.success(mode === "monthly_only" ? "Weekly entry turned off" : "Weekly entry turned on");
      renderMonthSundays();
    });
  }

  function describeMode() {
    document.getElementById("entryModeText").textContent =
      entryMode === "monthly_only"
        ? "Only the monthly Demographics form is filled in. Good for churches with poor network - Sundays aren't expected."
        : "Every Sunday's count is recorded here, plus the monthly Demographics form.";
  }

  // ==========================================================================
  // ENTRY
  // ==========================================================================

  function recordSunday(iso) {
    if (entryMode === "monthly_only") {
      Toast.info("Weekly entry is off for this church - turn it on under \"How we record\".");
      return;
    }
    openSunday(null, iso);
  }

  function openSunday(record, iso = null) {
    if (!CAN_ENTER_ATTENDANCE || !categories.sunday_service) return;
    A.openEntryModal({
      gatheringCategoryId: categories.sunday_service.id,
      isWeekly: true,
      territoryId: USER_TERRITORY.id,
      record,
      defaultDate: iso,
      records: sundays(),
      canDelete: CAN_DELETE_ATTENDANCE,
      onSaved: async () => {
        await loadRecords();
        await loadPeriod();
      },
      onDeleted: async () => {
        await loadRecords();
        await loadPeriod();
      },
    });
  }

  return { init };
})();

window.AttendanceOverview = AttendanceOverview;
