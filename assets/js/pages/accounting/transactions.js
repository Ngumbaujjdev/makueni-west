/**
 * ============================================================================
 * ACCOUNTING - Transactions (transactions.php, every level)
 * ============================================================================
 * Every attempt to pay (docs/specs/accounting-spec.md, A10e) - gifts on the
 * giving page, M-Pesa prompts, paybill payments typed by hand - paid,
 * waiting or not paid, with why; the way v1-events lists its transactions.
 * A row opens what happened when (every callback included); a waiting one
 * can be checked again with M-Pesa or Paystack.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const K = PeopleKit;
  const API = AccountingAPI;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  let kit = null;
  let period = new URLSearchParams(window.location.search).get("period") || "30";
  let placeId = Number(new URLSearchParams(window.location.search).get("place")) || null;

  const ST = { paid: ["success", "ri-checkbox-circle-line"], pending: ["warning", "ri-time-line"], failed: ["danger", "ri-close-circle-line"], abandoned: ["secondary", "ri-close-line"], refunded: ["danger", "ri-arrow-go-back-line"], to_sort: ["warning", "ri-question-line"], returned: ["secondary", "ri-arrow-go-back-line"] };
  const PERIODS = { 1: "Today", 7: "7 days", 30: "30 days", month: "This month", year: "This year" };
  const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const range = () => {
    const now = new Date();
    if (period === "month") return [iso(new Date(now.getFullYear(), now.getMonth(), 1)), iso(now)];
    if (period === "year") return [`${now.getFullYear()}-01-01`, iso(now)];
    return [iso(new Date(now.getTime() - (Number(period) - 1) * 86400000)), iso(now)];
  };
  const time = (s) => (s ? new Date(s).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "");
  const pill = (r) => `<span class="badge bg-${ST[r.status]?.[0] || "secondary"} ${A.textOn(ST[r.status]?.[0] || "secondary")}"><i class="${ST[r.status]?.[1] || "ri-question-line"} me-1"></i>${esc(r.status_label)}</span>`;

  function cards() {
    const s = data.stats;
    K.statRow($("statCardsRow"), [
      { icon: "ri-checkbox-circle-line", label: "Paid", sub: `${s.paid_count} payments · M-Pesa ${A.money(s.by_method.mpesa)} · card ${A.money(s.by_method.card)}`, value: A.money(s.paid), color: "success" },
      { icon: "ri-percent-line", label: "Went through", sub: "Of the ones that finished", value: s.success_rate === null ? "-" : `${s.success_rate}%`, color: "primary" },
      { icon: "ri-close-circle-line", label: "Not paid", sub: "Cancelled, timed out, refused", value: A.num(s.failed), color: "danger" },
      { icon: "ri-time-line", label: "Waiting", sub: s.to_sort ? `${s.to_sort} paybill payments to sort` : `Fees ${A.money(s.fees)}`, value: A.num(s.pending), color: "warning" },
    ]);
    $("txLate").innerHTML = s.waiting_long
      ? `<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i class="ri-time-line fs-5"></i><div class="flex-fill"><strong>${s.waiting_long} ${s.waiting_long === 1 ? "payment has" : "payments have"} been waiting more than 10 minutes.</strong> The answer may not have come back - they are checked again on their own every few minutes.</div>${data.can.check && !A.viewingBelow() ? '<button type="button" class="btn btn-sm btn-warning" data-check-all><i class="ri-refresh-line me-1"></i>Check them now</button>' : ""}</div>`
      : "";
    $("txCheckAll").hidden = !data.can.check || !s.pending;
  }

  const rowHtml = (r) => `<tr class="acc-row" data-key="${r.key}" data-pills="${r.status === "abandoned" ? "failed" : r.status} ${r.method}">
    ${K.checkCell(r.key, r.reference)}
    <td data-order="${r.when}" class="text-nowrap"><div class="d-flex align-items-center gap-2">${A.dateTile(r.when)}<small class="acc-sub">${esc(time(r.when).split(", ")[1] || "")}</small></div></td>
    <td data-search="${esc(`${r.payer.name || ""} ${r.payer.phone || ""} ${r.payer.email || ""} ${r.reference} ${r.code || ""} ${r.account_ref || ""}`)}"><div class="fw-semibold">${esc(r.payer.name || r.payer.phone || "Unknown payer")}</div><div class="acc-sub">${esc([r.payer.phone, r.reference].filter(Boolean).join(" · "))}</div></td>
    <td class="d-none d-md-table-cell"><div class="fw-semibold">${esc(r.purpose)}</div><div class="acc-sub">${esc(r.kind)}${r.place?.name && data.place.level !== "church" ? ` · ${esc(r.place.name)}` : ""}</div></td>
    <td class="d-none d-lg-table-cell">${A.methodChip(r.method)}<div class="acc-sub mt-1">${esc(r.route)}</div></td>
    <td>${pill(r)}${r.disputed ? ' <span class="badge bg-danger">Disputed</span>' : ""}<div class="acc-sub mt-1 text-truncate" style="max-width: 16rem">${esc(r.status === "paid" ? [r.code, r.receipt].filter(Boolean).join(" · ") : r.reason || (r.status === "pending" ? "Waiting for the answer" : ""))}</div></td>
    <td class="text-end" data-order="${r.amount}"><strong>${A.money(r.amount)}</strong>${r.fee ? `<div class="acc-sub">fee ${A.money(r.fee)}</div>` : ""}</td>
  </tr>`;

  function list() {
    kit?.destroy();
    if (!data.rows.length) {
      $("txPills").innerHTML = "";
      $("txFilters").innerHTML = "";
      $("txRows").innerHTML = `<tr><td colspan="7">${A.empty("ri-exchange-dollar-line", "No payments in this period", "Gifts on the giving page, M-Pesa prompts and paybill payments show here - the ones that didn't go through too.")}</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "txTable", stripId: "txFilters", pillsId: "txPills", rowsId: "txRows", items: data.rows, rowHtml, noun: "payments", defaultPill: "all",
      searchPlaceholder: "Search payer, phone, reference, M-Pesa code...",
      pills: [
        { key: "paid", label: "Paid", icon: ST.paid[1], color: "success", test: (r) => r.status === "paid" },
        { key: "pending", label: "Waiting", icon: ST.pending[1], color: "warning", test: (r) => r.status === "pending" },
        { key: "failed", label: "Not paid", icon: ST.failed[1], color: "danger", test: (r) => r.status === "failed" || r.status === "abandoned" },
        { key: "refunded", label: "Refunded", icon: ST.refunded[1], color: "danger", test: (r) => r.status === "refunded" },
        { key: "to_sort", label: "To sort", icon: ST.to_sort[1], color: "warning", test: (r) => r.status === "to_sort" },
        { key: "mpesa", label: "M-Pesa", icon: "ri-smartphone-line", color: "success", test: (r) => r.method === "mpesa" },
        { key: "card", label: "Card", icon: "ri-bank-card-line", color: "primary", test: (r) => r.method === "card" },
      ],
      sorts: [{ key: "new", label: "Newest first", order: [[1, "desc"]] }, { key: "big", label: "Largest first", order: [[6, "desc"]] }],
      actions: [],
    });
  }

  function controls() {
    $("txPeriods").innerHTML = Object.entries(PERIODS).map(([k, l]) => `<button type="button" class="btn btn-sm ${k === String(period) ? "btn-primary" : "btn-outline-primary"}" data-period="${k}">${l}</button>`).join("");
    if (data.places.length && !$("txPlace").dataset.ready) {
      $("txPlaceWrap").hidden = false;
      $("txPlace").innerHTML = `<option value="">Every place</option>${data.places.map((p) => `<option value="${p.id}"${p.id === placeId ? " selected" : ""}>${esc(p.name)}</option>`).join("")}`;
      $("txPlace").dataset.ready = "1";
      UI.enhanceSelect($("txPlace"), { search: true });
      $(`txPlace`).addEventListener("change", () => {
        placeId = Number($("txPlace").value) || null;
        syncUrl();
        load();
      });
    }
  }

  function syncUrl() {
    const p = new URLSearchParams(window.location.search);
    period === "30" ? p.delete("period") : p.set("period", period);
    placeId ? p.set("place", placeId) : p.delete("place");
    history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("txRows").innerHTML = UI.renderTableLoading(7);
    const [from, to] = range();
    const res = await API.transactions({ from, to, place_id: placeId || undefined, per: 1000 });
    if (!res.ok) {
      $("txTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    controls();
    cards();
    list();
  }

  // ------------------------------------------------------------ one transaction

  async function open(key) {
    const [source, id] = key.split("-");
    const res = await API.transaction(source, id);
    if (!res.ok) return Toast.error(res.message);
    const t = res.data;
    const m = t.money;
    const steps = t.steps.map((s) => `<li class="acc-step ${s.tone === "success" ? "is-done" : ""}"><span class="acc-step-dot${s.tone === "danger" ? " bg-danger text-white" : ""}"><i class="${s.tone === "success" ? "ri-check-line" : s.tone === "danger" ? "ri-close-line" : "ri-time-line"}"></i></span><div><strong>${esc(s.what)}</strong><small>${esc(time(s.at))}${s.detail ? ` · ${esc(s.detail)}` : ""}</small></div></li>`).join("");
    const el = K.confirmWindow({
      title: `${A.money(t.amount)} · ${t.status_label}`,
      subtitle: `${t.kind} · ${t.purpose}${t.place?.name ? ` · ${t.place.name}` : ""}`,
      icon: t.method === "card" ? "ri-bank-card-line" : "ri-smartphone-line",
      go: t.can_check && t.can.check && !A.viewingBelow() ? '<i class="ri-refresh-line me-1"></i>Check now' : '<i class="ri-check-line me-1"></i>Done',
      body: K.parts([
        { icon: "ri-file-list-3-line", title: "The payment", body: `<div class="acc-facts"><div><span>Status</span><strong>${pill(t)}</strong></div><div><span>How</span><strong>${A.methodChip(t.method)} <span class="acc-sub">${esc(t.route)}</span></strong></div><div><span>Reference</span><strong>${esc(t.reference)}</strong></div>${t.code ? `<div><span>${t.method === "mpesa" ? "M-Pesa code" : "Paystack reference"}</span><strong>${esc(t.code)}</strong></div>` : ""}${t.receipt ? `<div><span>In the books</span><strong>${esc(t.receipt)}</strong></div>` : ""}${t.reason ? `<div><span>Why</span><strong class="${t.status === "paid" ? "" : "text-danger"}">${esc(t.reason)}</strong></div>` : ""}</div>` },
        ...(m ? [{ icon: "ri-funds-line", title: "Where the money went", body: `<div class="acc-facts"><div><span>Given</span><strong>${A.money(m.gross)}</strong></div><div><span>Paystack's fee</span><strong>- ${A.money(m.fee)}</strong></div>${m.share ? `<div><span>Diocese share</span><strong>- ${A.money(m.share)}</strong></div>` : ""}<div><span>To the church</span><strong>${A.money(m.net)}</strong></div></div>` }] : []),
        { icon: "ri-user-line", title: "Who paid", body: `<div class="acc-facts"><div><span>Name</span><strong>${esc(t.payer.name || "-")}</strong></div><div><span>Phone</span><strong>${t.payer.phone ? `<a href="tel:+${esc(String(t.payer.phone).replace(/^\+/, ""))}">${esc(t.payer.phone)}</a>` : "-"}</strong></div>${t.payer.email ? `<div><span>Email</span><strong><a href="mailto:${esc(t.payer.email)}">${esc(t.payer.email)}</a></strong></div>` : ""}${t.requested_by ? `<div><span>Asked by</span><strong>${esc(t.requested_by)}</strong></div>` : ""}</div>` },
        { icon: "ri-route-line", title: "What happened", body: `<ol class="acc-steps flex-column mb-0">${steps}</ol>` },
      ]),
      run: async (w) => {
        if (!(t.can_check && t.can.check && !A.viewingBelow())) {
          bootstrap.Modal.getInstance(w)?.hide();
          return null;
        }
        const out = await API.checkTransaction(source, id);
        if (out.ok) load();
        return out;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
  }

  async function checkAll(btn) {
    UI.setButtonLoading(btn, "Checking...");
    const out = await API.checkWaiting({ place_id: placeId || undefined });
    UI.restoreButton(btn);
    out.ok ? (Toast.success(out.message), load()) : Toast.error(out.message);
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("txPeriods").addEventListener("click", (e) => {
      const b = e.target.closest("[data-period]");
      if (!b) return;
      period = b.dataset.period;
      syncUrl();
      load();
    });
    $("txRows").addEventListener("click", (e) => {
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("tr[data-key]");
      if (tr) open(tr.dataset.key);
    });
    $("txCheckAll").addEventListener("click", (e) => checkAll(e.currentTarget));
    $("txLate").addEventListener("click", (e) => e.target.closest("[data-check-all]") && checkAll(e.target.closest("[data-check-all]")));
    A.placePicker($("accPlacePick"), load);
    load();
  });
})();
