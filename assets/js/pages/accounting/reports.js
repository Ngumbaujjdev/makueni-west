/**
 * ============================================================================
 * ACCOUNTING - Reports (reports.php) - Redesign R4
 * ============================================================================
 * The books as PDFs on the diocese letterhead, through the report engine
 * (Settings > Documents & PDF: logo, QR verification, page numbers):
 *   Books      - the cashbook (with its cover) and the trial balance;
 *   Registers  - receipts, payment vouchers, collections, remittances;
 *   Documents  - where each single document is downloaded from;
 *   Statements - income & expenditure, balance sheet, funds and the audit
 *                pack, coming with the year-end statements (A9).
 * Each card picks its account and dates, then opens the export window.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let accounts = [];

  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  /** This month, last month, this year - or the dates typed. */
  function range(key) {
    const t = new Date();
    if (key === "last") return [iso(new Date(t.getFullYear(), t.getMonth() - 1, 1)), iso(new Date(t.getFullYear(), t.getMonth(), 0))];
    if (key === "year") return [iso(new Date(t.getFullYear(), 0, 1)), iso(t)];
    return [iso(new Date(t.getFullYear(), t.getMonth(), 1)), iso(t)];
  }

  const rangeControl = (id) => `<div class="acc-rp-range" data-range-for="${id}">
      <div class="btn-group btn-group-sm" role="group">${[["month", "This month"], ["last", "Last month"], ["year", "This year"], ["custom", "Dates"]].map(([k, l], i) => `<button type="button" class="btn ${i ? "btn-outline-primary" : "btn-primary"}" data-range="${k}">${l}</button>`).join("")}</div>
      <div class="acc-rp-dates" hidden><input type="date" class="form-control form-control-sm" data-from value="${range("month")[0]}"><span>to</span><input type="date" class="form-control form-control-sm" data-to value="${range("month")[1]}" max="${iso(new Date())}"></div>
    </div>`;

  function card({ id, key, icon, color, title, text, controls = "", cta = "Download PDF", coming = false, chips = "" }) {
    return `<div class="col-xxl-4 col-md-6 d-flex"><div class="card custom-card flex-fill acc-rp-card${coming ? " is-coming" : ""}" data-card="${id}" data-key="${key || ""}">
      <div class="card-body d-flex flex-column gap-3">
        <div class="d-flex align-items-start gap-3"><span class="avatar avatar-md avatar-rounded bg-${color} ${A.textOn(color)} flex-shrink-0"><i class="${icon} fs-18"></i></span>
          <div class="min-w-0"><div class="fw-semibold fs-15">${esc(title)}</div><p class="acc-sub mb-0">${esc(text)}</p>${chips ? `<div class="d-flex flex-wrap gap-1 mt-2">${chips}</div>` : ""}</div></div>
        ${controls}
        <div class="mt-auto">${coming ? '<span class="badge bg-secondary text-dark"><i class="ri-time-line me-1"></i>Coming with the year-end statements</span>' : `<button type="button" class="btn btn-primary" data-go="${id}"><i class="ri-file-pdf-line me-1"></i>${esc(cta)}</button>`}</div>
      </div></div></div>`;
  }

  const group = (icon, color, title, sub, cards) => `<div class="acc-rp-group"><div class="d-flex align-items-center gap-2 mb-3"><span class="acc-rec-icon bg-${color}"><i class="${icon}"></i></span><div><div class="fw-semibold fs-16">${esc(title)}</div><span class="acc-sub">${esc(sub)}</span></div></div><div class="row">${cards}</div></div>`;

  function render() {
    const money = accounts.filter((a) => a.is_active !== false);
    const accountSelect = `<select class="form-select form-select-sm" data-account>${money.map((a) => `<option value="${a.id}">${esc(a.name)} (${esc(a.code)})</option>`).join("")}</select>`;
    const church = CTX.level === "church";
    const docs = [
      ["ri-bill-line", "success", "Official receipt", "Open a receipt (All documents or the Overview) - Receipt (PDF)", "documents.php"],
      ["ri-file-list-3-line", "danger", "Payment voucher", "Open a voucher - Voucher (PDF), with its approvals and signatures", "payments.php"],
      ["ri-shopping-cart-2-line", "purple", "Purchase order (LPO)", "Open an order in Procurement - LPO (PDF)", "procurement.php"],
      ["ri-file-user-line", "pink", "Payslips and payroll register", "Open a month in Payroll - Payslips (PDF), Register (PDF)", "payroll.php"],
      ["ri-send-plane-line", "info", "Remittance advice", "Open a remittance - Advice (PDF)", "remittances.php"],
      church && ["ri-hand-coin-line", "success", "Collection sheet", "Open a service in Collections - Collection sheet (PDF)", "collections.php"],
      ["ri-bank-line", "primary", "Bank reconciliation", "Open a reconciliation - Statement (PDF)", "reconciliation.php"],
    ].filter(Boolean);
    $("rpApp").innerHTML =
      `<div id="rpRecent"></div>` +
      group(
        "ri-book-open-line",
        "primary",
        "Books",
        "The cashbook with its cover page, and the proof the books balance",
        card({ id: "cashbook", key: "accounting.cashbook", icon: "ri-book-open-line", color: "primary", title: "Cashbook", text: "One account's money in and out - brought forward, every movement with its running balance, carried forward - with a cover page and the church's logo.", controls: `<div class="d-flex flex-column gap-2">${accountSelect}${rangeControl("cashbook")}</div>`, chips: '<span class="soft-chip soft-primary"><i class="ri-book-2-line"></i>Cover page</span><span class="soft-chip soft-success"><i class="ri-qr-code-line"></i>Verifiable</span>' }) +
          card({ id: "tb", key: "accounting.trial_balance", icon: "ri-scales-3-line", color: "purple", title: "Trial balance", text: "Every account's balance, debits beside credits by kind, and whether the books balance.", controls: `<div class="d-flex flex-column gap-1"><label class="acc-sub">As at</label><input type="date" class="form-control form-control-sm" data-asat value="${iso(new Date())}" max="${iso(new Date())}"></div>` }),
      ) +
      group(
        "ri-list-check-2",
        "success",
        "Registers",
        "Everything of one kind in a period, totalled",
        card({ id: "receipts", key: "accounting.receipts", icon: "ri-bill-line", color: "success", title: "Receipts register", text: "Every receipt - who gave, how and into which account - totalled by how the money came.", controls: rangeControl("receipts") }) +
          card({ id: "vouchers", key: "accounting.vouchers", icon: "ri-file-list-3-line", color: "danger", title: "Payment vouchers register", text: "Every voucher - payee, what for, status, who authorised it and when it was paid.", controls: rangeControl("vouchers") }) +
          (church ? card({ id: "collections", key: "accounting.collections", icon: "ri-hand-heart-line", color: "success", title: "Collections register", text: "Every service's giving - cash and M-Pesa, who counted and confirmed it, when it was banked.", controls: rangeControl("collections") }) : "") +
          card({ id: "remittances", key: "accounting.remittances", icon: "ri-send-plane-line", color: "info", title: "Remittances register", text: "What was sent and received between levels - shares, support, settlements - and whether confirmed.", controls: rangeControl("remittances") }),
      ) +
      group(
        "ri-file-copy-2-line",
        "warning",
        "Documents",
        "One document at a time, downloaded from its own page",
        `<div class="col-12"><div class="card custom-card"><div class="card-body"><div class="acc-rp-docs">${docs.map(([icon, color, title, how, page]) => `<a class="acc-rp-doc" href="${A.link(page)}"><span class="avatar avatar-sm avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon}"></i></span><span class="min-w-0"><strong class="d-block">${esc(title)}</strong><small>${esc(how)}</small></span><i class="ri-arrow-right-line ms-auto"></i></a>`).join("")}</div></div></div></div>`,
      ) +
      group(
        "ri-pie-chart-2-line",
        "secondary",
        "Statements",
        "The year's financial statements - with the year-end close",
        ["Income & expenditure", "Balance sheet", "Funds statement", "Audit pack"].map((t, i) => card({ id: `a9-${i}`, icon: ["ri-line-chart-line", "ri-scales-line", "ri-safe-2-line", "ri-folder-zip-line"][i], color: "secondary", title: t, text: ["What came in and went out, by account, and the surplus.", "What the place holds and owes at a date.", "Each fund's money in, out and balance - restricted kept apart.", "Everything the auditor needs, in one bundle."][i], coming: true })).join(""),
      );
    $("rpApp").querySelectorAll("select[data-account]").forEach((s) => DemographicsUI.enhanceSelect?.(s));
  }

  function datesOf(cardEl) {
    const wrap = cardEl.querySelector("[data-range-for]");
    if (!wrap) return null;
    const on = wrap.querySelector("[data-range].btn-primary")?.dataset.range || "month";
    if (on === "custom") return [wrap.querySelector("[data-from]").value, wrap.querySelector("[data-to]").value];
    return range(on);
  }

  function go(id) {
    const el = $("rpApp").querySelector(`[data-card="${id}"]`);
    const key = el.dataset.key;
    const [from, to] = datesOf(el) || [];
    if (id === "cashbook") {
      const sel = el.querySelector("[data-account]");
      const acc = accounts.find((a) => String(a.id) === sel.value);
      if (!acc) return Toast.error("Add a cash, bank or M-Pesa account first.");
      return A.pdf(key, { account_id: acc.id, account_label: acc.name, date_from: from, date_to: to }, `Cashbook - ${acc.name}`);
    }
    if (id === "tb") {
      const at = el.querySelector("[data-asat]").value;
      return A.pdf(key, { date_from: `${at.slice(0, 4)}-01-01`, date_to: at }, "Trial balance");
    }
    if (!from || !to || from > to) return Toast.error("Pick the dates - the first on or before the last.");
    A.pdf(key, { date_from: from, date_to: to }, el.querySelector(".fw-semibold").textContent);
  }

  /** "My recent PDFs": what this person exported from Accounting, newest first, ready to download again. */
  let runs = [];
  let recentTimer = null;
  async function recent() {
    clearTimeout(recentTimer);
    const res = await AccountingAPI.reportRuns();
    const el = $("rpRecent");
    if (!el || !res.ok) return;
    runs = (res.data || []).filter((r) => String(r.report_key || "").startsWith("accounting."));
    const ST = { ready: ["success", "ri-checkbox-circle-line", "Ready"], queued: ["warning", "ri-time-line", "Waiting"], running: ["primary", "ri-loader-4-line", "Building"], failed: ["danger", "ri-error-warning-line", "Failed"] };
    const ago = (iso) => (iso ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "");
    el.innerHTML = `<div class="card custom-card acc-rec-card"><div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-danger"><i class="ri-file-pdf-line"></i></span>My recent PDFs</div><span class="card-subtitle-text">Everything you exported from Accounting - the same files are under <i class="ri-file-download-line"></i> at the top of every page</span></div><button type="button" class="btn btn-sm btn-outline-primary" data-recent-refresh><i class="ri-refresh-line me-1"></i>Refresh</button></div>
      <div class="card-body">${
        runs.length
          ? `<div class="acc-rp-runs">${runs
              .slice(0, 12)
              .map((r, i) => {
                const st = r.expired ? ["secondary", "ri-time-line", "Expired"] : ST[r.status] || ST.queued;
                return `<div class="acc-rp-run"><span class="avatar avatar-sm avatar-rounded bg-danger text-white"><i class="ri-file-pdf-line"></i></span><div class="min-w-0 flex-fill"><strong class="d-block text-truncate">${esc(r.title || r.report_key)}</strong><small>${esc(r.period_label || "")}${r.period_label ? " · " : ""}${esc(ago(r.created_at))}</small></div><span class="badge bg-${st[0]} ${A.textOn(st[0])}"><i class="${st[1]} me-1"></i>${st[2]}</span>${r.status === "ready" && !r.expired ? `<button type="button" class="btn btn-sm btn-primary" data-run="${i}"><i class="ri-download-2-line me-1"></i>Download</button>` : ""}</div>`;
              })
              .join("")}</div>`
          : '<p class="acc-muted-line mb-0">Nothing yet - download a cashbook, a register or any receipt or voucher and it shows here.</p>'
      }</div></div>`;
    if (runs.some((r) => ["queued", "running"].includes(r.status))) recentTimer = setTimeout(recent, 4000);
  }

  async function load() {
    const res = await AccountingAPI.accounts();
    if (!res.ok) {
      $("rpApp").innerHTML = A.errorBox(res.message);
      return;
    }
    accounts = res.data.cash || [];
    A.placeLine($("accPlaceLine"), res.data.place);
    render();
    recent();
  }

  function init() {
    A.ownOnly();
    $("rpApp").addEventListener("click", (e) => {
      const r = e.target.closest("[data-range]");
      if (r) {
        const wrap = r.closest("[data-range-for]");
        wrap.querySelectorAll("[data-range]").forEach((b) => {
          b.classList.toggle("btn-primary", b === r);
          b.classList.toggle("btn-outline-primary", b !== r);
        });
        wrap.querySelector(".acc-rp-dates").hidden = r.dataset.range !== "custom";
        return;
      }
      const g = e.target.closest("[data-go]");
      if (g) go(g.dataset.go);
      const dl = e.target.closest("[data-run]");
      if (dl) ReportCenter.download(runs[Number(dl.dataset.run)], dl);
      if (e.target.closest("[data-recent-refresh]")) recent();
    });
    A.placePicker($("accPlacePick"), load);
    // A PDF finished in the export window: show it in "My recent PDFs" straight away.
    document.getElementById("reportModal")?.addEventListener("hidden.bs.modal", recent);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
