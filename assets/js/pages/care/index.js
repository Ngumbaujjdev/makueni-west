/**
 * ============================================================================
 * PASTORAL CARE - the page (index.php)
 * ============================================================================
 * Four crisp cards (visits this month, open cases, prayer, in hospital);
 * Needs care - urgent cases, next steps this week (late in red), members
 * nobody has visited for a while - each with Record or Open; care by kind
 * over the year, who gave care, and the latest records.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const C = CareUI;
  const K = PeopleKit;
  const CTX = window.CARE_CTX;
  const $ = (id) => document.getElementById(id);
  const num = (n) => Number(n || 0).toLocaleString("en-GB");
  const initials = (name) => String(name || "?").split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();

  function cards(o) {
    const prayers = o.prayer_open + o.prayer_answered;
    K.statRow($("statCardsRow"), [
      { icon: "ri-home-heart-line", label: "Visits this month", sub: `${num(o.visits_last_month)} last month`, value: num(o.visits_this_month), color: "success", delta: UI.periodDelta(o.visits_this_month, o.visits_last_month), series: { labels: o.months, data: o.visits_series } },
      { icon: "ri-folder-open-line", label: "Open cases", sub: o.open_high ? `${num(o.open_high)} urgent` : "Nothing urgent", value: num(o.open), color: "primary" },
      { icon: "ri-hand-heart-line", label: "Prayer", sub: `${num(o.prayer_answered)} answered this year`, value: num(o.prayer_open), color: "pink", bar: { pct: prayers ? Math.round((o.prayer_answered / prayers) * 100) : 0, text: "answered" } },
      { icon: "ri-hospital-line", label: "In hospital now", sub: o.in_hospital ? "See who, and when they were last visited" : "Nobody we know of", value: num(o.in_hospital), color: "danger" },
    ]);
  }

  function needs(o) {
    const late = o.needs.filter((n) => n.due).length;
    $("needsHead").innerHTML = `${late ? `<span class="soft-chip soft-danger"><i class="ri-alarm-warning-line"></i>${late} late or today</span>` : ""}`;
    $("needsSub").textContent = `Urgent cases, next steps this week, and members nobody has visited in ${o.not_contacted_days} days`;
    if (!o.needs.length) {
      $("needsBody").innerHTML = `<div class="vs-due-none"><span class="avatar avatar-sm avatar-rounded bg-success text-white"><i class="ri-check-line"></i></span><span>Nobody waiting. Urgent cases and next steps show here.</span></div>`;
      return;
    }
    $("needsBody").innerHTML = `<div class="vs-due-grid">${o.needs
      .map((n) => {
        const why = n.why === "not_contacted" ? `<span class="vs-due"><i class="ri-time-line"></i>No visit in ${o.not_contacted_days}+ days</span>` : n.why === "high" ? '<span class="vs-due is-late"><i class="ri-flashlight-line"></i>Urgent</span>' : C.dueChip(n.next_on);
        const href = n.id ? `${CTX.baseUrl}/case?id=${n.id}` : `${CTX.membersUrl}/member?id=${n.person.id}`;
        const action = CTX.can.manage
          ? n.id
            ? `<a class="btn btn-sm btn-primary" href="${href}&act=contact"><i class="ri-add-line me-1"></i>Visit</a>`
            : `<button type="button" class="btn btn-sm btn-primary" data-record="${n.person.id}" data-name="${C.esc(n.who)}"><i class="ri-add-line me-1"></i>Record</button>`
          : "";
        return `<div class="vs-due-tile${n.due || n.why === "high" ? " is-late" : ""}">
          <div class="d-flex align-items-center gap-2">
            ${n.type ? C.typeTile(n.type, "sm") : `<span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(n.who)} text-white">${C.esc(initials(n.who))}</span>`}
            <a class="min-w-0 flex-fill mb-link" href="${href}"><strong>${C.esc(n.who)}</strong><small>${n.type ? C.esc(C.TYPES[n.type]?.label || "") : "Member"}${n.person?.area ? ` · ${C.esc(n.person.area)}` : ""}</small></a>
          </div>
          <div class="vs-due-tile-foot">${why}${action}</div>
        </div>`;
      })
      .join("")}</div>`;
  }

  function typeChart(o) {
    const el = $("typeChart");
    el.classList.remove("skel-chart");
    el.innerHTML = "";
    const series = o.by_type.filter((t) => t.data.some(Boolean));
    if (!series.length) {
      el.innerHTML = MembersUI.empty("ri-bar-chart-2-line", "No care recorded yet", "Record a visit, a call or a prayer, and the months show here.");
      return;
    }
    typeChartObj = new ApexCharts(el, {
      chart: { type: "bar", height: chartHeight, stacked: true, toolbar: { show: false }, fontFamily: "inherit" },
      plotOptions: { bar: { columnWidth: "45%", borderRadius: 3 } },
      series: series.map((t) => ({ name: t.label, data: t.data })),
      colors: series.map((t) => UI.cssColor(C.TYPES[t.key]?.color || "primary")),
      xaxis: { categories: o.months },
      yaxis: { labels: { formatter: (v) => Math.round(v) } },
      dataLabels: { enabled: false },
      legend: { position: "bottom" },
      grid: { borderColor: "rgba(var(--dark-rgb), .06)" },
    });
    typeChartObj.render().then(fitChart);
  }

  // The chart grows to the height of the cards beside it, so no empty space opens under it.
  let typeChartObj = null;
  let chartHeight = 300;
  function fitChart() {
    if (!typeChartObj) return;
    const side = $("careSide").querySelectorAll(".card");
    const wide = window.matchMedia("(min-width: 1200px)").matches;
    const gap = wide && side.length ? side[side.length - 1].getBoundingClientRect().bottom - $("typeCard").getBoundingClientRect().bottom : 0;
    const h = wide ? Math.max(300, Math.round(chartHeight + gap)) : 300;
    if (Math.abs(h - chartHeight) < 3) return;
    chartHeight = h;
    typeChartObj.updateOptions({ chart: { height: h } }, false, false);
  }
  let fitTimer = null;
  window.addEventListener("resize", () => {
    clearTimeout(fitTimer);
    fitTimer = setTimeout(fitChart, 200);
  });

  function leaders(o) {
    if (!o.by_leader.length) {
      $("leaders").innerHTML = '<p class="mb-0 fw-semibold">Nobody yet this year.</p>';
      return;
    }
    const top = Math.max(...o.by_leader.map((l) => l.count));
    $("leaders").innerHTML = `<div class="mb-areas">${o.by_leader
      .map((l) => `<div class="mb-area-row"><span class="mb-area-name">${C.esc(l.name)}</span><span class="mb-area-bar"><i style="width:${Math.max(4, Math.round((l.count / top) * 100))}%"></i></span><strong>${num(l.count)}</strong></div>`)
      .join("")}</div>`;
  }

  async function latest() {
    const res = await CareAPI.list({});
    const items = res.ok ? res.data.items.slice(0, 6) : [];
    // The YNEX dashboard's Recent Activity: a dot in the kind's colour, a dashed line down to the next.
    $("latest").innerHTML = items.length
      ? `<ul class="list-unstyled mb-0 crm-recent-activity budget-timeline cr-activity">${items
          .map((r) => {
            const t = C.TYPES[r.type] || C.TYPES.concern;
            const sub = r.hospital || (r.author ? `By ${r.author}` : "");
            return `<li class="crm-recent-activity-content" style="--tl-rgb: var(--${t.color}-rgb)">
              <div class="d-flex align-items-top">
                <div class="me-3"><span class="avatar avatar-xs avatar-rounded cr-activity-dot"><i class="${t.icon}"></i></span></div>
                <div class="crm-timeline-content">
                  <a class="fw-semibold mb-link" href="${CTX.baseUrl}/case?id=${r.id}">${C.esc(r.who)}</a>
                  <span class="cr-activity-kind">${C.esc(t.label)}</span>${r.priority === "high" ? '<span class="cr-activity-urgent">Urgent</span>' : ""}${r.confidential ? '<i class="ri-lock-2-line cr-activity-lock" title="Confidential"></i>' : ""}
                  ${sub ? `<span class="d-block cr-activity-sub">${C.esc(sub)}</span>` : ""}
                </div>
                <div class="ms-2 flex-shrink-0 text-end"><span class="cr-activity-when">${ago(r.on)}</span></div>
              </div>
            </li>`;
          })
          .join("")}</ul>`
      : '<p class="mb-0 fw-semibold">Nothing recorded yet.</p>';
    fitChart();
  }

  /** "Today", "2 days ago", "26 Sept". */
  function ago(iso) {
    const days = Math.round((new Date(`${C.todayIso()}T12:00:00`) - new Date(`${iso}T12:00:00`)) / 86400000);
    if (days <= 0) return "Today";
    if (days === 1) return "Yesterday";
    if (days < 7) return `${days} days ago`;
    return new Date(`${iso}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" });
  }

  async function load() {
    const res = await CareAPI.overview();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      return;
    }
    cards(res.data);
    needs(res.data);
    typeChart(res.data);
    leaders(res.data);
    latest();
  }

  function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("recordBtn")?.addEventListener("click", () => C.recordWindow({ userId: CTX.userId, onDone: (r) => (window.location.href = `${CTX.baseUrl}/case?id=${r.id}`) }));
    $("needsBody").addEventListener("click", (e) => {
      const b = e.target.closest("[data-record]");
      if (b) C.recordWindow({ person: { id: Number(b.dataset.record), name: b.dataset.name }, userId: CTX.userId, onDone: () => load() });
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
