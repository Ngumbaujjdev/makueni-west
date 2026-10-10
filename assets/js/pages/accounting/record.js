/**
 * ============================================================================
 * ACCOUNTING - one record's page (record.php?type=&id=) - Redesign R1
 * ============================================================================
 * A payment voucher, requisition, payroll month, purchase order, remittance
 * or Sunday collection on its own page, laid out like the template's invoice
 * page:
 *   - the hero: what it is, the amount in full, where it stands;
 *   - the journey: each step with who and when, and "what happens next" in
 *     plain words (solid gold when it is your turn), with the button to act;
 *   - its lines;
 *   - beside it, the documents it is chained to (requisition → order →
 *     delivery → bill → voucher → payment in the books), its papers, and
 *     everything that happened (GET accounting/trail/{type}/{id}).
 * Taking a step opens the same window the list page uses, then the page
 * reloads.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const W = AccountingWindows;
  const API = AccountingAPI;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const q = new URLSearchParams(window.location.search);
  const TYPE = q.get("type");
  const ID = Number(q.get("id"));

  const pill = (color, icon, label) => `<span class="badge bg-${color} ${A.textOn(color)}"><i class="${icon} me-1"></i>${esc(label)}</span>`;
  const btn = (attrs, cls, icon, label) => `<button type="button" class="btn ${cls}" ${attrs}><i class="${icon} me-1"></i>${esc(label)}</button>`;
  const go = (page, params, cls, icon, label) => `<a class="btn ${cls}" href="${A.link(`${page}.php`, params)}"><i class="${icon} me-1"></i>${esc(label)}</a>`;
  const when = (iso) => (!iso ? "-" : iso.length > 10 ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : A.day(iso));
  const monthName = (ym) => (/^\d{4}-\d{2}$/.test(ym || "") ? new Date(`${ym}-01T12:00:00`).toLocaleDateString("en-GB", { month: "long", year: "numeric" }) : ym);
  /** Approve / Send back / Reject on a "Your turn" card. */
  const decisionButtons = () => `${btn('data-decide="approve"', "btn-light", "ri-check-line", "Approve")}${btn('data-decide="return"', "btn-outline-dark", "ri-arrow-go-back-line", "Send back")}${btn('data-decide="reject"', "btn-outline-dark", "ri-close-line", "Reject")}`;
  /** The button on a "Your turn" card is white on gold; elsewhere outlined. */
  const actCls = (mine) => (mine ? "btn-light" : "btn-outline-primary");

  // ------------------------------------------------------------ each kind of record

  const VOUCHER_COLOR = { prepared: "warning", authorised: "primary", paid: "success", rejected: "danger", cancelled: "secondary" };

  async function voucher() {
    const res = await API.voucher(ID);
    if (!res.ok) return res;
    const v = res.data;
    const c = v.can;
    const engineTurn = v.approval?.can?.decide;
    const acts = engineTurn ? "" : c.authorise_this || c.reject_this ? "Approve or send back" : c.pay_this ? "Pay it" : c.edit ? "Change it" : "";
    const mine = !!(c.authorise_this || c.pay_this);
    const lines = `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Charged to</th><th class="d-none d-sm-table-cell">Fund</th><th class="text-end">KES</th></tr></thead><tbody>${v.lines
      .map((l) => `<tr><td><span class="fw-semibold">${esc(l.account.name)}</span><div class="acc-sub">${esc(l.account.code)}${l.budget_line ? ` · Budget: ${esc(l.budget_line)}` : ""}${l.description ? ` · ${esc(l.description)}` : ""}</div></td><td class="d-none d-sm-table-cell">${l.fund ? `<span class="soft-chip soft-${l.fund.code === "GEN" ? "success" : "warning"}">${esc(l.fund.name)}</span>` : ""}</td><td class="text-end">${A.amount(l.amount)}</td></tr>`)
      .join("")}</tbody><tfoot><tr><th>Total</th><th class="d-none d-sm-table-cell"></th><th class="text-end">${A.amount(v.amount)}</th></tr></tfoot></table></div>`;
    return {
      ok: true,
      place: v.place,
      icon: "ri-file-list-3-line",
      color: VOUCHER_COLOR[v.status],
      kind: "Payment voucher",
      number: v.number,
      title: v.narration,
      amount: v.amount,
      status: A.voucherPill(v.status, true),
      dir: "out",
      facts: [["Pay to", esc(v.payee_name)], ["Date", A.dateChip(v.date)], ["Pay from", A.accountChip(v.pay_from)], ["Prepared by", A.person(v.prepared_by)]],
      steps: W.voucherSteps(v),
      next: W.voucherNext(v, engineTurn ? decisionButtons() : acts ? btn("data-open", actCls(mine), "ri-arrow-right-circle-line", acts) : ""),
      decide: engineTurn ? (d, comment) => API.decideApproval(v.approval.id, d, comment) : null,
      open: () => W.viewVoucher(v.id, { onChange: load }),
      sections: [{ icon: "ri-list-check-2", title: "What it pays for", html: lines }],
      files: v.files,
      fileUrl: (m) => API.voucherFileUrl(v.id, m),
      list: ["payments", "Payment vouchers"],
      pdfs: [{ label: "Voucher (PDF)", key: "accounting.voucher", params: { record_id: v.id }, title: `Voucher ${v.number}` }],
    };
  }

  const REQ = { submitted: ["warning", "ri-time-line"], approved: ["primary", "ri-shield-check-line"], returned: ["danger", "ri-arrow-go-back-line"], rejected: ["danger", "ri-close-line"], ordered: ["purple", "ri-shopping-cart-2-line"], paid: ["success", "ri-checkbox-circle-line"], cancelled: ["secondary", "ri-close-circle-line"] };

  async function requisition(links) {
    const res = await API.requisition(ID);
    if (!res.ok) return res;
    const r = res.data;
    const has = (t) => links.filter((l) => l.type === t);
    const order = has("order")[0];
    const bills = has("bill");
    const adv = r.advance;
    const s = r.status;
    const steps = [{ title: "Asked", state: "done", icon: "ri-hand-heart-line", who: r.requested_by, when: r.requested_at }];
    if (r.approval) steps.push(...A.approvalSteps(r.approval));
    else steps.push({ title: "Approved", state: ["approved", "ordered", "paid"].includes(s) ? "done" : s === "submitted" ? "now" : ["rejected", "returned"].includes(s) ? "stopped" : "next", icon: "ri-shield-check-line", who: r.decided_by || (s === "submitted" ? `Waiting on ${(r.waiting_on || []).join(", ") || "the approver"}` : ""), note: r.decision_note });
    const ok = ["approved", "ordered", "paid"].includes(s);
    if (r.kind === "purchase") {
      const quotes = r.procurement?.quotes || [];
      steps.push({ title: "Quotes", state: quotes.some((x) => x.chosen) ? "done" : ok ? "now" : "next", icon: "ri-scales-3-line", who: quotes.length ? `${quotes.length} of ${r.procurement.needed || quotes.length} - ${quotes.find((x) => x.chosen)?.supplier || "none chosen yet"}` : "" });
      steps.push({ title: "Ordered", state: order ? "done" : ok && quotes.some((x) => x.chosen) ? "now" : "next", icon: "ri-shopping-cart-2-line", who: order?.number || "" });
      steps.push({ title: "Received", state: has("delivery").length ? "done" : order ? "now" : "next", icon: "ri-truck-line", who: has("delivery").map((d) => d.number).join(", ") });
      steps.push({ title: "Billed", state: bills.length ? "done" : has("delivery").length ? "now" : "next", icon: "ri-file-list-2-line", who: bills.map((b) => b.number).join(", ") });
    } else if (r.kind === "advance") {
      steps.push({ title: "Advance given", state: adv ? "done" : s === "approved" ? "now" : "next", icon: "ri-wallet-3-line", who: adv ? adv.holder : "", when: adv?.issued_on });
      steps.push({ title: "Accounted for", state: adv?.status === "retired" ? "done" : adv ? "now" : "next", icon: "ri-bill-line", who: adv ? (adv.status === "retired" ? `Spent ${A.money(adv.spent)}, returned ${A.money(adv.returned)}` : `By ${A.day(adv.due_on)}`) : "" });
    }
    if (r.kind !== "advance") steps.push({ title: "Paid", state: s === "paid" ? "done" : (s === "approved" && r.kind === "payment") || bills.length ? "now" : "next", icon: "ri-hand-coin-line", who: r.voucher ? `${r.voucher.number}` : bills.map((b) => b.status_label).join(", ") });
    if (["rejected", "cancelled"].includes(s)) steps.push({ title: s === "rejected" ? "Rejected" : "Cancelled", state: "stopped", note: r.decision_note });

    let next;
    const open = go("requisitions", { requisition: r.id }, "btn-outline-primary", "ri-external-link-line", "Open in Requisitions");
    if (s === "submitted")
      next = r.can.decide
        ? A.nextCard({ tone: "mine", title: "Decide on it", text: "Read what is asked and why, look at the papers, then approve it, send it back for changes, or reject it.", actions: decisionButtons() })
        : A.nextCard({ tone: "wait", title: `Waiting for ${(r.waiting_on || []).join(", ") || "the approver"}`, text: r.approval?.stage ? `Stage: ${r.approval.stage.name}.` : "" });
    else if (s === "returned") next = A.nextCard({ tone: "stopped", title: "Sent back for changes", text: `${r.decision_note || ""} ${r.requested_by} can change it and send it again.`.trim(), actions: r.can.edit ? open : "" });
    else if (s === "rejected") next = A.nextCard({ tone: "stopped", title: `Rejected${r.decided_by ? ` by ${r.decided_by}` : ""}`, text: r.decision_note || "" });
    else if (s === "cancelled") next = A.nextCard({ tone: "stopped", title: "Cancelled", text: "Nothing more will happen to it." });
    else if (s === "approved" && r.kind === "purchase")
      next = A.nextCard({ tone: CTX.can.procure ? "mine" : "wait", title: order ? "Ordered" : `Approved - get ${r.procurement?.needed || 1} quotes and order`, text: "Over the limit, so it is bought on an order: quotes from suppliers, the best chosen, then a purchase order (LPO).", actions: CTX.can.procure ? go("procurement", { order_for: r.id }, "btn-light", "ri-shopping-cart-2-line", "Quotes and order") : "" });
    else if (s === "approved") next = r.can.pay ? A.nextCard({ tone: "mine", title: "Approved - prepare the payment", text: `Pay ${A.money(r.amount)} to ${r.payee_name || "them"}: a payment voucher is made for it.`, actions: go("requisitions", { requisition: r.id }, "btn-light", "ri-hand-coin-line", "Pay it") }) : A.nextCard({ tone: "wait", title: "Approved - waiting to be paid", text: "The treasurer prepares the payment." });
    else if (s === "ordered") next = A.nextCard({ tone: "wait", title: bills.length ? "Billed - waiting to be paid" : has("delivery").length ? "Received - waiting for the supplier's bill" : "Ordered - waiting for the goods", text: order ? `Order ${order.number}.` : "", actions: order ? go("record", { type: "order", id: order.id }, "btn-outline-primary", "ri-shopping-cart-2-line", "Open the order") : "" });
    else if (adv && adv.status !== "retired") next = A.nextCard({ tone: "wait", title: `Advance given - ${adv.holder} accounts for it by ${A.day(adv.due_on)}`, text: "Receipts for what was spent, and any change returned.", actions: open });
    else {
      const paidOn = r.voucher ? [r.voucher] : has("voucher");
      next = A.nextCard({ tone: "done", title: "Paid", text: `${paidOn.length ? `Paid on ${paidOn.map((x) => x.number).join(", ")}.` : "Done."}${order ? " Anything bought as an asset is in Facilities › Equipment." : ""}` });
    }

    const quotes = r.procurement?.quotes?.length
      ? `<div class="acc-quotes">${r.procurement.quotes.map((x) => `<div class="acc-quote${x.chosen ? " is-chosen" : ""}"><div class="min-w-0 flex-fill"><div class="fw-semibold">${esc(x.supplier)}${x.chosen ? ' <span class="badge bg-success">Chosen</span>' : ""}</div><div class="acc-sub">${esc(x.notes || "")}${x.chosen_reason ? ` · ${esc(x.chosen_reason)}` : ""}</div></div><strong>${A.money(x.amount)}</strong></div>`).join("")}</div>`
      : "";
    return {
      ok: true,
      place: r.place || CTX.place,
      icon: "ri-hand-heart-line",
      color: REQ[s]?.[0] || "primary",
      kind: `Requisition - ${r.kind_label}`,
      number: r.number,
      title: r.purpose,
      amount: r.amount,
      status: pill(REQ[s]?.[0] || "primary", REQ[s]?.[1] || "ri-time-line", r.status_label),
      dir: "out",
      facts: [["Asked by", A.person(r.requested_by)], ["On", A.dateChip(r.requested_at)], ["Pay to", esc(r.payee_name || "-")], ["Needed by", r.needed_by ? A.dateChip(r.needed_by) : "-"]],
      steps,
      next,
      sections: [
        { icon: "ri-information-line", title: "What it is charged to", html: W.factGrid([["Account", r.account ? `${esc(r.account.code)} ${esc(r.account.name)}` : "-"], ["Budget line", esc(r.budget_line || "-")], ["Fund", esc(r.fund?.name || "-")], r.payee_phone && ["Phone", esc(r.payee_phone)]]) },
        quotes && { icon: "ri-scales-3-line", title: "Quotes", html: quotes },
      ],
      files: r.attachments || [],
      fileUrl: (m) => API.requisitionFileUrl(r.id, m),
      decide: async (decision, comment) => API.decideRequisition(r.id, decision, comment),
      list: ["requisitions", "Requisitions"],
      pdfs: r.voucher ? [{ label: "Voucher (PDF)", key: "accounting.voucher", params: { record_id: r.voucher.id }, title: `Voucher ${r.voucher.number}` }] : [],
    };
  }

  const RUN = { draft: ["secondary", "ri-draft-line"], submitted: ["warning", "ri-time-line"], returned: ["danger", "ri-arrow-go-back-line"], posted: ["primary", "ri-shield-check-line"], paid: ["success", "ri-checkbox-circle-line"], cancelled: ["secondary", "ri-close-circle-line"] };

  async function payroll() {
    const res = await API.payrollRun(ID);
    if (!res.ok) return res;
    const p = res.data;
    const s = p.status;
    const c = p.can;
    const steps = [{ title: "Started", state: "done", icon: "ri-calendar-line", who: p.prepared_by }, { title: "Submitted", state: s === "draft" ? "now" : "done", icon: "ri-send-plane-line" }];
    if (p.approval) steps.push(...A.approvalSteps(p.approval));
    else steps.push({ title: "Approved", state: ["posted", "paid"].includes(s) ? "done" : s === "submitted" ? "now" : s === "returned" ? "stopped" : "next", icon: "ri-shield-check-line", who: p.approved_by, when: p.approved_at, note: p.decision_note });
    steps.push({ title: "Paid", state: s === "paid" ? "done" : s === "posted" ? "now" : "next", icon: "ri-hand-coin-line", who: p.payment?.number || "" });
    const open = (mine, label) => go("payroll", { run: p.id }, actCls(mine), "ri-external-link-line", label);
    const next =
      s === "draft" ? A.nextCard({ tone: c.submit ? "mine" : "wait", title: "Check the payslips and submit", text: "Once submitted it goes for approval, then it can be paid.", actions: open(c.submit, "Open the payroll") })
      : s === "submitted" ? (c.decide ? A.nextCard({ tone: "mine", title: "Approve the payroll", text: `${p.people} people, net pay ${A.money(p.net)}. Check the payslips below.`, actions: p.approval?.can?.decide ? decisionButtons() : open(true, "Approve or send back") }) : A.nextCard({ tone: "wait", title: `Waiting for ${(p.approval?.waiting_on || []).join(", ") || "approval"}`, text: p.approval?.stage ? `Stage: ${p.approval.stage.name}.` : "" }))
      : s === "posted" ? A.nextCard({ tone: c.pay ? "mine" : "wait", title: c.pay ? "Approved - pay it" : "Approved - waiting to be paid", text: `Net pay ${A.money(p.net)} to ${p.people} people.`, actions: c.pay ? open(true, "Pay it") : "" })
      : s === "returned" ? A.nextCard({ tone: "stopped", title: "Sent back", text: p.decision_note || "", actions: open(false, "Open the payroll") })
      : s === "paid" ? A.nextCard({ tone: "done", title: "Paid", text: p.payment ? `Paid on voucher ${p.payment.number}.` : "Done." })
      : A.nextCard({ tone: "stopped", title: "Cancelled", text: "" });
    const slips = `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Person</th><th class="text-end">Gross</th><th class="text-end d-none d-sm-table-cell">Other</th><th class="text-end">Net</th></tr></thead><tbody>${p.payslips.map((x) => `<tr><td><span class="fw-semibold">${esc(x.name)}</span><div class="acc-sub">${esc(x.position || "")}</div></td><td class="text-end">${A.amount(x.gross)}</td><td class="text-end d-none d-sm-table-cell">${A.amount(x.other) || "-"}</td><td class="text-end fw-semibold">${A.amount(x.net)}</td></tr>`).join("")}</tbody><tfoot><tr><th>Total</th><th class="text-end">${A.amount(p.gross)}</th><th class="text-end d-none d-sm-table-cell">${A.amount(p.deductions) || "-"}</th><th class="text-end">${A.amount(p.net)}</th></tr></tfoot></table></div>`;
    return {
      ok: true,
      place: p.place,
      icon: "ri-money-dollar-box-line",
      color: RUN[s]?.[0],
      kind: "Payroll",
      number: p.label,
      title: `${p.people} ${p.people === 1 ? "person" : "people"} on the payroll`,
      amount: p.net,
      amountLabel: "Net pay",
      status: pill(RUN[s]?.[0] || "primary", RUN[s]?.[1] || "ri-time-line", p.status_label),
      dir: "out",
      facts: [["Gross", A.money(p.gross)], ["Other deductions", A.money(p.deductions)], ["People", A.num(p.people)], ["Prepared by", A.person(p.prepared_by)]],
      steps,
      next,
      sections: [{ icon: "ri-file-user-line", title: "Payslips", html: slips }],
      decide: p.approval?.can?.decide ? (d, comment) => API.decideApproval(p.approval.id, d, comment) : null,
      files: [],
      list: ["payroll", "Payroll"],
      pdfs: [{ label: "Payslips (PDF)", key: "accounting.payslips", params: { record_id: p.id }, title: `Payslips ${p.label}` }, { label: "Register (PDF)", key: "accounting.payroll", params: { record_id: p.id }, title: `Payroll register ${p.label}` }],
    };
  }

  const ORD = { issued: ["primary", "ri-shopping-cart-2-line"], part_received: ["warning", "ri-truck-line"], received: ["success", "ri-checkbox-circle-line"], closed: ["secondary", "ri-lock-line"], cancelled: ["secondary", "ri-close-circle-line"] };

  async function order() {
    const res = await API.order(ID);
    if (!res.ok) return res;
    const o = res.data;
    const s = o.status;
    const c = o.can;
    const billed = o.bills.length > 0 && o.lines.every((l) => Number(l.to_bill) <= 0);
    const paid = billed && o.bills.filter((b) => b.status !== "reversed").every((b) => b.status === "paid");
    const steps = [
      { title: "Requisition", state: "done", icon: "ri-hand-heart-line", who: o.requisition?.number },
      { title: "Ordered", state: "done", icon: "ri-shopping-cart-2-line", who: o.issued_by, when: o.date },
      { title: "Received", state: ["received", "closed"].includes(s) ? "done" : s === "cancelled" ? "stopped" : "now", icon: "ri-truck-line", who: o.deliveries.map((d) => `${d.number} · ${d.by}`).join(", ") || (o.deliver_by ? `Due by ${A.day(o.deliver_by)}` : "") },
      { title: "Billed", state: billed ? "done" : o.deliveries.length ? "now" : "next", icon: "ri-file-list-2-line", who: o.bills.map((b) => b.number).join(", ") },
      { title: "Paid", state: paid ? "done" : o.bills.length ? "now" : "next", icon: "ri-hand-coin-line", who: o.bills.map((b) => b.voucher?.number).filter(Boolean).join(", ") },
    ];
    const open = (mine, label) => go("procurement", { order: o.id }, actCls(mine), "ri-external-link-line", label);
    const next =
      s === "cancelled" ? A.nextCard({ tone: "stopped", title: "Cancelled", text: o.end_reason || "" })
      : c.receive && o.lines.some((l) => Number(l.to_receive) > 0) ? A.nextCard({ tone: "mine", title: "Record what arrived", text: `Items still to come from ${o.supplier}${o.late ? " - it is late" : ""}.`, actions: open(true, "Record a delivery") })
      : c.bill && !billed && o.deliveries.length ? A.nextCard({ tone: "mine", title: "Enter the supplier's bill", text: "Match it to what was ordered and received; then it can be paid.", actions: open(true, "Enter the bill") })
      : paid ? A.nextCard({ tone: "done", title: "Received, billed and paid", text: "Assets bought on this order are in Facilities › Equipment." })
      : o.bills.length ? A.nextCard({ tone: "wait", title: "Billed - waiting to be paid", text: o.bills.map((b) => `${b.number} due ${A.day(b.due_on)}`).join(", "), actions: open(false, "Open in Procurement") })
      : A.nextCard({ tone: "wait", title: o.deliveries.length ? "Waiting for the supplier's bill" : "Waiting for the goods", text: o.deliver_by ? `Due by ${A.day(o.deliver_by)}.` : "", actions: open(false, "Open in Procurement") });
    const lines = `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end d-none d-sm-table-cell">Unit price</th><th class="text-end">KES</th><th class="text-end d-none d-md-table-cell">Received</th></tr></thead><tbody>${o.lines.map((l) => `<tr><td><span class="fw-semibold">${esc(l.description)}</span>${l.is_asset ? ' <span class="soft-chip soft-purple">Asset</span>' : ""}<div class="acc-sub">${esc(l.account || "")}</div></td><td class="text-end">${A.num(l.quantity)}</td><td class="text-end d-none d-sm-table-cell">${A.amount(l.unit_price)}</td><td class="text-end">${A.amount(l.amount)}</td><td class="text-end d-none d-md-table-cell">${A.num(l.received)} of ${A.num(l.quantity)}</td></tr>`).join("")}</tbody><tfoot><tr><th colspan="3" class="d-none d-sm-table-cell">Total</th><th class="d-sm-none" colspan="2">Total</th><th class="text-end">${A.amount(o.amount)}</th><th class="d-none d-md-table-cell"></th></tr></tfoot></table></div>`;
    const deliveries = o.deliveries.length ? `<div class="acc-quotes">${o.deliveries.map((d) => `<div class="acc-quote"><div class="min-w-0 flex-fill"><div class="fw-semibold">${esc(d.number)} · ${A.day(d.date)}</div><div class="acc-sub">${esc(d.by || "")}${d.notes ? ` · ${esc(d.notes)}` : ""}</div></div>${d.lines.some((l) => l.equipment) ? '<span class="soft-chip soft-purple"><i class="ri-tools-line"></i>In Equipment</span>' : ""}</div>`).join("")}</div>` : "";
    return {
      ok: true,
      place: o.place,
      icon: "ri-shopping-cart-2-line",
      color: ORD[s]?.[0],
      kind: "Purchase order (LPO)",
      number: o.number,
      title: o.supplier,
      amount: o.amount,
      status: pill(ORD[s]?.[0] || "primary", ORD[s]?.[1] || "ri-time-line", o.status_label),
      dir: "out",
      facts: [["Supplier", esc(o.supplier)], ["Ordered on", A.dateChip(o.date)], ["Deliver by", o.deliver_by ? A.dateChip(o.deliver_by) : "-"], ["Ordered by", A.person(o.issued_by)]],
      steps,
      next,
      sections: [{ icon: "ri-list-check-2", title: "What was ordered", html: lines }, deliveries && { icon: "ri-truck-line", title: "Deliveries", html: deliveries }],
      files: [],
      list: ["procurement", "Procurement"],
      pdfs: [{ label: "LPO (PDF)", key: "accounting.lpo", params: { record_id: o.id }, title: `LPO ${o.number}` }],
    };
  }

  const REM = { waiting: ["warning", "ri-time-line"], sent: ["primary", "ri-send-plane-line"], queried: ["danger", "ri-question-line"], confirmed: ["success", "ri-checkbox-circle-line"], cancelled: ["secondary", "ri-close-circle-line"] };

  async function remittance() {
    const res = await API.remittance(ID);
    if (!res.ok) return res;
    const m = res.data;
    const s = m.status;
    const c = m.can;
    const steps = [
      { title: "Owed", state: "done", icon: "ri-calendar-line", who: (m.lines || []).map((l) => monthName(l.month)).join(", ") },
      { title: "Voucher", state: m.voucher ? (m.voucher.status === "paid" ? "done" : "now") : s === "waiting" ? "now" : "next", icon: "ri-file-list-3-line", who: m.voucher?.number || "" },
      { title: "Sent", state: m.sent_on ? "done" : "next", icon: "ri-upload-2-line", who: m.reference || "", when: m.sent_on },
      { title: `Confirmed by ${m.to?.name || "them"}`, state: s === "confirmed" ? "done" : s === "queried" ? "stopped" : s === "sent" ? "now" : "next", icon: "ri-checkbox-circle-line", who: m.confirmed_by || "", when: m.received_on, note: s === "queried" ? m.query_reason : "" },
    ];
    const open = (mine, label) => go("remittances", { remittance: m.id }, actCls(mine), "ri-external-link-line", label);
    const next =
      s === "confirmed" ? A.nextCard({ tone: "done", title: `Received by ${m.to?.name}`, text: m.received_on ? `Confirmed on ${A.day(m.received_on)}.` : "" })
      : s === "queried" ? A.nextCard({ tone: c.answer ? "mine" : "stopped", title: "Queried", text: m.query_reason || "", actions: c.answer ? open(true, "Answer the query") : "" })
      : s === "sent" ? (c.confirm ? A.nextCard({ tone: "mine", title: "Confirm you received it", text: `Check ${A.money(m.amount)} reached your account, then confirm.`, actions: open(true, "Confirm or query") }) : A.nextCard({ tone: "wait", title: `Sent - waiting for ${m.to?.name || "them"} to confirm`, text: m.reference ? `Reference ${m.reference}.` : "" }))
      : s === "waiting" && c.pay_mpesa ? A.nextCard({ tone: "mine", title: "Pay the share by M-Pesa", text: `The voucher is authorised - send ${A.money(m.amount)} from a phone; it is confirmed the moment it arrives.`, actions: go("remittances", { remittance: m.id }, "btn-light", "ri-smartphone-line", "Pay by M-Pesa") })
      : s === "waiting" ? A.nextCard({ tone: "wait", title: "Waiting to be paid", text: m.voucher ? `Voucher ${m.voucher.number} is ${m.voucher.status}.` : "", actions: m.voucher ? go("record", { type: "voucher", id: m.voucher.id }, "btn-outline-primary", "ri-file-list-3-line", "Open the voucher") : "" })
      : A.nextCard({ tone: "stopped", title: "Cancelled", text: "" });
    const lines = (m.lines || []).length ? `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Month</th><th class="text-end">Due</th><th class="text-end">Sent</th></tr></thead><tbody>${m.lines.map((l) => `<tr><td class="fw-semibold">${esc(monthName(l.month))}</td><td class="text-end">${A.amount(l.due)}</td><td class="text-end">${A.amount(l.amount)}</td></tr>`).join("")}</tbody><tfoot><tr><th>Total</th><th></th><th class="text-end">${A.amount(m.amount)}</th></tr></tfoot></table></div>` : "";
    return {
      ok: true,
      place: m.from,
      icon: "ri-send-plane-line",
      color: REM[s]?.[0],
      kind: m.kind_label,
      number: m.number,
      title: m.purpose,
      amount: m.amount,
      status: pill(REM[s]?.[0] || "primary", REM[s]?.[1] || "ri-time-line", m.status_label),
      dir: m.from?.id === CTX.place.id ? "out" : "in",
      facts: [["From", esc(m.from?.name)], ["To", esc(m.to?.name)], ["Sent on", m.sent_on ? A.dateChip(m.sent_on) : "-"], ["Reference", esc(m.reference || "-")]],
      steps,
      next,
      sections: [lines && { icon: "ri-calendar-2-line", title: "Months", html: lines }],
      files: [],
      list: ["remittances", "Remittances"],
      pdfs: [{ label: "Advice (PDF)", key: "accounting.remittance", params: { record_id: m.id }, title: `Remittance ${m.number}` }],
    };
  }

  const COL = { counted: ["warning", "ri-time-line"], returned: ["danger", "ri-arrow-go-back-line"], posted: ["success", "ri-checkbox-circle-line"], reversed: ["secondary", "ri-arrow-go-back-line"] };

  async function collection() {
    const res = await API.collection(ID);
    if (!res.ok) return res;
    const c = res.data;
    const s = c.status;
    const steps = [
      { title: "Counted", state: "done", icon: "ri-hand-coin-line", who: c.counted_by, when: c.date },
      { title: "Confirmed", state: s === "posted" || s === "reversed" ? "done" : s === "returned" ? "stopped" : "now", icon: "ri-user-follow-line", who: c.confirmed_by || (s === "counted" ? "A second person checks the count" : ""), when: c.confirmed_at, note: c.return_reason },
      { title: "In the books", state: c.journal ? "done" : "next", icon: "ri-book-2-line", who: c.journal?.number || "" },
      { title: "Banked", state: c.banked ? "done" : c.cash_total > 0 && s === "posted" ? "now" : c.cash_total > 0 ? "next" : "skipped", icon: "ri-bank-line", who: c.banked ? c.banked.number : c.cash_total > 0 ? "" : "No cash to bank", when: c.banked?.date },
    ];
    const open = (mine, label) => go("collections", { collection: c.id }, actCls(mine), "ri-external-link-line", label);
    const k = c.can;
    const next =
      s === "counted" ? (k.confirm_this ? A.nextCard({ tone: "mine", title: "Check the count and confirm it", text: `${A.money(c.total)} counted by ${c.counted_by}. Confirming posts the receipt.`, actions: open(true, "Confirm or send back") }) : A.nextCard({ tone: "wait", title: "Waiting for a second person to confirm", text: "Someone other than whoever counted it checks the count." }))
      : s === "returned" ? A.nextCard({ tone: "stopped", title: "Sent back to recount", text: c.return_reason || "", actions: k.edit ? open(true, "Recount") : "" })
      : s === "reversed" ? A.nextCard({ tone: "stopped", title: "Reversed", text: "" })
      : c.banked || !(c.cash_total > 0) ? A.nextCard({ tone: "done", title: c.banked ? `Banked on ${A.day(c.banked.date)}` : "In the books", text: c.journal ? `Receipt ${c.journal.number}.` : "" })
      : A.nextCard({ tone: k.bank_this ? "mine" : "wait", title: "Confirmed - bank the cash", text: `${A.money(c.cash_total)} in cash to take to the bank.`, actions: k.bank_this ? open(true, "Record the banking") : "" });
    const lines = `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Given as</th><th class="text-end">Cash</th><th class="text-end">M-Pesa</th></tr></thead><tbody>${c.lines.map((l) => `<tr><td><span class="fw-semibold">${esc(l.label)}</span><div class="acc-sub">${esc(l.account?.code || "")} ${esc(l.account?.name || "")}${l.fund && l.fund.code !== "GEN" ? ` · ${esc(l.fund.name)}` : ""}</div></td><td class="text-end">${A.amount(l.cash) || "-"}</td><td class="text-end">${A.amount(l.mpesa) || "-"}</td></tr>`).join("")}</tbody><tfoot><tr><th>Total ${A.money(c.total)}</th><th class="text-end">${A.amount(c.cash_total)}</th><th class="text-end">${A.amount(c.mpesa_total)}</th></tr></tfoot></table></div>`;
    return {
      ok: true,
      place: c.place,
      icon: "ri-hand-heart-line",
      color: COL[s]?.[0],
      kind: "Collection",
      number: c.title,
      title: A.day(c.date, { weekday: "long", day: "numeric", month: "long", year: "numeric" }),
      amount: c.total,
      status: pill(COL[s]?.[0] || "primary", COL[s]?.[1] || "ri-time-line", c.status_label),
      dir: "in",
      facts: [["Counted by", A.person(c.counted_by)], ["Cash", A.money(c.cash_total)], ["M-Pesa", A.money(c.mpesa_total)], ["Witnesses", (c.witnesses || []).length ? c.witnesses.map((w) => A.person(w)).join(" ") : "-"]],
      steps,
      next,
      sections: [{ icon: "ri-list-check-2", title: "What was given", html: lines }, c.notes && { icon: "ri-sticky-note-line", title: "Notes", html: `<p class="mb-0">${esc(c.notes)}</p>` }],
      files: [],
      list: ["collections", "Collections"],
      pdfs: [{ label: "Collection sheet (PDF)", key: "accounting.collection", params: { record_id: c.id }, title: `${c.title} - ${A.day(c.date)}` }],
    };
  }

  const KINDS = { voucher, requisition, payroll, order, remittance, collection };

  // ------------------------------------------------------------ the side: chain, papers, activity

  const LINK = {
    requisition: ["ri-hand-heart-line", "primary"],
    order: ["ri-shopping-cart-2-line", "purple"],
    delivery: ["ri-truck-line", "info"],
    bill: ["ri-file-list-2-line", "warning"],
    voucher: ["ri-file-list-3-line", "danger"],
    payroll: ["ri-money-dollar-box-line", "pink"],
    remittance: ["ri-send-plane-line", "primary"],
    advance: ["ri-wallet-3-line", "warning"],
    journal: ["ri-book-2-line", "success"],
  };

  function chain(links) {
    if (!links.length) return '<p class="acc-muted-line mb-0">Nothing linked to it yet.</p>';
    return `<ol class="acc-chain">${links
      .map((l) => {
        const [icon, color] = LINK[l.type] || ["ri-links-line", "secondary"];
        const inner = `<span class="avatar avatar-sm avatar-rounded bg-${color} ${A.textOn(color)} flex-shrink-0"><i class="${icon}"></i></span><span class="min-w-0 flex-fill"><span class="acc-chain-label">${esc(l.label)}</span><strong class="d-block acc-chain-num">${esc(l.number || "-")}</strong>${l.status_label ? `<small>${esc(l.status_label)}${l.date ? ` · ${A.day(l.date)}` : ""}</small>` : ""}</span>${l.amount ? `<span class="acc-chain-amount">${A.money(l.amount)}</span>` : ""}`;
        if (!l.opens) return `<li><div class="acc-chain-item">${inner}</div></li>`;
        if (l.type === "journal") return `<li><button type="button" class="acc-chain-item" data-journal="${l.id}">${inner}</button></li>`;
        return `<li><a class="acc-chain-item" href="${A.link("record.php", { type: l.type, id: l.id })}">${inner}</a></li>`;
      })
      .join("")}</ol>`;
  }

  const TONE = { primary: "primary", success: "success", danger: "danger", warning: "warning", secondary: "secondary", purple: "purple" };
  function activity(events) {
    if (!events.length) return '<p class="acc-muted-line mb-0">Nothing yet.</p>';
    return `<ul class="acc-activity">${events
      .map((e) => {
        const t = TONE[e.tone] || "primary";
        return `<li><span class="avatar avatar-xs avatar-rounded bg-${t} ${A.textOn(t)}"><i class="${e.icon}"></i></span><div class="min-w-0"><div class="acc-act-text">${e.who ? `<strong>${esc(e.who)}</strong> ` : ""}${esc(e.who ? e.text.charAt(0).toLowerCase() + e.text.slice(1) : e.text)}</div>${e.note ? `<div class="acc-act-note">"${esc(e.note)}"</div>` : ""}<small>${esc(when(e.at))}</small></div></li>`;
      })
      .join("")}</ul>`;
  }

  function files(rec) {
    if (!rec.files || !rec.files.length) return '<p class="acc-muted-line mb-0">No papers attached.</p>';
    return `<div class="acc-files">${rec.files.map((f) => `<div class="acc-file"><i class="${f.mime === "application/pdf" ? "ri-file-pdf-line text-danger" : "ri-image-line text-primary"}"></i><button type="button" class="btn btn-link p-0 text-start flex-fill" data-file="${f.id}">${esc(f.name)}</button></div>`).join("")}</div>`;
  }

  const card = (icon, title, body, extra = "", color = "primary") => `<div class="card custom-card acc-rec-card"><div class="card-header"><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-${color}"><i class="${icon}"></i></span>${esc(title)}</div>${extra}</div><div class="card-body">${body}</div></div>`;

  // ------------------------------------------------------------ the page

  function render(rec, trail) {
    document.title = `${rec.number} - ${rec.kind} - Makueni West Diocese`;
    const h1 = document.querySelector(".page-title");
    if (h1) h1.innerHTML = `<i class="${rec.icon} me-2"></i>${esc(rec.kind)}`;
    const crumb = document.querySelector(".breadcrumb-item.active");
    if (crumb) crumb.textContent = rec.number;
    $("recBack").href = A.link(`${rec.list[0]}.php`);
    $("recBack").innerHTML = `<i class="ri-arrow-left-line me-1"></i>${esc(rec.list[1])}`;
    if (rec.place) A.placeLine($("accPlaceLine"), rec.place);

    const hero = `<div class="card custom-card acc-rec-hero"><div class="card-body">
      <div class="acc-rec-top">
        <span class="avatar avatar-lg avatar-rounded bg-${rec.color || "primary"} ${A.textOn(rec.color || "primary")} flex-shrink-0"><i class="${rec.icon} fs-22"></i></span>
        <div class="min-w-0 flex-fill"><span class="acc-rec-kind">${esc(rec.kind)}</span><h4 class="acc-rec-number">${esc(rec.number)}</h4><p class="acc-rec-title">${esc(rec.title || "")}</p><div>${rec.status}</div></div>
        <div class="acc-rec-amount${rec.dir ? ` is-${rec.dir}` : ""}"><span>${esc(rec.amountLabel || "Amount")}</span>${A.figure(rec.amount)}${(rec.pdfs || []).length ? `<div class="acc-rec-pdfs">${rec.pdfs.map((p, i) => `<button type="button" class="btn btn-sm ${i ? "btn-outline-primary" : "btn-primary"}" data-pdf="${i}"><i class="ri-file-pdf-line me-1"></i>${esc(p.label)}</button>`).join("")}</div>` : ""}</div>
      </div>
      <div class="acc-rec-facts">${rec.facts.map(([k, v]) => `<div><span>${esc(k)}</span><strong>${v || "-"}</strong></div>`).join("")}</div>
    </div></div>`;
    const where = card("ri-route-line", "Where it stands", A.journey(rec.steps) + rec.next, "", "warning");
    const sections = rec.sections.filter(Boolean).map((s, i) => card(s.icon, s.title, s.html, "", s.color || ["purple", "primary", "success", "info"][i % 4])).join("");
    $("recApp").innerHTML = `<div class="row">
      <div class="col-xl-8">${hero}${where}${sections}</div>
      <div class="col-xl-4">
        ${card("ri-links-line", "Linked documents", chain(trail.links), "", "success")}
        ${card("ri-attachment-2", "Papers", files(rec), "", "pink")}
        ${card("ri-history-line", "What happened", activity(trail.events), `<span class="soft-chip soft-primary">${trail.events.length}</span>`, "purple")}
      </div>
    </div>`;
  }

  async function decide(rec, decision) {
    if (decision === "approve") {
      const el = PeopleKit.confirmWindow({
        title: `Approve ${rec.number}`,
        subtitle: `${A.money(rec.amount)} - ${rec.title || rec.kind}`,
        icon: "ri-check-line",
        go: '<i class="ri-check-line me-1"></i>Approve',
        body: PeopleKit.parts([{ icon: "ri-chat-3-line", title: "A note (optional)", body: '<textarea class="form-control" id="recNote" rows="2" maxlength="500" placeholder="e.g. Pay by Friday"></textarea>' }]),
        run: async () => {
          const r = await rec.decide("approve", document.getElementById("recNote").value.trim() || null);
          if (r.ok) setTimeout(load, 300);
          return r;
        },
      });
      return el;
    }
    W.reasonWindow({
      title: decision === "reject" ? "Reject it" : "Send it back",
      subtitle: decision === "reject" ? "It stops here - say why" : "Say what needs changing; whoever asked can fix it and send it again",
      go: decision === "reject" ? "Reject" : "Send back",
      run: (comment) => rec.decide(decision, comment),
      onDone: load,
    });
  }

  let current = null;
  async function load() {
    if (!KINDS[TYPE] || !ID) {
      $("recApp").innerHTML = A.errorBox("That record could not be found.");
      return;
    }
    const tr = await API.trail(TYPE, ID);
    if (!tr.ok) {
      $("recApp").innerHTML = A.errorBox(tr.message);
      return;
    }
    const rec = await KINDS[TYPE](tr.data.links);
    if (!rec.ok) {
      $("recApp").innerHTML = A.errorBox(rec.message);
      return;
    }
    current = rec;
    render(rec, tr.data);
  }

  function init() {
    $("recApp").addEventListener("click", async (e) => {
      if (e.target.closest("[data-open]")) return current?.open?.();
      const pdfBtn = e.target.closest("[data-pdf]");
      if (pdfBtn) {
        const p = current.pdfs[Number(pdfBtn.dataset.pdf)];
        return A.pdf(p.key, p.params, p.title);
      }
      const d = e.target.closest("[data-decide]");
      if (d && current?.decide) return decide(current, d.dataset.decide);
      const j = e.target.closest("[data-journal]");
      if (j) return W.viewJournal(Number(j.dataset.journal), { onChange: load });
      const f = e.target.closest("[data-file]");
      if (f && current?.fileUrl) {
        const w = window.open("", "_blank");
        const u = await current.fileUrl(Number(f.dataset.file));
        u ? (w.location = u) : (w.close(), Toast.error("That file could not be opened."));
      }
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
