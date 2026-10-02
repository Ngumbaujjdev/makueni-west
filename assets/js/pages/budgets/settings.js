/**
 * ============================================================================
 * PAGE - BUDGET SETTINGS (includes/budget/settings.php, every level)
 * ============================================================================
 * One simple page per place. Lines: the money in and money out lines its
 * budgets are built from - the diocese's shared ones (locked, unless this
 * is the diocese) and the place's own. Add, rename, switch off, or delete a
 * line no budget has used. Deductions: shares of money in, worked out for
 * you - the place's own, and those set above it (locked).
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
  let dedModal = null;
  let dedEditing = null;
  const ded = { type: "percentage", basis: "all" };

  async function init() {
    B.showFlash();
    modal = new bootstrap.Modal(document.getElementById("lineModal"));
    dedModal = new bootstrap.Modal(document.getElementById("deductionModal"));
    document.getElementById("addDeductionBtn").addEventListener("click", () => openDeduction(null));
    document.getElementById("dedSaveBtn").addEventListener("click", saveDeduction);
    ["dedName", "dedValue", "dedNewLine"].forEach((elId) => document.getElementById(elId).addEventListener("input", example));
    document.getElementById("dedApplies").addEventListener("change", () => (fillPaidThrough(), example()));
    document.getElementById("dedLine").addEventListener("change", () => {
      document.getElementById("dedNewLine").hidden = document.getElementById("dedLine").value !== "new";
      example();
    });
    document.getElementById("dedLines").addEventListener("change", example);
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
    renderDeductions();
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

  // ---------------------------------------------------------------- deductions

  const ruleLabel = (r) => r.rule;

  function renderDeductions() {
    document.getElementById("addDeductionBtn").hidden = !d.can.update;
    const list = d.deductions || [];
    const on = list.filter((x) => x.is_active).length;
    document.querySelector('[data-tab-figure="deductions"]').textContent = list.length ? `${on} on` : "None yet";
    const el = document.getElementById("deductionsList");
    if (!list.length) {
      el.innerHTML = `<div class="col-12"><div class="card custom-card"><div class="card-body"><div class="list-empty py-4">
        <span class="list-empty-icon bg-purple text-white"><i class="ri-percent-line"></i></span>
        <div class="fw-semibold mt-2">No deductions yet</div>
        <div class="fs-12">${d.can.update ? "Add one - e.g. 10% of the tithes received, for the diocese." : "None has been set for you."}</div></div></div></div></div>`;
      return;
    }
    el.innerHTML = list
      .map((x) => {
        const color = x.is_own ? "purple" : "primary";
        // "KES 60,000.00 Tithes received → KES 6,000.00 due" - always on what is recorded.
        const on = (x.rule.match(/% of (.+) received$/) || [])[1] || "money";
        const example = x.deduction_type === "percentage"
          ? `${B.money(60000)} ${B.esc(on === "all money" ? "" : on)} received → <b>${B.money((60000 * x.deduction_value) / 100)}</b> due`.replace("  ", " ")
          : `<b>${B.money(x.deduction_value)}</b> a month · <b>${B.money(x.deduction_value * 12)}</b> a year`;
        return `
          <div class="col-xl-6">
            <div class="card custom-card budget-deduction-card${x.is_active ? "" : " is-off"}">
              <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                  <span class="avatar avatar-md bg-${color} text-white flex-shrink-0"><i class="ri-percent-line"></i></span>
                  <div class="flex-fill" style="min-width: 0;">
                    <div class="d-flex flex-wrap align-items-center gap-1">
                      <span class="fw-bold fs-15">${B.esc(x.name)}</span>
                      ${x.is_own ? '<span class="soft-chip soft-success">Ours</span>' : `<span class="soft-chip soft-purple"><i class="ri-lock-line me-1"></i>${B.esc(x.set_by)}</span>`}
                      ${x.is_active ? "" : '<span class="soft-chip soft-warning">Switched off</span>'}
                    </div>
                    <div class="budget-deduction-rule">${B.esc(ruleLabel(x))}</div>
                    <ul class="list-unstyled mb-0 budget-facts">
                      <li><span>Paid through</span><span class="fw-semibold">${x.line ? B.lineDot(x.line) : '<span class="text-danger">No line yet</span>'}</span></li>
                      <li><span>Applies to</span><span class="fw-semibold">${B.esc(x.is_own ? x.applies_label : "This place")}</span></li>
                      <li><span>Example</span><span>${example}</span></li>
                      <li><span>Used in</span><span class="fw-semibold">${x.used} ${x.used === 1 ? "budget" : "budgets"}</span></li>
                    </ul>
                  </div>
                  ${x.editable ? `
                    <span class="d-inline-flex flex-column align-items-end gap-2 flex-shrink-0">
                      <span class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-ded-switch="${x.id}" ${x.is_active ? "checked" : ""} aria-label="On or off"></span>
                      <span class="d-inline-flex gap-1">
                        <button type="button" class="btn btn-sm btn-primary-light" data-ded-edit="${x.id}" title="Change" aria-label="Change"><i class="ri-edit-line"></i></button>
                        ${x.used === 0 ? `<button type="button" class="btn btn-sm btn-danger-light" data-ded-delete="${x.id}" title="Delete" aria-label="Delete"><i class="ri-delete-bin-line"></i></button>` : ""}
                      </span>
                    </span>` : ""}
                </div>
              </div>
            </div>
          </div>`;
      })
      .join("");
    el.querySelectorAll("[data-ded-switch]").forEach((sw) =>
      sw.addEventListener("change", async () => {
        const res = await BudgetsAPI.changeDeduction(Number(sw.dataset.dedSwitch), { is_active: sw.checked });
        res.ok ? Toast.success(res.message) : (Toast.error(res.message), (sw.checked = !sw.checked));
        await load();
      }),
    );
    el.querySelectorAll("[data-ded-edit]").forEach((b) => b.addEventListener("click", () => openDeduction(list.find((x) => x.id === Number(b.dataset.dedEdit)))));
    el.querySelectorAll("[data-ded-delete]").forEach((b) =>
      b.addEventListener("click", () => {
        const x = list.find((y) => y.id === Number(b.dataset.dedDelete));
        Toast.confirm(`Delete ${x.name}? No budget has used it yet.`, async () => {
          const res = await BudgetsAPI.removeDeduction(x.id);
          res.ok ? Toast.success(res.message) : Toast.error(res.message);
          await load();
        }, null, { title: "Delete deduction", confirmText: "Delete", type: "error" });
      }),
    );
  }

  function openDeduction(x) {
    dedEditing = x;
    ded.type = x?.deduction_type || "percentage";
    // The diocese share is a % of the Tithes received - so a new diocese deduction starts on Tithes.
    ded.basis = x?.basis || (isDiocese() ? "lines" : "all");
    document.getElementById("deductionModalTitle").textContent = x ? `Change ${x.name}` : "Add a deduction";
    document.getElementById("dedName").value = x?.name || "";
    document.getElementById("dedName").classList.remove("is-invalid");
    document.getElementById("dedValue").value = x ? String(x.deduction_value) : "";
    document.getElementById("dedTypeWrap").innerHTML = UI.renderSegmented("dedType", [{ value: "percentage", label: "% of money received" }, { value: "fixed_amount", label: "Fixed each month" }], ded.type, { ariaLabel: "How it's worked out" });
    UI.wireSegmented("dedType", (v) => ((ded.type = v), example()));
    document.getElementById("dedBasisWrap").innerHTML = UI.renderSegmented("dedBasis", [{ value: "all", label: "All money received" }, { value: "lines", label: "Only some lines" }], ded.basis, { ariaLabel: "On which money in" });
    UI.wireSegmented("dedBasis", (v) => ((ded.basis = v), example()));
    const lines = document.getElementById("dedLines");
    lines.innerHTML = d.lines.filter((l) => l.side === "in" && l.is_active).map((l) => `<option value="${l.id}" ${(x ? x.basis_line_ids?.includes(l.id) : isDiocese() && /^tithes?$/i.test(l.name.trim())) ? "selected" : ""}>${B.esc(l.name)}</option>`).join("");
    UI.enhanceSelect(lines, { search: true });
    const applies = document.getElementById("dedApplies");
    applies.innerHTML = d.applies_choices.map((c) => `<option value="${c.value}">${B.esc(c.label)}</option>`).join("");
    applies.value = x?.applies_to_level || d.applies_choices[d.applies_choices.length > 1 ? 1 : 0].value;
    document.getElementById("dedAppliesWrap").hidden = d.applies_choices.length < 2; // a church's deductions are always its own
    UI.enhanceSelect(applies, { search: false });
    UI.syncSelect(applies);
    fillPaidThrough(x?.budget_line_id);
    example();
    dedModal.show();
  }

  /** The money out lines this deduction can be paid through, for who it applies to - or a new line. */
  function fillPaidThrough(selected) {
    const level = document.getElementById("dedApplies").value;
    const select = document.getElementById("dedLine");
    const keep = selected ?? (select.value && select.value !== "new" ? Number(select.value) : null);
    const choices = d.paid_through.filter((l) => l.for.includes(level));
    const canNew = level === "own" || isDiocese();
    select.innerHTML = [
      '<option value="">Choose the line</option>',
      ...choices.map((l) => `<option value="${l.id}" ${l.id === keep ? "selected" : ""}>${B.esc(l.name)}</option>`),
      canNew ? '<option value="new" data-icon="ri-add-line" data-color="success">+ Make a new line…</option>' : "",
    ].join("");
    UI.enhanceSelect(select, { search: choices.length > 8 });
    UI.syncSelect(select);
    document.getElementById("dedNewLine").hidden = select.value !== "new";
  }

  function example() {
    const isPct = ded.type === "percentage";
    document.getElementById("dedPrefix").textContent = isPct ? "%" : "KES";
    document.getElementById("dedTypeHint").textContent = isPct ? "A share of the money actually received - what is recorded. On a budget it is estimated from the plan until money comes in." : "The same amount every month - twelve times that on a whole-year budget.";
    document.getElementById("dedBasisBlock").hidden = !isPct;
    document.getElementById("dedLinesWrap").hidden = ded.basis !== "lines";
    const value = Number(String(document.getElementById("dedValue").value).replace(/[^0-9.]/g, "")) || 0;
    const name = document.getElementById("dedName").value.trim() || "This deduction";
    const lineSel = document.getElementById("dedLine");
    const line = lineSel.value === "new" ? document.getElementById("dedNewLine").value.trim() || name : lineSel.selectedOptions[0]?.value ? lineSel.selectedOptions[0].text : "its line";
    const chosen = [...document.getElementById("dedLines").selectedOptions].map((o) => o.text);
    const on = ded.basis === "lines" && chosen.length ? (chosen.length > 1 ? `${chosen.slice(0, -1).join(", ")} and ${chosen[chosen.length - 1]}` : chosen[0]) : "Money";
    document.getElementById("dedExample").innerHTML = isPct
      ? `<div class="budget-example-row"><span>${B.esc(on)} received (recorded)</span><b>KES 60,000.00</b></div>
         <div class="budget-example-row is-out"><span>${B.esc(name)} due (${value || 0}%)</span><b>${B.money((60000 * value) / 100)}</b></div>
         <div class="fs-12 mt-2">Worked out on what is recorded, and sent as money out on <b>${B.esc(line)}</b>. On a budget it starts as an estimate from the plan.</div>`
      : `<div class="budget-example-row"><span>A month budget</span><b>${B.money(value)}</b></div>
         <div class="budget-example-row is-out"><span>A whole-year budget</span><b>${B.money(value * 12)}</b></div>
         <div class="fs-12 mt-2">Filled in on <b>${B.esc(line)}</b>.</div>`;
  }

  async function saveDeduction() {
    const name = document.getElementById("dedName").value.trim();
    document.getElementById("dedName").classList.toggle("is-invalid", !name);
    const value = Number(String(document.getElementById("dedValue").value).replace(/[^0-9.]/g, ""));
    const lineVal = document.getElementById("dedLine").value;
    if (!name || !value || !lineVal) {
      Toast.warning(!name ? "Give the deduction a name." : !value ? "Type the % or the amount." : "Choose the line it's paid through.");
      return;
    }
    const body = {
      name,
      deduction_type: ded.type,
      deduction_value: value,
      basis: ded.type === "percentage" ? ded.basis : "all",
      basis_line_ids: ded.basis === "lines" ? [...document.getElementById("dedLines").selectedOptions].map((o) => Number(o.value)) : [],
      applies_to_level: document.getElementById("dedApplies").value,
      budget_line_id: lineVal === "new" ? null : Number(lineVal),
      new_line_name: lineVal === "new" ? document.getElementById("dedNewLine").value.trim() || name : null,
    };
    const btn = document.getElementById("dedSaveBtn");
    UI.setButtonLoading(btn, "Saving...");
    const res = dedEditing ? await BudgetsAPI.changeDeduction(dedEditing.id, body) : await BudgetsAPI.addDeduction(body);
    UI.restoreButton(btn);
    if (!res.ok) {
      Toast.error(res.message);
      return;
    }
    dedModal.hide();
    Toast.success(res.message);
    await load();
  }

  return { init };
})();

window.BudgetsSettings = BudgetsSettings;
