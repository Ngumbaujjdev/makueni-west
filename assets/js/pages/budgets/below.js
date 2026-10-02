/**
 * ============================================================================
 * PAGE - THE BUDGETS OF THE PLACES BELOW (includes/budget/below.php)
 * ============================================================================
 * A region sees its churches, the diocese its churches (grouped by region)
 * or - with the Churches | Regions switch - its regions' own budgets. All
 * read-only, for a month or a whole year (default: the whole year):
 *   - how many have a budget in use, received, spent, deductions still owed;
 *   - spent against plan for the biggest places; who has a budget; what we
 *     noticed;
 *   - the groups at a glance (tap one to filter the table);
 *   - one row per place - opening it shows that place's Overview, view only.
 * ?level=region (diocese) and the filters are kept in the URL.
 * ============================================================================
 */
const BudgetsBelow = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const isDiocese = B.CTX.level === "diocese";
  let level = isDiocese && new URLSearchParams(window.location.search).get("level") === "region" ? "region" : "church";
  let period = null;
  let d = null;
  let charts = [];
  let table = null;

  function init() {
    period = B.periodControls({ defaultMonth: false, onChange: (p) => ((period = p), load()) });
    if (isDiocese) {
      document.getElementById("levelSwitchWrap").innerHTML = UI.renderSegmented(
        "levelSwitch",
        [
          { value: "church", label: "Churches" },
          { value: "region", label: "Regions" },
        ],
        level,
        { ariaLabel: "Churches or regions" },
      );
      UI.wireSegmented("levelSwitch", (value) => {
        level = value;
        const q = new URLSearchParams(window.location.search);
        level === "region" ? q.set("level", "region") : q.delete("level");
        q.delete("groupFilter");
        history.replaceState(null, "", `${window.location.pathname}?${q}`);
        load();
      });
    }
    load();
  }

  const words = () => d?.words || (level === "region" ? ["region", "regions"] : ["church", "churches"]);
  const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

  async function load() {
    charts.forEach((c) => c?.destroy?.());
    charts = [];
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards(4);
    const res = await BudgetsAPI.below({ year: period.year, month: period.month ?? "", level });
    if (!res.ok) {
      document.getElementById("statCardsRow").innerHTML = `<div class="col-12"><div class="alert alert-danger">${B.esc(res.message)}</div></div>`;
      ["pvaBody", "coverDonut", "insightsList", "belowTableBody"].forEach((id) => (document.getElementById(id).innerHTML = ""));
      return;
    }
    d = res.data;
    B.syncExport({ key: "budget.rollup", territoryId: d.place?.id, year: period.year, month: period.month });
    // The report is about the churches; the regions' own budgets each have their own reports.
    if (level === "region") document.getElementById("exportReportBtn")?.classList.add("d-none");
    renderHeader();
    renderStats();
    renderPlanVsActual();
    renderCoverage();
    renderGroups();
    renderTable();
    requestAnimationFrame(() => fillCard(charts.find((c) => c?.__pva), "pvaBody"));
  }

  // ---------------------------------------------------------------- header + cards

  function renderHeader() {
    const [, many] = words();
    const name = d.place?.name || "";
    document.getElementById("placeLine").textContent = `${cap(many)} ${isDiocese ? "across" : "in"} ${name} · ${period.month ? d.period.label : `Whole of ${d.period.year}`}`;
    document.getElementById("belowNote").textContent = `Each ${words()[0]} runs its own budgets. Open one to see it - you can look, but only that ${words()[0]} can change it.`;
    document.getElementById("listTitle").textContent = cap(many);
    document.getElementById("placeHead").textContent = cap(words()[0]);
    document.getElementById("groupHead").textContent = isDiocese ? "Region" : "Subregion";
  }

  function renderStats() {
    const t = d.totals;
    const p = d.previous;
    const vs = d.period.previous_label;
    const [, many] = words();
    const pct = (a, b) => (b > 0 ? `${Math.round((a / b) * 100)}% of ${B.shortMoney(b)} planned` : "Nothing planned");
    const spark = (key) => ({ labels: d.spark.labels, data: d.spark[key] });
    const hasPrev = p.in_actual > 0 || p.out_actual > 0;
    const owedKnown = t.due > 0 || t.sent > 0 || t.owed > 0;
    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-checkbox-circle-line",
        label: "With a budget in use",
        value: `${t.in_use} of ${t.places}`,
        color: t.none ? "primary" : "success",
        sub: [t.drafts ? `${t.drafts} ${t.drafts === 1 ? "draft" : "drafts"}` : "", t.closed ? `${t.closed} closed` : "", t.none ? `${t.none} without one` : `Every ${words()[0]} has one`].filter(Boolean).join(" · "),
      },
      { icon: "ri-arrow-down-circle-line", label: "Money received", value: B.shortMoney(t.in_actual), color: "success", delta: hasPrev ? B.delta(t.in_actual, p.in_actual, vs) : null, series: spark("in"), sub: pct(t.in_actual, t.in_planned) },
      { icon: "ri-arrow-up-circle-line", label: "Money spent", value: B.shortMoney(t.out_actual), color: "danger", delta: hasPrev ? B.delta(t.out_actual, p.out_actual, vs) : null, series: spark("out"), sub: `${pct(t.out_actual, t.out_planned)}${t.over ? ` · ${t.over} over plan` : ""}` },
      {
        icon: "ri-percent-line",
        label: "Deductions still owed",
        value: B.shortMoney(t.owed),
        color: t.owed > 0 ? "warning" : "purple",
        sub: owedKnown ? `Due ${B.shortMoney(t.due)} · sent ${B.shortMoney(t.sent)}` : period.month ? `By ${many} with a budget for the month` : "No deductions worked out yet",
      },
    ]);
  }

  // ---------------------------------------------------------------- spent against plan

  function renderPlanVsActual() {
    const [, many] = words();
    const body = document.getElementById("pvaBody");
    const rows = d.rows.filter((r) => r.out_planned > 0 || r.out_actual > 0).sort((a, b) => Math.max(b.out_planned, b.out_actual) - Math.max(a.out_planned, a.out_actual)).slice(0, 10);
    const t = d.totals;
    document.getElementById("pvaTitle").textContent = `Spent against plan, by ${words()[0]}`;
    document.getElementById("pvaSub").textContent = rows.length >= 10 ? `The ten ${many} with the most money out` : `Money out planned and spent, per ${words()[0]}`;
    document.getElementById("pvaChips").innerHTML = `
      <span class="soft-chip soft-primary">Planned ${B.shortMoney(t.out_planned)}</span>
      <span class="soft-chip soft-danger">Spent ${B.shortMoney(t.out_actual)}</span>
      ${t.over ? `<span class="soft-chip soft-warning">${t.over} over plan</span>` : ""}`;
    if (!rows.length) {
      body.innerHTML = `<div class="list-empty py-5"><span class="list-empty-icon bg-primary text-white"><i class="ri-bar-chart-horizontal-line"></i></span><div class="fw-semibold mt-2">No money out planned for ${B.esc(d.period.label)}</div><div class="fs-12">Once ${many} plan their budgets, you'll see them here.</div></div>`;
      return;
    }
    body.innerHTML = '<div id="pvaChart"></div>';
    const solid = UI.cssColor("primary");
    const over = UI.cssColor("danger");
    const chart = UI.renderTrendChart("pvaChart", {
      categories: rows.map((r) => r.name),
      series: [
        { name: "Planned", data: rows.map((r) => r.out_planned) },
        { name: "Spent", data: rows.map((r) => r.out_actual) },
      ],
      type: "bar",
      // A place that has gone over its plan turns red.
      colors: [UI.withAlpha(solid, 0.35), ({ dataPointIndex }) => (rows[dataPointIndex] && rows[dataPointIndex].over > 0 ? over : solid)],
      extra: {
        chart: { type: "bar", height: Math.max(240, rows.length * 38 + 70), toolbar: { show: false }, foreColor: UI.chartTextColor() },
        plotOptions: { bar: { horizontal: true, barHeight: rows.length < 5 ? "38%" : "70%", borderRadius: 4 } },
        xaxis: { categories: rows.map((r) => r.name), labels: { formatter: (v) => B.short(v), style: { colors: UI.chartTextColor(), fontWeight: 600 } } },
        yaxis: { labels: { maxWidth: 170, style: { colors: UI.chartTextColor(), fontWeight: 600 } } },
        tooltip: { shared: true, intersect: false, y: { formatter: (v) => (v == null ? v : B.money(v)) } },
        legend: { show: true, position: "top", horizontalAlign: "right", markers: { fillColors: [UI.withAlpha(solid, 0.35), solid] } },
      },
    });
    if (chart) {
      chart.__pva = true;
      charts.push(chart);
    }
  }

  /** Grows a chart by the empty space left in its card body (the row's cards share one height). */
  function fillCard(chart, bodyId) {
    const body = document.getElementById(bodyId);
    if (!chart || !body || window.innerWidth < 1200) return;
    const style = getComputedStyle(body);
    const used = [...body.children].reduce((h, el) => h + el.offsetHeight, 0) + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
    const spare = body.clientHeight - used;
    if (spare > 12) chart.updateOptions({ chart: { height: (chart.opts?.chart?.height || 300) + spare - 4 } }, false, false);
  }

  // ---------------------------------------------------------------- who has a budget

  function renderCoverage() {
    const t = d.totals;
    const [, many] = words();
    document.getElementById("coverSub").textContent = `${t.with_budget} of ${t.places} ${many} have a budget for ${period.month ? d.period.label : d.period.year}`;
    const parts = [
      { label: "In use", value: t.in_use, color: "success" },
      { label: "Draft", value: t.drafts, color: "warning" },
      { label: "Closed", value: t.closed, color: "purple" },
      { label: "No budget", value: t.none, color: "danger" },
    ].filter((p) => p.value > 0);
    if (!t.places) {
      document.getElementById("coverDonut").innerHTML = `<div class="list-empty py-4"><span class="list-empty-icon bg-primary text-white"><i class="ri-node-tree"></i></span><div class="fw-semibold mt-2">No ${many} below yet</div></div>`;
    } else {
      charts.push(UI.renderRingDonut("coverDonut", { labels: parts.map((p) => p.label), series: parts.map((p) => p.value), colors: parts.map((p) => p.color), centerLabel: cap(many) }));
    }
    UI.renderInsightList("insightsList", d.insights);
  }

  // ---------------------------------------------------------------- the groups at a glance

  function renderGroups() {
    const card = document.getElementById("groupsCard");
    const groups = d.groups || [];
    card.hidden = level !== "church" || groups.length < 2;
    if (card.hidden) return;
    document.getElementById("groupsTitle").textContent = isDiocese ? "Regions at a glance" : "Subregions at a glance";
    document.getElementById("groupsSub").textContent = "Tap one to see only its churches below";
    document.getElementById("groupsGrid").innerHTML = `<div class="budget-month-grid">${groups
      .map((g) => {
        const look = g.none === 0 ? "is-active" : g.with_budget === 0 ? "is-empty" : "is-draft";
        const pct = g.out_planned > 0 ? Math.round((g.out_actual / g.out_planned) * 100) : null;
        return `
          <a href="#belowTable" class="budget-month-tile ${look}" data-group="${B.esc(g.name)}">
            <div class="fw-semibold">${B.esc(g.name)}</div>
            <div>${UI.pill(`${g.in_use} of ${g.places} in use`, g.none === 0 ? "success" : g.with_budget === 0 ? "danger" : "warning", g.none === 0 ? "ri-checkbox-circle-line" : "ri-error-warning-line")}</div>
            <div class="budget-month-figure text-danger">${B.shortMoney(g.out_actual)} <small class="fw-semibold fs-12">spent${pct !== null ? ` · ${pct}%` : ""}</small></div>
            <div class="budget-month-sub">received ${B.shortMoney(g.in_actual)}${g.none ? ` · ${g.none} without a budget` : ""}${g.owed > 0 ? ` · ${B.shortMoney(g.owed)} owed` : ""}</div>
          </a>`;
      })
      .join("")}</div>`;
    document.querySelectorAll("#groupsGrid [data-group]").forEach((a) =>
      a.addEventListener("click", (e) => {
        e.preventDefault();
        const select = document.getElementById("groupFilter");
        if (!select) return;
        select.value = a.dataset.group;
        UI.syncSelect(select);
        select.dispatchEvent(new Event("change"));
        document.getElementById("belowTable").scrollIntoView({ behavior: "smooth", block: "start" });
      }),
    );
  }

  // ---------------------------------------------------------------- table

  const STATUS_LABEL = { active: "In use", draft: "Draft", closed: "Closed", none: "No budget" };

  function renderTable() {
    const [one, many] = words();
    const tbody = document.getElementById("belowTableBody");
    if (table) {
      table.destroy();
      table = null;
    }
    if (!d.rows.length) {
      tbody.innerHTML = `<tr><td colspan="8"><div class="list-empty"><span class="list-empty-icon bg-primary text-white"><i class="ri-node-tree"></i></span><div class="fw-semibold mt-2">No ${many} below yet</div></div></td></tr>`;
      document.getElementById("filterToolbar").innerHTML = "";
      return;
    }
    const overview = (r) => B.url("overview.php", { territory_id: r.id, year: period.year, month: period.month ?? "year", from: "below" });
    tbody.innerHTML = d.rows
      .map((r) => {
        const link = overview(r);
        const none = r.status === "none";
        const owed = r.deductions?.owed ?? null;
        return `
          <tr>
            <td data-search="${B.esc(r.name)}" data-order="${B.esc(r.name)}">
              <a href="${link}" class="d-flex align-items-center gap-2 text-reset">
                <span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(r.name)} ${B.tileText(UI.colorFor(r.name))} flex-shrink-0">${B.esc(initials(r.name))}</span>
                <div style="min-width: 0;">
                  <div class="fw-semibold text-truncate">${B.esc(r.name)}</div>
                  <div class="fs-12">${none ? `No budget for ${B.esc(period.month ? d.period.label : d.period.year)}` : B.esc(r.budget_label || "")}</div>
                </div>
              </a>
            </td>
            <td data-search="${B.esc(r.group || "-")}">${r.group ? `<span class="soft-chip soft-${UI.colorFor(r.group)}">${B.esc(r.group)}</span>` : "-"}</td>
            <td data-search="${STATUS_LABEL[r.status]}">${none ? UI.pill("No budget", "warning", "ri-error-warning-line") : B.statusPill(r.status)}</td>
            <td class="text-end" data-order="${r.in_actual}">${
              none && !r.in_actual
                ? "-"
                : `<div class="fw-semibold text-success">${B.amount(r.in_actual)}</div>${miniBar(r.in_actual, r.in_planned, "success", "of", "planned")}`
            }</td>
            <td class="text-end" data-order="${r.out_actual}">${
              none && !r.out_actual
                ? "-"
                : `<div class="fw-semibold text-danger">${B.amount(r.out_actual)}${pctPill(r)}</div>${miniBar(r.out_actual, r.out_planned, r.over > 0 ? "danger" : r.pct_used >= 80 ? "warning" : "primary", "of", "planned")}`
            }</td>
            <td class="text-end fw-semibold ${none && !r.in_actual && !r.out_actual ? "" : r.left < 0 ? "text-danger" : "text-success"}" data-order="${r.left}">${none && !r.in_actual && !r.out_actual ? "-" : B.amount(r.left)}</td>
            <td class="text-end" data-order="${owed ?? -1}">${owed === null ? "-" : owed > 0 ? `<span class="fw-semibold text-danger">${B.amount(owed)}</span>` : '<span class="soft-chip soft-success">Paid up</span>'}</td>
            <td class="text-end"><a href="${link}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
          </tr>`;
      })
      .join("");

    const groups = [...new Set(d.rows.map((r) => r.group).filter(Boolean))];
    const filters = [{ id: "statusFilter", label: "All statuses", options: Object.values(STATUS_LABEL).map((s) => ({ value: s, label: s })) }];
    if (groups.length > 1) filters.unshift({ id: "groupFilter", label: isDiocese ? "All regions" : "All subregions", options: groups.map((g) => ({ value: g, label: g })) });
    UI.renderFilterToolbar("filterToolbar", { searchPlaceholder: `Search ${many}...`, filters });
    table = UI.initListDataTable("belowTable", { order: [[4, "desc"]], nonSortableColumns: [7], hideDefaultSearch: true, noun: many, pageLength: 25 });
    if (table) table.column(1).visible(level === "church" && groups.length > 0);
    UI.wireFilterToolbar(
      "filterToolbar",
      table,
      [
        ...(groups.length > 1 ? [{ id: "groupFilter", columnIndex: 1, exact: true }] : []),
        { id: "statusFilter", columnIndex: 2, exact: true },
      ],
      { noun: many },
    );
    document.getElementById("listSub").textContent = `Every ${one}'s budget for ${period.month ? d.period.label : d.period.year} - open one to see it, view only`;
  }

  /** The % of the plan spent, as a solid pill: green, gold from 80%, red over. */
  function pctPill(r) {
    if (r.pct_used === null) return "";
    const color = r.over > 0 ? "danger" : r.pct_used >= 80 ? "warning" : "success";
    return ` <span class="badge bg-${color} ${B.tileText(color)} ms-1">${Math.round(r.pct_used)}%</span>`;
  }

  /** "of 12,000.00 planned" with a thin bar of how much of the plan that is. */
  function miniBar(actual, planned, color, word, verb) {
    const pct = planned > 0 ? Math.min(100, (actual / planned) * 100) : 0;
    return `<div class="budget-mini-bar"><span class="count-bar"><span class="bg-${color}" style="width: ${pct}%"></span></span><small>${planned > 0 ? `${word} ${B.amount(planned)} ${verb}` : `Nothing ${verb}`}</small></div>`;
  }

  const initials = (name) =>
    String(name)
      .replace(/^(CCI|ACK|AIC)\s+/i, "")
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((w) => w[0].toUpperCase())
      .join("");

  return { init };
})();

window.BudgetsBelow = BudgetsBelow;
