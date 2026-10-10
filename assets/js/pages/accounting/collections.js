/**
 * ============================================================================
 * ACCOUNTING - Collections (collections.php, churches)
 * ============================================================================
 * Cards (this month's giving with its change and sparkline, the last
 * service, cash not yet banked, waiting to be confirmed), what waits for a
 * second person, and every collection - pills by where it stands. The count
 * window: which service, what was given (cash and M-Pesa per kind), the notes
 * and coins, who counted. The view window: confirm, send back, bank it,
 * print the collection sheet.
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

  const STATUS = {
    counted: { label: "Waiting to confirm", color: "warning", icon: "ri-time-line" },
    returned: { label: "Sent back", color: "danger", icon: "ri-arrow-go-back-line" },
    posted: { label: "Receipted", color: "success", icon: "ri-checkbox-circle-line" },
    reversed: { label: "Reversed", color: "secondary", icon: "ri-close-circle-line" },
  };
  const pill = (s) => `<span class="badge bg-${STATUS[s].color} ${A.textOn(STATUS[s].color)}"><i class="${STATUS[s].icon} me-1"></i>${STATUS[s].label}</span>`;

  // ------------------------------------------------------------ page

  function cards() {
    const posted = data.items.filter((c) => c.status === "posted");
    const ym = new Date().toISOString().slice(0, 7);
    const lastYm = (() => {
      const d = new Date();
      d.setDate(1);
      d.setMonth(d.getMonth() - 1);
      return d.toISOString().slice(0, 7);
    })();
    const sum = (arr) => arr.reduce((t, c) => t + c.total, 0);
    const month = posted.filter((c) => c.date.startsWith(ym));
    const prev = posted.filter((c) => c.date.startsWith(lastYm));
    const last = posted[0];
    const waiting = data.items.filter((c) => c.status === "counted").length;
    K.statRow($("statCardsRow"), [
      { icon: "ri-hand-heart-line", label: "Given this month", sub: `${A.num(month.length)} ${month.length === 1 ? "collection" : "collections"} receipted`, value: A.figure(sum(month)), color: "success", delta: UI.periodDelta(sum(month), sum(prev)), series: data.monthly, trim: true },
      { icon: "ri-sun-line", label: "Last service", sub: last ? `${last.title}, ${A.day(last.date, { day: "numeric", month: "short" })}` : "None yet", value: last ? A.figure(last.total) : "-", color: "primary" },
      { icon: "ri-bank-line", label: "Cash not banked", sub: data.unbanked.count ? `From ${data.unbanked.count} ${data.unbanked.count === 1 ? "collection" : "collections"}` : "All banked", value: A.figure(data.unbanked.total), color: "purple" },
      { icon: "ri-time-line", label: "Waiting to confirm", sub: "A second person checks each count", value: A.num(waiting), color: "warning" },
    ]);
  }

  function waitingList() {
    const list = data.items.filter((c) => c.status === "counted" || c.status === "returned");
    $("waitCard").hidden = !list.length;
    $("waitCount").textContent = list.length ? `${list.length} waiting` : "";
    $("waitList").innerHTML = list
      .map((c) => {
        const mine = c.counted_by_id === data.me;
        const act = c.status === "returned" ? `<span class="soft-chip soft-danger">Sent back: ${esc(c.return_reason || "")}</span>` : data.can.confirm && !mine ? `<button type="button" class="btn btn-sm btn-success" data-open="${c.id}"><i class="ri-shield-check-line me-1"></i>Check and confirm</button>` : `<span class="soft-chip soft-warning">${mine ? "You counted it - someone else confirms" : "Waiting for a second person"}</span>`;
        return `<div class="acc-wait-row"><span class="avatar avatar-sm avatar-rounded bg-warning text-dark"><i class="ri-hand-coin-line"></i></span><div class="flex-fill min-w-0"><div class="fw-semibold">${esc(c.title)} · ${A.money(c.total)}</div><div class="acc-sub">${A.day(c.date)} · counted by ${esc(c.counted_by || "")}</div></div>${act}</div>`;
      })
      .join("");
  }

  const rowHtml = (c) => `<tr class="acc-row" data-id="${c.id}" data-pills="${c.status}${c.status === "posted" && !c.banked && c.cash_total > 0 ? " unbanked" : ""}">
    ${K.checkCell(c.id, c.title)}
    <td data-search="${esc(`${c.title} ${c.kinds.map((k) => k.label).join(" ")} ${c.counted_by || ""} ${c.journal?.number || ""}`)}" data-order="${esc(c.title.toLowerCase())}"><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${STATUS[c.status].color} ${A.textOn(STATUS[c.status].color)}"><i class="ri-hand-coin-line"></i></span><div class="min-w-0"><div class="fw-semibold">${esc(c.title)}</div><div class="acc-sub">${c.journal ? esc(c.journal.number) : `Counted by ${esc(c.counted_by || "")}`}</div></div></div></td>
    <td data-order="${c.date}${String(c.id).padStart(8, "0")}" class="text-nowrap">${A.day(c.date)}</td>
    <td class="d-none d-lg-table-cell"><div class="d-flex flex-wrap gap-1">${c.kinds.map((k) => `<span class="soft-chip soft-primary">${esc(k.label)} ${A.money(k.cash + k.mpesa).replace("KES ", "")}</span>`).join("")}</div></td>
    <td class="d-none d-md-table-cell">${pill(c.status)}${c.status === "posted" ? `<div class="acc-sub mt-1">${c.banked ? `Banked ${A.day(c.banked.date, { day: "numeric", month: "short" })}` : c.cash_total > 0 ? "Cash not banked yet" : "All by M-Pesa"}</div>` : ""}</td>
    <td class="text-end" data-order="${c.total}"><strong>${A.money(c.total)}</strong><div class="acc-sub">Cash ${A.money(c.cash_total)} · M-Pesa ${A.money(c.mpesa_total)}</div></td>
  </tr>`;

  async function load() {
    kit?.destroy();
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("colRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.collections();
    if (!res.ok) {
      $("colTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    waitingList();
    if (!data.items.length) {
      $("colPills").innerHTML = "";
      $("colFilters").innerHTML = "";
      $("colRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-hand-coin-line", "No collections yet", "After each service, record what was given - a second person confirms it and it is receipted.", data.can.collect ? '<button type="button" class="btn btn-primary" data-first><i class="ri-hand-coin-line me-1"></i>Record a collection</button>' : "")}</td></tr>`;
      return;
    }
    const home = data.can.confirm && data.items.some((c) => c.status === "counted" && c.counted_by_id !== data.me) ? "counted" : "all";
    kit = K.listTable({
      tableId: "colTable",
      stripId: "colFilters",
      pillsId: "colPills",
      rowsId: "colRows",
      items: data.items,
      rowHtml,
      noun: "collections",
      defaultPill: home,
      searchPlaceholder: "Search service, kind of giving, who counted, receipt...",
      pills: [
        { key: "counted", label: "Waiting", icon: STATUS.counted.icon, color: "warning", test: (c) => c.status === "counted" },
        { key: "unbanked", label: "Not banked", icon: "ri-bank-line", color: "purple", test: (c) => c.status === "posted" && !c.banked && c.cash_total > 0 },
        { key: "posted", label: "Receipted", icon: STATUS.posted.icon, color: "success", test: (c) => c.status === "posted" },
        { key: "returned", label: "Sent back", icon: STATUS.returned.icon, color: "danger", test: (c) => c.status === "returned" },
      ],
      sorts: [
        { key: "new", label: "Newest first", order: [[2, "desc"]] },
        { key: "big", label: "Largest first", order: [[5, "desc"]] },
      ],
      actions: [],
    });
  }

  // ------------------------------------------------------------ the count window

  async function countWindow(existing = null) {
    const today = new Date().toISOString().slice(0, 10);
    const lastSunday = (() => {
      const d = new Date();
      d.setDate(d.getDate() - d.getDay());
      return d.toISOString().slice(0, 10);
    })();
    const date0 = existing?.date || lastSunday;
    const res = await API.collectionOptions(date0);
    if (!res.ok) return Toast.error(res.message);
    let o = res.data;
    const presetRows = existing ? existing.lines.map((l) => ({ label: l.label, account_id: l.account.id, fund_id: l.fund?.id, cash: l.cash, mpesa: l.mpesa })) : o.presets.map((p) => ({ ...p, cash: "", mpesa: "" }));
    const kindRow = (r, extra = false) => `<div class="acc-kind" data-kind>
      ${extra ? `<div class="acc-kind-pick"><select class="form-select" data-role="account">${o.accounts.map((a) => `<option value="${a.id}"${String(a.id) === String(r.account_id) ? " selected" : ""}>${esc(a.code)} · ${esc(a.name)}</option>`).join("")}</select><select class="form-select" data-role="fund">${o.funds.map((f) => `<option value="${f.id}"${String(f.id) === String(r.fund_id) || (!r.fund_id && f.code === "GEN") ? " selected" : ""} data-color="${f.is_restricted ? "warning" : "success"}">${esc(f.name)}</option>`).join("")}</select><input type="text" class="form-control" data-role="label" maxlength="100" placeholder="Name, e.g. Harvest" value="${esc(r.label || "")}"></div>` : `<div class="acc-kind-name"><strong>${esc(r.label)}</strong><small>${esc((o.funds.find((f) => f.id === r.fund_id) || {}).name || "")}</small><input type="hidden" data-role="account" value="${r.account_id}"><input type="hidden" data-role="fund" value="${r.fund_id || ""}"><input type="hidden" data-role="label" value="${esc(r.label)}"></div>`}
      <div class="input-group"><span class="input-group-text"><i class="ri-money-dollar-box-line"></i></span><input type="text" inputmode="decimal" class="form-control text-end" data-role="cash" placeholder="Cash" value="${r.cash || ""}" aria-label="${esc(r.label || "Kind")} in cash"></div>
      <div class="input-group"><span class="input-group-text"><i class="ri-smartphone-line"></i></span><input type="text" inputmode="decimal" class="form-control text-end" data-role="mpesa" placeholder="M-Pesa" value="${r.mpesa || ""}" aria-label="${esc(r.label || "Kind")} by M-Pesa"></div>
    </div>`;
    const den = (list) => list.map((d) => `<label class="acc-den"><span class="acc-den-face">${Number(d).toLocaleString("en-GB")}</span><span class="acc-den-x">×</span><input type="number" min="0" step="1" inputmode="numeric" class="form-control" data-den="${d}" placeholder="0" value="${existing?.denominations?.[d] || ""}"><span class="acc-den-sum" data-sum="${d}">-</span></label>`).join("");
    const el = (function () {
      document.getElementById("colWindow")?.remove();
      document.body.insertAdjacentHTML(
        "beforeend",
        `<div class="modal fade app-modal att-modal acc-modal" id="colWindow" tabindex="-1" aria-labelledby="colWindowTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon"><i class="ri-hand-coin-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="colWindowTitle">${existing ? "Change the count" : "Record a collection"}</h5><div class="app-modal-subtitle">Counted by you - a second person confirms it, then it is receipted</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body"><div class="att-entry">
            <div class="att-entry-main">
              <section class="att-entry-section"><div class="att-entry-title"><span>1</span>Which service?</div>
                <div class="row g-2"><div class="col-sm-4"><input type="date" class="form-control" id="cwDate" value="${date0}" max="${today}"></div><div class="col-sm-8"><select class="form-select" id="cwGathering"></select></div><div class="col-12"><input type="text" class="form-control" id="cwTitle" maxlength="150" placeholder="Name, e.g. Sunday main service" value="${esc(existing?.title || "Sunday service")}"></div></div></section>
              <section class="att-entry-section"><div class="att-entry-title"><span>2</span>What was given<small class="acc-part-hint">Cash and M-Pesa for each kind</small></div>
                <div class="acc-kinds" id="cwKinds">${presetRows.map((r) => kindRow(r, !!existing && !o.presets.some((p) => p.label === r.label && p.account_id === r.account_id && p.fund_id === r.fund_id))).join("")}</div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="cwAdd"><i class="ri-add-line me-1"></i>Add another kind</button></section>
              <section class="att-entry-section"><div class="att-entry-title"><span>3</span>Count the cash<small class="acc-part-hint">Optional - it must agree with the cash above</small></div>
                <div class="acc-den-grid"><div class="acc-den-group"><div class="acc-den-head">Notes</div>${den(["1000", "500", "200", "100", "50"])}</div><div class="acc-den-group"><div class="acc-den-head">Coins</div>${den(["40", "20", "10", "5", "1"])}</div></div><div class="mt-2" id="cwDenCheck"></div></section>
              <section class="att-entry-section mb-0"><div class="att-entry-title"><span>4</span>Who counted with you</div>
                <div class="row g-2"><div class="col-sm-6"><input type="text" class="form-control" data-witness maxlength="100" placeholder="e.g. an usher" value="${esc(existing?.witnesses?.[0] || "")}"></div><div class="col-sm-6"><input type="text" class="form-control" data-witness maxlength="100" placeholder="Another (optional)" value="${esc(existing?.witnesses?.[1] || "")}"></div><div class="col-12"><input type="text" class="form-control" id="cwNotes" maxlength="255" placeholder="Note (optional)" value="${esc(existing?.notes || "")}"></div></div></section>
            </div>
            <aside class="att-entry-preview acc-preview" aria-live="polite"><div class="att-preview-label">Collection</div><div class="att-preview-what" id="cwPvTitle">-</div><div class="att-preview-total budget-fit-amount"><small>KES</small><span id="cwPvTotal">0</span></div><ul class="att-preview-list" id="cwPvList"></ul><div class="att-preview-compare" id="cwPvSplit"></div><div class="att-preview-compare">Next: a second person confirms it.</div></aside>
          </div></div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="cwGo"><i class="ri-check-line me-1"></i>${existing ? "Save" : "Record it"}</button></div>
        </div></div></div>`,
      );
      return document.getElementById("colWindow");
    })();
    el.addEventListener("hidden.bs.modal", () => el.remove());

    const gatherings = () => {
      const sel = el.querySelector("#cwGathering");
      sel.innerHTML = `<option value="">${o.gatherings.length ? "Not linked to an attendance record" : "No attendance recorded that day"}</option>${o.gatherings.map((g) => `<option value="${g.id}"${String(existing?.attendance_record_id) === String(g.id) ? " selected" : ""} data-icon="ri-group-line" data-color="primary">${esc(g.name)}</option>`).join("")}`;
      UI.enhanceSelect(sel, { search: false });
    };
    const rows = () =>
      [...el.querySelectorAll("[data-kind]")].map((r) => ({
        label: r.querySelector('[data-role="label"]').value.trim(),
        account_id: Number(r.querySelector('[data-role="account"]').value),
        fund_id: Number(r.querySelector('[data-role="fund"]').value) || null,
        cash_amount: n(r.querySelector('[data-role="cash"]').value),
        mpesa_amount: n(r.querySelector('[data-role="mpesa"]').value),
      }));
    const upd = () => {
      const list = rows().filter((r) => r.cash_amount || r.mpesa_amount);
      const cash = list.reduce((t, r) => t + r.cash_amount, 0);
      const mpesa = list.reduce((t, r) => t + r.mpesa_amount, 0);
      el.querySelector("#cwPvTitle").textContent = el.querySelector("#cwTitle").value || "Service";
      el.querySelector("#cwPvTotal").textContent = (cash + mpesa).toLocaleString("en-GB");
      el.querySelector("#cwPvList").innerHTML = list.map((r) => `<li><span>${esc(r.label || "Other")}</span><strong>${A.amount(r.cash_amount + r.mpesa_amount)}</strong></li>`).join("");
      el.querySelector("#cwPvSplit").innerHTML = `<div class="d-flex justify-content-between"><span>Cash</span><strong>${A.amount(cash) || "0.00"}</strong></div><div class="d-flex justify-content-between"><span>M-Pesa</span><strong>${A.amount(mpesa) || "0.00"}</strong></div>`;
      let den = 0;
      let any = false;
      el.querySelectorAll("[data-den]").forEach((i) => {
        const v = Math.max(0, parseInt(i.value || "0", 10) || 0) * Number(i.dataset.den);
        den += v;
        any = any || v > 0;
        el.querySelector(`[data-sum="${i.dataset.den}"]`).textContent = v ? v.toLocaleString("en-GB") : "-";
      });
      el.querySelector("#cwDenCheck").innerHTML = !any ? "" : Math.abs(den - cash) < 0.005 ? `<span class="badge bg-success"><i class="ri-check-line me-1"></i>Notes and coins agree: ${A.money(den)}</span>` : `<span class="badge bg-danger">Notes and coins ${A.money(den)} - cash entered ${A.money(cash)}</span>`;
    };
    gatherings();
    el.querySelector("#cwKinds").addEventListener("input", upd);
    el.querySelectorAll("[data-den]").forEach((i) => i.addEventListener("input", upd));
    el.querySelector("#cwTitle").addEventListener("input", upd);
    el.querySelector("#cwGathering").addEventListener("change", (e) => {
      const g = o.gatherings.find((x) => String(x.id) === e.target.value);
      if (g) el.querySelector("#cwTitle").value = g.name;
      upd();
    });
    el.querySelector("#cwDate").addEventListener("change", async () => {
      const r = await API.collectionOptions(el.querySelector("#cwDate").value);
      if (r.ok) {
        o = { ...o, gatherings: r.data.gatherings };
        gatherings();
      }
    });
    el.querySelector("#cwAdd").addEventListener("click", () => {
      el.querySelector("#cwKinds").insertAdjacentHTML("beforeend", kindRow({ account_id: o.accounts.find((a) => a.code === "4030")?.id }, true));
      el.querySelector("#cwKinds").lastElementChild.querySelectorAll("select").forEach((s) => UI.enhanceSelect(s, { search: s.dataset.role === "account" }));
    });
    el.querySelectorAll("#cwKinds select").forEach((s) => UI.enhanceSelect(s, { search: s.dataset.role === "account" }));
    if (window.DateField) DateField.enhance(el.querySelector("#cwDate"), { quick: ["lastSunday", "today", "yesterday"] });
    upd();
    bootstrap.Modal.getOrCreateInstance(el).show();

    el.querySelector("#cwGo").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      const denominations = {};
      el.querySelectorAll("[data-den]").forEach((i) => {
        const v = parseInt(i.value || "0", 10) || 0;
        if (v > 0) denominations[i.dataset.den] = v;
      });
      UI.setButtonLoading(btn, "Saving...");
      const r = await API.saveCollection(existing?.id, {
        date: el.querySelector("#cwDate").value,
        title: el.querySelector("#cwTitle").value.trim(),
        attendance_record_id: Number(el.querySelector("#cwGathering").value) || null,
        denominations: Object.keys(denominations).length ? denominations : null,
        witnesses: [...el.querySelectorAll("[data-witness]")].map((w) => w.value.trim()).filter(Boolean),
        notes: el.querySelector("#cwNotes").value.trim() || null,
        lines: rows().filter((x) => x.cash_amount || x.mpesa_amount),
      });
      UI.restoreButton(btn);
      if (!r.ok) return Toast.error(r.message);
      Toast.success(r.message);
      bootstrap.Modal.getInstance(el)?.hide();
      load();
    });
  }

  // ------------------------------------------------------------ the view window

  async function view(id) {
    const res = await API.collection(id);
    if (!res.ok) return Toast.error(res.message);
    const c = res.data;
    const can = c.can;
    document.getElementById("colWindow")?.remove();
    const lines = `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Kind</th><th class="d-none d-sm-table-cell">Fund</th><th class="text-end">Cash</th><th class="text-end">M-Pesa</th><th class="text-end">Total</th></tr></thead><tbody>${c.lines
      .map((l) => `<tr><td><span class="fw-semibold">${esc(l.label)}</span><div class="acc-sub">${esc(l.account.code)} · ${esc(l.account.name)}</div></td><td class="d-none d-sm-table-cell">${l.fund ? `<span class="soft-chip soft-${l.fund.code === "GEN" ? "success" : "warning"}">${esc(l.fund.name)}</span>` : ""}</td><td class="text-end">${A.amount(l.cash)}</td><td class="text-end">${A.amount(l.mpesa)}</td><td class="text-end fw-semibold">${A.amount(l.cash + l.mpesa)}</td></tr>`)
      .join("")}</tbody><tfoot><tr><th>Total</th><th class="d-none d-sm-table-cell"></th><th class="text-end">${A.amount(c.cash_total) || "0.00"}</th><th class="text-end">${A.amount(c.mpesa_total) || "0.00"}</th><th class="text-end">${A.amount(c.total)}</th></tr></tfoot></table></div>`;
    const den = c.denominations ? `<div class="d-flex flex-wrap gap-1">${Object.entries(c.denominations).sort((a, b) => Number(b[0]) - Number(a[0])).map(([d, q]) => `<span class="soft-chip soft-success">${Number(d).toLocaleString("en-GB")} × ${q}</span>`).join("")}</div>` : '<p class="acc-muted-line mb-0">Not counted note by note.</p>';
    const alert = c.status === "returned" ? `<div class="alert alert-danger mb-3"><strong>Sent back:</strong> ${esc(c.return_reason)}</div>` : c.status === "counted" && can.counted_this ? '<div class="alert alert-info mb-3"><i class="ri-information-line me-1"></i>You counted this - a second person must confirm it.</div>' : "";
    const fact = (k, v) => (v ? `<div><span>${esc(k)}</span><strong>${v}</strong></div>` : "");
    const body = `${alert}
      <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-information-line"></i>The service</div><div class="acc-facts">${fact("Date", A.day(c.date))}${fact("Service", esc(c.title))}${fact("Attendance", c.attendance ? esc(c.attendance.name) : "")}${fact("Where it stands", pill(c.status))}${fact("Receipt", c.journal ? esc(c.journal.number) : "")}${fact("Banked", c.banked ? `${esc(c.banked.number)} · ${A.day(c.banked.date)}` : c.status === "posted" && c.cash_total > 0 ? "Not yet" : "")}</div></section>
      <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-list-check-2"></i>What was given</div>${lines}</section>
      <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-coins-line"></i>The notes and coins</div>${den}</section>
      <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-team-line"></i>Counted and confirmed</div><div class="acc-facts">${fact("Counted by", esc(c.counted_by || ""))}${fact("With", esc((c.witnesses || []).join(", ")))}${fact("Confirmed by", c.confirmed_by ? `${esc(c.confirmed_by)} · ${A.day(c.confirmed_at)}` : "Not yet")}${fact("Note", esc(c.notes || ""))}</div></section>`;
    const btn = (k, cls, icon, label) => `<button type="button" class="btn ${cls}" data-act="${k}"><i class="${icon} me-1"></i>${label}</button>`;
    const foot = [
      can.delete_this ? btn("delete", "btn-outline-danger me-auto", "ri-delete-bin-line", "Delete") : "",
      can.reverse_this ? btn("reverse", "btn-outline-danger me-auto", "ri-arrow-go-back-line", "Reverse") : "",
      btn("print", "btn-outline-primary", "ri-printer-line", "Collection sheet"),
      can.edit ? btn("edit", "btn-outline-primary", "ri-edit-line", "Change") : "",
      can.return_this ? btn("return", "btn-outline-danger", "ri-arrow-go-back-line", "Send back") : "",
      can.confirm_this ? btn("confirm", "btn-success", "ri-shield-check-line", "Confirm and receipt") : "",
      can.bank_this ? btn("bank", "btn-primary", "ri-bank-line", "Bank it") : "",
      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>',
    ].join("");
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="colWindow" tabindex="-1" aria-labelledby="colWindowTitle"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-hand-coin-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="colWindowTitle">${esc(c.title)}</h5><div class="app-modal-subtitle">${A.day(c.date)} · ${A.money(c.total)} · ${esc(STATUS[c.status].label)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">${body}</div><div class="modal-footer" id="colFoot">${foot}</div></div></div></div>`,
    );
    const el = document.getElementById("colWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    const again = () => {
      load();
      view(id);
    };
    el.querySelector("#colFoot").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-act]");
      if (!b) return;
      const act = b.dataset.act;
      if (act === "print") return sheet(c);
      if (act === "edit") {
        bootstrap.Modal.getInstance(el)?.hide();
        return countWindow(c);
      }
      if (act === "return") return W.reasonWindow({ title: "Send the count back", subtitle: "Whoever counted it checks and records it again", go: "Send back", placeholder: "e.g. Recount the tithe envelopes", run: (r) => API.returnCollection(c.id, r), onDone: again });
      if (act === "reverse") return W.reasonWindow({ title: "Reverse this collection", subtitle: "Its receipt is reversed in the books", go: "Reverse", run: (r) => API.reverseCollection(c.id, r), onDone: again });
      if (act === "bank") return bankWindow(c, again);
      if (act === "delete" && !confirm("Delete this count? Nothing was put in the books.")) return;
      UI.setButtonLoading(b, "...");
      const r = act === "confirm" ? await API.confirmCollection(c.id) : await API.deleteCollection(c.id);
      UI.restoreButton(b);
      if (!r.ok) return Toast.error(r.message);
      Toast.success(r.message);
      if (act === "delete") {
        bootstrap.Modal.getInstance(el)?.hide();
        return load();
      }
      again();
    });
  }

  async function bankWindow(c, onDone) {
    const o = await A.options();
    if (!o) return;
    const to = o.cash.filter((a) => a.cash_kind === "bank" || a.cash_kind === "mpesa");
    if (!to.length) return Toast.error("Add the church's bank account first - Cash & bank, Add an account.");
    const today = new Date().toISOString().slice(0, 10);
    const el = K.confirmWindow({
      title: "Bank the cash",
      subtitle: `${A.money(c.cash_total)} from ${c.cash_account.name}`,
      icon: "ri-bank-line",
      go: '<i class="ri-check-line me-1"></i>Record the deposit',
      body: K.parts([
        { icon: "ri-bank-line", title: "Into", body: W.cashTiles(to, to.find((a) => a.cash_kind === "bank")?.id || to[0].id, "bkTo") },
        { icon: "ri-calendar-line", title: "Deposited on, and the slip", body: `<div class="row g-2"><div class="col-sm-6"><input type="date" class="form-control" id="bkDate" value="${today}" max="${today}"></div><div class="col-sm-6"><input type="text" class="form-control" id="bkRef" maxlength="100" placeholder="Deposit slip number"></div><div class="col-12"><input type="text" inputmode="decimal" class="form-control text-end" id="bkAmount" value="${c.cash_total}" aria-label="Amount"></div><div class="col-12"><label class="budget-receipt-pick mb-0"><i class="ri-attachment-2"></i><span id="bkFileName">Photo of the deposit slip (optional)</span><input type="file" id="bkFile" accept="image/jpeg,image/png,image/webp,application/pdf" hidden></label></div></div>` },
      ]),
      run: async () => {
        const res = await API.bankCollection(c.id, { to_account_id: Number(document.querySelector('input[name="bkTo"]:checked').value), date: document.getElementById("bkDate").value, amount: n(document.getElementById("bkAmount").value), reference: document.getElementById("bkRef").value.trim() || null });
        const f = document.getElementById("bkFile").files[0];
        if (res.ok && f && res.data.banked) await API.addJournalFile(res.data.banked.id, f);
        if (res.ok) setTimeout(onDone, 300);
        return res;
      },
    });
    el.querySelector("#bkFile").addEventListener("change", (e) => (el.querySelector("#bkFileName").textContent = e.target.files[0]?.name || "Photo of the deposit slip (optional)"));
    if (window.DateField) DateField.enhance(el.querySelector("#bkDate"), { quick: ["today", "yesterday"] });
  }

  /** The collection sheet, signed by both counters. */
  function sheet(c) {
    const w = window.open("", "_blank");
    if (!w) return Toast.error("Allow pop-ups to print.");
    const den = c.denominations ? Object.entries(c.denominations).sort((a, b) => Number(b[0]) - Number(a[0])).map(([d, q]) => `<tr><td>${Number(d).toLocaleString("en-GB")}</td><td>${q}</td><td style="text-align:right">${A.amount(Number(d) * q)}</td></tr>`).join("") : "";
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Collection sheet</title><style>body{font-family:Inter,Arial,sans-serif;color:#0D0D0D;max-width:700px;margin:28px auto;padding:0 16px;font-size:13px}h1{font-size:18px;margin:0}h2{font-size:14px;margin:18px 0 6px}table{width:100%;border-collapse:collapse}td,th{padding:6px;border-bottom:1px solid #ddd;text-align:left}.tot td{font-weight:700;border-top:2px solid #0D0D0D}.sig{margin-top:44px;display:flex;justify-content:space-between;gap:16px}.sig div{border-top:1px solid #0D0D0D;padding-top:6px;flex:1}</style></head><body>
      <h1>Christian Church International - ${esc(c.place.name)}</h1><div>COLLECTION SHEET - <b>${esc(c.title)}</b>, ${A.day(c.date)}${c.journal ? ` · Receipt ${esc(c.journal.number)}` : ""}</div>
      <h2>What was given</h2><table><thead><tr><th>Kind</th><th>Fund</th><th style="text-align:right">Cash</th><th style="text-align:right">M-Pesa</th><th style="text-align:right">Total</th></tr></thead><tbody>${c.lines.map((l) => `<tr><td>${esc(l.label)}</td><td>${esc(l.fund?.name || "")}</td><td style="text-align:right">${A.amount(l.cash)}</td><td style="text-align:right">${A.amount(l.mpesa)}</td><td style="text-align:right">${A.amount(l.cash + l.mpesa)}</td></tr>`).join("")}<tr class="tot"><td>Total</td><td></td><td style="text-align:right">${A.amount(c.cash_total) || "0.00"}</td><td style="text-align:right">${A.amount(c.mpesa_total) || "0.00"}</td><td style="text-align:right">${A.amount(c.total)}</td></tr></tbody></table>
      ${den ? `<h2>Notes and coins</h2><table><thead><tr><th>Note / coin</th><th>How many</th><th style="text-align:right">KES</th></tr></thead><tbody>${den}</tbody></table>` : ""}
      <div class="sig"><div>Counted by: ${esc(c.counted_by || "")}</div><div>With: ${esc((c.witnesses || []).join(", "))}</div><div>Confirmed by: ${esc(c.confirmed_by || "")}</div></div>
      <script>window.onload=()=>window.print()<\/script></body></html>`);
    w.document.close();
  }

  function init() {
    $("countBtn")?.addEventListener("click", () => countWindow());
    $("waitList").addEventListener("click", (e) => {
      const b = e.target.closest("[data-open]");
      if (b) view(Number(b.dataset.open));
    });
    $("colRows").addEventListener("click", (e) => {
      if (e.target.closest("[data-first]")) return countWindow();
      if (e.target.closest("input, .pp-check")) return;
      const tr = e.target.closest("tr[data-id]");
      if (tr) view(Number(tr.dataset.id));
    });
    const open = new URLSearchParams(window.location.search).get("collection");
    if (open) view(Number(open));
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
