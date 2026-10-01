/**
 * ============================================================================
 * PAGE - NEW / CHANGE BUDGET (includes/budget/form.php, every level)
 * ============================================================================
 * Step by step, like recording Demographics, with a live preview:
 *   1 Month or year?  - a clear "A month | The whole year" choice, the year,
 *                       and why a period can't be picked when it can't;
 *                       "Copy amounts" from the last budget (Undo stays)
 *   2 Money in        - one amount box per line, "Last time" under each
 *   3 Money out       - the same
 *   4 Check and save  - every planned line against last time, notes, save
 * The whole budget is saved in one request.
 * ============================================================================
 */
const BudgetsForm = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const params = new URLSearchParams(window.location.search);
  const EXTRA_AFTER = 8; // lines shown before "Show all lines", when nothing is filled in yet
  const STEP_NAMES = ["Month or year?", "Money in", "Money out", "Check and save"];
  const LAST = STEP_NAMES.length;

  const state = {
    id: Number(params.get("id")) || null,
    budget: null, // the budget being changed
    year: Number(params.get("year")) || new Date().getFullYear(),
    month: null, // null = whole year
    kind: "month", // "month" | "year" - what the person chose, even while that period is blocked
    lines: { in: [], out: [] },
    amounts: {}, // line id -> amount
    taken: { year: null, months: {} },
    statuses: { year: null, months: {} }, // status of each taken period, for the chip colours
    ownYear: null, // the year the budget being changed had when loaded
    ownTaken: null, // that year's taken periods - without the budget itself
    copy: null,
    copied: null, // { before, label } after "Copy amounts", for the Undo that stays
    expanded: { in: false, out: false },
    step: 1,
    reached: 1,
    dirty: false,
    saving: false,
  };

  async function init() {
    const wanted = requestedMonth();
    const res = state.id ? await BudgetsAPI.formFor(state.id) : await BudgetsAPI.form({ year: state.year, month: wanted === null ? "year" : wanted });
    if (!res.ok) {
      document.getElementById("budgetForm").innerHTML = emptyCard(res.message);
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
    state.kind = state.month === null ? "year" : "month";
    await loadStatuses();

    renderKind();
    renderYears();
    renderPeriods();
    renderLines("in");
    renderLines("out");
    renderCopy();
    renderButtons();
    updateTotals();
    wireSteps();

    document.getElementById("notesInput").addEventListener("input", markDirty);
    document.getElementById("copyBtn").addEventListener("click", () => (state.copied ? undoCopy() : copyAmounts()));
    document.getElementById("saveStartBtn").addEventListener("click", () => save(true));
    document.getElementById("saveDraftBtn").addEventListener("click", () => save(false));
    window.addEventListener("beforeunload", (e) => {
      if (state.dirty && !state.saving) {
        e.preventDefault();
        e.returnValue = "";
      }
    });

    // Changing a budget: every step is open, and it starts at the amounts.
    if (state.budget) {
      state.reached = LAST;
      showStep(2);
    } else {
      showStep(1);
    }
  }

  /** ?month=3, ?month=year (whole year), or nothing. */
  function requestedMonth() {
    const m = params.get("month");
    if (m === "year") return null;
    return m ? Number(m) : undefined;
  }

  const isTakenMonth = (m) => state.taken.year !== null || state.taken.months[m] !== undefined;
  const isTakenYear = () => state.taken.year !== null || Object.keys(state.taken.months).length > 0;
  const takenMonths = () => Object.keys(state.taken.months).map(Number).sort((a, b) => a - b);

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

  /** Can the chosen period be saved? */
  const periodOk = () => (state.kind === "year" ? state.month === null && !isTakenYear() : state.month !== null && !isTakenMonth(state.month));

  function markDirty() {
    state.dirty = true;
    updateSavedLabel();
  }

  // ------------------------------------------------------------------ steps

  function wireSteps() {
    document.querySelectorAll("#intakeSteps [data-go]").forEach((btn) => btn.addEventListener("click", () => goToStep(Number(btn.dataset.go))));
    document.getElementById("nextBtn").addEventListener("click", () => goToStep(state.step + 1));
    document.getElementById("backBtn").addEventListener("click", () => showStep(Math.max(1, state.step - 1)));
  }

  /** Moves to step n. Going forward checks each step on the way. */
  function goToStep(n) {
    if (n > state.step) {
      for (let s = state.step; s < n; s++) {
        if (!validateStep(s)) {
          if (s !== state.step) showStep(s);
          return;
        }
      }
    }
    if (n > state.reached + 1) return;
    showStep(n);
  }

  function showStep(n) {
    state.step = n;
    state.reached = Math.max(state.reached, n);
    document.querySelectorAll("#budgetFormCard .intake-step").forEach((sec) => {
      sec.hidden = Number(sec.dataset.step) !== n;
    });
    if (n === LAST) renderReview();
    updateStepBar();
    updateFooter();
    const bar = document.getElementById("intakeSteps");
    const top = bar.getBoundingClientRect().top + window.scrollY - 80;
    if (window.scrollY > top) window.scrollTo({ top: Math.max(0, top), behavior: "smooth" });
  }

  function updateStepBar() {
    document.querySelectorAll("#intakeSteps [data-go]").forEach((btn) => {
      const n = Number(btn.dataset.go);
      btn.classList.toggle("is-on", n === state.step);
      btn.classList.toggle("is-done", n !== state.step && n <= state.reached && n < LAST);
      btn.disabled = n > state.reached + (n === state.step + 1 ? 1 : 0);
    });
    document.getElementById("intakeStepsMobile").textContent = `Step ${state.step} of ${LAST} · ${STEP_NAMES[state.step - 1]}`;
    document.getElementById("intakeStepsBar").style.width = `${(state.step / LAST) * 100}%`;
  }

  function updateFooter() {
    const last = state.step === LAST;
    document.getElementById("backBtn").hidden = state.step === 1;
    document.getElementById("nextBtn").hidden = last;
    document.getElementById("nextBtn").innerHTML = state.step === LAST - 1 ? 'Check and save<i class="ri-arrow-right-line ms-1"></i>' : 'Next<i class="ri-arrow-right-line ms-1"></i>';
    document.getElementById("saveStartBtn").hidden = !last;
    updateSavedLabel();
  }

  function updateSavedLabel() {
    const el = document.getElementById("intakeSaved");
    if (state.dirty) {
      el.innerHTML = '<span class="intake-saved-dot"></span>Unsaved changes';
      el.className = "intake-saved me-auto is-dirty";
    } else {
      el.textContent = state.budget ? `${B.STATUS[state.budget.status]?.label || ""} · saved` : "Not saved yet";
      el.className = "intake-saved me-auto";
    }
  }

  /** Checks one step; shows what to fix and returns false if anything is wrong. */
  function validateStep(step) {
    const problems = [];
    if (step === 1 && !periodOk()) {
      problems.push(state.kind === "year" ? whyYearBlocked() || "Pick the whole year." : "Pick a month that doesn't have a budget yet.");
    }
    const box = document.querySelector(`[data-errors-for="${step}"]`);
    if (!problems.length) {
      box.hidden = true;
      return true;
    }
    box.innerHTML = `<i class="ri-error-warning-line"></i><div><strong>${problems.length === 1 ? "One thing to fix" : `${problems.length} things to fix`}</strong><ul>${problems.map((m) => `<li>${m}</li>`).join("")}</ul></div>`;
    box.hidden = false;
    box.scrollIntoView({ behavior: "smooth", block: "center" });
    return false;
  }

  // ------------------------------------------------------------------ period

  function renderKind() {
    document.getElementById("kindSwitchWrap").innerHTML = UI.renderSegmented(
      "kindSwitch",
      [
        { value: "month", label: '<i class="ri-calendar-line me-1"></i>A month' },
        { value: "year", label: '<i class="ri-calendar-2-line me-1"></i>The whole year' },
      ],
      state.kind,
      { ariaLabel: "A month or the whole year" },
    );
    UI.wireSegmented("kindSwitch", (value) => {
      state.kind = value;
      changePeriod(state.year, value === "year" ? null : state.month ?? firstFreeMonth() ?? 1);
    });
  }

  function renderYears() {
    const now = new Date().getFullYear();
    const years = [...new Set([now - 1, now, now + 1, now + 2, state.year])].sort((a, b) => a - b);
    document.getElementById("yearSwitchWrap").innerHTML = UI.renderSegmented("yearSwitch", years.map((y) => ({ value: y, label: String(y) })), state.year, { ariaLabel: "Year" });
    UI.wireSegmented("yearSwitch", (value) => changePeriod(Number(value), state.kind === "year" ? null : state.month));
  }

  /** Why the whole year can't be picked, in plain words (or "" when it can). */
  function whyYearBlocked() {
    if (state.taken.year) return `${state.year} already has a whole-year budget.`;
    const months = takenMonths();
    if (!months.length) return "";
    const names = months.map((m) => B.MONTHS[m - 1]);
    const list = names.length > 1 ? `${names.slice(0, -1).join(", ")} and ${names[names.length - 1]}` : names[0];
    return `${state.year} already has budgets for ${list}, so it can't also have a whole-year budget. Pick another year, or plan by month.`;
  }

  /** In use / Draft / Closed for each taken period of the year, from the year's budget list. */
  async function loadStatuses() {
    const res = await BudgetsAPI.list({ year: state.year });
    state.statuses = { year: null, months: {} };
    (res.ok ? res.data || [] : []).forEach((b) => {
      if (b.period_month) state.statuses.months[b.period_month] = b.status;
      else state.statuses.year = b.status;
    });
  }

  // The Demographics period chips: a tint and a status line per state.
  const CHIP_STATES = {
    active: { cls: "is-approved", icon: "ri-checkbox-circle-fill", text: "In use" },
    draft: { cls: "is-draft", icon: "ri-draft-fill", text: "Draft" },
    closed: { cls: "is-closed", icon: "ri-lock-fill", text: "Closed" },
    free: { cls: "is-open", icon: "ri-checkbox-blank-circle-line", text: "Free" },
    blocked: { cls: "is-blocked", icon: "ri-forbid-line", text: "Can't be picked" },
  };

  function periodChip({ month, name, takenId, status, selected, now }) {
    const s = CHIP_STATES[status];
    return `
      <button type="button" class="period-chip ${s.cls}${selected ? " is-selected" : ""}${now ? " is-now" : ""}"
              ${month ? `data-month="${month}"` : 'data-year="1"'} ${takenId ? `data-open="${takenId}"` : ""} ${status === "blocked" ? "disabled" : ""}
              role="radio" aria-checked="${selected}" title="${takenId ? "Open this budget" : s.text}">
        <span class="period-chip-name">${B.esc(name)}</span>
        <span class="period-chip-status"><i class="${s.icon}"></i>${s.text}</span>
        ${takenId ? '<i class="ri-arrow-right-up-line period-chip-go" aria-hidden="true"></i>' : ""}
        ${now ? `<span class="period-chip-now">${month ? "This month" : "This year"}</span>` : ""}
      </button>`;
  }

  /** The month chips, or one card for the whole year - with the reason when it can't be picked. */
  function renderPeriods() {
    const el = document.getElementById("periodChips");
    const note = document.getElementById("periodNote");
    const today = new Date();
    document.getElementById("periodLabel").textContent = state.kind === "year" ? "The year" : "Which month?";
    note.innerHTML = "";

    if (state.kind === "year") {
      const blocked = isTakenYear();
      const openId = state.taken.year;
      el.className = "period-grid is-single";
      el.innerHTML = periodChip({
        month: null,
        name: `Whole of ${state.year}`,
        takenId: openId,
        status: openId ? state.statuses.year || "draft" : blocked ? "blocked" : "free",
        selected: !blocked,
        now: state.year === today.getFullYear(),
      });
      if (blocked && !openId) {
        note.innerHTML = `
          <div class="budget-period-note">
            <i class="ri-information-line"></i>
            <span>${B.esc(whyYearBlocked())}</span>
            <button type="button" class="btn btn-sm btn-outline-primary ms-auto flex-shrink-0" data-plan-months>Plan by month</button>
          </div>`;
        note.querySelector("[data-plan-months]").addEventListener("click", () => {
          state.kind = "month";
          renderKind();
          changePeriod(state.year, firstFreeMonth() ?? 1);
        });
      }
    } else {
      el.className = "period-grid";
      el.innerHTML = B.MONTHS.map((name, i) => {
        const month = i + 1;
        const takenId = state.taken.year ?? state.taken.months[month];
        const taken = isTakenMonth(month);
        return periodChip({
          month,
          name,
          takenId: taken ? takenId : null,
          status: taken ? (state.taken.year ? state.statuses.year : state.statuses.months[month]) || "draft" : "free",
          selected: !taken && month === state.month,
          now: state.year === today.getFullYear() && month === today.getMonth() + 1,
        });
      }).join("");
      if (state.taken.year) {
        note.innerHTML = `
          <div class="budget-period-note">
            <i class="ri-information-line"></i>
            <span>${state.year} has a whole-year budget, so it can't also have month budgets.</span>
            <a class="btn btn-sm btn-outline-primary ms-auto flex-shrink-0" href="${B.url("budget.php", { id: state.taken.year })}">Open it</a>
          </div>`;
      }
    }

    el.querySelectorAll(".period-chip").forEach((btn) =>
      btn.addEventListener("click", () => {
        if (btn.dataset.open) {
          window.location.href = B.url("budget.php", { id: btn.dataset.open });
          return;
        }
        if (btn.dataset.month) changePeriod(state.year, Number(btn.dataset.month));
      }),
    );
    document.getElementById("periodError").hidden = true;
    document.querySelector('[data-errors-for="1"]').hidden = true;
    renderPreviewTitle();
  }

  async function changePeriod(year, month) {
    const yearChanged = year !== state.year;
    state.year = year;
    state.month = month;
    markDirty();
    let res = await BudgetsAPI.form({ year, month: month ?? "year" });
    // In the budget's own year its own period doesn't block it; elsewhere, ask.
    if (res.ok) state.taken = state.budget && year === state.ownYear ? state.ownTaken : res.data.taken;
    // A new year: keep to months, but move off a month that's taken there.
    if (yearChanged && !state.budget && month !== null) {
      const picked = pickMonth(month);
      if (picked !== month && picked !== null) {
        state.month = picked;
        res = await BudgetsAPI.form({ year, month: picked });
      }
    }
    if (res.ok) state.copy = res.data.copy;
    if (yearChanged) await loadStatuses();
    renderPeriods();
    renderCopy();
    // "Last time" amounts follow the chosen period
    renderLines("in");
    renderLines("out");
    updateTotals();
  }

  // ------------------------------------------------------------------ copy

  /** The "start from the last budget" banner - and, once copied, "Filled in · Undo" until Undo is pressed. */
  function renderCopy() {
    const box = document.getElementById("copyBox");
    const btn = document.getElementById("copyBtn");
    const copy = state.copy;
    box.hidden = !state.copied && (!copy || !Object.keys(copy.amounts || {}).length);
    if (box.hidden) return;
    box.classList.toggle("is-done", !!state.copied);
    const icon = document.getElementById("copyIcon");
    if (state.copied) {
      icon.innerHTML = '<i class="ri-check-line"></i>';
      document.getElementById("copyTitle").innerHTML = `Filled from the <b>${B.esc(state.copied.label)}</b> budget`;
      document.getElementById("copyText").textContent = "Change any amount in the next steps, or undo to start empty again.";
      btn.className = "btn btn-sm btn-outline-primary";
      btn.innerHTML = '<i class="ri-arrow-go-back-line me-1"></i>Undo';
      return;
    }
    const count = Object.keys(copy.amounts).length;
    const total = sum("in", copy.amounts) - sum("out", copy.amounts);
    icon.innerHTML = '<i class="ri-history-line"></i>';
    document.getElementById("copyTitle").innerHTML = `Last budget: <b>${B.esc(copy.period_label)}</b> · ${count} lines · ${B.shortMoney(total)} left`;
    document.getElementById("copyText").textContent = "Fills every amount from it. You can change any of them after.";
    btn.className = "btn btn-sm btn-primary";
    btn.innerHTML = '<i class="ri-magic-line me-1"></i>Start from these amounts';
  }

  function copyAmounts() {
    state.copied = { before: { ...state.amounts }, label: state.copy.period_label };
    state.amounts = { ...state.copy.amounts };
    state.expanded = { in: false, out: false };
    markDirty();
    refreshAmounts();
    Toast.success(`Filled from the ${state.copy.period_label} budget`, { action: { label: "Undo", onClick: undoCopy } });
  }

  function undoCopy() {
    if (!state.copied) return;
    state.amounts = state.copied.before;
    state.copied = null;
    refreshAmounts();
  }

  function refreshAmounts() {
    renderCopy();
    renderLines("in");
    renderLines("out");
    updateTotals();
    if (state.step === LAST) renderReview();
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
      <div class="budget-tile-grid" data-side="${side}">
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
      el.querySelectorAll(".budget-tile.is-extra").forEach((r) => r.classList.remove("is-extra"));
      e.currentTarget.hidden = true;
    });
    el.querySelector(`[data-line-search="${side}"]`)?.addEventListener("input", (e) => {
      const q = e.target.value.trim().toLowerCase();
      el.querySelectorAll(".budget-tile").forEach((r) => {
        const match = !q || r.dataset.name.includes(q);
        r.classList.toggle("is-filtered", !match);
        if (q && match) r.classList.remove("is-extra");
      });
    });
  }

  /** A line's colour stays the same wherever it shows: its place in the side's list. */
  const colorOf = (line, side) => B.lineColor(side, Math.max(0, (state.lines[side] || []).findIndex((l) => l.id === line.id)));

  /** One line as a Demographics-style number tile: coloured icon, name, KES box, "Last time". */
  function lineRow(line, side, extra) {
    const amount = Number(state.amounts[line.id]) || 0;
    const value = amount > 0 ? B.amount(amount) : "";
    const last = Number(state.copy?.amounts?.[line.id]) || 0;
    const color = colorOf(line, side);
    return `
      <div class="num-tile budget-tile${amount > 0 ? " is-filled" : ""}${extra ? " is-extra" : ""}" data-name="${B.esc(line.name.toLowerCase())}" style="--tile-rgb: var(--${color}-rgb)">
        <label class="num-tile-label" for="amt-${line.id}">
          <span class="num-tile-icon bg-${color} ${B.tileText(color)}"><i class="${B.lineIcon(line.name, side)}"></i></span>
          <span class="budget-tile-name"><span>${B.esc(line.name)}</span>${line.is_own ? ' <span class="soft-chip soft-primary">Ours</span>' : ""}${line.description ? `<small>${B.esc(line.description)}</small>` : ""}</span>
        </label>
        <div class="input-group">
          <span class="input-group-text">KES</span>
          <input type="text" inputmode="decimal" class="form-control text-end" id="amt-${line.id}" data-amount="${line.id}" data-side="${side}" value="${value}" placeholder="0.00" autocomplete="off">
        </div>
        <div class="num-tile-foot"><span class="num-tile-last">${last ? `Last time <b>${B.money(last)}</b>` : "New this time"}</span></div>
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
    input.closest(".budget-tile")?.classList.toggle("is-filled", !!v);
    markDirty();
    updateTotals();
  }

  // ------------------------------------------------------------------ preview

  function renderPreviewTitle() {
    const ok = periodOk();
    document.getElementById("summaryTitle").textContent = ok || state.budget ? `${B.periodLabel(state.year, state.month)} budget` : "Pick a month or year";
    document.getElementById("previewStatus").innerHTML = state.budget ? B.statusPill(state.budget.status) : ok ? '<span class="soft-chip soft-primary">New</span>' : "";
  }

  const sum = (side, amounts = state.amounts) => (state.lines[side] || []).reduce((t, l) => t + (Number(amounts?.[l.id]) || 0), 0);

  function updateTotals() {
    const inT = sum("in");
    const outT = sum("out");
    const left = inT - outT;
    document.getElementById("inTotal").textContent = B.money(inT);
    document.getElementById("outTotal").textContent = B.money(outT);
    const tile = (id, value) => {
      const el = document.getElementById(id);
      el.textContent = B.shortMoney(value);
      el.title = B.money(value);
      el.closest(".preview-change").classList.toggle("is-empty", !value);
    };
    tile("sumIn", inT);
    tile("sumOut", outT);
    tile("sumLeft", left);
    document.getElementById("sumLeft").classList.toggle("text-danger", left < 0);
    const both = inT + outT;
    const bar = document.getElementById("sumBar").children;
    bar[0].style.width = `${both ? (inT / both) * 100 : 50}%`;
    bar[1].style.width = `${both ? (outT / both) * 100 : 50}%`;

    // How many lines are filled in
    const all = (state.lines.in || []).length + (state.lines.out || []).length;
    const count = Object.keys(state.amounts).length;
    document.getElementById("previewFilled").textContent = `${count} of ${all} lines filled`;
    document.getElementById("previewFilledBar").style.width = `${all ? (count / all) * 100 : 0}%`;

    // Compared with the last budget
    const copy = state.copy;
    const chip = (label, cur, prev, goodWhenUp) => {
      const d = B.delta(cur, prev, copy.period_label);
      if (!d) return "";
      const tone = d.dir === "flat" ? "primary" : (d.dir === "up") === goodWhenUp ? "success" : "danger";
      const arrow = d.dir === "up" ? "ri-arrow-up-line" : d.dir === "down" ? "ri-arrow-down-line" : "ri-arrow-right-line";
      return `<span class="soft-chip soft-${tone}"><i class="${arrow} me-1"></i>${label}: ${d.dir === "flat" ? "no change" : d.text}</span>`;
    };
    document.getElementById("sumCompare").innerHTML =
      copy && (inT || outT) ? `${chip("In", inT, sum("in", copy.amounts), true)}${chip("Out", outT, sum("out", copy.amounts), false)}<span class="fs-12 align-self-center ms-1">vs ${B.esc(copy.period_label)}</span>` : "";

    renderPreviewList("in", inT);
    renderPreviewList("out", outT);

    document.getElementById("sumHint").textContent = count
      ? `${count} ${count === 1 ? "line" : "lines"} planned.${left < 0 ? " You plan to spend more than comes in." : ""}`
      : "Type an amount next to each line you plan for. Lines left empty are left out.";
    renderPreviewTitle();
  }

  /** Each line with its own coloured dot - the biggest five, then "+ n more". "–" until filled. */
  function renderPreviewList(side, total) {
    const rows = (state.lines[side] || [])
      .map((l, i) => ({ name: l.name, value: Number(state.amounts[l.id]) || 0, color: B.lineColor(side, i), i }))
      .sort((a, b) => b.value - a.value || a.i - b.i);
    const shown = rows.slice(0, 5);
    const more = rows.length - shown.length;
    document.getElementById(side === "in" ? "previewIn" : "previewOut").innerHTML = rows.length
      ? `<ul class="composition-list budget-preview-list">${shown
          .map(
            (r) => `<li class="${r.value ? "" : "is-empty"}"><span class="composition-name"><span class="count-dot bg-${r.color}"></span><span class="text-truncate">${B.esc(r.name)}</span></span><span class="composition-value">${r.value ? B.shortMoney(r.value) : "–"} <span>${total ? Math.round((r.value / total) * 100) : 0}%</span></span></li>`,
          )
          .join("")}</ul>${more > 0 ? `<div class="fs-12 mt-1">+ ${more} more ${more === 1 ? "line" : "lines"}</div>` : ""}`
      : '<div class="fs-12">No lines yet.</div>';
  }

  // ------------------------------------------------------------------ check and save

  /** Every planned line, grouped in and out, with its change against last time. */
  function renderReview() {
    const el = document.getElementById("reviewBody");
    const lastAmounts = state.copy?.amounts || {};
    const lastShort = state.copy ? state.copy.period_label.split(" ")[0].slice(0, 3) : "";
    const group = (side) => {
      const isIn = side === "in";
      const rows = (state.lines[side] || []).filter((l) => Number(state.amounts[l.id]) > 0);
      const total = sum(side);
      const change = (l) => {
        if (!state.copy) return "";
        const prev = Number(lastAmounts[l.id]) || 0;
        const cur = Number(state.amounts[l.id]) || 0;
        if (!prev) return '<span class="soft-chip soft-purple">New</span>';
        if (Math.abs(cur - prev) < 0.005) return "";
        const pct = Math.round(((cur - prev) / prev) * 100);
        return `<span class="soft-chip soft-${pct > 0 ? "warning" : "primary"}">${pct > 0 ? "+" : ""}${pct}% vs ${lastShort}</span>`;
      };
      return `
        <div class="budget-review">
          <div class="budget-review-head soft-${isIn ? "success" : "danger"}">
            <span><i class="${isIn ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line"} me-1"></i>${isIn ? "Money in" : "Money out (spending)"} · ${rows.length} ${rows.length === 1 ? "line" : "lines"}</span>
            <span class="d-flex align-items-center gap-2"><b>${B.money(total)}</b><button type="button" class="btn btn-sm btn-light" data-edit-step="${isIn ? 2 : 3}"><i class="ri-edit-line me-1"></i>Change</button></span>
          </div>
          ${
            rows.length
              ? `<ul class="budget-review-list">${rows
                  .map(
                    (l) => `<li><span class="budget-review-name"><span class="budget-review-icon bg-${colorOf(l, side)} ${B.tileText(colorOf(l, side))}"><i class="${B.lineIcon(l.name, side)}"></i></span>${B.esc(l.name)} ${change(l)}</span><b>${B.money(state.amounts[l.id])}</b></li>`,
                  )
                  .join("")}</ul>`
              : `<div class="budget-review-empty">No money ${side} planned.</div>`
          }
        </div>`;
    };
    const inT = sum("in");
    const outT = sum("out");
    const none = !Object.keys(state.amounts).length;
    const planned = Object.keys(state.amounts).length;
    document.getElementById("reviewChip").textContent = `${planned} ${planned === 1 ? "line" : "lines"}`;
    el.innerHTML = `
      ${none ? '<div class="budget-period-note mb-3"><i class="ri-error-warning-line"></i><span>Nothing is planned yet. Go back and type an amount for at least one line.</span></div>' : ""}
      <div class="budget-review-sum">
        <div><small>Period</small><b>${B.esc(B.periodLabel(state.year, state.month))}</b></div>
        <div><small>Money in</small><b class="text-success">${B.money(inT)}</b></div>
        <div><small>Money out</small><b class="text-danger">${B.money(outT)}</b></div>
        <div><small>Money left</small><b class="${inT - outT < 0 ? "text-danger" : "text-success"}">${B.money(inT - outT)}</b></div>
      </div>
      ${group("in")}
      ${group("out")}`;
    el.querySelectorAll("[data-edit-step]").forEach((btn) => btn.addEventListener("click", () => showStep(Number(btn.dataset.editStep))));
  }

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
    if (!periodOk()) {
      showStep(1);
      validateStep(1);
      return;
    }
    const lines = Object.entries(state.amounts)
      .filter(([, v]) => Number(v) > 0)
      .map(([id, amount]) => ({ budget_line_id: Number(id), amount }));
    if (!lines.length) {
      Toast.warning("Type an amount for at least one line.");
      showStep(2);
      document.querySelector("#linesIn input[data-amount]")?.focus();
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
        showStep(1);
        const err = document.getElementById("periodError");
        err.textContent = [].concat(res.errors.month)[0];
        err.hidden = false;
        document.getElementById("periodChips").scrollIntoView({ behavior: "smooth", block: "center" });
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
