/**
 * ============================================================================
 * ACCOUNTING - Cashbook (cashbook.php)
 * ============================================================================
 * One money account at a time (tiles to switch), a period (this month, last
 * month, this year, or two dates): brought forward, money in, money out and
 * carried forward, then every movement with the balance after it - the book
 * a treasurer keeps by hand, kept for them. Print or download as CSV.
 * Account and dates live in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const pad = (n) => String(n).padStart(2, "0");
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  let book = null;

  function range(key) {
    const t = new Date();
    if (key === "last") return [iso(new Date(t.getFullYear(), t.getMonth() - 1, 1)), iso(new Date(t.getFullYear(), t.getMonth(), 0))];
    if (key === "year") return [iso(new Date(t.getFullYear(), 0, 1)), iso(t)];
    return [iso(new Date(t.getFullYear(), t.getMonth(), 1)), iso(t)];
  }

  function syncUrl() {
    const p = new URLSearchParams(window.location.search);
    if (book) p.set("account_id", book.account.id);
    p.set("from", $("fromDate").value);
    p.set("to", $("toDate").value);
    history.replaceState(null, "", `${window.location.pathname}?${p}`);
  }

  function tiles() {
    const current = String(book.account.id);
    $("accountTiles").innerHTML = book.accounts
      .map((a) => {
        const k = A.kind(a.cash_kind);
        return `<label class="acc-tile" style="--q: var(--${k.color}-rgb)"><input type="radio" name="cbAccount" value="${a.id}"${String(a.id) === current ? " checked" : ""}><span class="acc-tile-icon"><i class="${k.icon}"></i></span><span class="acc-tile-text"><strong>${esc(a.name)}</strong><small>${A.money(a.balance)}</small></span></label>`;
      })
      .join("");
  }

  function stats() {
    const k = A.kind(book.account.cash_kind);
    K.statRow($("cbStats"), [
      { icon: "ri-skip-back-line", label: "Brought forward", sub: `On ${A.day(book.from)}`, value: A.figure(book.opening), color: "secondary" },
      { icon: "ri-arrow-down-circle-line", label: "Money in", sub: `${book.rows.filter((r) => r.in).length} receipts and transfers in`, value: A.figure(book.in), color: "success" },
      { icon: "ri-arrow-up-circle-line", label: "Money out", sub: `${book.rows.filter((r) => r.out).length} payments and transfers out`, value: A.figure(book.out), color: "danger" },
      { icon: k.icon, label: "Carried forward", sub: `On ${A.day(book.to)}`, value: A.figure(book.closing), color: k.color },
    ]);
  }

  function rows() {
    $("cbTitle").textContent = `Cashbook - ${book.account.name}`;
    $("cbSub").textContent = `${A.day(book.from)} to ${A.day(book.to)} · ${book.rows.length} ${book.rows.length === 1 ? "movement" : "movements"}`;
    // Brought and carried forward stand outside the table, so sorting and filtering never move them.
    $("cbOpen").innerHTML = `<span><i class="ri-login-box-line me-1"></i>Balance brought forward · ${A.day(book.from)}</span><strong>${A.money(book.opening)}</strong>`;
    $("cbClose").innerHTML = `<span><i class="ri-logout-box-r-line me-1"></i>Balance carried forward · ${A.day(book.to)}</span><span class="acc-bf-sums"><span class="text-success">In ${A.money(book.in)}</span><span class="text-danger">Out ${A.money(book.out)}</span><strong>${A.money(book.closing)}</strong></span>`;
    A.tableKit({
      tableId: "cbTable",
      items: book.rows,
      noun: "movements",
      search: "Search name, number, reference...",
      pageLength: 100,
      pills: [
        { key: "in", label: "Money in", icon: "ri-arrow-left-down-line", color: "success", test: (r) => r.in > 0 },
        { key: "out", label: "Money out", icon: "ri-arrow-right-up-line", color: "danger", test: (r) => r.out > 0 },
        { key: "transfer", label: "Transfers", icon: "ri-arrow-left-right-line", color: "primary", test: (r) => r.doc_type === "transfer" },
        { key: "reversed", label: "Reversed", icon: "ri-arrow-go-back-line", color: "secondary", test: (r) => r.reversed },
      ],
      sorts: [
        { key: "date", label: "In date order", order: [[0, "asc"]] },
        { key: "new", label: "Newest first", order: [[0, "desc"]] },
        { key: "in", label: "Largest in", order: [[3, "desc"]] },
        { key: "out", label: "Largest out", order: [[4, "desc"]] },
      ],
      empty: A.empty("ri-book-open-line", "No money moved in this period", "Pick another period or account."),
      rowHtml: (r, i) => `<tr class="acc-row${r.reversed ? " is-reversed" : ""}" data-journal="${r.journal_id}" data-pills="${r.in > 0 ? "in" : "out"} ${r.doc_type}${r.reversed ? " reversed" : ""}">
        <td class="text-nowrap" data-order="${r.date}-${String(book.rows.indexOf(r)).padStart(6, "0")}">${A.day(r.date, { day: "numeric", month: "short" })}</td>
        <td><div class="d-flex align-items-center gap-2">${A.docTile(r.doc_type, "xs")}<span class="fw-semibold text-nowrap">${esc(r.number.split("/").slice(-3).join("/"))}</span></div></td>
        <td class="acc-details" data-search="${esc([r.number, r.party, r.details, r.reference, ...(r.against || [])].join(" "))}"><div class="fw-semibold text-truncate">${esc(r.party || r.details || A.doc(r.doc_type).label)}</div><div class="acc-sub text-truncate">${esc((r.against || []).join(", "))}${r.reference ? ` · ${esc(r.reference)}` : ""}${r.reversed ? " · reversed" : ""}</div></td>
        <td class="text-end text-success" data-order="${r.in}">${A.amount(r.in)}</td>
        <td class="text-end text-danger" data-order="${r.out}">${A.amount(r.out)}</td>
        <td class="text-end fw-semibold${r.balance < 0 ? " text-danger" : ""}" data-order="${r.balance}">${A.amount(r.balance) || "0.00"}</td>
      </tr>`,
    });
  }

  async function load(accountId) {
    $("cbRows").innerHTML = DemographicsUI.renderTableLoading(6);
    A.ownOnly();
    const res = await AccountingAPI.cashbook({ account_id: accountId || new URLSearchParams(window.location.search).get("account_id"), from: $("fromDate").value, to: $("toDate").value });
    if (!res.ok) {
      $("cbRows").innerHTML = `<tr><td colspan="6">${A.errorBox(res.message)}</td></tr>`;
      return;
    }
    book = res.data;
    A.placeLine($("accPlaceLine"), book.place);
    tiles();
    stats();
    rows();
    syncUrl();
    document.querySelectorAll("#rangeBtns [data-range]").forEach((b) => {
      const [f, t] = range(b.dataset.range);
      b.classList.toggle("active", f === book.from && t === book.to);
    });
  }

  function csv() {
    if (!book) return;
    const cell = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
    const lines = [["Date", "Document", "From / to", "Details", "Against", "Reference", "In", "Out", "Balance"].map(cell).join(",")];
    lines.push([book.from, "", "Balance brought forward", "", "", "", "", "", book.opening].map(cell).join(","));
    book.rows.forEach((r) => lines.push([r.date, r.number, r.party, r.details, (r.against || []).join("; "), r.reference, r.in || "", r.out || "", r.balance].map(cell).join(",")));
    lines.push([book.to, "", "Balance carried forward", "", "", "", book.in, book.out, book.closing].map(cell).join(","));
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob([lines.join("\n")], { type: "text/csv" }));
    a.download = `Cashbook ${book.account.name} ${book.from} to ${book.to}.csv`;
    a.click();
  }

  function init() {
    const q = new URLSearchParams(window.location.search);
    const [f, t] = range("month");
    $("fromDate").value = q.get("from") || f;
    $("toDate").value = q.get("to") || t;
    if (window.DateField) [$("fromDate"), $("toDate")].forEach((x) => DateField.enhance(x));
    $("accountTiles").addEventListener("change", (e) => e.target.name === "cbAccount" && load(e.target.value));
    $("rangeBtns").addEventListener("click", (e) => {
      const b = e.target.closest("[data-range]");
      if (!b) return;
      const [ff, tt] = range(b.dataset.range);
      window.DateField ? (DateField.set($("fromDate"), ff), DateField.set($("toDate"), tt)) : (($("fromDate").value = ff), ($("toDate").value = tt));
      load(book?.account.id);
    });
    [$("fromDate"), $("toDate")].forEach((x) => x.addEventListener("change", () => book && load(book.account.id)));
    $("cbRows").addEventListener("click", (e) => {
      const tr = e.target.closest("[data-journal]");
      if (tr) W.viewJournal(Number(tr.dataset.journal), { onChange: () => load(book.account.id) });
    });
    // The cashbook as a book: the diocese PDF with its cover (accounting.cashbook).
    $("printBtn").addEventListener("click", () => book && A.pdf("accounting.cashbook", { account_id: book.account.id, account_label: book.account.name, date_from: book.from, date_to: book.to }, `Cashbook - ${book.account.name}`));
    $("csvBtn").addEventListener("click", csv);
    $("receiptBtn")?.addEventListener("click", () => W.receipt({ onDone: () => load(book?.account.id), prefill: { account_id: book?.account.id } }));
    A.placePicker($("accPlacePick"), () => {
      const p = new URLSearchParams(window.location.search);
      p.delete("account_id");
      history.replaceState(null, "", `${window.location.pathname}?${p}`);
      load();
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
