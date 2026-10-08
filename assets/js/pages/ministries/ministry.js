/**
 * ============================================================================
 * MINISTRIES - one ministry (ministry.php?id=)
 * ============================================================================
 * The hero (its icon, when it meets, its gathering, its leaders) and what
 * you can do - Add members, Send message, Edit (or When it meets, for its
 * own leader); then Members (pills, Sort, ticks and the bulk bar),
 * Gatherings (from Attendance), Activities (events and initiatives for it)
 * and History. The tab is kept in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const N = MinistriesUI;
  const K = PeopleKit;
  const M = MembersUI;
  const CTX = window.MIN_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("id"));
  const TABS = ["members", "gatherings", "activities", "history"];
  const state = { m: null, tab: TABS.includes(params.get("tab")) ? params.get("tab") : "members", members: null, history: null, gatherings: null, activities: null };
  let kit = null;

  // -------------------------------------------------------------- the hero
  function hero() {
    const m = state.m;
    const can = m.can;
    $("mnHero").innerHTML = `<div class="card-body">
      <div class="ev-hero-row">
        ${N.tile(m, "xl")}
        <div class="flex-fill min-w-0">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${N.esc(m.name)}</h2>${m.active ? "" : '<span class="badge bg-secondary text-dark">Switched off</span>'}${can.mine ? '<span class="soft-chip soft-success"><i class="ri-star-smile-line"></i>You lead it</span>' : ""}</div>
          <div class="d-flex flex-wrap gap-1 mb-1">
            <span class="soft-chip soft-primary"><i class="ri-repeat-line"></i>${N.esc(m.meets || "No set day")}</span>
            ${m.next_meeting ? `<span class="soft-chip soft-primary"><i class="ri-calendar-line"></i>Next: ${N.nextLabel(m.next_meeting)}</span>` : ""}
            ${m.gathering_type ? `<span class="soft-chip soft-success"><i class="ri-links-line"></i>${N.esc(m.gathering_type.name)}</span>` : ""}
          </div>
          <div class="ev-card-meta">${m.leaders.length ? m.leaders.map((l) => `<span><i class="ri-user-star-line"></i>${N.esc(l.name)} · ${N.esc(l.role_label.toLowerCase())}</span>`).join("") : "<span><i class=\"ri-user-star-line\"></i>No leader yet</span>"}</div>
        </div>
        <div class="ev-hero-actions">
          ${can.roster && m.active ? '<button type="button" class="btn btn-primary" data-act="add"><i class="ri-user-add-line me-1"></i>Add members</button>' : ""}
          ${CTX.can.message && m.members ? '<button type="button" class="btn btn-outline-primary" data-act="message"><i class="ri-chat-3-line me-1"></i>Send message</button>' : ""}
          ${can.manage ? '<button type="button" class="btn btn-outline-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Edit</button>' : can.mine ? '<button type="button" class="btn btn-outline-primary" data-act="meets"><i class="ri-repeat-line me-1"></i>When it meets</button>' : ""}
        </div>
      </div>
      <div class="pp-facts mn-hero-facts">${facts(m)}</div>
    </div>`;
    UI.mountSparklines($("mnHero"));
    $("tabMembers").textContent = `${N.num(m.members)} ${m.members === 1 ? "member" : "members"}`;
    $("tabGatherings").textContent = m.gathering_type ? m.gathering_type.name : "Not linked yet";
  }

  /** The hero's facts: who serves, the church's own monthly figure, its attendance and its last gathering. */
  function facts(m) {
    const fact = (icon, color, label, value, sub = "", solid = false) =>
      `<div class="pp-fact" style="--q: var(--${color}-rgb)"><span class="pp-fact-icon${solid ? " is-solid" : ""}"><i class="${icon}"></i></span><div class="min-w-0"><span>${label}</span><strong>${value}${sub ? `<small>${sub}</small>` : ""}</strong></div></div>`;
    const avg = N.average(m.attendance_series);
    const d = m.demographic;
    return [
      fact("ri-group-line", "primary", "Serve in it", N.num(m.members), "In our register", true),
      d
        ? fact("ri-file-chart-line", "purple", `Monthly report · ${N.esc(d.period)}`, `${N.num(d.value)} ${N.esc(d.label)}`, `<a class="mb-link" href="${CTX.siteUrl}/church/demographics-growth/metric?key=${d.metric}">See the trend<i class="ri-arrow-right-up-line ms-1"></i></a>`)
        : fact("ri-file-chart-line", "purple", "Monthly report", "-", m.kind === "music" || m.kind === "prayer" ? "Not counted in the report" : "No report yet"),
      fact("ri-bar-chart-box-line", "success", "Average · 6 months", avg === null ? "-" : N.num(avg), m.gathering_type ? `${N.num(m.gatherings_six_months)} gatherings` : "Not linked to a gathering"),
      fact("ri-time-line", "pink", "Last gathered", m.last_gathering ? N.day(m.last_gathering) : "-", m.next_meeting ? `Next: ${N.nextLabel(m.next_meeting)}` : ""),
    ].join("");
  }

  // -------------------------------------------------------------- members
  function memberRow(p) {
    const href = p.kind === "visitor" ? `${CTX.visitorsUrl}/visitor?id=${p.id}` : `${CTX.membersUrl}/member?id=${p.id}`;
    const name = CTX.can.members ? `<a class="fw-semibold mb-link" href="${href}">${N.esc(p.name)}</a>` : `<span class="fw-semibold">${N.esc(p.name)}</span>`;
    return `<tr class="mb-row" data-id="${p.id}"${CTX.can.members ? ` data-href="${href}"` : ""} data-pills="${[p.congregation || "", p.gender || "", p.kind].join(" ")}">
      ${K.checkCell(p.id, p.name)}
      <td data-search="${N.esc(`${p.name} ${p.phone || ""} ${p.area || ""}`)}" data-order="${N.esc(p.name.toLowerCase())}">
        <div class="d-flex align-items-center gap-2">${M.avatar(p, "sm")}<div class="min-w-0">${name}<div class="mb-sub">${N.esc(p.phone || "No phone")}</div></div></div>
      </td>
      <td data-order="${N.esc((p.area || "~").toLowerCase())}">${p.area ? N.esc(p.area) : '<span class="mb-sub">Not given</span>'}</td>
      <td>${p.kind === "visitor" ? '<span class="soft-chip soft-purple"><i class="ri-user-heart-line"></i>Visitor</span>' : p.congregation ? M.groupChip(p.congregation) : '<span class="mb-sub">Not set</span>'}${p.auto ? ' <span class="soft-chip soft-success" title="Sunday-school children are always in it - change it on their page"><i class="ri-links-line"></i>From the register</span>' : ""}</td>
      <td class="d-none d-md-table-cell">${p.gender ? (p.gender === "male" ? "Male" : "Female") : "-"}</td>
      <td class="d-none d-lg-table-cell" data-order="${p.joined_on || ""}">${M.day(p.joined_on)}</td>
      <td class="text-end">${CTX.can.members ? `<a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a>` : ""}</td>
    </tr>`;
  }

  function memberActions() {
    const byId = new Map(state.members.map((p) => [p.id, p]));
    const list = [];
    if (CTX.can.message) list.push({ key: "sms", label: "Send message", icon: "ri-chat-3-line", primary: true, run: (ids) => K.messagePeople(CTX.messagesUrl, ids.map((x) => byId.get(x)).filter(Boolean)) });
    if (state.m.can.roster)
      list.push({
        key: "remove",
        label: "Take out",
        icon: "ri-user-unfollow-line",
        run: (ids) =>
          K.confirmWindow({
            title: `Take out of ${state.m.name}`,
            subtitle: `${ids.length} ${ids.length === 1 ? "person" : "people"} picked`,
            icon: "ri-user-unfollow-line",
            danger: true,
            go: '<i class="ri-user-unfollow-line me-1"></i>Take them out',
            body: K.parts([{ icon: "ri-information-line", title: "What happens", body: `<p class="mb-0">They leave ${N.esc(state.m.name)} only - they stay in the register and in any other ministry.${ids.some((x) => byId.get(x)?.auto) ? " Sunday-school children stay: the register keeps them in - change it on their page." : ""}</p>` }]),
            run: async () => {
              const res = await MinistriesAPI.removeMembers(id, ids);
              if (res.ok) refresh(true);
              return res;
            },
          }),
      });
    return list;
  }

  async function membersTab() {
    $("mnMain").innerHTML = `<div class="card custom-card">
      <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Members</div><span class="card-subtitle-text">${state.m.can.roster ? "Tick people to message them or take them out" : "Who serves in it"}</span></div>
        ${state.m.can.roster && state.m.active ? '<button type="button" class="btn btn-sm btn-primary" data-act="add"><i class="ri-user-add-line me-1"></i>Add members</button>' : ""}
      </div>
      <div class="card-body pb-0 pt-3" id="mnPills"></div>
      <div class="card-body p-0" id="mnTableWrap">
        <div id="mnFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
          <table class="table table-hover mb-0 pp-table" id="mnTable">
            <thead><tr>${K.checkHead()}<th>Name</th><th>Area</th><th>Part of</th><th class="d-none d-md-table-cell">Gender</th><th class="d-none d-lg-table-cell">Joined it</th><th class="text-end">Action</th></tr></thead>
            <tbody id="mnRows">${UI.renderTableLoading(7)}</tbody>
          </table>
        </div>
      </div>
    </div>`;
    if (!state.members) {
      const res = await MinistriesAPI.members(id);
      if (!res.ok) return ($("mnTableWrap").innerHTML = M.errorBox(res.message));
      state.members = res.data;
    }
    if (state.tab !== "members") return;
    if (!state.members.length) {
      $("mnFilters").innerHTML = "";
      $("mnRows").innerHTML = `<tr><td colspan="7">${M.empty("ri-group-line", "Nobody in it yet", "Add people from the register - members or visitors.", state.m.can.roster && state.m.active ? '<button type="button" class="btn btn-primary" data-act="add"><i class="ri-user-add-line me-1"></i>Add members</button>' : "")}</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "mnTable",
      stripId: "mnFilters",
      pillsId: "mnPills",
      rowsId: "mnRows",
      items: state.members,
      rowHtml: memberRow,
      noun: "members",
      // It opens on the people the ministry is for - Sunday school, women or men; "All" is a tap away.
      defaultPill: { children: "sunday_school", women: "female", men: "male" }[state.m.kind],
      searchPlaceholder: "Search by name, phone or area...",
      pills: [
        { key: "main_church", label: "Main church", icon: "ri-community-line", color: "primary", test: (p) => p.congregation === "main_church" },
        { key: "sunday_school", label: "Sunday school", icon: "ri-book-open-line", color: "pink", test: (p) => p.congregation === "sunday_school" },
        { key: "female", label: "Women", icon: "ri-women-line", color: "pink", test: (p) => p.gender === "female" },
        { key: "male", label: "Men", icon: "ri-men-line", color: "info", test: (p) => p.gender === "male" },
        { key: "visitor", label: "Visitors", icon: "ri-user-heart-line", color: "purple", test: (p) => p.kind === "visitor" },
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "newest", label: "Joined it - newest", order: [[5, "desc"]] },
        { key: "area", label: "Area", order: [[2, "asc"], [1, "asc"]] },
      ],
      nonSortable: [6],
      actions: memberActions(),
    });
  }

  // -------------------------------------------------------------- gatherings
  async function gatheringsTab() {
    $("mnMain").innerHTML = `<div class="row" id="gaCards">${UI.skeletonCards(4, "col-xl-3 col-sm-6")}</div><div id="gaBody"></div>`;
    if (!state.gatherings) {
      const res = await MinistriesAPI.gatherings(id);
      if (!res.ok) return ($("gaBody").innerHTML = M.errorBox(res.message));
      state.gatherings = res.data;
    }
    if (state.tab !== "gatherings") return;
    const g = state.gatherings;
    if (!g.linked || !g.detail) {
      $("gaCards").innerHTML = "";
      $("gaBody").innerHTML = `<div class="card custom-card"><div class="card-body">${M.empty(
        "ri-links-line",
        "Not linked to a gathering yet",
        "Link it to the gathering type its attendance is recorded under (Youth Service, Choir Practice...), and its numbers show here - straight from Attendance.",
        state.m.can.manage ? '<button type="button" class="btn btn-primary" data-act="edit"><i class="ri-links-line me-1"></i>Link a gathering</button>' : "",
      )}</div></div>`;
      return;
    }
    const d = g.detail;
    const s = d.summary;
    K.statRow($("gaCards"), [
      { icon: "ri-calendar-check-line", label: "Times it met", sub: "The last twelve months", value: N.num(s.times), color: "primary", series: { labels: d.monthly.map((x) => x.label), data: d.monthly.map((x) => x.meetings) }, trim: true },
      { icon: "ri-group-line", label: "Average", sub: s.previous_average !== null && s.previous_average !== undefined ? `${N.num(s.previous_average)} the year before` : "People each time", value: s.average === null ? "-" : N.num(s.average), color: "success", delta: s.previous_average ? UI.periodDelta(s.average || 0, s.previous_average) : null },
      { icon: "ri-trophy-line", label: "Highest", sub: s.peak ? N.day(s.peak.date) : "Not yet", value: s.peak ? N.num(s.peak.total) : "-", color: "purple" },
      { icon: "ri-time-line", label: "Last met", sub: s.last ? "" : "Not recorded yet", value: s.last ? N.day(s.last) : "-", color: "pink" },
    ]);
    const rows = [...(d.meetings || d.rows || [])].sort((a, b) => (a.date < b.date ? 1 : -1));
    if (!s.times) {
      $("gaBody").innerHTML = `<div class="card custom-card"><div class="card-body">${M.empty(
        "ri-bar-chart-box-line",
        `Nothing recorded under ${N.esc(state.m.gathering_type.name)} lately`,
        "When its attendance is recorded in Attendance, each month and each meeting show here.",
        `<a class="btn btn-outline-primary" href="${CTX.attendanceUrl}/gathering?type=${state.m.gathering_type.id}"><i class="ri-external-link-line me-1"></i>Open it in Attendance</a>`,
      )}</div></div>`;
      return;
    }
    // The months from the first one it met - no empty months on the left.
    const firstMet = Math.max(0, d.monthly.findIndex((x) => x.meetings));
    const months = d.monthly.slice(firstMet);
    $("gaBody").innerHTML = `<div class="row mn-fill-row">
      <div class="col-xl-7 d-flex"><div class="card custom-card flex-fill">
        <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">Each month</div><span class="card-subtitle-text">The average each time it met</span></div><a class="btn btn-sm btn-outline-primary" href="${CTX.attendanceUrl}/gathering?type=${state.m.gathering_type.id}"><i class="ri-external-link-line me-1"></i>In Attendance</a></div>
        <div class="card-body"><div id="gaChart" style="min-height:300px"></div></div>
      </div></div>
      <div class="col-xl-5 d-flex"><div class="card custom-card flex-fill">
        <div class="card-header justify-content-between"><div><div class="card-title">Each time</div><span class="card-subtitle-text">Newest first - who came, and the change from the time before</span></div><span class="badge bg-primary">${N.num(rows.length)}</span></div>
        <div class="card-body mn-fill-body"><ul class="mn-meetings">${meetingRows(rows, s.peak)}</ul></div>
      </div></div>
    </div>`;
    new ApexCharts($("gaChart"), {
      chart: { type: "bar", height: 300, toolbar: { show: false }, fontFamily: "inherit" },
      plotOptions: { bar: { columnWidth: months.length > 8 ? "50%" : "38%", borderRadius: 5, borderRadiusApplication: "end", dataLabels: { position: "top" } } },
      series: [{ name: "Average", data: months.map((x) => x.average) }],
      colors: [UI.cssColor(state.m.colour)],
      xaxis: { categories: months.map((x) => x.label), axisBorder: { show: false }, axisTicks: { show: false } },
      yaxis: { labels: { formatter: (v) => Math.round(v) } },
      dataLabels: { enabled: true, offsetY: -18, style: { fontSize: "11px", fontWeight: 700, colors: [UI.cssColor("dark")] }, formatter: (v) => (v ? v : "") },
      tooltip: { y: { formatter: (v, { dataPointIndex }) => (months[dataPointIndex].meetings ? `${v} on average · met ${months[dataPointIndex].meetings}x` : "Didn't meet") } },
      grid: { borderColor: "rgba(var(--dark-rgb), .05)", strokeDashArray: 4, xaxis: { lines: { show: false } } },
    }).render();
  }

  /** Each meeting, newest first: its date, how many came, adults · youth · children, and the change from the one before. */
  function meetingRows(rows, peak) {
    if (!rows.length) return '<li class="mn-meeting-none">Nothing recorded in the last twelve months.</li>';
    const week = Date.now() - 7 * 86400000;
    return rows
      .map((r, i) => {
        const before = rows[i + 1];
        const children = r.children_male_count + r.children_female_count;
        const parts = [
          ["Adults", r.adults_count, "primary"],
          ["Youth", r.youth_count, "success"],
          ["Children", children, "warning"],
        ];
        const when = new Date(`${r.date}T12:00:00`);
        const change = before ? r.total - before.total : null;
        return `<li>
          <span class="mn-date${when.getTime() >= week ? "" : " is-past"}"><strong>${when.getDate()}</strong><small>${when.toLocaleDateString("en-GB", { month: "short" })}</small></span>
          <div class="flex-fill min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap"><span class="mn-meeting-total">${N.num(r.total)}</span><span class="mb-sub">came</span>${peak && peak.date === r.date ? '<span class="soft-chip soft-purple"><i class="ri-trophy-line"></i>Highest</span>' : ""}</div>
            <div class="count-bar mn-split" aria-hidden="true">${parts.map(([, v, c]) => (r.total && v ? `<span class="bg-${c}" style="width:${(v / r.total) * 100}%"></span>` : "")).join("")}</div>
            <div class="mn-meeting-parts">${parts.map(([l, v, c]) => `<span><i class="bg-${c}"></i>${l} <b>${N.num(v)}</b></span>`).join("")}</div>
          </div>
          ${change === null ? "" : `<span class="stat-delta is-${change > 0 ? "up" : change < 0 ? "down" : "flat"}"><i class="ri-arrow-${change > 0 ? "up" : change < 0 ? "down" : "right"}-s-fill"></i>${Math.abs(change)}</span>`}
        </li>`;
      })
      .join("");
  }

  // -------------------------------------------------------------- activities
  async function activitiesTab() {
    $("mnMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div><div class="card-title">Activities for it</div><span class="card-subtitle-text">Our events and initiatives meant for ${N.esc(state.m.name.toLowerCase())}, from three months ago</span></div></div><div class="card-body" id="acBody"><span class="skel skel-line"></span></div></div>`;
    if (!state.activities) {
      const res = await MinistriesAPI.activities(id);
      if (!res.ok) return ($("acBody").innerHTML = M.errorBox(res.message));
      state.activities = res.data;
    }
    if (state.tab !== "activities") return;
    const list = state.activities;
    if (!list.length) {
      $("acBody").innerHTML = M.empty("ri-calendar-event-line", "Nothing planned for it yet", `Events and initiatives for ${N.esc(state.m.kind_label.toLowerCase())} (by their audience or kind) show here.`, `<a class="btn btn-outline-primary" href="${CTX.eventsUrl}/new"><i class="ri-add-line me-1"></i>Plan an event</a>`);
      return;
    }
    const item = (a) => {
      const href = a.kind === "initiative" ? `${CTX.initiativesUrl}/initiative?id=${a.id}` : `${CTX.eventsUrl}/event?id=${a.id}`;
      const when = a.starts_at ? new Date(a.starts_at) : null;
      return `<li><span class="mn-date${a.past ? " is-past" : ""}"><strong>${when ? when.getDate() : "-"}</strong><small>${when ? when.toLocaleDateString("en-GB", { month: "short" }) : ""}</small></span>
        <div class="flex-fill min-w-0"><a class="fw-semibold mb-link" href="${href}">${N.esc(a.title)}</a><small>${N.esc([a.kind === "initiative" ? "Initiative" : "Event", a.type_label, a.venue].filter(Boolean).join(" · "))}</small></div>
        ${a.status === "completed" ? '<span class="badge bg-success">Done</span>' : a.past ? "" : '<span class="badge bg-primary">Coming</span>'}</li>`;
    };
    const coming = list.filter((a) => !a.past);
    const past = list.filter((a) => a.past).reverse();
    $("acBody").innerHTML = `${coming.length ? `<h6 class="mn-group-head">Coming up</h6><ul class="mb-mini-list mn-acts">${coming.map(item).join("")}</ul>` : ""}${past.length ? `<h6 class="mn-group-head${coming.length ? " mt-4" : ""}">Recently</h6><ul class="mb-mini-list mn-acts">${past.map(item).join("")}</ul>` : ""}`;
  }

  // -------------------------------------------------------------- history
  async function historyTab() {
    $("mnMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">History</div></div><div class="card-body" id="historyBody"><span class="skel skel-line"></span></div></div>`;
    if (!state.history) {
      const res = await MinistriesAPI.history(id);
      if (!res.ok) return ($("historyBody").innerHTML = M.errorBox(res.message));
      state.history = res.data;
    }
    if (state.tab !== "history") return;
    $("historyBody").innerHTML = state.history.length
      ? `<ul class="ev-history">${state.history.map((h) => `<li><span class="ev-history-dot bg-${UI.colorFor(h.who)}"></span><div><strong>${N.esc(h.sentence)}</strong><small>${new Date(h.at).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" })}</small></div></li>`).join("")}</ul>`
      : '<p class="mb-0 fw-semibold">Nothing changed yet - members added and leaders named show here.</p>';
  }

  // -------------------------------------------------------------- wiring
  function show() {
    kit?.destroy();
    kit = null;
    document.querySelectorAll("#mnTabs [data-tab]").forEach((b) => b.classList.toggle("active", b.dataset.tab === state.tab));
    const q = new URLSearchParams(window.location.search);
    q.delete("tab");
    // The members list keeps its pill, search and sort in the URL; the other tabs don't need them.
    if (state.tab !== "members") {
      ["pill", "q", "sort"].forEach((k) => q.delete(k));
      q.set("tab", state.tab);
    }
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    ({ members: membersTab, gatherings: gatheringsTab, activities: activitiesTab, history: historyTab })[state.tab]();
  }

  async function refresh(membersChanged = false) {
    const res = await MinistriesAPI.get(id);
    if (!res.ok) return Toast.error(res.message);
    state.m = res.data;
    state.history = null;
    state.gatherings = null;
    if (membersChanged) state.members = null;
    hero();
    show();
  }

  function act(name) {
    const m = state.m;
    ({
      add: () => N.addMembersWindow(m, { onDone: () => refresh(true) }),
      message: async () => {
        if (!state.members) state.members = (await MinistriesAPI.members(id)).data || [];
        K.messagePeople(CTX.messagesUrl, state.members);
      },
      edit: async () => {
        if (!state.members) state.members = (await MinistriesAPI.members(id)).data || [];
        N.ministryWindow({ ministry: m, members: state.members, onDone: () => refresh() });
      },
      meets: () => N.meetsWindow(m, { onDone: () => refresh() }),
    })[name]?.();
  }

  async function init() {
    if (!id) return ($("mnHero").innerHTML = `<div class="card-body">${M.errorBox("Open a ministry from Ministries.", `location.href='${CTX.baseUrl}/'`)}</div>`);
    const res = await MinistriesAPI.get(id);
    if (!res.ok) return ($("mnHero").innerHTML = `<div class="card-body">${M.errorBox(res.message, `location.href='${CTX.baseUrl}/'`)}</div>`);
    state.m = res.data;
    document.title = `${state.m.name} - Ministries - Makueni West Diocese`;
    document.querySelector(".page-header-breadcrumb .breadcrumb-item.active, .breadcrumb .active")?.replaceChildren(document.createTextNode(state.m.name));
    $("mnTabs").hidden = false;
    hero();
    show();
    $("mnTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b || b.dataset.tab === state.tab) return;
      state.tab = b.dataset.tab;
      show();
    });
    document.addEventListener("click", (e) => {
      const b = e.target.closest("#mnHero [data-act], #mnMain [data-act]");
      if (b) act(b.dataset.act);
    });
    $("mnMain").addEventListener("click", (e) => {
      if (e.target.closest("a, input, button, .pp-check")) return;
      const tr = e.target.closest("tr[data-href]");
      if (tr) window.location.href = tr.dataset.href;
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
