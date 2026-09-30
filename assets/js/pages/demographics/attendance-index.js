/**
 * ============================================================================
 * PAGE - ATTENDANCE OVERVIEW (church/attendance/index.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * The church's attendance at a glance:
 *   - KPI cards (Sunday average, Sundays recorded this month, gatherings,
 *     last recorded)
 *   - this month's Sundays as chips - a missing one opens the form for that
 *     date, the quickest way to catch up
 *   - the last 12 Sundays stacked by group, and who attends (averages)
 *   - ministries and events at a glance, with a status each
 *   - how this church records (weekly + monthly, or monthly only)
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, AttendanceFormShared,
 * Toast, ApexCharts, jQuery + Select2
 * ============================================================================
 */

const AttendanceOverview = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AttendanceFormShared;

  let categories = {};
  let allRows = [];
  let types = [];
  let entryMode = "weekly_and_monthly";
  let trendChart = null;

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const [cats, , typesResult, mode] = await Promise.all([
      DemographicsAPIHandler.getGatheringCategories(),
      loadRecords(),
      DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id),
      DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id),
    ]);
    (cats.success ? cats.data || [] : []).forEach((c) => (categories[c.slug] = c));
    types = typesResult.success ? typesResult.data || [] : [];
    if (mode.success) entryMode = mode.data.attendance_mode;

    renderAll();
    renderEntryMode();

    document.getElementById("recordSundayBtn")?.addEventListener("click", () => recordSunday(A.nextMissingSunday(sundays()) || A.isoDate(A.sundayOnOrBefore())));
    document.getElementById("monthSundays").addEventListener("click", (e) => {
      const chip = e.target.closest("[data-sunday]");
      if (!chip || chip.disabled) return;
      const record = sundays().find((r) => A.recordIso(r) === chip.dataset.sunday);
      record ? openSunday(record) : recordSunday(chip.dataset.sunday);
    });
  }

  async function loadRecords() {
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id);
    if (!result.success) Toast.error(result.message || "Couldn't load attendance");
    allRows = (result.data || []).sort((a, b) => A.recordIso(b).localeCompare(A.recordIso(a)));
  }

  const sundays = () => allRows.filter((r) => r.gathering_category?.slug === "sunday_service");
  const others = () => allRows.filter((r) => r.gathering_category?.slug !== "sunday_service");

  function renderAll() {
    renderStats();
    renderMonthSundays();
    renderCharts();
    renderGlance();
    const next = A.nextMissingSunday(sundays());
    const btn = document.getElementById("recordSundayBtn");
    if (btn) btn.innerHTML = `<i class="ri-add-line me-1"></i>${next ? `Record ${A.shortDate(next)}` : "Record Sunday"}`;
  }

  // ==========================================================================
  // KPI CARDS
  // ==========================================================================

  function renderStats() {
    const prevLabel = UI.monthLabel(1);
    const sun = sundays();
    const sums = UI.monthlySeries(sun, { value: A.recordTotal });
    const counts = UI.monthlySeries(sun);
    const avgSeries = { labels: sums.labels, data: sums.data.map((v, i) => (counts.data[i] ? Math.round(v / counts.data[i]) : 0)) };

    const now = new Date();
    const monthSundays = A.sundayRows(sun, new Date(now.getFullYear(), now.getMonth(), 1), now);
    const missing = monthSundays.filter((s) => s.status === "missing").length;

    const gatherThis = UI.rowsInMonth(others(), 0);
    const gatherLast = UI.rowsInMonth(others(), 1);
    const last = allRows[0];
    const daysAgo = last ? Math.floor((A.parseIso(A.todayIso()) - A.parseIso(A.recordIso(last))) / 86400000) : null;

    UI.renderStatCardsRow("statCardsRow", [
      avgCard(sun, avgSeries, prevLabel),
      {
        icon: "ri-calendar-check-line",
        label: "Sundays recorded",
        value: `${monthSundays.length - missing} of ${monthSundays.length}`,
        color: missing ? "danger" : "success",
        sub: missing ? `${missing} not recorded this month` : "All caught up this month",
      },
      {
        icon: "ri-group-line",
        label: "Gatherings & events",
        value: gatherThis.length,
        color: "purple",
        delta: UI.periodDelta(gatherThis.length, gatherLast.length, { percent: false, prevLabel }),
        series: UI.monthlySeries(others()),
      },
      {
        icon: "ri-time-line",
        label: "Last recorded",
        value: last ? A.formatDate(A.recordIso(last), { day: "numeric", month: "short" }) : "-",
        color: daysAgo != null && daysAgo > 14 ? "danger" : "secondary",
        sub: daysAgo == null ? "Nothing recorded yet" : daysAgo === 0 ? "Today" : `${daysAgo} day${daysAgo === 1 ? "" : "s"} ago · ${A.escapeHtml(last.gathering_type?.name || last.event_name || "Sunday service")}`,
      },
    ]);
  }

  /**
   * Average Sunday this month vs last. With no Sunday recorded yet this
   * month there's no average to compare - it shows last month's instead of
   * a "0, -100%" that reads like nobody came.
   */
  function avgCard(sun, series, prevLabel) {
    const avg = (list) => Math.round(list.reduce((acc, r) => acc + A.recordTotal(r), 0) / list.length);
    const thisMonth = UI.rowsInMonth(sun, 0);
    const lastMonth = UI.rowsInMonth(sun, 1);
    const card = { icon: "ri-team-line", label: "Avg Sunday attendance", color: "primary", series };
    if (thisMonth.length) {
      return { ...card, value: avg(thisMonth).toLocaleString(), delta: lastMonth.length ? UI.periodDelta(avg(thisMonth), avg(lastMonth), { prevLabel }) : null };
    }
    return { ...card, value: lastMonth.length ? avg(lastMonth).toLocaleString() : "-", sub: lastMonth.length ? `${prevLabel} - none recorded this month yet` : "None recorded this month yet" };
  }

  // ==========================================================================
  // THIS MONTH'S SUNDAYS
  // ==========================================================================

  function renderMonthSundays() {
    const now = new Date();
    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    const rows = A.sundayRows(sundays(), new Date(now.getFullYear(), now.getMonth(), 1), end).reverse();
    const missing = rows.filter((s) => s.status === "missing").length;
    document.getElementById("monthSundaysTitle").textContent = `${now.toLocaleDateString("en-GB", { month: "long" })}'s Sundays`;
    document.getElementById("monthSundaysSub").textContent =
      entryMode === "monthly_only" ? "Weekly entry is off - only the monthly form is needed" : missing ? `${missing} not recorded yet - tap one to record it` : "Every Sunday so far is recorded";
    document.getElementById("monthSundays").innerHTML = A.sundayChipsHtml(rows);
    if (!CAN_ENTER_ATTENDANCE || entryMode === "monthly_only") {
      document.querySelectorAll("#monthSundays .att-sunday-chip.is-missing").forEach((c) => (c.disabled = true));
    }
  }

  // ==========================================================================
  // CHARTS
  // ==========================================================================

  function renderCharts() {
    const recent = [...sundays()].reverse().slice(-12);
    if (trendChart) trendChart.destroy();
    trendChart = null;
    const trendEl = document.getElementById("sundayTrendChart");
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
      const totals = recent.map(A.recordTotal);
      const avg = Math.round(totals.reduce((a, b) => a + b, 0) / totals.length);
      document.getElementById("trendChips").innerHTML = `
        <span class="soft-chip soft-primary">Average <b>${avg.toLocaleString()}</b></span>
        <span class="soft-chip soft-success">Highest <b>${Math.max(...totals).toLocaleString()}</b></span>`;
    }

    const y = new Date().getFullYear();
    const thisYear = sundays().filter((r) => A.parseIso(A.recordIso(r)).getFullYear() === y);
    const base = thisYear.length ? thisYear : sundays();
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
  // MINISTRIES & EVENTS AT A GLANCE
  // ==========================================================================

  function renderGlance() {
    const base = `${AppConfig.FRONTEND_BASE_URL}/church/attendance`;
    const list = A.summarizeGatherings(others(), types.filter((t) => t.gathering_category_id !== categories.sunday_service?.id)).slice(0, 6);
    const quiet = A.summarizeGatherings(others(), types).filter((g) => g.status.key === "quiet").length;
    document.getElementById("glanceSub").textContent = quiet ? `${quiet} haven't met for 60+ days` : "Most met first";
    const el = document.getElementById("glanceList");
    if (!list.length) {
      el.innerHTML = `<div class="att-empty">No ministries or events yet - add them under Gathering types</div>`;
      return;
    }
    el.innerHTML = list
      .map((g) => {
        const isEvent = g.rows[0]?.gathering_category?.slug === "special_event" || types.find((t) => `t${t.id}` === g.key)?.gathering_category_id === categories.special_event?.id;
        return `
          <a class="att-glance-item" href="${base}/${isEvent ? "events" : "ministries"}?gatheringFilter=${encodeURIComponent(g.name)}">
            ${UI.avatarTile(A.escapeHtml(g.icon), g.color)}
            <span class="att-glance-text">
              <strong>${A.escapeHtml(g.name)}</strong>
              <small>${g.times ? `Met ${g.timesThisYear}× this year · avg ${g.average.toLocaleString()}` : "Not held yet"}${g.last ? ` · last ${A.formatDate(g.last, { day: "numeric", month: "short" })}` : ""}</small>
            </span>
            ${UI.pill(g.status.label, g.status.color)}
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
      onSaved: async () => {
        await loadRecords();
        renderAll();
      },
    });
  }

  return { init };
})();

window.AttendanceOverview = AttendanceOverview;
