/**
 * ============================================================================
 * PAGE - ONE BUDGET (includes/budget/budget.php, every level)
 * ============================================================================
 * Header with what you can do (Change, Start using, Close, Reopen, Delete -
 * only what the API says is allowed), the year's figures, each line's
 * planned amount and how much came in or went out, and the History.
 * Opened from a level above, it's view only. Actions update in place.
 * ============================================================================
 */
const BudgetsDetail = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const id = Number(new URLSearchParams(window.location.search).get("id"));
  let d = null;
  let whereSide = "out";
  let donut = null;
  let monthsChart = null;

  async function init() {
    B.showFlash();
    if (!id) {
      showError("No budget was chosen.");
      return;
    }
    wireActions();
    wireTabs();
    const res = await BudgetsAPI.get(id);
    if (!res.ok) {
      showError(res.message);
      return;
    }
    render(res.data);
    loadHistory();
    loadSpending();
  }

  /** After money is recorded, changed or removed: fresh figures, entries and history. */
  async function refresh() {
    const res = await BudgetsAPI.get(id);
    if (res.ok) render(res.data);
    loadHistory();
    loadSpending();
  }

  // ------------------------------------------------------------------ spending

  async function loadSpending() {
    const el = document.getElementById("budgetSpending");
    const res = await BudgetsAPI.entries({ budget_id: id, territory_id: d?.view_only ? d.budget.place.id : "" });
    const rows = res.ok ? res.data || [] : [];
    document.querySelector('[data-tab-figure="spending"]').textContent = `${rows.length} ${rows.length === 1 ? "entry" : "entries"}`;
    if (res.ok) {
      document.getElementById("spendingChips").innerHTML = `
        <span class="soft-chip soft-success">In ${B.shortMoney(res.body.stats.in)}</span>
        <span class="soft-chip soft-danger">Out ${B.shortMoney(res.body.stats.out)}</span>`;
    }
    if (!rows.length) {
      el.innerHTML = `<div class="list-empty py-4"><span class="list-empty-icon bg-primary text-white"><i class="ri-exchange-dollar-line"></i></span>
        <div class="fw-semibold mt-2">Nothing recorded yet</div>
        <div class="fs-12">${d?.can?.record ? "Press Record money when money comes in or goes out." : d?.budget?.status === "draft" ? "Start using the budget to record money against it." : ""}</div></div>`;
      return;
    }
    const last = rows[0] ? new Date(`${rows[0].entry_date}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "-";
    const net = res.body.stats.in - res.body.stats.out;
    const row = (e) => {
      const date = new Date(`${e.entry_date}T00:00:00`);
      const isIn = e.direction === "in";
      return `
        <li class="is-clickable" data-href="${B.url("entry.php", { id: e.id })}">
          <span class="budget-recent-date is-${isIn ? "in" : "out"}"><b>${date.getDate()}</b><small>${date.toLocaleDateString("en-GB", { month: "short" })}</small></span>
          <span class="flex-fill" style="min-width: 0;">
            <span class="d-block fw-semibold text-truncate">${B.esc(e.description)}</span>
            <span class="d-block fs-12 text-truncate">${B.lineDot(e.line)}${e.counterparty ? ` · ${B.esc(e.counterparty)}` : ""}${e.recorded_by ? ` · ${B.esc(e.recorded_by)}` : ""}</span>
          </span>
          <span class="fw-bold ${isIn ? "text-success" : "text-danger"}">${isIn ? "+" : "−"}${B.amount(e.amount)}</span>
        </li>`;
    };
    // A whole-year budget: entries under their month, with the month's money in and out.
    let list;
    if (d?.budget && !d.budget.period_month) {
      const groups = [];
      rows.forEach((e) => {
        const key = e.entry_date.slice(0, 7);
        if (!groups.length || groups[groups.length - 1].key !== key) groups.push({ key, items: [] });
        groups[groups.length - 1].items.push(e);
      });
      list = groups
        .map((g) => {
          const inn = g.items.filter((e) => e.direction === "in").reduce((t, e) => t + e.amount, 0);
          const out = g.items.filter((e) => e.direction === "out").reduce((t, e) => t + e.amount, 0);
          const label = new Date(`${g.key}-01T00:00:00`).toLocaleDateString("en-GB", { month: "long", year: "numeric" });
          return `
            <div class="budget-month-group" data-month="${Number(g.key.slice(5))}">
              <span class="fw-semibold">${label}</span>
              <span class="fs-12">${g.items.length} ${g.items.length === 1 ? "entry" : "entries"}${inn ? ` · <b class="text-success">+${B.amount(inn)}</b>` : ""}${out ? ` · <b class="text-danger">−${B.amount(out)}</b>` : ""}</span>
            </div>
            <ul class="budget-recent">${g.items.map(row).join("")}</ul>`;
        })
        .join("");
    } else {
      list = `<ul class="budget-recent">${rows.map(row).join("")}</ul>`;
    }
    el.innerHTML = `
      <div class="budget-items-strip soft-primary mx-n3 mt-n3 mb-2">
        <div><small>Money in</small><b class="text-success">${B.money(res.body.stats.in)}</b></div>
        <div><small>Money out</small><b class="text-danger">${B.money(res.body.stats.out)}</b></div>
        <div><small>Difference</small><b class="${net < 0 ? "text-danger" : ""}">${B.money(net)}</b></div>
        <div><small>Last recorded</small><b>${last}</b></div>
      </div>
      ${list}`;
    el.querySelectorAll("li[data-href]").forEach((li) => li.addEventListener("click", () => (window.location.href = li.dataset.href)));
  }


  function render(data) {
    d = data;
    const b = d.budget;
    document.title = `${b.period_label} budget - Makueni West Diocese`;
    document.getElementById("budgetIcon").innerHTML = `<span class="avatar avatar-md bg-${b.period_month ? "primary" : "purple"} text-white"><i class="${b.period_month ? "ri-calendar-line" : "ri-calendar-2-line"}"></i></span>`;
    document.getElementById("budgetTitle").textContent = `${b.period_label} budget`;
    const when = (iso) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");
    const bits = [
      B.esc(b.place?.name || ""),
      B.statusPill(b.status),
      b.prepared_by ? `Prepared by ${B.esc(b.prepared_by)}` : "",
      b.status === "active" && b.started_by ? `In use since ${when(b.started_at)} (${B.esc(b.started_by)})` : "",
      b.status === "closed" && b.closed_by ? `Closed ${when(b.closed_at)} by ${B.esc(b.closed_by)}` : "",
    ].filter(Boolean);
    document.getElementById("budgetSub").innerHTML = bits.join(" · ");

    // Only what's allowed
    const can = d.can || {};
    document.getElementById("editBtn").hidden = !can.edit;
    document.getElementById("editBtn").href = B.url("form.php", { id: b.id });
    document.getElementById("startBtn").hidden = !can.start;
    document.getElementById("closeBtn").hidden = !can.close;
    document.getElementById("reopenBtn").hidden = !can.reopen;
    document.getElementById("deleteBtn").hidden = !can.delete;
    document.getElementById("recordBtn").hidden = !can.record;

    const viewOnly = document.getElementById("viewOnlyBanner");
    viewOnly.classList.toggle("d-none", !d.view_only);
    viewOnly.classList.toggle("d-flex", !!d.view_only);
    if (d.view_only) {
      document.getElementById("viewOnlyText").textContent = `This is ${b.place?.name}'s budget. Only ${b.place?.name} can change it.`;
      document.getElementById("backBtn").href = B.url("budgets.php", { territory_id: b.place.id, year: b.fiscal_year });
    }
    const draft = document.getElementById("draftBanner");
    const showDraft = b.status === "draft" && !d.view_only;
    draft.classList.toggle("d-none", !showDraft);
    draft.classList.toggle("d-flex", showDraft);

    B.syncExport({ key: "budget.statement", territoryId: b.place?.id, budgetId: b.id, title: `${b.period_label} budget` });
    renderStats(b);
    renderDeductions();
    renderMonths();
    renderWhere();
    renderStatusCard(b);
    renderLines("in", d.lines.in);
    renderLines("out", d.lines.out);
    document.querySelector('[data-tab-figure="lines"]').textContent = `${d.lines.in.length + d.lines.out.length} lines`;

    const notesCard = document.getElementById("notesCard");
    notesCard.hidden = !b.notes;
    if (b.notes) document.getElementById("budgetNotes").textContent = b.notes;
  }

  /** "10% of KES 40,000.00 Tithes received = KES 4,000.00 due". */
  function dueSentence(r) {
    if (r.rate_type !== "percentage") return `${B.money(r.due)} due`;
    const on = (r.rule.match(/% of (.+) received$/) || [])[1] || "money";
    const rate = `${Number(r.rate_value).toLocaleString("en-GB", { maximumFractionDigits: 2 })}%`;
    return `${rate} of ${B.money(r.base_received || 0)} ${B.esc(on === "all money" ? "" : on).trim()} received = <span class="text-${r.owed > 0 ? "danger" : "success"}">${B.money(r.due)} due</span>`.replace("  ", " ");
  }

  /** Deductions: what is due on the money actually received (recorded), what was sent, what is still owed - and the plan's estimate. */
  function renderDeductions() {
    const rows = d.deductions || [];
    document.getElementById("deductionsTabBtn").hidden = !rows.length;
    if (!rows.length) return;
    const owed = rows.reduce((t, r) => t + r.owed, 0);
    document.querySelector('[data-tab-figure="deductions"]').textContent = owed > 0 ? `${B.shortMoney(owed)} still owed` : "All sent";
    const fig = (label, value, cls = "") => `<div><small>${label}</small><b class="${cls}">${value}</b></div>`;
    const total = (k) => rows.reduce((t, r) => t + r[k], 0);
    document.getElementById("budgetDeductions").innerHTML = `
      <div class="budget-items-strip soft-warning">
        ${fig("Due on money received", B.money(total("due")))}
        ${fig("Sent", B.money(total("sent")), "text-success")}
        ${fig("Still owed", B.money(owed), owed > 0 ? "text-danger" : "")}
        ${fig("Estimate from the plan", B.money(total("planned")))}
      </div>
      <ul class="budget-items">${rows
        .map((r) => {
          const pct = r.due > 0 ? Math.min(100, (r.sent / r.due) * 100) : r.sent > 0 ? 100 : 0;
          const href = r.line_id ? B.url("line.php", { budget: id, line: r.line_id }) : "";
          return `
            <li class="budget-item${href ? " is-clickable" : ""}" ${href ? `data-href="${href}"` : ""}>
              <span class="avatar avatar-md bg-purple text-white flex-shrink-0"><i class="ri-percent-line"></i></span>
              <div class="budget-item-main">
                <div class="budget-item-top">
                  <span class="budget-item-name">${B.esc(r.name)} <span class="soft-chip soft-purple">${B.esc(r.set_by)}</span></span>
                  ${r.owed > 0 ? `<span class="badge bg-danger">${B.money(r.owed)} owed</span>` : r.due > 0 ? '<span class="badge bg-success">All sent</span>' : '<span class="soft-chip soft-primary">Nothing due yet</span>'}
                </div>
                <div class="fw-semibold mb-1">${dueSentence(r)}</div>
                <div class="fs-12 mb-1">${B.esc(r.rule)} · paid through ${href ? `<a href="${href}">${B.esc(r.line || "")}</a>` : B.esc(r.line || "-")}</div>
                <div class="count-bar"><span class="bg-${r.owed > 0 ? "warning" : "success"}" style="width: ${pct}%"></span></div>
                <div class="budget-item-figs">
                  <span>Due <b>${B.money(r.due)}</b></span>
                  <span>Sent <b>${B.money(r.sent)}</b></span>
                  <span>Estimate <b>${B.money(r.planned)}</b></span>
                </div>
              </div>
            </li>`;
        })
        .join("")}</ul>
      <div class="budget-items-total"><span>Due is worked out on the money actually received - what is recorded. The estimate comes from the plan.</span>${d.can?.record && owed > 0 ? '<button type="button" class="btn btn-sm btn-primary" id="sendDeductionBtn"><i class="ri-send-plane-line me-1"></i>Record what was sent</button>' : ""}</div>`;
    document.querySelectorAll("#budgetDeductions .budget-item[data-href]").forEach((li) =>
      li.addEventListener("click", (ev) => {
        if (!ev.target.closest("a, button")) window.location.href = li.dataset.href;
      }),
    );
    const owedRow = rows.find((r) => r.owed > 0);
    document.getElementById("sendDeductionBtn")?.addEventListener("click", () => BudgetsEntryModal.open({ budgetId: id, lineId: owedRow.line_id, direction: "out", onSaved: refresh }));
  }

  /** A whole-year budget: money in and out per month, by the date each amount was recorded. */
  function renderMonths() {
    const card = document.getElementById("monthsCard");
    const months = d.months;
    card.hidden = !months;
    monthsChart?.destroy?.();
    if (!months) return;
    const inT = months.reduce((t, m) => t + m.in, 0);
    const outT = months.reduce((t, m) => t + m.out, 0);
    const busy = months.filter((m) => m.count).length;
    document.getElementById("monthsChips").innerHTML = `
      <span class="soft-chip soft-success">In ${B.shortMoney(inT)}</span>
      <span class="soft-chip soft-danger">Out ${B.shortMoney(outT)}</span>
      <span class="soft-chip soft-primary">${busy} of 12 months with money</span>`;
    monthsChart = UI.renderTrendChart("monthsChart", {
      categories: months.map((m) => m.label),
      series: [
        { name: "Received", data: months.map((m) => m.in) },
        { name: "Spent", data: months.map((m) => m.out) },
      ],
      type: "bar",
      colors: [UI.cssColor("success"), UI.cssColor("danger")],
      yFormat: (v, full) => (full ? B.money(v) : B.short(v)),
      extra: { chart: { type: "bar", height: 240, toolbar: { show: false }, foreColor: UI.chartTextColor() } },
    });
    document.getElementById("monthsStrip").innerHTML = months
      .map(
        (m) => `
        <button type="button" class="budget-month-cell${m.count ? " has-money" : ""}" data-month="${m.month}" ${m.count ? "" : "disabled"}>
          <span>${m.label}</span>
          <b class="${m.in - m.out < 0 ? "text-danger" : m.count ? "text-success" : ""}">${m.count ? B.short(m.in - m.out) : "-"}</b>
          <small>${m.count ? `${m.count} ${m.count === 1 ? "entry" : "entries"}` : "nothing"}</small>
        </button>`,
      )
      .join("");
    document.querySelectorAll("#monthsStrip [data-month]").forEach((btn) =>
      btn.addEventListener("click", async () => {
        document.querySelector('#budgetTabs [data-tab="spending"]')?.click();
        setTimeout(() => {
          const group = document.querySelector(`#budgetSpending .budget-month-group[data-month="${btn.dataset.month}"]`);
          group?.scrollIntoView({ behavior: "smooth", block: "start" });
          group?.classList.add("is-flash");
          setTimeout(() => group?.classList.remove("is-flash"), 1600);
        }, 350);
      }),
    );
  }

  function renderStats(b) {
    const left = b.in_planned - b.out_planned;
    const prev = d.previous;
    const vs = prev?.period_label;
    const pct = (a, p) => (p > 0 ? `${Math.round((a / p) * 100)}% of planned` : "");
    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-arrow-down-circle-line",
        label: "Money in (planned)",
        value: B.money(b.in_planned),
        color: "success",
        delta: prev ? B.delta(b.in_planned, prev.in_planned, vs) : null,
        sub: b.in_actual ? `Received ${B.money(b.in_actual)} · ${pct(b.in_actual, b.in_planned)}` : "Nothing received yet",
      },
      {
        icon: "ri-arrow-up-circle-line",
        label: "Money out (planned)",
        value: B.money(b.out_planned),
        color: "danger",
        delta: prev ? B.delta(b.out_planned, prev.out_planned, vs) : null,
        sub: b.out_actual ? `Spent ${B.money(b.out_actual)} · ${pct(b.out_actual, b.out_planned)}` : "Nothing spent yet",
      },
      {
        icon: "ri-scales-3-line",
        label: "Money left (planned)",
        value: B.money(left),
        color: left < 0 ? "danger" : "purple",
        delta: prev ? B.delta(left, prev.left_planned, vs) : null,
        sub: left < 0 ? "Planning to spend more than comes in" : "Money in minus money out",
      },
      {
        icon: "ri-list-check-2",
        label: "Lines",
        value: d.lines.in.length + d.lines.out.length,
        color: "primary",
        sub: `${d.lines.in.length} money in · ${d.lines.out.length} money out`,
      },
    ]);
  }

  /** "Where the money goes" - the top five lines of this budget and the rest. */
  function renderWhere() {
    const wrap = document.getElementById("whereSwitchWrap");
    if (!wrap.innerHTML) {
      wrap.innerHTML = UI.renderSegmented("whereSwitch", [{ value: "out", label: "Out" }, { value: "in", label: "In" }], whereSide, { ariaLabel: "Money in or out" });
      UI.wireSegmented("whereSwitch", (value) => {
        whereSide = value;
        renderWhere();
      });
    }
    const lines = [...(d.lines[whereSide] || [])].sort((a, b) => b.planned - a.planned);
    document.getElementById("whereSub").textContent = whereSide === "out" ? "This budget's money out, by line" : "Where this budget's money in comes from";
    donut?.destroy?.();
    if (!lines.length) {
      document.getElementById("whereDonut").innerHTML = `<p class="fw-semibold mb-0">No money ${whereSide} planned.</p>`;
      return;
    }
    donut = B.moneyDonut("whereDonut", {
      rows: lines.map((l) => ({ name: l.name, value: l.planned })),
      colors: whereSide === "out" ? ["danger", "warning", "purple", "pink", "primary", "secondary"] : ["success", "primary", "purple", "warning", "pink", "secondary"],
      centerLabel: whereSide === "out" ? "Money out" : "Money in",
    });
  }

  /** "This budget": how far into its period we are, the in/out split and the facts. */
  function renderStatusCard(b) {
    const start = new Date(`${b.start_date}T00:00:00`);
    const end = new Date(`${b.end_date}T23:59:59`);
    const now = new Date();
    const totalDays = Math.round((end - start) / 86400000);
    const gone = Math.min(totalDays, Math.max(0, Math.ceil((now - start) / 86400000)));
    const periodText = now < start ? `Starts ${start.toLocaleDateString("en-GB", { day: "numeric", month: "short" })}` : now > end ? "This period has ended" : `${gone} of ${totalDays} days gone`;
    const pct = totalDays ? Math.round((gone / totalDays) * 100) : 0;
    const when = (iso) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "-");
    const both = b.in_planned + b.out_planned;
    const facts = [
      ["Status", B.statusPill(b.status)],
      ["Prepared by", B.esc(b.prepared_by || "-")],
      b.status !== "draft" ? ["In use since", `${when(b.started_at)}${b.started_by ? ` · ${B.esc(b.started_by)}` : ""}`] : null,
      b.status === "closed" ? ["Closed", `${when(b.closed_at)}${b.closed_by ? ` · ${B.esc(b.closed_by)}` : ""}`] : null,
      d.previous ? ["Budget before", `<a href="${B.url("budget.php", { id: d.previous.id })}">${B.esc(d.previous.period_label)}</a>`] : null,
    ].filter(Boolean);
    document.getElementById("statusSub").textContent = `${b.period_label} · ${B.esc(b.place?.name || "")}`;
    document.getElementById("statusCard").innerHTML = `
      <div class="d-flex align-items-end justify-content-between gap-2">
        <div><span class="composition-total">${now > end ? "100" : now < start ? "0" : pct}%</span> <span class="kpi-caption">of the period gone</span></div>
        <span class="soft-chip soft-primary">${periodText}</span>
      </div>
      <div class="count-bar my-2"><span class="bg-primary" style="width: ${now > end ? 100 : now < start ? 0 : pct}%"></span></div>
      <div class="d-flex justify-content-between fs-12 fw-semibold mb-1 mt-3"><span class="text-success">Money in ${B.shortMoney(b.in_planned)}</span><span class="text-danger">Money out ${B.shortMoney(b.out_planned)}</span></div>
      <div class="count-bar composition-bar" aria-hidden="true"><span class="bg-success" style="width: ${both ? (b.in_planned / both) * 100 : 50}%"></span><span class="bg-danger" style="width: ${both ? (b.out_planned / both) * 100 : 50}%"></span></div>
      <ul class="list-unstyled mb-0 mt-3 budget-facts">
        ${facts.map(([k, v]) => `<li><span>${k}</span><span class="fw-semibold">${v}</span></li>`).join("")}
      </ul>`;
  }

  /**
   * One card per side: a tinted strip with the side's figures, then a row per
   * line (icon, name, change chip, % pill, bar, planned / received or spent /
   * left), and a tinted totals row.
   */
  function renderLines(side, lines) {
    const el = document.getElementById(side === "in" ? "linesIn" : "linesOut");
    const isIn = side === "in";
    const verb = isIn ? "Received" : "Spent";
    const planned = lines.reduce((t, l) => t + l.planned, 0);
    const actual = lines.reduce((t, l) => t + l.actual, 0);
    const pctOf = (a, p) => (p > 0 ? Math.round((a / p) * 100) : a > 0 ? 100 : 0);
    document.getElementById(isIn ? "inChip" : "outChip").innerHTML = `<b>${B.money(planned)}</b>`;
    if (!lines.length) {
      el.innerHTML = `<div class="list-empty py-4"><span class="list-empty-icon bg-${isIn ? "success" : "danger"} text-white"><i class="ri-list-check-2"></i></span><div class="fw-semibold mt-2">No money ${isIn ? "in" : "out"} planned</div></div>`;
      return;
    }
    const left = planned - actual;
    const fig = (label, value, cls = "") => `<div><small>${label}</small><b class="${cls}">${value}</b></div>`;
    const strip = `
      <div class="budget-items-strip soft-${isIn ? "success" : "danger"}">
        ${fig("Planned", B.money(planned))}
        ${fig(verb, B.money(actual))}
        ${fig(isIn ? "Still to come" : left < 0 ? "Over by" : "Left", B.money(Math.abs(isIn ? Math.max(left, 0) : left)), !isIn && left < 0 ? "text-danger" : "")}
        ${fig("Done", `${pctOf(actual, planned)}%`)}
      </div>`;
    const callout = !actual
      ? `<div class="budget-items-callout">
          <i class="ri-information-line"></i>
          <span>${isIn ? "Nothing received yet." : "Nothing spent yet."} ${d.can?.record ? "Record money as it comes in or goes out, and each line fills up." : d.budget.status === "draft" ? "Money can be recorded once the budget is in use." : ""}</span>
          ${d.can?.record ? `<button type="button" class="btn btn-sm btn-primary ms-auto flex-shrink-0" data-record-side="${side}"><i class="ri-add-line me-1"></i>Record money</button>` : ""}
        </div>`
      : "";
    const rows = lines
      .map((l, i) => {
        const pct = pctOf(l.actual, l.planned);
        const over = !isIn && l.actual > l.planned;
        const color = isIn ? "success" : over ? "danger" : pct >= 80 ? "warning" : "primary";
        const leftText = over ? `<span class="text-danger">Over ${B.money(l.actual - l.planned)}</span>` : B.money(Math.max(l.left, 0));
        return `
          <li class="budget-item is-clickable" data-href="${B.url("line.php", { budget: id, line: l.line_id })}" title="Open ${B.esc(l.name)}">
            <span class="avatar avatar-md bg-${B.lineColor(side, i)} ${B.tileText(B.lineColor(side, i))} flex-shrink-0"><i class="${B.lineIcon(l.name, side)}"></i></span>
            <div class="budget-item-main">
              <div class="budget-item-top">
                <span class="budget-item-name"><a href="${B.url("line.php", { budget: id, line: l.line_id })}" class="text-reset">${B.esc(l.name)}</a>${l.is_unplanned ? ' <span class="soft-chip soft-warning">Unplanned</span>' : ""}${l.is_own ? ' <span class="soft-chip soft-primary">Ours</span>' : ""}${changeChip(l)}</span>
                ${l.actual ? `<span class="badge bg-${color}">${pct}%</span>` : `<span class="soft-chip soft-primary">0%</span>`}
              </div>
              <div class="count-bar"><span class="bg-${color}" style="width: ${Math.min(pct, 100)}%"></span></div>
              <div class="budget-item-figs">
                <span>Planned <b>${B.money(l.planned)}</b></span>
                <span>${verb} <b>${B.money(l.actual)}</b></span>
                <span>${isIn ? "To come" : "Left"} <b>${leftText}</b></span>
              </div>
            </div>
          </li>`;
      })
      .join("");
    el.innerHTML = `
      ${strip}
      ${callout}
      <ul class="budget-items">${rows}</ul>
      <div class="budget-items-total">
        <span>${lines.length} ${lines.length === 1 ? "line" : "lines"}</span>
        <span>Planned <b>${B.money(planned)}</b> · ${verb} <b>${B.money(actual)}</b></span>
      </div>`;
    el.querySelector("[data-record-side]")?.addEventListener("click", () => BudgetsEntryModal.open({ budgetId: id, direction: side, onSaved: refresh }));
    el.querySelectorAll(".budget-item[data-href]").forEach((li) =>
      li.addEventListener("click", (ev) => {
        if (!ev.target.closest("a, button")) window.location.href = li.dataset.href;
      }),
    );
  }

  /** A small "+10% vs Dec" / "New" chip against the budget before. */
  function changeChip(line) {
    const prev = d.previous?.lines?.[line.line_id];
    if (!d.previous) return "";
    if (prev === undefined) return ' <span class="soft-chip soft-purple">New</span>';
    if (!prev || Math.abs(line.planned - prev) < 0.005) return "";
    const pct = Math.round(((line.planned - prev) / prev) * 100);
    const short = d.previous.period_label.split(" ")[0].slice(0, 3);
    return ` <span class="soft-chip soft-${pct > 0 ? "warning" : "primary"}">${pct > 0 ? "+" : ""}${pct}% vs ${short}</span>`;
  }

  // ------------------------------------------------------------------ history

  async function loadHistory() {
    const el = document.getElementById("budgetHistory");
    el.innerHTML = '<span class="skel" style="height: 6rem; display: block;"></span>';
    const res = await BudgetsAPI.history(id);
    const items = res.ok ? res.data || [] : [];
    document.querySelector('[data-tab-figure="history"]').textContent = `${items.length} ${items.length === 1 ? "entry" : "entries"}`;
    if (!items.length) {
      el.innerHTML = '<p class="fw-semibold mb-0">Nothing recorded yet.</p>';
      return;
    }
    const changes = items.filter((h) => !String(h.action).startsWith("entry_")).length;
    el.innerHTML = `
      <div class="budget-items-strip soft-purple mx-n3 mt-n3 mb-3">
        <div><small>Everything</small><b>${items.length}</b></div>
        <div><small>Budget changes</small><b>${changes}</b></div>
        <div><small>Money entries</small><b>${items.length - changes}</b></div>
        <div><small>Latest</small><b>${B.esc(items[0].when_label || "-")}</b></div>
      </div>
      ${B.timeline(items)}`;
  }

  // ------------------------------------------------------------------ actions

  function wireActions() {
    document.getElementById("recordBtn").addEventListener("click", () => BudgetsEntryModal.open({ budgetId: id, onSaved: refresh }));
    const act = (btnId, call, confirm) => {
      document.getElementById(btnId).addEventListener("click", (e) => {
        const btn = e.currentTarget;
        const run = async () => {
          UI.setButtonLoading(btn, "Working...");
          const res = await call(id);
          UI.restoreButton(btn);
          if (!res.ok) {
            Toast.error(res.message);
            return;
          }
          Toast.success(res.message);
          render(res.data);
          loadHistory();
        };
        confirm ? Toast.confirm(confirm.message(), run, null, confirm.options) : run();
      });
    };

    act("startBtn", BudgetsAPI.start, {
      message: () => `Start using the ${d.budget.period_label} budget? You can still change it afterwards.`,
      options: { title: "Start using", confirmText: "Start using", type: "success" },
    });
    act("closeBtn", BudgetsAPI.close, {
      message: () => `Close the ${d.budget.period_label} budget? It can't be changed while closed, but you can reopen it.`,
      options: { title: "Close budget", confirmText: "Close", type: "warning" },
    });
    act("reopenBtn", BudgetsAPI.reopen, null);

    document.getElementById("deleteBtn").addEventListener("click", () => {
      Toast.confirm(
        `Delete the draft ${d.budget.period_label} budget?`,
        async () => {
          const res = await BudgetsAPI.remove(id);
          if (!res.ok) {
            Toast.error(res.message);
            return;
          }
          B.flash(res.message);
          window.location.href = B.url("budgets.php", { year: d.budget.fiscal_year });
        },
        null,
        { title: "Delete draft", confirmText: "Delete", type: "error" },
      );
    });
  }

  function wireTabs() {
    const wanted = new URLSearchParams(window.location.search).get("tab");
    if (wanted) document.querySelector(`#budgetTabs [data-tab="${wanted}"]`)?.click();
    document.querySelectorAll("#budgetTabs [data-tab]").forEach((btn) =>
      btn.addEventListener("shown.bs.tab", () => {
        const p = new URLSearchParams(window.location.search);
        p.set("tab", btn.dataset.tab);
        history.replaceState(null, "", `${window.location.pathname}?${p}`);
      }),
    );
  }

  function showError(message) {
    document.getElementById("budgetTitle").textContent = "Budget not available";
    document.getElementById("budgetIcon").innerHTML = '<span class="avatar avatar-md bg-danger text-white"><i class="ri-error-warning-line"></i></span>';
    document.getElementById("budgetSub").textContent = message;
    ["statCardsRow", "linesIn", "linesOut"].forEach((elId) => (document.getElementById(elId).innerHTML = ""));
    document.getElementById("budgetTabs").hidden = true;
    document.querySelector(".section-tab-content").hidden = true;
  }

  return { init };
})();

window.BudgetsDetail = BudgetsDetail;
