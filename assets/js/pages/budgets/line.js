/**
 * ============================================================================
 * PAGE - ONE LINE OF A BUDGET (includes/budget/line.php, every level)
 * ============================================================================
 * ?budget=ID&line=LINE_ID. Planned against what came in or went out on this
 * line, against the budget before; when the money moved (a whole-year budget
 * month by month by the date each amount was recorded, a month day by day
 * against an even pace); and every amount on the line, each opening its own
 * page. "Record money on this line" opens the window with the line chosen.
 * ============================================================================
 */
const BudgetsLine = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const params = new URLSearchParams(window.location.search);
  const budgetId = Number(params.get("budget"));
  const lineId = Number(params.get("line"));
  let d = null;
  let chart = null;

  async function init() {
    B.showFlash();
    if (!budgetId || !lineId) {
      showError("No budget line was chosen.");
      return;
    }
    document.getElementById("recordLineBtn").addEventListener("click", () =>
      BudgetsEntryModal.open({ budgetId, lineId, direction: d?.line.side || "out", onSaved: load }),
    );
    await load();
  }

  async function load() {
    const res = await BudgetsAPI.line(budgetId, lineId);
    if (!res.ok) {
      showError(res.message);
      return;
    }
    d = res.data;
    render();
  }

  function render() {
    const l = d.line;
    const isIn = l.side === "in";
    const place = d.budget.place?.name || "";
    document.title = `${l.name} - ${d.budget.period_label} budget - Makueni West Diocese`;
    document.getElementById("lineIcon").innerHTML = `<span class="avatar avatar-lg bg-${isIn ? "success" : "danger"} text-white"><i class="${B.lineIcon(l.name, l.side)}"></i></span>`;
    document.getElementById("lineKicker").textContent = `${isIn ? "Money in" : "Money out"} line · ${place}`;
    document.getElementById("lineTitle").textContent = l.name;
    document.getElementById("lineSub").innerHTML = `<a href="${B.url("budget.php", { id: d.budget.id })}" class="fw-semibold">${B.esc(d.budget.period_label)} budget</a> ${B.statusPill(d.budget.status)}${l.is_unplanned ? ' <span class="soft-chip soft-warning">Unplanned line</span>' : ""}${l.description ? ` <span class="ms-1">${B.esc(l.description)}</span>` : ""}`;
    document.getElementById("backBtn").href = B.url("budget.php", { id: d.budget.id });
    document.getElementById("recordLineBtn").hidden = !d.can.record;
    const banner = document.getElementById("viewOnlyBanner");
    banner.classList.toggle("d-none", !d.view_only);
    banner.classList.toggle("d-flex", !!d.view_only);
    if (d.view_only) document.getElementById("viewOnlyText").textContent = `This is ${place}'s budget. Only ${place} can record its money.`;

    renderStats(l, isIn);
    renderChart(l, isIn);
    renderFacts(l, isIn);
    renderRows(isIn);
  }

  function renderStats(l, isIn) {
    const prev = d.previous;
    const over = !isIn && l.actual > l.planned;
    const last = l.last_date ? new Date(`${l.last_date}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : null;
    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-flag-line",
        label: "Planned",
        value: B.money(l.planned),
        color: "primary",
        delta: prev && prev.planned !== null ? B.delta(l.planned, prev.planned, prev.period_label) : null,
        sub: prev ? (prev.planned !== null ? `vs ${prev.period_label}` : `Not in the ${prev.period_label} budget`) : "No budget before this one",
      },
      {
        icon: isIn ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line",
        label: isIn ? "Received" : "Spent",
        value: B.money(l.actual),
        color: isIn ? "success" : "danger",
        sub: l.pct !== null ? `${Math.round(l.pct)}% of the plan` : "Nothing was planned",
      },
      {
        icon: "ri-scales-3-line",
        label: isIn ? "Still to come" : over ? "Over plan by" : "Left",
        value: B.money(Math.abs(isIn ? Math.max(l.left, 0) : l.left)),
        color: over ? "danger" : "purple",
        sub: over ? "More has gone out than was planned" : isIn ? "Planned, not yet received" : "Planned, not yet spent",
      },
      {
        icon: "ri-file-list-3-line",
        label: "Entries",
        value: d.entries.length,
        color: "warning",
        sub: last ? `Last on ${last}` : "Nothing recorded yet",
      },
    ]);
  }

  /** A year: bars per month by the date each amount was recorded, against the planned twelfth. A month: running total against an even pace. */
  function renderChart(l, isIn) {
    chart?.destroy?.();
    const body = document.getElementById("lineChartBody");
    const c = d.chart;
    const color = UI.cssColor(isIn ? "success" : "danger");
    document.getElementById("chartChips").innerHTML = `
      <span class="soft-chip soft-primary">Planned ${B.shortMoney(l.planned)}</span>
      <span class="soft-chip soft-${isIn ? "success" : "danger"}">${isIn ? "Received" : "Spent"} ${B.shortMoney(l.actual)}</span>`;
    if (!d.entries.length) {
      document.getElementById("chartSub").textContent = "Nothing recorded on this line yet";
      body.innerHTML = `<div class="list-empty py-5"><span class="list-empty-icon bg-primary text-white"><i class="ri-bar-chart-2-line"></i></span><div class="fw-semibold mt-2">No money ${isIn ? "in" : "out"} on this line yet</div><div class="fs-12">Once money is recorded, you'll see here when it moved.</div></div>`;
      return;
    }
    body.innerHTML = '<div id="lineChart"></div>';
    if (c.kind === "months") {
      document.getElementById("chartTitle").textContent = "Month by month";
      document.getElementById("chartSub").textContent = `${isIn ? "Received" : "Spent"} each month, by the date it was recorded, against a twelfth of the plan`;
      chart = UI.renderTrendChart("lineChart", {
        categories: c.points.map((p) => p.label),
        series: [
          { name: isIn ? "Received" : "Spent", type: "column", data: c.points.map((p) => (isIn ? p.in : p.out)) },
          { name: "A twelfth of the plan", type: "line", data: c.points.map((p) => p.planned) },
        ],
        type: "mixed",
        colors: [color, UI.cssColor("primary")],
        yFormat: (v, full) => (full ? B.money(v) : B.short(v)),
        extra: { stroke: { width: [0, 2], dashArray: [0, 6], curve: "straight" }, plotOptions: { bar: { columnWidth: "45%", borderRadius: 4 } }, markers: { size: [0, 0] } },
      });
    } else {
      document.getElementById("chartTitle").textContent = "Through the month";
      document.getElementById("chartSub").textContent = `${isIn ? "Received" : "Spent"} so far, day by day, against using the plan evenly`;
      chart = UI.renderTrendChart("lineChart", {
        categories: c.points.map((p) => p.label),
        series: [
          { name: isIn ? "Received so far" : "Spent so far", data: c.points.map((p) => p.actual) },
          { name: "Even pace", data: c.points.map((p) => p.pace) },
        ],
        type: "line",
        colors: [color, UI.cssColor("primary")],
        yFormat: (v, full) => (full ? B.money(v) : B.short(v)),
        extra: { stroke: { curve: "stepline", width: [3, 2], dashArray: [0, 6] } },
      });
    }
  }

  function renderFacts(l, isIn) {
    const pct = l.planned > 0 ? Math.min(100, (l.actual / l.planned) * 100) : l.actual > 0 ? 100 : 0;
    const over = !isIn && l.actual > l.planned;
    const color = isIn ? "success" : over ? "danger" : pct >= 80 ? "warning" : "primary";
    const people = [...new Set(d.entries.map((e) => e.recorded_by).filter(Boolean))];
    const months = d.chart.kind === "months" ? d.chart.points.filter((p) => p.count).length : null;
    const facts = [
      ["Budget", `<a href="${B.url("budget.php", { id: d.budget.id })}">${B.esc(d.budget.period_label)}</a>`],
      ["Planned", B.money(l.planned)],
      [isIn ? "Received" : "Spent", B.money(l.actual)],
      d.previous ? [`In ${d.previous.period_label}`, d.previous.planned !== null ? B.money(d.previous.planned) : "Not planned"] : null,
      months !== null ? ["Months with money", `${months} of 12`] : null,
      ["Recorded by", people.length ? B.esc(people.join(", ")) : "-"],
    ].filter(Boolean);
    document.getElementById("factsSub").textContent = `${d.budget.period_label} · ${isIn ? "money in" : "money out"}`;
    document.getElementById("lineFacts").innerHTML = `
      <div class="d-flex align-items-end justify-content-between gap-2">
        <div><span class="composition-total">${Math.round(l.pct ?? (l.actual ? 100 : 0))}%</span> <span class="kpi-caption">${isIn ? "received" : "used"}</span></div>
        ${over ? `<span class="badge bg-danger">Over by ${B.money(l.actual - l.planned)}</span>` : `<span class="soft-chip soft-${color}">${B.money(Math.max(l.left, 0))} ${isIn ? "to come" : "left"}</span>`}
      </div>
      <div class="count-bar my-2"><span class="bg-${color}" style="width: ${pct}%"></span></div>
      <ul class="list-unstyled mb-0 mt-3 budget-facts">
        ${facts.map(([k, v]) => `<li><span>${k}</span><span class="fw-semibold text-end">${v}</span></li>`).join("")}
      </ul>`;
  }

  function renderRows(isIn) {
    document.getElementById("counterpartyHead").textContent = isIn ? "Received from" : "Paid to";
    const tbody = document.getElementById("lineEntriesBody");
    if (!d.entries.length) {
      tbody.innerHTML = `<tr><td colspan="6"><div class="list-empty"><span class="list-empty-icon bg-primary text-white"><i class="ri-exchange-dollar-line"></i></span><div class="fw-semibold mt-2">Nothing recorded on this line yet</div>${d.can.record ? '<div class="fs-12">Press <b>Record money on this line</b> when money comes in or goes out.</div>' : ""}</div></td></tr>`;
      UI.initListDataTable("lineEntriesTable", {});
      document.getElementById("filterToolbar").innerHTML = "";
      return;
    }
    tbody.innerHTML = d.entries
      .map((e) => {
        const date = new Date(`${e.entry_date}T00:00:00`);
        const link = B.url("entry.php", { id: e.id });
        return `
          <tr data-row-id="${e.id}" class="is-clickable" data-href="${link}">
            <td data-order="${e.entry_date}"><span class="budget-recent-date is-${isIn ? "in" : "out"}"><b>${date.getDate()}</b><small>${date.toLocaleDateString("en-GB", { month: "short" })}</small></span></td>
            <td data-search="${B.esc(`${e.description} ${e.reference || ""}`)}"><a href="${link}" class="fw-semibold text-reset">${B.esc(e.description)}</a>${e.reference ? `<div class="fs-12 font-monospace">${B.esc(e.reference)}</div>` : ""}</td>
            <td class="d-none d-md-table-cell">${B.esc(e.counterparty || "-")}</td>
            <td data-search="${B.METHODS[e.method]?.label || ""}">${B.methodChip(e.method)}</td>
            <td class="d-none d-lg-table-cell">${B.esc(e.recorded_by || "-")}</td>
            <td class="text-end fw-bold ${isIn ? "text-success" : "text-danger"}" data-order="${e.amount}">${isIn ? "+" : "−"}${B.amount(e.amount)}</td>
          </tr>`;
      })
      .join("");
    UI.renderFilterToolbar("filterToolbar", {
      searchPlaceholder: "Search what for, reference...",
      filters: [{ id: "methodFilter", label: "Any way", options: Object.values(B.METHODS).map((m) => ({ value: m.label, label: m.label })) }],
    });
    const table = UI.initListDataTable("lineEntriesTable", { order: [[0, "desc"]], hideDefaultSearch: true, noun: "entries", pageLength: 25 });
    UI.wireFilterToolbar("filterToolbar", table, [{ id: "methodFilter", columnIndex: 3 }], { noun: "entries" });
    tbody.onclick = (ev) => {
      const row = ev.target.closest("tr[data-href]");
      if (row && !ev.target.closest("a")) window.location.href = row.dataset.href;
    };
  }

  function showError(message) {
    document.getElementById("lineTitle").textContent = "Line not available";
    document.getElementById("lineSub").textContent = message;
    ["statCardsRow", "lineChartBody", "lineFacts"].forEach((elId) => (document.getElementById(elId).innerHTML = ""));
  }

  return { init };
})();

window.BudgetsLine = BudgetsLine;
