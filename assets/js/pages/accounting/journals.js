/**
 * ============================================================================
 * ACCOUNTING - Journals (journals.php)
 * ============================================================================
 * The journals (opening balances, corrections) and their reversals, and the
 * trial balance beside them - every account's debit or credit balance, and
 * whether the two sides agree.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const L = AccountingDocList;
  const W = AccountingWindows;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let list = null;

  async function trialBalance() {
    $("tbRows").innerHTML = DemographicsUI.renderTableLoading(3, "Loading...", 4);
    const res = await AccountingAPI.trialBalance();
    if (!res.ok) {
      $("tbRows").innerHTML = `<tr><td colspan="3">${A.errorBox(res.message)}</td></tr>`;
      return;
    }
    const t = res.data;
    $("tbBadge").innerHTML = t.lines.length ? (t.balanced ? '<span class="badge bg-success"><i class="ri-check-line me-1"></i>Balanced</span>' : '<span class="badge bg-danger">Out of balance</span>') : "";
    $("tbRows").innerHTML = t.lines.length
      ? t.lines.map((l) => `<tr><td><span class="acc-sub">${esc(l.code)}</span> <span class="fw-semibold">${esc(l.name)}</span></td><td class="text-end">${A.amount(l.debit)}</td><td class="text-end">${A.amount(l.credit)}</td></tr>`).join("")
      : `<tr><td colspan="3">${A.empty("ri-scales-3-line", "Nothing posted yet", "Balances show here once money is recorded.")}</td></tr>`;
    $("tbFoot").innerHTML = t.lines.length ? `<tr><th>Total</th><th class="text-end">${A.amount(t.debit)}</th><th class="text-end">${A.amount(t.credit)}</th></tr>` : "";
  }

  document.addEventListener("DOMContentLoaded", () => {
    const reload = () => {
      list.reload();
      trialBalance();
    };
    list = L.mount({
      type: null,
      only: ["journal", "reversal"],
      pills: [
        { key: "journal", label: "Journals", icon: A.doc("journal").icon, color: "purple", test: (j) => j.doc_type === "journal" },
        { key: "reversal", label: "Reversals", icon: A.doc("reversal").icon, color: "secondary", test: (j) => j.doc_type === "reversal" },
        L.REVERSED_PILL,
      ],
      emptyIcon: "ri-book-2-line",
      emptyTitle: "No journals yet",
      emptyText: "Start with the opening balances: what each account held on the day you began.",
      onLoad: () => trialBalance(),
    });
    $("journalBtn")?.addEventListener("click", () => W.journal({ onDone: reload }));
    $("openingBtn")?.addEventListener("click", () => W.journal({ opening: true, onDone: reload }));
  });
})();
