/**
 * ============================================================================
 * ACCOUNTING - Overview (index.php)
 * ============================================================================
 * Four cards (the money we hold now, received and spent this month - with
 * the change on last month and a sparkline - and vouchers waiting), where the
 * money is (each account's balance), money in and out over 12 months, the
 * year by fund, where it came from and went, and the latest documents.
 * Empty books get three first steps instead.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let chart = null;

  function cards(d) {
    const held = d.cash.reduce((s, a) => s + a.balance, 0);
    const m = d.monthly;
    const last = (arr) => arr.slice(-12);
    const waiting = d.vouchers.prepared.count;
    const toPay = d.vouchers.authorised.count;
    K.statRow($("statCardsRow"), [
      { icon: "ri-safe-2-line", label: "Money we hold", sub: `In ${d.cash.length} ${d.cash.length === 1 ? "account" : "accounts"}, today`, value: A.short(held), color: "primary", series: { labels: m.labels, data: cumulative(m, held) } },
      { icon: "ri-arrow-down-circle-line", label: "Received", sub: d.month.label, value: A.short(d.month.in), color: "success", delta: UI.periodDelta(d.month.in, d.last_month.in), series: { labels: last(m.labels), data: last(m.in) }, trim: true },
      { icon: "ri-arrow-up-circle-line", label: "Spent", sub: d.month.label, value: A.short(d.month.out), color: "danger", delta: invert(UI.periodDelta(d.month.out, d.last_month.out)), series: { labels: last(m.labels), data: last(m.out) }, trim: true },
      { icon: "ri-file-list-3-line", label: "Payments waiting", sub: toPay ? `${toPay} authorised - to pay` : "None waiting to be paid", value: A.num(waiting + toPay), color: "warning", bar: { pct: waiting + toPay ? Math.round((toPay / (waiting + toPay)) * 100) : 0, text: waiting ? `${waiting} to authorise` : "All authorised" } },
    ]);
  }

  /** Spending going up is not good news: show it red. */
  const invert = (d) => (d ? { ...d, dir: d.dir === "up" ? "down" : d.dir === "down" ? "up" : d.dir } : d);
  /** The balance at each month end, worked back from today's total. */
  function cumulative(m, held) {
    let run = 0;
    const net = m.in.map((v, i) => (run += v - m.out[i]));
    const shift = held - (net[net.length - 1] || 0);
    return net.map((v) => Math.round(v + shift));
  }

  function cashList(d) {
    if (!d.cash.length) {
      $("cashList").innerHTML = A.empty("ri-bank-line", "No accounts yet", "Cash at hand is ready; add the bank and M-Pesa in Cash & bank.");
      return;
    }
    const total = d.cash.reduce((s, a) => s + Math.max(0, a.balance), 0) || 1;
    $("cashList").innerHTML = `<div class="acc-cash-list">${d.cash
      .map(
        (a) => `<a class="acc-cash-row" href="${A.link("cashbook.php", { account_id: a.id })}">
          ${A.tile(a.cash_kind)}
          <div class="flex-fill min-w-0"><div class="fw-semibold text-truncate">${esc(a.name)}</div><div class="acc-sub">${esc(A.kind(a.cash_kind).label)}${a.number_masked ? ` · ${esc(a.number_masked)}` : ""}${a.is_active ? "" : " · switched off"}</div>
          <div class="progress progress-xs mt-1"><div class="progress-bar bg-${A.kind(a.cash_kind).color}" style="width:${Math.round((Math.max(0, a.balance) / total) * 100)}%"></div></div></div>
          <strong class="acc-amount${a.balance < 0 ? " text-danger" : ""}">${A.money(a.balance)}</strong>
        </a>`,
      )
      .join("")}</div><div class="acc-cash-total"><span>Total</span><strong>${A.money(d.cash.reduce((s, a) => s + a.balance, 0))}</strong></div>`;
  }

  function inOutChart(d) {
    chart?.destroy();
    const m = d.monthly;
    const css = getComputedStyle(document.documentElement);
    const rgb = (v) => `rgb(${css.getPropertyValue(v).trim()})`;
    $("chartChips").innerHTML = `<span class="soft-chip soft-success">This year in · ${esc(A.short(d.year.in))}</span> <span class="soft-chip soft-danger">Out · ${esc(A.short(d.year.out))}</span>`;
    chart = new ApexCharts($("inOutChart"), {
      chart: { type: "bar", height: 280, toolbar: { show: false }, fontFamily: "Inter, sans-serif" },
      series: [
        { name: "Money in", data: m.in },
        { name: "Money out", data: m.out },
      ],
      colors: [rgb("--success-rgb"), rgb("--danger-rgb")],
      plotOptions: { bar: { columnWidth: "55%", borderRadius: 3 } },
      dataLabels: { enabled: false },
      xaxis: { categories: m.labels.map((l) => l.split(" ")[0]), axisBorder: { show: false } },
      yaxis: { min: 0, max: Math.max(...m.in, ...m.out) > 0 ? undefined : 1000, tickAmount: 4, labels: { formatter: (v) => (!Number.isFinite(v) ? "" : v >= 1000 ? `${Math.round(v / 1000)}k` : Math.round(v)) } },
      grid: { borderColor: "rgba(0,0,0,0.06)", strokeDashArray: 3 },
      legend: { position: "top", horizontalAlign: "right" },
      tooltip: { y: { formatter: (v) => A.money(v) } },
    });
    chart.render();
  }

  function fundList(d) {
    const funds = d.funds.filter((f) => f.in || f.out || f.code === "GEN");
    $("fundList").innerHTML = funds.length
      ? `<div class="acc-fund-list">${funds
          .map(
            (f) => `<div class="acc-fund"><span class="avatar avatar-sm avatar-rounded bg-${f.restricted ? "warning text-dark" : "success text-white"}"><i class="${f.restricted ? "ri-lock-line" : "ri-hand-heart-line"}"></i></span>
            <div class="flex-fill min-w-0"><div class="fw-semibold">${esc(f.name)}</div><div class="acc-sub">In ${esc(A.short(f.in))} · out ${esc(A.short(f.out))}</div></div>
            <strong class="${f.in - f.out < 0 ? "text-danger" : ""}">${A.short(f.in - f.out)}</strong></div>`,
          )
          .join("")}</div>`
      : A.empty("ri-hand-heart-line", "Nothing yet", "Receipts show here by fund.");
  }

  function topList(el, rows, color) {
    const max = Math.max(1, ...rows.map((r) => r.total));
    el.innerHTML = rows.length
      ? `<div class="acc-top">${rows
          .map((r) => `<div class="acc-top-row"><div class="d-flex justify-content-between gap-2"><span class="fw-semibold text-truncate">${esc(r.name)}</span><strong>${A.short(r.total)}</strong></div><div class="progress progress-xs"><div class="progress-bar bg-${color}" style="width:${Math.round((r.total / max) * 100)}%"></div></div></div>`)
          .join("")}</div>`
      : A.empty(color === "success" ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line", "Nothing yet", "It fills in as money is recorded.");
  }

  function latest(d) {
    $("latestRows").innerHTML = d.latest.length
      ? d.latest
          .map(
            (j) => `<tr data-journal="${j.id}" class="acc-row">
            <td><div class="d-flex align-items-center gap-2">${A.docTile(j.doc_type)}<div class="min-w-0"><div class="fw-semibold">${esc(j.number)}</div><div class="acc-sub">${esc(A.doc(j.doc_type).label)}${j.narration ? ` · ${esc(j.narration)}` : ""}</div></div></div></td>
            <td>${A.day(j.date)}</td>
            <td class="d-none d-md-table-cell">${esc(j.party_name || "-")}</td>
            <td class="text-end"><strong class="${j.doc_type === "receipt" ? "text-success" : j.doc_type === "payment" ? "text-danger" : ""}">${A.money(j.amount)}</strong>${j.status === "reversed" ? `<div>${A.reversedChip()}</div>` : ""}</td>
          </tr>`,
          )
          .join("")
      : `<tr><td colspan="4">${A.empty("ri-file-list-3-line", "No documents yet", "Receipts, payments and transfers show here as they are written.")}</td></tr>`;
  }

  function start(d) {
    const el = $("startCard");
    const own = !A.viewingBelow();
    if (d.documents > 0 || !own) {
      el.hidden = true;
      return;
    }
    const c = d.can;
    const step = (n, icon, title, text, btn) => `<div class="acc-start-step"><span class="acc-start-n">${n}</span><div class="flex-fill"><div class="fw-semibold"><i class="${icon} me-1"></i>${title}</div><p class="mb-2">${text}</p>${btn}</div></div>`;
    el.innerHTML = `<div class="card-body"><div class="fs-15 fw-semibold mb-1">Start the books</div><p class="mb-3">Three steps and every shilling ${esc(d.place.name)} receives and spends has a home.</p><div class="acc-start-steps">
      ${step(1, "ri-bank-line", "Add the bank and M-Pesa", "Cash at hand is already there.", c.accounts ? `<a class="btn btn-sm btn-outline-primary" href="${A.link("accounts.php")}">Cash & bank</a>` : "")}
      ${step(2, "ri-scales-3-line", "Enter what each account holds today", "One opening journal - the cash counted, the bank statement's balance.", c.journal ? '<button type="button" class="btn btn-sm btn-outline-primary" data-opening>Opening balances</button>' : "")}
      ${step(3, "ri-bill-line", "Write receipts and pay by voucher", "From now on, money in is a receipt and money out a payment voucher.", c.receipt ? '<button type="button" class="btn btn-sm btn-primary" data-first-receipt>Write a receipt</button>' : "")}
    </div></div>`;
    el.hidden = false;
    el.querySelector("[data-opening]")?.addEventListener("click", () => W.journal({ opening: true, onDone: load }));
    el.querySelector("[data-first-receipt]")?.addEventListener("click", () => W.receipt({ onDone: load }));
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("latestRows").innerHTML = UI.renderTableLoading(4);
    A.ownOnly();
    const res = await AccountingAPI.overview();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${A.errorBox(res.message)}</div>`;
      $("latestRows").innerHTML = "";
      return;
    }
    const d = res.data;
    A.placeLine($("accPlaceLine"), d.place);
    start(d);
    cards(d);
    cashList(d);
    inOutChart(d);
    fundList(d);
    topList($("incomeList"), d.top_income, "success");
    topList($("expenseList"), d.top_expense, "danger");
    latest(d);
  }

  function init() {
    $("receiptBtn")?.addEventListener("click", () => W.receipt({ onDone: load }));
    $("voucherBtn")?.addEventListener("click", () => W.voucher({ onDone: load }));
    $("transferBtn")?.addEventListener("click", () => W.transfer({ onDone: load }));
    $("latestRows").addEventListener("click", (e) => {
      const tr = e.target.closest("[data-journal]");
      if (tr) W.viewJournal(Number(tr.dataset.journal), { onChange: load });
    });
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
