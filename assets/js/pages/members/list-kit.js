/**
 * ============================================================================
 * PEOPLE - the list kit (Members and Visitors, round 2 - 2026-10-09)
 * ============================================================================
 * The pieces both lists share, copied from the template dashboards and
 * v1-events' nominations inbox:
 *   statCard()  - a crisp KPI card (index-11): title and sub-line, a round
 *                 icon, a big number with its change, a sparkline or a bar.
 *   listTable() - pills with counts for filtering, a search and a Sort menu
 *                 in our filter strip, a tick box per row, "Select all N
 *                 matching", and a floating "N selected" bar with actions.
 * State (pill, search, sort) is kept in the URL.
 * ============================================================================
 */
const PeopleKit = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");

  // ---------------------------------------------------------------- stat card
  /**
   * @param {object} c {icon, label, sub, value, color, delta (UI.periodDelta), series {labels,data}, bar {pct, text}}
   */
  function statCard(c) {
    const color = c.color || "primary";
    const d = c.delta;
    const delta = d
      ? `<span class="pp-stat-delta is-${d.dir}"><i class="ri-arrow-${d.dir === "down" ? "right-down" : d.dir === "up" ? "right-up" : "right"}-line"></i>${esc(d.text)}</span>`
      : "";
    const spark = c.series ? `<div class="pp-stat-spark" data-spark='${JSON.stringify(c.series).replace(/'/g, "&#39;")}' data-spark-color="${color}" data-spark-height="42"></div>` : "";
    const bar = c.bar
      ? `<div class="pp-stat-bar"><div class="progress progress-xs flex-fill"><div class="progress-bar bg-${color}" style="width:${Math.max(0, Math.min(100, c.bar.pct || 0))}%"></div></div><span>${esc(c.bar.text)}</span></div>`
      : "";
    return `<div class="card custom-card pp-stat">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3">
          <div class="min-w-0"><p class="pp-stat-label">${esc(c.label)}</p>${c.sub ? `<span class="pp-stat-sub">${esc(c.sub)}</span>` : ""}</div>
          <span class="avatar avatar-md avatar-rounded bg-${color} ${textOn(color)} flex-shrink-0"><i class="${c.icon} fs-18"></i></span>
        </div>
        <div class="d-flex align-items-end justify-content-between gap-2 mt-3">
          <div class="d-flex align-items-baseline gap-2 flex-wrap"><span class="pp-stat-value">${c.value}</span>${delta}${d ? `<span class="pp-stat-caption">${esc(d.caption)}</span>` : ""}</div>
          ${spark}
        </div>
        ${bar}
      </div>
    </div>`;
  }

  function statRow(el, cards, col = "col-xl-3 col-sm-6") {
    el.innerHTML = cards.map((c) => `<div class="${col}">${statCard(c)}</div>`).join("");
    UI.mountSparklines(el);
  }

  // ---------------------------------------------------------------- the list
  let filterRegistered = false;
  const active = {}; // tableId -> pill key
  const picks = {}; // tableId -> {select key: value} (the optional dropdowns)

  function registerPillFilter() {
    if (filterRegistered || typeof $ === "undefined" || !$.fn.dataTable) return;
    filterRegistered = true;
    $.fn.dataTable.ext.search.push((settings, data, i) => {
      const tr = settings.aoData[i] && settings.aoData[i].nTr;
      if (!tr) return true;
      const pill = active[settings.nTable.id];
      if (pill && pill !== "all" && !` ${tr.dataset.pills || ""} `.includes(` ${pill} `)) return false;
      // A dropdown matches a token in the row's data-f-{key}.
      return Object.entries(picks[settings.nTable.id] || {}).every(([k, v]) => !v || ` ${tr.getAttribute(`data-f-${k}`) || ""} `.includes(` ${v} `));
    });
  }

  /**
   * @param {object} o
   *   tableId, stripId, pillsId, rowsId - the elements
   *   items, rowHtml(item) - <tr data-id data-pills="a b"> with the first cell from checkCell(id)
   *   pills [{key, label, icon, color, test(item)}] - "all" is added first
   *   sorts [{key, label, order: [[col, "asc"|"desc"]]}]
   *   actions [{key, label, icon, run(ids)}] - the bulk bar's buttons
   *   selects [{key, label, options: [{value, label, icon, color}]}] - optional dropdowns
   *     in the strip; a row matches when its data-f-{key} holds the value
   *   noun, searchPlaceholder, columns (count, for the empty row)
   *   onDraw() - after each draw (the board follows the list)
   */
  function listTable(o) {
    registerPillFilter();
    const $id = (x) => document.getElementById(x);
    const q = new URLSearchParams(window.location.search);
    const state = { pill: q.get("pill") || "all", picked: new Set() };
    if (!o.pills.some((p) => p.key === state.pill)) state.pill = "all";
    active[o.tableId] = state.pill;
    const selects = o.selects || [];
    picks[o.tableId] = Object.fromEntries(selects.map((x) => [x.key, x.options.some((op) => String(op.value) === q.get(x.key)) ? q.get(x.key) : ""]));

    // Pills with counts.
    const all = [{ key: "all", label: "All", icon: "ri-apps-2-line", color: "primary", test: () => true }, ...o.pills];
    $id(o.pillsId).innerHTML = `<div class="pp-pills" role="tablist" aria-label="Show">${all
      .map((p) => `<button type="button" class="pp-pill${p.key === state.pill ? " is-on" : ""}" style="--q: var(--${p.color || "primary"}-rgb)" data-pill="${p.key}" role="tab" aria-selected="${p.key === state.pill}"><i class="${p.icon}"></i>${esc(p.label)}<span class="pp-pill-count">${o.items.filter(p.test).length}</span></button>`)
      .join("")}</div>`;

    // Search and sort, in our filter strip.
    const sortKey = q.get("sort") && o.sorts.some((s) => s.key === q.get("sort")) ? q.get("sort") : o.sorts[0].key;
    $id(o.stripId).innerHTML = `
      <div class="list-filterbar">
        <div class="list-search"><i class="ri-search-line"></i><input type="search" class="form-control" id="${o.stripId}Search" placeholder="${esc(o.searchPlaceholder || "Search...")}" autocomplete="off" value="${esc(q.get("q") || "")}"></div>
        ${selects.map((x) => `<select class="form-select list-filter" id="${o.stripId}_${x.key}" data-pick-select="${x.key}" aria-label="${esc(x.label)}"><option value="">${esc(x.label)}</option>${x.options.map((op) => `<option value="${esc(op.value)}"${op.icon ? ` data-icon="${op.icon}"` : ""}${op.color ? ` data-color="${op.color}"` : ""}${String(op.value) === picks[o.tableId][x.key] ? " selected" : ""}>${esc(op.label)}</option>`).join("")}</select>`).join("")}
        <select class="form-select list-filter" id="${o.stripId}Sort" aria-label="Sort">${o.sorts.map((s) => `<option value="${s.key}" data-icon="ri-sort-desc" data-color="primary"${s.key === sortKey ? " selected" : ""}>${esc(s.label)}</option>`).join("")}</select>
        <div class="list-filterbar-end"><span class="list-count" id="${o.stripId}Count"></span><button type="button" class="list-reset d-none" id="${o.stripId}Clear"><i class="ri-refresh-line"></i><span>Reset</span></button></div>
      </div>
      <div class="pp-selectall" id="${o.stripId}All" hidden></div>`;

    $id(o.rowsId).innerHTML = o.items.map(o.rowHtml).join("");
    const sortOrder = (k) => o.sorts.find((s) => s.key === k).order;
    const table = UI.initListDataTable(o.tableId, { hideDefaultSearch: true, order: sortOrder(sortKey), nonSortableColumns: [0, ...(o.nonSortable || [])], noun: o.noun, pageLength: 25, responsive: false });
    UI.enhanceSelect($id(`${o.stripId}Sort`), { search: false });
    selects.forEach((x) => UI.enhanceSelect($id(`${o.stripId}_${x.key}`), { search: x.options.length > 8 }));
    const search = $id(`${o.stripId}Search`);

    function syncUrl() {
      const p = new URLSearchParams(window.location.search);
      const set = (k, v, def) => (v && v !== def ? p.set(k, v) : p.delete(k));
      set("q", search.value.trim(), "");
      set("pill", state.pill, "all");
      set("sort", $id(`${o.stripId}Sort`).value, o.sorts[0].key);
      selects.forEach((x) => set(x.key, picks[o.tableId][x.key], ""));
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
    }

    function counts() {
      const info = table ? table.page.info() : { recordsDisplay: 0, recordsTotal: 0 };
      const filtered = state.pill !== "all" || search.value.trim() || Object.values(picks[o.tableId]).some(Boolean);
      $id(`${o.stripId}Count`).textContent = filtered ? `${info.recordsDisplay} of ${info.recordsTotal} ${o.noun}` : `${info.recordsTotal} ${o.noun}`;
      $id(`${o.stripId}Clear`).classList.toggle("d-none", !filtered && $id(`${o.stripId}Sort`).value === o.sorts[0].key);
    }

    // ---- ticking rows
    const pageRows = () => (table ? table.rows({ page: "current", search: "applied" }).nodes().toArray() : []);
    const matchRows = () => (table ? table.rows({ search: "applied" }).nodes().toArray() : []);
    const headBox = () => document.querySelector(`#${o.tableId} thead .pp-pick-page`);

    function syncPicks() {
      document.querySelectorAll(`#${o.tableId} tbody tr[data-id]`).forEach((tr) => {
        const on = state.picked.has(Number(tr.dataset.id));
        tr.classList.toggle("is-picked", on);
        const box = tr.querySelector(".pp-pick");
        if (box) box.checked = on;
      });
      const rows = pageRows();
      const on = rows.filter((tr) => state.picked.has(Number(tr.dataset.id))).length;
      const hb = headBox();
      if (hb) {
        hb.checked = rows.length > 0 && on === rows.length;
        hb.indeterminate = on > 0 && on < rows.length;
      }
      const matching = matchRows();
      const line = $id(`${o.stripId}All`);
      const allMatchingPicked = matching.every((tr) => state.picked.has(Number(tr.dataset.id)));
      line.hidden = !(rows.length && on === rows.length && matching.length > rows.length);
      line.innerHTML = allMatchingPicked
        ? `All <strong>${matching.length}</strong> ${o.noun} that match are picked. <button type="button" class="btn btn-link p-0 align-baseline" data-pp="none">Clear</button>`
        : `All <strong>${rows.length}</strong> on this page are picked. <button type="button" class="btn btn-link p-0 align-baseline" data-pp="all">Pick all ${matching.length} that match</button>`;
      renderBar();
    }

    // ---- the floating bar
    let bar = document.getElementById(`${o.tableId}Bar`);
    if (!bar) {
      document.body.insertAdjacentHTML("beforeend", `<div class="pp-bulkbar" id="${o.tableId}Bar" hidden role="region" aria-label="Selected"></div>`);
      bar = document.getElementById(`${o.tableId}Bar`);
    }
    function renderBar() {
      const n = state.picked.size;
      bar.hidden = n === 0;
      if (!n) return;
      bar.innerHTML = `<span class="pp-bulkbar-count"><strong>${n}</strong> selected</span>${(o.actions || [])
        .map((a) => `<button type="button" class="btn btn-sm ${a.primary ? "btn-light" : "pp-bulkbar-btn"}" data-act="${a.key}"><i class="${a.icon} me-1"></i>${esc(a.label)}</button>`)
        .join("")}<button type="button" class="btn btn-sm pp-bulkbar-btn" data-act="__clear" aria-label="Clear the selection"><i class="ri-close-line"></i></button>`;
    }
    bar.onclick = (e) => {
      const b = e.target.closest("[data-act]");
      if (!b) return;
      if (b.dataset.act === "__clear") return clear();
      const a = (o.actions || []).find((x) => x.key === b.dataset.act);
      if (a) a.run([...state.picked]);
    };

    function clear() {
      state.picked.clear();
      syncPicks();
    }

    function apply() {
      if (table) {
        table.search(search.value);
        table.order(sortOrder($id(`${o.stripId}Sort`).value));
        table.draw();
      }
      counts();
      syncUrl();
    }

    // ---- wiring
    $id(o.pillsId).onclick = (e) => {
      const b = e.target.closest("[data-pill]");
      if (!b || b.dataset.pill === state.pill) return;
      state.pill = b.dataset.pill;
      active[o.tableId] = state.pill;
      $id(o.pillsId).querySelectorAll(".pp-pill").forEach((x) => {
        x.classList.toggle("is-on", x === b);
        x.setAttribute("aria-selected", x === b);
      });
      apply();
    };
    let t = null;
    search.addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(apply, 250);
    });
    $id(`${o.stripId}Sort`).addEventListener("change", apply);
    selects.forEach((x) =>
      $id(`${o.stripId}_${x.key}`).addEventListener("change", (e) => {
        picks[o.tableId][x.key] = e.target.value;
        apply();
      }),
    );
    $id(`${o.stripId}Clear`).addEventListener("click", () => {
      search.value = "";
      $id(`${o.stripId}Sort`).value = o.sorts[0].key;
      UI.syncSelect($id(`${o.stripId}Sort`));
      selects.forEach((x) => {
        picks[o.tableId][x.key] = "";
        $id(`${o.stripId}_${x.key}`).value = "";
        UI.syncSelect($id(`${o.stripId}_${x.key}`));
      });
      $id(o.pillsId).querySelector('[data-pill="all"]').click();
      apply();
    });
    $id(`${o.stripId}All`).onclick = (e) => {
      const b = e.target.closest("[data-pp]");
      if (!b) return;
      if (b.dataset.pp === "all") matchRows().forEach((tr) => state.picked.add(Number(tr.dataset.id)));
      else state.picked.clear();
      syncPicks();
    };
    document.getElementById(o.tableId).addEventListener("change", (e) => {
      if (e.target.classList.contains("pp-pick-page")) {
        pageRows().forEach((tr) => (e.target.checked ? state.picked.add(Number(tr.dataset.id)) : state.picked.delete(Number(tr.dataset.id))));
        return syncPicks();
      }
      if (e.target.classList.contains("pp-pick")) {
        const id = Number(e.target.value);
        e.target.checked ? state.picked.add(id) : state.picked.delete(id);
        syncPicks();
      }
    });
    if (table) {
      window.jQuery(`#${o.tableId}`).off("draw.ppkit").on("draw.dt.ppkit", () => {
        syncPicks();
        counts();
        o.onDraw?.();
      });
    }
    apply();

    return {
      table,
      state,
      clear,
      /** The rows the pills, search and sort show right now (ids), in order. */
      visibleIds: () => matchRows().map((tr) => Number(tr.dataset.id)),
      destroy() {
        bar.hidden = true;
        state.picked.clear();
      },
    };
  }

  /** The first cell of a row: its tick box. */
  const checkCell = (id, label) => `<td class="pp-check"><input type="checkbox" class="form-check-input pp-pick" value="${id}" aria-label="Pick ${esc(label)}"></td>`;
  const checkHead = () => `<th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everyone on this page"></th>`;

  /** A window in parts: [{icon, title, hint, body}] */
  const parts = (list) =>
    list
      .map((p) => `<section class="app-modal-part"><div class="app-modal-part-head"><i class="${p.icon}"></i>${esc(p.title)}${p.hint ? `<small>${esc(p.hint)}</small>` : ""}</div>${p.body}</section>`)
      .join("");

  /**
   * A window (.app-modal, navy band) - body in parts, and one action.
   * run() returns the API result; the window closes when it is ok.
   */
  function confirmWindow({ title, subtitle = "", icon, danger = false, body, go, run }) {
    document.getElementById("ppModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal${danger ? " is-danger" : ""}" id="ppModal" tabindex="-1" aria-labelledby="ppModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon"><i class="${icon}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="ppModalTitle">${esc(title)}</h5>${subtitle ? `<div class="app-modal-subtitle">${esc(subtitle)}</div>` : ""}</div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">${body}</div>
          <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn ${danger ? "btn-danger" : "btn-primary"}" id="ppGo">${go}</button></div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById("ppModal");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    document.getElementById("ppGo").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Working...");
      const res = await run(el);
      UI.restoreButton(btn);
      if (!res) return;
      if (!res.ok) return Toast.error(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message);
    });
    return el;
  }

  /** "Send message" for picked people: their names go along (sessionStorage), the ids in the link. */
  function messagePeople(messagesUrl, people) {
    try {
      sessionStorage.setItem("pp-picked", JSON.stringify(people.map((p) => ({ id: p.id, name: p.name }))));
    } catch (e) {
      /* names are a nicety - the composer shows "Person 12" without them */
    }
    window.location.href = `${messagesUrl}?channel=sms&people=${people.map((p) => p.id).join(",")}`;
  }

  return { statCard, statRow, listTable, checkCell, checkHead, parts, confirmWindow, messagePeople, esc };
})();

window.PeopleKit = PeopleKit;
