/**
 * ============================================================================
 * ACCOUNTING - Paybill (paybill.php, every level)
 * ============================================================================
 * The diocese M-Pesa paybill (docs/specs/accounting-spec.md, A8). At the
 * diocese: every payment, what waits To sort, the monthly Settlements and the
 * Setup. At a church or region: its own paybill giving (in its books the same
 * day), what the diocese holds for it, how members pay, and its settlements.
 * "Ask to pay" sends the M-Pesa prompt to a member's phone.
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
  const n = (v) => Math.round(parseFloat(String(v ?? "").replace(/[^0-9.]/g, "")) * 100) / 100 || 0;
  const params = new URLSearchParams(window.location.search);
  let data = null;
  let kit = null;
  let tab = params.get("tab") || "payments";
  let month = params.get("month") || new Date(new Date().getFullYear(), new Date().getMonth() - 1, 15).toISOString().slice(0, 7);

  const ST = { posted: ["success", "ri-checkbox-circle-line", "In the books"], to_sort: ["warning", "ri-question-line", "To sort"], returned: ["secondary", "ri-arrow-go-back-line", "Returned"] };
  /** Each giving option's colour and icon come from its settings (A11). */
  const purposeOf = (k) => (data?.purposes || []).find((u) => u.key === k) || {};
  const purposeColour = (k) => purposeOf(k).colour || "secondary";
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const monthLabel = (m) => new Date(`${m}-15T12:00:00`).toLocaleDateString("en-GB", { month: "long", year: "numeric" });
  const sum = (arr) => arr.reduce((t, p) => t + p.amount, 0);
  const when = (iso) => (iso ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "-");

  // ------------------------------------------------------------ the page

  function cards() {
    const ym = new Date().toISOString().slice(0, 7);
    const pays = data.payments;
    const month0 = pays.filter((p) => (p.paid_at || "").startsWith(ym));
    const today = pays.filter((p) => (p.paid_at || "").slice(0, 10) === new Date().toISOString().slice(0, 10));
    const tithe = month0.filter((p) => p.purpose === "T");
    K.statRow($("statCardsRow"), [
      { icon: "ri-smartphone-line", label: "This month", sub: `${month0.length} payments`, value: A.figure(sum(month0)), color: "success" },
      { icon: "ri-sun-line", label: "Today", sub: `${today.length} payments`, value: A.figure(sum(today)), color: "primary" },
      data.diocese
        ? { icon: "ri-question-line", label: "To sort", sub: data.to_sort ? "Account numbers that named no place" : "Nothing waiting", value: A.num(data.to_sort), color: data.to_sort ? "warning" : "success" }
        : { icon: "ri-safe-2-line", label: "Held by the diocese", sub: "Until it settles - in our books as 1310", value: A.figure(data.held || 0), color: "warning" },
      { icon: "ri-hand-heart-line", label: "Tithe this month", sub: `${tithe.length} payments`, value: A.figure(sum(tithe)), color: "purple" },
    ]);
    $("pbPaymentsFigure").textContent = `${A.money(sum(month0))} this month`;
    if ($("pbSortFigure")) $("pbSortFigure").textContent = data.to_sort ? `${data.to_sort} waiting` : "Nothing waiting";
    if ($("pbNumbersFigure")) $("pbNumbersFigure").textContent = data.own ? `Our ${data.own.till ? "till" : "paybill"} ${data.own.number}` : data.shortcode ? `Paybill ${data.shortcode}` : "Not set up yet";
    if ($("pbSetupFigure")) $("pbSetupFigure").textContent = data.setup.ready ? (data.setup.environment === "production" ? "Live" : "Sandbox") : "Not set up";
  }

  const rowHtml = (p) => `<tr class="acc-row" data-id="${p.id}" data-pills="${p.status}${p.purpose ? ` p${p.purpose}` : ""}${p.own ? " own" : ""}">
    ${K.checkCell(p.id, p.trans_id)}
    <td data-search="${esc(`${p.payer_name || ""} ${p.phone || ""} ${p.trans_id} ${p.bill_ref || ""} ${p.place?.name || ""}`)}"><div class="fw-semibold">${esc(p.payer_name || "M-Pesa payer")}</div><div class="acc-sub">${esc(p.trans_id)}${p.phone ? ` · ${esc(p.phone)}` : ""}</div></td>
    <td data-order="${p.paid_at}" class="text-nowrap">${when(p.paid_at)}</td>
    <td class="d-none d-md-table-cell"><span class="soft-chip soft-secondary">${esc(p.bill_ref || "-")}</span></td>
    <td class="d-none d-lg-table-cell">${p.status === "posted" ? `${data.diocese ? `<div class="fw-semibold">${esc(p.place?.name || "")}</div>` : ""}<span class="badge bg-${purposeColour(p.purpose)} ${A.textOn(purposeColour(p.purpose))}">${esc(p.purpose_label || "")}</span>${p.receipt ? `<div class="acc-sub mt-1">${esc(p.receipt)}</div>` : ""}` : `${pill(p.status)}${p.note ? `<div class="acc-sub mt-1">${esc(p.note)}</div>` : ""}`}</td>
    <td class="text-end" data-order="${p.amount}"><strong>${A.money(p.amount)}</strong></td>
  </tr>`;

  function payments() {
    kit?.destroy();
    if (!data.payments.length) {
      $("pbPills").innerHTML = "";
      $("pbFilters").innerHTML = "";
      $("pbRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-smartphone-line", "No paybill payments yet", data.diocese ? (data.setup.ready ? "Payments show here as Safaricom sends them." : "Set up the paybill first - Setup tab.") : (data.own ? `When members pay our ${data.own.till ? "till" : "paybill"} ${data.own.number}, each payment shows here.` : "When members pay the diocese paybill with our account number, it shows here."))}</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "pbTable",
      stripId: "pbFilters",
      pillsId: "pbPills",
      rowsId: "pbRows",
      items: data.payments,
      rowHtml,
      noun: "payments",
      defaultPill: "all",
      searchPlaceholder: "Search name, phone, M-Pesa code, account...",
      pills: [
        ...(data.diocese ? [{ key: "to_sort", label: "To sort", icon: ST.to_sort[1], color: "warning", test: (p) => p.status === "to_sort" }] : []),
        ...data.purposes.map((u) => ({ key: `p${u.key}`, label: u.label, icon: "ri-price-tag-3-line", color: u.colour || "secondary", test: (p) => p.purpose === u.key && p.status === "posted" })),
      ],
      sorts: [
        { key: "new", label: "Newest first", order: [[2, "desc"]] },
        { key: "big", label: "Largest first", order: [[5, "desc"]] },
      ],
      actions: [],
    });
  }

  function toSort() {
    if (!$("pbSortRows")) return;
    const waiting = data.payments.filter((p) => p.status === "to_sort");
    $("pbSortRows").innerHTML = waiting.length
      ? waiting.map((p) => `<tr><td><div class="fw-semibold">${esc(p.payer_name || "M-Pesa payer")}</div><div class="acc-sub">${esc(p.trans_id)}${p.phone ? ` · ${esc(p.phone)}` : ""}</div></td><td class="d-none d-md-table-cell text-nowrap">${when(p.paid_at)}</td><td><span class="soft-chip soft-warning">${esc(p.bill_ref || "(nothing)")}</span>${p.note ? `<div class="acc-sub mt-1">${esc(p.note)}</div>` : ""}</td><td class="text-end"><strong>${A.money(p.amount)}</strong></td><td class="text-end">${data.can.manage && !A.viewingBelow() ? `<button type="button" class="btn btn-sm btn-primary" data-sort="${p.id}"><i class="ri-git-merge-line me-1"></i>Sort</button>` : ""}</td></tr>`).join("")
      : `<tr><td colspan="5">${A.empty("ri-checkbox-circle-line", "Nothing to sort", "Every payment named its place.")}</td></tr>`;
  }

  const quote = (a) => `<div class="acc-quote"><div class="min-w-0 flex-fill"><div class="fw-semibold">${esc(a.label)}</div><div class="acc-sub">Account number</div></div><strong class="fs-5 text-nowrap">${esc(a.account)}</strong><button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-copy="${esc(a.account)}" aria-label="Copy ${esc(a.account)}"><i class="ri-file-copy-line"></i></button></div>`;

  function numbers() {
    if (!$("pbNumbers")) return;
    const own = data.own;
    if (own) {
      // Our own paybill or till (A10b): straight into our books, nothing held by the diocese.
      $("pbNumbers").innerHTML = `<div class="row g-3"><div class="col-lg-5"><div class="acc-facts"><div><span>Our own ${own.till ? "till (Buy Goods)" : "paybill"}</span><strong class="fs-4">${esc(own.number)}</strong></div><div><span>Steps on the phone</span><strong>M-Pesa → Lipa na M-Pesa → ${own.till ? `Buy Goods → ${esc(own.number)}` : `Pay Bill → ${esc(own.number)} → the account number`} → the amount → PIN</strong></div></div><p class="acc-sub mt-2 mb-0">Through ${esc(own.label)} - the money comes straight to us and is in our books at once. We send the diocese share under Remittances as usual.</p></div>
        <div class="col-lg-7">${own.till ? `<p class="mb-0">A till takes no account number - gifts paid to it straight count as ${esc(data.account_numbers[0]?.label?.replace(/^Any \(|\)$/g, "") || "offering")}. The giving page asks what each gift is for.</p>` : `<div class="acc-quotes">${own.accounts.map(quote).join("")}</div>`}</div></div>`;
      return;
    }
    const short = data.shortcode;
    $("pbNumbers").innerHTML = !short
      ? A.empty("ri-smartphone-line", "The diocese paybill isn't set up yet", "Once it is, members pay with our account number below.")
      : `<div class="row g-3"><div class="col-lg-5"><div class="acc-facts"><div><span>Business number (paybill)</span><strong class="fs-4">${esc(short)}</strong></div><div><span>Steps on the phone</span><strong>M-Pesa → Lipa na M-Pesa → Pay Bill → ${esc(short)} → the account number → the amount → PIN</strong></div></div><p class="acc-sub mt-2 mb-0">The giving is in our books the same day. The diocese holds the money and pays it to us monthly, less the diocese share we owe.</p></div>
        <div class="col-lg-7"><div class="acc-quotes">${data.account_numbers.map(quote).join("")}</div></div></div>`;
  }

  async function settlements() {
    const body = $("pbSettleBody");
    if (!data.diocese) {
      $("pbSettleSub").textContent = "Paybill money the diocese paid us - confirm each when it reaches the account (under Remittances)";
      $("pbSettleFigure").textContent = (data.settlements || []).some((s) => s.status === "sent") ? "To confirm" : `${(data.settlements || []).length} so far`;
      body.innerHTML = (data.settlements || []).length
        ? `<div class="table-responsive"><table class="table mb-0 acc-table"><tbody>${data.settlements.map((s) => `<tr><td><div class="fw-semibold">${esc(s.purpose)}</div><div class="acc-sub">${esc(s.number)}${s.sent_on ? ` · sent ${A.day(s.sent_on)}` : ""}</div></td><td><span class="badge bg-${s.status === "confirmed" ? "success" : s.status === "sent" ? "primary" : "secondary"}">${esc(s.status_label)}</span></td><td class="text-end"><strong>${A.money(s.amount)}</strong></td><td class="text-end">${s.status === "sent" ? `<a class="btn btn-sm btn-success" href="${A.link("remittances.php", { tab: "in", remittance: s.id })}">Confirm</a>` : ""}</td></tr>`).join("")}</tbody></table></div>`
        : `<div class="p-3">${A.empty("ri-exchange-funds-line", "No settlements yet", "The diocese settles each month's paybill money, less the share we owe.")}</div>`;
      return;
    }
    $("pbSettleSub").textContent = `${monthLabel(month)}: what the diocese holds for each place, less the share it owes, paid by a voucher`;
    const months = Array.from({ length: 12 }, (_, i) => new Date(new Date().getFullYear(), new Date().getMonth() - 1 - i, 15).toISOString().slice(0, 7));
    $("pbMonthPick").innerHTML = `<select class="form-select" id="pbMonth" aria-label="Month">${months.map((m) => `<option value="${m}"${m === month ? " selected" : ""}>${monthLabel(m)}</option>`).join("")}</select>`;
    UI.enhanceSelect($("pbMonth"), { search: false });
    $("pbMonth").addEventListener("change", (e) => {
      month = e.target.value;
      const p = new URLSearchParams(window.location.search);
      p.set("month", month);
      history.replaceState(null, "", `${window.location.pathname}?${p}`);
      settlements();
    });
    body.innerHTML = UI.renderTableLoading(4);
    const res = await API.paybillSettlements(month);
    if (!res.ok) return (body.innerHTML = A.errorBox(res.message));
    const s = res.data;
    const open = s.preview.filter((r) => !r.settled);
    $("pbSettleFigure").textContent = open.length ? `${open.length} to settle` : "All settled";
    body.innerHTML = `${
      open.length
        ? `<div class="table-responsive"><table class="table mb-0 acc-table"><thead><tr><th class="pp-check"><input type="checkbox" class="form-check-input" id="pbPickAll" checked aria-label="Pick all"></th><th>Place</th><th class="text-end">Held</th><th class="text-end">Share netted</th><th class="text-end">To pay</th></tr></thead><tbody>${open.map((r) => `<tr><td class="pp-check"><input type="checkbox" class="form-check-input" data-pick="${r.place.id}" checked aria-label="Settle ${esc(r.place.name)}"></td><td><div class="fw-semibold">${esc(r.place.name)}</div><div class="acc-sub">${esc(r.place.code || "")}</div></td><td class="text-end">${A.amount(r.held)}</td><td class="text-end">${A.amount(r.share) || "-"}</td><td class="text-end"><strong>${A.money(r.net)}</strong></td></tr>`).join("")}</tbody></table></div>`
        : `<div class="p-3">${A.empty("ri-checkbox-circle-line", "Nothing to settle for " + monthLabel(month), "Every place's paybill money for the month is settled.")}</div>`
    }${
      s.settled.length
        ? `<div class="p-3 pt-2"><div class="app-modal-part-head mt-2"><i class="ri-history-line"></i>Settled for ${esc(monthLabel(month))}</div><div class="acc-quotes">${s.settled.map((x) => `<div class="acc-quote${x.status === "paid" ? " is-chosen" : ""}"><div class="min-w-0 flex-fill"><div class="fw-semibold">${esc(x.place?.name || "")}</div><div class="acc-sub">held ${A.money(x.held)} · share ${A.money(x.share)}${x.remittance ? ` · ${esc(x.remittance.number)} ${esc(x.remittance.status)}` : ""}</div></div><strong>${A.money(x.net)}</strong>${x.remittance?.voucher ? `<a class="btn btn-sm btn-outline-primary" href="${A.link("payments.php", { voucher: x.remittance.voucher.id })}">Voucher</a>` : ""}${x.status === "prepared" ? `<button type="button" class="btn btn-sm btn-outline-danger" data-unsettle="${x.id}">Cancel</button>` : ""}</div>`).join("")}</div></div>`
        : ""
    }`;
    body.querySelector("#pbPickAll")?.addEventListener("change", (e) => body.querySelectorAll("[data-pick]").forEach((x) => (x.checked = e.target.checked)));
    body.dataset.cash = JSON.stringify(s.cash);
  }

  function setup() {
    if (!$("pbSetup")) return;
    const s = data.setup;
    const fact = (k, v, ok) => `<div><span>${esc(k)}</span><strong class="${ok === false ? "text-danger" : ""}">${v}</strong></div>`;
    $("pbSetup").innerHTML = `<div class="acc-facts mb-3">${fact("Safaricom", s.environment === "production" ? "Live - real money" : "Sandbox - testing")}${fact("Paybill number", esc(s.shortcode || "Not set"), !!s.shortcode)}${fact("App key and secret", s.ready ? "Saved" : "Missing", s.ready)}${fact("Ask to pay (passkey)", s.can_ask ? "Ready" : "Add the passkey", s.can_ask)}${fact("Callback key", s.callbacks ? "Made" : "Made when you register", s.callbacks || null)}${fact("Last payment in", s.last_payment ? when(s.last_payment) : "None yet")}</div>
      <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="${CTX.siteUrl}/diocese/settings/?section=paybill"><i class="ri-settings-3-line me-1"></i>Settings, Paybill</a>${s.ready ? '<button type="button" class="btn btn-primary" id="pbRegister"><i class="ri-links-line me-1"></i>Register our addresses with Safaricom</button>' : ""}${s.ready && s.environment !== "production" ? '<button type="button" class="btn btn-outline-success" id="pbSimulate"><i class="ri-flask-line me-1"></i>Send a test payment</button>' : ""}</div>
      <p class="acc-sub mt-3 mb-0">Register once, and again after changing the paybill, the public address or the callback key. Safaricom then sends every payment here; each lands in the diocese books (held for its place) and in the place's books the same day.</p>`;
  }

  function showTab() {
    const tabs = [...document.querySelectorAll("#pbTabs [data-tab]")].map((b) => b.dataset.tab);
    if (!tabs.includes(tab)) tab = "payments";
    document.querySelectorAll("#pbTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    ["payments", "sort", "numbers", "settlements", "setup"].forEach((t) => $(`pb${t[0].toUpperCase()}${t.slice(1)}Pane`) && ($(`pb${t[0].toUpperCase()}${t.slice(1)}Pane`).hidden = tab !== t));
  }

  async function load() {
    A.ownOnly();
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("pbRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.paybill();
    if (!res.ok) {
      $("pbTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    if ($("askBtn")) $("askBtn").hidden = !data.can.ask || !data.setup.can_ask;
    cards();
    payments();
    toSort();
    numbers();
    setup();
    showTab();
    settlements();
  }

  // ------------------------------------------------------------ windows

  function sortWindow(p) {
    const el = K.confirmWindow({
      title: `Sort ${p.trans_id}`,
      subtitle: `${A.money(p.amount)} from ${p.payer_name || "an M-Pesa payer"} - typed "${p.bill_ref || ""}"`,
      icon: "ri-git-merge-line",
      go: '<i class="ri-check-line me-1"></i>Sort it',
      body: K.parts([
        { icon: "ri-map-pin-line", title: "It is for", body: `<div class="acc-tiles">${[["place", "A church or region", "ri-community-line", "primary"], ["diocese", "The diocese", "ri-government-line", "success"], ["return", "Return it to the payer", "ri-arrow-go-back-line", "danger"]].map(([k, t, i, c], x) => `<label class="acc-tile" style="--q: var(--${c}-rgb)"><input type="radio" name="sTo" value="${k}"${x === 0 ? " checked" : ""}><span class="acc-tile-icon"><i class="${i}"></i></span><span class="acc-tile-text"><strong>${t}</strong></span></label>`).join("")}</div>` },
        { icon: "ri-community-line", title: "Which place", body: `<div data-show="place"><select class="form-select" id="sPlace"><option value="">Pick the place</option>${(data.places || []).map((t) => `<option value="${t.id}">${esc(t.name)} (${esc(t.code)})</option>`).join("")}</select></div><p class="acc-sub mb-0" data-show="diocese,return" hidden>Not needed.</p>` },
        { icon: "ri-price-tag-3-line", title: "Given for", body: `<div data-show="place,diocese"><select class="form-select" id="sPurpose">${data.purposes.map((u) => `<option value="${u.key}">${esc(u.label)}</option>`).join("")}</select></div><p class="acc-sub mb-0" data-show="return" hidden>A voucher to pay it back goes for approval, then it is paid as usual.</p>` },
        { icon: "ri-chat-3-line", title: "Note", body: '<input type="text" class="form-control" id="sNote" maxlength="255" placeholder="e.g. Called the payer - it was for Sultan Hamud">' },
      ]),
      run: async () => {
        const to = el.querySelector('input[name="sTo"]:checked').value;
        const out = await API.sortPayment(p.id, { to, territory_id: Number(el.querySelector("#sPlace").value) || null, purpose: el.querySelector("#sPurpose").value, note: el.querySelector("#sNote").value.trim() || null });
        if (out.ok) load();
        return out;
      },
    });
    const show = () => {
      const to = el.querySelector('input[name="sTo"]:checked').value;
      el.querySelectorAll("[data-show]").forEach((x) => (x.hidden = !x.dataset.show.split(",").includes(to)));
    };
    el.querySelectorAll('input[name="sTo"]').forEach((r) => r.addEventListener("change", show));
    UI.enhanceSelect(el.querySelector("#sPlace"), { search: true });
    UI.enhanceSelect(el.querySelector("#sPurpose"), { search: false });
    show();
  }

  function askWindow() {
    const forPlace = data.diocese && data.can.manage;
    const el = K.confirmWindow({
      title: "Ask to pay",
      subtitle: "The M-Pesa prompt appears on their phone - they only enter their PIN",
      icon: "ri-smartphone-line",
      go: '<i class="ri-send-plane-line me-1"></i>Send the prompt',
      body: K.parts([
        ...(forPlace ? [{ icon: "ri-community-line", title: "For", body: `<select class="form-select" id="aFor"><option value="">The diocese itself</option>${(data.places || []).map((t) => `<option value="${t.id}">${esc(t.name)} (${esc(t.code)})</option>`).join("")}</select>` }] : []),
        { icon: "ri-price-tag-3-line", title: "Given for", body: `<div class="acc-tiles">${data.purposes.map((u, i) => `<label class="acc-tile" style="--q: var(--${esc(u.colour || "primary")}-rgb)"><input type="radio" name="aPurpose" value="${esc(u.key)}"${i === 0 ? " checked" : ""}><span class="acc-tile-icon"><i class="${esc(u.icon || "ri-price-tag-3-line")}"></i></span><span class="acc-tile-text"><strong>${esc(u.label)}</strong></span></label>`).join("")}</div>` },
        { icon: "ri-phone-line", title: "Phone and amount", body: '<div class="row g-2"><div class="col-sm-7"><input type="tel" class="form-control" id="aPhone" placeholder="0712 345 678" autocomplete="off"></div><div class="col-sm-5"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" id="aAmount" placeholder="0"></div></div></div><div class="acc-sub mt-2" id="aWait"></div>' },
      ]),
      run: async () => {
        const out = await API.askToPay({ phone: el.querySelector("#aPhone").value, amount: n(el.querySelector("#aAmount").value), purpose: el.querySelector('input[name="aPurpose"]:checked').value, for_id: forPlace ? Number(el.querySelector("#aFor").value) || null : null });
        if (out.ok) follow(out.data.id);
        return out;
      },
    });
    if (el.querySelector("#aFor")) UI.enhanceSelect(el.querySelector("#aFor"), { search: true });
  }

  /** Follow the prompt until it is paid, refused or 2 minutes pass. */
  function follow(id) {
    let tries = 0;
    const tick = async () => {
      const r = await API.askStatus(id);
      if (r.ok && r.data.status === "paid") {
        Toast.success(`Paid - KES ${A.num(r.data.amount)} for ${r.data.account_ref} is in the books.`);
        return load();
      }
      if (r.ok && r.data.status === "failed") return Toast.error(`Not paid: ${r.data.result || "the prompt was cancelled"}.`);
      if (++tries < 40) setTimeout(tick, 3000);
    };
    setTimeout(tick, 4000);
  }

  function settleWindow() {
    tab = "settlements";
    showTab();
    const body = $("pbSettleBody");
    const picked = [...body.querySelectorAll("[data-pick]:checked")].map((x) => Number(x.dataset.pick));
    if (!picked.length) return Toast.error("Pick the places to settle in the Settlements list.");
    const cash = JSON.parse(body.dataset.cash || "[]");
    K.confirmWindow({
      title: `Settle ${monthLabel(month)}`,
      subtitle: `${picked.length} ${picked.length === 1 ? "place" : "places"} - the share is netted now; each payment goes for approval, then it is paid`,
      icon: "ri-hand-coin-line",
      go: '<i class="ri-check-line me-1"></i>Settle',
      body: K.parts([{ icon: "ri-bank-line", title: "Pay them from", body: W.cashTiles(cash, cash.find((a) => a.cash_kind === "bank")?.id || cash[0]?.id, "stFrom") }]),
      run: async () => {
        const out = await API.settlePaybill({ month, places: picked, pay_from_account_id: Number(document.querySelector('input[name="stFrom"]:checked')?.value) });
        if (out.ok) setTimeout(settlements, 300);
        return out;
      },
    });
  }

  function simulateWindow() {
    const el = K.confirmWindow({
      title: "Send a test payment",
      subtitle: "Sandbox only - Safaricom sends it back to us like a real one",
      icon: "ri-flask-line",
      go: '<i class="ri-send-plane-line me-1"></i>Send',
      body: K.parts([{ icon: "ri-hashtag", title: "Payment", body: `<div class="row g-2"><div class="col-sm-4"><input type="text" class="form-control" id="tAcc" value="${esc((data.places || [])[0]?.code || "MWD")}T" aria-label="Account number"></div><div class="col-sm-4"><input type="tel" class="form-control" id="tPhone" value="254708374149" aria-label="Phone"></div><div class="col-sm-4"><input type="text" inputmode="decimal" class="form-control text-end" id="tAmt" value="100" aria-label="Amount"></div></div>` }]),
      run: async () => {
        const out = await API.simulatePaybill({ account: el.querySelector("#tAcc").value.trim(), phone: el.querySelector("#tPhone").value.trim(), amount: n(el.querySelector("#tAmt").value) });
        if (out.ok) setTimeout(load, 5000);
        return out;
      },
    });
  }

  // ------------------------------------------------------------ start

  function init() {
    $("askBtn")?.addEventListener("click", askWindow);
    $("settleBtn")?.addEventListener("click", settleWindow);
    $("pbRows").addEventListener("click", (e) => {
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("tr[data-id]");
      const p = tr && data.payments.find((x) => x.id === Number(tr.dataset.id));
      if (p && p.status === "to_sort" && data.can.manage) sortWindow(p);
    });
    $("pbSortRows")?.addEventListener("click", (e) => {
      const b = e.target.closest("[data-sort]");
      if (b) sortWindow(data.payments.find((x) => x.id === Number(b.dataset.sort)));
    });
    $("pbNumbers")?.addEventListener("click", async (e) => {
      const b = e.target.closest("[data-copy]");
      if (!b) return;
      try {
        await navigator.clipboard.writeText(b.dataset.copy);
        Toast.success(`Copied ${b.dataset.copy}`);
      } catch (err) {
        Toast.error("Copy didn't work - select it instead.");
      }
    });
    $("pbSettleBody").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-unsettle]");
      if (!b || !confirm("Cancel this settlement? The share netting is undone and its voucher cancelled.")) return;
      const out = await API.cancelSettlement(Number(b.dataset.unsettle));
      out.ok ? (Toast.success(out.message), settlements()) : Toast.error(out.message);
    });
    $("pbSetup")?.addEventListener("click", async (e) => {
      if (e.target.closest("#pbSimulate")) return simulateWindow();
      const b = e.target.closest("#pbRegister");
      if (!b) return;
      UI.setButtonLoading(b, "Registering...");
      const out = await API.registerPaybill();
      UI.restoreButton(b);
      out.ok ? (Toast.success(out.message), load()) : Toast.error(out.message);
    });
    $("pbTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "payments" ? p.delete("tab") : p.set("tab", tab);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      showTab();
    });
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
