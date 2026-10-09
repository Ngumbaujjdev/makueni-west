/**
 * ============================================================================
 * ACCOUNTING - Requisitions (requisitions.php, every level)
 * ============================================================================
 * Ask for money in one window - to pay for something, to buy something, or a
 * cash advance - with what's left on the budget line beside it. It goes
 * through the approval rules; once approved the treasurer makes the payment
 * (a voucher already authorised). The Advances tab shows money given ahead
 * and accounts for it with receipts and change. Everyone sees their own;
 * whoever reads the books sees all of the place's.
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
  let data = null;
  let kit = null;
  let tab = new URLSearchParams(window.location.search).get("tab") === "advances" ? "advances" : "requisitions";

  const ST = {
    submitted: ["warning", "ri-time-line", "Waiting"],
    approved: ["primary", "ri-shield-check-line", "To pay"],
    returned: ["danger", "ri-arrow-go-back-line", "Sent back"],
    rejected: ["danger", "ri-close-circle-line", "Rejected"],
    paid: ["success", "ri-checkbox-circle-line", "Paid"],
    cancelled: ["secondary", "ri-close-line", "Cancelled"],
  };
  const KIND = { payment: ["ri-bill-line", "primary"], purchase: ["ri-shopping-cart-2-line", "purple"], advance: ["ri-wallet-3-line", "warning"] };
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;

  function cards() {
    const it = data.items;
    const of = (s) => it.filter((r) => r.status === s);
    const sum = (arr) => arr.reduce((t, r) => t + r.amount, 0);
    const ym = new Date().toISOString().slice(0, 7);
    const out = data.advances.filter((a) => a.status === "open");
    K.statRow($("statCardsRow"), [
      { icon: "ri-time-line", label: "Waiting for approval", sub: A.short(sum(of("submitted"))), value: A.num(of("submitted").length), color: "warning" },
      { icon: "ri-shield-check-line", label: "Approved - to pay", sub: A.short(sum(of("approved"))), value: A.num(of("approved").length), color: "primary" },
      { icon: "ri-checkbox-circle-line", label: "Paid this month", sub: `${A.num(of("paid").filter((r) => (r.requested_at || "").startsWith(ym)).length)} requisitions`, value: A.short(sum(of("paid").filter((r) => (r.requested_at || "").startsWith(ym)))), color: "success" },
      { icon: "ri-wallet-3-line", label: "Advances out", sub: out.some((a) => a.overdue) ? `${out.filter((a) => a.overdue).length} overdue` : "None overdue", value: A.short(out.reduce((t, a) => t + a.outstanding, 0)), color: "purple" },
    ]);
    $("rqFigure").textContent = `${of("submitted").length} waiting`;
    $("advFigure").textContent = out.length ? `${out.length} out` : "None out";
  }

  const rowHtml = (r) => `<tr class="acc-row" data-id="${r.id}" data-pills="${r.status}${r.requested_by_id === data.me ? " mine" : ""} ${r.kind}">
    ${K.checkCell(r.id, r.number)}
    <td data-search="${esc(`${r.number} ${r.purpose} ${r.requested_by || ""} ${r.payee_name || ""}`)}" data-order="${esc(r.number)}"><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${KIND[r.kind][1]} ${A.textOn(KIND[r.kind][1])}"><i class="${KIND[r.kind][0]}"></i></span><div class="min-w-0"><div class="fw-semibold">${esc(r.purpose)}</div><div class="acc-sub">${esc(r.number)} · ${esc(r.kind_label)}${r.files ? ` · <i class="ri-attachment-2"></i>${r.files}` : ""}</div></div></div></td>
    <td data-order="${r.requested_at}" class="text-nowrap">${A.day(r.requested_at)}</td>
    <td class="d-none d-md-table-cell">${esc(r.requested_by || "")}</td>
    <td class="d-none d-lg-table-cell">${pill(r.status)}<div class="acc-sub mt-1">${esc(r.status === "submitted" ? (r.waiting_on.length ? `Waiting for ${r.waiting_on.join(", ")}` : "Waiting for someone who can approve") : r.status === "approved" ? (r.voucher ? `Voucher ${r.voucher.number}` : `Approved by ${r.decided_by || ""}`) : r.decision_note || "")}</div></td>
    <td class="text-end" data-order="${r.amount}"><strong>${A.money(r.amount)}</strong>${r.can.decide ? '<div><span class="badge bg-success mt-1">Your turn</span></div>' : ""}</td>
  </tr>`;

  function list() {
    kit?.destroy();
    $("rqTitle").textContent = data.all ? "Requisitions" : "My requisitions";
    if (!data.items.length) {
      $("rqPills").innerHTML = "";
      $("rqFilters").innerHTML = "";
      $("rqRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-hand-coin-line", "Nothing asked for yet", "Need money for something? Ask here - it goes for approval, then the treasurer pays it.", data.can.request && !A.viewingBelow() ? '<button type="button" class="btn btn-primary" data-first><i class="ri-hand-coin-line me-1"></i>Ask for money</button>' : "")}</td></tr>`;
      return;
    }
    const home = data.items.some((r) => r.can.decide) ? "submitted" : data.can.pay && data.items.some((r) => r.status === "approved") ? "approved" : "all";
    kit = K.listTable({
      tableId: "rqTable",
      stripId: "rqFilters",
      pillsId: "rqPills",
      rowsId: "rqRows",
      items: data.items,
      rowHtml,
      noun: "requisitions",
      defaultPill: home,
      searchPlaceholder: "Search what for, number, who asked...",
      pills: [
        { key: "submitted", label: "Waiting", icon: ST.submitted[1], color: "warning", test: (r) => r.status === "submitted" },
        { key: "approved", label: "To pay", icon: ST.approved[1], color: "primary", test: (r) => r.status === "approved" },
        { key: "returned", label: "Sent back", icon: ST.returned[1], color: "danger", test: (r) => r.status === "returned" },
        { key: "paid", label: "Paid", icon: ST.paid[1], color: "success", test: (r) => r.status === "paid" },
        { key: "advance", label: "Advances", icon: KIND.advance[0], color: "purple", test: (r) => r.kind === "advance" },
        { key: "mine", label: "Mine", icon: "ri-user-line", color: "pink", test: (r) => r.requested_by_id === data.me },
      ],
      sorts: [
        { key: "new", label: "Newest first", order: [[2, "desc"]] },
        { key: "big", label: "Largest first", order: [[5, "desc"]] },
      ],
      actions: [],
    });
  }

  function advances() {
    $("advRows").innerHTML = data.advances.length
      ? data.advances
          .map(
            (a) => `<tr><td><span class="fw-semibold">${esc(a.holder)}</span>${a.overdue ? ' <span class="badge bg-danger">Overdue</span>' : ""}</td><td>${esc(a.purpose)}<div class="acc-sub">Account for it by ${A.day(a.due_on)}</div></td><td class="d-none d-md-table-cell">${A.day(a.issued_on)}</td><td class="text-end">${A.money(a.amount)}</td><td class="text-end"><strong class="${a.outstanding > 0 ? "text-danger" : "text-success"}">${A.money(a.outstanding)}</strong><div class="acc-sub">spent ${A.short(a.spent)} · back ${A.short(a.returned)}</div></td><td class="text-end">${a.status === "open" && data.can.retire && !A.viewingBelow() ? `<button type="button" class="btn btn-sm btn-primary" data-retire="${a.id}"><i class="ri-receipt-2-line me-1"></i>Account for it</button>` : a.status === "retired" ? '<span class="badge bg-success">Accounted for</span>' : ""}</td></tr>`,
          )
          .join("")
      : `<tr><td colspan="6">${A.empty("ri-wallet-3-line", "No advances", "A requisition for a cash advance shows here once it's paid.")}</td></tr>`;
  }

  function showTab() {
    document.querySelectorAll("#rqTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("rqPane").hidden = tab !== "requisitions";
    $("advPane").hidden = tab !== "advances";
  }

  async function load() {
    A.ownOnly();
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("rqRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.requisitions();
    if (!res.ok) {
      $("rqTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    list();
    advances();
    showTab();
  }

  // ------------------------------------------------------------ ask

  async function ask(existing = null) {
    const res = await API.requisitionOptions();
    if (!res.ok) return Toast.error(res.message);
    const o = res.data;
    const kind0 = existing?.kind || "payment";
    const tiles = Object.entries({ payment: ["Pay for something", "A bill, a service, a person"], purchase: ["Buy something", "Chairs, a printer, materials"], advance: ["Cash advance", "Money ahead, accounted for later"] })
      .map(([k, [t, sub]]) => `<label class="acc-tile" style="--q: var(--${KIND[k][1]}-rgb)"><input type="radio" name="rkKind" value="${k}"${k === kind0 ? " checked" : ""}><span class="acc-tile-icon"><i class="${KIND[k][0]}"></i></span><span class="acc-tile-text"><strong>${t}</strong><small>${sub}</small></span></label>`)
      .join("");
    const opt = (a) => `<option value="${a.id}"${String(existing?.account_id) === String(a.id) ? " selected" : ""}${a.left !== null && a.left !== undefined ? ` data-left="${a.left}"` : ""}>${esc(a.code)} · ${esc(a.name)}</option>`;
    document.getElementById("rkWindow")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal att-modal acc-modal" id="rkWindow" tabindex="-1" aria-labelledby="rkTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-hand-coin-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="rkTitle">${existing ? `Change ${esc(existing.number)}` : "Ask for money"}</h5><div class="app-modal-subtitle">It goes for approval; once approved the treasurer pays it</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="att-entry"><div class="att-entry-main">
          <section class="att-entry-section"><div class="att-entry-title"><span>1</span>What kind?</div><div class="acc-tiles">${tiles}</div>${o.overdue_advance ? '<div class="alert alert-danger mt-2 mb-0">You have an advance not accounted for past its date - account for it before asking for another.</div>' : ""}</section>
          <section class="att-entry-section"><div class="att-entry-title"><span>2</span>What for, and how much?</div>
            <div class="row g-2"><div class="col-sm-8"><input type="text" class="form-control" id="rkPurpose" maxlength="255" placeholder="e.g. 20 plastic chairs for the youth hall" value="${esc(existing?.purpose || "")}"></div><div class="col-sm-4"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end fw-semibold" id="rkAmount" placeholder="0" value="${existing?.amount || ""}"></div></div></div></section>
          <section class="att-entry-section" data-not-advance><div class="att-entry-title"><span>3</span>Charged to</div>
            <div class="row g-2"><div class="col-sm-8"><select class="form-select" id="rkAccount"><option value="">Pick what it will be spent on</option>${o.accounts.map(opt).join("")}</select><div class="acc-sub mt-1" id="rkLeft"></div></div><div class="col-sm-4"><select class="form-select" id="rkFund">${o.funds.map((f) => `<option value="${f.id}"${String(existing?.fund_id || "") === String(f.id) || (!existing?.fund_id && f.code === "GEN") ? " selected" : ""} data-color="${f.is_restricted ? "warning" : "success"}">${esc(f.name)}</option>`).join("")}</select></div></div></section>
          <section class="att-entry-section mb-0"><div class="att-entry-title"><span>4</span>Details</div>
            <div class="row g-2"><div class="col-sm-6" data-not-advance><input type="text" class="form-control" id="rkPayee" maxlength="150" placeholder="Pay to (optional)" value="${esc(existing?.payee_name || "")}"></div><div class="col-sm-6" data-not-advance><input type="tel" class="form-control" id="rkPhone" maxlength="30" placeholder="Their phone (optional)" value="${esc(existing?.payee_phone || "")}"></div>
            <div class="col-sm-6"><input type="date" class="form-control" id="rkNeeded" min="${o.today}" value="${existing?.needed_by || ""}" aria-label="Needed by"></div>
            <div class="col-sm-6">${existing ? "" : '<label class="budget-receipt-pick mb-0"><i class="ri-attachment-2"></i><span id="rkFileName">Attach a quote or invoice (optional)</span><input type="file" id="rkFile" accept="image/jpeg,image/png,image/webp,application/pdf" multiple hidden></label>'}</div></div></section>
        </div>
        <aside class="att-entry-preview acc-preview" aria-live="polite"><div class="att-preview-label">Requisition</div><div class="att-preview-what" id="rkPvWhat">-</div><div class="att-preview-total budget-fit-amount"><small>KES</small><span id="rkPvAmount">0</span></div><div class="att-preview-compare" id="rkPvNote"></div><div class="att-preview-compare">Next: approval by the rule for this amount - you'll see who on the Approvals page.</div></aside>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rkGo"><i class="ri-send-plane-line me-1"></i>${existing ? "Send again" : "Send for approval"}</button></div>
      </div></div></div>`,
    );
    const el = document.getElementById("rkWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    const kind = () => el.querySelector('input[name="rkKind"]:checked').value;
    const upd = () => {
      el.querySelectorAll("[data-not-advance]").forEach((x) => (x.hidden = kind() === "advance"));
      const amt = n(el.querySelector("#rkAmount").value);
      el.querySelector("#rkPvWhat").textContent = el.querySelector("#rkPurpose").value || "What for?";
      el.querySelector("#rkPvAmount").textContent = amt.toLocaleString("en-GB");
      const left = el.querySelector("#rkAccount").selectedOptions[0]?.dataset.left;
      el.querySelector("#rkLeft").innerHTML = !el.querySelector("#rkAccount").value ? "" : left === undefined ? (o.budget ? "Not in this period's budget" : "No budget in use") : `KES ${Number(left).toLocaleString("en-GB")} left on this line in the ${esc(o.budget?.label || "")} budget`;
      el.querySelector("#rkPvNote").innerHTML = kind() !== "advance" && left !== undefined && amt > Number(left) ? `<span class="text-danger fw-semibold">More than is left on the budget line</span>` : kind() === "advance" ? "An advance is accounted for later with receipts and any change." : "";
    };
    el.querySelectorAll('input[name="rkKind"]').forEach((r) => r.addEventListener("change", upd));
    ["#rkAmount", "#rkPurpose"].forEach((s) => el.querySelector(s).addEventListener("input", upd));
    el.querySelector("#rkAccount").addEventListener("change", upd);
    UI.enhanceSelect(el.querySelector("#rkAccount"), { search: true });
    UI.enhanceSelect(el.querySelector("#rkFund"), { search: false });
    if (window.DateField) DateField.enhance(el.querySelector("#rkNeeded"), { quick: ["in3", "in14"] });
    el.querySelector("#rkFile")?.addEventListener("change", (e) => (el.querySelector("#rkFileName").textContent = [...e.target.files].map((f) => f.name).join(", ") || "Attach a quote or invoice (optional)"));
    upd();
    bootstrap.Modal.getOrCreateInstance(el).show();
    el.querySelector("#rkGo").addEventListener("click", async (e) => {
      const b = e.currentTarget;
      UI.setButtonLoading(b, "Sending...");
      const k = kind();
      const r = await API.saveRequisition(existing?.id, {
        kind: k,
        purpose: el.querySelector("#rkPurpose").value.trim(),
        amount: n(el.querySelector("#rkAmount").value),
        account_id: k === "advance" ? null : Number(el.querySelector("#rkAccount").value) || null,
        fund_id: k === "advance" ? null : Number(el.querySelector("#rkFund").value) || null,
        payee_name: k === "advance" ? null : el.querySelector("#rkPayee").value.trim() || null,
        payee_phone: k === "advance" ? null : el.querySelector("#rkPhone").value.trim() || null,
        needed_by: el.querySelector("#rkNeeded").value || null,
      });
      if (r.ok) for (const f of [...(el.querySelector("#rkFile")?.files || [])]) await API.addRequisitionFile(r.data.id, f);
      UI.restoreButton(b);
      if (!r.ok) return Toast.error(r.message);
      Toast.success(r.message);
      bootstrap.Modal.getInstance(el)?.hide();
      load();
    });
  }

  // ------------------------------------------------------------ view

  async function view(id) {
    const res = await API.requisition(id);
    if (!res.ok) return Toast.error(res.message);
    const r = res.data;
    const c = r.can;
    document.getElementById("rkWindow")?.remove();
    const fact = (k, v) => (v ? `<div><span>${esc(k)}</span><strong>${v}</strong></div>` : "");
    const files = r.attachments.length ? `<div class="acc-files">${r.attachments.map((f) => `<div class="acc-file"><i class="${f.mime === "application/pdf" ? "ri-file-pdf-line text-danger" : "ri-image-line text-primary"}"></i><button type="button" class="btn btn-link p-0 text-start flex-fill" data-file="${f.id}">${esc(f.name)}</button></div>`).join("")}</div>` : '<p class="acc-muted-line mb-0">No papers attached.</p>';
    const adv = r.advance ? `<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-wallet-3-line"></i>The advance</div><div class="acc-facts">${fact("Given", A.money(r.advance.amount))}${fact("Spent", A.money(r.advance.spent))}${fact("Change back", A.money(r.advance.returned))}${fact("Still out", A.money(r.advance.outstanding))}${fact("Account for it by", A.day(r.advance.due_on))}</div></section>` : "";
    const btn = (k, cls, icon, label) => `<button type="button" class="btn ${cls}" data-act="${k}"><i class="${icon} me-1"></i>${label}</button>`;
    const foot = [
      c.cancel ? btn("cancel", "btn-outline-danger me-auto", "ri-close-circle-line", "Cancel it") : "",
      c.edit ? btn("edit", "btn-outline-primary", "ri-edit-line", r.status === "returned" ? "Fix and send again" : "Change") : "",
      c.decide ? btn("reject", "btn-outline-danger", "ri-close-line", "Reject") + btn("return", "btn-outline-warning", "ri-arrow-go-back-line", "Send back") + btn("approve", "btn-success", "ri-check-line", "Approve") : "",
      c.pay ? btn("pay", "btn-primary", "ri-hand-coin-line", "Make the payment") : "",
      r.voucher ? `<a class="btn btn-outline-primary" href="${A.link("payments.php", { voucher: r.voucher.id })}"><i class="ri-file-list-3-line me-1"></i>Voucher ${esc(r.voucher.number)}</a>` : "",
      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>',
    ].join("");
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="rkWindow" tabindex="-1" aria-labelledby="rkTitle"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="${KIND[r.kind][0]}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="rkTitle">${esc(r.number)} · ${esc(r.kind_label)}</h5><div class="app-modal-subtitle">${A.money(r.amount)} · ${esc(r.status_label)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          ${r.status === "returned" ? `<div class="alert alert-warning mb-3"><strong>Sent back:</strong> ${esc(r.decision_note || "")}</div>` : r.status === "rejected" ? `<div class="alert alert-danger mb-3"><strong>Rejected:</strong> ${esc(r.decision_note || "")}</div>` : ""}
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-file-text-line"></i>What is asked</div><div class="acc-facts">${fact("What for", esc(r.purpose))}${fact("Amount", A.money(r.amount))}${fact("Asked by", esc(r.requested_by || ""))}${fact("On", A.day(r.requested_at))}${fact("Pay to", esc(r.payee_name || ""))}${fact("Needed by", r.needed_by ? A.day(r.needed_by) : "")}${fact("Charged to", r.account ? esc(`${r.account.code} ${r.account.name}`) : "")}${fact("Budget line", esc(r.budget_line || ""))}${fact("Fund", esc(r.fund?.name || ""))}</div></section>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-attachment-2"></i>Papers</div>${files}</section>
          ${r.approval ? `<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Approval<small>${esc(r.approval.workflow || "")}</small></div>${A.approvalTimeline(r.approval)}</section>` : r.status === "submitted" ? '<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Approval</div><p class="mb-0">No rule covers it - anyone who authorises payments here (not the person asking) approves it.</p></section>' : ""}
          ${adv}
          ${c.decide ? '<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-chat-3-line"></i>Your decision<small>A comment is needed to send back or reject</small></div><textarea class="form-control" id="rkComment" rows="2" maxlength="500" placeholder="Comment"></textarea></section>' : ""}
        </div>
        <div class="modal-footer" id="rkFoot">${foot}</div>
      </div></div></div>`,
    );
    const el = document.getElementById("rkWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    el.addEventListener("click", async (e) => {
      const f = e.target.closest("[data-file]");
      if (!f) return;
      const w = window.open("", "_blank");
      const u = await API.requisitionFileUrl(r.id, Number(f.dataset.file));
      u ? (w.location = u) : (w.close(), Toast.error("That file could not be opened."));
    });
    el.querySelector("#rkFoot").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-act]");
      if (!b) return;
      const act = b.dataset.act;
      if (act === "edit") {
        bootstrap.Modal.getInstance(el)?.hide();
        return ask(r);
      }
      if (act === "pay") return payWindow(r, el);
      const comment = el.querySelector("#rkComment")?.value.trim() || null;
      if ((act === "reject" || act === "return") && !comment) {
        el.querySelector("#rkComment").classList.add("is-invalid");
        return Toast.error("Say why.");
      }
      if (act === "cancel" && !confirm(`Cancel ${r.number}?`)) return;
      UI.setButtonLoading(b, "...");
      const out = act === "cancel" ? await API.cancelRequisition(r.id) : await API.decideRequisition(r.id, act, comment);
      UI.restoreButton(b);
      if (!out.ok) return Toast.error(out.message);
      Toast.success(out.message);
      bootstrap.Modal.getInstance(el)?.hide();
      load();
    });
  }

  async function payWindow(r, parent) {
    const o = await A.options();
    if (!o) return;
    K.confirmWindow({
      title: `Pay ${r.number}`,
      subtitle: `${A.money(r.amount)} - the voucher is already authorised; you pay it next`,
      icon: "ri-hand-coin-line",
      go: '<i class="ri-check-line me-1"></i>Make the voucher',
      body: K.parts([{ icon: "ri-bank-line", title: "Pay it from", body: W.cashTiles(o.cash, o.cash.find((a) => a.cash_kind === "bank")?.id || o.cash[0]?.id, "rkFrom") }]),
      run: async () => {
        const res = await API.payRequisition(r.id, Number(document.querySelector('input[name="rkFrom"]:checked')?.value));
        if (res.ok) {
          bootstrap.Modal.getInstance(parent)?.hide();
          setTimeout(() => (window.location.href = A.link("payments.php", { voucher: res.data.voucher_id })), 500);
        }
        return res;
      },
    });
  }

  // ------------------------------------------------------------ retire an advance

  async function retireWindow(a) {
    const o = await A.options();
    if (!o) return;
    const today = new Date().toISOString().slice(0, 10);
    const el = K.confirmWindow({
      title: `Account for the advance to ${a.holder}`,
      subtitle: `${A.money(a.outstanding)} still out of ${A.money(a.amount)} - ${a.purpose}`,
      icon: "ri-receipt-2-line",
      go: '<i class="ri-check-line me-1"></i>Record it',
      body: K.parts([
        { icon: "ri-calendar-line", title: "Date", body: `<input type="date" class="form-control" id="rtDate" value="${today}" max="${today}">` },
        { icon: "ri-shopping-bag-3-line", title: "What it was spent on", hint: "With the receipts", body: `<div id="rtLines"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="rtAdd"><i class="ri-add-line me-1"></i>Add another</button>` },
        { icon: "ri-coins-line", title: "Change brought back", body: `<div class="row g-2"><div class="col-sm-6"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" id="rtBack" placeholder="0"></div></div><div class="col-sm-6"><select class="form-select" id="rtInto">${o.cash.map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join("")}</select></div></div><div class="acc-sub mt-2" id="rtSum"></div>` },
      ]),
      run: async () => {
        const lines = [...document.querySelectorAll("#rtLines [data-rt]")].map((r) => ({ account_id: Number(r.querySelector("select").value), amount: n(r.querySelector("input[data-amt]").value), memo: r.querySelector("input[data-memo]").value.trim() || null })).filter((l) => l.amount > 0);
        const res = await API.retireAdvance(a.id, { date: document.getElementById("rtDate").value, lines, returned: n(document.getElementById("rtBack").value), return_account_id: Number(document.getElementById("rtInto").value) });
        if (res.ok) setTimeout(load, 300);
        return res;
      },
    });
    const box = el.querySelector("#rtLines");
    const sum = () => {
      const spent = [...box.querySelectorAll("input[data-amt]")].reduce((t, x) => t + n(x.value), 0);
      const back = n(el.querySelector("#rtBack").value);
      const left = Math.round((a.outstanding - spent - back) * 100) / 100;
      el.querySelector("#rtSum").innerHTML = `Spent ${A.money(spent)} + back ${A.money(back)} → ${left < 0 ? `<span class="text-danger fw-semibold">${A.money(-left)} too much</span>` : left === 0 ? '<span class="text-success fw-semibold">accounted for in full</span>' : `${A.money(left)} still out`}`;
    };
    const add = () => {
      box.insertAdjacentHTML("beforeend", `<div class="row g-2 mb-2" data-rt><div class="col-sm-5"><select class="form-select">${o.expense.map((x) => `<option value="${x.id}">${esc(x.code)} · ${esc(x.name)}</option>`).join("")}</select></div><div class="col-sm-3"><input type="text" inputmode="decimal" class="form-control text-end" data-amt placeholder="KES"></div><div class="col-sm-4"><input type="text" class="form-control" data-memo maxlength="255" placeholder="Note"></div></div>`);
      UI.enhanceSelect(box.lastElementChild.querySelector("select"), { search: true });
      box.lastElementChild.querySelector("input[data-amt]").addEventListener("input", sum);
    };
    add();
    el.querySelector("#rtAdd").addEventListener("click", add);
    el.querySelector("#rtBack").addEventListener("input", sum);
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    if (window.DateField) DateField.enhance(el.querySelector("#rtDate"), { quick: ["today", "yesterday"] });
    sum();
  }

  function init() {
    $("askBtn")?.addEventListener("click", () => ask());
    $("rqRows").addEventListener("click", (e) => {
      if (e.target.closest("[data-first]")) return ask();
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("tr[data-id]");
      if (tr) view(Number(tr.dataset.id));
    });
    $("advRows").addEventListener("click", (e) => {
      const b = e.target.closest("[data-retire]");
      if (b) retireWindow(data.advances.find((a) => a.id === Number(b.dataset.retire)));
    });
    $("rqTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "advances" ? p.set("tab", "advances") : p.delete("tab");
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      showTab();
    });
    const open = new URLSearchParams(window.location.search).get("requisition");
    if (open) view(Number(open));
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
