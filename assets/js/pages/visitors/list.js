/**
 * ============================================================================
 * VISITORS - the page, top-down
 * ============================================================================
 * Four crisp cards; "My follow-ups" - compact cards, late in red, each with
 * Log and SMS; then our visitors. Pills with counts (each stage, and Due or
 * late), a search (name, phone or area) and a Sort menu drive both views:
 *   Board - New, Contacted, Returning, Regular, Became a member; drag a card
 *           to move them along; "Take it" puts an unassigned visitor on you.
 *   List  - a tick box per row and a floating "N selected" bar: Send
 *           message, Assign, Move to, Archive.
 * The view, pill, search and sort are kept in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const V = VisitorsUI;
  const K = PeopleKit;
  const CTX = window.VISITORS_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const state = {
    view: ["list", "archived"].includes(params.get("view")) ? params.get("view") : "board",
    items: [],
    byId: new Map(),
    options: null,
    can: { manage: false, make_member: false },
    kit: null,
    drake: null,
  };

  // The calendar's "follow-ups due" link opens the Due pill.
  (function translateLinks() {
    const q = new URLSearchParams(window.location.search);
    if (!q.has("due") && !q.has("assigned")) return;
    if (q.get("due")) q.set("pill", "due");
    q.delete("due");
    q.delete("assigned");
    history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
  })();

  const isDue = (p) => !!p.due_on && p.due_on <= V.todayIso();
  const initials = (name) =>
    String(name || "?")
      .split(" ")
      .map((w) => w[0])
      .slice(0, 2)
      .join("")
      .toUpperCase();

  // -------------------------------------------------------------- the cards
  function renderCards(o) {
    K.statRow($("statCardsRow"), [
      { icon: "ri-user-heart-line", label: "Visitors this month", sub: `${M.num(o.first_timers)} of them first-timers`, value: M.num(o.this_month), color: "primary", delta: UI.periodDelta(o.this_month, o.last_month), series: { labels: o.months, data: o.visitors_series } },
      { icon: "ri-star-smile-line", label: "First-timers", sub: `${M.num(o.first_timers_last_month)} last month`, value: M.num(o.first_timers), color: "pink", delta: UI.periodDelta(o.first_timers, o.first_timers_last_month), series: { labels: o.months, data: o.first_series } },
      { icon: "ri-phone-line", label: `Followed up in ${o.followup_days} days`, sub: o.followup_cohort ? `Of ${M.num(o.followup_cohort)} first visits, last 3 months` : "No first visits to check yet", value: o.followed_up_in_time === null ? "-" : `${o.followed_up_in_time}%`, color: "success", bar: { pct: o.followed_up_in_time || 0, text: "followed up in time" } },
      { icon: "ri-home-heart-line", label: "Became members", sub: "This year", value: M.num(o.became_members), color: "purple", bar: { pct: o.conversion || 0, text: o.conversion === null ? "no first-timers yet" : `${o.conversion}% of ${M.num(o.first_timers_this_year)} first-timers` } },
    ]);
  }

  // -------------------------------------------------------------- my follow-ups
  function renderDue(o) {
    $("dueHead").innerHTML = `${o.overdue ? `<span class="soft-chip soft-danger"><i class="ri-alarm-warning-line"></i>${M.num(o.overdue)} late</span>` : ""}${o.due_count ? `<span class="soft-chip soft-primary">${M.num(o.due_count)} this week</span>` : ""}${o.due_count ? '<button type="button" class="btn btn-sm btn-outline-primary" id="showDue">Show them all</button>' : ""}`;
    if (!o.due.length) {
      $("dueBody").innerHTML = `<div class="vs-due-none"><span class="avatar avatar-sm avatar-rounded bg-success text-white"><i class="ri-check-line"></i></span><span>Nothing due this week. Visitors show here ${o.followup_days} days after a visit until someone follows them up.</span></div>`;
      return;
    }
    $("dueBody").innerHTML = `<div class="vs-due-grid">${o.due
      .map((r) => {
        const href = `${CTX.baseUrl}/visitor?id=${r.id}`;
        const last = r.last_followup ? `${V.TYPES[r.last_followup.type]?.label || "Follow-up"} · ${M.day(r.last_followup.done_on)}` : `Visited ${M.day(r.last_visit_on)}`;
        const sms = r.consent && r.phone && !String(r.phone).startsWith("+254700000") ? `<a class="btn btn-sm btn-light border" href="${href}&act=sms" title="Send an SMS" aria-label="Send ${M.esc(r.name)} an SMS"><i class="ri-chat-3-line"></i></a>` : "";
        return `<div class="vs-due-tile${r.overdue ? " is-late" : ""}">
          <div class="d-flex align-items-center gap-2">
            ${M.avatar(r, "sm")}
            <a class="min-w-0 flex-fill mb-link" href="${href}"><strong>${M.esc(r.name)}</strong><small>${M.esc(last)}${r.assigned ? "" : " · nobody yet"}</small></a>
          </div>
          <div class="vs-due-tile-foot">${V.dueChip(r.due_on)}<span class="d-flex gap-1">${sms}${state.can.manage ? `<a class="btn btn-sm btn-primary" href="${href}&act=log"><i class="ri-add-line me-1"></i>Log</a>` : ""}</span></div>
        </div>`;
      })
      .join("")}</div>`;
    $("showDue")?.addEventListener("click", () => {
      $("visitorPills").querySelector('[data-pill="due"]')?.click();
      $("visitorPills").scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }

  // -------------------------------------------------------------- the list
  function rowHtml(p) {
    const href = `${CTX.baseUrl}/visitor?id=${p.id}`;
    return `<tr class="mb-row" data-id="${p.id}" data-href="${href}" data-pills="${[p.stage, isDue(p) ? "due" : ""].join(" ")}">
      ${K.checkCell(p.id, p.name)}
      <td data-search="${M.esc(`${p.name} ${p.phone || ""} ${p.area || ""}`)}" data-order="${M.esc(p.name.toLowerCase())}">
        <div class="d-flex align-items-center gap-2">
          ${M.avatar(p, "sm")}
          <div class="min-w-0"><a class="fw-semibold mb-link" href="${href}">${M.esc(p.name)}</a><div class="mb-sub">${M.esc(p.phone || "No phone")}</div></div>
        </div>
      </td>
      <td>${V.stagePill(p.stage)}</td>
      <td data-order="${M.esc((p.area || "~").toLowerCase())}">${p.area ? M.esc(p.area) : '<span class="mb-sub">Not given</span>'}</td>
      <td class="d-none d-md-table-cell" data-order="${p.visits}">${M.num(p.visits)}</td>
      <td class="d-none d-lg-table-cell" data-order="${p.last_visit_on || ""}">${M.day(p.last_visit_on)}</td>
      <td data-order="${p.due_on || "9999"}">${p.due_on ? V.dueChip(p.due_on) : '<span class="mb-sub">Nothing due</span>'}</td>
      <td class="d-none d-lg-table-cell">${p.assigned ? M.esc(p.assigned.name) : '<span class="mb-sub">Nobody yet</span>'}</td>
      <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
    </tr>`;
  }

  function bulkActions() {
    const send = { key: "sms", label: "Send message", icon: "ri-chat-3-line", primary: true, run: (ids) => K.messagePeople(CTX.messagesUrl, ids.map((id) => state.byId.get(id)).filter(Boolean)) };
    if (!state.can.manage || state.view === "archived") return [send];
    const leaders = state.options?.leaders || [];
    const call = (ids, body) => async () => {
      const res = await VisitorsAPI.bulk({ ids, ...body() });
      if (res.ok) refreshAll();
      return res;
    };
    const subtitle = (ids) => `${ids.length} ${ids.length === 1 ? "visitor" : "visitors"} picked`;
    return [
      send,
      {
        key: "assign",
        label: "Assign",
        icon: "ri-user-follow-line",
        run: (ids) => {
          const el = K.confirmWindow({
            title: "Who follows them up",
            subtitle: subtitle(ids),
            icon: "ri-user-follow-line",
            go: '<i class="ri-check-line me-1"></i>Assign',
            body: K.parts([{ icon: "ri-user-follow-line", title: "Leader", hint: "They're told in the app", body: `<select class="form-select" id="bkWho"><option value="">Nobody yet</option>${leaders.map((l) => `<option value="${l.id}" data-color="${UI.colorFor(l.name)}">${M.esc(l.name)}${l.id === CTX.userId ? " (me)" : ""}</option>`).join("")}</select>` }]),
            run: call(ids, () => ({ action: "assign", user_id: Number($("bkWho").value) || null })),
          });
          UI.enhanceSelect(el.querySelector("#bkWho"));
        },
      },
      {
        key: "stage",
        label: "Move to",
        icon: "ri-arrow-right-circle-line",
        run: (ids) =>
          K.confirmWindow({
            title: "Move them to",
            subtitle: subtitle(ids),
            icon: "ri-arrow-right-circle-line",
            go: '<i class="ri-check-line me-1"></i>Move',
            body: K.parts([
              {
                icon: "ri-flag-line",
                title: "Stage",
                hint: "Members are made one by one",
                body: `<div class="ec-choices">${Object.entries(V.STAGES)
                  .filter(([k]) => k !== "member")
                  .map(([k, s], i) => `<label class="ec-choice"><input type="radio" name="bkStage" value="${k}"${i === 1 ? " checked" : ""}><span class="ec-choice-icon"><i class="${s.icon}"></i></span><strong>${s.label}</strong><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`)
                  .join("")}</div>`,
              },
            ]),
            run: call(ids, () => ({ action: "stage", stage: document.querySelector('input[name="bkStage"]:checked').value })),
          }),
      },
      {
        key: "archive",
        label: "Archive",
        icon: "ri-archive-line",
        run: (ids) =>
          K.confirmWindow({
            title: "Archive them",
            subtitle: subtitle(ids),
            icon: "ri-archive-line",
            danger: true,
            go: '<i class="ri-archive-line me-1"></i>Archive',
            body: K.parts([{ icon: "ri-archive-line", title: "What happens", body: '<p class="mb-0">They leave the board and the list but stay counted. You can bring each one back from Archived.</p>' }]),
            run: call(ids, () => ({ action: "archive" })),
          }),
      },
    ];
  }

  // -------------------------------------------------------------- the board
  function card(p) {
    const visits = p.visits > 1 ? `<span class="soft-chip soft-primary"><i class="ri-repeat-line"></i>${V.ordinal(p.visits)} visit</span>` : "";
    const area = p.area ? `<span class="soft-chip soft-success"><i class="ri-map-pin-line"></i>${M.esc(p.area)}</span>` : "";
    const short = (iso) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "");
    const visitor = p.status === "visitor";
    const who = !visitor
      ? `<span class="mb-sub">Member since ${short(p.became_member_on)}</span>`
      : p.assigned
        ? `<span class="vs-card-who"><span class="avatar avatar-xs avatar-rounded bg-${UI.colorFor(p.assigned.name)} text-white">${M.esc(initials(p.assigned.name))}</span>${M.esc(p.assigned.name.split(" ")[0])}</span>`
        : state.can.manage
          ? `<button type="button" class="btn btn-sm btn-outline-primary vs-take" data-take="${p.id}"><i class="ri-hand-heart-line me-1"></i>Take it</button>`
          : '<span class="mb-sub">Nobody yet</span>';
    return `<div class="vs-card${visitor && state.can.manage ? "" : " is-fixed"}${p.overdue ? " is-late" : ""}" data-id="${p.id}">
      <div class="d-flex align-items-center gap-2">
        ${M.avatar(p, "sm")}
        <div class="min-w-0 flex-fill"><a class="fw-semibold mb-link vs-card-name" href="${CTX.baseUrl}/visitor?id=${p.id}">${M.esc(p.name)}</a><div class="mb-sub">${M.esc(p.phone || "No phone")}</div></div>
      </div>
      ${area || visits || p.due_on ? `<div class="vs-card-chips">${p.due_on ? V.dueChip(p.due_on) : ""}${area}${visits}</div>` : ""}
      <div class="vs-card-foot"><span class="mb-sub">${visitor && p.first_visit_on ? `First came ${short(p.first_visit_on)}` : ""}</span>${who}</div>
    </div>`;
  }

  function renderBoard() {
    if (state.view !== "board") return;
    const ids = state.kit ? state.kit.visibleIds() : state.items.map((p) => p.id);
    const items = ids.map((id) => state.byId.get(id)).filter(Boolean);
    $("vsBoard").innerHTML = `<div class="vs-board">${Object.entries(V.STAGES)
      .map(([key, s]) => {
        const list = items.filter((p) => p.stage === key);
        return `<section class="vs-col" style="--q: var(--${s.color}-rgb)">
          <header class="vs-col-head">
            <span class="vs-col-icon"><i class="${s.icon}"></i></span>
            <div class="min-w-0 flex-fill"><strong>${s.label}</strong><small>${key === "member" ? "In the last 3 months" : s.hint}</small></div>
            <span class="vs-col-count">${list.length}</span>
          </header>
          <div class="vs-drop" data-stage="${key}">${list.map(card).join("")}</div>
        </section>`;
      })
      .join("")}</div>`;
    wireDrag();
  }

  function wireDrag() {
    state.drake?.destroy();
    state.drake = null;
    if (!state.can.manage || typeof dragula === "undefined") return;
    state.drake = dragula([...document.querySelectorAll("#vsBoard .vs-drop")], {
      moves: (el, source, handle) => !el.classList.contains("is-fixed") && !handle.closest("a, button"),
      accepts: (el, target) => target.dataset.stage !== "member" || state.can.make_member,
    });
    state.drake.on("drop", async (el, target, source) => {
      const p = state.byId.get(Number(el.dataset.id));
      if (!p || target === source) return;
      if (target.dataset.stage === "member") {
        V.becomeMember(p, CTX.membersUrl, { onDone: () => refreshAll(), onCancel: renderBoard });
        return;
      }
      const res = await VisitorsAPI.stage(p.id, target.dataset.stage);
      if (!res.ok) {
        Toast.error(res.message);
        return renderBoard();
      }
      Toast.success(`${p.name}: ${res.message}`);
      load();
    });
  }

  // -------------------------------------------------------------- views
  function renderViewSwitch() {
    $("viewSwitchWrap").innerHTML = UI.renderSegmented("viewSwitch", [{ value: "board", label: '<i class="ri-layout-column-line me-1"></i>Board' }, { value: "list", label: '<i class="ri-list-check me-1"></i>List' }, { value: "archived", label: '<i class="ri-archive-line me-1"></i>Archived' }], state.view, { ariaLabel: "How to show our visitors" });
    UI.wireSegmented("viewSwitch", (v) => {
      const reload = state.view === "archived" || v === "archived";
      state.view = v;
      const q = new URLSearchParams(window.location.search);
      v === "board" ? q.delete("view") : q.set("view", v);
      history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
      reload ? load() : showView();
    });
  }

  function showView() {
    const board = state.view === "board";
    $("vsBoardWrap").hidden = !board;
    $("vsTableWrap").hidden = board;
    document.getElementById("visitorTableBar")?.classList.toggle("d-none", board);
    $("viewHint").textContent = board
      ? state.can.manage
        ? "Drag a card to move them along - or take one to follow up"
        : "Where each visitor is in their follow-up"
      : state.view === "archived"
        ? "Taken off the board - still counted"
        : "Tick visitors to message, assign or move them together";
    if (board) renderBoard();
    else state.kit?.table?.columns.adjust();
  }

  // -------------------------------------------------------------- loading
  async function load() {
    state.kit?.destroy();
    $("vsBoard").innerHTML = `<div class="vs-board-loading">${'<span class="skel" style="height:220px;border-radius:.85rem"></span>'.repeat(4)}</div>`;
    $("visitorRows").innerHTML = UI.renderTableLoading(9);
    const res = await VisitorsAPI.list({ archived: state.view === "archived" ? 1 : "" });
    if (!res.ok) {
      $("vsBoardWrap").innerHTML = M.errorBox(res.message);
      return;
    }
    state.items = res.data.items;
    state.byId = new Map(state.items.map((p) => [p.id, p]));
    if (!state.items.length) {
      state.kit = null;
      $("visitorFilters").innerHTML = "";
      $("visitorPills").innerHTML = "";
      const none =
        state.view === "archived"
          ? M.empty("ri-archive-line", "No one archived", "Visitors you archive leave the board but stay counted.")
          : M.empty("ri-user-heart-line", "No visitors yet", "Record this Sunday's visitors, and they show here until they belong.", CTX.can.manage ? `<a class="btn btn-primary" href="${CTX.baseUrl}/new"><i class="ri-user-add-line me-1"></i>Record visitors</a>` : "");
      $("visitorRows").innerHTML = `<tr><td colspan="9">${none}</td></tr>`;
      $("vsBoard").innerHTML = none;
      $("vsBoardWrap").hidden = state.view !== "board";
      $("vsTableWrap").hidden = state.view === "board";
      return;
    }
    state.kit = K.listTable({
      tableId: "visitorTable",
      stripId: "visitorFilters",
      pillsId: "visitorPills",
      rowsId: "visitorRows",
      items: state.items,
      rowHtml,
      noun: "visitors",
      searchPlaceholder: "Search by name, phone or area...",
      pills: [
        ...Object.entries(V.STAGES).map(([key, s]) => ({ key, label: key === "member" ? "Became members" : s.label, icon: s.icon, color: s.color, test: (p) => p.stage === key })),
        { key: "due", label: "Due or late", icon: "ri-alarm-warning-line", color: "danger", test: isDue },
      ],
      sorts: [
        { key: "recent", label: "Last visit - newest", order: [[5, "desc"]] },
        { key: "due", label: "Due soonest", order: [[6, "asc"]] },
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "visits", label: "Most visits", order: [[4, "desc"]] },
        { key: "area", label: "Area", order: [[3, "asc"], [1, "asc"]] },
      ],
      nonSortable: [8],
      actions: bulkActions(),
      onDraw: renderBoard,
    });
    showView();
  }

  async function refreshAll() {
    const [o] = await Promise.all([VisitorsAPI.overview(), load()]);
    if (o.ok) {
      renderCards(o.data);
      renderDue(o.data);
    }
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    renderViewSwitch();
    $("visitorRows").addEventListener("click", (e) => {
      if (e.target.closest("a, input, .pp-check")) return;
      const tr = e.target.closest("tr[data-href]");
      if (tr) window.location.href = tr.dataset.href;
    });
    // "Take it": this visitor is now mine to follow up.
    $("vsBoard").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-take]");
      if (!b) return;
      UI.setButtonLoading(b, "");
      const res = await VisitorsAPI.assign(Number(b.dataset.take), CTX.userId);
      if (!res.ok) {
        UI.restoreButton(b);
        return Toast.error(res.message);
      }
      Toast.success(`${res.data.name} is yours to follow up.`);
      refreshAll();
    });
    const [opts, o] = await Promise.all([VisitorsAPI.options(), VisitorsAPI.overview()]);
    if (!opts.ok || !o.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox((opts.ok ? o : opts).message)}</div>`;
      $("dueCard").hidden = true;
      return;
    }
    state.options = opts.data;
    state.can = o.data.can;
    renderCards(o.data);
    renderDue(o.data);
    await load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
