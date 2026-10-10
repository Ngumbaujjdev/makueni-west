/**
 * ============================================================================
 * ACCOUNTING - Online giving (giving.php, every level)
 * ============================================================================
 * The place's gifts from its public giving page (docs/specs/accounting-spec.md,
 * A10a) - by M-Pesa through the diocese paybill or by card on Paystack - and
 * its giving link to share. Each gift is in the books once it is paid.
 * A10b: its own M-Pesa (PayHero or its own Daraja app) - where it stands, or
 * the steps to set it up.
 * A10c: Payouts - what Paystack paid to our bank, each opening the gifts it
 * carried - and Getting paid: how our card money reaches us, and asking the
 * diocese for our own Paystack.
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
  let po = null;
  let tab = "gifts";
  let year = new Date().getFullYear();

  const ST = { paid: ["success", "ri-checkbox-circle-line"], pending: ["warning", "ri-time-line"], failed: ["danger", "ri-close-circle-line"], abandoned: ["secondary", "ri-close-line"], refunded: ["danger", "ri-arrow-go-back-line"] };
  const PO = { paid: ["success", "ri-checkbox-circle-line"], sent: ["primary", "ri-send-plane-line"], on_the_way: ["warning", "ri-time-line"], failed: ["danger", "ri-error-warning-line"] };
  /** Each giving option's colour comes from its settings (A11). */
  const purposeColour = (k) => ((data?.purposes || []).find((u) => u.key === k) || {}).colour || "secondary";
  const when = (iso) => (iso ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "-");
  const sum = (arr, f = (g) => g.amount) => arr.reduce((t, g) => t + f(g), 0);

  function cards() {
    const ym = new Date().toISOString().slice(0, 7);
    const paid = data.gifts.filter((g) => g.status === "paid");
    const month = paid.filter((g) => (g.paid_at || "").startsWith(ym));
    K.statRow($("statCardsRow"), [
      { icon: "ri-hand-heart-line", label: "Given online this month", sub: `${month.length} gifts`, value: A.figure(sum(month)), color: "success" },
      { icon: "ri-smartphone-line", label: "By M-Pesa", sub: OWN[data.mpesa.route] ? `Straight to our own ${ownChannel()?.till ? "till" : "paybill"}` : "Through the diocese paybill", value: A.figure(sum(month.filter((g) => g.method === "mpesa"))), color: "primary" },
      { icon: "ri-bank-card-line", label: "By card (Paystack)", sub: `Fees ${A.money(sum(month, (g) => g.fee))} this month`, value: A.figure(sum(month.filter((g) => g.method === "paystack"))), color: "purple" },
      { icon: "ri-percent-line", label: "Diocese share split off", sub: "At source on card tithes", value: A.figure(sum(month, (g) => g.split)), color: "warning" },
    ]);
  }

  const OWN = { payhero: "PayHero", daraja: "our own Daraja app" };
  const ownChannel = () => data.mpesa.channels.find((c) => c.provider === data.mpesa.route);

  /** Our own M-Pesa: where it stands, or the steps to get there. */
  function mpesa() {
    const box = $("gvMpesa");
    if (data.place.level === "diocese") {
      box.closest(".card").hidden = true;
      return;
    }
    const ch = ownChannel();
    const saved = data.mpesa.channels.length > 0;
    const on = !!ch;
    const steps = [
      [saved, "Get a paybill or till", "Apply to Safaricom (M-Pesa for Business), or ask our bank for a bank paybill."],
      [saved, "Link it", 'Add it as a payment channel in a <a href="https://payhero.co.ke" target="_blank" rel="noopener">PayHero</a> account - or, for a Safaricom paybill, make an app on <a href="https://developer.safaricom.co.ke" target="_blank" rel="noopener">Daraja</a>.'],
      [saved, "Hand it to the diocese", "The diocese finance officer enters the number and its keys on Gateways - keys are kept encrypted."],
      [on, "Switched on", "M-Pesa on our giving page then comes straight to our own number, into our books."],
    ];
    box.innerHTML = `${on
      ? `<div class="d-flex align-items-center gap-3 mb-3"><span class="avatar avatar-md bg-success text-white"><i class="ri-smartphone-line fs-5"></i></span><div><div class="fw-semibold">${ch.till ? "Till" : "Paybill"} ${esc(ch.number)}</div><div class="acc-sub">Through ${esc(OWN[ch.provider])} · into ${esc(ch.settles_into?.name || "our M-Pesa")}</div></div></div>
         ${ch.till ? "" : `<div class="acc-sub mb-2">Members paying it themselves type what it's for as the account number:</div><div class="d-flex flex-wrap gap-1 mb-3">${data.mpesa.accounts.map((p) => `<span class="soft-chip soft-primary">${esc(p.label)} · <strong>${esc(p.account)}</strong></span>`).join("")}</div>`}`
      : `<p class="mb-3">M-Pesa gifts now go ${data.mpesa.diocese_paybill ? `through the diocese paybill ${esc(data.mpesa.diocese_paybill)} and are settled to us monthly` : "nowhere yet - the diocese paybill isn't set up"}. With our own paybill or till they come straight to us:</p>`}
      <ol class="list-unstyled mb-0">${steps.map(([done, t, sub], i) => `<li class="d-flex gap-2 mb-2"><span class="avatar avatar-xs avatar-rounded ${done ? "bg-success" : "bg-secondary"} text-white flex-shrink-0">${done ? '<i class="ri-check-line"></i>' : i + 1}</span><div><div class="fw-semibold">${t}</div><div class="acc-sub">${sub}</div></div></li>`).join("")}</ol>
      ${saved && !on ? `<div class="alert alert-warning mt-2 mb-0 py-2">Saved on Gateways${data.mpesa.channels.some((c) => !c.ready) ? " but some details are missing" : ""} - waiting to be switched on.</div>` : ""}`;
  }

  function link() {
    const ch = data.paystack.channel;
    $("gvLink").innerHTML = `<div class="give-link mb-3"><code class="d-block text-break fs-6 mb-2">${esc(data.link)}</code><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-primary" data-copy><i class="ri-file-copy-line me-1"></i>Copy</button><a class="btn btn-sm btn-outline-primary" href="${esc(data.link)}" target="_blank" rel="noopener"><i class="ri-external-link-line me-1"></i>Open</a><a class="btn btn-sm btn-outline-success" href="https://wa.me/?text=${encodeURIComponent(`Give your tithe and offering to ${data.place.name} online: ${data.link}`)}" target="_blank" rel="noopener"><i class="ri-whatsapp-line me-1"></i>WhatsApp</a></div></div>
      <div class="acc-facts"><div><span>Our code</span><strong>${esc(data.code)}</strong></div><div><span>Card giving</span><strong>${!data.paystack.ready ? "Not open yet (diocese)" : ch?.status === "active" ? "Our own Paystack - settles to " + esc(ch.settles_into?.name || "our bank") : "Through the diocese - settled monthly"}</strong></div></div>`;
  }

  const rowHtml = (g) => `<tr data-id="${g.id}" data-pills="${g.status} ${g.method}">
    ${K.checkCell(g.id, g.reference)}
    <td data-search="${esc(`${g.giver || ""} ${g.phone || ""} ${g.reference} ${g.receipt || ""}`)}"><div class="fw-semibold">${esc(g.giver || "Online giver")}</div><div class="acc-sub">${esc(g.reference)}${g.phone ? ` · ${esc(g.phone)}` : ""}</div></td>
    <td data-order="${g.paid_at || g.created_at}" class="text-nowrap">${when(g.paid_at || g.created_at)}</td>
    <td class="d-none d-md-table-cell"><span class="badge bg-${purposeColour(g.purpose)} ${A.textOn(purposeColour(g.purpose))}">${esc(g.purpose_label)}</span><div class="acc-sub mt-1">${g.method === "mpesa" ? (g.channel === "payhero" || g.channel === "own_daraja" ? "M-Pesa · our paybill" : "M-Pesa") : "Card"}</div></td>
    <td class="d-none d-lg-table-cell"><span class="badge bg-${ST[g.status][0]} ${A.textOn(ST[g.status][0])}"><i class="${ST[g.status][1]} me-1"></i>${esc(g.status_label)}</span>${g.disputed ? ' <span class="badge bg-danger">Disputed</span>' : ""}<div class="acc-sub mt-1">${g.status === "paid" ? `${g.receipt ? esc(g.receipt) : ""}${g.fee ? ` · fee ${A.money(g.fee)}` : ""}${g.split ? ` · share ${A.money(g.split)}` : ""}` : esc(g.result || "")}</div></td>
    <td class="text-end" data-order="${g.amount}"><strong>${A.money(g.amount)}</strong></td>
  </tr>`;

  function gifts() {
    kit?.destroy();
    if (!data.gifts.length) {
      $("gvPills").innerHTML = "";
      $("gvFilters").innerHTML = "";
      $("gvRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-hand-heart-line", "No gifts online yet", "Share the giving link - members give by M-Pesa or card, and each gift lands in the books.")}</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "gvTable", stripId: "gvFilters", pillsId: "gvPills", rowsId: "gvRows", items: data.gifts, rowHtml, noun: "gifts", defaultPill: "paid",
      searchPlaceholder: "Search giver, phone, reference...",
      pills: [
        { key: "paid", label: "Paid", icon: ST.paid[1], color: "success", test: (g) => g.status === "paid" },
        { key: "pending", label: "Waiting", icon: ST.pending[1], color: "warning", test: (g) => g.status === "pending" },
        { key: "failed", label: "Not paid", icon: ST.failed[1], color: "danger", test: (g) => g.status === "failed" || g.status === "abandoned" },
        { key: "mpesa", label: "M-Pesa", icon: "ri-smartphone-line", color: "primary", test: (g) => g.method === "mpesa" },
        { key: "paystack", label: "Card", icon: "ri-bank-card-line", color: "purple", test: (g) => g.method === "paystack" },
      ],
      sorts: [{ key: "new", label: "Newest first", order: [[2, "desc"]] }, { key: "big", label: "Largest first", order: [[5, "desc"]] }],
      actions: [],
    });
  }

  // ------------------------------------------------------------ payouts (A10c)

  function payoutRows() {
    const st = po.stats;
    $("gvPayoutsFigure").textContent = `${A.money(st.paid)} in ${po.year}`;
    $("gvYears").innerHTML = [0, 1, 2].map((n) => new Date().getFullYear() - n).map((y) => `<button type="button" class="btn btn-sm ${y === po.year ? "btn-primary" : "btn-outline-primary"}" data-year="${y}">${y}</button>`).join("");
    $("gvPayoutsSub").textContent = po.own_paystack ? "Card money Paystack paid to our bank - open one to see the gifts it carried" : "We have no Paystack of our own yet - the diocese holds our card and paybill money and settles it monthly";
    $("gvPayoutFacts").innerHTML = `<div class="acc-facts"><div><span>Paid to our bank in ${po.year}</span><strong>${A.money(st.paid)}</strong></div><div><span>Payouts</span><strong>${st.count}${st.count ? ` · about ${A.money(st.average)} each` : ""}</strong></div><div><span>Last payout</span><strong>${st.last ? `${A.money(st.last.amount)} · ${A.day(st.last.date)}` : "None yet"}</strong></div><div><span>${po.own_paystack ? "On the way" : "Held by the diocese"}</span><strong>${A.money(po.own_paystack ? st.on_the_way : st.held || 0)}</strong></div>${st.failed ? `<div><span>Failed</span><strong class="text-danger">${st.failed} - check our bank details</strong></div>` : ""}</div>`;
    $("gvPayoutRows").innerHTML = po.payouts.length
      ? po.payouts.map((p) => `<tr${p.kind === "paystack" ? ` data-payout="${p.id}" class="acc-row"` : ""}>
          <td class="text-nowrap"><div class="fw-semibold">${A.day(p.date)}</div><div class="acc-sub">${p.kind === "paystack" ? "Paystack" : "From the diocese"}</div></td>
          <td class="d-none d-md-table-cell">${p.kind === "paystack" ? (p.covers_from ? `${A.day(p.covers_from)}${p.covers_to !== p.covers_from ? ` - ${A.day(p.covers_to)}` : ""}<div class="acc-sub">${p.gifts ? `${p.gifts} gifts · ` : ""}${esc(p.matched_label)}</div>` : '<span class="acc-sub">Gifts not found</span>') : `<span class="acc-sub">${esc(p.matched_label || "")}</span>`}</td>
          <td><span class="badge bg-${PO[p.status][0]} ${A.textOn(PO[p.status][0])}"><i class="${PO[p.status][1]} me-1"></i>${esc(p.status_label)}</span></td>
          <td class="d-none d-lg-table-cell">${p.receipt ? esc(p.receipt) : '<span class="acc-sub">-</span>'}</td>
          <td class="text-end"><strong>${A.money(p.amount)}</strong></td></tr>`).join("")
      : `<tr><td colspan="5">${A.empty("ri-bank-line", "No payouts in " + po.year, po.own_paystack ? "Paystack pays card gifts to our bank a day or two after they are given." : "Card gifts reach us through the diocese's monthly settlement until we have our own Paystack - see Getting paid.")}</td></tr>`;
  }

  async function payoutWindow(id) {
    const res = await API.payout(id);
    if (!res.ok) return Toast.error(res.message);
    const d = res.data;
    const match = d.matched === "adds_up" ? '<span class="badge bg-success"><i class="ri-check-double-line me-1"></i>Adds up</span>' : d.matched === "closest" ? '<span class="soft-chip soft-warning">Closest match</span>' : '<span class="soft-chip soft-secondary">Gifts not found</span>';
    const t = d.totals;
    const el = K.confirmWindow({
      title: `${A.money(d.amount)} paid out ${A.day(d.date)}`,
      subtitle: d.covers_from ? `For gifts of ${A.day(d.covers_from)}${d.covers_to !== d.covers_from ? ` - ${A.day(d.covers_to)}` : ""} (Lagos days - Paystack's clock)` : "Paystack doesn't list a payout's gifts - none matched its day",
      icon: "ri-bank-line",
      go: '<i class="ri-check-line me-1"></i>Done',
      body: K.parts([
        { icon: "ri-calculator-line", title: "How it adds up", hint: match, body: `<div class="acc-facts"><div><span>Given</span><strong>${A.money(t.gross)}</strong></div><div><span>Paystack's fees</span><strong>- ${A.money(t.fee)}</strong></div>${d.main ? "" : `<div><span>Diocese share</span><strong>- ${A.money(t.share)}</strong></div>`}<div><span>${d.main ? "The diocese's part" : "To our bank"}</span><strong>${A.money(t.part)}</strong></div>${Math.abs(d.difference) > 0.009 ? `<div><span>Not explained</span><strong class="text-danger">${A.money(d.difference)}</strong></div>` : ""}</div>` },
        { icon: "ri-hand-heart-line", title: `Gifts (${d.gifts.length})`, body: d.gifts.length ? `<div class="table-responsive"><table class="table table-sm mb-0 acc-table"><thead><tr><th>Giver</th><th class="d-none d-sm-table-cell">For</th><th class="text-end d-none d-md-table-cell">Given</th><th class="text-end">${d.main ? "Diocese part" : "To us"}</th></tr></thead><tbody>${d.gifts.map((g) => `<tr><td><div class="fw-semibold">${esc(g.giver)}${g.refunded ? ' <span class="badge bg-danger">Refunded</span>' : ""}</div><div class="acc-sub">${esc(g.reference)}${d.main && g.place ? ` · ${esc(g.place)}` : ""}</div></td><td class="d-none d-sm-table-cell">${esc(g.purpose)}</td><td class="text-end d-none d-md-table-cell">${A.amount(g.gross)}</td><td class="text-end fw-semibold">${A.amount(g.part)}</td></tr>`).join("")}</tbody></table></div>` : '<p class="mb-0">No gifts matched this payout\'s day.</p>' },
      ]),
      run: async (w) => {
        bootstrap.Modal.getInstance(w)?.hide();
        return null;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
  }

  // ------------------------------------------------------------ getting paid (A10c)

  function gettingPaid() {
    const r = po.route;
    const c = r.card;
    const box = $("gvCard");
    $("gvPaidFigure").textContent = c.route === "main" ? "The diocese's account" : r.request ? "Being checked" : c.route === "own" ? "Our own Paystack" : "Through the diocese";
    if (c.route === "main") {
      box.innerHTML = `<p class="mb-2">Card gifts to the diocese, and the share split off each church's card tithes, come to the diocese's own Paystack account.</p><div class="acc-facts"><div><span>Paid into</span><strong>${esc(c.into?.name || "Not picked yet - on Gateways")}</strong></div></div>`;
      return;
    }
    const can = po.can.ask && !A.viewingBelow();
    const now = c.route === "own"
      ? `<div class="d-flex align-items-center gap-3 mb-3"><span class="avatar avatar-md bg-success text-white"><i class="ri-bank-card-line fs-5"></i></span><div><div class="fw-semibold">Our own Paystack</div><div class="acc-sub">${esc(c.bank || "")} ${esc(c.account || "")} · ${esc(c.account_name || "")} · into ${esc(c.into?.name || "our bank")}</div></div></div><p class="acc-sub">Paystack pays card gifts to our bank a day or two after they are given, with the diocese share split off at source.</p>`
      : `<div class="d-flex align-items-center gap-3 mb-3"><span class="avatar avatar-md bg-warning text-dark"><i class="ri-community-line fs-5"></i></span><div><div class="fw-semibold">${c.open ? "Through the diocese" : "Card giving isn't open yet"}</div><div class="acc-sub">${c.open ? "The diocese holds our card gifts and settles them to us monthly, less the share we owe." : "The diocese adds Paystack first."}</div></div></div>`;
    const req = r.request
      ? `<div class="alert alert-warning mb-3"><div class="fw-semibold mb-1"><i class="ri-time-line me-1"></i>${r.request.change ? "A change is being checked" : "Being checked by the diocese"}</div>${esc(r.request.bank)} ${esc(r.request.account_number)} · ${esc(r.request.account_name)} · into ${esc(r.request.into?.name || "")}<div class="acc-sub mt-1">Asked ${A.day(r.request.on)}${r.request.by ? ` by ${esc(r.request.by)}` : ""}</div></div>`
      : r.sent_back ? `<div class="alert alert-danger mb-3"><div class="fw-semibold mb-1"><i class="ri-reply-line me-1"></i>Sent back by the diocese</div>${esc(r.sent_back.note)}</div>` : "";
    const buttons = !can || !c.open ? "" : r.request
      ? '<button type="button" class="btn btn-outline-danger" id="gvWithdraw"><i class="ri-close-line me-1"></i>Withdraw it</button>'
      : `<button type="button" class="btn btn-primary" id="gvAsk"><i class="ri-bank-line me-1"></i>${c.route === "own" ? "Change our bank details" : "Ask for our own Paystack"}</button>`;
    box.innerHTML = `${now}${req}${buttons}`;
  }

  async function askWindow() {
    const res = await API.payoutOptions();
    if (!res.ok) return Toast.error(res.message);
    const d = res.data;
    if (!d.accounts.length) return Toast.error("Add our bank account under Cash & bank first.");
    const el = K.confirmWindow({
      title: po.route.card.route === "own" ? "Change our bank details" : "Our own Paystack",
      subtitle: "The diocese finance officer checks it, then card gifts settle straight to our bank",
      icon: "ri-bank-line",
      go: '<i class="ri-send-plane-line me-1"></i>Send for checking',
      body: K.parts([
        { icon: "ri-bank-line", title: "Our bank, as Paystack pays it", hint: "Type the name exactly as the bank has it - Paystack can't look it up", body: `<div class="row g-2"><div class="col-12"><select class="form-select" id="pqBank"><option value="">Pick the bank</option>${d.banks.map((b) => `<option value="${esc(b.code)}">${esc(b.name)}</option>`).join("")}</select></div><div class="col-sm-6"><input type="text" inputmode="numeric" class="form-control" id="pqNumber" placeholder="Account number"></div><div class="col-sm-6"><input type="text" class="form-control" id="pqName" maxlength="150" placeholder="Account name"></div></div>` },
        { icon: "ri-book-2-line", title: "Recorded into", hint: "Which of our bank accounts in the books", body: `<select class="form-select" id="pqInto">${d.accounts.map((a) => `<option value="${a.id}">${esc(a.code)} · ${esc(a.name)}${a.account_number ? ` (${esc(a.account_number)})` : ""}</option>`).join("")}</select>` },
      ]),
      run: async () => {
        const out = await API.askPayout({ bank_code: el.querySelector("#pqBank").value || null, account_number: el.querySelector("#pqNumber").value.trim() || null, account_name: el.querySelector("#pqName").value.trim() || null, settles_into_id: Number(el.querySelector("#pqInto").value) });
        if (out.ok) loadPayouts();
        return out;
      },
    });
    ["#pqBank", "#pqInto"].forEach((x) => UI.enhanceSelect(el.querySelector(x), { search: x === "#pqBank" }));
  }

  async function loadPayouts() {
    $("gvPayoutRows").innerHTML = UI.renderTableLoading(5);
    const res = await API.payouts(year);
    if (!res.ok) {
      $("gvPayoutRows").innerHTML = `<tr><td colspan="5">${A.errorBox(res.message)}</td></tr>`;
      return;
    }
    po = res.data;
    payoutRows();
    gettingPaid();
  }

  function showTab() {
    document.querySelectorAll("#gvTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("gvGiftsPane").hidden = tab !== "gifts";
    $("gvPayoutsPane").hidden = tab !== "payouts";
    $("gvPaidPane").hidden = tab !== "paid";
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(data.link);
      Toast.success("Giving link copied.");
    } catch (e) {
      Toast.error("Copy didn't work - select the link instead.");
    }
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("gvRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.giving();
    if (!res.ok) {
      $("gvTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    link();
    mpesa();
    const month = data.gifts.filter((g) => g.status === "paid" && (g.paid_at || "").startsWith(new Date().toISOString().slice(0, 7)));
    $("gvGiftsFigure").textContent = `${month.length} this month`;
    loadPayouts();
    gifts();
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("copyLinkBtn").addEventListener("click", copy);
    $("gvLink").addEventListener("click", (e) => e.target.closest("[data-copy]") && copy());
    const q = new URLSearchParams(window.location.search);
    if (["payouts", "paid"].includes(q.get("tab"))) tab = q.get("tab");
    showTab();
    $("gvTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "gifts" ? p.delete("tab") : p.set("tab", tab);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      showTab();
    });
    $("gvPayoutsPane").addEventListener("click", (e) => {
      const y = e.target.closest("[data-year]");
      if (y) return ((year = Number(y.dataset.year)), loadPayouts());
      const tr = e.target.closest("[data-payout]");
      if (tr) payoutWindow(Number(tr.dataset.payout));
    });
    $("gvPaidPane").addEventListener("click", async (e) => {
      if (e.target.closest("#gvAsk")) return askWindow();
      const w = e.target.closest("#gvWithdraw");
      if (w) {
        UI.setButtonLoading(w, "...");
        const out = await API.withdrawPayout();
        UI.restoreButton(w);
        out.ok ? (Toast.success(out.message), loadPayouts()) : Toast.error(out.message);
      }
    });
    A.placePicker($("accPlacePick"), load);
    load();
  });
})();
