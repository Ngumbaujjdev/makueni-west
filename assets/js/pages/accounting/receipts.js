/**
 * ============================================================================
 * ACCOUNTING - Receipts (receipts.php)
 * ============================================================================
 * Cards (received this year, this month, by M-Pesa, without papers) and the
 * receipts list - by how they were paid, search, sort; open one to print it.
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
    const live = items.filter((j) => j.status !== "reversed");
    const sum = (arr) => arr.reduce((s, j) => s + j.amount, 0);
    const ym = new Date().toISOString().slice(0, 7);
    const month = live.filter((j) => j.date.startsWith(ym));
    const mpesa = live.filter((j) => j.method === "mpesa");
    const bare = live.filter((j) => !j.attachments);
    PeopleKit.statRow($("statCardsRow"), [
      { icon: "ri-bill-line", label: "Received", sub: `${A.num(live.length)} receipts`, value: A.figure(sum(live)), color: "success" },
      { icon: "ri-calendar-check-line", label: "This month", sub: `${A.num(month.length)} receipts`, value: A.figure(sum(month)), color: "primary" },
      { icon: "ri-smartphone-line", label: "By M-Pesa", sub: `${live.length ? Math.round((mpesa.length / live.length) * 100) : 0}% of receipts`, value: A.figure(sum(mpesa)), color: "purple" },
      { icon: "ri-attachment-2", label: "Without papers", sub: "No photo or PDF attached", value: A.num(bare.length), color: "warning" },
    ]);
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("statCardsRow").innerHTML = DemographicsUI.skeletonCards(4, "col-xl-3 col-sm-6");
    list = L.mount({
      type: "receipt",
      pills: [...L.METHOD_PILLS, L.NOFILES_PILL, L.REVERSED_PILL],
      cards,
      emptyIcon: "ri-bill-line",
      emptyTitle: "No receipts yet",
      emptyText: "Write one for every amount received - tithes, offerings, contributions.",
    });
    $("receiptBtn")?.addEventListener("click", () => W.receipt({ onDone: () => list.reload() }));
  });
})();
