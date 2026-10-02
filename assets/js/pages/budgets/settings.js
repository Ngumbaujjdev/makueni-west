/**
 * ============================================================================
 * PAGE - BUDGET SETTINGS (includes/budget/settings.php, every level)
 * ============================================================================
 * One simple page per place. Lines: the money in and money out lines its
 * budgets are built from - the diocese's shared ones (locked, unless this
 * is the diocese) and the place's own. Add, rename, switch off, or delete a
 * line no budget has used. Deductions arrive in the next tab.
 * ============================================================================
 */
const BudgetsSettings = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  let d = null;
  let search = "";
  let showOff = false;
  const expanded = { in: false, out: false }; // "Show all" per list
  const LIMIT = 8;
  let editing = null; // the line being changed, or null for a new one
  let side = "out";
  let modal = null;

  async function init() {
    B.showFlash();
    modal = new bootstrap.Modal(document.getElementById("lineModal"));
    document.getElementById("addLineBtn").addEventListener("click", () => openLine(null));
    document.getElementById("lineSaveBtn").addEventListener("click", saveLine);
    ["lineName", "lineDescription"].forEach((elId) => document.getElementById(elId).addEventListener("input", preview));
    document.getElementById("lineShare").addEventListener("change", preview);
    renderToolbar();
    await load();
  }

  async function load() {
    const res = await BudgetsAPI.settings();
    if (!res.ok) {
      document.getElementById("statCardsRow").innerHTML = `<div class="col-12"><div class="alert alert-danger">${B.esc(res.message)}</div></div>`;
      ["linesIn", "linesOut"].forEach((elId) => (document.getElementById(elId).innerHTML = ""));
      return;
    }
    d = res.data;
    const place = d.place?.name || "";
    document.getElementById("placeLine").textContent = `The lines ${place ? `${place}'s` : "your"} budgets are built from`;
    document.getElementById("addLineBtn").hidden = !d.can.update;
    const banner = document.getElementById("readOnlyBanner");
    banner.classList.toggle("d-none", d.can.update);
    banner.classList.toggle("d-flex", !d.can.update);
    renderStats();
    renderLists();
  }

  const isDiocese = () => d?.place?.type === "diocese";

  function renderStats() {
    const lines = d.lines;
    const active = lines.filter((l) => l.is_active);
    const own = lines.filter((l) => l.is_own);
    const shared = lines.filter((l) => !l.is_own && l.shared_with);
    const off = lines.filter((l) => !l.is_active);
    document.querySelector('[data-tab-figure="lines"]').textContent = `${active.length} in use`;
    UI.renderStatCardsRow("statCardsRow", [
      { icon: "ri-list-check-2", label: "Lines in use", value: active.length, color: "primary", sub: `${active.filter((l) => l.side === "in").length} money in · ${active.filter((l) => l.side === "out").length} money out` },
      { icon: "ri-user-star-line", label: isDiocese() ? "The diocese's own" : "Our own", value: own.length, color: "success", sub: own.length ? "Added by you, only you use them" : "Add a line for anything not in the list" },
      { icon: "ri-share-line", label: isDiocese() ? "Shared with others" : "Set by the diocese", value: shared.length, color: "purple", sub: isDiocese() ? "Churches and regions use them" : "Shared lines - only the diocese changes them" },
      { icon: "ri-toggle-line", label: "Switched off", value: off.length, color: "warning", sub: off.length ? "Not offered for new budgets" : "Every line is on" },
    ]);
  }

  function renderToolbar() {
    document.getElementById("linesToolbar").innerHTML = `
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="list-search flex-fill" style="min-width: 14rem;"><i class="ri-search-line"></i><input type="search" class="form-control" id="lineSearch" placeholder="Find a line..." autocomplete="off"></div>
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" id="showOffSwitch">
          <label class="form-check-label fw-semibold" for="showOffSwitch">Show switched-off lines</label>
        </div>
      </div>`;
    document.getElementById("lineSearch").addEventListener("input", (e) => {
      search = e.target.value.trim().toLowerCase();
      renderLists();
    });
    document.getElementById("showOffSwitch").addEventListener("change", (e) => {
      showOff = e.target.checked;
      renderLists();
    });
  }

  function renderLists() {
    if (!d) return;
    ["in", "out"].forEach((s) => {
      const all = d.lines.filter((l) => l.side === s);
      const lines = all.filter((l) => (showOff || l.is_active) && (!search || `${l.name} ${l.description || ""}`.toLowerCase().includes(search)));
      document.getElementById(s === "in" ? "inCount" : "outCount").textContent = `${all.filter((l) => l.is_active).length} lines`;
      const el = document.getElementById(s === "in" ? "linesIn" : "linesOut");
      if (!lines.length) {
        el.innerHTML = `<div class="list-empty py-5"><span class="list-empty-icon bg-${s === "in" ? "success" : "danger"} text-white"><i class="ri-list-check-2"></i></span><div class="fw-semibold mt-2">${search ? "No line matches" : `No money ${s} lines yet`}</div></div>`;
        return;
      }
      // The first few, then "Show all" - so a long list doesn't leave a gap beside a short one.
      const open = expanded[s] || search;
      const shown = open ? lines : lines.slice(0, LIMIT);
      el.innerHTML = `<ul class="budget-items budget-settings-lines">${shown.map((l, i) => row(l, s, i)).join("")}</ul>
        ${lines.length > LIMIT && !search ? `<div class="budget-items-total"><span>${open ? `All ${lines.length} lines` : `${LIMIT} of ${lines.length} lines`}</span><button type="button" class="btn btn-sm btn-outline-primary" data-more="${s}">${open ? "Show fewer" : "Show all"}</button></div>` : ""}`;
      el.querySelector("[data-more]")?.addEventListener("click", () => {
        expanded[s] = !expanded[s];
        renderLists();
      });
      el.querySelectorAll("[data-switch]").forEach((sw) => sw.addEventListener("change", () => toggle(Number(sw.dataset.switch), sw.checked, sw)));
      el.querySelectorAll("[data-edit]").forEach((btn) => btn.addEventListener("click", () => openLine(d.lines.find((l) => l.id === Number(btn.dataset.edit)))));
      el.querySelectorAll("[data-delete]").forEach((btn) => btn.addEventListener("click", () => remove(d.lines.find((l) => l.id === Number(btn.dataset.delete)))));
    });
  }

  function row(l, s, i) {
    const color = B.lineColor(s, i);
    const chips = [
      l.is_own ? `<span class="soft-chip soft-success">${isDiocese() ? "The diocese's own" : "Ours"}</span>` : `<span class="soft-chip soft-purple"><i class="ri-lock-line me-1"></i>${isDiocese() ? l.shared_label : `Set by the diocese · ${B.esc(l.shared_label || "")}`}</span>`,
      l.is_active ? "" : '<span class="soft-chip soft-warning">Switched off</span>',
    ].join(" ");
    const used = l.used_here ? `Used in ${l.used_here} of your ${l.used_here === 1 ? "budget" : "budgets"}` : "Not in any of your budgets yet";
    return `
      <li class="budget-item${l.is_active ? "" : " is-off"}">
        <span class="avatar avatar-md bg-${color} ${B.tileText(color)} flex-shrink-0"><i class="${B.lineIcon(l.name, s)}"></i></span>
        <div class="budget-item-main">
          <div class="budget-item-top">
            <span class="budget-item-name">${B.esc(l.name)} ${chips}</span>
            ${l.editable ? `
              <span class="d-inline-flex align-items-center gap-1 flex-shrink-0">
                <span class="form-check form-switch mb-0" title="${l.is_active ? "On: offered for new budgets" : "Off: not offered for new budgets"}"><input class="form-check-input" type="checkbox" role="switch" data-switch="${l.id}" ${l.is_active ? "checked" : ""} aria-label="On or off"></span>
                <button type="button" class="btn btn-sm btn-primary-light" data-edit="${l.id}" title="Change" aria-label="Change"><i class="ri-edit-line"></i></button>
                ${l.used_anywhere === 0 && !l.is_system_default ? `<button type="button" class="btn btn-sm btn-danger-light" data-delete="${l.id}" title="Delete" aria-label="Delete"><i class="ri-delete-bin-line"></i></button>` : ""}
              </span>` : ""}
          </div>
          ${l.description ? `<div class="fs-12 mb-1">${B.esc(l.description)}</div>` : ""}
          <div class="budget-item-figs"><span>${used}</span>${isDiocese() && !l.is_own && l.used_anywhere ? `<span>${l.used_anywhere} budgets across the diocese</span>` : ""}</div>
        </div>
      </li>`;
  }

  // ---------------------------------------------------------------- add / change

  function openLine(line) {
    editing = line;
    side = line?.side || "out";
    const used = line ? line.used_anywhere > 0 : false;
    document.getElementById("lineModalTitle").textContent = line ? `Change ${line.name}` : "Add a line";
    document.getElementById("lineModalSub").textContent = line ? (used ? `Used in ${line.used_anywhere} ${line.used_anywhere === 1 ? "budget" : "budgets"} - the change shows there too` : "Not used in a budget yet") : isDiocese() ? "A line for the diocese's budgets, or shared with churches and regions" : `Only ${d.place?.name || "you"} sees and uses it`;
    document.getElementById("lineSideWrap").innerHTML = UI.renderSegmented(
      "lineSide",
      [
        { value: "in", label: '<i class="ri-arrow-down-circle-line me-1"></i>Money in' },
        { value: "out", label: '<i class="ri-arrow-up-circle-line me-1"></i>Money out' },
      ],
      side,
      { ariaLabel: "Money in or money out" },
    );
    UI.wireSegmented("lineSide", (value) => {
      side = value;
      preview();
    });
    document.querySelectorAll("#lineSide .seg-btn").forEach((b) => (b.disabled = used));
    document.getElementById("lineSideHint").textContent = used ? "It's already used in a budget, so it stays where it is." : "Money in: what you receive. Money out: what you spend.";
    document.getElementById("lineName").value = line?.name || "";
    document.getElementById("lineName").classList.remove("is-invalid");
    document.getElementById("lineDescription").value = line?.description || "";
    const shareWrap = document.getElementById("lineShareWrap");
    shareWrap.hidden = !isDiocese();
    if (isDiocese()) {
      const select = document.getElementById("lineShare");
      select.value = line ? (line.is_own ? "own" : line.shared_with || "all") : "own";
      // An own line stays own; a shared one can change who uses it.
      [...select.options].forEach((o) => (o.disabled = !!line && (line.is_own ? o.value !== "own" : o.value === "own")));
      UI.enhanceSelect(select, { search: false });
      UI.syncSelect(select);
    }
    preview();
    modal.show();
    setTimeout(() => document.getElementById("lineName").focus(), 300);
  }

  /** The line as the budget form will show it. */
  function preview() {
    const name = document.getElementById("lineName").value.trim() || "Your line";
    const desc = document.getElementById("lineDescription").value.trim();
    const color = side === "in" ? "success" : "danger";
    document.getElementById("lineModalIcon").className = `app-modal-icon bg-${color}`;
    document.getElementById("linePreview").style.setProperty("--tile-rgb", `var(--${color}-rgb)`);
    document.getElementById("linePreview").innerHTML = `
      <div class="num-tile-label">
        <span class="num-tile-icon bg-${color} text-white"><i class="${B.lineIcon(name, side)}"></i></span>
        <span class="budget-tile-name"><span>${B.esc(name)}</span>${desc ? `<small>${B.esc(desc)}</small>` : ""}</span>
      </div>
      <div class="input-group"><span class="input-group-text">KES</span><input type="text" class="form-control text-end" placeholder="0.00" disabled></div>
      <div class="num-tile-foot"><span class="num-tile-last">${side === "in" ? "Money in" : "Money out"}</span></div>`;
  }

  async function saveLine() {
    const name = document.getElementById("lineName").value.trim();
    document.getElementById("lineName").classList.toggle("is-invalid", !name);
    if (!name) return;
    const body = { name, side, description: document.getElementById("lineDescription").value.trim() || null };
    if (isDiocese()) {
      const share = document.getElementById("lineShare").value;
      if (!editing || !editing.is_own) body.share_with = share;
    }
    if (editing && editing.used_anywhere > 0) delete body.side;
    const btn = document.getElementById("lineSaveBtn");
    UI.setButtonLoading(btn, "Saving...");
    const res = editing ? await BudgetsAPI.changeLine(editing.id, body) : await BudgetsAPI.addLine(body);
    UI.restoreButton(btn);
    if (!res.ok) {
      Toast.error(res.message);
      return;
    }
    modal.hide();
    Toast.success(res.message);
    await load();
  }

  async function toggle(id, on, sw) {
    const res = await BudgetsAPI.changeLine(id, { is_active: on });
    if (!res.ok) {
      sw.checked = !on;
      Toast.error(res.message);
      return;
    }
    const line = d.lines.find((l) => l.id === id);
    Toast.success(on ? `${line.name} is on - offered for new budgets` : `${line.name} is off - not offered for new budgets`);
    await load();
  }

  function remove(line) {
    Toast.confirm(
      `Delete ${line.name}? It isn't used in any budget.`,
      async () => {
        const res = await BudgetsAPI.removeLine(line.id);
        res.ok ? Toast.success(res.message) : Toast.error(res.message);
        await load();
      },
      null,
      { title: "Delete line", confirmText: "Delete", type: "error" },
    );
  }

  return { init };
})();

window.BudgetsSettings = BudgetsSettings;
