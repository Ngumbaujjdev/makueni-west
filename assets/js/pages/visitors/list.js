/**
 * ============================================================================
 * VISITORS - the page (board and list)
 * ============================================================================
 * Four cards (visitors this month, first-timers, followed up in time, became
 * members), "My follow-ups" (due this week or late, late in red), then our
 * visitors as a board - New, Contacted, Returning, Regular, Became a member;
 * drag a card to move them along (the YNEX kanban) - or as a list. One
 * filter strip (search, stage, follow-up - like our other tables) serves
 * both, kept in the URL with the view (?view=list).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const V = VisitorsUI;
  const CTX = window.VISITORS_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const state = {
    view: ["list", "archived"].includes(params.get("view")) ? params.get("view") : "board",
    items: [],
    byId: new Map(),
    options: null,
    can: { manage: false, make_member: false },
    table: null,
    drake: null,
  };

  // The calendar's "follow-ups due" link becomes a filter value ("?assigned=me" just opens the page).
  (function translateLinks() {
    const q = new URLSearchParams(window.location.search);
    if (q.get("due")) q.set("fDue", "due");
    if (!q.has("due") && !q.has("assigned")) return;
    q.delete("due");
    q.delete("assigned");
    history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
  })();

  // -------------------------------------------------------------- the cards
  function renderCards(o) {
    const cards = [
      { icon: "ri-user-heart-line", label: "Visitors this month", value: M.num(o.this_month), color: "primary", delta: UI.periodDelta(o.this_month, o.last_month), series: { labels: o.months, data: o.visitors_series }, sub: `${M.num(o.first_timers)} of them first-timers` },
      { icon: "ri-star-smile-line", label: "First-timers", value: M.num(o.first_timers), color: "pink", delta: UI.periodDelta(o.first_timers, o.first_timers_last_month), series: { labels: o.months, data: o.first_series }, sub: `${M.num(o.first_timers_last_month)} last month` },
      { icon: "ri-phone-line", label: `Followed up in ${o.followup_days} days`, value: o.followed_up_in_time === null ? "-" : `${o.followed_up_in_time}%`, color: "success", sub: o.followup_cohort ? `Of ${M.num(o.followup_cohort)} first visits in the last 3 months` : "No first visits to check yet" },
      { icon: "ri-home-heart-line", label: "Became members", value: M.num(o.became_members), color: "purple", sub: o.conversion === null ? "This year" : `${o.conversion}% of this year's ${M.num(o.first_timers_this_year)} first-timers` },
    ];
    const row = $("statCardsRow");
    row.innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
    UI.mountSparklines(row);
  }

  // -------------------------------------------------------------- my follow-ups
  function renderDue(o) {
    $("dueHead").innerHTML = `${o.overdue ? `<span class="soft-chip soft-danger"><i class="ri-alarm-warning-line"></i>${M.num(o.overdue)} late</span>` : ""}${o.due_count ? `<button type="button" class="btn btn-sm btn-outline-primary" id="showDue">Show all due</button>` : ""}`;
    if (!o.due.length) {
      $("dueBody").innerHTML = `<div class="vs-due-none"><span class="avatar avatar-sm avatar-rounded bg-success text-white"><i class="ri-check-line"></i></span><span>Nothing due this week. Visitors show here ${o.followup_days} days after a visit until someone follows them up.</span></div>`;
      return;
    }
    $("dueBody").innerHTML = `<div class="vs-due-strip">${o.due
      .map((r) => {
        const last = r.last_followup ? `${V.TYPES[r.last_followup.type]?.label || "Follow-up"} · ${M.day(r.last_followup.done_on)}` : `Visited ${M.day(r.last_visit_on)}`;
        return `<a class="vs-due-item${r.overdue ? " is-late" : ""}" href="${CTX.baseUrl}/visitor?id=${r.id}&tab=followup">
          ${M.avatar(r, "sm")}
          <span class="min-w-0"><strong>${M.esc(r.name)}</strong><small>${M.esc(last)}${r.assigned ? "" : " · nobody yet"}</small></span>
          ${V.dueChip(r.due_on)}
        </a>`;
      })
      .join("")}</div>`;
    $("showDue")?.addEventListener("click", () => {
      const sel = $("fDue");
      if (!sel) return;
      sel.value = "due";
      UI.syncSelect(sel);
      sel.dispatchEvent(new Event("change"));
      $("visitorFilters").scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }

  // -------------------------------------------------------------- the list
  function rowHtml(p) {
    const due = V.due(p.due_on);
    const href = `${CTX.baseUrl}/visitor?id=${p.id}`;
    return `<tr class="mb-row" data-id="${p.id}" data-href="${href}">
      <td data-search="${M.esc(`${p.name} ${p.phone || ""} ${p.area || ""}`)}">
        <div class="d-flex align-items-center gap-2">
          ${M.avatar(p, "sm")}
          <div class="min-w-0"><a class="fw-semibold mb-link" href="${href}">${M.esc(p.name)}</a><div class="mb-sub">${M.esc(p.phone || "No phone")}</div></div>
        </div>
      </td>
      <td data-search="${p.stage}">${V.stagePill(p.stage)}</td>
      <td>${p.area ? M.esc(p.area) : '<span class="mb-sub">Not given</span>'}</td>
      <td class="d-none d-md-table-cell" data-order="${p.visits}">${M.num(p.visits)}</td>
      <td class="d-none d-lg-table-cell" data-order="${p.last_visit_on || ""}">${M.day(p.last_visit_on)}</td>
      <td data-search="${due.key}" data-order="${p.due_on || "9999"}">${p.due_on ? V.dueChip(p.due_on) : '<span class="mb-sub">Nothing due</span>'}</td>
      <td class="d-none d-lg-table-cell">${p.assigned ? M.esc(p.assigned.name) : '<span class="mb-sub">Nobody yet</span>'}</td>
      <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
    </tr>`;
  }

  function filters() {
    return [
      { id: "fStage", label: "All stages", options: Object.entries(V.STAGES).map(([value, s]) => ({ value, label: s.label, color: s.color })), columnIndex: 1, exact: true },
      { id: "fDue", label: "Any follow-up", options: [{ value: "due", label: "Due or late", color: "danger" }, { value: "later", label: "Coming up", color: "warning" }, { value: "none", label: "Nothing due", color: "secondary" }], columnIndex: 5, exact: true },
    ];
  }

  // -------------------------------------------------------------- the board
  const visibleIds = () => (state.table ? new Set(state.table.rows({ search: "applied" }).nodes().toArray().map((tr) => Number(tr.dataset.id))) : new Set());

  function card(p) {
    const visits = p.visits > 1 ? `<span class="soft-chip soft-primary"><i class="ri-repeat-line"></i>${V.ordinal(p.visits)} visit</span>` : "";
    const area = p.area ? `<span class="soft-chip soft-success"><i class="ri-map-pin-line"></i>${M.esc(p.area)}</span>` : "";
    const who = p.assigned
      ? `<span class="avatar avatar-xs avatar-rounded bg-${UI.colorFor(p.assigned.name)} text-white" title="${M.esc(p.assigned.name)} follows up">${M.esc(
          p.assigned.name
            .split(" ")
            .map((w) => w[0])
            .slice(0, 2)
            .join(""),
        )}</span>`
      : '<span class="mb-sub">Nobody yet</span>';
    const short = (iso) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "");
    const foot = p.status === "visitor" ? (p.first_visit_on ? `First came ${short(p.first_visit_on)}` : "") : `Member since ${short(p.became_member_on)}`;
    return `<div class="card custom-card vs-card${p.status === "visitor" && state.can.manage ? "" : " is-fixed"}" data-id="${p.id}">
      <div class="card-body p-0">
        <div class="p-3 kanban-board-head">
          <div class="d-flex align-items-center gap-2">
            ${M.avatar(p, "sm")}
            <div class="min-w-0 flex-fill"><a class="fw-semibold mb-link vs-card-name" href="${CTX.baseUrl}/visitor?id=${p.id}">${M.esc(p.name)}</a><div class="mb-sub">${M.esc(p.phone || "No phone")}</div></div>
          </div>
          ${visits || area || p.due_on ? `<div class="kanban-content d-flex flex-wrap gap-1">${p.due_on ? V.dueChip(p.due_on) : ""}${area}${visits}</div>` : ""}
        </div>
        <div class="vs-card-foot"><span class="mb-sub">${foot}</span>${p.status === "visitor" ? who : ""}</div>
      </div>
    </div>`;
  }

  function renderBoard() {
    const show = visibleIds();
    const items = state.items.filter((p) => show.has(p.id));
    $("vsBoard").innerHTML = `<div class="ynex-kanban-board vs-board">${Object.entries(V.STAGES)
      .map(([key, s]) => {
        const list = items.filter((p) => p.stage === key);
        return `<div class="kanban-tasks-type vs-col">
          <div class="vs-col-head">
            <span class="ev-tile is-sm" style="--q: var(--${s.color}-rgb)"><i class="${s.icon}"></i></span>
            <div class="min-w-0 flex-fill"><strong>${s.label}</strong><small>${key === "member" ? "In the last 3 months" : s.hint}</small></div>
            <span class="vs-col-count">${list.length}</span>
          </div>
          <div class="kanban-tasks"><div class="vs-drop" data-stage="${key}">${list.map(card).join("")}</div></div>
        </div>`;
      })
      .join("")}</div>`;
    M.loadPhotos($("vsBoard"));
    wireDrag();
  }

  function wireDrag() {
    state.drake?.destroy();
    state.drake = null;
    if (!state.can.manage || typeof dragula === "undefined") return;
    const drops = [...document.querySelectorAll("#vsBoard .vs-drop")];
    state.drake = dragula(drops, {
      moves: (el, source, handle) => !el.classList.contains("is-fixed") && !handle.closest("a"),
      accepts: (el, target) => target.dataset.stage !== "member" || state.can.make_member,
    });
    state.drake.on("drop", async (el, target, source) => {
      const id = Number(el.dataset.id);
      const p = state.byId.get(id);
      const to = target.dataset.stage;
      if (!p || target === source) return;
      if (to === "member") {
        V.becomeMember(p, CTX.membersUrl, { onDone: () => load({ quiet: true }), onCancel: renderBoard });
        return;
      }
      const res = await VisitorsAPI.stage(id, to);
      if (!res.ok) {
        Toast.error(res.message);
        return renderBoard();
      }
      Toast.success(`${p.name}: ${res.message}`);
      load({ quiet: true });
    });
  }

  // -------------------------------------------------------------- views
  function renderViewSwitch() {
    $("viewSwitchWrap").innerHTML = UI.renderSegmented("viewSwitch", [{ value: "board", label: '<i class="ri-layout-column-line me-1"></i>Board' }, { value: "list", label: '<i class="ri-list-check me-1"></i>List' }, { value: "archived", label: '<i class="ri-archive-line me-1"></i>Archived' }], state.view, { ariaLabel: "How to show our visitors" });
    UI.wireSegmented("viewSwitch", (v) => {
      const wasArchived = state.view === "archived";
      state.view = v;
      const q = new URLSearchParams(window.location.search);
      v === "board" ? q.delete("view") : q.set("view", v);
      history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
      if (wasArchived || v === "archived") load({ quiet: false });
      else showView();
    });
  }

  function showView() {
    const board = state.view === "board";
    $("vsBoardWrap").hidden = !board;
    $("vsTableWrap").hidden = board;
    $("viewHint").textContent = board ? (state.can.manage ? "Drag a card to move them along - from new to regular" : "Where each visitor is in their follow-up") : state.view === "archived" ? "Taken off the board - still counted, and you can bring them back" : "Everyone visiting now, and those who joined in the last 3 months";
    if (board) renderBoard();
    else state.table?.columns.adjust();
  }

  // -------------------------------------------------------------- loading
  async function load({ quiet = false } = {}) {
    if (!quiet) {
      $("vsBoard").innerHTML = `<div class="vs-board-loading">${'<span class="skel" style="height:220px;border-radius:.85rem"></span>'.repeat(4)}</div>`;
      $("visitorRows").innerHTML = UI.renderTableLoading(8);
    }
    const res = await VisitorsAPI.list({ archived: state.view === "archived" ? 1 : "" });
    if (!res.ok) {
      $("vsBoardWrap").innerHTML = M.errorBox(res.message);
      return;
    }
    state.items = res.data.items;
    state.byId = new Map(state.items.map((p) => [p.id, p]));
    state.table?.destroy?.();
    state.table = null;
    const f = filters();
    UI.renderFilterToolbar("visitorFilters", { searchPlaceholder: "Search by name, phone or area...", filters: f });
    if (!state.items.length) {
      $("visitorFilters").hidden = true;
      const none =
        state.view === "archived"
          ? M.empty("ri-archive-line", "No one archived", "Visitors you archive leave the board but stay counted.")
          : M.empty("ri-user-heart-line", "No visitors yet", "Record this Sunday's visitors, and they show here until they belong.", CTX.can.manage ? `<a class="btn btn-primary" href="${CTX.baseUrl}/new"><i class="ri-user-add-line me-1"></i>Record visitors</a>` : "");
      $("visitorRows").innerHTML = `<tr><td colspan="8">${none}</td></tr>`;
      if (state.view === "board") {
        $("vsBoard").innerHTML = none;
      }
      $("vsBoardWrap").hidden = state.view !== "board";
      $("vsTableWrap").hidden = state.view === "board";
      return;
    }
    $("visitorFilters").hidden = false;
    $("visitorRows").innerHTML = state.items.map(rowHtml).join("");
    state.table = UI.initListDataTable("visitorTable", { hideDefaultSearch: true, order: [[4, "desc"]], nonSortableColumns: [7], noun: "visitors", pageLength: 25 });
    f.forEach((x) => UI.enhanceSelect($(x.id), { search: false }));
    UI.wireFilterToolbar("visitorFilters", state.table, f, { noun: "visitors" });
    // The board follows the same filters.
    window.jQuery("#visitorTable").off("draw.vsboard").on("draw.dt.vsboard", () => state.view === "board" && renderBoard());
    M.loadPhotos($("visitorRows"));
    showView();
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-lg-6 col-md-6");
    renderViewSwitch();
    $("visitorRows").addEventListener("click", (e) => {
      if (e.target.closest("a")) return;
      const tr = e.target.closest("tr[data-href]");
      if (tr) window.location.href = tr.dataset.href;
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
