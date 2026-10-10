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
  let data = null;

  const card = (icon, title, body, { color = "primary", extra = "", sub = "", flex = false } = {}) =>
    `<div class="card custom-card acc-rec-card${flex ? " flex-fill" : ""}"><div class="card-header"><div><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-${color}"><i class="${icon}"></i></span>${esc(title)}</div>${sub ? `<span class="card-subtitle-text">${esc(sub)}</span>` : ""}</div>${extra}</div><div class="card-body">${body}</div></div>`;

  function hero(d) {
    const a = d.account;
    const color = a.cash_kind ? A.kind(a.cash_kind).color : TYPE_COLOR[a.type] || "primary";
    const icon = a.cash_kind ? A.kind(a.cash_kind).icon : { income: "ri-arrow-down-circle-line", expense: "ri-arrow-up-circle-line", liability: "ri-scales-3-line", fund: "ri-safe-2-line" }[a.type] || "ri-book-2-line";
    const rec = d.reconciled;
    const facts = [
      ["Code", esc(a.code)],
      ["Kind", a.cash_kind ? A.methodChip(a.cash_kind === "petty_cash" ? "cash" : a.cash_kind, a.kind_label) : esc(a.type_label)],
      a.bank_name ? ["Bank", esc(`${a.bank_name}${a.branch ? ` · ${a.branch}` : ""}`)] : a.number_masked ? ["Number", esc(a.number_masked)] : ["Type", esc(a.type_label)],
      a.cash_kind ? ["Last reconciled", rec ? `${A.dateChip(rec.date)} <span class="badge bg-${rec.status === "approved" ? "success" : "warning"} ${A.textOn(rec.status === "approved" ? "success" : "warning")}">${esc(rec.status === "approved" ? "Approved" : rec.status)}</span>` : '<span class="badge bg-warning text-dark">Never</span>'] : ["Ours", a.own ? "Our own account" : "Standard diocese account"],
    ];
    const tileHtml = ["mpesa", "airtel"].includes(a.cash_kind) ? A.tile(a.cash_kind, "lg") : `<span class="avatar avatar-lg avatar-rounded bg-${color} ${A.textOn(color)} flex-shrink-0"><i class="${icon} fs-22"></i></span>`;
    return `<div class="card custom-card acc-rec-hero"><div class="card-body">
      <div class="acc-rec-top">
        ${tileHtml}
        <div class="min-w-0 flex-fill"><span class="acc-rec-kind">${esc(a.type_label || "Account")}${a.number_masked ? ` · ${esc(a.number_masked)}` : ""}</span><h4 class="acc-rec-number">${esc(a.name)}</h4><p class="acc-rec-title">${esc(a.description || (a.cash_kind ? "Money the church holds here" : "An account in the books"))}</p>${a.is_active ? "" : '<span class="badge bg-secondary">Switched off</span>'}</div>
        <div class="acc-rec-amount"><span>Balance today</span>${A.figure(d.balance)}</div>
      </div>
      <div class="acc-rec-facts">${facts.map(([k, v]) => `<div><span>${esc(k)}</span><strong>${v || "-"}</strong></div>`).join("")}</div>
    </div></div>`;
  }

  function stats(d) {
    const s = d.series;
    const L = d.labels;
    const yIn = d.this_year.in;
    const yOut = d.this_year.out;
    const net = yIn - yOut;
    const share = yIn ? Math.min(100, Math.round((yOut / yIn) * 100)) : 0;
    K.statRow($("acStats"), [
      { icon: "ri-arrow-down-circle-line", label: `${L.in} this month`, sub: "vs last month", value: A.figure(d.this_month.in), color: "success", delta: UI.periodDelta(d.this_month.in, d.last_month.in), series: { labels: s.labels, data: s.in }, trim: true },
      { icon: "ri-arrow-up-circle-line", label: `${L.out} this month`, sub: "vs last month", value: A.figure(d.this_month.out), color: "danger", delta: UI.periodDelta(d.this_month.out, d.last_month.out), series: { labels: s.labels, data: s.out }, trim: true },
      { icon: "ri-calendar-check-line", label: `${L.in} this year`, sub: `Since 1 January ${new Date().getFullYear()}`, value: A.figure(yIn), color: "primary", series: { labels: s.labels, data: s.in }, trim: true },
      { icon: "ri-calendar-2-line", label: `${L.out} this year`, sub: `Net ${A.money(net)}`, value: A.figure(yOut), color: "purple", bar: { pct: share, text: `${share}% of ${L.in.toLowerCase()}` } },
    ]);
  }

  /** The year in a few lines: net, monthly averages, the biggest month, how many movements, the last one. */
  function glance(d) {
    const s = d.series;
    const year = String(new Date().getFullYear());
    const idx = s.months.map((m, i) => (m.startsWith(year) ? i : -1)).filter((i) => i >= 0);
    const months = Math.max(1, idx.length);
    const net = d.this_year.in - d.this_year.out;
    const best = idx.reduce((b, i) => (s.in[i] > (b === null ? -1 : s.in[b]) ? i : b), null);
    const last = d.movements[0];
    const row = (icon, color, label, value) => `<div class="acc-glance-row"><span class="avatar avatar-sm avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon}"></i></span><span class="flex-fill">${esc(label)}</span><strong>${value}</strong></div>`;
    return `<div class="acc-glance">
      ${row("ri-scales-3-line", net >= 0 ? "success" : "danger", "Net this year", `<span class="${net >= 0 ? "text-success" : "text-danger"}">${A.money(net)}</span>`)}
      ${row("ri-arrow-down-line", "success", `Average ${d.labels.in.toLowerCase()} a month`, A.money(d.this_year.in / months))}
      ${row("ri-arrow-up-line", "danger", `Average ${d.labels.out.toLowerCase()} a month`, A.money(d.this_year.out / months))}
      ${best !== null && s.in[best] > 0 ? row("ri-trophy-line", "warning", `Biggest month (${s.labels[best]})`, A.money(s.in[best])) : ""}
      ${row("ri-exchange-line", "purple", "Movements this year", A.num(d.year_count))}
      ${last ? row("ri-time-line", "primary", "Last movement", esc(A.since(last.date))) : ""}
    </div>`;
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
    if (!d.budget_lines.length) return A.empty("ri-wallet-3-line", "No budget line posts here", "Budget lines are tied to income and expense accounts - money accounts like this one only move the money.");
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

  /** What a movement was against: the other accounts, money accounts with their logo. */
  const against = (m) =>
    m.against
      .slice(0, 2)
      .map((x) => `<span class="acc-against">${x.cash_kind ? A.methodLogo(x.cash_kind === "petty_cash" ? "cash" : x.cash_kind, "xs") : '<i class="ri-book-2-line"></i>'}${esc(x.name)}</span>`)
      .join("") + (m.against.length > 2 ? `<span class="acc-sub">+${m.against.length - 2}</span>` : "");

  /** One movement: direction, from or to whom, the document and how, what it was against, the amount and the balance after. */
  function stRow(d, m) {
    const rec = A.sourceRecord(m);
    const who = m.party || m.against.map((x) => x.name).join(", ");
    return `<div class="acc-st-row${m.reversed ? " is-reversed" : ""}" data-journal="${m.journal_id}"${rec ? ` data-record="${A.link("record.php", rec)}"` : ""} role="button" tabindex="0">
      <span class="acc-st-dir is-${m.direction}"><i class="${m.direction === "in" ? "ri-arrow-left-down-line" : "ri-arrow-right-up-line"}"></i></span>
      <div class="acc-st-main"><div class="acc-st-line"><strong>${m.direction === "in" ? `${esc(d.labels.in)} from` : `${esc(d.labels.out)} to`} ${esc(who || "-")}</strong></div>
        <div class="acc-st-meta"><span class="fw-semibold">${esc(m.number)}</span>${A.docPill(m.doc_type)}${m.method ? A.methodChip(m.method, m.method_label) : ""}${m.reversed ? '<span class="badge bg-danger">Reversed</span>' : ""}${m.attachments ? `<span class="badge bg-primary"><i class="ri-attachment-2 me-1"></i>${m.attachments}</span>` : ""}</div>
        ${m.details ? `<div class="acc-sub text-truncate">${esc(m.details)}</div>` : ""}
        <div class="acc-st-against">${against(m)}</div></div>
      <div class="acc-st-money"><span class="acc-st-amount is-${m.direction}">${m.direction === "in" ? "+" : "−"} ${A.money(m.direction === "in" ? m.in : m.out)}</span><small>Balance ${A.money(m.balance_after)}</small></div>
    </div>`;
  }

  /** A statement of money in and out: by day, newest first, each with its direction, who or what it was against, and the balance after it. */
  function movements(d, filter = "all", q = "", sort = "new") {
    q = q.trim().toLowerCase();
    const list = d.movements
      .filter((m) => filter === "all" || m.direction === filter)
      .filter((m) => !q || [m.number, m.party, m.details, m.method_label, ...m.against.map((x) => x.name)].join(" ").toLowerCase().includes(q))
      .sort((a, b) => (sort === "big" ? Math.max(b.in, b.out) - Math.max(a.in, a.out) : sort === "old" ? String(a.date).localeCompare(String(b.date)) : 0));
    if (sort !== "new") {
      // Out of date order a day header means nothing: one flat list.
      return list.length ? `<div class="acc-statement"><div class="acc-st-day">${list.map((m) => stRow(d, m)).join("")}</div></div>` : A.empty("ri-search-line", "Nothing matches", "Try another word.");
    }
    if (!list.length) return A.empty("ri-exchange-line", filter === "all" ? "Nothing moved yet" : "Nothing like that lately", "Receipts, payments and transfers on this account show here.");
    const days = [];
    list.forEach((m) => {
      const last = days[days.length - 1];
      if (last && last.date === m.date) last.items.push(m);
      else days.push({ date: m.date, items: [m] });
    });
    return `<div class="acc-statement">${days
      .map((g) => {
        const tin = g.items.reduce((t, m) => t + m.in, 0);
        const tout = g.items.reduce((t, m) => t + m.out, 0);
        return `<div class="acc-st-day"><div class="acc-st-dayhead">${A.dateTile(g.date)}<div class="flex-fill min-w-0"><strong>${A.day(g.date, { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</strong><small>${esc(A.since(g.date))}</small></div><div class="acc-st-daysum">${tin ? `<span class="text-success">In ${A.money(tin)}</span>` : ""}${tout ? `<span class="text-danger">Out ${A.money(tout)}</span>` : ""}</div></div>
          ${g.items
            .map((m) => stRow(d, m))
            .join("")}</div>`;
      })
      .join("")}</div>`;
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
    const tin = d.movements.reduce((t, m) => t + m.in, 0);
    const tout = d.movements.reduce((t, m) => t + m.out, 0);
    const pills = `<div class="d-flex flex-wrap align-items-center gap-2"><div class="list-search acc-st-search"><i class="ri-search-line"></i><input type="search" class="form-control form-control-sm" id="acSearch" placeholder="Search..." autocomplete="off"></div><select class="form-select form-select-sm acc-st-sort" id="acSort" aria-label="Sort"><option value="new">Newest first, by day</option><option value="old">Oldest first</option><option value="big">Largest first</option></select><div class="pp-pills" role="tablist">${[["all", "All", "ri-apps-2-line", "primary"], ["in", d.labels.in, "ri-arrow-left-down-line", "success"], ["out", d.labels.out, "ri-arrow-right-up-line", "danger"]].map(([k, l, i, c]) => `<button type="button" class="pp-pill${k === "all" ? " is-on" : ""}" style="--q: var(--${c}-rgb)" data-filter="${k}"><i class="${i}"></i>${esc(l)}</button>`).join("")}</div>${last}</div>`;
    data = d;
    $("acApp").innerHTML = `${hero(d)}
      <div class="row" id="acStats"></div>
      <div class="row">
        <div class="col-xl-8 d-flex">${card("ri-bar-chart-2-line", `${d.labels.in} and ${d.labels.out.toLowerCase()}`, '<div id="acChart" class="acc-chart"></div>', { color: "success", sub: "The last 12 months", flex: true })}</div>
        <div class="col-xl-4 d-flex flex-column">${card("ri-wallet-3-line", "Budget lines using it", budgetLines(d), { color: "warning" })}${card("ri-dashboard-3-line", "This year at a glance", glance(d), { color: "primary", flex: true })}</div>
      </div>
      ${card("ri-exchange-line", "Money in and out", '<div id="acStatement"></div>', { color: "purple", extra: pills, sub: `The ${d.movements.length} latest movements · in ${A.money(tin)} · out ${A.money(tout)} - with the balance after each` })}`;
    $("acStatement").innerHTML = movements(d);
    const redraw = () => ($("acStatement").innerHTML = movements(data, document.querySelector("[data-filter].is-on")?.dataset.filter || "all", $("acSearch").value, $("acSort").value));
    let t = null;
    $("acSearch").addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(redraw, 200);
    });
    $("acSort").addEventListener("change", redraw);
    stats(d);
    drawChart(d);
  }

  const API = () => AccountingAPI;

  function init() {
    $("acApp").addEventListener("click", (e) => {
      const f = e.target.closest("[data-filter]");
      if (f) {
        document.querySelectorAll("[data-filter]").forEach((b) => b.classList.toggle("is-on", b === f));
        $("acStatement").innerHTML = movements(data, f.dataset.filter, $("acSearch").value, $("acSort").value);
        return;
      }
      if (e.target.closest("a, button")) return;
      const row = e.target.closest("[data-journal]");
      if (!row) return;
      if (row.dataset.record) window.location.href = row.dataset.record;
      else W.viewJournal(Number(row.dataset.journal), { onChange: load });
    });
    if (!ID) {
      $("acApp").innerHTML = A.errorBox("Pick an account on Cash & bank.");
      return;
    }
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
