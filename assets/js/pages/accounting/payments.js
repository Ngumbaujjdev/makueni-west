/**
 * ============================================================================
 * ACCOUNTING - Payment vouchers (payments.php)
 * ============================================================================
 * Cards (waiting to be authorised, to pay, sent back, paid this month) and
 * the vouchers - pills by where they stand, opening on what is waiting for
 * this person (an authoriser sees "Waiting", whoever pays "To pay"). A row
 * opens the voucher with its next step.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let kit = null;
  let items = [];

  function cards() {
    const of = (s) => items.filter((v) => v.status === s);
    const sum = (arr) => arr.reduce((t, v) => t + v.amount, 0);
    const ym = new Date().toISOString().slice(0, 7);
    const paidMonth = of("paid").filter((v) => (v.paid_on || "").startsWith(ym));
    K.statRow($("statCardsRow"), [
      { icon: "ri-time-line", label: "Waiting to be authorised", sub: A.money(sum(of("prepared"))), value: A.num(of("prepared").length), color: "warning" },
      { icon: "ri-shield-check-line", label: "Authorised - to pay", sub: A.money(sum(of("authorised"))), value: A.num(of("authorised").length), color: "primary" },
      { icon: "ri-arrow-go-back-line", label: "Sent back", sub: "To fix and send again", value: A.num(of("rejected").length), color: "danger" },
      { icon: "ri-checkbox-circle-line", label: "Paid this month", sub: `${A.num(paidMonth.length)} vouchers`, value: A.figure(sum(paidMonth)), color: "success" },
    ]);
  }

  const rowHtml = (v) => `<tr class="acc-row" data-id="${v.id}" data-pills="${v.status}${v.prepared_by_id === CTX.userId ? " mine" : ""}${v.attachments ? "" : " nofiles"}">
    ${K.checkCell(v.id, v.number)}
    <td data-search="${esc(`${v.number} ${v.payee_name} ${v.narration} ${(v.charged_to || []).join(" ")} ${v.reference || ""}`)}" data-order="${esc(v.number)}">
      <div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${A.VOUCHER[v.status].color} ${A.textOn(A.VOUCHER[v.status].color)}"><i class="${A.VOUCHER[v.status].icon}"></i></span><div class="min-w-0"><div class="fw-semibold">${esc(v.number)}</div><div class="acc-sub text-truncate">${esc(v.narration)}</div></div></div>
    </td>
    <td data-order="${v.date}${String(v.id).padStart(8, "0")}" class="text-nowrap">${A.day(v.date)}</td>
    <td class="d-none d-md-table-cell"><span class="fw-semibold">${esc(v.payee_name)}</span><div class="acc-sub">${esc((v.charged_to || []).join(", "))}</div></td>
    <td class="d-none d-lg-table-cell acc-steps-cell">${A.mini(["Prepared", "Authorised", "Paid"], { prepared: 1, authorised: 2, paid: 3, rejected: 1, cancelled: 1 }[v.status] ?? 0, { stop: v.status === "rejected" ? "Sent back" : v.status === "cancelled" ? "Cancelled" : null })}<div class="acc-sub mt-1">${esc(v.status === "paid" ? `Paid ${A.day(v.paid_on)}${v.reference ? ` · ${v.reference}` : ""}` : v.status === "authorised" ? `By ${v.authorised_by || ""}` : v.status === "rejected" ? v.reject_reason || "" : `By ${v.prepared_by || ""}`)}</div></td>
    <td class="text-end" data-order="${v.amount}"><strong>${A.money(v.amount)}</strong><div class="acc-sub">from ${esc(v.pay_from?.name || "")}</div><div class="mt-1">${A.pdfButton("accounting.voucher", { record_id: v.id }, `Voucher ${v.number}`, "Voucher")}</div></td>
  </tr>`;

  async function load() {
    kit?.destroy();
    A.ownOnly();
    $("pvRows").innerHTML = DemographicsUI.renderTableLoading(6);
    const res = await AccountingAPI.vouchers();
    if (!res.ok) {
      $("pvTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    items = res.data.items;
    A.placeLine($("accPlaceLine"), res.data.place);
    cards();
    if (!items.length) {
      $("pvFilters").innerHTML = "";
      $("pvPills").innerHTML = "";
      $("pvRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-file-list-3-line", "No payment vouchers yet", "Every payment starts as a voucher: prepared, authorised by someone else, then paid.", res.data.can.prepare && !A.viewingBelow() ? '<button type="button" class="btn btn-primary" data-first><i class="ri-add-line me-1"></i>Prepare a payment</button>' : "")}</td></tr>`;
      return;
    }
    const can = res.data.can;
    const S = A.VOUCHER;
    const pill = (k) => ({ key: k, label: S[k].short, icon: S[k].icon, color: S[k].color, test: (v) => v.status === k });
    const home = can.authorise && items.some((v) => v.status === "prepared") ? "prepared" : can.pay && items.some((v) => v.status === "authorised") ? "authorised" : "all";
    kit = K.listTable({
      tableId: "pvTable",
      stripId: "pvFilters",
      pillsId: "pvPills",
      rowsId: "pvRows",
      items,
      rowHtml,
      noun: "vouchers",
      defaultPill: home,
      searchPlaceholder: "Search number, payee, what for, reference...",
      pills: [pill("prepared"), pill("authorised"), pill("rejected"), pill("paid"), pill("cancelled"), { key: "mine", label: "Prepared by me", icon: "ri-user-line", color: "pink", test: (v) => v.prepared_by_id === CTX.userId }],
      sorts: [
        { key: "new", label: "Newest first", order: [[2, "desc"]] },
        { key: "old", label: "Oldest first", order: [[2, "asc"]] },
        { key: "big", label: "Largest first", order: [[5, "desc"]] },
      ],
      actions: [],
    });
  }

  function init() {
    $("statCardsRow").innerHTML = DemographicsUI.skeletonCards(4, "col-xl-3 col-sm-6");
    const prepare = () => W.voucher({ onDone: load });
    $("voucherBtn")?.addEventListener("click", prepare);
    $("pvRows").addEventListener("click", (e) => {
      if (e.target.closest("[data-first]")) return prepare();
      if (e.target.closest("a, input, .pp-check, button")) return;
      const tr = e.target.closest("tr[data-id]");
      if (tr) window.location.href = A.link("record.php", { type: "voucher", id: tr.dataset.id });
    });
    const open = new URLSearchParams(window.location.search).get("voucher");
    if (open) W.viewVoucher(Number(open), { onChange: load });
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
