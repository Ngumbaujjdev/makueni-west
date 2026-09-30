/**
 * ============================================================================
 * PAGE - NEW / CHANGE BUDGET (includes/budget/form.php, every level)
 * ============================================================================
 * One page instead of the old 3-step wizard:
 *   1 Which month or year?  (periods that already have a budget are greyed out)
 *   2 Money in    - one amount box per line
 *   3 Money out   - one amount box per line
 *   4 Notes
 * "Copy amounts" fills everything from the last budget (with Undo), totals
 * update as you type, and the whole budget is saved in one request.
 * ============================================================================
 */
const BudgetsForm = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const params = new URLSearchParams(window.location.search);
  const EXTRA_AFTER = 8; // lines shown before "Show all lines", when nothing is filled in yet

  const state = {
    id: Number(params.get("id")) || null,
    budget: null, // the budget being changed
    year: Number(params.get("year")) || new Date().getFullYear(),
    month: null, // null = whole year
    lines: { in: [], out: [] },
    amounts: {}, // line id -> amount
    taken: { year: null, months: {} },
    ownYear: null, // the year the budget being changed had when loaded
    ownTaken: null, // that year's taken periods - without the budget itself
    copy: null,
    expanded: { in: false, out: false },
    dirty: false,
    saving: false,
  };

  async function init() {
    const wanted = requestedMonth();
    const res = state.id ? await BudgetsAPI.formFor(state.id) : await BudgetsAPI.form({ year: state.year, month: wanted === null ? "year" : wanted });
    if (!res.ok) {
      document.getElementById("budgetForm").innerHTML = `<div class="col-12">${emptyCard(res.message)}</div>`;
      return;
    }
    const d = res.data;
    state.lines = d.lines;
    state.copy = d.copy;
    state.taken = d.taken;

    if (d.budget) {
      if (d.budget.status === "closed") {
        B.flash("This budget is closed. Reopen it to make changes.", "error");
        window.location.href = B.url("budget.php", { id: d.budget.id });
        return;
      }
      state.budget = d.budget;
      state.year = Number(d.year);
      state.month = d.month;
      state.ownYear = Number(d.year);
      state.ownTaken = d.taken;
      state.amounts = { ...d.budget.amounts };
      document.getElementById("notesInput").value = d.budget.notes || "";
      document.getElementById("formSub").textContent = `The ${d.budget.period_label} budget${d.place?.name ? ` of ${d.place.name}` : ""}`;
    } else {
      state.year = Number(d.year);
      state.month = pickMonth(wanted);
    }

    renderYears();
    renderPeriods();
    renderLines("in");
    renderLines("out");
    renderCopy();
    renderButtons();
    updateTotals();

    document.getElementById("notesInput").addEventListener("input", () => (state.dirty = true));
    document.getElementById("copyBtn").addEventListener("click", copyAmounts);
    document.getElementById("saveStartBtn").addEventListener("click", () => save(true));
    document.getElementById("saveDraftBtn").addEventListener("click", () => save(false));
    window.addEventListener("beforeunload", (e) => {
      if (state.dirty && !state.saving) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }

  /** ?month=3, ?month=year (whole year), or nothing. */
  function requestedMonth() {
    const m = params.get("month");
    if (m === "year") return null;
    return m ? Number(m) : undefined;
  }

  const isTakenMonth = (m) => state.taken.year !== null || state.taken.months[m] !== undefined;
  const isTakenYear = () => state.taken.year !== null || Object.keys(state.taken.months).length > 0;

  /** The asked-for period if it's free; otherwise the next free month from now; otherwise the whole year. */
  function pickMonth(wanted) {
    if (wanted === null) return isTakenYear() ? firstFreeMonth() : null;
    if (wanted && !isTakenMonth(wanted)) return wanted;
    return firstFreeMonth();
  }

  function firstFreeMonth() {
    const now = new Date();
    const from = state.year === now.getFullYear() ? now.getMonth() + 1 : 1;
    for (let m = from; m <= 12; m++) if (!isTakenMonth(m)) return m;
    for (let m = 1; m < from; m++) if (!isTakenMonth(m)) return m;
    return isTakenYear() ? 1 : null;
  }

  // ------------------------------------------------------------------ period

  function renderYears() {
    const select = document.getElementById("yearInput");
    const now = new Date().getFullYear();
    const years = [...new Set([now - 1, now, now + 1, now + 2, state.year])].sort((a, b) => a - b);
    select.innerHTML = years.map((y) => `<option value="${y}" ${y === state.year ? "selected" : ""}>${y}</option>`).join("");
    UI.enhanceSelect(select, { search: false });
    select.addEventListener("change", () => changePeriod(Number(select.value), state.month));
  }

  function renderPeriods() {
    const select = document.getElementById("periodInput");
    const opt = (value, label, taken) =>
      `<option value="${value}" ${taken ? "disabled" : ""} ${String(value) === String(state.month ?? "") ? "selected" : ""} data-icon="${value === "" ? "ri-calendar-2-line" : "ri-calendar-line"}" data-color="${taken ? "secondary" : value === "" ? "purple" : "primary"}">${label}${taken ? " — already has a budget" : ""}</option>`;
    select.innerHTML = [
      opt("", `Whole of ${state.year}`, isTakenYear()),
      ...B.MONTHS.map((name, i) => opt(i + 1, `${name} ${state.year}`, isTakenMonth(i + 1))),
    ].join("");
    if (!select.dataset.wired) {
      select.dataset.wired = "1";
      UI.enhanceSelect(select, { search: false });
      select.addEventListener("change", () => changePeriod(state.year, select.value === "" ? null : Number(select.value)));
    } else {
      UI.syncSelect(select);
    }
    document.getElementById("periodError").hidden = true;
    document.getElementById("summaryTitle").textContent = `${B.periodLabel(state.year, state.month)} budget`;
  }

  async function changePeriod(year, month) {
    const yearChanged = year !== state.year;
    state.year = year;
    state.month = month;
    state.dirty = true;
    // In the budget's own year its own period doesn't block it; elsewhere, ask.
    const res = await BudgetsAPI.form({ year, month: month ?? "year" });
    if (res.ok) {
      state.copy = res.data.copy;
      state.taken = state.budget && year === state.ownYear ? state.ownTaken : res.data.taken;
    }
    if (yearChanged && !state.budget) state.month = pickMonth(month);
    renderPeriods();
    renderCopy();
  }

  // ------------------------------------------------------------------ copy

  function renderCopy() {
    const box = document.getElementById("copyBox");
    const copy = state.copy;
    box.hidden = !copy || !Object.keys(copy.amounts || {}).length;
    if (box.hidden) return;
    const count = Object.keys(copy.amounts).length;
    document.getElementById("copyTitle").textContent = `Start from the ${copy.period_label} budget`;
    document.getElementById("copyText").textContent = `Fills all ${count} amounts from it. You can change any of them after.`;
  }

  function copyAmounts() {
    const before = { ...state.amounts };
    state.amounts = { ...state.copy.amounts };
    state.expanded = { in: false, out: false };
    state.dirty = true;
    renderLines("in");
    renderLines("out");
    updateTotals();
    Toast.success(`Filled from the ${state.copy.period_label} budget`, {
      action: {
        label: "Undo",
        onClick: () => {
          state.amounts = before;
          renderLines("in");
          renderLines("out");
          updateTotals();
        },
      },
    });
  }

  // ------------------------------------------------------------------ lines

  function renderLines(side) {
    const el = document.getElementById(side === "in" ? "linesIn" : "linesOut");
    const lines = state.lines[side] || [];
    if (!lines.length) {
      el.innerHTML = `<p class="fw-semibold mb-0">No ${side === "in" ? "money in" : "money out"} lines yet. Add lines in Budget Settings.</p>`;
      return;
    }
    const filled = (l) => Number(state.amounts[l.id]) > 0;
    const anyFilled = lines.some(filled);
    // Filled lines first; the rest behind "Show all" once something is filled in.
    const ordered = anyFilled ? [...lines.filter(filled), ...lines.filter((l) => !filled(l))] : lines;
    const visibleCount = anyFilled ? lines.filter(filled).length : Math.min(lines.length, EXTRA_AFTER);
    const hiddenCount = lines.length - visibleCount;

    el.innerHTML = `
      ${lines.length > EXTRA_AFTER ? `<div class="list-search mb-2"><i class="ri-search-line"></i><input type="search" class="form-control" placeholder="Find a line..." data-line-search="${side}" autocomplete="off"></div>` : ""}
      <div class="budget-lines" data-side="${side}">
        ${ordered.map((l, i) => lineRow(l, side, i >= visibleCount && !state.expanded[side])).join("")}
      </div>
      ${hiddenCount > 0 ? `<button type="button" class="btn btn-sm btn-outline-primary mt-2" data-show-all="${side}" ${state.expanded[side] ? "hidden" : ""}><i class="ri-add-line me-1"></i>Show ${hiddenCount} more ${hiddenCount === 1 ? "line" : "lines"}</button>` : ""}`;

    el.querySelectorAll("input[data-amount]").forEach((input) => {
      input.addEventListener("input", onAmount);
      input.addEventListener("blur", () => {
        const v = parseAmount(input.value);
        input.value = v === null || v === 0 ? "" : B.amount(v);
      });
      // Select the amount on focus, so typing replaces it rather than adding to it.
      input.addEventListener("focus", () => setTimeout(() => input.select(), 0));
    });
    el.querySelector(`[data-show-all="${side}"]`)?.addEventListener("click", (e) => {
      state.expanded[side] = true;
      el.querySelectorAll(".budget-line-row.is-extra").forEach((r) => r.classList.remove("is-extra"));
      e.currentTarget.hidden = true;
    });
    el.querySelector(`[data-line-search="${side}"]`)?.addEventListener("input", (e) => {
      const q = e.target.value.trim().toLowerCase();
      el.querySelectorAll(".budget-line-row").forEach((r) => {
        const match = !q || r.dataset.name.includes(q);
        r.classList.toggle("is-filtered", !match);
        if (q && match) r.classList.remove("is-extra");
      });
    });
  }

  function lineRow(line, side, extra) {
    const value = Number(state.amounts[line.id]) > 0 ? B.amount(state.amounts[line.id]) : "";
    return `
      <div class="budget-line-row${extra ? " is-extra" : ""}" data-name="${B.esc(line.name.toLowerCase())}">
        <label class="budget-line-name" for="amt-${line.id}">
          <span class="fw-semibold">${B.esc(line.name)}</span>${line.is_own ? ' <span class="soft-chip soft-primary">Ours</span>' : ""}
          ${line.description ? `<span class="d-block fs-12">${B.esc(line.description)}</span>` : ""}
        </label>
        <div class="input-group budget-amount">
          <span class="input-group-text">KES</span>
          <input type="text" inputmode="decimal" class="form-control text-end" id="amt-${line.id}" data-amount="${line.id}" data-side="${side}" value="${value}" placeholder="0.00" autocomplete="off">
        </div>
      </div>`;
  }

  function parseAmount(text) {
    const clean = String(text || "").replace(/[^0-9.]/g, "");
    if (clean === "") return null;
    const n = Number(clean);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : null;
  }

  function onAmount(e) {
    const input = e.target;
    const v = parseAmount(input.value);
    if (v === null || v === 0) delete state.amounts[input.dataset.amount];
    else state.amounts[input.dataset.amount] = v;
    state.dirty = true;
    updateTotals();
  }

  function updateTotals() {
    const sum = (side) => (state.lines[side] || []).reduce((t, l) => t + (Number(state.amounts[l.id]) || 0), 0);
    const inT = sum("in");
    const outT = sum("out");
    const left = inT - outT;
    document.getElementById("inTotal").textContent = B.money(inT);
    document.getElementById("outTotal").textContent = B.money(outT);
    document.getElementById("sumIn").textContent = B.money(inT);
    document.getElementById("sumOut").textContent = B.money(outT);
    const leftEl = document.getElementById("sumLeft");
    leftEl.textContent = B.money(left);
    leftEl.className = left < 0 ? "text-danger" : "text-success";
    const count = Object.keys(state.amounts).length;
    document.getElementById("sumHint").textContent = count
      ? `${count} ${count === 1 ? "line" : "lines"} planned.${left < 0 ? " You plan to spend more than comes in." : ""}`
      : "Type an amount next to each line you plan for. Lines left empty are left out.";
  }

  // ------------------------------------------------------------------ save

  function renderButtons() {
    const startBtn = document.getElementById("saveStartBtn");
    const draftBtn = document.getElementById("saveDraftBtn");
    if (state.budget?.status === "active") {
      startBtn.innerHTML = '<i class="ri-check-line me-1"></i>Save changes';
      draftBtn.hidden = true;
    }
    document.getElementById("cancelBtn").href = state.budget ? B.url("budget.php", { id: state.budget.id }) : B.url("budgets.php");
  }

  async function save(start) {
    const lines = Object.entries(state.amounts)
      .filter(([, v]) => Number(v) > 0)
      .map(([id, amount]) => ({ budget_line_id: Number(id), amount }));
    if (!lines.length) {
      Toast.warning("Type an amount for at least one line.");
      document.querySelector("input[data-amount]")?.focus();
      return;
    }

    const body = {
      year: state.year,
      month: state.month,
      notes: document.getElementById("notesInput").value.trim() || null,
      lines,
      start: start && state.budget?.status !== "active",
    };
    if (state.budget) body.updated_at = state.budget.updated_at;

    const btn = document.getElementById(start ? "saveStartBtn" : "saveDraftBtn");
    UI.setButtonLoading(btn, "Saving...");
    state.saving = true;
    const res = state.budget ? await BudgetsAPI.update(state.budget.id, body) : await BudgetsAPI.create(body);
    UI.restoreButton(btn);

    if (!res.ok) {
      state.saving = false;
      if (res.errors?.month) {
        const err = document.getElementById("periodError");
        err.textContent = [].concat(res.errors.month)[0];
        err.hidden = false;
        document.getElementById("periodInput").scrollIntoView({ behavior: "smooth", block: "center" });
      }
      Toast.error(res.message);
      return;
    }
    state.dirty = false;
    B.flash(res.message);
    window.location.href = B.url("budget.php", { id: res.data.budget.id });
  }

  function emptyCard(message) {
    return `<div class="card custom-card"><div class="card-body"><div class="list-empty">
      <span class="list-empty-icon bg-danger text-white"><i class="ri-error-warning-line"></i></span>
      <div class="fw-semibold mt-2">${B.esc(message)}</div>
      <a href="${B.url("budgets.php")}" class="btn btn-light btn-sm mt-3">Back to budgets</a>
    </div></div></div>`;
  }

  return { init };
})();

window.BudgetsForm = BudgetsForm;
