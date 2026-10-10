/**
 * ============================================================================
 * ACCOUNTING - Reconciliation (reconciliation.php)
 * ============================================================================
 * Our accounts: a card per money account (cash counted, bank and M-Pesa
 * reconciled - when, and whether it's due), the counts and reconciliations
 * waiting for someone else to approve, and the history. A region and the
 * diocese also get "Places below": how up to date every church's books are.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const API = AccountingAPI;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  let boardKit = null;
  let boardLoaded = false;

  const STATE = {
    ok: { label: "Up to date", color: "success", icon: "ri-checkbox-circle-line" },
    due: { label: "Due this month", color: "warning", icon: "ri-time-line" },
    late: { label: "Behind", color: "danger", icon: "ri-error-warning-line" },
    none: { label: "Not started", color: "secondary", icon: "ri-subtract-line" },
  };
  const statePill = (s) => `<span class="badge bg-${STATE[s].color} ${A.textOn(STATE[s].color)}"><i class="${STATE[s].icon} me-1"></i>${STATE[s].label}</span>`;
  const statusPill = (item) => {
    const map = { balanced: ["success", "Agrees"], approved: ["success", item.type === "count" ? "Difference approved" : "Signed off"], waiting: ["warning", "Waiting"], submitted: ["warning", "Waiting for sign-off"], rejected: ["danger", "Sent back"], returned: ["danger", "Sent back"], draft: ["primary", "In progress"] };
    const [c, l] = map[item.status] || ["secondary", item.status_label];
    return `<span class="badge bg-${c} ${A.textOn(c)}">${esc(l)}</span>`;
  };

  function cards() {
    const own = !A.viewingBelow();
    $("recCards").innerHTML = data.accounts
      .map((a) => {
        const k = A.kind(a.cash_kind);
        const counted = a.check === "count";
        const last = a.last;
        const lastText = last ? `${counted ? "Counted" : "Reconciled to"} ${A.day(last.on)}` : counted ? "Never counted" : "Never reconciled";
        const action = !own || !data.can.reconcile ? "" : counted ? `<button type="button" class="btn btn-sm btn-primary" data-count="${a.id}"><i class="ri-calculator-line me-1"></i>Count cash</button>` : `<a class="btn btn-sm btn-primary" href="${A.link("reconcile.php", { account_id: a.id })}"><i class="ri-scales-3-line me-1"></i>${last && ["draft", "returned"].includes(last.status) ? "Carry on" : "Reconcile"}</a>`;
        const petty = a.cash_kind === "petty_cash" && data.petty.float !== null ? `<div class="acc-float"><span>Float ${A.money(data.petty.float)}</span><span>${data.petty.top_up > 0 ? `${A.money(data.petty.top_up)} to top up` : "Full"}</span></div>` : "";
        return `<div class="col-xxl-3 col-xl-4 col-sm-6 d-flex"><div class="card custom-card flex-fill acc-money-card">
          <div class="card-body">
            <div class="d-flex align-items-start gap-3">${A.tile(a.cash_kind)}<div class="min-w-0 flex-fill"><div class="fw-semibold text-truncate">${esc(a.name)}</div><div class="acc-sub">${esc(k.label)} · ${counted ? "counted" : "reconciled to the statement"}</div></div>${a.due ? '<span class="badge bg-warning text-dark">Due</span>' : '<span class="badge bg-success">Done</span>'}</div>
            <div class="acc-money-value${a.balance < 0 ? " text-danger" : ""}">${A.money(a.balance)}</div>
            <div class="acc-sub">${esc(lastText)}${last ? ` · ${statusPill({ ...last, type: a.check })}` : ""}</div>
            ${petty}
            <div class="d-flex gap-2 flex-wrap">${action}<a class="btn btn-sm btn-outline-primary" href="${A.link("cashbook.php", { account_id: a.id })}"><i class="ri-book-open-line me-1"></i>Cashbook</a></div>
          </div></div></div>`;
      })
      .join("");
    const due = data.accounts.filter((a) => a.due).length;
    if ($("oursFigure")) $("oursFigure").textContent = due ? `${due} due` : "All up to date";
  }

  function waiting() {
    const list = data.waiting;
    $("waitCard").hidden = !list.length;
    $("waitCount").textContent = list.length ? `${list.length} waiting` : "";
    $("waitList").innerHTML = list
      .map((w) => {
        const canApprove = data.can.authorise && !w.mine && !A.viewingBelow();
        const what = w.type === "count" ? `Cash count of ${w.account.name}: ${w.difference < 0 ? "short" : "over"} by ${A.money(Math.abs(w.difference))}` : `${w.account.name} reconciled to ${A.day(w.date)}`;
        return `<div class="acc-wait-row">
          ${A.tile(w.account.kind, "sm")}
          <div class="flex-fill min-w-0"><div class="fw-semibold">${esc(what)}</div><div class="acc-sub">${esc(w.by || "")} · ${A.day(w.date)}${w.reason ? ` · "${esc(w.reason)}"` : ""}</div></div>
          ${w.type === "count"
            ? canApprove
              ? `<button type="button" class="btn btn-sm btn-outline-danger" data-reject="${w.id}">Send back</button><button type="button" class="btn btn-sm btn-success" data-approve="${w.id}"><i class="ri-check-line me-1"></i>Approve</button>`
              : `<span class="soft-chip soft-warning">${w.mine ? "You counted it - someone else approves" : "Waiting"}</span>`
            : `<a class="btn btn-sm ${canApprove ? "btn-success" : "btn-outline-primary"}" href="${A.link("reconcile.php", { id: w.id })}">${canApprove ? '<i class="ri-shield-check-line me-1"></i>Review and sign off' : "Open"}</a>`}
        </div>`;
      })
      .join("");
  }

  function historyTable() {
    A.tableKit({
      tableId: "histTable",
      prefix: "h_",
      items: data.history,
      noun: "checks",
      search: "Search account, who...",
      pills: [
        { key: "reconciliation", label: "Reconciliations", icon: "ri-bank-line", color: "primary", test: (h) => h.type === "reconciliation" },
        { key: "count", label: "Cash counts", icon: "ri-money-dollar-box-line", color: "success", test: (h) => h.type === "count" },
        { key: "diff", label: "With a difference", icon: "ri-error-warning-line", color: "danger", test: (h) => !!h.difference },
      ],
      sorts: [
        { key: "new", label: "Newest first", order: [[0, "desc"]] },
        { key: "old", label: "Oldest first", order: [[0, "asc"]] },
        { key: "diff", label: "Biggest difference", order: [[4, "desc"]] },
      ],
      empty: A.empty("ri-scales-3-line", "Nothing checked yet", "Count the cash and reconcile the bank each month - it shows here."),
      rowHtml: (h) => `<tr${h.type === "reconciliation" ? ` class="acc-row" data-rec="${h.id}"` : ""} data-pills="${h.type}${h.difference ? " diff" : ""}">
            <td class="text-nowrap" data-order="${h.date}">${A.day(h.date)}</td>
            <td><div class="d-flex align-items-center gap-2">${A.tile(h.account.kind, "xs")}<div><div class="fw-semibold">${esc(h.account.name)}</div><div class="acc-sub">${h.type === "count" ? `Cash count${h.surprise ? " (surprise)" : ""}` : "Reconciliation"}</div></div></div></td>
            <td class="d-none d-md-table-cell">${esc(h.by || "-")}${h.approved_by ? `<div class="acc-sub">Approved by ${esc(h.approved_by)}</div>` : ""}</td>
            <td class="text-end" data-order="${h.book || 0}">${A.amount(h.book) || "0.00"}</td>
            <td class="text-end ${h.difference ? (h.difference < 0 ? "text-danger" : "text-warning") : ""}" data-order="${Math.abs(h.difference || 0)}">${h.difference ? A.money(h.difference, { sign: true }) : "-"}</td>
            <td>${statusPill(h)}${h.journal ? `<div class="acc-sub mt-1">${esc(h.journal.number)}</div>` : ""}${h.type === "reconciliation" ? `<div class="mt-1">${A.pdfButton("accounting.reconciliation", { record_id: h.id }, `Reconciliation - ${h.account.name}`, "Statement")}</div>` : ""}</td>
          </tr>`,
    });
  }


  async function load() {
    A.ownOnly();
    $("recCards").innerHTML = UI.skeletonCards(4, "col-xxl-3 col-xl-4 col-sm-6");
    const res = await API.reconciliation();
    if (!res.ok) {
      $("recCards").innerHTML = `<div class="col-12">${A.errorBox(res.message)}</div>`;
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    waiting();
    historyTable();
  }

  // ------------------------------------------------------------ the board

  async function loadBoard() {
    boardKit?.destroy();
    $("boardRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.board();
    if (!res.ok) {
      $("boardWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    boardLoaded = true;
    const rows = res.data.rows;
    const n = (s) => rows.filter((r) => r.state === s).length;
    $("belowFigure").textContent = `${n("late")} behind of ${rows.length}`;
    K.statRow($("boardCards"), [
      { icon: "ri-community-line", label: "Places", sub: `${rows.filter((r) => r.started).length} keeping books`, value: A.num(rows.length), color: "primary" },
      { icon: "ri-checkbox-circle-line", label: "Up to date", sub: "Checked last month or later", value: A.num(n("ok")), color: "success", bar: { pct: rows.length ? Math.round((n("ok") / rows.length) * 100) : 0, text: "of places" } },
      { icon: "ri-error-warning-line", label: "Behind", sub: "Two months or more, or waiting", value: A.num(n("late")), color: "danger" },
      { icon: "ri-safe-2-line", label: "Money held", sub: "Cash, banks and M-Pesa below us", value: A.figure(rows.reduce((t, r) => t + r.held, 0)), color: "purple" },
    ]);
    const rowHtml = (r) => `<tr class="acc-row" data-id="${r.place.id}" data-pills="${r.state}${r.waiting ? " waiting" : ""}" data-f-level="${r.place.level}">
      ${K.checkCell(r.place.id, r.place.name)}
      <td data-search="${esc(`${r.place.name} ${r.place.parent || ""}`)}" data-order="${esc(r.place.name.toLowerCase())}"><div class="fw-semibold">${esc(r.place.name)}</div><div class="acc-sub">${r.place.level === "region" ? "Region" : esc(r.place.parent || "Church")}</div></td>
      <td class="d-none d-lg-table-cell"><div class="d-flex flex-wrap gap-1">${r.accounts.filter((a) => a.used || a.balance).map((a) => `<span class="soft-chip soft-${a.behind > 1 ? "danger" : a.behind === 1 ? "warning" : "success"}" title="${esc(a.name)}: ${a.last_on ? `last ${a.check === "count" ? "counted" : "reconciled"} ${A.day(a.last_on)}` : "never checked"}"><i class="${A.kind(a.kind).icon}"></i>${esc(a.name)}${a.behind ? ` · ${a.behind}m` : ""}</span>`).join("") || '<span class="acc-sub">No money accounts used yet</span>'}</div></td>
      <td class="text-end" data-order="${r.held}"><strong>${A.money(r.held)}</strong></td>
      <td class="d-none d-md-table-cell" data-order="${r.last_closed || ""}">${r.last_closed_label ? esc(r.last_closed_label) : '<span class="acc-sub">None</span>'}</td>
      <td data-order="${["late", "due", "ok", "none"].indexOf(r.state)}">${statePill(r.state)}${r.waiting ? `<div class="acc-sub mt-1">${r.waiting} waiting</div>` : ""}</td>
    </tr>`;
    if (!rows.length) {
      $("boardRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-community-line", "No places below", "")}</td></tr>`;
      return;
    }
    boardKit = K.listTable({
      tableId: "boardTable",
      stripId: "boardFilters",
      pillsId: "boardPills",
      rowsId: "boardRows",
      items: rows,
      rowHtml,
      noun: "places",
      searchPlaceholder: "Search a church or region...",
      pills: [
        { key: "late", label: "Behind", icon: STATE.late.icon, color: "danger", test: (r) => r.state === "late" },
        { key: "due", label: "Due", icon: STATE.due.icon, color: "warning", test: (r) => r.state === "due" },
        { key: "ok", label: "Up to date", icon: STATE.ok.icon, color: "success", test: (r) => r.state === "ok" },
        { key: "none", label: "Not started", icon: STATE.none.icon, color: "secondary", test: (r) => r.state === "none" },
        { key: "waiting", label: "Waiting", icon: "ri-time-line", color: "purple", test: (r) => r.waiting > 0 },
      ],
      selects: CTX.level === "diocese" ? [{ key: "level", label: "Churches and regions", options: [{ value: "church", label: "Churches", icon: "ri-community-line", color: "success" }, { value: "region", label: "Regions", icon: "ri-map-pin-line", color: "purple" }] }] : [],
      sorts: [
        { key: "state", label: "Behind first", order: [[5, "asc"]] },
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "held", label: "Most money held", order: [[3, "desc"]] },
      ],
      actions: [],
    });
  }

  function init() {
    $("countBtn")?.addEventListener("click", () => W.countCash({ accounts: data?.accounts || [], onDone: load }));
    $("recCards").addEventListener("click", (e) => {
      const b = e.target.closest("[data-count]");
      if (b) W.countCash({ accounts: data.accounts, accountId: Number(b.dataset.count), onDone: load });
    });
    $("waitList").addEventListener("click", async (e) => {
      const ap = e.target.closest("[data-approve]");
      if (ap) {
        UI.setButtonLoading(ap, "...");
        const res = await API.approveCount(Number(ap.dataset.approve));
        UI.restoreButton(ap);
        return res.ok ? (Toast.success(res.message), load()) : Toast.error(res.message);
      }
      const rj = e.target.closest("[data-reject]");
      if (rj) W.reasonWindow({ title: "Send the count back", subtitle: "It will be counted again", go: "Send back", placeholder: "e.g. Count again with a witness", run: (r) => API.rejectCount(Number(rj.dataset.reject), r), onDone: load });
    });
    $("histRows").addEventListener("click", (e) => {
      const tr = e.target.closest("[data-rec]");
      if (tr) window.location.href = A.link("reconcile.php", { id: tr.dataset.rec });
    });
    $("recTabs")?.addEventListener("shown.bs.tab", (e) => {
      const tab = e.target.closest("[data-tab]")?.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "below" ? p.set("tab", "below") : p.delete("tab");
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      if (tab === "below" && !boardLoaded) loadBoard();
    });
    if (new URLSearchParams(window.location.search).get("tab") === "below" && $("recTabs")) {
      bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-tab="below"]')).show();
    }
    $("boardRows")?.addEventListener("click", (e) => {
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("tr[data-id]");
      if (tr) window.location.href = `${CTX.baseUrl}/reconciliation.php?territory_id=${tr.dataset.id}`;
    });
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
