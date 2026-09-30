/**
 * ============================================================================
 * PAGE - BUDGETS LIST (includes/budget/budgets.php, every level)
 * ============================================================================
 * A place's budgets for a year: the year's figures, then one row per budget
 * (a month or the whole year). ?territory_id= shows a place below, read-only.
 * The year picker reloads in place and is kept in the URL.
 * ============================================================================
 */
const BudgetsList = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const params = new URLSearchParams(window.location.search);
  const state = {
    year: Number(params.get("year")) || new Date().getFullYear(),
    territoryId: params.get("territory_id") || "",
    viewOnly: false,
  };

  async function init() {
    B.showFlash();
    document.getElementById("budgetsTableBody").innerHTML = UI.renderTableLoading(7, "Loading budgets...");
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards ? UI.skeletonCards(4) : "";
    await load();
  }

  async function load() {
    const res = await BudgetsAPI.list({ year: state.year, territory_id: state.territoryId });
    if (!res.ok) {
      document.getElementById("budgetsTableBody").innerHTML = UI.renderTableEmpty(7, res.message, "ri-lock-line");
      document.getElementById("statCardsRow").innerHTML = "";
      return;
    }
    const body = res.body;
    state.viewOnly = !!body.view_only;
    renderPlace(body.place);
    renderYears(body.years);
    renderStats(body.stats);
    renderRows(res.data || []);
  }

  function renderPlace(place) {
    const name = place?.name || "";
    document.getElementById("placeLine").textContent = name ? `Budgets for ${name}` : "Budgets";
    document.getElementById("listTitle").textContent = `Budgets for ${state.year}`;
    const banner = document.getElementById("viewOnlyBanner");
    banner.classList.toggle("d-none", !state.viewOnly);
    banner.classList.toggle("d-flex", state.viewOnly);
    if (state.viewOnly) document.getElementById("viewOnlyText").textContent = `These are ${name}'s budgets. Only ${name} can change them.`;
    const newBtn = document.getElementById("newBudgetBtn");
    if (newBtn) newBtn.classList.toggle("d-none", state.viewOnly);
  }

  function renderYears(years) {
    const select = document.getElementById("yearSelect");
    const list = [...new Set([...(years || []), state.year])].sort((a, b) => b - a);
    select.innerHTML = list.map((y) => `<option value="${y}" ${y === state.year ? "selected" : ""}>${y}</option>`).join("");
    if (!select.dataset.wired) {
      select.dataset.wired = "1";
      UI.enhanceSelect(select, { search: false });
      select.addEventListener("change", () => {
        state.year = Number(select.value);
        const p = new URLSearchParams(window.location.search);
        p.set("year", state.year);
        history.replaceState(null, "", `${window.location.pathname}?${p}`);
        document.getElementById("budgetsTableBody").innerHTML = UI.renderTableLoading(7, "Loading budgets...");
        load();
      });
    } else {
      UI.syncSelect(select);
    }
  }

  function renderStats(s) {
    const left = s.in_planned - s.out_planned;
    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-wallet-3-line",
        label: `Budgets in ${s.year}`,
        value: s.budgets,
        color: "primary",
        sub: s.budgets ? `${s.in_use} in use · ${s.drafts} ${s.drafts === 1 ? "draft" : "drafts"}` : "None yet",
      },
      { icon: "ri-arrow-down-circle-line", label: "Money in (planned)", value: B.money(s.in_planned), color: "success", sub: `Received so far ${B.money(s.in_actual)}` },
      { icon: "ri-arrow-up-circle-line", label: "Money out (planned)", value: B.money(s.out_planned), color: "danger", sub: `Spent so far ${B.money(s.out_actual)}` },
      {
        icon: "ri-scales-3-line",
        label: "Money left (planned)",
        value: B.money(left),
        color: left < 0 ? "danger" : "purple",
        sub: left < 0 ? "Planning to spend more than comes in" : "Money in minus money out",
      },
    ]);
  }

  function renderRows(budgets) {
    const tbody = document.getElementById("budgetsTableBody");
    if (!budgets.length) {
      const canPrepare = B.CTX.can?.prepare && !state.viewOnly;
      tbody.innerHTML = `
        <tr><td colspan="7">
          <div class="list-empty">
            <span class="list-empty-icon bg-primary text-white"><i class="ri-wallet-3-line"></i></span>
            <div class="fw-semibold mt-2">No budgets for ${state.year} yet</div>
            <div class="fs-12">${canPrepare ? "Plan a month or the whole year - it takes a few minutes." : "Nothing has been prepared for this year."}</div>
            ${canPrepare ? `<a href="${B.url("form.php", { year: state.year })}" class="btn btn-primary btn-sm mt-3"><i class="ri-add-line me-1"></i>Prepare a budget</a>` : ""}
          </div>
        </td></tr>`;
      return;
    }

    tbody.innerHTML = budgets
      .map((b) => {
        const link = B.url("budget.php", { id: b.id });
        const left = b.left_planned;
        const sortKey = b.fiscal_year * 100 + (b.period_month ?? 0);
        return `
          <tr data-row-id="${b.id}">
            <td data-order="${sortKey}" data-search="${B.esc(b.period_label)}">
              <a href="${link}" class="d-flex align-items-center gap-2 text-reset">
                ${UI.avatarTile(b.period_month ? "ri-calendar-line" : "ri-calendar-2-line", b.period_month ? "primary" : "purple")}
                <div>
                  <div class="fw-semibold">${B.esc(b.period_label)}</div>
                  <div class="fs-12">${b.period_month ? "Month budget" : "Whole-year budget"}</div>
                </div>
              </a>
            </td>
            <td data-search="${B.STATUS[b.status]?.label || b.status}">${B.statusPill(b.status)}</td>
            <td class="text-end" data-order="${b.in_planned}">
              <div class="fw-semibold">${B.amount(b.in_planned)}</div>
              ${b.in_actual ? `<div class="fs-12">${B.amount(b.in_actual)} received</div>` : ""}
            </td>
            <td class="text-end" data-order="${b.out_planned}">
              <div class="fw-semibold">${B.amount(b.out_planned)}</div>
              ${b.out_actual ? `<div class="fs-12">${B.amount(b.out_actual)} spent</div>` : ""}
            </td>
            <td class="text-end fw-semibold ${left < 0 ? "text-danger" : "text-success"}" data-order="${left}">${B.amount(left)}</td>
            <td class="d-none d-lg-table-cell">${B.esc(b.prepared_by || "-")}</td>
            <td class="text-end"><a href="${link}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
          </tr>`;
      })
      .join("");

    UI.renderFilterToolbar("filterToolbar", {
      searchPlaceholder: "Search budgets...",
      filters: [{ id: "statusFilter", label: "All statuses", options: Object.values(B.STATUS).map((s) => ({ value: s.label, label: s.label })) }],
    });
    const table = UI.initListDataTable("budgetsTable", {
      order: [[0, "desc"]],
      nonSortableColumns: [6],
      hideDefaultSearch: true,
      noun: "budgets",
      pageLength: 25,
    });
    UI.wireFilterToolbar("filterToolbar", table, [{ id: "statusFilter", columnIndex: 1, exact: true }], { noun: "budgets" });
  }

  return { init };
})();

window.BudgetsList = BudgetsList;
