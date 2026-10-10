/**
 * ============================================================================
 * ACCOUNTING - Gateways (gateways.php, diocese)
 * ============================================================================
 * Each church's Paystack subaccount (docs/specs/accounting-spec.md, A10a):
 * made on Paystack from the church's bank details, off until switched on,
 * and which of its bank accounts the payouts are recorded into. The diocese
 * sets where its own payouts land. The latest payouts below.
 * A10b: a church's own paybill or till - through PayHero or its own Daraja
 * app - so M-Pesa gifts land straight in its own M-Pesa. Keys go in and are
 * only ever shown as "saved".
 * A10c: Requests - churches asking for their own Paystack, checked here -
 * and Payouts per place, each opening that place's payouts.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const K = PeopleKit;
  const API = AccountingAPI;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  let kit = null;
  let po = null;
  let poKit = null;
  let tab = "places";
  let period = "year";

  const pill = (c) => (!c ? '<span class="badge bg-secondary">Not set up</span>' : c.status === "pending" ? '<span class="badge bg-warning text-dark"><i class="ri-inbox-archive-line me-1"></i>Asked</span>' : c.status === "active" ? '<span class="badge bg-success"><i class="ri-checkbox-circle-line me-1"></i>On</span>' : '<span class="badge bg-warning text-dark"><i class="ri-pause-circle-line me-1"></i>Off</span>');

  function cards() {
    const ch = data.places.filter((p) => p.level !== "diocese");
    const on = ch.filter((p) => p.channel?.status === "active");
    const own = ch.filter((p) => p.mpesa.some((m) => m.status === "active"));
    K.statRow($("statCardsRow"), [
      { icon: "ri-bank-card-line", label: "Paystack", sub: data.ready ? "Keys saved" : "Add the keys in Settings", value: data.ready ? (data.mode === "live" ? "Live" : "Test") : "Off", color: data.ready ? "success" : "danger" },
      { icon: "ri-community-line", label: "Churches on their own Paystack", sub: `${ch.length - on.length} through the diocese`, value: `${on.length}/${ch.length}`, color: "primary" },
      { icon: "ri-time-line", label: "Card gifts waiting", sub: "Checked every 10 minutes", value: A.num(data.gifts_pending), color: "warning" },
      { icon: "ri-smartphone-line", label: "Churches on their own M-Pesa", sub: `${ch.length - own.length} through the diocese paybill`, value: `${own.length}/${ch.length}`, color: "purple" },
    ]);
    $("gwReady").innerHTML = data.ready ? "" : `<div class="alert alert-warning">Paystack isn't set up - add the secret key in <a href="${CTX.siteUrl}/diocese/settings/?section=giving">Settings, Online giving</a>. Until then the giving page offers M-Pesa only.</div>`;
  }

  const MPESA_ICON = { payhero: "ri-flashlight-line", daraja: "ri-smartphone-line" };

  /** The place's own M-Pesa: each channel with its state and what can be done next. */
  const mpesaCell = (p) => {
    if (p.level === "diocese") return '<span class="soft-chip soft-success"><i class="ri-smartphone-line me-1"></i>The diocese paybill</span>';
    const rows = p.mpesa.map((m) => `<div class="mb-2"><div class="d-flex flex-wrap align-items-center gap-1"><span class="fw-semibold"><i class="${MPESA_ICON[m.provider]} me-1"></i>${esc(m.provider_label)}</span>${pill(m)}${m.environment === "sandbox" ? '<span class="soft-chip soft-warning">Sandbox</span>' : ""}</div>
      <div class="acc-sub">${m.till ? "Till" : "Paybill"} ${esc(m.number || "")} · into ${esc(m.settles_into?.name || "-")}${m.ready ? "" : ' · <span class="text-danger">details missing</span>'}</div>
      <div class="d-flex flex-wrap gap-1 mt-1"><button type="button" class="btn btn-sm ${m.status === "active" ? "btn-outline-warning" : "btn-success"}" data-toggle="${m.id}" data-to="${m.status === "active" ? "off" : "active"}">${m.status === "active" ? "Switch off" : "Switch on"}</button><button type="button" class="btn btn-sm btn-outline-primary" data-mpesa-edit="${m.id}" data-place="${p.id}">Change</button>${m.provider === "daraja" ? `<button type="button" class="btn btn-sm btn-outline-primary" data-register="${m.id}">Register</button>` : ""}</div></div>`).join("");
    const more = p.mpesa.length < 2 ? `<button type="button" class="btn btn-sm btn-outline-primary" data-mpesa-new="${p.id}"><i class="ri-add-line me-1"></i>${p.mpesa.length ? "Another route" : "Own paybill"}</button>` : "";
    return `${rows || '<div class="acc-sub mb-1">Through the diocese paybill</div>'}${more}`;
  };

  const rowHtml = (p) => `<tr data-id="${p.id}" data-pills="${p.channel ? p.channel.status : "none"} ${p.mpesa.some((m) => m.status === "active") ? "ownmpesa" : ""} ${p.level}">
    ${K.checkCell(p.id, p.name)}
    <td data-search="${esc(`${p.name} ${p.code}`)}" data-order="${esc(p.name)}"><div class="fw-semibold">${esc(p.name)}</div><div class="acc-sub">${esc(p.code)} · ${esc(p.level)}</div></td>
    <td class="d-none d-md-table-cell">${p.channel ? `${esc(p.channel.bank || "")} ${esc(p.channel.account_number || "")}<div class="acc-sub">into ${esc(p.channel.settles_into?.name || "-")}</div>` : '<span class="acc-sub">-</span>'}</td>
    <td>${p.level === "diocese" ? (p.channel ? '<span class="badge bg-success">Payouts recorded</span>' : '<span class="badge bg-secondary">Pick its bank account</span>') : pill(p.channel)}${p.channel?.subaccount ? `<div class="acc-sub mt-1">${esc(p.channel.subaccount)}</div>` : ""}</td>
    <td>${mpesaCell(p)}</td>
    <td class="text-end text-nowrap">${p.channel?.status === "pending" ? (p.channel.request ? '<button type="button" class="btn btn-sm btn-warning" data-gotab="requests"><i class="ri-search-eye-line me-1"></i>Check</button>' : `<button type="button" class="btn btn-sm btn-primary" data-setup="${p.id}"><i class="ri-add-line me-1"></i>Set up</button>`) : !p.channel ? `<button type="button" class="btn btn-sm btn-primary" data-setup="${p.id}"><i class="ri-add-line me-1"></i>Set up</button>` : p.level === "diocese" ? "" : `<button type="button" class="btn btn-sm ${p.channel.status === "active" ? "btn-outline-warning" : "btn-success"}" data-toggle="${p.channel.id}" data-to="${p.channel.status === "active" ? "off" : "active"}">${p.channel.status === "active" ? "Switch off" : "Switch on"}</button>`}</td>
  </tr>`;

  function places() {
    kit?.destroy();
    kit = K.listTable({
      tableId: "gwTable", stripId: "gwFilters", pillsId: "gwPills", rowsId: "gwRows", items: data.places, rowHtml, noun: "places", defaultPill: "all",
      searchPlaceholder: "Search a church...",
      pills: [
        { key: "active", label: "On", icon: "ri-checkbox-circle-line", color: "success", test: (p) => p.channel?.status === "active" },
        { key: "off", label: "Off", icon: "ri-pause-circle-line", color: "warning", test: (p) => p.channel?.status === "off" },
        { key: "none", label: "Not set up", icon: "ri-add-circle-line", color: "secondary", test: (p) => !p.channel },
        { key: "ownmpesa", label: "Own M-Pesa", icon: "ri-smartphone-line", color: "purple", test: (p) => p.mpesa.some((m) => m.status === "active") },
      ],
      sorts: [{ key: "name", label: "By name", order: [[1, "asc"]] }],
      actions: [],
    });
    $("gwPayouts").innerHTML = data.settlements.length
      ? data.settlements.map((s) => `<tr><td><div class="fw-semibold">${esc(s.place || "")}</div><div class="acc-sub">${A.day(s.settled_on)}</div></td><td class="text-end"><strong>${A.money(s.amount)}</strong></td></tr>`).join("")
      : `<tr><td>${A.empty("ri-exchange-funds-line", "No payouts recorded yet", "Paystack's payouts are recorded here each morning.")}</td></tr>`;
  }

  async function setupWindow(place) {
    const res = await API.gatewayBanks(place.id);
    if (!res.ok) return Toast.error(res.message);
    const d = res.data;
    const isDiocese = place.level === "diocese";
    if (!d.accounts.length) return Toast.error(`${place.name} has no bank account in its books yet - add one under Cash & bank first.`);
    const el = K.confirmWindow({
      title: isDiocese ? "Where the diocese's payouts land" : `Paystack for ${place.name}`,
      subtitle: isDiocese ? "Paystack pays the diocese's share and held gifts into this account" : "A subaccount on Paystack - card gifts settle straight to the church's bank",
      icon: "ri-bank-card-line",
      go: '<i class="ri-check-line me-1"></i>' + (isDiocese ? "Save" : "Make the subaccount"),
      body: K.parts([
        ...(isDiocese ? [] : [{ icon: "ri-bank-line", title: "The church's bank (as Paystack pays it)", hint: "Paystack can't look up a Kenyan account name - type it as the bank has it", body: `<div class="row g-2"><div class="col-12"><select class="form-select" id="chBank"><option value="">Pick the bank</option>${d.banks.map((b) => `<option value="${esc(b.code)}">${esc(b.name)}</option>`).join("")}</select>${d.banks.length ? "" : '<div class="acc-sub mt-1 text-danger">Paystack\'s bank list isn\'t available - check the keys.</div>'}</div><div class="col-sm-6"><input type="text" inputmode="numeric" class="form-control" id="chNumber" placeholder="Account number"></div><div class="col-sm-6"><input type="text" class="form-control" id="chName" maxlength="150" placeholder="Account name"></div></div>` }]),
        { icon: "ri-book-2-line", title: "Recorded into", hint: "Which of its bank accounts in the books", body: `<select class="form-select" id="chInto">${d.accounts.map((a) => `<option value="${a.id}">${esc(a.code)} · ${esc(a.name)}${a.account_number ? ` (${esc(a.account_number)})` : ""}</option>`).join("")}</select>` },
      ]),
      run: async () => {
        const out = await API.saveChannel({ territory_id: place.id, bank_code: el.querySelector("#chBank")?.value || null, account_number: el.querySelector("#chNumber")?.value.trim() || null, account_name: el.querySelector("#chName")?.value.trim() || null, settles_into_id: Number(el.querySelector("#chInto").value) });
        if (out.ok) load();
        return out;
      },
    });
    ["#chBank", "#chInto"].forEach((s) => el.querySelector(s) && UI.enhanceSelect(el.querySelector(s), { search: true }));
  }

  /** A church's own paybill or till: PayHero or its own Daraja app (A10b). */
  async function mpesaWindow(place, channel) {
    const res = await API.gatewayBanks(place.id);
    if (!res.ok) return Toast.error(res.message);
    const accounts = res.data.mpesa_accounts;
    const taken = place.mpesa.map((m) => m.provider);
    let provider = channel?.provider || (taken.includes("payhero") ? "daraja" : "payhero");
    const saved = (k) => (channel?.keys?.[k] ? "Saved - leave blank to keep" : "");
    const tile = (k, t, sub, icon, color) => `<label class="acc-tile" style="--q: var(--${color}-rgb)"><input type="radio" name="mpProvider" value="${k}"${provider === k ? " checked" : ""}${channel || taken.includes(k) ? " disabled" : ""}><span class="acc-tile-icon"><i class="${icon}"></i></span><span class="acc-tile-text"><strong>${t}</strong><small>${sub}</small></span></label>`;
    const keys = {
      payhero: `<div class="row g-2"><div class="col-sm-6"><input type="text" class="form-control" id="mpUser" autocomplete="off" placeholder="${saved("username") || "API username"}"></div><div class="col-sm-6"><input type="password" class="form-control" id="mpPass" autocomplete="new-password" placeholder="${saved("password") || "API password"}"></div><div class="col-sm-6"><input type="text" inputmode="numeric" class="form-control" id="mpChannel" placeholder="${saved("channel_id") || "Payment channel id, e.g. 911"}"></div></div><div class="acc-sub mt-2">In PayHero: API Keys for the username and password; Payment Channels for the id of the church's paybill or till.</div>`,
      daraja: `<div class="row g-2"><div class="col-sm-6"><select class="form-select" id="mpEnv"><option value="sandbox"${channel?.environment === "production" ? "" : " selected"}>Sandbox (testing)</option><option value="production"${channel?.environment === "production" ? " selected" : ""}>Live</option></select></div><div class="col-sm-6"><input type="text" class="form-control" id="mpCk" autocomplete="off" placeholder="${saved("consumer_key") || "Consumer key"}"></div><div class="col-sm-6"><input type="password" class="form-control" id="mpCs" autocomplete="new-password" placeholder="${saved("consumer_secret") || "Consumer secret"}"></div><div class="col-sm-6"><input type="password" class="form-control" id="mpPk" autocomplete="new-password" placeholder="${saved("passkey") || "Lipa na M-Pesa passkey"}"></div></div><div class="acc-sub mt-2">From the church's app on developer.safaricom.co.ke. After saving, press Register so Safaricom tells us about each payment.</div>`,
    };
    const el = K.confirmWindow({
      title: channel ? `${channel.provider_label} for ${place.name}` : `Own M-Pesa for ${place.name}`,
      subtitle: "M-Pesa gifts on the giving page go straight to the church's own paybill or till, into its own books",
      icon: "ri-smartphone-line",
      go: '<i class="ri-check-line me-1"></i>' + (channel ? "Save" : "Save it (off until switched on)"),
      body: K.parts([
        { icon: "ri-links-line", title: "How it connects", body: `<div class="acc-tiles">${tile("payhero", "PayHero", "Any paybill or till, bank paybills too", "ri-flashlight-line", "purple")}${tile("daraja", "Its own Daraja app", "A Safaricom paybill with a developer app", "ri-smartphone-line", "success")}</div>` },
        { icon: "ri-hashtag", title: "The paybill or till", body: `<div class="row g-2 align-items-center"><div class="col-sm-6"><input type="text" inputmode="numeric" class="form-control" id="mpNumber" value="${esc(channel?.number || "")}" placeholder="e.g. 4123456"></div><div class="col-sm-6"><div class="btn-group w-100" role="group"><input type="radio" class="btn-check" name="mpKind" id="mpKindP" value="0"${channel?.till ? "" : " checked"}><label class="btn btn-outline-primary" for="mpKindP">Paybill</label><input type="radio" class="btn-check" name="mpKind" id="mpKindT" value="1"${channel?.till ? " checked" : ""}><label class="btn btn-outline-primary" for="mpKindT">Till</label></div></div></div>` },
        { icon: "ri-key-2-line", title: "Its keys", hint: "Kept encrypted - never shown again", body: `<div id="mpKeys">${keys[provider]}</div>` },
        { icon: "ri-book-2-line", title: "Recorded into", hint: "Which M-Pesa account in the church's books", body: `<select class="form-select" id="mpInto">${channel ? "" : '<option value="">A new M-Pesa account for it</option>'}${accounts.map((a) => `<option value="${a.id}"${channel?.settles_into?.id === a.id ? " selected" : ""}>${esc(a.code)} · ${esc(a.name)}${a.number ? ` (${esc(a.number)})` : ""}</option>`).join("")}</select>` },
      ]),
      run: async () => {
        const v = (id) => el.querySelector(id)?.value.trim() || null;
        const body = { number: v("#mpNumber"), till: el.querySelector('input[name="mpKind"]:checked').value === "1", settles_into_id: Number(v("#mpInto")) || null };
        Object.assign(body, provider === "payhero" ? { username: v("#mpUser"), password: v("#mpPass"), channel_id: v("#mpChannel") ? Number(v("#mpChannel")) : null } : { environment: v("#mpEnv"), consumer_key: v("#mpCk"), consumer_secret: v("#mpCs"), passkey: v("#mpPk") });
        const out = channel ? await API.updateChannel(channel.id, body) : await API.saveChannel({ ...body, provider, territory_id: place.id });
        if (out.ok) load();
        return out;
      },
    });
    el.querySelectorAll('input[name="mpProvider"]').forEach((r) => r.addEventListener("change", () => {
      provider = r.value;
      el.querySelector("#mpKeys").innerHTML = keys[provider];
      el.querySelector("#mpEnv") && UI.enhanceSelect(el.querySelector("#mpEnv"));
    }));
    ["#mpInto", "#mpEnv"].forEach((s) => el.querySelector(s) && UI.enhanceSelect(el.querySelector(s)));
  }

  // ------------------------------------------------------------ requests and payouts (A10c)

  const PERIODS = { month: "This month", year: "This year", last: "Last year" };
  const range = () => {
    const d = new Date();
    const iso = (x) => x.toISOString().slice(0, 10);
    if (period === "month") return [iso(new Date(d.getFullYear(), d.getMonth(), 1, 12)), iso(d)];
    if (period === "last") return [`${d.getFullYear() - 1}-01-01`, `${d.getFullYear() - 1}-12-31`];
    return [`${d.getFullYear()}-01-01`, iso(d)];
  };
  const ROUTE = { own: ["Own Paystack", "success"], diocese: ["Through the diocese", "secondary"], main: ["Main account", "primary"] };

  function requests() {
    $("gwRequestsFigure").textContent = po.requests.length ? `${po.requests.length} to check` : "Nothing waiting";
    $("gwRequestRows").innerHTML = po.requests.length
      ? po.requests.map((r) => `<tr><td><div class="fw-semibold">${esc(r.place.name)}</div><div class="acc-sub">${esc(r.place.code || "")}${r.change ? ' · <span class="soft-chip soft-primary">A change</span>' : ""}</div></td>
          <td><div class="fw-semibold">${esc(r.bank)} · ${esc(r.account_number)}</div><div class="acc-sub">${esc(r.account_name)} · into ${esc(r.into?.name || "-")}</div></td>
          <td class="d-none d-md-table-cell">${A.day(r.on)}<div class="acc-sub">${esc(r.by || "")}</div></td>
          <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-primary" data-review="${r.id}"><i class="ri-search-eye-line me-1"></i>Check</button></td></tr>`).join("")
      : `<tr><td colspan="4">${A.empty("ri-inbox-archive-line", "Nothing to check", "When a church asks for its own Paystack on its Online giving page, it waits here.")}</td></tr>`;
  }

  const poRow = (p) => `<tr data-id="${p.id}" data-pills="${p.route} ${p.failed ? "failed" : ""}" class="acc-row">
    ${K.checkCell(p.id, p.name)}
    <td data-search="${esc(`${p.name} ${p.code}`)}" data-order="${esc(p.name)}"><div class="fw-semibold">${esc(p.name)}</div><div class="acc-sub">${esc(p.code)} · ${esc(p.level)}</div></td>
    <td><span class="soft-chip soft-${ROUTE[p.route][1]}">${ROUTE[p.route][0]}</span>${p.waiting ? ' <span class="badge bg-warning text-dark">Asked</span>' : ""}${p.failed ? ` <span class="badge bg-danger">${p.failed} failed</span>` : ""}</td>
    <td class="d-none d-md-table-cell" data-order="${p.payouts}">${p.payouts || '<span class="acc-sub">-</span>'}</td>
    <td class="d-none d-lg-table-cell" data-order="${p.share}">${p.share ? A.money(p.share) : '<span class="acc-sub">-</span>'}</td>
    <td class="d-none d-lg-table-cell" data-order="${p.last?.date || ""}">${p.last ? `${A.day(p.last.date)}<div class="acc-sub">${A.money(p.last.amount)}</div>` : '<span class="acc-sub">-</span>'}</td>
    <td class="d-none d-md-table-cell" data-order="${p.on_the_way}">${p.on_the_way ? A.money(p.on_the_way) : '<span class="acc-sub">-</span>'}</td>
    <td class="text-end" data-order="${p.paid}"><strong>${A.money(p.paid)}</strong></td>
  </tr>`;

  function payouts() {
    const paid = po.places.reduce((t, p) => t + p.paid, 0);
    $("gwPayoutsFigure").textContent = `${A.short(paid)} ${PERIODS[period].toLowerCase()}`;
    $("gwPeriods").innerHTML = Object.entries(PERIODS).map(([k, l]) => `<button type="button" class="btn btn-sm ${k === period ? "btn-primary" : "btn-outline-primary"}" data-period="${k}">${l}</button>`).join("");
    poKit?.destroy();
    poKit = K.listTable({
      tableId: "gwPoTable", stripId: "gwPoFilters", pillsId: "gwPoPills", rowsId: "gwPoRows", items: po.places, rowHtml: poRow, noun: "places", defaultPill: "all",
      searchPlaceholder: "Search a place...",
      pills: [
        { key: "own", label: "Own Paystack", icon: "ri-bank-card-line", color: "success", test: (p) => p.route === "own" },
        { key: "diocese", label: "Through the diocese", icon: "ri-community-line", color: "secondary", test: (p) => p.route === "diocese" },
        { key: "failed", label: "Failed payouts", icon: "ri-error-warning-line", color: "danger", test: (p) => p.failed > 0 },
      ],
      sorts: [{ key: "paid", label: "Most paid", order: [[7, "desc"]] }, { key: "name", label: "By name", order: [[1, "asc"]] }],
      actions: [],
    });
  }

  async function loadPayouts() {
    $("gwPoRows").innerHTML = UI.renderTableLoading(8);
    const [from, to] = range();
    const res = await API.gatewayPayouts(from, to);
    if (!res.ok) {
      $("gwPoRows").innerHTML = `<tr><td colspan="8">${A.errorBox(res.message)}</td></tr>`;
      return;
    }
    po = res.data;
    requests();
    payouts();
  }

  function reviewWindow(r) {
    const el = K.confirmWindow({
      title: `${r.change ? "A change for" : "Own Paystack for"} ${r.place.name}`,
      subtitle: "Check it against the church's bank letter - Paystack can't look up a Kenyan account name",
      icon: "ri-search-eye-line",
      go: '<i class="ri-check-line me-1"></i>Approve',
      body: K.parts([
        { icon: "ri-bank-line", title: "What they asked for", body: `<div class="acc-facts"><div><span>Bank</span><strong>${esc(r.bank)}</strong></div><div><span>Account number</span><strong>${esc(r.account_number)}</strong></div><div><span>Account name</span><strong>${esc(r.account_name)}</strong></div><div><span>Recorded into</span><strong>${esc(r.into?.name || "-")}</strong></div></div><div class="acc-sub mt-2">Asked ${A.day(r.on)}${r.by ? ` by ${esc(r.by)}` : ""}</div>` },
        { icon: "ri-toggle-line", title: "Once approved", body: r.change ? '<p class="mb-0">Paystack\'s subaccount is changed to these details; it stays as it is (on or off).</p>' : '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="rvOn" checked><label class="form-check-label" for="rvOn">Switch it on at once - card gifts then settle to this bank</label></div>' },
        { icon: "ri-reply-line", title: "Or send it back", hint: "The church sees your note", body: `<textarea class="form-control" id="rvNote" rows="2" maxlength="255" placeholder="What needs changing"></textarea><button type="button" class="btn btn-outline-danger btn-sm mt-2" id="rvBack"><i class="ri-reply-line me-1"></i>Send back</button>` },
      ]),
      run: async () => {
        const out = await API.reviewChannel(r.id, { decision: "approve", switch_on: !!el.querySelector("#rvOn")?.checked });
        if (out.ok) (loadPayouts(), load());
        return out;
      },
    });
    el.querySelector("#rvBack").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "...");
      const out = await API.reviewChannel(r.id, { decision: "return", note: el.querySelector("#rvNote").value.trim() || null });
      UI.restoreButton(btn);
      if (!out.ok) return Toast.error(out.message);
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(out.message);
      loadPayouts();
      load();
    });
  }

  function showTab() {
    document.querySelectorAll("#gwTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("gwPlacesPane").hidden = tab !== "places";
    $("gwRequestsPane").hidden = tab !== "requests";
    $("gwPayoutsPane").hidden = tab !== "payouts";
  }

  function setTab(t) {
    tab = t;
    const p = new URLSearchParams(window.location.search);
    tab === "places" ? p.delete("tab") : p.set("tab", tab);
    history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
    showTab();
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("gwRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.gateways();
    if (!res.ok) {
      $("gwRows").innerHTML = `<tr><td colspan="6">${A.errorBox(res.message)}</td></tr>`;
      return;
    }
    data = res.data;
    cards();
    places();
    const ch = data.places.filter((p) => p.level !== "diocese");
    $("gwPlacesFigure").textContent = `${ch.filter((p) => p.channel?.status === "active").length} on their own Paystack`;
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("gwRows").addEventListener("click", async (e) => {
      const g = e.target.closest("[data-gotab]");
      if (g) return setTab(g.dataset.gotab);
      const s = e.target.closest("[data-setup]");
      const t = e.target.closest("[data-toggle]");
      const mNew = e.target.closest("[data-mpesa-new]");
      const mEdit = e.target.closest("[data-mpesa-edit]");
      const reg = e.target.closest("[data-register]");
      const placeOf = (id) => data.places.find((p) => p.id === Number(id));
      if (s) return setupWindow(placeOf(s.dataset.setup));
      if (mNew) return mpesaWindow(placeOf(mNew.dataset.mpesaNew), null);
      if (mEdit) {
        const place = placeOf(mEdit.dataset.place);
        return mpesaWindow(place, place.mpesa.find((m) => m.id === Number(mEdit.dataset.mpesaEdit)));
      }
      if (reg) {
        UI.setButtonLoading(reg, "...");
        const out = await API.registerChannel(Number(reg.dataset.register));
        UI.restoreButton(reg);
        return out.ok ? (Toast.success(out.message), load()) : Toast.error(out.message);
      }
      if (t) {
        UI.setButtonLoading(t, "...");
        const out = await API.updateChannel(Number(t.dataset.toggle), { status: t.dataset.to });
        UI.restoreButton(t);
        out.ok ? (Toast.success(out.message), load()) : Toast.error(out.message);
      }
    });
    const q = new URLSearchParams(window.location.search).get("tab");
    if (["requests", "payouts"].includes(q)) tab = q;
    showTab();
    $("gwTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (b) setTab(b.dataset.tab);
    });
    $("gwRequestRows").addEventListener("click", (e) => {
      const b = e.target.closest("[data-review]");
      if (b) reviewWindow(po.requests.find((r) => r.id === Number(b.dataset.review)));
    });
    $("gwPayoutsPane").addEventListener("click", (e) => {
      const p = e.target.closest("[data-period]");
      if (p) return ((period = p.dataset.period), loadPayouts());
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("#gwPoRows tr[data-id]");
      if (tr) window.location.href = `${CTX.siteUrl}/diocese/accounting/giving.php?territory_id=${tr.dataset.id}&tab=payouts`;
    });
    load();
    loadPayouts();
  });
})();
