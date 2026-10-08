/**
 * ============================================================================
 * FACILITIES - one piece of equipment (item.php?id=)
 * ============================================================================
 * The hero (its kind, where it is kept, its condition and the facts) and
 * what you can do - Lend, Report a repair, Change; then Loans (Mark back),
 * Repairs and History. The tab is kept in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("id"));
  const TABS = ["loans", "repairs", "history"];
  const state = { it: null, tab: TABS.includes(params.get("tab")) ? params.get("tab") : "loans" };

  function hero() {
    const it = state.it;
    const can = it.can;
    const fact = (icon, color, label, value, sub = "") => `<div class="pp-fact" style="--q: var(--${color}-rgb)"><span class="pp-fact-icon"><i class="${icon}"></i></span><div class="min-w-0"><span>${label}</span><strong>${value}${sub ? `<small>${sub}</small>` : ""}</strong></div></div>`;
    $("itHero").innerHTML = `<div class="card-body">
      <div class="ev-hero-row">
        ${F.tile(it.icon, it.colour, "xl")}
        <div class="flex-fill min-w-0">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${F.esc(it.name)}</h2>${F.conditionPill(it.condition)}${it.open_repairs ? '<span class="soft-chip soft-warning"><i class="ri-tools-line"></i>Being repaired</span>' : ""}</div>
          <div class="d-flex flex-wrap gap-1"><span class="soft-chip soft-${it.colour}"><i class="${it.icon}"></i>${F.esc(it.category_label)}</span><span class="soft-chip soft-primary"><i class="ri-map-pin-line"></i>${it.room ? F.esc(it.room.name) : "No room"}</span>${it.serial ? `<span class="soft-chip soft-primary"><i class="ri-barcode-line"></i>${F.esc(it.serial)}</span>` : ""}</div>
          ${it.notes ? `<p class="cr-note mt-2 mb-0">${F.esc(it.notes)}</p>` : ""}
        </div>
        <div class="ev-hero-actions">
          ${can.manage && it.available ? '<button type="button" class="btn btn-primary" data-act="lend"><i class="ri-hand-coin-line me-1"></i>Lend</button>' : ""}
          <button type="button" class="btn btn-outline-primary" data-act="repair"><i class="ri-tools-line me-1"></i>Report a repair</button>
          ${can.manage ? '<button type="button" class="btn btn-outline-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Change</button>' : ""}
        </div>
      </div>
      <div class="pp-facts mn-hero-facts">
        ${fact("ri-hashtag", "primary", "How many", F.num(it.quantity), `${F.num(it.available)} here · ${F.num(it.on_loan)} on loan`)}
        ${fact("ri-money-dollar-circle-line", "success", "Value", it.value ? F.money(it.value) : "-", it.value && it.quantity > 1 ? `Each · ${F.money(it.value * it.quantity)} in all` : "")}
        ${fact("ri-calendar-line", "warning", "Bought", it.bought_on ? F.day(it.bought_on, { day: "numeric", month: "short", year: "numeric" }) : "-")}
        ${fact("ri-tools-line", "pink", "Repairs", F.num(it.repairs.length), it.open_repairs ? `${F.num(it.open_repairs)} still open` : "All done")}
      </div>
    </div>`;
    $("tabLoans").textContent = it.loans.length ? `${it.loans.filter((l) => !l.returned_on).length} out · ${it.loans.length} in all` : "Who has borrowed it";
    $("tabRepairs").textContent = it.repairs.length ? `${it.repairs.length} ${it.repairs.length === 1 ? "repair" : "repairs"}` : "What was fixed";
  }

  function loansTab() {
    const list = state.it.loans;
    $("itMain").innerHTML = `<div class="card custom-card"><div class="card-header justify-content-between"><div class="card-title">Loans</div>${state.it.can.manage && state.it.available ? '<button type="button" class="btn btn-sm btn-primary" data-act="lend"><i class="ri-hand-coin-line me-1"></i>Lend</button>' : ""}</div><div class="card-body">${
      list.length
        ? `<div class="fx-loans">${list
            .map(
              (l) => `<div class="fx-loan${l.overdue ? " is-late" : ""}${l.returned_on ? " is-back" : ""}">
                <span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(l.to_name)} text-white flex-shrink-0">${F.esc(l.to_name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase())}</span>
                <div class="flex-fill min-w-0"><strong>${F.esc(l.to_name)}${l.quantity > 1 ? ` ×${l.quantity}` : ""}</strong><small>${F.day(l.out_on)} → ${l.returned_on ? `back ${F.day(l.returned_on)}` : `due ${F.day(l.due_on)}`}${l.note ? ` · ${F.esc(l.note)}` : ""}</small></div>
                ${l.returned_on ? '<span class="badge bg-success">Back</span>' : l.overdue ? '<span class="badge bg-danger">Late</span>' : '<span class="badge bg-primary">Out</span>'}
                ${!l.returned_on && state.it.can.manage ? `<button type="button" class="btn btn-sm btn-outline-success" data-back="${l.id}"><i class="ri-arrow-go-back-line me-1"></i>Back</button>` : ""}
              </div>`,
            )
            .join("")}</div>`
        : '<p class="mb-0 fw-semibold">Never lent out.</p>'
    }</div></div>`;
  }

  function repairsTab() {
    const list = state.it.repairs;
    $("itMain").innerHTML = `<div class="card custom-card"><div class="card-header justify-content-between"><div class="card-title">Repairs</div><button type="button" class="btn btn-sm btn-outline-primary" data-act="repair"><i class="ri-tools-line me-1"></i>Report one</button></div><div class="card-body">${
      list.length
        ? `<ol class="ev-timeline vs-timeline">${list
            .map((j) => {
              const s = F.STATUS[j.status];
              return `<li style="--q: var(--${s[2]}-rgb)"><span class="ev-timeline-dot"></span><div class="flex-fill min-w-0"><span class="ev-timeline-when">${F.day(j.reported_on)}${j.reported_by ? ` · ${F.esc(j.reported_by)}` : ""}</span><span class="ev-timeline-what fw-semibold">${F.esc(j.title)} ${F.statusPill(j.status)}${j.priority === "urgent" && j.status !== "done" ? ' <span class="badge bg-danger">Urgent</span>' : ""}</span>${j.detail ? `<p class="cr-note">${F.esc(j.detail)}</p>` : ""}<span class="mb-sub">${[j.assignee ? `On it: ${F.esc(j.assignee)}` : null, j.cost !== null ? `Cost ${F.money(j.cost)}` : null, j.done_on ? `Done ${F.day(j.done_on)}` : null].filter(Boolean).join(" · ")}</span></div></li>`;
            })
            .join("")}</ol>`
        : '<p class="mb-0 fw-semibold">Nothing has needed fixing.</p>'
    }</div></div>`;
  }

  function historyTab() {
    const h = state.it.history;
    $("itMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">History</div></div><div class="card-body">${
      h.length ? `<ul class="ev-history">${h.map((x) => `<li><span class="ev-history-dot bg-${UI.colorFor(x.who)}"></span><div><strong>${F.esc(x.sentence)}</strong><small>${new Date(x.at).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" })}</small></div></li>`).join("")}</ul>` : '<p class="mb-0 fw-semibold">Nothing changed yet.</p>'
    }</div></div>`;
  }

  function show() {
    document.querySelectorAll("#itTabs [data-tab]").forEach((b) => b.classList.toggle("active", b.dataset.tab === state.tab));
    const q = new URLSearchParams(window.location.search);
    state.tab === "loans" ? q.delete("tab") : q.set("tab", state.tab);
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    ({ loans: loansTab, repairs: repairsTab, history: historyTab })[state.tab]();
  }

  async function refresh(data = null) {
    if (!data) {
      const res = await FacilitiesAPI.item(id);
      if (!res.ok) return Toast.error(res.message);
      data = res.data;
    }
    state.it = data;
    hero();
    show();
  }

  function act(name) {
    ({
      lend: () => F.lendWindow(state.it, { onDone: (d) => refresh(d) }),
      repair: () => F.reportWindow({ about: { equipment_id: id }, onDone: () => refresh() }),
      edit: () => F.itemWindow({ item: state.it, onDone: () => refresh() }),
    })[name]?.();
  }

  async function init() {
    if (!id) return ($("itHero").innerHTML = `<div class="card-body">${MembersUI.errorBox("Open an item from Equipment.", `location.href='${CTX.baseUrl}/equipment'`)}</div>`);
    const res = await FacilitiesAPI.item(id);
    if (!res.ok) return ($("itHero").innerHTML = `<div class="card-body">${MembersUI.errorBox(res.message, `location.href='${CTX.baseUrl}/equipment'`)}</div>`);
    document.title = `${res.data.name} - Equipment - Makueni West Diocese`;
    document.querySelector(".page-header-breadcrumb .breadcrumb-item.active")?.replaceChildren(document.createTextNode(res.data.name));
    $("itTabs").hidden = false;
    refresh(res.data);
    $("itTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b || b.dataset.tab === state.tab) return;
      state.tab = b.dataset.tab;
      show();
    });
    document.addEventListener("click", async (e) => {
      const a = e.target.closest("#itHero [data-act], #itMain [data-act]");
      if (a) return act(a.dataset.act);
      const back = e.target.closest("[data-back]");
      if (back) {
        UI.setButtonLoading(back, "...");
        const r = await FacilitiesAPI.giveBack(Number(back.dataset.back));
        UI.restoreButton(back);
        r.ok ? (Toast.success(r.message), refresh(r.data)) : Toast.error(r.message);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
