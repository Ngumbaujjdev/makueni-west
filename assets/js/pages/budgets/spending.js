/**
 * ============================================================================
 * PAGE - INCOME & EXPENSES (includes/budget/spending.php, every level)
 * ============================================================================
 * Every amount received and spent in a month or a year: the period's
 * figures, a filterable list (income or expense, line, how it was paid,
 * search), Record money, and change / delete with Undo - all in place.
 * The Income and Expenses menu pages open it filtered; an Income | Expenses
 * | Both switch changes it in place (?dir=in|out|all).
 * ?territory_id= shows a place below, read-only.
 * ============================================================================
 */
const BudgetsSpending = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const territoryId = new URLSearchParams(window.location.search).get("territory_id") || "";
  const METHODS = { cash: "Cash", mpesa: "M-Pesa", bank: "Bank", cheque: "Cheque" };
  let period = null;
  let entries = [];
  let canRecord = false;
  let budget = null; // the budget money is recorded against (the month's, or the whole year's)
  let dir = ["in", "out", "all"].includes(new URLSearchParams(window.location.search).get("dir")) ? new URLSearchParams(window.location.search).get("dir") : window.SPENDING_DIR || "all";
  let lastBody = null;
  const allEntries = () => entries; // every entry of the period; the switch narrows what is shown
  let lastDash = null;

  function init() {
    B.showFlash();
    period = B.periodControls({ defaultMonth: true, onChange: (p) => ((period = p), load()) });
    document.getElementById("dirSwitchWrap").innerHTML = UI.renderSegmented(
      "dirSwitch",
      [
        { value: "in", label: '<i class="ri-arrow-down-circle-line me-1"></i>Income' },
        { value: "out", label: '<i class="ri-arrow-up-circle-line me-1"></i>Expenses' },
        { value: "all", label: "Both" },
      ],
      dir,
      { ariaLabel: "Income, expenses or both" },
    );
    UI.wireSegmented("dirSwitch", (v) => {
      dir = v;
      const q = new URLSearchParams(window.location.search);
      q.set("dir", dir);
      history.replaceState(null, "", `${window.location.pathname}?${q}`);
      if (lastBody) {
        renderHeader(lastBody, lastDash);
        renderStats(lastBody.stats, lastDash);
        renderRows();
      }
    });
    // The main button records on the side being looked at.
    document.getElementById("recordOutBtn").addEventListener("click", () => record(dir === "in" ? "in" : "out"));
    document.querySelectorAll("[data-record]").forEach((a) => a.addEventListener("click", () => record(a.dataset.record)));
    load();
  }

  async function load() {
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards(4);
    document.getElementById("entriesTableBody").innerHTML = UI.renderTableLoading(7, "Loading income and expenses...");
    const params = { year: period.year, month: period.month ?? "", territory_id: territoryId };
    const [list, dash] = await Promise.all([BudgetsAPI.entries(params), BudgetsAPI.dashboard(params)]);
    if (!list.ok) {
      document.getElementById("statCardsRow").innerHTML = "";
      document.getElementById("entriesTableBody").innerHTML = UI.renderTableEmpty(7, list.message, "ri-lock-line");
      return;
    }
    entries = list.data || [];
    canRecord = !!list.body.can_record;
    budget = dash.ok ? dash.data.budget : null;
    B.syncExport({ key: "budget.spending", territoryId: list.body.place?.id, year: period.year, month: period.month });
    lastBody = list.body;
    lastDash = dash.ok ? dash.data : null;
    renderHeader(list.body, dash.ok ? dash.data : null);
    renderStats(list.body.stats, dash.ok ? dash.data : null);
    renderRows();
  }

  function label() {
    return B.periodLabel(period.year, period.month).replace("Whole of ", "");
  }

  function renderHeader(body, dash) {
    const name = body.place?.name || "";
    const title = { in: "Income", out: "Expenses", all: "Income & Expenses" }[dir];
    document.getElementById("placeLine").textContent = `${title} · ${name} · ${label()}`;
    document.getElementById("listTitle").textContent = `${title}, ${label()}`;
    document.getElementById("listSub").textContent = { in: "Every amount received - tap one to see it", out: "Every amount paid out, taken off its budget line - tap one to see it", all: "Every amount received and spent - tap one to see it" }[dir];
    document.getElementById("recordOutBtn").innerHTML = `<i class="ri-add-line me-1"></i>${dir === "in" ? "Record income" : dir === "out" ? "Record an expense" : "Record money"}`;
    const banner = document.getElementById("viewOnlyBanner");
    banner.classList.toggle("d-none", !body.view_only);
    banner.classList.toggle("d-flex", !!body.view_only);
    if (body.view_only) document.getElementById("viewOnlyText").textContent = `This is ${name}'s money. Only ${name} can record or change it.`;

    const ready = canRecord && budget?.status === "active";
    document.getElementById("recordGroup").classList.toggle("d-none", !ready);
    const hint = document.getElementById("recordHint");
    hint.innerHTML = "";
    if (canRecord && !body.view_only && !ready) {
      const why = !dash?.budgets?.length
        ? `There's no budget for ${label()} yet - <a href="${B.url("form.php", { year: period.year, month: period.month ?? "year" })}" class="fw-semibold">prepare one</a> to record money against it.`
        : budget?.status === "draft"
          ? `The ${B.esc(budget.period_label)} budget is still a draft - <a href="${B.url("budget.php", { id: budget.id })}" class="fw-semibold">start using it</a> to record money.`
          : budget?.status === "closed"
            ? `The ${B.esc(budget.period_label)} budget is closed - reopen it to record money.`
            : "Pick a month to record money against its budget.";
      hint.innerHTML = `<div class="alert alert-warning d-flex align-items-center gap-3"><span class="avatar avatar-sm bg-warning text-dark flex-shrink-0"><i class="ri-information-line"></i></span><div>${why}</div></div>`;
    }
  }

  function renderStats(s, dash) {
    const t = dash?.totals;
    const left = s.in - s.out;
    // One side: its total, against plan, its biggest line, and how many entries.
    if (dir !== "all") {
      const isIn = dir === "in";
      const rows = entries.filter((e) => e.direction === dir);
      const total = isIn ? s.in : s.out;
      const planned = isIn ? t?.in_planned : t?.out_planned;
      const byLine = {};
      rows.forEach((e) => (byLine[e.line] = (byLine[e.line] || 0) + e.amount));
      const top = Object.entries(byLine).sort((a, b) => b[1] - a[1])[0];
      UI.renderStatCardsRow("statCardsRow", [
        { icon: isIn ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line", label: isIn ? "Income (received)" : "Expenses (spent)", value: B.shortMoney(total), color: isIn ? "success" : "danger", sub: B.money(total) },
        { icon: "ri-pie-chart-2-line", label: "Against plan", value: planned ? `${Math.round((total / planned) * 100)}%` : "-", color: "primary", sub: planned ? `of ${B.shortMoney(planned)} planned` : "Nothing planned" },
        { icon: "ri-trophy-line", label: isIn ? "Biggest source" : "Biggest expense", value: top ? B.shortMoney(top[1]) : "-", color: "purple", sub: top ? top[0] : `No ${isIn ? "income" : "expenses"} yet` },
        { icon: "ri-file-list-3-line", label: "Entries", value: rows.length, color: "warning", sub: rows.filter((e) => e.receipts).length ? `${rows.filter((e) => e.receipts).length} with a receipt` : "No receipts attached" },
      ]);
      return;
    }
    UI.renderStatCardsRow("statCardsRow", [
      { icon: "ri-arrow-down-circle-line", label: "Income (received)", value: B.shortMoney(s.in), color: "success", sub: t?.in_planned ? `${B.money(s.in)} · of ${B.shortMoney(t.in_planned)} planned` : B.money(s.in) },
      { icon: "ri-arrow-up-circle-line", label: "Expenses (spent)", value: B.shortMoney(s.out), color: "danger", sub: t?.out_planned ? `${B.money(s.out)} · of ${B.shortMoney(t.out_planned)} planned` : B.money(s.out) },
      { icon: "ri-scales-3-line", label: "Money left", value: B.shortMoney(left), color: left < 0 ? "danger" : "purple", sub: left < 0 ? "More has gone out than came in" : "Income minus expenses" },
      { icon: "ri-file-list-3-line", label: "Entries", value: s.count, color: "primary", sub: s.biggest_line ? `Most spent on ${s.biggest_line} (${B.shortMoney(s.biggest_amount)})` : "Nothing spent yet" },
    ]);
  }

  function renderRows() {
    const tbody = document.getElementById("entriesTableBody");
    const entries = dir === "all" ? allEntries() : allEntries().filter((e) => e.direction === dir);
    if (!entries.length) {
      tbody.innerHTML = `
        <tr><td colspan="7">
          <div class="list-empty">
            <span class="list-empty-icon bg-primary text-white"><i class="ri-exchange-dollar-line"></i></span>
            <div class="fw-semibold mt-2">No ${dir === "in" ? "income" : dir === "out" ? "expenses" : "income or expenses"} recorded for ${B.esc(label())}</div>
            <div class="fs-12">${dir === "in" ? "Money received" : dir === "out" ? "Money paid out" : "Money received and spent"} shows here once it's recorded.</div>
          </div>
        </td></tr>`;
      UI.initListDataTable("entriesTable", {});
      document.getElementById("filterToolbar").innerHTML = "";
      return;
    }
    tbody.innerHTML = entries
      .map((e) => {
        const isIn = e.direction === "in";
        const date = new Date(`${e.entry_date}T00:00:00`);
        return `
          <tr data-row-id="${e.id}">
            <td data-order="${e.entry_date}">
              <span class="budget-recent-date is-${isIn ? "in" : "out"}"><b>${date.getDate()}</b><small>${date.toLocaleDateString("en-GB", { month: "short" })}</small></span>
            </td>
            <td data-search="${B.esc(`${e.description} ${e.counterparty || ""} ${e.reference || ""}`)}">
              <a href="${B.url("entry.php", { id: e.id })}" class="fw-semibold text-reset">${B.esc(e.description)}</a>${e.receipts ? ` <i class="ri-attachment-2 text-primary" title="${e.receipts} ${e.receipts === 1 ? "receipt" : "receipts"}" aria-label="Has a receipt"></i>` : ""}
              ${e.counterparty ? `<div class="fs-12">${isIn ? "From" : "To"} ${B.esc(e.counterparty)}</div>` : ""}
            </td>
            <td data-search="${B.esc(`${e.line || ""} · ${isIn ? "Income" : "Expense"}`)}">
              <div>${e.line_id ? `<a href="${B.url("line.php", { budget: e.budget_id, line: e.line_id })}" class="text-reset">${B.lineDot(e.line)}</a>` : B.lineDot(e.line)}</div>
              <span class="soft-chip soft-${isIn ? "success" : "danger"}" data-dir="${isIn ? "Income" : "Expense"}">${isIn ? "Income" : "Expense"}</span>
            </td>
            <td class="d-none d-md-table-cell" data-search="${METHODS[e.method] || ""}">
              ${B.methodChip(e.method)}${e.reference ? `<div class="fs-12 mt-1">${B.esc(e.reference)}</div>` : ""}
            </td>
            <td class="text-end fw-bold ${isIn ? "text-success" : "text-danger"}" data-order="${isIn ? e.amount : -e.amount}">${isIn ? "+" : "−"}${B.amount(e.amount)}</td>
            <td class="d-none d-lg-table-cell" data-search="${B.esc(e.recorded_by || "-")}">${B.esc(e.recorded_by || "-")}</td>
            <td class="text-end">
              ${canRecord ? `
              <div class="d-inline-flex gap-1">
                <button type="button" class="btn btn-sm btn-primary-light" data-edit="${e.id}" title="Change" aria-label="Change"><i class="ri-edit-line"></i></button>
                <button type="button" class="btn btn-sm btn-danger-light" data-delete="${e.id}" title="Delete" aria-label="Delete"><i class="ri-delete-bin-line"></i></button>
              </div>` : ""}
            </td>
          </tr>`;
      })
      .join("");

    const lines = [...new Set(entries.map((e) => e.line).filter(Boolean))].sort();
    const people = [...new Set(entries.map((e) => e.recorded_by).filter(Boolean))].sort();
    UI.renderFilterToolbar("filterToolbar", {
      searchPlaceholder: "Search what for, paid to, reference...",
      filters: [
        { id: "lineFilter", label: "All lines", options: lines.map((l) => ({ value: l, label: l })) },
        { id: "methodFilter", label: "Any way", options: Object.values(METHODS).map((m) => ({ value: m, label: m })) },
        { id: "byFilter", label: "Anyone", options: people.map((p) => ({ value: p, label: p })) },
      ],
    });
    const table = UI.initListDataTable("entriesTable", { order: [[0, "desc"]], nonSortableColumns: [6], hideDefaultSearch: true, noun: "entries", pageLength: 25 });
    UI.wireFilterToolbar("filterToolbar", table, [
      { id: "lineFilter", columnIndex: 2 },
      { id: "methodFilter", columnIndex: 3 },
      { id: "byFilter", columnIndex: 5, exact: true },
    ], { noun: "entries" });

    tbody.onclick = (ev) => {
      const edit = ev.target.closest("[data-edit]");
      const del = ev.target.closest("[data-delete]");
      if (edit) change(Number(edit.dataset.edit));
      if (del) remove(Number(del.dataset.delete));
    };
  }

  function record(direction) {
    if (!budget) return;
    BudgetsEntryModal.open({ budgetId: budget.id, direction, onSaved: () => load() });
  }

  function change(id) {
    const entry = entries.find((e) => e.id === id);
    if (entry) BudgetsEntryModal.open({ budgetId: entry.budget_id, entry, onSaved: () => load() });
  }

  function remove(id) {
    const entry = entries.find((e) => e.id === id);
    if (!entry) return;
    Toast.confirm(
      `Delete ${B.money(entry.amount)} - ${entry.description}?`,
      async () => {
        const res = await BudgetsAPI.removeEntry(id);
        if (!res.ok) {
          Toast.error(res.message);
          return;
        }
        await load();
        Toast.success("Entry deleted", {
          action: {
            label: "Undo",
            onClick: async () => {
              const back = await BudgetsAPI.restoreEntry(id);
              back.ok ? Toast.success("Entry brought back") : Toast.error(back.message);
              await load();
              UI.flashRow(id);
            },
          },
        });
      },
      null,
      { title: "Delete entry", confirmText: "Delete", type: "error" },
    );
  }

  return { init };
})();

window.BudgetsSpending = BudgetsSpending;
