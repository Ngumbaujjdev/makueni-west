/**
 * ============================================================================
 * ACCOUNTING - All documents (documents.php)
 * ============================================================================
 * Cards (money in, money out, transfers, documents) and every document of
 * the year - pills by kind, search, sort; open one for its lines and papers.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const L = AccountingDocList;
  const W = AccountingWindows;
  const $ = (id) => document.getElementById(id);
  let list = null;

  function cards(items) {
    const live = items.filter((j) => j.status !== "reversed" && j.doc_type !== "reversal");
    const sum = (t) => live.filter((j) => j.doc_type === t).reduce((s, j) => s + j.amount, 0);
    PeopleKit.statRow($("statCardsRow"), [
      { icon: "ri-arrow-down-circle-line", label: "Receipts", sub: `${A.num(live.filter((j) => j.doc_type === "receipt").length)} written`, value: A.figure(sum("receipt")), color: "success" },
      { icon: "ri-arrow-up-circle-line", label: "Payments", sub: `${A.num(live.filter((j) => j.doc_type === "payment").length)} paid`, value: A.figure(sum("payment")), color: "danger" },
      { icon: "ri-arrow-left-right-line", label: "Transfers", sub: "Between our own accounts", value: A.figure(sum("transfer")), color: "primary" },
      { icon: "ri-file-list-3-line", label: "Documents", sub: `${A.num(items.filter((j) => j.status === "reversed").length)} reversed`, value: A.num(items.length), color: "purple" },
    ]);
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("statCardsRow").innerHTML = DemographicsUI.skeletonCards(4, "col-xl-3 col-sm-6");
    const kinds = ["receipt", "payment", "transfer", "journal", "reversal"].map((t) => ({ key: t, label: `${A.doc(t).label}s`, icon: A.doc(t).icon, color: A.doc(t).color, test: (j) => j.doc_type === t }));
    list = L.mount({ type: null, pills: [...kinds, L.NOFILES_PILL], cards, emptyText: "Receipts, payments, transfers and journals show here as they are written." });
    $("receiptBtn")?.addEventListener("click", () => W.receipt({ onDone: () => list.reload() }));
  });
})();
