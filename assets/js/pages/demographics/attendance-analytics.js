/**
 * ============================================================================
 * PAGE - ATTENDANCE ANALYTICS (church/attendance/analytics.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * How attendance is going for a year, one month, or all time. One request
 * (GET /attendance-reports/analytics - AttendanceData on the API, the same
 * numbers the attendance reports use) fills:
 *   - the summary strip: average Sunday, Sundays recorded, gatherings held,
 *     members at a typical Sunday
 *   - four tabs: Sunday service, Ministries, Special events, Children -
 *     each with its charts and "What we noticed" (insights + recommendations)
 *
 * Charts are drawn when their tab is first shown (ApexCharts mis-sizes in a
 * hidden pane) and redrawn after a period change. Period and tab live in
 * the URL (?year=2026&month=3&tab=children) - nothing reloads the page.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery + Select2, Bootstrap tabs
 * ============================================================================
 */

const AttendanceAnalytics = (function () {
  "use strict";

  const UI = DemographicsUI;
  const GROUP_COLORS = { adults_count: "primary", youth_count: "success", children_male_count: "purple", children_female_count: "pink" };
  const TABS = ["sunday", "ministries", "events", "children"];
  /** Export gives the open tab's report. */
  const REPORT_FOR_TAB = { sunday: "attendance.sunday", ministries: "attendance.ministries", events: "attendance.events", children: "attendance.children" };

  let picker = null;
  let data = null;
  let tab = "sunday";
  const charts = [];
  const drawn = new Set();

  const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const fmtDate = (iso, opts = { day: "numeric", month: "short", year: "numeric" }) => new Date(`${iso}T00:00:00`).toLocaleDateString("en-GB", opts);
  const n = (v) => (v == null ? "-" : Number(v).toLocaleString());

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    const params = new URLSearchParams(window.location.search);
    tab = TABS.includes(params.get("tab")) ? params.get("tab") : "sunday";

    const res = await DemographicsAPIHandler.getFiscalYears();
    picker = UI.renderPeriodPicker({
      yearId: "periodYear",
      monthId: "periodMonth",
      monthWrapId: "periodMonthWrap",
      fromId: "periodFrom",
      toId: "periodTo",
      rangeWrapId: "periodRangeWrap",
      years: res.success ? res.data || [] : [],
      onChange: load,
    });
    wireTabs();
    await load();
  }

  // ==========================================================================
  // PERIOD (shared picker - DemographicsUI.renderPeriodPicker)
  // ==========================================================================

  function syncUrl() {
    const params = new URLSearchParams(window.location.search);
    tab !== "sunday" ? params.set("tab", tab) : params.delete("tab");
    const qs = params.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
    UI.syncExportButton({ reportKey: REPORT_FOR_TAB[tab], ...picker.exportParams() });
  }

  async function load() {
    syncUrl();
    document.getElementById("analyticsBody").classList.add("is-loading");
    const res = await DemographicsAPIHandler.getAttendanceAnalytics(USER_TERRITORY.id, picker.filters());
    document.getElementById("analyticsBody").classList.remove("is-loading");
    if (!res.success) {
      Toast.error(res.message || "Couldn't load attendance analytics");
      return;
    }
    data = res.data;
    charts.splice(0).forEach((c) => c && c.destroy());
    drawn.clear();
    document.getElementById("periodLabel").textContent = data.period.label;
    renderSummary();
    renderTabFigures();
    drawTab(tab);
  }

  // ==========================================================================
  // SUMMARY STRIP
  // ==========================================================================

  function renderSummary() {
    const s = data.summary;
    const prev = data.period.previous_label;
    const c = s.coverage;
    const m = s.membership;
    const missing = c.elapsed - c.recorded;
    UI.renderStatCardsRow("summaryCardsRow", [
      {
        icon: "ri-team-line",
        label: "Average Sunday",
        value: n(s.sunday_average),
        color: "primary",
        delta: s.sunday_average != null && s.previous_sunday_average ? UI.periodDelta(s.sunday_average, s.previous_sunday_average, { prevLabel: prev }) : null,
        sub: s.sunday_average == null ? "No Sundays recorded" : s.peak ? `Best ${n(s.peak.total)} on ${fmtDate(s.peak.date, { day: "numeric", month: "short" })}` : "",
      },
      {
        icon: "ri-calendar-check-line",
        label: "Sundays recorded",
        value: c.elapsed ? `${c.recorded} of ${c.elapsed}` : "-",
        color: !c.elapsed ? "secondary" : missing ? (c.percentage < 75 ? "danger" : "secondary") : "success",
        sub: !c.elapsed ? "No Sundays yet in this period" : missing ? `${missing} not recorded · ${c.percentage}%` : "Every Sunday recorded",
      },
      {
        icon: "ri-group-line",
        label: "Gatherings & events",
        value: n(s.gatherings_held),
        color: "purple",
        delta: s.previous_gatherings_held != null ? UI.periodDelta(s.gatherings_held, s.previous_gatherings_held, { percent: false, prevLabel: prev }) : null,
        sub: "Ministry meetings and special events",
      },
      {
        icon: "ri-user-heart-line",
        label: "Members on a Sunday",
        value: m && m.rate != null ? `${m.rate}%` : "-",
        color: !m || m.rate == null ? "secondary" : m.rate >= 70 ? "success" : m.rate >= 40 ? "secondary" : "danger",
        sub: m ? `Of ${n(m.total_members)} members${m.as_of ? ` (${esc(m.as_of)})` : ""}` : "No approved demographics yet",
      },
    ]);
  }

  /** The live figure under each tab's name. */
  function renderTabFigures() {
    const s = data.summary;
    const c = s.coverage;
    const heldOf = (items) => `${items.filter((g) => g.times > 0).length} of ${items.length} met`;
    const events = data.events.items.reduce((sum, g) => sum + g.times, 0);
    const ch = data.children;
    const figures = {
      sunday: s.sunday_average == null ? "No Sundays recorded" : `Avg ${n(s.sunday_average)}${c.elapsed ? ` · ${c.recorded} of ${c.elapsed}` : ""}`,
      ministries: data.ministries.items.length ? heldOf(data.ministries.items) : "None set up",
      events: events ? `${events} held` : "None held",
      children: ch.boys + ch.girls ? `${n(ch.boys + ch.girls)} a Sunday${ch.girls_share != null ? ` · ${ch.girls_share}% girls` : ""}` : "None recorded",
    };
    Object.entries(figures).forEach(([key, text]) => {
      const el = document.querySelector(`[data-tab-figure="${key}"]`);
      if (el) el.textContent = text;
    });
  }

  // ==========================================================================
  // TABS
  // ==========================================================================

  function wireTabs() {
    TABS.forEach((t) => {
      const btn = document.getElementById(`tab-${t}-btn`);
      btn.addEventListener("shown.bs.tab", () => {
        tab = t;
        syncUrl();
        if (data) drawTab(t);
      });
    });
    if (tab !== "sunday") bootstrap.Tab.getOrCreateInstance(document.getElementById(`tab-${tab}-btn`)).show();
  }

  function drawTab(t) {
    if (drawn.has(t) || !data) return;
    drawn.add(t);
    ({ sunday: drawSunday, ministries: () => drawGatherings("ministries"), events: () => drawGatherings("events"), children: drawChildren })[t]();
  }

  function chart(el, options) {
    const target = typeof el === "string" ? document.getElementById(el) : el;
    if (!target || typeof ApexCharts === "undefined") return null;
    target.innerHTML = "";
    const c = new ApexCharts(target, {
      ...options,
      chart: { toolbar: { show: false }, foreColor: UI.chartTextColor(), fontFamily: "inherit", animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") }, ...options.chart },
    });
    c.render();
    charts.push(c);
    return c;
  }

  /** Keep a helper-drawn chart so a period change can destroy it. */
  const track = (c) => (c && charts.push(c), c);

  const empty = (id, text) => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = `<div class="att-empty">${text}</div>`;
  };

  // ==========================================================================
  // SUNDAY SERVICE
  // ==========================================================================

  function drawSunday() {
    const sd = data.sunday;
    const weekly = sd.weekly;

    document.getElementById("sundayTopChips").innerHTML = sd.top.length
      ? sd.top.map((t, i) => `<span class="soft-chip soft-${["success", "primary", "purple"][i]}">${i === 0 ? "Best" : `#${i + 1}`} <b>${n(t.total)}</b> ${fmtDate(t.date, { day: "numeric", month: "short" })}</span>`).join("")
      : "";

    if (!weekly.length) {
      ["weeklyChart", "heatmapChart", "whoDonut"].forEach((id) => empty(id, "No Sundays recorded in this period"));
    } else {
      document.getElementById("weeklyChart").innerHTML = "";
      track(UI.renderTrendChart("weeklyChart", {
        type: "area",
        stacked: true,
        categories: weekly.map((w) => fmtDate(w.date, { day: "numeric", month: "short" })),
        series: sd.composition.map((g) => ({ name: g.label, data: weekly.map((w) => w[g.key]) })),
        colors: sd.composition.map((g) => UI.cssColor(GROUP_COLORS[g.key])),
      }));
      drawHeatmap(sd.heatmap);
      track(UI.renderRingDonut("whoDonut", {
        labels: sd.composition.map((g) => g.label),
        series: sd.composition.map((g) => g.average),
        colors: sd.composition.map((g) => GROUP_COLORS[g.key]),
        centerLabel: "Avg Sunday",
      }));
    }

    // Coverage ring + the Sundays that weren't recorded.
    const c = data.summary.coverage;
    if (!c.elapsed) {
      empty("coverageRing", "No Sundays have happened in this period yet");
    } else {
      track(UI.renderRingDonut("coverageRing", { labels: ["Recorded", "Not recorded"], series: [c.recorded, c.elapsed - c.recorded], colors: ["success", "danger"], centerLabel: "Sundays" }));
    }
    const missingEl = document.getElementById("missingSundays");
    missingEl.innerHTML = c.missing.length
      ? `<div class="att-missing-title">Not recorded</div><div class="att-missing">${c.missing
          .slice(0, 12)
          .map((d) => `<a class="soft-chip soft-danger" href="${AppConfig.FRONTEND_BASE_URL}/church/attendance/services?year=${d.substring(0, 4)}&sundayStatusFilter=Not+recorded" title="Record it on Sunday Services">${fmtDate(d, { day: "numeric", month: "short" })}</a>`)
          .join("")}${c.missing.length > 12 ? `<span class="soft-chip soft-secondary">+${c.missing.length - 12} more</span>` : ""}</div>`
      : c.elapsed
        ? '<div class="att-missing-title text-success"><i class="ri-checkbox-circle-fill me-1"></i>Every Sunday recorded</div>'
        : "";

    // Month by month.
    const tbody = document.getElementById("monthsBody");
    tbody.innerHTML = sd.months.length
      ? [...sd.months]
          .reverse()
          .map((m, i, arr) => {
            const before = arr[i + 1];
            return `
              <tr>
                <td class="fw-semibold">${esc(m.label)}</td>
                <td>${m.sundays}</td>
                <td><b>${n(m.average)}</b>${UI.changePill(m.average, before ? before.average : null)}</td>
                <td>${n(m.best)} <span class="fs-12">${fmtDate(m.best_date, { day: "numeric", month: "short" })}</span></td>
                <td class="d-none d-md-table-cell">${m.children_share}%</td>
              </tr>`;
          })
          .join("")
      : UI.renderTableEmpty(5, "No Sundays recorded in this period", "ri-sun-line");

    UI.renderInsightList("sundayInsights", sd.insights);
  }

  /** Month x week-of-month grid: shaded by attendance, red where a Sunday wasn't recorded. */
  function drawHeatmap(rows) {
    const values = rows.flatMap((r) => r.cells.map((c) => c.total)).filter((v) => v > 0);
    if (!values.length) {
      empty("heatmapChart", "No Sundays recorded in this period");
      return;
    }
    const max = Math.max(...values);
    const min = Math.min(...values);
    const step = Math.max(1, Math.ceil((max - min + 1) / 4));
    const primary = UI.cssColor("primary");
    const ranges = [
      { from: -1, to: -1, color: "rgba(125, 125, 125, 0.1)", name: "No Sunday" },
      { from: 0, to: 0, color: UI.cssColor("danger"), name: "Not recorded" },
      ...[0, 1, 2, 3].map((i) => ({
        from: min + step * i,
        to: i === 3 ? max : min + step * (i + 1) - 1,
        color: UI.withAlpha(primary, 0.3 + i * 0.23),
        name: `${min + step * i}-${i === 3 ? max : min + step * (i + 1) - 1}`,
      })),
    ];
    chart("heatmapChart", {
      chart: { type: "heatmap", height: Math.max(220, rows.length * 30 + 70) },
      series: [...rows].reverse().map((r) => ({ name: r.label, data: r.cells.map((c) => ({ x: `Sun ${c.week}`, y: c.total ?? -1, date: c.date })) })),
      dataLabels: { enabled: true, formatter: (v) => (v > 0 ? v : v === 0 ? "!" : ""), style: { fontSize: "11px", colors: ["#fff"] } },
      plotOptions: { heatmap: { radius: 4, enableShades: false, colorScale: { ranges } } },
      stroke: { width: 2, colors: [getComputedStyle(document.documentElement).getPropertyValue("--custom-white").trim() || "#fff"] },
      legend: { show: true, position: "bottom", fontSize: "11px" },
      tooltip: {
        custom: ({ seriesIndex, dataPointIndex, w }) => {
          const p = w.config.series[seriesIndex].data[dataPointIndex];
          if (!p.date) return '<div class="apex-tip">No Sunday</div>';
          return `<div class="apex-tip"><b>${fmtDate(p.date, { weekday: "short", day: "numeric", month: "short" })}</b><br>${p.y > 0 ? `${p.y.toLocaleString()} attended` : "Not recorded"}</div>`;
        },
      },
    });
  }

  // ==========================================================================
  // MINISTRIES / EVENTS
  // ==========================================================================

  const STATUS = { active: ["Active", "success"], quiet: ["Quiet 60+ days", "danger"], never: ["Not held yet", "secondary"] };

  function drawGatherings(key) {
    const items = data[key].items;
    const held = items.filter((g) => g.times > 0);
    const noun = key === "ministries" ? "ministry" : "event";

    if (!held.length) {
      empty(`${key}Share`, `No ${noun} met in this period`);
      empty(`${key}Bars`, `No ${noun} met in this period`);
    } else {
      const top = held.slice(0, 5);
      const others = held.slice(5).reduce((s, g) => s + g.total, 0);
      track(UI.renderRingDonut(`${key}Share`, {
        labels: [...top.map((g) => g.name), ...(others ? ["Others"] : [])],
        series: [...top.map((g) => g.total), ...(others ? [others] : [])],
        colors: [...top.map((g) => UI.colorFor(g.name)), "secondary"],
        centerLabel: "Attended",
      }));
      const byAvg = [...held].sort((a, b) => b.average - a.average);
      chart(`${key}Bars`, {
        chart: { type: "bar", height: Math.max(200, byAvg.length * 42 + 40) },
        series: [{ name: "Average per meeting", data: byAvg.map((g) => g.average) }],
        xaxis: { categories: byAvg.map((g) => g.name), labels: { formatter: (v) => Math.round(v) } },
        colors: byAvg.map((g) => UI.cssColor(UI.colorFor(g.name))),
        plotOptions: { bar: { horizontal: true, distributed: true, borderRadius: 5, barHeight: "60%" } },
        dataLabels: { enabled: true, style: { fontSize: "11px" } },
        legend: { show: false },
        grid: { borderColor: UI.withAlpha(UI.cssColor("primary"), 0.08) },
        tooltip: { y: { formatter: (v, { dataPointIndex }) => `${v} on average · met ${byAvg[dataPointIndex].times}×` } },
      });
    }

    const tbody = document.getElementById(`${key}Body`);
    tbody.innerHTML = items.length
      ? items
          .map((g) => {
            const [label, color] = STATUS[g.status] || STATUS.never;
            return `
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    ${UI.avatarTile(esc(g.icon || (key === "events" ? "ri-star-line" : "ri-group-line")), UI.colorFor(g.name))}
                    <span class="fw-semibold">${esc(g.name)}</span>
                  </div>
                </td>
                <td>${g.times}</td>
                <td><b>${g.times ? n(g.average) : "-"}</b></td>
                <td class="d-none d-md-table-cell">${g.times ? n(g.peak) : "-"}</td>
                <td class="d-none d-md-table-cell">${g.last ? fmtDate(g.last) : "Never"}</td>
                <td>${UI.pill(label, color)}</td>
              </tr>`;
          })
          .join("")
      : UI.renderTableEmpty(6, `No ${noun === "ministry" ? "ministries" : "events"} set up yet - add them under Gathering types`, "ri-group-line");

    UI.renderInsightList(`${key}Insights`, data[key].insights);
  }

  // ==========================================================================
  // CHILDREN
  // ==========================================================================

  function drawChildren() {
    const ch = data.children;
    const weekly = data.sunday.weekly;
    document.getElementById("childrenChips").innerHTML =
      ch.children_share != null ? `<span class="soft-chip soft-pink">Children are <b>${ch.children_share}%</b> of a Sunday</span>${ch.girls_share != null ? `<span class="soft-chip soft-purple">Girls <b>${ch.girls_share}%</b> of children</span>` : ""}` : "";

    if (!weekly.length) {
      ["childrenWeekly", "childrenDonut", "childrenShare"].forEach((id) => empty(id, "No Sundays recorded in this period"));
    } else {
      chart("childrenWeekly", {
        chart: { type: "bar", height: 300, stacked: true },
        series: [
          { name: "Boys", data: weekly.map((w) => w.children_male_count) },
          { name: "Girls", data: weekly.map((w) => w.children_female_count) },
        ],
        xaxis: { categories: weekly.map((w) => fmtDate(w.date, { day: "numeric", month: "short" })), labels: { rotateAlways: weekly.length > 16, style: { fontSize: "11px" } } },
        yaxis: { labels: { formatter: (v) => Math.round(v) } },
        colors: [UI.cssColor("purple"), UI.cssColor("pink")],
        plotOptions: { bar: { columnWidth: weekly.length > 20 ? "80%" : "50%", borderRadius: 3 } },
        dataLabels: { enabled: false },
        legend: { position: "bottom" },
        grid: { borderColor: UI.withAlpha(UI.cssColor("primary"), 0.08) },
      });
      track(UI.renderRingDonut("childrenDonut", { labels: ["Boys", "Girls"], series: [ch.boys, ch.girls], colors: ["purple", "pink"], centerLabel: "Children a Sunday" }));
      chart("childrenShare", {
        chart: { type: "bar", height: 260 },
        series: [{ name: "Children's share", data: ch.months.map((m) => m.children_share) }],
        xaxis: { categories: ch.months.map((m) => m.label) },
        yaxis: { min: 0, max: Math.min(100, Math.ceil((Math.max(...ch.months.map((m) => m.children_share)) + 10) / 10) * 10), labels: { formatter: (v) => `${Math.round(v)}%` } },
        colors: [UI.cssColor("pink")],
        plotOptions: { bar: { columnWidth: "45%", borderRadius: 5 } },
        dataLabels: { enabled: true, formatter: (v) => `${v}%`, style: { fontSize: "11px" } },
        tooltip: { y: { formatter: (v, { dataPointIndex }) => `${v}% · ${ch.months[dataPointIndex].boys} boys, ${ch.months[dataPointIndex].girls} girls on average` } },
        grid: { borderColor: UI.withAlpha(UI.cssColor("primary"), 0.08) },
      });
    }
    UI.renderInsightList("childrenInsights", ch.insights, { empty: "Boys and girls are well balanced." });
  }

  return { init };
})();

window.AttendanceAnalytics = AttendanceAnalytics;
