/**
 * ============================================================================
 * ACCOUNTING - Remittances (remittances.php, every level)
 * ============================================================================
 * Money between levels (docs/specs/accounting-spec.md, A6). What we send: each
 * share we owe by month - due from our own books, sent, confirmed, owed - with
 * Send the share, and our remittances. Coming in: money other places sent us,
 * to confirm into our books or query. Places below (region, diocese): the
 * board for the year, each place's statement.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const API = AccountingAPI;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const n = (v) => Math.round(parseFloat(String(v ?? "").replace(/[^0-9.]/g, "")) * 100) / 100 || 0;
  const params = new URLSearchParams(window.location.search);
  let data = null;
  let board = null;
  let boardKit = null;
  let year = Number(params.get("year")) || new Date().getFullYear();
  let tab = ["in", "below"].includes(params.get("tab")) ? params.get("tab") : "owe";

  const ST = {
    waiting: ["warning", "ri-time-line", "Waiting to be paid"],
    sent: ["primary", "ri-send-plane-line", "In transit"],
    queried: ["danger", "ri-question-line", "Queried"],
    confirmed: ["success", "ri-checkbox-circle-line", "Confirmed"],
    cancelled: ["secondary", "ri-close-line", "Cancelled"],
  };
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const monthName = (m, opts = { month: "short" }) => new Date(`${m}-15T12:00:00`).toLocaleDateString("en-GB", opts);
  const months = (list) => (list.length === 1 ? monthName(list[0], { month: "short", year: "numeric" }) : list.length ? `${monthName(list[0])} - ${monthName(list[list.length - 1], { month: "short", year: "numeric" })}` : "");
  const sum = (arr, f) => arr.reduce((t, x) => t + f(x), 0);

  // ------------------------------------------------------------ the page

  function cards() {
    const owed = sum(data.owing, (r) => r.owed);
    const sent = sum(data.owing, (r) => r.sent);
    const transit = data.sent.filter((r) => ["sent", "queried"].includes(r.status));
    const toConfirm = data.coming_in.filter((r) => ["sent", "queried"].includes(r.status));
    K.statRow($("statCardsRow"), [
      { icon: "ri-error-warning-line", label: `Owed for ${year}`, sub: data.owing.length ? data.owing.map((r) => r.name).join(", ") : "No share is set for this place", value: A.figure(owed), color: owed > 0 ? "danger" : "success" },
      { icon: "ri-upload-2-line", label: `Sent for ${year}`, sub: `${A.money(sum(data.owing, (r) => r.confirmed))} confirmed by them`, value: A.figure(sent), color: "primary" },
      { icon: "ri-send-plane-line", label: "Ours in transit", sub: transit.some((r) => r.status === "queried") ? `${transit.filter((r) => r.status === "queried").length} queried` : `${transit.length} not confirmed yet`, value: A.figure(sum(transit, (r) => r.amount)), color: "warning" },
      { icon: "ri-download-2-line", label: "Coming in to confirm", sub: `${toConfirm.length} from other places`, value: A.figure(sum(toConfirm, (r) => r.amount)), color: "purple" },
    ]);
    $("rmOweFigure").textContent = owed > 0 ? `${A.money(owed)} owed` : "Up to date";
    $("rmInFigure").textContent = toConfirm.length ? `${toConfirm.length} to confirm` : "Nothing waiting";
  }

  function rules() {
    $("shareBtn") && ($("shareBtn").hidden = !data.owing.length || !data.can.send || A.viewingBelow());
    $("rmRules").innerHTML = data.owing
      .map(
        (r) => `<div class="card custom-card"><div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">${esc(r.name)}</div><span class="card-subtitle-text">${esc(r.rule)}, sent to ${esc(r.to?.name || "")} - worked out from our books, month by month</span></div><div class="d-flex gap-2 flex-wrap"><span class="soft-chip soft-primary">Due ${A.money(r.due)}</span><span class="soft-chip soft-success">Sent ${A.money(r.sent)}</span>${r.owed > 0 ? `<span class="badge bg-danger">Owed ${A.money(r.owed)}</span>` : '<span class="badge bg-success">Up to date</span>'}</div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 acc-table"><thead><tr><th>Month</th><th class="text-end">Due</th><th class="text-end">Sent</th><th class="text-end d-none d-md-table-cell">Confirmed</th><th class="text-end">Owed</th></tr></thead><tbody>${
          r.months.length
            ? r.months
                .map((m) => `<tr><td>${monthName(m.month, { month: "long" })}</td><td class="text-end">${A.amount(m.due) || "-"}</td><td class="text-end">${A.amount(m.sent) || "-"}</td><td class="text-end d-none d-md-table-cell">${A.amount(m.confirmed) || "-"}</td><td class="text-end ${m.owed > 0 ? "text-danger fw-semibold" : "text-success"}">${m.owed > 0 ? A.amount(m.owed) : m.due > 0 ? '<i class="ri-check-line"></i>' : "-"}</td></tr>`)
                .join("")
            : `<tr><td colspan="5">${A.empty("ri-calendar-line", "No months yet", "Nothing is due for this year yet.")}</td></tr>`
        }</tbody></table></div></div></div>`,
      )
      .join("");
  }

  const sentRow = (r) => `<tr class="acc-row" data-id="${r.id}"><td><div class="fw-semibold">${esc(r.purpose)}</div><div class="acc-sub">${esc(r.number)} · ${esc(r.kind_label)}</div></td><td class="d-none d-md-table-cell">${esc(r.to?.name || "")}</td><td class="d-none d-lg-table-cell text-nowrap">${r.sent_on ? A.day(r.sent_on) : "-"}</td><td>${pill(r.status)}${r.status === "waiting" && r.voucher ? `<div class="acc-sub mt-1">Voucher ${esc(r.voucher.number)}</div>` : ""}${r.can.answer ? '<div><span class="badge bg-danger mt-1">Answer their query</span></div>' : ""}</td><td class="text-end"><strong>${A.money(r.amount)}</strong></td></tr>`;

  function sent() {
    $("rmSentRows").innerHTML = data.sent.length
      ? data.sent.map(sentRow).join("")
      : `<tr><td colspan="5">${A.empty("ri-upload-2-line", "Nothing sent yet", data.owing.length ? "Send the share from here - it is paid by a voucher like any payment, then the place receiving it confirms it." : "Remittances this place sends show here.")}</td></tr>`;
  }

  function comingIn() {
    $("rmInRows").innerHTML = data.coming_in.length
      ? data.coming_in
          .map(
            (r) => `<tr class="acc-row" data-id="${r.id}"><td><div class="fw-semibold">${esc(r.from?.name || "")}</div><div class="acc-sub">${esc(r.purpose)} · ${esc(r.number)}</div></td><td class="d-none d-md-table-cell text-nowrap">${r.sent_on ? A.day(r.sent_on) : "-"}</td><td>${pill(r.status)}${r.status === "confirmed" && r.received_on ? `<div class="acc-sub mt-1">Reached us ${A.day(r.received_on)}</div>` : ""}</td><td class="text-end"><strong>${A.money(r.amount)}</strong></td><td class="text-end">${r.can.confirm && !A.viewingBelow() ? `<button type="button" class="btn btn-sm btn-success" data-confirm="${r.id}"><i class="ri-check-line me-1"></i>Confirm</button>` : ""}</td></tr>`,
          )
          .join("")
      : `<tr><td colspan="5">${A.empty("ri-download-2-line", "Nothing coming in", "Money another place sends you shows here once they have paid it.")}</td></tr>`;
  }

  async function loadBoard() {
    if (!document.querySelector('#rmTabs [data-tab="below"]')) return;
    $("rmBoardRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.remittanceBoard(year);
    if (!res.ok) return ($("rmBoardWrap").innerHTML = A.errorBox(res.message));
    board = res.data.rows;
    const late = board.filter((r) => r.late).length;
    $("rmBelowFigure").textContent = board.length ? (late ? `${late} behind` : "All up to date") : "None";
    boardKit?.destroy();
    if (!board.length) {
      $("rmBoardPills").innerHTML = "";
      $("rmBoardFilters").innerHTML = "";
      $("rmBoardRows").innerHTML = `<tr><td colspan="7">${A.empty("ri-community-line", "No places owe this place a share", "A share set here in Budgets > Deductions for the places below shows each place's figures.")}</td></tr>`;
      return;
    }
    boardKit = K.listTable({
      tableId: "rmBoardTable",
      stripId: "rmBoardFilters",
      pillsId: "rmBoardPills",
      rowsId: "rmBoardRows",
      items: board.map((r) => ({ ...r, id: r.place.id })),
      rowHtml: (r) => `<tr class="acc-row" data-id="${r.place.id}" data-place="${r.place.id}" data-pills="${r.late ? "late" : "ok"}${r.queried ? " queried" : ""}${r.in_transit > 0 ? " transit" : ""}${r.sent > 0 ? " sent" : " none"}">${K.checkCell(r.place.id, r.place.name)}<td data-search="${esc(`${r.place.name} ${r.place.code || ""}`)}" data-order="${esc(r.place.name)}"><div class="fw-semibold">${esc(r.place.name)}${r.late ? ' <span class="badge bg-danger">Behind</span>' : ""}${r.queried ? ' <span class="badge bg-warning">Queried</span>' : ""}</div><div class="acc-sub">${esc(r.place.code || "")}${r.last_sent ? ` · last sent ${A.day(r.last_sent)}` : " · nothing sent yet"}</div></td><td class="text-end" data-order="${r.due}">${A.amount(r.due) || "-"}</td><td class="text-end d-none d-md-table-cell" data-order="${r.sent}">${A.amount(r.sent) || "-"}</td><td class="text-end d-none d-lg-table-cell" data-order="${r.confirmed}">${A.amount(r.confirmed) || "-"}</td><td class="text-end d-none d-lg-table-cell" data-order="${r.in_transit}">${A.amount(r.in_transit) || "-"}</td><td class="text-end" data-order="${r.owed}"><strong class="${r.owed > 0 ? "text-danger" : "text-success"}">${r.owed > 0 ? A.amount(r.owed) : r.due > 0 ? '<i class="ri-check-line"></i>' : "-"}</strong></td></tr>`,
      noun: "places",
      defaultPill: late ? "late" : "all",
      searchPlaceholder: "Search a church or region...",
      pills: [
        { key: "late", label: "Behind", icon: "ri-error-warning-line", color: "danger", test: (r) => r.late },
        { key: "transit", label: "In transit", icon: "ri-send-plane-line", color: "primary", test: (r) => r.in_transit > 0 },
        { key: "queried", label: "Queried", icon: "ri-question-line", color: "warning", test: (r) => r.queried > 0 },
        { key: "none", label: "Nothing sent", icon: "ri-time-line", color: "purple", test: (r) => !(r.sent > 0) },
      ],
      sorts: [
        { key: "owed", label: "Most owed first", order: [[6, "desc"], [2, "desc"]] },
        { key: "name", label: "By name", order: [[1, "asc"]] },
      ],
      actions: [],
    });
  }

  function showTab() {
    document.querySelectorAll("#rmTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("rmOwePane").hidden = tab !== "owe";
    $("rmInPane").hidden = tab !== "in";
    $("rmBelowPane").hidden = tab !== "below";
  }

  async function load() {
    A.ownOnly();
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("rmSentRows").innerHTML = UI.renderTableLoading(5);
    const res = await API.remittances(year);
    if (!res.ok) {
      $("rmOwePane").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    rules();
    sent();
    comingIn();
    showTab();
    loadBoard();
  }

  // ------------------------------------------------------------ sending

  async function shareWindow() {
    const o = await API.remittanceOptions();
    if (!o.ok) return Toast.error(o.message);
    const owing = data.owing;
    if (!owing.length) return;
    const rule0 = owing.find((r) => r.owed > 0) || owing[0];
    const ruleTiles = owing.length > 1 ? `<div class="acc-tiles">${owing.map((r) => `<label class="acc-tile" style="--q: var(--primary-rgb)"><input type="radio" name="shRule" value="${r.id}"${r.id === rule0.id ? " checked" : ""}><span class="acc-tile-icon"><i class="ri-percent-line"></i></span><span class="acc-tile-text"><strong>${esc(r.name)}</strong><small>${A.money(r.owed)} owed to ${esc(r.to?.name || "")}</small></span></label>`).join("")}</div>` : `<div class="acc-facts"><div><span>Share</span><strong>${esc(rule0.name)} - ${esc(rule0.rule)}</strong></div><div><span>To</span><strong>${esc(rule0.to?.name || "")}</strong></div></div>`;
    const el = K.confirmWindow({
      title: "Send the share",
      subtitle: `For ${year} - it makes a payment voucher, approved and paid as usual`,
      icon: "ri-send-plane-line",
      go: '<i class="ri-check-line me-1"></i>Make the voucher',
      body: K.parts([
        { icon: "ri-percent-line", title: "Which share", body: ruleTiles },
        { icon: "ri-calendar-line", title: "Months and amounts", hint: "What is owed is filled in", body: `<div id="shMonths"></div><div class="acc-sub mt-2" id="shTotal"></div>` },
        { icon: "ri-bank-line", title: "Pay it from", body: W.cashTiles(o.data.cash, o.data.cash.find((a) => a.cash_kind === "bank")?.id || o.data.cash[0]?.id, "shFrom") },
      ]),
      run: async () => {
        const rule = Number(el.querySelector('input[name="shRule"]:checked')?.value || rule0.id);
        const lines = [...el.querySelectorAll("[data-sh]")].filter((x) => x.querySelector("input[type=checkbox]").checked).map((x) => ({ month: x.dataset.sh, amount: n(x.querySelector("[data-amt]").value) }));
        const res = await API.sendRemittance({ kind: "share", budget_deduction_id: rule, lines, pay_from_account_id: Number(el.querySelector('input[name="shFrom"]:checked')?.value) });
        if (res.ok) setTimeout(load, 300);
        return res;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    const total = () => (el.querySelector("#shTotal").innerHTML = `Sending <strong>${A.money(sum([...el.querySelectorAll("[data-sh]")].filter((x) => x.querySelector("input[type=checkbox]").checked), (x) => n(x.querySelector("[data-amt]").value)))}</strong>`);
    const fill = () => {
      const r = owing.find((x) => x.id === Number(el.querySelector('input[name="shRule"]:checked')?.value || rule0.id));
      const shown = r.months.filter((m) => m.due > 0 || m.sent > 0);
      el.querySelector("#shMonths").innerHTML = (shown.length ? shown : r.months)
        .map((m) => `<div class="row g-2 mb-2 align-items-center" data-sh="${m.month}"><div class="col-7"><label class="form-check mb-0"><input type="checkbox" class="form-check-input"${m.owed > 0 ? " checked" : ""}><span class="form-check-label fw-semibold">${monthName(m.month, { month: "long" })}</span></label><div class="acc-sub">Due ${A.money(m.due)}${m.sent ? ` · sent ${A.money(m.sent)}` : ""}</div></div><div class="col-5"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" data-amt value="${m.owed || ""}"></div></div></div>`)
        .join("");
      el.querySelectorAll("#shMonths input").forEach((x) => x.addEventListener("input", total));
      el.querySelectorAll("#shMonths input[type=checkbox]").forEach((x) => x.addEventListener("change", total));
      total();
    };
    el.querySelectorAll('input[name="shRule"]').forEach((x) => x.addEventListener("change", fill));
    fill();
  }

  async function supportWindow() {
    const o = await API.remittanceOptions();
    if (!o.ok) return Toast.error(o.message);
    const d = o.data;
    const el = K.confirmWindow({
      title: "Send support",
      subtitle: "To a place below - it makes a payment voucher; they confirm it into their books",
      icon: "ri-hand-heart-line",
      go: '<i class="ri-check-line me-1"></i>Make the voucher',
      body: K.parts([
        { icon: "ri-community-line", title: "To", body: `<select class="form-select" id="suTo"><option value="">Pick the place</option>${d.below.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join("")}</select>` },
        { icon: "ri-file-text-line", title: "What for and how much", body: `<div class="row g-2"><div class="col-sm-8"><input type="text" class="form-control" id="suWhat" maxlength="255" placeholder="e.g. Roof repairs after the storm"></div><div class="col-sm-4"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" id="suAmt" placeholder="0"></div></div><div class="col-12"><select class="form-select" id="suAcc">${d.expenses.map((a) => `<option value="${a.id}"${a.code === d.support_code ? " selected" : ""}>${esc(a.code)} · ${esc(a.name)}</option>`).join("")}</select></div></div>` },
        { icon: "ri-bank-line", title: "Pay it from", body: W.cashTiles(d.cash, d.cash.find((a) => a.cash_kind === "bank")?.id || d.cash[0]?.id, "suFrom") },
      ]),
      run: async () => {
        const res = await API.sendRemittance({ kind: "support", to_territory_id: Number(el.querySelector("#suTo").value) || null, purpose: el.querySelector("#suWhat").value.trim(), amount: n(el.querySelector("#suAmt").value), account_id: Number(el.querySelector("#suAcc").value) || null, pay_from_account_id: Number(el.querySelector('input[name="suFrom"]:checked')?.value) });
        if (res.ok) setTimeout(load, 300);
        return res;
      },
    });
    UI.enhanceSelect(el.querySelector("#suTo"), { search: true });
    UI.enhanceSelect(el.querySelector("#suAcc"), { search: true });
  }

  // ------------------------------------------------------------ one remittance

  async function view(id) {
    const res = await API.remittance(id);
    if (!res.ok) return Toast.error(res.message);
    const r = res.data;
    const c = r.can;
    const own = !A.viewingBelow();
    document.getElementById("rmWindow")?.remove();
    const step = (done, active, icon, title, sub) => `<li class="acc-step${done ? " is-done" : active ? " is-on" : ""}"><span class="acc-step-dot"><i class="${icon}"></i></span><div><strong>${title}</strong><small>${sub}</small></div></li>`;
    const order = ["waiting", "sent", "confirmed"];
    const at = r.status === "queried" ? 1 : order.indexOf(r.status);
    const steps = r.status === "cancelled"
      ? '<div class="alert alert-secondary mb-0">Cancelled - its voucher was cancelled.</div>'
      : `<ol class="acc-steps mb-0">${step(at > 0, at === 0, "ri-file-list-3-line", "Voucher", r.voucher ? `${esc(r.voucher.number)} · ${esc(r.voucher.status)}` : "-")}${step(at > 1, at === 1, "ri-send-plane-line", r.status === "queried" ? "Queried" : "Sent", r.sent_on ? A.day(r.sent_on) : "Not paid yet")}${step(at === 2, false, "ri-checkbox-circle-line", "Confirmed", r.received_on ? `${A.day(r.received_on)}${r.confirmed_by ? ` by ${esc(r.confirmed_by)}` : ""}` : "Not yet")}</ol>`;
    const lines = r.lines.length > 1 || r.kind === "share" ? `<div class="table-responsive"><table class="table table-sm mb-0 acc-table"><thead><tr><th>Month</th><th class="text-end">Due then</th><th class="text-end">Sent</th></tr></thead><tbody>${r.lines.map((l) => `<tr><td>${monthName(l.month, { month: "long", year: "numeric" })}</td><td class="text-end">${l.due !== null ? A.amount(l.due) : "-"}</td><td class="text-end fw-semibold">${A.amount(l.amount)}</td></tr>`).join("")}</tbody></table></div>` : "";
    const query = r.query_reason ? `<div class="alert alert-${r.status === "queried" ? "danger" : "secondary"} mb-0"><strong>${esc(r.to?.name || "")} asked:</strong> ${esc(r.query_reason)}${r.answer ? `<div class="mt-1"><strong>${esc(r.from?.name || "")} answered:</strong> ${esc(r.answer)}</div>` : ""}</div>` : "";
    const btn = (k, cls, icon, label) => `<button type="button" class="btn ${cls}" data-act="${k}"><i class="${icon} me-1"></i>${label}</button>`;
    const foot = [
      own && c.unconfirm ? btn("unconfirm", "btn-outline-danger me-auto", "ri-arrow-go-back-line", "Undo the confirmation") : "",
      own && c.query ? btn("query", "btn-outline-danger", "ri-question-line", "Query it") : "",
      own && c.answer ? btn("answer", "btn-primary", "ri-reply-line", "Answer") : "",
      own && c.confirm ? btn("confirm", "btn-success", "ri-check-line", "Confirm received") : "",
      r.voucher && !c.confirm && !c.unconfirm ? `<a class="btn btn-outline-primary" href="${A.link("payments.php", { voucher: r.voucher.id })}"><i class="ri-file-list-3-line me-1"></i>Voucher ${esc(r.voucher.number)}</a>` : "",
      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>',
    ].join("");
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="rmWindow" tabindex="-1" aria-labelledby="rmTitle"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-exchange-funds-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="rmTitle">${esc(r.number)} · ${esc(r.kind_label)}</h5><div class="app-modal-subtitle">${A.money(r.amount)} · ${esc(r.from?.name || "")} → ${esc(r.to?.name || "")}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Where it stands</div>${steps}</section>
          ${query ? `<section class="app-modal-part">${query}</section>` : ""}
          <section class="app-modal-part mb-0"><div class="app-modal-part-head"><i class="ri-file-text-line"></i>What it is</div><div class="acc-facts"><div><span>For</span><strong>${esc(r.purpose)}</strong></div><div><span>From</span><strong>${esc(r.from?.name || "")}</strong></div><div><span>To</span><strong>${esc(r.to?.name || "")}</strong></div>${r.method ? `<div><span>Paid by</span><strong>${esc(r.method)}${r.reference ? ` · ${esc(r.reference)}` : ""}</strong></div>` : ""}</div>${lines}</section>
        </div>
        <div class="modal-footer" id="rmFoot">${foot}</div>
      </div></div></div>`,
    );
    const el = document.getElementById("rmWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    el.querySelector("#rmFoot").addEventListener("click", (e) => {
      const act = e.target.closest("[data-act]")?.dataset.act;
      if (!act) return;
      const done = () => {
        bootstrap.Modal.getInstance(el)?.hide();
        load();
      };
      if (act === "confirm") return confirmWindow(r, done);
      const text = { query: ["Query it", "What is wrong? e.g. Not on our statement", "reason", "Query it", true], unconfirm: ["Undo the confirmation", "Why? e.g. Confirmed into the wrong account", "reason", "Undo it", true], answer: ["Answer their query", "e.g. Sent by M-Pesa on 3 Oct, code QWE123", "answer", "Send the answer", false] }[act];
      K.confirmWindow({
        title: text[0],
        subtitle: `${r.number} · ${A.money(r.amount)}`,
        icon: act === "answer" ? "ri-reply-line" : act === "query" ? "ri-question-line" : "ri-arrow-go-back-line",
        danger: text[4],
        go: text[3],
        body: K.parts([{ icon: "ri-chat-3-line", title: text[0], body: `<textarea class="form-control" id="rmText" rows="2" maxlength="255" placeholder="${esc(text[1])}"></textarea>` }]),
        run: async () => {
          const out = await API.remittanceAct(r.id, act, { [text[2]]: document.getElementById("rmText").value.trim() });
          if (out.ok) done();
          return out;
        },
      });
    });
  }

  async function confirmWindow(r, done) {
    const o = await API.remittanceOptions();
    if (!o.ok) return Toast.error(o.message);
    const today = new Date().toISOString().slice(0, 10);
    const el = K.confirmWindow({
      title: `Confirm ${A.money(r.amount)} from ${r.from?.name || ""}`,
      subtitle: `${r.purpose} - it goes into our books as a receipt`,
      icon: "ri-check-double-line",
      go: '<i class="ri-check-line me-1"></i>Confirm it',
      body: K.parts([
        { icon: "ri-bank-line", title: "Where it reached us", body: W.cashTiles(o.data.cash, o.data.cash.find((a) => a.cash_kind === "bank")?.id || o.data.cash[0]?.id, "cfInto") },
        { icon: "ri-calendar-line", title: "On", body: `<input type="date" class="form-control" id="cfOn" value="${today}" min="${r.sent_on || ""}" max="${today}">` },
      ]),
      run: async () => {
        const out = await API.remittanceAct(r.id, "confirm", { into_account_id: Number(document.querySelector('input[name="cfInto"]:checked')?.value), received_on: document.getElementById("cfOn").value });
        if (out.ok) done();
        return out;
      },
    });
    if (window.DateField) DateField.enhance(el.querySelector("#cfOn"), { quick: ["today", "yesterday"] });
  }

  // ------------------------------------------------------------ a place's statement

  async function statement(placeId) {
    const res = await API.remittanceStatement(placeId, year);
    if (!res.ok) return Toast.error(res.message);
    const s = res.data;
    const rows = s.months.map((m) => `<tr><td>${monthName(m.month, { month: "long" })}</td><td class="text-end">${A.amount(m.due) || "-"}</td><td class="text-end">${A.amount(m.sent) || "-"}</td><td class="text-end d-none d-md-table-cell">${A.amount(m.confirmed) || "-"}</td><td class="text-end fw-semibold ${m.balance > 0.009 ? "text-danger" : ""}">${A.amount(m.balance) || "0.00"}</td></tr>`).join("");
    const tot = (k) => sum(s.months, (m) => m[k]);
    const el = K.confirmWindow({
      title: `${s.place.name} - ${s.year}`,
      subtitle: `Statement with ${s.owner.name}${s.rules.length ? `: ${s.rules.map((r) => r.rule).join("; ")}` : ""}`,
      icon: "ri-file-list-2-line",
      go: '<i class="ri-printer-line me-1"></i>Print',
      body: K.parts([
        { icon: "ri-calendar-line", title: "By month", body: `<div class="table-responsive"><table class="table table-sm mb-0 acc-table"><thead><tr><th>Month</th><th class="text-end">Due</th><th class="text-end">Sent</th><th class="text-end d-none d-md-table-cell">Confirmed</th><th class="text-end">Owed so far</th></tr></thead><tbody>${rows}<tr class="fw-semibold"><td>Year</td><td class="text-end">${A.amount(tot("due"))}</td><td class="text-end">${A.amount(tot("sent"))}</td><td class="text-end d-none d-md-table-cell">${A.amount(tot("confirmed"))}</td><td class="text-end">${A.amount(tot("due") - tot("sent"))}</td></tr></tbody></table></div>` },
        { icon: "ri-exchange-funds-line", title: "Remittances", body: s.remittances.length ? `<div class="acc-quotes">${s.remittances.map((r) => `<div class="acc-quote"><div class="min-w-0 flex-fill"><div class="fw-semibold">${esc(r.purpose)}</div><div class="acc-sub">${esc(r.number)}${r.sent_on ? ` · sent ${A.day(r.sent_on)}` : ""}${r.received_on ? ` · confirmed ${A.day(r.received_on)}` : ""}</div></div>${pill(r.status)}<strong>${A.money(r.amount)}</strong></div>`).join("")}</div>` : '<p class="acc-muted-line mb-0">Nothing sent this year.</p>' },
      ]),
      run: async () => {
        printStatement(s);
        return null;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
  }

  function printStatement(s) {
    const w = window.open("", "_blank");
    if (!w) return Toast.error("Allow pop-ups to print.");
    const rows = s.months.map((m) => `<tr><td>${monthName(m.month, { month: "long" })}</td><td style="text-align:right">${A.amount(m.due) || "-"}</td><td style="text-align:right">${A.amount(m.sent) || "-"}</td><td style="text-align:right">${A.amount(m.confirmed) || "-"}</td><td style="text-align:right">${A.amount(m.balance) || "0.00"}</td></tr>`).join("");
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Statement ${esc(s.place.name)} ${s.year}</title><style>body{font-family:Inter,Arial,sans-serif;color:#0D0D0D;max-width:760px;margin:32px auto;padding:0 16px}h1{font-size:20px;margin:0}h2{font-size:14px;font-weight:600;margin:4px 0 18px}table{width:100%;border-collapse:collapse;margin:16px 0}td,th{padding:8px;border-bottom:1px solid #ddd;font-size:14px;text-align:left}</style></head><body>
      <h1>Christian Church International - ${esc(s.owner.name)}</h1><h2>STATEMENT - ${esc(s.place.name)} (${esc(s.place.code || "")}), ${s.year}</h2>
      <p style="font-size:13px">${s.rules.map((r) => `${esc(r.name)}: ${esc(r.rule)}`).join("<br>")}</p>
      <table><thead><tr><th>Month</th><th style="text-align:right">Due</th><th style="text-align:right">Sent</th><th style="text-align:right">Confirmed</th><th style="text-align:right">Owed so far</th></tr></thead><tbody>${rows}</tbody></table>
      <script>window.onload=()=>window.print()<\/script></body></html>`);
    w.document.close();
  }

  // ------------------------------------------------------------ start

  function init() {
    $("shareBtn")?.addEventListener("click", shareWindow);
    $("supportBtn")?.addEventListener("click", supportWindow);
    $("rmYear")?.addEventListener("click", (e) => {
      const b = e.target.closest("[data-year]");
      if (!b) return;
      year = Number(b.dataset.year);
      document.querySelectorAll("#rmYear [data-year]").forEach((x) => x.classList.toggle("active", x === b));
      const p = new URLSearchParams(window.location.search);
      year === new Date().getFullYear() ? p.delete("year") : p.set("year", year);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      load();
    });
    document.querySelectorAll("#rmYear [data-year]").forEach((x) => x.classList.toggle("active", Number(x.dataset.year) === year));
    ["rmSentRows", "rmInRows"].forEach((t) =>
      $(t).addEventListener("click", (e) => {
        const c = e.target.closest("[data-confirm]");
        if (c) {
          const r = data.coming_in.find((x) => x.id === Number(c.dataset.confirm));
          return confirmWindow(r, load);
        }
        const tr = e.target.closest("tr[data-id]");
        if (tr) view(Number(tr.dataset.id));
      }),
    );
    $("rmBoardRows")?.addEventListener("click", (e) => {
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("tr[data-place]");
      if (tr) statement(Number(tr.dataset.place));
    });
    $("rmTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "owe" ? p.delete("tab") : p.set("tab", tab);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      showTab();
    });
    if (tab === "below" && !document.querySelector('#rmTabs [data-tab="below"]')) tab = "owe";
    A.placePicker($("accPlacePick"), load);
    load().then(() => params.get("remittance") && view(Number(params.get("remittance"))));
  }

  document.addEventListener("DOMContentLoaded", init);
})();
