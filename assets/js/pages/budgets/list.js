/**
 * ============================================================================
 * PAGE - BUDGETS (includes/budget/budgets.php, every level)
 * ============================================================================
 * The year's budget dashboard, in the Demographics look:
 *   - year buttons in the toolbar (kept in the URL);
 *   - four cards with "vs last year" and month-by-month sparklines;
 *   - "Money in and out, month by month" + "Where the money goes";
 *   - "The year at a glance": a tile per month (or the whole-year budget);
 *   - the searchable table of the year's budgets.
 * ?territory_id= shows a place below, read-only.
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
    body: null,
    whereSide: "out",
  };
  let charts = [];

  async function init() {
    B.showFlash();
    renderYearSwitch();
    await load();
  }

  function renderYearSwitch() {
    const now = new Date().getFullYear();
    const years = [...new Set([now - 1, now, now + 1, state.year])].sort((a, b) => a - b);
    document.getElementById("yearSwitchWrap").innerHTML = UI.renderSegmented("yearSwitch", years.map((y) => ({ value: y, label: String(y) })), state.year, { ariaLabel: "Year" });
    UI.wireSegmented("yearSwitch", (value) => {
      state.year = Number(value);
      const p = new URLSearchParams(window.location.search);
      p.set("year", state.year);
      history.replaceState(null, "", `${window.location.pathname}?${p}`);
      load();
    });
  }

  async function load() {
    showLoading();
    const res = await BudgetsAPI.list({ year: state.year, territory_id: state.territoryId });
    if (!res.ok) {
      document.getElementById("statCardsRow").innerHTML = "";
      ["flowBody", "whereDonut", "yearGrid"].forEach((id) => (document.getElementById(id).innerHTML = ""));
      document.getElementById("budgetsTableBody").innerHTML = UI.renderTableEmpty(7, res.message, "ri-lock-line");
      return;
    }
    state.body = res.body;
    state.viewOnly = !!res.body.view_only;
    const budgets = res.data || [];
    const months = monthsOf(budgets);

    charts.forEach((c) => c?.destroy?.());
    charts = [];
    renderPlace(res.body.place);
    renderStats(res.body.stats, res.body.previous_stats, months);
    renderFlow(budgets, months, res.body.stats);
    renderWhere();
    renderGlance(budgets, months);
    renderRows(budgets);
  }

  function showLoading() {
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards(4);
    ["flowBody", "whereDonut"].forEach((id) => (document.getElementById(id).innerHTML = '<span class="skel" style="height: 300px; display: block;"></span>'));
    document.getElementById("yearGrid").innerHTML = '<span class="skel" style="height: 10rem; display: block;"></span>';
    document.getElementById("budgetsTableBody").innerHTML = UI.renderTableLoading(7, "Loading budgets...");
  }

  /** The year's budgets by month: [1..12] -> budget (or null), plus the whole-year budget. */
  function monthsOf(budgets) {
    const byMonth = Array.from({ length: 12 }, (_, i) => budgets.find((b) => b.period_month === i + 1) || null);
    return { byMonth, year: budgets.find((b) => b.period_month === null) || null };
  }

  function renderPlace(place) {
    const name = place?.name || "";
    document.getElementById("placeLine").textContent = name ? `Budgets for ${name} · ${state.year}` : `Budgets · ${state.year}`;
    document.getElementById("listTitle").textContent = `Budgets in ${state.year}`;
    const banner = document.getElementById("viewOnlyBanner");
    banner.classList.toggle("d-none", !state.viewOnly);
    banner.classList.toggle("d-flex", state.viewOnly);
    if (state.viewOnly) document.getElementById("viewOnlyText").textContent = `These are ${name}'s budgets. Only ${name} can change them.`;
    document.getElementById("newBudgetBtn")?.classList.toggle("d-none", state.viewOnly);
  }

  // ---------------------------------------------------------------- cards

  function renderStats(s, prev, months) {
    const vs = String(state.year - 1);
    const left = s.in_planned - s.out_planned;
    // Sparklines: month by month ({labels, data}, as mountSparklines expects); none for a whole-year budget.
    const planned = months.byMonth.map((b, i) => [b, i]).filter(([b]) => b);
    const series = (key) => (months.year || planned.length < 2 ? null : { labels: planned.map(([, i]) => B.MONTHS[i].slice(0, 3)), data: planned.map(([b]) => b[key]) });
    const monthsPlanned = months.year ? 12 : planned.length;
    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-arrow-down-circle-line",
        label: "Money in (planned)",
        value: B.shortMoney(s.in_planned),
        color: "success",
        delta: B.delta(s.in_planned, prev?.in_planned, vs),
        series: series("in_planned"),
        sub: `${B.money(s.in_planned)} · received ${B.shortMoney(s.in_actual)}`,
      },
      {
        icon: "ri-arrow-up-circle-line",
        label: "Money out (planned)",
        value: B.shortMoney(s.out_planned),
        color: "danger",
        delta: B.delta(s.out_planned, prev?.out_planned, vs),
        series: series("out_planned"),
        sub: `${B.money(s.out_planned)} · spent ${B.shortMoney(s.out_actual)}`,
      },
      {
        icon: "ri-scales-3-line",
        label: "Money left (planned)",
        value: B.shortMoney(left),
        color: left < 0 ? "danger" : "purple",
        delta: prev ? B.delta(left, prev.in_planned - prev.out_planned, vs) : null,
        series: series("left_planned"),
        sub: left < 0 ? `${B.money(left)} · spending more than comes in` : B.money(left),
      },
      {
        icon: "ri-calendar-check-line",
        label: "Months planned",
        value: months.year ? "Whole year" : `${monthsPlanned} of 12`,
        color: "primary",
        sub: s.budgets ? `${s.in_use} in use · ${s.drafts} ${s.drafts === 1 ? "draft" : "drafts"}` : "Nothing planned yet",
      },
    ]);
  }

  // ---------------------------------------------------------------- money in and out

  function renderFlow(budgets, months, s) {
    const body = document.getElementById("flowBody");
    const chips = document.getElementById("flowChips");
    const left = s.in_planned - s.out_planned;
    chips.innerHTML = budgets.length
      ? `<span class="soft-chip soft-success">In ${B.shortMoney(s.in_planned)}</span>
         <span class="soft-chip soft-danger">Out ${B.shortMoney(s.out_planned)}</span>
         <span class="soft-chip soft-${left < 0 ? "danger" : "primary"}">Left ${B.shortMoney(left)}</span>`
      : "";

    if (!budgets.length) {
      body.innerHTML = emptyState("ri-bar-chart-grouped-line", `Nothing planned for ${state.year} yet`, "Once a budget is prepared, its money in and out shows here.");
      return;
    }

    // A whole-year budget can't be split by month - show its split instead.
    if (months.year) {
      document.getElementById("flowTitle").textContent = `Money in and out, whole of ${state.year}`;
      document.getElementById("flowSub").textContent = "What the year's budget plans to receive and spend";
      UI.renderCompositionCard("flowBody", {
        total: left,
        totalLabel: "money left, planned for the year",
        format: (v) => B.money(v),
        items: [
          { label: "Money in", value: s.in_planned, color: "success" },
          { label: "Money out", value: s.out_planned, color: "danger" },
        ],
      });
      return;
    }

    document.getElementById("flowTitle").textContent = "Money in and out, month by month";
    document.getElementById("flowSub").textContent = "What each month's budget plans to receive and spend";
    body.innerHTML = '<div id="flowChart"></div>';
    charts.push(
      UI.renderTrendChart("flowChart", {
        categories: B.MONTHS.map((m) => m.slice(0, 3)),
        series: [
          { name: "Money in", data: months.byMonth.map((b) => (b ? b.in_planned : 0)) },
          { name: "Money out", data: months.byMonth.map((b) => (b ? b.out_planned : 0)) },
        ],
        type: "bar",
        colors: [UI.cssColor("success"), UI.cssColor("danger")],
        yFormat: (v, full) => (full ? B.money(v) : B.short(v)),
      }),
    );
  }

  // ---------------------------------------------------------------- where the money goes

  function renderWhere() {
    const wrap = document.getElementById("whereSwitchWrap");
    if (!wrap.innerHTML) {
      wrap.innerHTML = UI.renderSegmented("whereSwitch", [{ value: "out", label: "Out" }, { value: "in", label: "In" }], state.whereSide, { ariaLabel: "Money in or out" });
      UI.wireSegmented("whereSwitch", (value) => {
        state.whereSide = value;
        renderWhere();
      });
    }
    const lines = (state.whereSide === "out" ? state.body.top_out : state.body.top_in) || [];
    document.getElementById("whereSub").textContent = state.whereSide === "out" ? `The biggest money out lines in ${state.year}` : `Where money in comes from in ${state.year}`;
    if (!lines.length) {
      document.getElementById("whereDonut").innerHTML = emptyState("ri-pie-chart-2-line", "Nothing planned yet", "The lines of the year's budgets show here.");
      return;
    }
    charts.push(
      UI.renderRingDonut("whereDonut", {
        labels: lines.map((l) => B.esc(l.name)),
        series: lines.map((l) => l.planned),
        colors: state.whereSide === "out" ? ["danger", "warning", "purple", "pink", "primary", "secondary"] : ["success", "primary", "purple", "warning", "pink", "secondary"],
        centerLabel: state.whereSide === "out" ? "Money out" : "Money in",
        format: (v) => B.shortMoney(v),
      }),
    );
  }

  // ---------------------------------------------------------------- the year at a glance

  function renderGlance(budgets, months) {
    const grid = document.getElementById("yearGrid");
    const canPlan = B.CTX.can?.prepare && !state.viewOnly;
    const counts = { active: 0, draft: 0, closed: 0 };
    budgets.forEach((b) => (counts[b.status] = (counts[b.status] || 0) + 1));
    document.getElementById("glanceTitle").textContent = `${state.year} at a glance`;
    document.getElementById("glanceChips").innerHTML = [
      counts.active ? `<span class="soft-chip soft-success">${counts.active} in use</span>` : "",
      counts.draft ? `<span class="soft-chip soft-secondary">${counts.draft} ${counts.draft === 1 ? "draft" : "drafts"}</span>` : "",
      counts.closed ? `<span class="soft-chip soft-primary">${counts.closed} closed</span>` : "",
    ].join("");

    if (months.year) {
      grid.innerHTML = `<div class="budget-month-grid">${tile(months.year, `Whole of ${state.year}`, true)}</div>`;
      return;
    }
    const now = new Date();
    grid.innerHTML = `<div class="budget-month-grid">${months.byMonth
      .map((b, i) => {
        const label = B.MONTHS[i];
        const isNow = now.getFullYear() === state.year && now.getMonth() === i;
        if (b) return tile(b, label, false, isNow);
        return `
          <div class="budget-month-tile is-empty${isNow ? " is-now" : ""}">
            <div class="budget-month-top"><span class="fw-semibold">${label}</span>${isNow ? '<span class="soft-chip soft-primary">This month</span>' : ""}</div>
            <div class="budget-month-figure">Not planned</div>
            ${canPlan ? `<a href="${B.url("form.php", { year: state.year, month: i + 1 })}" class="budget-month-link"><i class="ri-add-line me-1"></i>Plan it</a>` : '<span class="budget-month-link text-reset">No budget</span>'}
          </div>`;
      })
      .join("")}</div>`;
  }

  function tile(b, label, wide, isNow = false) {
    const left = b.left_planned;
    return `
      <a href="${B.url("budget.php", { id: b.id })}" class="budget-month-tile${wide ? " is-wide" : ""}${isNow ? " is-now" : ""}">
        <div class="budget-month-top"><span class="fw-semibold">${label}</span>${B.statusPill(b.status)}</div>
        <div class="budget-month-figure ${left < 0 ? "text-danger" : "text-success"}">${B.money(left)}</div>
        <div class="budget-month-sub">money left · in ${B.shortMoney(b.in_planned)} · out ${B.shortMoney(b.out_planned)}</div>
      </a>`;
  }

  // ---------------------------------------------------------------- table

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
      UI.initListDataTable("budgetsTable", {});
      document.getElementById("filterToolbar").innerHTML = "";
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
    const table = UI.initListDataTable("budgetsTable", { order: [[0, "desc"]], nonSortableColumns: [6], hideDefaultSearch: true, noun: "budgets", pageLength: 25 });
    UI.wireFilterToolbar("filterToolbar", table, [{ id: "statusFilter", columnIndex: 1, exact: true }], { noun: "budgets" });
  }

  function emptyState(icon, title, text) {
    return `<div class="list-empty py-5">
      <span class="list-empty-icon bg-primary text-white"><i class="${icon}"></i></span>
      <div class="fw-semibold mt-2">${B.esc(title)}</div>
      <div class="fs-12">${B.esc(text)}</div>
    </div>`;
  }

  return { init };
})();

window.BudgetsList = BudgetsList;
