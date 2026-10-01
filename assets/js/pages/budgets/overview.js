/**
 * ============================================================================
 * PAGE - BUDGET OVERVIEW (includes/budget/overview.php, every level)
 * ============================================================================
 * Straight to the point, for a month (default: this month) or a whole year:
 *   - a verdict: on track, spending ahead or over plan, with money in and
 *     out against how much of the period has gone;
 *   - money in received, money out spent, money left, and how far in we are,
 *     each against the period before;
 *   - how the money moved (a month: spending against an even pace;
 *     a year: received and spent each month);
 *   - what we noticed; plan vs actual for the biggest lines; where the money
 *     received came from; where the money is going, line by line;
 *   - the latest money in and out, and "Record money".
 * ?territory_id= shows a place below, read-only.
 * ============================================================================
 */
const BudgetsOverview = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const territoryId = new URLSearchParams(window.location.search).get("territory_id") || "";
  let period = null;
  let d = null;
  let side = "out";
  let pvaSide = "out";
  let showAllLines = false;
  let charts = [];

  function init() {
    B.showFlash();
    period = B.periodControls({ defaultMonth: true, onChange: (p) => ((period = p), load()) });
    document.getElementById("recordOutBtn").addEventListener("click", () => record("out"));
    document.querySelectorAll("[data-record]").forEach((a) => a.addEventListener("click", () => record(a.dataset.record)));
    if (territoryId) document.getElementById("seeAllLink").href = B.url("spending.php", { territory_id: territoryId });
    load();
  }

  async function load() {
    charts.forEach((c) => c?.destroy?.());
    charts = [];
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards(4);
    const res = await BudgetsAPI.dashboard({ year: period.year, month: period.month ?? "", territory_id: territoryId });
    if (!res.ok) {
      document.getElementById("statCardsRow").innerHTML = "";
      document.getElementById("budgetAlert").innerHTML = `<div class="alert alert-danger">${B.esc(res.message)}</div>`;
      document.getElementById("heroCard").hidden = true;
      ["trendBody", "insights", "pvaBody", "sourceDonut", "lineProgress", "recentList"].forEach((id) => (document.getElementById(id).innerHTML = ""));
      return;
    }
    d = res.data;
    B.syncExport({ key: "budget.summary", territoryId: d.place?.id, year: period.year, month: period.month });
    renderHeader();
    renderAlert();
    renderHero();
    renderStats();
    renderTrend();
    UI.renderInsightList("insights", d.insights);
    renderPlanVsActual();
    renderSources();
    renderSideSwitch();
    renderLines();
    renderRecent();
  }

  // ---------------------------------------------------------------- header + alerts

  function renderHeader() {
    const name = d.place?.name || "";
    document.getElementById("placeLine").textContent = `${name} · ${d.period.label}`;
    const banner = document.getElementById("viewOnlyBanner");
    banner.classList.toggle("d-none", !d.view_only);
    banner.classList.toggle("d-flex", !!d.view_only);
    if (d.view_only) document.getElementById("viewOnlyText").textContent = `This is ${name}'s budget. Only ${name} can record its money.`;

    const open = document.getElementById("openBudgetBtn");
    open.classList.toggle("d-none", !d.budget);
    if (d.budget) open.href = B.url("budget.php", { id: d.budget.id });
    const canRecord = d.can_record && d.budget?.status === "active";
    document.getElementById("recordGroup").classList.toggle("d-none", !canRecord);
  }

  function renderAlert() {
    const el = document.getElementById("budgetAlert");
    const canPlan = B.CTX.can?.prepare && !d.view_only;
    if (!d.budget && !d.budgets.length) {
      el.innerHTML = `
        <div class="card custom-card">
          <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <span class="avatar avatar-md bg-warning text-dark"><i class="ri-calendar-todo-line"></i></span>
            <div class="flex-fill">
              <div class="fw-semibold fs-15">No budget for ${B.esc(d.period.label)} yet</div>
              <div>${canPlan ? "Plan what you expect to receive and spend - start from the last budget and it takes a minute." : "Nothing has been planned for this period."}</div>
            </div>
            ${canPlan ? `<a class="btn btn-primary" href="${B.url("form.php", { year: d.period.year, month: d.period.month ?? "year" })}"><i class="ri-add-line me-1"></i>Prepare it</a>` : ""}
          </div>
        </div>`;
      return;
    }
    if (d.budget?.status === "draft") {
      el.innerHTML = `
        <div class="alert alert-warning d-flex align-items-center gap-3" role="note">
          <span class="avatar avatar-sm bg-warning text-dark flex-shrink-0"><i class="ri-draft-line"></i></span>
          <div class="flex-fill"><b>The ${B.esc(d.budget.period_label)} budget is still a draft.</b> Money can be recorded once it's in use.</div>
          ${!d.view_only ? `<a class="btn btn-sm btn-warning" href="${B.url("budget.php", { id: d.budget.id })}">Start using it</a>` : ""}
        </div>`;
      return;
    }
    el.innerHTML = d.budget?.is_year && d.period.month
      ? `<div class="alert alert-primary d-flex align-items-center gap-3"><span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-information-line"></i></span><div>Planned figures are a twelfth of the ${B.esc(d.budget.period_label)} budget.</div></div>`
      : "";
  }

  // ---------------------------------------------------------------- the verdict

  const pctOf = (a, b) => (b > 0 ? (a / b) * 100 : 0);
  const roundPct = (v) => (v > 0 && v < 1 ? "<1" : Math.round(v));

  function verdict() {
    const t = d.totals;
    const spent = pctOf(t.out_actual, t.out_planned);
    const time = d.period.time_pct;
    if (t.out_planned > 0 && t.out_actual > t.out_planned) return { label: "Over plan", color: "danger", icon: "ri-error-warning-fill" };
    if (time !== null && t.out_planned > 0 && spent > time + 10) return { label: "Spending ahead", color: "warning", icon: "ri-speed-up-fill" };
    if (time === null && !d.period.ended) return { label: "Not started", color: "primary", icon: "ri-calendar-event-fill" };
    return { label: d.period.ended ? "Within plan" : "On track", color: "success", icon: "ri-checkbox-circle-fill" };
  }

  // "October" for a month, "2026" for a year.
  const shortLabel = () => (d.period.month ? B.MONTHS[d.period.month - 1] : String(d.period.year));

  function heroSentence() {
    const t = d.totals;
    const spent = roundPct(pctOf(t.out_actual, t.out_planned));
    const label = shortLabel();
    const unit = d.period.month ? "month" : "year";
    if (t.out_planned > 0 && t.out_actual > t.out_planned) return `Spending is ${B.money(t.out_actual - t.out_planned)} over ${label}'s plan.`;
    if (d.period.time_pct !== null) return `You've spent ${spent}% of ${label}'s plan with ${roundPct(d.period.time_pct)}% of the ${unit} gone.`;
    if (d.period.ended) return `${label} has ended: ${spent}% of the plan was spent and ${roundPct(pctOf(t.in_actual, t.in_planned))}% of the money expected came in.`;
    return `${label} hasn't started yet. ${B.money(t.out_planned)} is planned to go out.`;
  }

  function renderHero() {
    const card = document.getElementById("heroCard");
    card.hidden = !d.budget;
    if (!d.budget) return;
    const t = d.totals;
    const v = verdict();
    const time = d.period.time_pct;
    const label = shortLabel();
    document.getElementById("heroTop").innerHTML = `
      ${UI.pill(v.label, v.color, v.icon)}
      ${B.statusPill(d.budget.status)}
      <span class="soft-chip soft-primary">${B.esc(d.budget.period_label)} budget${d.budget.is_year && d.period.month ? " · a twelfth" : ""}</span>`;
    document.getElementById("heroTitle").textContent = heroSentence();

    const bar = (name, actual, planned, color, verb) => {
      const pct = pctOf(actual, planned);
      const over = actual > planned && planned > 0;
      return `
        <div class="budget-hero-bar">
          <div class="budget-hero-bar-top">
            <span class="fw-semibold">${name}</span>
            <span><b>${B.money(actual)}</b> ${verb} of ${B.money(planned)} · <b class="${over ? "text-danger" : ""}">${planned > 0 ? `${roundPct(pct)}%` : "-"}</b></span>
          </div>
          <div class="budget-hero-track">
            <span class="bg-${over ? "danger" : color}" style="width: ${Math.min(pct, 100)}%"></span>
            ${time !== null ? `<i class="budget-hero-today" style="left: ${Math.min(time, 100)}%" title="Today: ${roundPct(time)}% of ${B.esc(label)} gone"></i>` : ""}
          </div>
        </div>`;
    };
    document.getElementById("heroBars").innerHTML = `
      ${bar("Money in", t.in_actual, t.in_planned, "success", "received")}
      ${bar("Money out", t.out_actual, t.out_planned, "primary", "spent")}
      ${time !== null ? `<div class="budget-hero-legend"><i class="budget-hero-today-key"></i>Today · ${roundPct(time)}% of ${B.esc(label)} gone</div>` : ""}`;

    const left = document.getElementById("heroLeft");
    left.textContent = B.money(t.left_actual);
    left.classList.toggle("text-danger", t.left_actual < 0);
    document.getElementById("heroLeftSub").textContent = t.left_actual < 0 ? "More has gone out than came in" : `Planned to be left: ${B.money(t.left_planned)}`;

    // A small ring: of the money received, how much is spent and how much is left.
    const ring = document.getElementById("heroRing");
    const kept = Math.max(t.in_actual - t.out_actual, 0);
    document.getElementById("heroKeys").innerHTML = t.in_actual || t.out_actual
      ? `<span class="soft-chip soft-danger">Spent ${B.shortMoney(t.out_actual)}</span><span class="soft-chip soft-success">Left ${B.shortMoney(kept)}</span>`
      : "";
    if (!t.in_actual && !t.out_actual) {
      ring.innerHTML = '<span class="budget-hero-ring-empty"><i class="ri-donut-chart-line"></i></span>';
      return;
    }
    ring.innerHTML = '<div class="budget-hero-ring-chart"></div><div class="budget-hero-ring-cap">of money in spent</div>';
    const spentPct = t.in_actual > 0 ? Math.round(pctOf(t.out_actual, t.in_actual)) : 100;
    const chart = new ApexCharts(ring.querySelector(".budget-hero-ring-chart"), {
      chart: { type: "donut", height: 160, width: 160, animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") } },
      series: [t.out_actual, kept],
      labels: ["Spent", "Left"],
      colors: [UI.cssColor("danger"), UI.cssColor("success")],
      stroke: { width: 0 },
      legend: { show: false },
      dataLabels: { enabled: false },
      tooltip: { y: { formatter: (v) => B.money(v) } },
      plotOptions: {
        pie: {
          donut: {
            size: "76%",
            labels: {
              show: true,
              name: { show: false },
              value: { show: true, fontSize: "20px", fontWeight: 700, color: UI.chartTextColor(), offsetY: 7 },
              total: { show: true, showAlways: true, label: "", fontSize: "11px", color: UI.chartTextColor(), formatter: () => `${spentPct}%` },
            },
          },
        },
      },
    });
    chart.render();
    charts.push(chart);
  }

  // ---------------------------------------------------------------- cards

  function renderStats() {
    const t = d.totals;
    const p = d.previous;
    const vs = d.period.previous_label;
    const pct = (a, b) => (b > 0 ? `${Math.round((a / b) * 100)}% of ${B.shortMoney(b)} planned` : "Nothing planned");
    const spark = (key) => ({ labels: d.spark.labels, data: d.spark[key] });
    const cards = [
      { icon: "ri-arrow-down-circle-line", label: "Money in (received)", value: B.shortMoney(t.in_actual), color: "success", delta: p.entries ? B.delta(t.in_actual, p.in_actual, vs) : null, series: spark("in"), sub: pct(t.in_actual, t.in_planned) },
      { icon: "ri-arrow-up-circle-line", label: "Money out (spent)", value: B.shortMoney(t.out_actual), color: "danger", delta: p.entries ? B.delta(t.out_actual, p.out_actual, vs) : null, series: spark("out"), sub: pct(t.out_actual, t.out_planned) },
      {
        icon: "ri-scales-3-line",
        label: "Money left",
        value: B.shortMoney(t.left_actual),
        color: t.left_actual < 0 ? "danger" : "purple",
        series: spark("left"),
        sub: t.left_actual < 0 ? "More has gone out than came in" : `Planned to be left: ${B.shortMoney(t.left_planned)}`,
      },
    ];
    if (d.period.time_pct !== null) {
      const end = new Date(`${d.period.end}T00:00:00`);
      const daysLeft = Math.max(0, Math.ceil((end - new Date()) / 86400000));
      cards.push({ icon: "ri-time-line", label: `${d.period.label} so far`, value: `${Math.round(d.period.time_pct)}%`, color: "primary", sub: `${daysLeft} ${daysLeft === 1 ? "day" : "days"} left · ${t.entries} ${t.entries === 1 ? "entry" : "entries"}` });
    } else {
      cards.push({ icon: "ri-file-list-3-line", label: "Entries", value: t.entries, color: "primary", sub: d.period.ended ? `${d.period.label} has ended` : `${d.period.label} hasn't started` });
    }
    UI.renderStatCardsRow("statCardsRow", cards);
  }

  // ---------------------------------------------------------------- how the money moved

  function renderTrend() {
    const t = d.totals;
    document.getElementById("trendChips").innerHTML = `
      <span class="soft-chip soft-success">In ${B.shortMoney(t.in_actual)}</span>
      <span class="soft-chip soft-danger">Out ${B.shortMoney(t.out_actual)}</span>
      <span class="soft-chip soft-${t.left_actual < 0 ? "danger" : "primary"}">Left ${B.shortMoney(t.left_actual)}</span>`;
    const body = document.getElementById("trendBody");
    const points = d.trend.points;
    if (!t.entries && !t.out_planned && !t.in_planned) {
      document.getElementById("trendSub").textContent = "Nothing planned or recorded yet";
      body.innerHTML = `<div class="list-empty py-5"><span class="list-empty-icon bg-primary text-white"><i class="ri-line-chart-line"></i></span><div class="fw-semibold mt-2">Nothing to show yet</div><div class="fs-12">Once money is recorded, you'll see how it moves here.</div></div>`;
      return;
    }
    body.innerHTML = '<div id="trendChart"></div>';
    if (d.trend.kind === "days") {
      document.getElementById("trendTitle").textContent = "Spending through the month";
      document.getElementById("trendSub").textContent = "Money out spent so far, against spending the plan evenly";
      charts.push(
        UI.renderTrendChart("trendChart", {
          categories: points.map((x) => x.label),
          series: [
            { name: "Spent so far", data: points.map((x) => x.out_actual) },
            { name: "Even pace", data: points.map((x) => x.out_pace) },
            { name: "Received so far", data: points.map((x) => x.in_actual) },
          ],
          type: "line",
          colors: [UI.cssColor("danger"), UI.cssColor("primary"), UI.cssColor("success")],
          yFormat: (v, full) => (full ? B.money(v) : B.short(v)),
          // The even pace is a dashed guide; dots show the money even on the first day.
          extra: { stroke: { curve: "straight", width: [3, 2, 3], dashArray: [0, 6, 0] }, markers: { size: [4, 0, 4], strokeWidth: 0 } },
        }),
      );
    } else {
      document.getElementById("trendTitle").textContent = "Money in and out, month by month";
      document.getElementById("trendSub").textContent = "Received and spent each month";
      charts.push(
        UI.renderTrendChart("trendChart", {
          categories: points.map((x) => x.label),
          series: [
            { name: "Received", data: points.map((x) => x.in_actual) },
            { name: "Spent", data: points.map((x) => x.out_actual) },
          ],
          type: "bar",
          colors: [UI.cssColor("success"), UI.cssColor("danger")],
          yFormat: (v, full) => (full ? B.money(v) : B.short(v)),
        }),
      );
    }
  }

  // ---------------------------------------------------------------- plan vs actual

  function renderPlanVsActual() {
    const wrap = document.getElementById("pvaSwitchWrap");
    if (!wrap.innerHTML) {
      wrap.innerHTML = UI.renderSegmented("pvaSwitch", [{ value: "out", label: "Out" }, { value: "in", label: "In" }], pvaSide, { ariaLabel: "Money in or out" });
      UI.wireSegmented("pvaSwitch", (value) => {
        pvaSide = value;
        renderPlanVsActual();
      });
    }
    const body = document.getElementById("pvaBody");
    const isOut = pvaSide === "out";
    document.getElementById("pvaSub").textContent = isOut ? "The biggest lines: planned, and spent so far" : "The biggest lines: planned, and received so far";
    const lines = [...(d.lines[pvaSide] || [])].sort((a, b) => Math.max(b.planned, b.actual) - Math.max(a.planned, a.actual)).slice(0, 8);
    const old = charts.find((c) => c?.__pva);
    if (old) {
      old.destroy();
      charts = charts.filter((c) => c !== old);
    }
    if (!lines.length) {
      body.innerHTML = `<div class="list-empty py-5"><span class="list-empty-icon bg-primary text-white"><i class="ri-bar-chart-horizontal-line"></i></span><div class="fw-semibold mt-2">No money ${pvaSide} planned for ${B.esc(d.period.label)}</div></div>`;
      return;
    }
    body.innerHTML = '<div id="pvaChart"></div>';
    const solid = UI.cssColor(isOut ? "primary" : "success");
    const over = UI.cssColor("danger");
    const money = (v) => (v == null ? v : B.money(v));
    const chart = UI.renderTrendChart("pvaChart", {
      categories: lines.map((l) => l.name),
      series: [
        { name: "Planned", data: lines.map((l) => l.planned) },
        { name: isOut ? "Spent" : "Received", data: lines.map((l) => l.actual) },
      ],
      type: "bar",
      // A line that has gone over its plan turns red.
      colors: [UI.withAlpha(solid, 0.35), ({ dataPointIndex }) => (isOut && lines[dataPointIndex] && lines[dataPointIndex].actual > lines[dataPointIndex].planned ? over : solid)],
      extra: {
        chart: { type: "bar", height: Math.max(220, lines.length * 40 + 70), toolbar: { show: false }, foreColor: UI.chartTextColor() },
        plotOptions: { bar: { horizontal: true, barHeight: "70%", borderRadius: 4, distributed: false } },
        xaxis: { categories: lines.map((l) => l.name), labels: { formatter: (v) => B.short(v), style: { colors: UI.chartTextColor(), fontWeight: 600 } } },
        yaxis: { labels: { maxWidth: 160, style: { colors: UI.chartTextColor(), fontWeight: 600 } } },
        tooltip: { shared: true, intersect: false, y: { formatter: money } },
        legend: { show: true, position: "top", horizontalAlign: "right", markers: { fillColors: [UI.withAlpha(solid, 0.35), solid] } },
      },
    });
    if (chart) {
      chart.__pva = true;
      charts.push(chart);
    }
  }

  // ---------------------------------------------------------------- money in by source

  function renderSources() {
    const el = document.getElementById("sourceDonut");
    const lines = d.lines.in || [];
    const received = lines.filter((l) => l.actual > 0);
    const useActual = received.length > 0;
    const rows = (useActual ? received : lines.filter((l) => l.planned > 0)).map((l) => ({ name: l.name, value: useActual ? l.actual : l.planned })).sort((a, b) => b.value - a.value);
    document.getElementById("sourceSub").textContent = useActual ? "Where the money received came from" : "Nothing received yet - this is what's planned";
    if (!rows.length) {
      el.innerHTML = `<div class="list-empty py-5"><span class="list-empty-icon bg-success text-white"><i class="ri-pie-chart-line"></i></span><div class="fw-semibold mt-2">No money in for ${B.esc(d.period.label)}</div><div class="fs-12">Plan or record money in to see where it comes from.</div></div>`;
      return;
    }
    const top = rows.slice(0, 5);
    const rest = rows.slice(5).reduce((a, r) => a + r.value, 0);
    if (rest > 0) top.push({ name: "Other", value: rest });
    el.innerHTML = "";
    charts.push(
      UI.renderRingDonut("sourceDonut", {
        labels: top.map((r) => B.esc(r.name)),
        series: top.map((r) => r.value),
        colors: ["success", "primary", "secondary", "purple", "pink", "danger"],
        centerLabel: useActual ? "Received" : "Planned",
        format: (n) => B.shortMoney(n),
      }),
    );
  }

  // ---------------------------------------------------------------- lines

  function renderSideSwitch() {
    const wrap = document.getElementById("sideSwitchWrap");
    if (wrap.innerHTML) return;
    wrap.innerHTML = UI.renderSegmented("sideSwitch", [{ value: "out", label: "Out" }, { value: "in", label: "In" }], side, { ariaLabel: "Money in or out" });
    UI.wireSegmented("sideSwitch", (value) => {
      side = value;
      showAllLines = false;
      renderLines();
    });
  }

  function renderLines() {
    const el = document.getElementById("lineProgress");
    const lines = d.lines[side] || [];
    document.getElementById("linesSub").textContent = side === "out" ? "Each line: planned, and spent so far" : "Each line: planned, and received so far";
    if (!lines.length) {
      el.innerHTML = `<p class="fw-semibold mb-0">No money ${side} planned or recorded for ${B.esc(d.period.label)}.</p>`;
      return;
    }
    const LIMIT = 6;
    const shown = showAllLines ? lines : lines.slice(0, LIMIT);
    el.innerHTML = `
      <div class="budget-progress">${shown.map((l) => progressRow(l, side)).join("")}</div>
      ${lines.length > LIMIT ? `<button type="button" class="btn btn-sm btn-outline-primary w-100 mt-2" id="linesMoreBtn">${showAllLines ? "Show fewer" : `Show all ${lines.length} lines`}</button>` : ""}`;
    document.getElementById("linesMoreBtn")?.addEventListener("click", () => {
      showAllLines = !showAllLines;
      renderLines();
    });
  }

  function progressRow(l, s) {
    const pct = l.planned > 0 ? (l.actual / l.planned) * 100 : l.actual > 0 ? 100 : 0;
    const over = s === "out" && l.actual > l.planned;
    const color = s === "in" ? "success" : over ? "danger" : pct >= 80 ? "warning" : "primary";
    const verb = s === "in" ? "received" : "spent";
    const status = over
      ? `<span class="text-danger fw-semibold">Over by ${B.money(l.actual - l.planned)}</span>`
      : s === "in"
        ? `${B.money(Math.max(l.left, 0))} still to come`
        : `${B.money(l.left)} left`;
    return `
      <div class="budget-progress-row">
        <div class="budget-progress-top">
          <span class="fw-semibold">${d.budget ? `<a href="${B.url("line.php", { budget: d.budget.id, line: l.line_id })}" class="text-reset">${B.esc(l.name)}</a>` : B.esc(l.name)}${l.is_unplanned ? ' <span class="soft-chip soft-warning">Unplanned</span>' : ""}</span>
          <span class="fw-semibold">${B.money(l.planned)}</span>
        </div>
        <div class="count-bar"><span class="bg-${color}" style="width: ${Math.min(pct, 100)}%"></span></div>
        <div class="budget-progress-foot">
          <span>${l.actual ? `${B.money(l.actual)} ${verb}` : `Nothing ${verb} yet`}</span>
          <span>${status}</span>
        </div>
      </div>`;
  }

  // ---------------------------------------------------------------- recent

  function renderRecent() {
    const el = document.getElementById("recentList");
    if (!d.recent.length) {
      el.innerHTML = `<div class="list-empty py-4"><span class="list-empty-icon bg-primary text-white"><i class="ri-exchange-dollar-line"></i></span><div class="fw-semibold mt-2">Nothing recorded for ${B.esc(d.period.label)}</div></div>`;
      return;
    }
    el.innerHTML = `<ul class="budget-recent">${d.recent.map((e) => recentRow(e)).join("")}</ul>`;
    el.querySelectorAll("[data-entry]").forEach((row) =>
      row.addEventListener("click", () => {
        // Each amount has its own page (Change and Delete are there).
        window.location.href = B.url("entry.php", { id: row.dataset.entry });
      }),
    );
  }

  function recentRow(e) {
    const date = new Date(`${e.entry_date}T00:00:00`);
    const isIn = e.direction === "in";
    return `
      <li data-entry="${e.id}" class="is-clickable">
        <span class="budget-recent-date is-${isIn ? "in" : "out"}"><b>${date.getDate()}</b><small>${date.toLocaleDateString("en-GB", { month: "short" })}</small></span>
        <span class="flex-fill" style="min-width: 0;">
          <span class="d-block fw-semibold text-truncate">${B.esc(e.description)}</span>
          <span class="d-block fs-12 text-truncate">${B.esc(e.line || "")}${e.counterparty ? ` · ${B.esc(e.counterparty)}` : ""}</span>
        </span>
        <span class="fw-bold ${isIn ? "text-success" : "text-danger"}">${isIn ? "+" : "−"}${B.amount(e.amount)}</span>
      </li>`;
  }

  // ---------------------------------------------------------------- record

  function record(direction) {
    if (!d?.budget) return;
    BudgetsEntryModal.open({ budgetId: d.budget.id, direction, onSaved: () => load() });
  }

  return { init };
})();

window.BudgetsOverview = BudgetsOverview;
