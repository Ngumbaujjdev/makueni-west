/**
 * ============================================================================
 * FACILITIES - Repairs (repairs.php)
 * ============================================================================
 * Four cards (to do, urgent, in progress, fixed this month and its cost), and
 * the board - Reported, In progress, Done - drag a card to move it along
 * (those who manage); tap it for who is on it, the cost, and "Record the
 * cost" in the budget. Anyone who sees the facilities can report one.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const K = PeopleKit;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  const state = { items: [], byId: new Map(), budget: null, can: {}, drake: null };
  const COLS = [
    ["reported", "Reported", "ri-flag-line", "warning", "Waiting for someone to take it"],
    ["in_progress", "In progress", "ri-tools-line", "primary", "Someone is on it"],
    ["done", "Done", "ri-checkbox-circle-line", "success", "Fixed in the last 3 months"],
  ];

  function cards() {
    const open = state.items.filter((j) => j.status !== "done");
    const month = F.todayIso().slice(0, 7);
    const doneMonth = state.items.filter((j) => j.status === "done" && (j.done_on || "").startsWith(month));
    const cost = doneMonth.reduce((a, j) => a + (j.cost || 0), 0);
    K.statRow($("statCardsRow"), [
      { icon: "ri-flag-line", label: "To do", sub: "Reported or being fixed", value: F.num(open.length), color: "warning" },
      { icon: "ri-alarm-warning-line", label: "Urgent", sub: open.filter((j) => j.priority === "urgent").length ? "Needs doing first" : "Nothing urgent", value: F.num(open.filter((j) => j.priority === "urgent").length), color: "danger" },
      { icon: "ri-tools-line", label: "In progress", sub: "Someone is on it", value: F.num(state.items.filter((j) => j.status === "in_progress").length), color: "primary" },
      { icon: "ri-checkbox-circle-line", label: "Fixed this month", sub: cost ? `${F.money(cost)} spent` : "No cost recorded", value: F.num(doneMonth.length), color: "success" },
    ]);
  }

  function card(j) {
    const about = j.equipment ? `<span class="soft-chip soft-primary"><i class="ri-archive-line"></i>${F.esc(j.equipment.name)}</span>` : j.room ? `<span class="soft-chip soft-success"><i class="ri-door-open-line"></i>${F.esc(j.room.name)}</span>` : "";
    const who = j.assignee
      ? `<span class="vs-card-who"><span class="avatar avatar-xs avatar-rounded bg-${UI.colorFor(j.assignee)} text-white">${F.esc(j.assignee.split(" ").map((w) => w[0]).slice(0, 2).join(""))}</span>${F.esc(j.assignee.split(" ")[0])}</span>`
      : '<span class="mb-sub">Nobody yet</span>';
    return `<div class="vs-card fx-job${state.can.manage ? "" : " is-fixed"}${j.priority === "urgent" && j.status !== "done" ? " is-late" : ""}" data-id="${j.id}" role="button" tabindex="0">
      <div class="d-flex align-items-start gap-2"><strong class="flex-fill fx-job-title">${F.esc(j.title)}</strong>${j.priority === "urgent" && j.status !== "done" ? '<span class="badge bg-danger">Urgent</span>' : ""}</div>
      ${j.detail ? `<p class="fx-job-detail">${F.esc(j.detail)}</p>` : ""}
      ${about ? `<div class="vs-card-chips">${about}${j.cost !== null ? `<span class="soft-chip soft-success"><i class="ri-money-dollar-circle-line"></i>${F.money(j.cost)}</span>` : ""}${j.budget_entry_id ? '<span class="soft-chip soft-success"><i class="ri-checkbox-circle-line"></i>In the budget</span>' : ""}</div>` : ""}
      <div class="vs-card-foot"><span class="mb-sub">${j.status === "done" ? `Fixed ${F.day(j.done_on, { day: "numeric", month: "short" })}` : j.days_open ? `${j.days_open} ${j.days_open === 1 ? "day" : "days"} open` : "Reported today"}</span>${who}</div>
    </div>`;
  }

  function board() {
    $("rpBoard").innerHTML = COLS.map(([key, label, icon, color, hint]) => {
      const list = state.items.filter((j) => j.status === key);
      return `<section class="vs-col" style="--q: var(--${color}-rgb)">
        <header class="vs-col-head"><span class="vs-col-icon"><i class="${icon}"></i></span><div class="min-w-0 flex-fill"><strong>${label}</strong><small>${hint}</small></div><span class="vs-col-count">${list.length}</span></header>
        <div class="vs-drop" data-status="${key}">${list.map(card).join("")}</div>
      </section>`;
    }).join("");
    state.drake?.destroy();
    state.drake = null;
    if (!state.can.manage || typeof dragula === "undefined") return;
    state.drake = dragula([...document.querySelectorAll("#rpBoard .vs-drop")], { moves: (el, s, handle) => !handle.closest("a, button") });
    state.drake.on("drop", async (el, target, source) => {
      if (target === source) return;
      const res = await FacilitiesAPI.updateRepair(Number(el.dataset.id), { status: target.dataset.status });
      res.ok ? Toast.success(res.message) : Toast.error(res.message);
      load();
    });
  }

  async function load() {
    const res = await FacilitiesAPI.repairs();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      return;
    }
    state.items = res.data.items;
    state.byId = new Map(state.items.map((j) => [j.id, j]));
    state.budget = res.data.budget_in_use;
    state.can = res.data.can;
    cards();
    board();
  }

  function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("reportBtn").addEventListener("click", () => F.reportWindow({ onDone: () => load() }));
    $("rpBoard").addEventListener("click", (e) => {
      const c = e.target.closest(".fx-job");
      if (!c || !state.can.manage) return;
      F.repairWindow(state.byId.get(Number(c.dataset.id)), { budget: state.budget, onDone: () => load() });
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
