/**
 * ============================================================================
 * ACCOUNTING - one account's page (account.php?id=) - Redesign R2
 * ============================================================================
 * What an account holds and what moved through it: the balance in full,
 * money in and out this month and this year (against last month), twelve
 * months as a chart, the latest movements (each opens its document), when it
 * was last reconciled, and the budget lines that post to it. "In" is what
 * makes the account bigger (received for income, spent for an expense).
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const UI = DemographicsUI;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const ID = Number(new URLSearchParams(window.location.search).get("id"));
  const TYPE_COLOR = { asset: "primary", liability: "danger", fund: "warning", income: "success", expense: "purple" };
  let chart = null;

  const card = (icon, title, body, { color = "primary", extra = "", sub = "" } = {}) =>
    `<div class="card custom-card acc-rec-card"><div class="card-header"><div><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-${color}"><i class="${icon}"></i></span>${esc(title)}</div>${sub ? `<span class="card-subtitle-text">${esc(sub)}</span>` : ""}</div>${extra}</div><div class="card-body">${body}</div></div>`;

  function hero(d) {
    const a = d.account;
    const color = a.cash_kind ? A.kind(a.cash_kind).color : TYPE_COLOR[a.type] || "primary";
    const icon = a.cash_kind ? A.kind(a.cash_kind).icon : { income: "ri-arrow-down-circle-line", expense: "ri-arrow-up-circle-line", liability: "ri-scales-3-line", fund: "ri-safe-2-line" }[a.type] || "ri-book-2-line";
    const rec = d.reconciled;
    const facts = [
      ["Code", esc(a.code)],
      ["Kind", esc(a.kind_label || a.type_label)],
      a.bank_name ? ["Bank", esc(`${a.bank_name}${a.branch ? ` · ${a.branch}` : ""}`)] : a.number_masked ? ["Number", esc(a.number_masked)] : ["Type", esc(a.type_label)],
      a.cash_kind ? ["Last reconciled", rec ? `${A.dateChip(rec.date)} <span class="badge bg-${rec.status === "approved" ? "success" : "warning"} ${A.textOn(rec.status === "approved" ? "success" : "warning")}">${esc(rec.status === "approved" ? "Approved" : rec.status)}</span>` : '<span class="badge bg-warning text-dark">Never</span>'] : ["Ours", a.own ? "Our own account" : "Standard diocese account"],
    ];
    return `<div class="card custom-card acc-rec-hero"><div class="card-body">
      <div class="acc-rec-top">
        <span class="avatar avatar-lg avatar-rounded bg-${color} ${A.textOn(color)} flex-shrink-0"><i class="${icon} fs-22"></i></span>
        <div class="min-w-0 flex-fill"><span class="acc-rec-kind">${esc(a.type_label || "Account")}${a.number_masked ? ` · ${esc(a.number_masked)}` : ""}</span><h4 class="acc-rec-number">${esc(a.name)}</h4><p class="acc-rec-title">${esc(a.description || (a.cash_kind ? "Money the church holds here" : "An account in the books"))}</p>${a.is_active ? "" : '<span class="badge bg-secondary">Switched off</span>'}</div>
        <div class="acc-rec-amount"><span>Balance today</span>${A.figure(d.balance)}</div>
      </div>
      <div class="acc-rec-facts">${facts.map(([k, v]) => `<div><span>${esc(k)}</span><strong>${v || "-"}</strong></div>`).join("")}</div>
    </div></div>`;
  }

  function stats(d) {
    const s = d.series;
    const L = d.labels;
    K.statRow($("acStats"), [
      { icon: "ri-arrow-down-circle-line", label: `${L.in} this month`, sub: "vs last month", value: A.figure(d.this_month.in), color: "success", delta: UI.periodDelta(d.this_month.in, d.last_month.in), series: { labels: s.labels, data: s.in }, trim: true },
      { icon: "ri-arrow-up-circle-line", label: `${L.out} this month`, sub: "vs last month", value: A.figure(d.this_month.out), color: "danger", delta: UI.periodDelta(d.this_month.out, d.last_month.out), series: { labels: s.labels, data: s.out }, trim: true },
      { icon: "ri-calendar-check-line", label: `${L.in} this year`, sub: `Since 1 January ${new Date().getFullYear()}`, value: A.figure(d.this_year.in), color: "primary" },
      { icon: "ri-calendar-2-line", label: `${L.out} this year`, sub: `Since 1 January ${new Date().getFullYear()}`, value: A.figure(d.this_year.out), color: "purple" },
    ]);
  }

  function drawChart(d) {
    chart?.destroy();
    const s = d.series;
    const css = getComputedStyle(document.documentElement);
    const rgb = (v) => `rgb(${css.getPropertyValue(v).trim()})`;
    chart = new ApexCharts($("acChart"), {
      chart: { type: "bar", height: 280, toolbar: { show: false }, fontFamily: "Inter, sans-serif" },
      series: [
        { name: d.labels.in, data: s.in },
        { name: d.labels.out, data: s.out },
      ],
      colors: [rgb("--success-rgb"), rgb("--danger-rgb")],
      plotOptions: { bar: { columnWidth: "55%", borderRadius: 3 } },
      dataLabels: { enabled: false },
      xaxis: { categories: s.labels.map((l) => l.split(" ")[0]), axisBorder: { show: false } },
      yaxis: { min: 0, max: Math.max(...s.in, ...s.out) > 0 ? undefined : 1000, tickAmount: 4, labels: { formatter: (v) => (!Number.isFinite(v) ? "" : Math.round(v).toLocaleString("en-GB")) } },
      grid: { borderColor: "rgba(0,0,0,0.06)", strokeDashArray: 3 },
      legend: { position: "top", horizontalAlign: "right" },
      tooltip: { y: { formatter: (v) => A.money(v) } },
    });
    chart.render();
  }

  function budgetLines(d) {
    if (!d.budget_lines.length) return '<p class="acc-muted-line mb-0">No budget line posts to this account.</p>';
    const head = d.budget ? `<p class="acc-sub mb-3">Planned and actual from <strong>${esc(d.budget.name || "the budget in use")}</strong>.</p>` : '<p class="acc-sub mb-3">No budget is in use today - these lines post here when one is.</p>';
    return (
      head +
      `<div class="acc-top">${d.budget_lines
        .map((l) => {
          const pct = l.planned ? Math.min(100, Math.round((l.actual / l.planned) * 100)) : 0;
          const over = l.planned && l.actual > l.planned;
          return `<div class="acc-top-row"><div class="d-flex justify-content-between gap-2"><span class="fw-semibold text-truncate">${esc(l.name)}</span>${l.planned !== null ? `<strong>${A.money(l.actual)} <small class="acc-sub">of ${A.money(l.planned)}</small></strong>` : ""}</div>${l.planned !== null ? `<div class="progress progress-xs"><div class="progress-bar bg-${over ? "danger" : "success"}" style="width:${pct}%"></div></div>` : ""}</div>`;
        })
        .join("")}</div><a class="btn btn-sm btn-outline-primary mt-3" href="${CTX.budgetsUrl}/"><i class="ri-wallet-3-line me-1"></i>Budgets</a>`
    );
  }

  function movements(d) {
    if (!d.movements.length) return A.empty("ri-exchange-line", "Nothing moved yet", "Receipts, payments and transfers on this account show here.");
    return `<div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>Date</th><th>Document</th><th class="d-none d-md-table-cell">Against</th><th class="text-end">${esc(d.labels.in)}</th><th class="text-end">${esc(d.labels.out)}</th></tr></thead><tbody>${d.movements
      .map(
        (m) => `<tr data-id="${m.journal_id}"><td class="text-nowrap">${A.day(m.date, { day: "numeric", month: "short", year: "numeric" })}</td><td><div class="d-flex align-items-center gap-2">${A.docTile(m.doc_type)}<div class="min-w-0"><div class="fw-semibold">${esc(m.number)}${m.reversed ? ` ${A.reversedChip()}` : ""}</div><div class="acc-sub text-truncate">${esc(m.party || m.details || "")}</div></div></div></td><td class="d-none d-md-table-cell"><span class="acc-sub">${esc(m.against.join(", "))}</span></td><td class="text-end">${m.in ? `<strong class="text-success">${A.amount(m.in)}</strong>` : ""}</td><td class="text-end">${m.out ? `<strong class="text-danger">${A.amount(m.out)}</strong>` : ""}</td></tr>`,
      )
      .join("")}</tbody></table></div>`;
  }

  async function load() {
    const res = await API().account(ID);
    if (!res.ok) {
      $("acApp").innerHTML = A.errorBox(res.message);
      return;
    }
    const d = res.data;
    const a = d.account;
    document.title = `${a.name} - Accounting - Makueni West Diocese`;
    const crumb = document.querySelector(".breadcrumb-item.active");
    if (crumb) crumb.textContent = a.name;
    A.placeLine($("accPlaceLine"), d.place);
    if (a.cash_kind) {
      $("acCashbook").href = A.link("cashbook.php", { account_id: a.id });
      $("acCashbook").hidden = false;
    }
    const last = d.movements.length ? `<a class="btn btn-sm btn-outline-primary" href="${a.cash_kind ? A.link("cashbook.php", { account_id: a.id, from: `${new Date().getFullYear()}-01-01` }) : A.link("documents.php")}"><i class="ri-book-open-line me-1"></i>${a.cash_kind ? "Full cashbook" : "All documents"}</a>` : "";
    $("acApp").innerHTML = `${hero(d)}
      <div class="row" id="acStats"></div>
      <div class="row">
        <div class="col-xl-8">${card("ri-bar-chart-2-line", `${d.labels.in} and ${d.labels.out.toLowerCase()}`, '<div id="acChart" class="acc-chart"></div>', { color: "success", sub: "The last 12 months" })}</div>
        <div class="col-xl-4">${card("ri-wallet-3-line", "Budget lines using it", budgetLines(d), { color: "warning" })}</div>
      </div>
      ${card("ri-exchange-line", "Latest movements", movements(d), { color: "purple", extra: last, sub: "The 15 most recent - open one for its lines and papers" })}`;
    stats(d);
    drawChart(d);
  }

  const API = () => AccountingAPI;

  function init() {
    $("acApp").addEventListener("click", (e) => {
      const tr = e.target.closest("tr[data-id]");
      if (tr) W.viewJournal(Number(tr.dataset.id), { onChange: load });
    });
    if (!ID) {
      $("acApp").innerHTML = A.errorBox("Pick an account on Cash & bank.");
      return;
    }
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
