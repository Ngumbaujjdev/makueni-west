/**
 * ============================================================================
 * ACCOUNTING - Procurement (procurement.php, every level)
 * ============================================================================
 * Bigger purchases the standard way (docs/specs/accounting-spec.md, A5): an
 * approved purchase requisition becomes a local purchase order to the chosen
 * supplier; goods are received against it; the supplier's bill is matched to
 * what was ordered and received, then paid by a voucher already authorised.
 * Tabs: Orders (with the approved purchases waiting to be ordered), Bills to
 * pay, Suppliers.
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
  const qty = (v) => Number(v).toLocaleString("en-GB", { maximumFractionDigits: 2 });
  const params = new URLSearchParams(window.location.search);
  let data = null;
  let kit = null;
  const billCache = {};
  let tab = ["bills", "suppliers"].includes(params.get("tab")) ? params.get("tab") : "orders";

  const ST = {
    issued: ["primary", "ri-send-plane-line", "Ordered"],
    part_received: ["warning", "ri-truck-line", "Part received"],
    received: ["success", "ri-checkbox-circle-line", "Received"],
    closed: ["secondary", "ri-lock-line", "Closed"],
    cancelled: ["danger", "ri-close-circle-line", "Cancelled"],
  };
  const BILL = { posted: ["warning", "ri-time-line", "To pay"], paid: ["success", "ri-checkbox-circle-line", "Paid"], reversed: ["secondary", "ri-arrow-go-back-line", "Reversed"] };
  const pill = (map, s) => `<span class="badge bg-${map[s][0]} ${A.textOn(map[s][0])}"><i class="${map[s][1]} me-1"></i>${map[s][2]}</span>`;
  const sum = (arr, f = (x) => x.amount) => arr.reduce((t, x) => t + f(x), 0);

  // ------------------------------------------------------------ the page

  function cards() {
    const open = data.orders.filter((o) => ["issued", "part_received"].includes(o.status));
    const owed = data.bills.filter((b) => b.status === "posted");
    const ym = new Date().toISOString().slice(0, 7);
    const month = data.orders.filter((o) => o.status !== "cancelled" && o.date.startsWith(ym));
    K.statRow($("statCardsRow"), [
      { icon: "ri-shield-check-line", label: "Approved - to order", sub: A.money(sum(data.to_order)), value: A.num(data.to_order.length), color: "primary" },
      { icon: "ri-truck-line", label: "Goods to come", sub: open.some((o) => o.late) ? `${open.filter((o) => o.late).length} late` : "None late", value: A.num(open.length), color: "warning" },
      { icon: "ri-file-list-2-line", label: "Owed to suppliers", sub: owed.some((b) => b.overdue) ? `${owed.filter((b) => b.overdue).length} overdue` : `${owed.length} bills`, value: A.figure(sum(owed)), color: "danger" },
      { icon: "ri-shopping-cart-2-line", label: "Ordered this month", sub: `${month.length} orders`, value: A.figure(sum(month)), color: "success" },
    ]);
    $("prOrdersFigure").textContent = `${open.length} open`;
    $("prBillsFigure").textContent = owed.length ? `${A.money(sum(owed))} owed` : "Nothing owed";
    $("prSuppliersFigure").textContent = `${data.suppliers.filter((s) => s.is_active).length} active`;
  }

  function toOrder() {
    $("prToOrderCard").hidden = !data.to_order.length;
    const ready = (r) => !r.must_order || (r.quotes >= data.limits.quotes && r.chosen);
    A.tableKit({
      tableId: "prToOrderTable",
      prefix: "o_",
      items: data.to_order,
      noun: "approved purchases",
      search: "Search what for, number, who asked...",
      pills: [
        { key: "ready", label: "Ready to order", icon: "ri-shopping-cart-2-line", color: "success", test: ready },
        { key: "quotes", label: "Needs quotations", icon: "ri-scales-3-line", color: "warning", test: (r) => !ready(r) },
      ],
      sorts: [
        { key: "big", label: "Largest first", order: [[3, "desc"]] },
        { key: "small", label: "Smallest first", order: [[3, "asc"]] },
      ],
      nonSortable: [4],
      rowHtml: (r) => {
        const ok = ready(r);
        const q = r.must_order ? `${r.quotes} of ${data.limits.quotes}${r.chosen ? " · chosen" : ""}` : r.quotes ? `${r.quotes}${r.chosen ? " · chosen" : ""}` : "Not needed";
        const act = !data.can.procure || A.viewingBelow()
          ? ""
          : ok
            ? `<button type="button" class="btn btn-sm btn-primary" data-raise="${r.id}"><i class="ri-shopping-cart-2-line me-1"></i>Raise the order</button>`
            : `<a class="btn btn-sm btn-outline-primary" href="${A.link("requisitions.php", { requisition: r.id })}"><i class="ri-scales-3-line me-1"></i>Get quotations</a>`;
        return `<tr data-pills="${ok ? "ready" : "quotes"}"><td><div class="fw-semibold">${esc(r.purpose)}</div><div class="acc-sub">${esc(r.number)}${r.must_order ? ` · above KES ${A.num(data.limits.one_quote)}` : ""}</div></td><td class="d-none d-md-table-cell">${esc(r.requested_by || "")}</td><td class="d-none d-lg-table-cell"><span class="soft-chip soft-${ok ? "success" : "warning"}">${esc(q)}</span></td><td class="text-end" data-order="${r.amount}"><strong>${A.money(r.amount)}</strong></td><td class="text-end">${act}</td></tr>`;
      },
    });
  }

  const rowHtml = (o) => `<tr class="acc-row" data-id="${o.id}" data-pills="${o.status}${o.to_receive || o.to_bill ? " open" : ""}${o.to_receive ? " receive" : ""}${o.to_bill ? " bill" : ""}${["received", "closed"].includes(o.status) ? " received" : ""}${o.late ? " late" : ""}">
    ${K.checkCell(o.id, o.number)}
    <td data-search="${esc(`${o.number} ${o.supplier || ""} ${o.requisition?.purpose || ""} ${o.requisition?.number || ""}`)}" data-order="${esc(o.number)}"><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${ST[o.status][0]} ${A.textOn(ST[o.status][0])}"><i class="${ST[o.status][1]}"></i></span><div class="min-w-0"><div class="fw-semibold">${esc(o.requisition?.purpose || o.number)}</div><div class="acc-sub">${esc(o.number)} · ${o.items} ${o.items === 1 ? "item" : "items"}</div></div></div></td>
    <td data-order="${o.date}" class="text-nowrap">${A.day(o.date)}</td>
    <td class="d-none d-md-table-cell">${esc(o.supplier || "")}</td>
    <td class="d-none d-lg-table-cell acc-steps-cell">${A.mini(["Ordered", "Received", "Billed"], o.status === "cancelled" ? 1 : o.to_receive ? 1 : o.to_bill ? 2 : 3, { stop: o.status === "cancelled" ? "Cancelled" : null })}${o.late ? ' <span class="badge bg-danger">Late</span>' : ""}<div class="acc-sub mt-1">${o.to_receive ? (o.deliver_by ? `Due by ${A.day(o.deliver_by)}` : "Goods to come") : o.to_bill ? "Bill to enter" : o.status === "cancelled" ? "" : "Billed"}</div></td>
    <td class="text-end" data-order="${o.amount}"><strong>${A.money(o.amount)}</strong><div class="mt-1">${A.pdfButton("accounting.lpo", { record_id: o.id }, `LPO ${o.number}`, "LPO")}</div></td>
  </tr>`;

  function orders() {
    kit?.destroy();
    if (!data.orders.length) {
      $("prPills").innerHTML = "";
      $("prFilters").innerHTML = "";
      $("prRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-shopping-cart-2-line", "No orders yet", `An approved purchase is ordered from here. Up to KES ${A.num(data.limits.one_quote)} it can be paid straight away; above it, ${data.limits.quotes} quotations and an order.`)}</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "prTable",
      stripId: "prFilters",
      pillsId: "prPills",
      rowsId: "prRows",
      items: data.orders,
      rowHtml,
      noun: "orders",
      defaultPill: data.orders.some((o) => o.to_receive || o.to_bill) ? "open" : "all",
      searchPlaceholder: "Search order, supplier, what for...",
      pills: [
        { key: "open", label: "To act on", icon: "ri-flashlight-line", color: "primary", test: (o) => o.to_receive || o.to_bill },
        { key: "receive", label: "Goods to come", icon: ST.part_received[1], color: "warning", test: (o) => o.to_receive },
        { key: "bill", label: "Bill to enter", icon: "ri-file-list-2-line", color: "purple", test: (o) => o.to_bill },
        { key: "received", label: "Received", icon: ST.received[1], color: "success", test: (o) => o.status === "received" || o.status === "closed" },
        { key: "cancelled", label: "Cancelled", icon: ST.cancelled[1], color: "danger", test: (o) => o.status === "cancelled" },
      ],
      sorts: [
        { key: "new", label: "Newest first", order: [[2, "desc"]] },
        { key: "big", label: "Largest first", order: [[5, "desc"]] },
      ],
      actions: [],
    });
  }

  const billRow = (b, withOrder = true) => `<tr data-pills="${b.status}${b.overdue ? " overdue" : ""}"><td data-order="${esc(b.supplier || "")}"><div class="fw-semibold">${esc(b.supplier || "")}</div><div class="acc-sub">${esc(b.number)} · their invoice ${esc(b.supplier_ref)}${b.file ? ` · <button type="button" class="btn btn-link p-0 acc-sub" data-billfile="${b.id}"><i class="ri-attachment-2"></i> invoice</button>` : ""}</div></td>
    <td class="d-none d-md-table-cell text-nowrap" data-order="${b.date}">${A.day(b.date)}${b.due_on ? `<div class="acc-sub">due ${A.day(b.due_on)}</div>` : ""}</td>
    ${withOrder ? `<td class="d-none d-lg-table-cell">${b.order ? `<button type="button" class="btn btn-link p-0" data-openorder="${b.order.id}">${esc(b.order.number)}</button>` : ""}</td>` : ""}
    <td>${pill(BILL, b.status)}${b.overdue ? ' <span class="badge bg-danger">Overdue</span>' : ""}${b.voucher ? `<div class="acc-sub mt-1">Voucher ${esc(b.voucher.number)}</div>` : ""}</td>
    <td class="text-end" data-order="${b.amount}"><strong>${A.money(b.amount)}</strong></td>
    <td class="text-end text-nowrap">${A.viewingBelow() ? "" : `${b.can.pay ? `<button type="button" class="btn btn-sm btn-primary" data-paybill="${b.id}"><i class="ri-hand-coin-line me-1"></i>Pay</button>` : ""}${b.voucher ? `<a class="btn btn-sm btn-outline-primary" href="${A.link("payments.php", { voucher: b.voucher.id })}">Voucher</a>` : ""}${b.can.reverse ? ` <button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-reversebill="${b.id}" aria-label="Reverse"><i class="ri-arrow-go-back-line"></i></button>` : ""}`}</td></tr>`;

  function bills() {
    A.tableKit({
      tableId: "prBillTable",
      prefix: "b_",
      items: data.bills,
      noun: "bills",
      search: "Search supplier, bill, their invoice...",
      pills: [
        { key: "posted", label: "To pay", icon: "ri-hand-coin-line", color: "warning", test: (b) => b.status === "posted" },
        { key: "overdue", label: "Overdue", icon: "ri-alarm-warning-line", color: "danger", test: (b) => b.overdue },
        { key: "paid", label: "Paid", icon: "ri-checkbox-circle-line", color: "success", test: (b) => b.status === "paid" },
      ],
      sorts: [
        { key: "new", label: "Newest first", order: [[1, "desc"]] },
        { key: "old", label: "Oldest first", order: [[1, "asc"]] },
        { key: "big", label: "Largest first", order: [[4, "desc"]] },
      ],
      nonSortable: [5],
      empty: A.empty("ri-file-list-2-line", "No bills yet", "The supplier's invoice is entered from its order once the goods have come."),
      rowHtml: (b) => billRow(b),
    });
  }

  function suppliers() {
    A.tableKit({
      tableId: "prSupplierTable",
      prefix: "s_",
      items: data.suppliers,
      noun: "suppliers",
      search: "Search name, phone, KRA PIN...",
      pills: [
        { key: "owed", label: "We owe them", icon: "ri-error-warning-line", color: "danger", test: (s) => s.owed > 0 },
        { key: "on", label: "In use", icon: "ri-store-2-line", color: "success", test: (s) => s.is_active },
        { key: "off", label: "Off", icon: "ri-forbid-line", color: "secondary", test: (s) => !s.is_active },
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[0, "asc"]] },
        { key: "ordered", label: "Most ordered", order: [[3, "desc"]] },
        { key: "owed", label: "Most owed", order: [[4, "desc"]] },
      ],
      nonSortable: [5],
      empty: A.empty("ri-store-2-line", "No suppliers yet", "Add the shops and companies you buy from - they are picked on quotations and orders.", data.can.procure && !A.viewingBelow() ? '<button type="button" class="btn btn-primary" data-firstsupplier><i class="ri-store-2-line me-1"></i>Add a supplier</button>' : ""),
      rowHtml: (s) => `<tr data-pills="${s.owed > 0 ? "owed " : ""}${s.is_active ? "on" : "off"}"><td data-order="${esc(s.name)}"><div class="fw-semibold">${esc(s.name)}${s.is_active ? "" : ' <span class="badge bg-secondary">Off</span>'}</div><div class="acc-sub">${esc(s.pay_details || "")}</div></td><td class="d-none d-md-table-cell">${esc(s.phone || "")}<div class="acc-sub">${esc(s.email || "")}</div></td><td class="d-none d-lg-table-cell">${esc(s.kra_pin || "-")}</td><td class="text-end" data-order="${s.ordered}">${A.money(s.ordered)}<div class="acc-sub">${s.orders} ${s.orders === 1 ? "order" : "orders"}</div></td><td class="text-end" data-order="${s.owed}"><strong class="${s.owed > 0 ? "text-danger" : ""}">${A.money(s.owed)}</strong></td><td class="text-end text-nowrap">${data.can.procure && !A.viewingBelow() ? `<button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-editsupplier="${s.id}" aria-label="Change"><i class="ri-edit-line"></i></button> <button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-removesupplier="${s.id}" aria-label="Remove"><i class="ri-delete-bin-line"></i></button>` : ""}</td></tr>`,
    });
  }


  function showTab() {
    document.querySelectorAll("#prTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("prOrdersPane").hidden = tab !== "orders";
    $("prBillsPane").hidden = tab !== "bills";
    $("prSuppliersPane").hidden = tab !== "suppliers";
  }

  async function load() {
    A.ownOnly();
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("prRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.procurement();
    if (!res.ok) {
      $("prTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    toOrder();
    orders();
    bills();
    suppliers();
    showTab();
  }

  // ------------------------------------------------------------ raise the order

  async function raiseWindow(reqId) {
    const [rr, oo] = await Promise.all([API.requisition(reqId), API.procurementOptions()]);
    if (!rr.ok) return Toast.error(rr.message);
    if (!oo.ok) return Toast.error(oo.message);
    const r = rr.data;
    const o = oo.data;
    const p = r.procurement;
    if (!p?.can.order) return Toast.error("This requisition can't be ordered now.");
    const chosen = p.quotes.find((q) => q.chosen);
    const supplierPart = p.must_order
      ? `<div class="acc-facts"><div><span>Chosen quotation</span><strong>${esc(chosen?.supplier || "")} · ${A.money(chosen?.amount || 0)}</strong></div>${chosen?.chosen_reason ? `<div><span>Why</span><strong>${esc(chosen.chosen_reason)}</strong></div>` : ""}</div>`
      : `<select class="form-select" id="roSupplier"><option value="">Pick the supplier</option>${o.suppliers.map((s) => `<option value="${s.id}"${chosen?.supplier_id === s.id ? " selected" : ""}>${esc(s.name)}</option>`).join("")}</select>${o.suppliers.length ? "" : '<div class="acc-sub mt-1">No suppliers yet - add one first (Suppliers tab).</div>'}`;
    const el = K.confirmWindow({
      title: `Order for ${r.number}`,
      subtitle: `${r.purpose} - ${A.money(r.amount)} approved`,
      icon: "ri-shopping-cart-2-line",
      go: '<i class="ri-send-plane-line me-1"></i>Raise the order',
      body: K.parts([
        { icon: "ri-store-2-line", title: "Supplier", body: supplierPart },
        { icon: "ri-list-check-2", title: "What is ordered", hint: o.church ? "Tick equipment - it goes into Facilities when it comes" : "Tick equipment - it is booked as a fixed asset", body: `<div id="roLines"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="roAdd"><i class="ri-add-line me-1"></i>Add an item</button><div class="acc-sub mt-2" id="roTotal"></div>` },
        { icon: "ri-calendar-line", title: "Delivery", body: `<div class="row g-2"><div class="col-sm-6"><input type="date" class="form-control" id="roBy" min="${o.today}" aria-label="Deliver by"></div><div class="col-sm-6"><input type="text" class="form-control" id="roNotes" maxlength="500" placeholder="Note for the supplier (optional)"></div></div>` },
      ]),
      run: async () => {
        const lines = [...document.querySelectorAll("#roLines [data-ro]")]
          .map((x) => ({
            description: x.querySelector("[data-desc]").value.trim(),
            quantity: n(x.querySelector("[data-qty]").value),
            unit_price: n(x.querySelector("[data-price]").value),
            is_asset: x.querySelector("[data-asset]").checked,
            account_id: x.querySelector("[data-asset]").checked ? null : Number(x.querySelector("select").value) || null,
          }))
          .filter((l) => l.description || l.quantity || l.unit_price);
        const res = await API.raiseOrder(r.id, { supplier_id: Number(document.getElementById("roSupplier")?.value) || null, deliver_by: document.getElementById("roBy").value || null, notes: document.getElementById("roNotes").value.trim() || null, lines });
        if (res.ok) {
          history.replaceState(null, "", A.link("procurement.php", {}));
          setTimeout(() => {
            load();
            orderWindow(res.data.id);
          }, 300);
        }
        return res;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-xl");
    const box = el.querySelector("#roLines");
    const total = () => {
      const t = [...box.querySelectorAll("[data-ro]")].reduce((s, x) => s + n(x.querySelector("[data-qty]").value) * n(x.querySelector("[data-price]").value), 0);
      el.querySelector("#roTotal").innerHTML = `Order total ${A.money(t)} of ${A.money(r.amount)} approved${t > r.amount ? ' - <span class="text-danger fw-semibold">more than approved</span>' : ""}`;
    };
    const add = (l = {}) => {
      box.insertAdjacentHTML(
        "beforeend",
        `<div class="row g-2 mb-2 align-items-center" data-ro><div class="col-md-4"><input type="text" class="form-control" data-desc maxlength="255" placeholder="Item, e.g. Plastic chairs" value="${esc(l.description || "")}"></div><div class="col-4 col-md-1"><input type="text" inputmode="decimal" class="form-control text-end" data-qty placeholder="Qty" value="${l.quantity || ""}"></div><div class="col-8 col-md-2"><div class="input-group"><span class="input-group-text">@</span><input type="text" inputmode="decimal" class="form-control text-end" data-price placeholder="Price" value="${l.unit_price || ""}"></div></div><div class="col-8 col-md-3"><select class="form-select">${o.accounts.map((a) => `<option value="${a.id}"${a.id === r.account_id ? " selected" : ""}>${esc(a.code)} · ${esc(a.name)}</option>`).join("")}</select></div><div class="col-4 col-md-2"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" data-asset><span class="form-check-label">Equipment</span></label></div></div>`,
      );
      const row = box.lastElementChild;
      UI.enhanceSelect(row.querySelector("select"), { search: true });
      row.querySelectorAll("[data-qty],[data-price]").forEach((x) => x.addEventListener("input", total));
      row.querySelector("[data-asset]").addEventListener("change", (e) => (row.querySelector(".col-md-3").style.visibility = e.target.checked ? "hidden" : ""));
    };
    add({ description: r.purpose, quantity: 1, unit_price: chosen?.amount || r.amount });
    el.querySelector("#roAdd").addEventListener("click", () => add());
    if (el.querySelector("#roSupplier")) UI.enhanceSelect(el.querySelector("#roSupplier"), { search: true });
    if (window.DateField) DateField.enhance(el.querySelector("#roBy"), { quick: ["in3", "in14"] });
    total();
  }

  // ------------------------------------------------------------ one order

  async function orderWindow(id) {
    const res = await API.order(id);
    if (!res.ok) return Toast.error(res.message);
    const o = res.data;
    const c = o.can;
    const own = !A.viewingBelow();
    o.bills.forEach((b) => (billCache[b.id] = b));
    document.getElementById("prWindow")?.remove();
    const lines = `<div class="table-responsive"><table class="table table-sm mb-0 acc-table"><thead><tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Price</th><th class="text-end">Received</th><th class="text-end">Billed</th></tr></thead><tbody>${o.lines
      .map((l) => `<tr><td><div class="fw-semibold">${esc(l.description)}${l.is_asset ? ' <span class="soft-chip soft-purple ms-1">Equipment</span>' : ""}</div><div class="acc-sub">${esc(l.account || "")}</div></td><td class="text-end">${qty(l.quantity)}</td><td class="text-end">${A.amount(l.unit_price)}</td><td class="text-end ${l.received >= l.quantity ? "text-success fw-semibold" : ""}">${qty(l.received)}</td><td class="text-end">${qty(l.billed)}</td></tr>`)
      .join("")}<tr><td colspan="2" class="fw-semibold">Total</td><td class="text-end fw-semibold" colspan="3">${A.money(o.amount)}</td></tr></tbody></table></div>`;
    const deliveries = o.deliveries.length
      ? o.deliveries
          .map(
            (g) => `<div class="acc-quote${g.status === "undone" ? " opacity-50" : ""}"><div class="min-w-0 flex-fill"><div class="fw-semibold">${esc(g.number)} · ${A.day(g.date)}${g.status === "undone" ? ' <span class="badge bg-secondary">Undone</span>' : ""}</div><div class="acc-sub">${g.lines.map((x) => `${qty(x.quantity)} × ${esc(x.description || "")}${x.equipment ? " (to equipment)" : ""}`).join(" · ")}${g.by ? ` · by ${esc(g.by)}` : ""}</div></div><div class="d-flex gap-1">${g.files.map((f) => `<button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-gfile="${g.id}:${f.id}" aria-label="${esc(f.name)}"><i class="ri-attachment-2"></i></button>`).join("")}${g.can_undo && own ? `<button type="button" class="btn btn-sm btn-outline-danger" data-undo="${g.id}">Undo</button>` : ""}</div></div>`,
          )
          .join("")
      : '<p class="acc-muted-line mb-0">Nothing received yet.</p>';
    const billsHtml = o.bills.length ? `<div class="table-responsive"><table class="table table-sm mb-0 acc-table"><tbody>${o.bills.map((b) => billRow(b, false)).join("")}</tbody></table></div>` : '<p class="acc-muted-line mb-0">No bill yet - enter it when the supplier\'s invoice comes.</p>';
    const btn = (k, cls, icon, label) => `<button type="button" class="btn ${cls}" data-act="${k}"><i class="${icon} me-1"></i>${label}</button>`;
    const foot = [
      own && c.cancel ? btn("cancel", "btn-outline-danger me-auto", "ri-close-circle-line", "Cancel the order") : "",
      own && c.close ? btn("close", "btn-outline-secondary me-auto", "ri-lock-line", "Close - no more coming") : "",
      btn("print", "btn-outline-primary", "ri-file-pdf-line", "LPO (PDF)"),
      own && c.bill ? btn("bill", "btn-outline-primary", "ri-file-list-2-line", "Enter the bill") : "",
      own && c.receive ? btn("receive", "btn-primary", "ri-truck-line", "Goods received") : "",
      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>',
    ].join("");
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="prWindow" tabindex="-1" aria-labelledby="prTitle"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-shopping-cart-2-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="prTitle">${esc(o.number)} · ${esc(o.supplier || "")}</h5><div class="app-modal-subtitle">${A.money(o.amount)} · ${esc(o.status_label)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          ${o.end_reason ? `<div class="alert alert-${o.status === "cancelled" ? "danger" : "secondary"} mb-3"><strong>${o.status === "cancelled" ? "Cancelled" : "Closed"}:</strong> ${esc(o.end_reason)}</div>` : ""}
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-file-text-line"></i>The order</div><div class="acc-facts"><div><span>For</span><strong>${o.requisition ? `<a href="${A.link("requisitions.php", { requisition: o.requisition.id })}">${esc(o.requisition.number)}</a> · ${esc(o.requisition.purpose)}` : ""}</strong></div><div><span>Ordered</span><strong>${A.day(o.date)}${o.issued_by ? ` by ${esc(o.issued_by)}` : ""}</strong></div>${o.deliver_by ? `<div><span>Deliver by</span><strong class="${o.late ? "text-danger" : ""}">${A.day(o.deliver_by)}</strong></div>` : ""}${o.supplier_info?.phone ? `<div><span>Supplier phone</span><strong>${esc(o.supplier_info.phone)}</strong></div>` : ""}${o.notes ? `<div><span>Note</span><strong>${esc(o.notes)}</strong></div>` : ""}</div></section>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-list-check-2"></i>Items</div>${lines}</section>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-truck-line"></i>Goods received</div><div class="acc-quotes">${deliveries}</div></section>
          <section class="app-modal-part mb-0"><div class="app-modal-part-head"><i class="ri-file-list-2-line"></i>Bills</div>${billsHtml}</section>
        </div>
        <div class="modal-footer" id="prFoot">${foot}</div>
      </div></div></div>`,
    );
    const el = document.getElementById("prWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    const again = () => {
      bootstrap.Modal.getInstance(el)?.hide();
      load();
      setTimeout(() => orderWindow(o.id), 350);
    };
    el.addEventListener("click", async (e) => {
      const act = e.target.closest("[data-act]")?.dataset.act;
      const gfile = e.target.closest("[data-gfile]")?.dataset.gfile;
      const undo = e.target.closest("[data-undo]")?.dataset.undo;
      if (gfile) {
        const [g, m] = gfile.split(":").map(Number);
        return openFile(() => API.deliveryFileUrl(g, m));
      }
      if (undo) {
        if (!confirm("Undo this delivery? Any equipment it added goes too.")) return;
        const out = await API.undoDelivery(Number(undo));
        if (!out.ok) return Toast.error(out.message);
        Toast.success(out.message);
        return again();
      }
      if (act === "print") return printOrder(o);
      if (act === "receive") return receiveWindow(o, again);
      if (act === "bill") return billWindow(o, again);
      if (act === "cancel" || act === "close") {
        return K.confirmWindow({
          title: act === "cancel" ? `Cancel ${o.number}` : `Close ${o.number}`,
          subtitle: act === "cancel" ? "Nothing has come on it - the requisition can be ordered again" : "Part of it came - nothing more is expected",
          icon: act === "cancel" ? "ri-close-circle-line" : "ri-lock-line",
          danger: act === "cancel",
          go: act === "cancel" ? "Cancel the order" : "Close it",
          body: K.parts([{ icon: "ri-chat-3-line", title: "Why?", body: '<textarea class="form-control" id="prWhy" rows="2" maxlength="255"></textarea>' }]),
          run: async () => {
            const why = document.getElementById("prWhy").value.trim();
            const out = act === "cancel" ? await API.cancelOrder(o.id, why) : await API.closeOrder(o.id, why);
            if (out.ok) again();
            return out;
          },
        });
      }
    });
    wireBillButtons(el, again);
  }

  async function openFile(get) {
    const w = window.open("", "_blank");
    const u = await get();
    u ? (w.location = u) : (w.close(), Toast.error("That file could not be opened."));
  }

  function receiveWindow(o, done) {
    const today = new Date().toISOString().slice(0, 10);
    const rows = o.lines
      .filter((l) => l.to_receive > 0)
      .map((l) => `<div class="row g-2 mb-2 align-items-center" data-gr="${l.id}"><div class="col-7"><div class="fw-semibold">${esc(l.description)}${l.is_asset ? ' <span class="soft-chip soft-purple ms-1">Equipment</span>' : ""}</div><div class="acc-sub">${qty(l.to_receive)} still to come of ${qty(l.quantity)}</div></div><div class="col-5"><input type="text" inputmode="decimal" class="form-control text-end" value="${l.to_receive}" aria-label="How many came"></div></div>`)
      .join("");
    const el = K.confirmWindow({
      title: `Goods received on ${o.number}`,
      subtitle: `From ${o.supplier || ""} - count what actually came`,
      icon: "ri-truck-line",
      go: '<i class="ri-check-line me-1"></i>Record it',
      body: K.parts([
        { icon: "ri-calendar-line", title: "Date", body: `<input type="date" class="form-control" id="grDate" value="${today}" max="${today}" min="${o.date}">` },
        { icon: "ri-list-check-2", title: "How many came", hint: "0 for what didn't", body: rows },
        { icon: "ri-attachment-2", title: "Delivery note or photo", hint: "Optional", body: `<label class="budget-receipt-pick mb-2"><i class="ri-attachment-2"></i><span id="grFileName">Attach (up to 3)</span><input type="file" id="grFiles" accept="image/jpeg,image/png,image/webp,application/pdf" multiple hidden></label><input type="text" class="form-control" id="grNotes" maxlength="255" placeholder="Note (optional), e.g. 2 chairs cracked - returned">` },
      ]),
      run: async () => {
        const lines = [...document.querySelectorAll("[data-gr]")].map((x) => ({ line_id: Number(x.dataset.gr), quantity: n(x.querySelector("input").value) }));
        const out = await API.receiveGoods(o.id, { date: document.getElementById("grDate").value, notes: document.getElementById("grNotes").value.trim() || null, lines }, [...document.getElementById("grFiles").files].slice(0, 3));
        if (out.ok) done();
        return out;
      },
    });
    el.querySelector("#grFiles").addEventListener("change", (e) => (el.querySelector("#grFileName").textContent = [...e.target.files].map((f) => f.name).join(", ") || "Attach (up to 3)"));
    if (window.DateField) DateField.enhance(el.querySelector("#grDate"), { quick: ["today", "yesterday"] });
  }

  function billWindow(o, done) {
    const today = new Date().toISOString().slice(0, 10);
    const rows = o.lines
      .filter((l) => l.to_bill > 0)
      .map((l) => `<div class="row g-2 mb-2 align-items-center" data-bl="${l.id}" data-max="${l.unit_price}"><div class="col-md-6"><div class="fw-semibold">${esc(l.description)}</div><div class="acc-sub">${qty(l.to_bill)} received, not billed · ordered at ${A.amount(l.unit_price)}</div></div><div class="col-5 col-md-2"><input type="text" inputmode="decimal" class="form-control text-end" data-qty value="${l.to_bill}" aria-label="Quantity billed"></div><div class="col-7 col-md-4"><div class="input-group"><span class="input-group-text">@</span><input type="text" inputmode="decimal" class="form-control text-end" data-price value="${l.unit_price}" aria-label="Price billed"></div></div></div>`)
      .join("");
    const el = K.confirmWindow({
      title: `The bill for ${o.number}`,
      subtitle: `${o.supplier || ""}'s invoice - matched to what was ordered and received`,
      icon: "ri-file-list-2-line",
      go: '<i class="ri-check-line me-1"></i>Post the bill',
      body: K.parts([
        { icon: "ri-hashtag", title: "Their invoice", body: `<div class="row g-2"><div class="col-sm-4"><input type="text" class="form-control" id="biRef" maxlength="60" placeholder="Invoice number"></div><div class="col-sm-4"><input type="date" class="form-control" id="biDate" value="${today}" max="${today}" aria-label="Invoice date"></div><div class="col-sm-4"><input type="date" class="form-control" id="biDue" aria-label="Due date (optional)"></div></div>` },
        { icon: "ri-list-check-2", title: "What it charges", body: `${rows}<div class="acc-sub mt-1" id="biTotal"></div>` },
        { icon: "ri-attachment-2", title: "The invoice", hint: "Optional", body: '<label class="budget-receipt-pick mb-0"><i class="ri-attachment-2"></i><span id="biFileName">Attach the invoice</span><input type="file" id="biFile" accept="image/jpeg,image/png,image/webp,application/pdf" hidden></label>' },
      ]),
      run: async () => {
        const lines = [...document.querySelectorAll("[data-bl]")].map((x) => ({ line_id: Number(x.dataset.bl), quantity: n(x.querySelector("[data-qty]").value), unit_price: n(x.querySelector("[data-price]").value) }));
        const out = await API.postBill(o.id, { supplier_ref: document.getElementById("biRef").value.trim(), date: document.getElementById("biDate").value, due_on: document.getElementById("biDue").value || null, lines }, document.getElementById("biFile").files[0]);
        if (out.ok) done();
        return out;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    const total = () => {
      let t = 0;
      let over = false;
      el.querySelectorAll("[data-bl]").forEach((x) => {
        const p = n(x.querySelector("[data-price]").value);
        t += n(x.querySelector("[data-qty]").value) * p;
        over ||= p > Number(x.dataset.max);
      });
      el.querySelector("#biTotal").innerHTML = `Bill total <strong>${A.money(t)}</strong>${over ? ' - <span class="text-danger fw-semibold">a price is above the order\'s</span>' : ""}`;
    };
    el.querySelectorAll("[data-bl] input").forEach((x) => x.addEventListener("input", total));
    el.querySelector("#biFile").addEventListener("change", (e) => (el.querySelector("#biFileName").textContent = e.target.files[0]?.name || "Attach the invoice"));
    ["#biDate", "#biDue"].forEach((s) => window.DateField && DateField.enhance(el.querySelector(s), { quick: s === "#biDate" ? ["today", "yesterday"] : ["in14", "in30"] }));
    total();
  }

  // ------------------------------------------------------------ bills

  function wireBillButtons(root, done) {
    root.addEventListener("click", async (e) => {
      const pay = e.target.closest("[data-paybill]");
      const rev = e.target.closest("[data-reversebill]");
      const file = e.target.closest("[data-billfile]");
      const ord = e.target.closest("[data-openorder]");
      if (file) return openFile(() => API.billFileUrl(Number(file.dataset.billfile)));
      if (ord && root !== document.getElementById("prWindow")) return orderWindow(Number(ord.dataset.openorder));
      if (pay) return payBillWindow(findBill(Number(pay.dataset.paybill)));
      if (rev) {
        const b = findBill(Number(rev.dataset.reversebill));
        return K.confirmWindow({
          title: `Reverse bill ${b.number}`,
          subtitle: `${A.money(b.amount)} to ${b.supplier || ""} - its quantities can be billed again`,
          icon: "ri-arrow-go-back-line",
          danger: true,
          go: "Reverse it",
          body: K.parts([{ icon: "ri-chat-3-line", title: "Why?", body: '<textarea class="form-control" id="prWhy" rows="2" maxlength="255" placeholder="e.g. Entered the wrong amount"></textarea>' }]),
          run: async () => {
            const out = await API.reverseBill(b.id, document.getElementById("prWhy").value.trim());
            if (out.ok) (done || load)();
            return out;
          },
        });
      }
    });
  }

  const findBill = (id) => billCache[id] || data.bills.find((b) => b.id === id);

  async function payBillWindow(b) {
    const o = await A.options();
    if (!o) return;
    K.confirmWindow({
      title: `Pay ${b.supplier || ""}`,
      subtitle: `${A.money(b.amount)} - bill ${b.number}; the voucher is already authorised, you pay it next`,
      icon: "ri-hand-coin-line",
      go: '<i class="ri-check-line me-1"></i>Make the voucher',
      body: K.parts([{ icon: "ri-bank-line", title: "Pay it from", body: W.cashTiles(o.cash, o.cash.find((a) => a.cash_kind === "bank")?.id || o.cash[0]?.id, "pbFrom") }]),
      run: async () => {
        const res = await API.payBill(b.id, Number(document.querySelector('input[name="pbFrom"]:checked')?.value));
        if (res.ok) setTimeout(() => (window.location.href = A.link("payments.php", { voucher: res.data.voucher_id })), 500);
        return res;
      },
    });
  }

  // ------------------------------------------------------------ suppliers

  function supplierWindow(s = null) {
    const f = (id, label, v, type = "text", max = 150) => `<div class="col-sm-6"><label class="form-label" for="${id}">${label}</label><input type="${type}" class="form-control" id="${id}" maxlength="${max}" value="${esc(v || "")}"></div>`;
    K.confirmWindow({
      title: s ? `Change ${s.name}` : "Add a supplier",
      subtitle: "Picked on quotations and orders",
      icon: "ri-store-2-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: "ri-store-2-line", title: "Who", body: `<div class="row g-2">${f("suName", "Name", s?.name)}${f("suPin", "KRA PIN", s?.kra_pin, "text", 20)}</div>` },
        { icon: "ri-phone-line", title: "Contact", body: `<div class="row g-2">${f("suPhone", "Phone", s?.phone, "tel", 30)}${f("suEmail", "Email", s?.email, "email")}</div>` },
        { icon: "ri-bank-card-line", title: "How to pay them", body: `<input type="text" class="form-control" id="suPay" maxlength="255" placeholder="e.g. Till 123456, or Equity a/c 0123..." value="${esc(s?.pay_details || "")}">${s ? `<label class="form-check form-switch mt-2 mb-0"><input class="form-check-input" type="checkbox" id="suOn"${s.is_active ? " checked" : ""}><span class="form-check-label">Active - offered on new quotations and orders</span></label>` : ""}` },
      ]),
      run: async () => {
        const v = (id) => document.getElementById(id).value.trim() || null;
        const out = await API.saveSupplier(s?.id, { name: v("suName") || "", kra_pin: v("suPin"), phone: v("suPhone"), email: v("suEmail"), pay_details: v("suPay"), is_active: s ? document.getElementById("suOn").checked : true });
        if (out.ok) load();
        return out;
      },
    });
  }

  // ------------------------------------------------------------ print

  /** The local purchase order, printed from the browser for the supplier. */
  /** The LPO the supplier is given: the diocese PDF (accounting.lpo). */
  const printOrder = (o) => A.pdf("accounting.lpo", { record_id: o.id }, `LPO ${o.number}`);
  // ------------------------------------------------------------ start

  function init() {
    $("supplierBtn")?.addEventListener("click", () => supplierWindow());
    $("prToOrderRows").addEventListener("click", (e) => {
      const b = e.target.closest("[data-raise]");
      if (b) raiseWindow(Number(b.dataset.raise));
    });
    $("prRows").addEventListener("click", (e) => {
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("tr[data-id]");
      if (tr) window.location.href = A.link("record.php", { type: "order", id: tr.dataset.id });
    });
    wireBillButtons($("prBillRows"));
    $("prSupplierRows").addEventListener("click", async (e) => {
      if (e.target.closest("[data-firstsupplier]")) return supplierWindow();
      const ed = e.target.closest("[data-editsupplier]");
      const rm = e.target.closest("[data-removesupplier]");
      if (ed) return supplierWindow(data.suppliers.find((s) => s.id === Number(ed.dataset.editsupplier)));
      if (rm) {
        const s = data.suppliers.find((x) => x.id === Number(rm.dataset.removesupplier));
        if (!confirm(`Remove ${s.name}? One with quotes or orders is switched off instead.`)) return;
        const out = await API.removeSupplier(s.id);
        if (!out.ok) return Toast.error(out.message);
        Toast.success(out.message);
        load();
      }
    });
    $("prTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "orders" ? p.delete("tab") : p.set("tab", tab);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      showTab();
    });
    A.placePicker($("accPlacePick"), load);
    load().then(() => {
      if (params.get("order")) orderWindow(Number(params.get("order")));
      if (params.get("order_for")) raiseWindow(Number(params.get("order_for")));
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
