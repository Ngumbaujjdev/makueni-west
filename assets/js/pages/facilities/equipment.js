/**
 * ============================================================================
 * FACILITIES - Equipment (equipment.php)
 * ============================================================================
 * Four cards (items, their value, poor or broken, on loan), what is out on
 * loan now (late ones first, Mark back), and the list: condition pills with
 * counts, Kind and Room menus, a search and a Sort menu, ticks and a bulk bar
 * - Move to a room, Mark condition, Report a repair.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const K = PeopleKit;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  let kit = null;
  let opts = null;

  function cards(items, loans) {
    const value = items.reduce((a, i) => a + (i.value || 0) * i.quantity, 0);
    const units = items.reduce((a, i) => a + i.quantity, 0);
    const poor = items.filter((i) => i.condition === "poor" || i.condition === "broken");
    const late = loans.filter((l) => l.overdue).length;
    K.statRow($("statCardsRow"), [
      { icon: "ri-archive-line", label: "Items", sub: `${F.num(items.length)} kinds of thing`, value: F.num(units), color: "primary" },
      { icon: "ri-money-dollar-circle-line", label: "What it's worth", sub: "Where a value is recorded", value: value ? `KES ${F.num(Math.round(value / 1000))}k` : "-", color: "success" },
      { icon: "ri-error-warning-line", label: "Poor or broken", sub: `${F.num(poor.filter((i) => i.condition === "broken").length)} broken`, value: F.num(poor.length), color: "warning", bar: { pct: items.length ? Math.round((poor.length / items.length) * 100) : 0, text: "of kinds" } },
      { icon: "ri-hand-coin-line", label: "On loan", sub: late ? `${F.num(late)} not back on time` : "All on time", value: F.num(loans.length), color: "purple" },
    ]);
  }

  function loansCard(loans) {
    $("loansCard").hidden = !loans.length;
    if (!loans.length) return;
    $("loansHead").innerHTML = loans.some((l) => l.overdue) ? `<span class="badge bg-danger">${loans.filter((l) => l.overdue).length} late</span>` : "";
    $("loans").innerHTML = `<div class="fx-loans">${loans
      .sort((a, b) => (b.overdue - a.overdue) || String(a.due_on).localeCompare(String(b.due_on)))
      .map(
        (l) => `<div class="fx-loan${l.overdue ? " is-late" : ""}">
          <span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(l.to_name)} text-white flex-shrink-0">${F.esc(l.to_name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase())}</span>
          <div class="flex-fill min-w-0"><a class="fw-semibold mb-link" href="${CTX.baseUrl}/item?id=${l.equipment_id}">${F.esc(l.equipment)}${l.quantity > 1 ? ` ×${l.quantity}` : ""}</a><small>${F.esc(l.to_name)} · since ${F.day(l.out_on)}</small></div>
          <span class="${l.overdue ? "badge bg-danger" : "soft-chip soft-primary"}">${l.overdue ? "Late - " : "Due "}${F.day(l.due_on, { day: "numeric", month: "short" })}</span>
          ${CTX.can.manage ? `<button type="button" class="btn btn-sm btn-outline-success" data-back="${l.id}"><i class="ri-arrow-go-back-line me-1"></i>Back</button>` : ""}
        </div>`,
      )
      .join("")}</div>`;
  }

  function rowHtml(i) {
    const href = `${CTX.baseUrl}/item?id=${i.id}`;
    const pills = [i.condition, i.on_loan ? "loaned" : "", i.open_repairs ? "repair" : ""].join(" ");
    return `<tr class="mb-row" data-id="${i.id}" data-href="${href}" data-pills="${pills}" data-f-category="${i.category}" data-f-room="${i.room ? i.room.id : "none"}">
      ${K.checkCell(i.id, i.name)}
      <td data-search="${F.esc(`${i.name} ${i.serial || ""} ${i.category_label}`)}" data-order="${F.esc(i.name.toLowerCase())}">
        <div class="d-flex align-items-center gap-2">${F.tile(i.icon, i.colour, "sm")}<div class="min-w-0"><a class="fw-semibold mb-link" href="${href}">${F.esc(i.name)}</a><div class="mb-sub">${F.esc(i.category_label)}${i.serial ? ` · ${F.esc(i.serial)}` : ""}</div></div></div>
      </td>
      <td data-order="${F.esc(i.room ? i.room.name.toLowerCase() : "~")}">${i.room ? F.esc(i.room.name) : '<span class="mb-sub">No room</span>'}</td>
      <td data-order="${i.quantity}"><strong>${F.num(i.quantity)}</strong>${i.on_loan ? ` <span class="soft-chip soft-purple">${F.num(i.on_loan)} on loan</span>` : ""}</td>
      <td data-order="${["good", "fair", "poor", "broken"].indexOf(i.condition)}">${F.conditionPill(i.condition)}${i.open_repairs ? ' <span class="soft-chip soft-warning"><i class="ri-tools-line"></i>Repair</span>' : ""}</td>
      <td class="d-none d-lg-table-cell" data-order="${i.value || 0}">${i.value ? F.money(i.value) : '<span class="mb-sub">-</span>'}</td>
      <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
    </tr>`;
  }

  function actions(items) {
    if (!CTX.can.manage) return [];
    const sub = (ids) => `${ids.length} ${ids.length === 1 ? "item" : "items"} picked`;
    return [
      {
        key: "room",
        label: "Move to a room",
        icon: "ri-door-open-line",
        primary: true,
        run: (ids) => {
          const el = K.confirmWindow({
            title: "Move to a room",
            subtitle: sub(ids),
            icon: "ri-door-open-line",
            go: '<i class="ri-check-line me-1"></i>Move them',
            body: K.parts([{ icon: "ri-door-open-line", title: "Room", body: `<select class="form-select" id="bkRoomSel"><option value="">No room</option>${opts.rooms.map((r) => `<option value="${r.id}" data-color="${r.colour}">${F.esc(r.name)}</option>`).join("")}</select>` }]),
            run: async () => {
              const v = document.getElementById("bkRoomSel").value;
              const res = await FacilitiesAPI.bulkItems({ ids, action: "room", value: v ? Number(v) : null });
              if (res.ok) load();
              return res;
            },
          });
          UI.enhanceSelect(el.querySelector("#bkRoomSel"));
        },
      },
      {
        key: "condition",
        label: "Mark condition",
        icon: "ri-shield-check-line",
        run: (ids) =>
          K.confirmWindow({
            title: "Mark their condition",
            subtitle: sub(ids),
            icon: "ri-shield-check-line",
            go: '<i class="ri-check-line me-1"></i>Mark them',
            body: K.parts([{ icon: "ri-shield-check-line", title: "Condition", body: `<div class="mw-days" role="radiogroup">${opts.conditions.map((c, n) => `<label><input type="radio" name="bkCond" value="${c.key}"${n === 0 ? " checked" : ""}><span>${F.esc(c.label)}</span></label>`).join("")}</div>` }]),
            run: async () => {
              const res = await FacilitiesAPI.bulkItems({ ids, action: "condition", value: document.querySelector('input[name="bkCond"]:checked').value });
              if (res.ok) load();
              return res;
            },
          }),
      },
      { key: "repair", label: "Report a repair", icon: "ri-tools-line", run: (ids) => F.reportWindow({ about: { equipment_id: ids[0] }, onDone: () => load() }) },
    ];
  }

  async function load() {
    kit?.destroy();
    $("eqRows").innerHTML = UI.renderTableLoading(7);
    const [res, loans] = await Promise.all([FacilitiesAPI.equipment(), FacilitiesAPI.loans()]);
    if (!res.ok) {
      $("eqTableWrap").innerHTML = MembersUI.errorBox(res.message);
      return;
    }
    const items = res.data.items;
    cards(items, loans.ok ? loans.data : []);
    loansCard(loans.ok ? loans.data : []);
    if (!items.length) {
      $("eqFilters").innerHTML = "";
      $("eqPills").innerHTML = "";
      $("eqRows").innerHTML = `<tr><td colspan="7">${MembersUI.empty("ri-archive-line", "Nothing recorded yet", "Add what the church owns - the sound system, instruments, chairs, the generator...", CTX.can.manage ? '<button type="button" class="btn btn-primary" data-first><i class="ri-add-line me-1"></i>Add equipment</button>' : "")}</td></tr>`;
      return;
    }
    const usedRooms = opts.rooms.filter((r) => items.some((i) => i.room?.id === r.id));
    kit = K.listTable({
      tableId: "eqTable",
      stripId: "eqFilters",
      pillsId: "eqPills",
      rowsId: "eqRows",
      items,
      rowHtml,
      noun: "items",
      searchPlaceholder: "Search by name, kind or serial...",
      pills: [
        { key: "good", label: "Good", icon: "ri-checkbox-circle-line", color: "success", test: (i) => i.condition === "good" },
        { key: "fair", label: "Fair", icon: "ri-checkbox-blank-circle-line", color: "primary", test: (i) => i.condition === "fair" },
        { key: "poor", label: "Poor", icon: "ri-error-warning-line", color: "warning", test: (i) => i.condition === "poor" },
        { key: "broken", label: "Broken", icon: "ri-close-circle-line", color: "danger", test: (i) => i.condition === "broken" },
        { key: "loaned", label: "On loan", icon: "ri-hand-coin-line", color: "purple", test: (i) => i.on_loan > 0 },
        { key: "repair", label: "Being repaired", icon: "ri-tools-line", color: "warning", test: (i) => i.open_repairs > 0 },
      ],
      selects: [
        { key: "category", label: "Any kind", options: opts.categories.filter((c) => items.some((i) => i.category === c.key)).map((c) => ({ value: c.key, label: c.label, icon: c.icon, color: c.color })) },
        { key: "room", label: "Any room", options: [...usedRooms.map((r) => ({ value: String(r.id), label: r.name, color: r.colour })), { value: "none", label: "No room", color: "secondary" }] },
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "condition", label: "Worst condition first", order: [[4, "desc"]] },
        { key: "value", label: "Most valuable", order: [[5, "desc"]] },
        { key: "room", label: "Room", order: [[2, "asc"], [1, "asc"]] },
      ],
      nonSortable: [6],
      actions: actions(items),
    });
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    opts = await F.options();
    if (!opts) return;
    const add = () => F.itemWindow({ onDone: () => load() });
    $("addBtn")?.addEventListener("click", add);
    $("reportBtn").addEventListener("click", () => F.reportWindow({ onDone: () => load() }));
    $("eqRows").addEventListener("click", (e) => {
      if (e.target.closest("[data-first]")) return add();
      if (e.target.closest("a, input, .pp-check, button")) return;
      const tr = e.target.closest("tr[data-href]");
      if (tr) window.location.href = tr.dataset.href;
    });
    $("loans").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-back]");
      if (!b) return;
      UI.setButtonLoading(b, "...");
      const res = await FacilitiesAPI.giveBack(Number(b.dataset.back));
      UI.restoreButton(b);
      res.ok ? (Toast.success(res.message), load()) : Toast.error(res.message);
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
