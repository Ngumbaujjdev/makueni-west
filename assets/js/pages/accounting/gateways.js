/**
 * ============================================================================
 * ACCOUNTING - Gateways (gateways.php, diocese)
 * ============================================================================
 * Each church's Paystack subaccount (docs/specs/accounting-spec.md, A10a):
 * made on Paystack from the church's bank details, off until switched on,
 * and which of its bank accounts the payouts are recorded into. The diocese
 * sets where its own payouts land. The latest payouts below.
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

  const pill = (c) => (!c ? '<span class="badge bg-secondary">Not set up</span>' : c.status === "active" ? '<span class="badge bg-success"><i class="ri-checkbox-circle-line me-1"></i>On</span>' : '<span class="badge bg-warning text-dark"><i class="ri-pause-circle-line me-1"></i>Off</span>');

  function cards() {
    const ch = data.places.filter((p) => p.level !== "diocese");
    const on = ch.filter((p) => p.channel?.status === "active");
    K.statRow($("statCardsRow"), [
      { icon: "ri-bank-card-line", label: "Paystack", sub: data.ready ? "Keys saved" : "Add the keys in Settings", value: data.ready ? (data.mode === "live" ? "Live" : "Test") : "Off", color: data.ready ? "success" : "danger" },
      { icon: "ri-community-line", label: "Churches on their own Paystack", sub: `${ch.length - on.length} through the diocese`, value: `${on.length}/${ch.length}`, color: "primary" },
      { icon: "ri-time-line", label: "Card gifts waiting", sub: "Checked every 10 minutes", value: A.num(data.gifts_pending), color: "warning" },
      { icon: "ri-exchange-funds-line", label: "Payouts recorded", sub: "The latest 50 below", value: A.num(data.settlements.length), color: "purple" },
    ]);
    $("gwReady").innerHTML = data.ready ? "" : `<div class="alert alert-warning">Paystack isn't set up - add the secret key in <a href="${CTX.siteUrl}/diocese/settings/?section=giving">Settings, Online giving</a>. Until then the giving page offers M-Pesa only.</div>`;
  }

  const rowHtml = (p) => `<tr data-id="${p.id}" data-pills="${p.channel ? p.channel.status : "none"} ${p.level}">
    ${K.checkCell(p.id, p.name)}
    <td data-search="${esc(`${p.name} ${p.code}`)}" data-order="${esc(p.name)}"><div class="fw-semibold">${esc(p.name)}</div><div class="acc-sub">${esc(p.code)} · ${esc(p.level)}</div></td>
    <td class="d-none d-md-table-cell">${p.channel ? `${esc(p.channel.bank || "")} ${esc(p.channel.account_number || "")}<div class="acc-sub">into ${esc(p.channel.settles_into?.name || "-")}</div>` : '<span class="acc-sub">-</span>'}</td>
    <td>${p.level === "diocese" ? (p.channel ? '<span class="badge bg-success">Payouts recorded</span>' : '<span class="badge bg-secondary">Pick its bank account</span>') : pill(p.channel)}${p.channel?.subaccount ? `<div class="acc-sub mt-1">${esc(p.channel.subaccount)}</div>` : ""}</td>
    <td class="text-end text-nowrap">${!p.channel ? `<button type="button" class="btn btn-sm btn-primary" data-setup="${p.id}"><i class="ri-add-line me-1"></i>Set up</button>` : p.level === "diocese" ? "" : `<button type="button" class="btn btn-sm ${p.channel.status === "active" ? "btn-outline-warning" : "btn-success"}" data-toggle="${p.channel.id}" data-to="${p.channel.status === "active" ? "off" : "active"}">${p.channel.status === "active" ? "Switch off" : "Switch on"}</button>`}</td>
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

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("gwRows").innerHTML = UI.renderTableLoading(5);
    const res = await API.gateways();
    if (!res.ok) {
      $("gwRows").innerHTML = `<tr><td colspan="5">${A.errorBox(res.message)}</td></tr>`;
      return;
    }
    data = res.data;
    cards();
    places();
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("gwRows").addEventListener("click", async (e) => {
      const s = e.target.closest("[data-setup]");
      const t = e.target.closest("[data-toggle]");
      if (s) return setupWindow(data.places.find((p) => p.id === Number(s.dataset.setup)));
      if (t) {
        UI.setButtonLoading(t, "...");
        const out = await API.updateChannel(Number(t.dataset.toggle), { status: t.dataset.to });
        UI.restoreButton(t);
        out.ok ? (Toast.success(out.message), load()) : Toast.error(out.message);
      }
    });
    load();
  });
})();
