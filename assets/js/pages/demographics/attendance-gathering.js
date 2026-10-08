/**
 * ============================================================================
 * PAGE - GATHERING (church/attendance/gathering.php?type= | ?name=)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One ministry or event over time, for the period picked on top (year,
 * month, range or all time - the shared picker): how often it met, how many
 * came, every meeting as a stacked chart, who attends, meetings per month
 * (so gaps show), every meeting in a table linking to its record page, and
 * "What we noticed". Export gives this ministry's report; Record a meeting
 * opens the entry form with this gathering chosen.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, AttendanceFormShared,
 * Toast, ApexCharts, jQuery + Select2
 * ============================================================================
 */

const AttendanceGathering = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AttendanceFormShared;
  const GROUP_COLORS = { adults_count: "primary", youth_count: "success", children_male_count: "purple", children_female_count: "pink" };
  const STATUS = { active: ["Active", "success"], quiet: ["Quiet 60+ days", "danger"], never: ["Not held yet", "secondary"] };

  let target = {};
  let picker = null;
  let data = null;
  const charts = [];
  const base = () => `${AppConfig.FRONTEND_BASE_URL}/church/attendance`;
  /** "in 2026", "in Jan 2025 - Aug 2026", or "since records began". */
  const when = () => (picker.state().year === "all" ? "since records began" : `in ${picker.label()}`);

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    const params = new URLSearchParams(window.location.search);
    target = params.get("type") ? { gathering_type_id: params.get("type") } : params.get("name") ? { name: params.get("name") } : null;
    if (!target) {
      showMissing("No ministry or event was chosen.");
      return;
    }
    const years = await DemographicsAPIHandler.getFiscalYears();
    picker = UI.renderPeriodPicker({
      yearId: "periodYear",
      monthId: "periodMonth",
      monthWrapId: "periodMonthWrap",
      fromId: "periodFrom",
      toId: "periodTo",
      rangeWrapId: "periodRangeWrap",
      years: years.success ? years.data || [] : [],
      onChange: load,
    });
    await load();
  }

  function showMissing(text) {
    document.getElementById("gatheringBody").innerHTML = `
      <div class="card custom-card"><div class="card-body">
        <div class="list-empty">
          <span class="list-empty-icon bg-danger text-white"><i class="ri-file-search-line"></i></span>
          <div class="fw-semibold mt-2">${A.escapeHtml(text)}</div>
          <a href="${base()}/ministries" class="btn btn-primary btn-sm mt-3">Ministry gatherings</a>
        </div>
      </div></div>`;
  }

  async function load() {
    const body = document.getElementById("gatheringBody");
    body.classList.add("is-loading");
    document.getElementById("periodLabel").textContent = picker.label();
    const res = await DemographicsAPIHandler.getGatheringDetail(USER_TERRITORY.id, { ...target, ...picker.filters() });
    body.classList.remove("is-loading");
    if (!res.success) {
      if (res.status === 404 || res.status === 422) showMissing(res.message || "That ministry or event couldn't be found.");
      else Toast.error(res.message || "Couldn't load this gathering");
      return;
    }
    data = res.data;
    charts.splice(0).forEach((c) => c && c.destroy());
    renderHead();
    renderStats();
    renderCharts();
    renderMeetings();
    UI.renderInsightList("gatheringInsights", data.insights);
  }

  // ==========================================================================
  // HEADER
  // ==========================================================================

  function renderHead() {
    const g = data.gathering;
    const s = data.summary;
    const isEvent = g.category_slug === "special_event";
    const [label, color] = STATUS[s.status] || STATUS.never;
    document.title = `${g.name} - Makueni West Diocese`;
    document.getElementById("gatheringIcon").innerHTML = UI.avatarTile(A.escapeHtml(g.icon || (isEvent ? "ri-star-line" : "ri-group-line")), UI.colorFor(g.name));
    document.getElementById("gatheringTitle").textContent = g.name;
    document.getElementById("gatheringSub").innerHTML = `
      <span class="soft-chip soft-${isEvent ? "purple" : "success"}">${A.escapeHtml(g.category || (isEvent ? "Special event" : "Ministry gathering"))}</span>
      ${UI.pill(label, color)}
      ${g.is_active ? "" : UI.pill("Inactive", "danger")}
      <span>${s.times_ever} ${s.times_ever === 1 ? "meeting" : "meetings"} recorded in all</span>`;
    // The ministry that meets as this gathering (Ministries, P4).
    if (g.type_id && typeof MinistryLinks !== "undefined") {
      MinistryLinks.byType().then((byType) => {
        const m = byType.get(String(g.type_id));
        if (m) document.getElementById("gatheringSub").insertAdjacentHTML("beforeend", MinistryLinks.chip(m, `Ministry: ${m.name} · ${m.members} serve - open it`));
      });
    }
    const back = document.getElementById("gatheringBack");
    back.href = `${base()}/${isEvent ? "events" : "ministries"}`;
    back.innerHTML = `<i class="ri-arrow-left-line me-1"></i>${isEvent ? "Special Events" : "Ministry Gatherings"}`;

    const exportBtn = document.getElementById("exportReportBtn");
    if (exportBtn) {
      exportBtn.hidden = !g.type_id;
      exportBtn.dataset.reportKey = isEvent ? "attendance.events" : "attendance.ministries";
      UI.syncExportButton({ gatheringTypeId: g.type_id || "", reportTitle: g.name, ...picker.exportParams() });
    }
    const recordBtn = document.getElementById("gatheringRecord");
    if (recordBtn) {
      const can = (CAN_WRITE_ATTENDANCE || {})[g.category_slug];
      recordBtn.hidden = !can;
      recordBtn.onclick = openNew;
    }
  }

  // ==========================================================================
  // KPIs + CHARTS
  // ==========================================================================

  function renderStats() {
    const s = data.summary;
    const prev = data.period.previous_label;
    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-calendar-check-line",
        label: "Times met",
        value: s.times.toLocaleString(),
        color: "primary",
        delta: s.previous_times != null ? UI.periodDelta(s.times, s.previous_times, { percent: false, prevLabel: prev }) : null,
        sub: when(),
      },
      {
        icon: "ri-team-line",
        label: "Average attendance",
        value: s.average == null ? "-" : s.average.toLocaleString(),
        color: "success",
        delta: s.average != null && s.previous_average ? UI.periodDelta(s.average, s.previous_average, { prevLabel: prev }) : null,
        sub: s.share != null ? `${s.share}% of all ${data.gathering.category_slug === "special_event" ? "event" : "ministry"} attendance` : "",
      },
      {
        icon: "ri-trophy-line",
        label: "Most attended",
        value: s.peak ? s.peak.total.toLocaleString() : "-",
        color: "secondary",
        sub: s.peak ? A.formatDate(s.peak.date, { weekday: "short", day: "numeric", month: "short", year: "numeric" }) : "No meeting in this period",
      },
      {
        icon: "ri-time-line",
        label: "Last met",
        value: s.last ? A.formatDate(s.last, { day: "numeric", month: "short" }) : "Never",
        color: s.status === "quiet" ? "danger" : s.status === "never" ? "secondary" : "purple",
        sub: s.last ? `${Math.floor((A.parseIso(A.todayIso()) - A.parseIso(s.last)) / 86400000)} days ago` : "Nothing recorded yet",
      },
    ]);
  }

  function renderCharts() {
    const meetings = [...data.meetings].reverse();
    const empty = (id, text) => (document.getElementById(id).innerHTML = `<div class="att-empty">${text}</div>`);

    if (!meetings.length) {
      empty("meetingsChart", `No meetings ${A.escapeHtml(when())}`);
      empty("whoDonut", `No meetings ${A.escapeHtml(when())}`);
    } else {
      document.getElementById("meetingsChart").innerHTML = "";
      const c = UI.renderTrendChart("meetingsChart", {
        type: "area",
        stacked: true,
        categories: meetings.map((m) => A.formatDate(m.date, { day: "numeric", month: "short" })),
        series: A.GROUPS.map((g) => ({ name: g.label, data: meetings.map((m) => m[g.key]) })),
        colors: A.GROUPS.map((g) => UI.cssColor(g.color)),
      });
      charts.push(c);
      charts.push(
        UI.renderRingDonut("whoDonut", {
          labels: data.composition.map((g) => g.label),
          series: data.composition.map((g) => g.average),
          colors: data.composition.map((g) => GROUP_COLORS[g.key]),
          centerLabel: "Avg meeting",
          centerValue: data.summary.average,
        }),
      );
    }

    const monthly = data.monthly;
    const monthsEl = document.getElementById("monthlyChart");
    if (!monthly.length) {
      empty("monthlyChart", "No months to show yet");
    } else {
      monthsEl.innerHTML = "";
      const primary = UI.cssColor("primary");
      const chart = new ApexCharts(monthsEl, {
        chart: { type: "bar", height: 240, toolbar: { show: false }, foreColor: UI.chartTextColor(), fontFamily: "inherit" },
        series: [{ name: "Meetings", data: monthly.map((m) => m.meetings) }],
        xaxis: { categories: monthly.map((m) => m.label), labels: { style: { fontSize: "11px" } } },
        yaxis: { tickAmount: Math.max(1, Math.max(...monthly.map((m) => m.meetings))), labels: { formatter: (v) => Math.round(v) } },
        colors: monthly.map((m) => (m.meetings ? primary : UI.cssColor("danger"))),
        plotOptions: { bar: { distributed: true, columnWidth: "55%", borderRadius: 4 } },
        dataLabels: { enabled: false },
        legend: { show: false },
        grid: { borderColor: UI.withAlpha(primary, 0.08) },
        tooltip: { y: { formatter: (v, { dataPointIndex }) => (v ? `${v} meeting${v === 1 ? "" : "s"} · avg ${monthly[dataPointIndex].average}` : "Didn't meet") } },
      });
      chart.render();
      charts.push(chart);
      const without = monthly.filter((m) => !m.meetings).length;
      document.getElementById("monthlySub").textContent = without ? `${without} of ${monthly.length} months without a meeting` : "It met every month";
    }
  }

  function renderMeetings() {
    const tbody = document.getElementById("meetingsBody");
    const rows = data.meetings;
    document.getElementById("meetingsSub").textContent = `${rows.length} ${when()}, newest first - tap one to open it`;
    tbody.innerHTML = rows.length
      ? rows
          .map(
            (m) => `
          <tr data-row-id="${m.id}">
            <td><a class="fw-semibold" href="${base()}/record?id=${m.id}">${A.formatDate(m.date, { day: "numeric", month: "short", year: "numeric" })}</a><div class="fs-12">${A.formatDate(m.date, { weekday: "long" })}</div></td>
            <td class="d-none d-md-table-cell">${m.adults_count}</td>
            <td class="d-none d-md-table-cell">${m.youth_count}</td>
            <td class="d-none d-md-table-cell">${m.children_male_count + m.children_female_count}<div class="fs-12">${m.children_male_count} ${m.children_male_count === 1 ? "boy" : "boys"} · ${m.children_female_count} ${m.children_female_count === 1 ? "girl" : "girls"}</div></td>
            <td><span class="fw-bold fs-15">${m.total.toLocaleString()}</span>${m.change == null ? "" : UI.changePill(m.total, m.total - m.change)}</td>
            <td class="list-notes d-none d-lg-table-cell">${m.notes ? `<span title="${A.escapeHtml(m.notes)}">${A.escapeHtml(m.notes)}</span>` : "-"}</td>
            <td class="text-end"><a class="btn btn-sm btn-primary-light" href="${base()}/record?id=${m.id}" title="Open" aria-label="Open"><i class="ri-arrow-right-line"></i></a></td>
          </tr>`,
          )
          .join("")
      : UI.renderTableEmpty(7, `No meetings ${A.escapeHtml(when())}`, "ri-calendar-line");
  }

  // ==========================================================================
  // RECORD A MEETING
  // ==========================================================================

  async function openNew() {
    const g = data.gathering;
    const categories = await DemographicsAPIHandler.getGatheringCategories();
    const category = (categories.success ? categories.data || [] : []).find((c) => c.slug === g.category_slug);
    if (!category) return;
    const [recs, types] = await Promise.all([
      DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { gathering_category_id: category.id }),
      DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, { gathering_category_id: category.id }),
    ]);
    A.openEntryModal({
      gatheringCategoryId: category.id,
      isWeekly: false,
      territoryId: USER_TERRITORY.id,
      record: null,
      records: recs.success ? recs.data || [] : [],
      types: types.success ? types.data || [] : [],
      icon: g.category_slug === "special_event" ? "ri-star-line" : "ri-group-line",
      categoryLabel: g.category_slug === "special_event" ? "Special event" : "Ministry gathering",
      defaultTypeId: g.type_id,
      defaultName: g.type_id ? null : g.name,
      onSaved: async (saved) => {
        await load();
        UI.flashRow(saved?.id);
      },
    });
  }

  return { init };
})();

window.AttendanceGathering = AttendanceGathering;
