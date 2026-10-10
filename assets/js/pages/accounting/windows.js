/**
 * ============================================================================
 * ACCOUNTING - the windows (docs/specs/accounting-spec.md)
 * ============================================================================
 * Every document is written in a window in the Record money look (numbered
 * parts, a live preview on the side, busy and done views):
 *   receipt()  - an official receipt: from whom, into which account, what for
 *   voucher()  - a payment voucher: to whom, from which account, what for
 *   transfer() - between two of our own accounts
 *   journal()  - balanced lines (opening balances, corrections)
 *   account()  - one of our own bank or M-Pesa accounts
 * and two view windows: viewJournal(id) and viewVoucher(id), with their
 * actions (authorise, send back, pay, reverse, files).
 * ============================================================================
 */
const AccountingWindows = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const API = AccountingAPI;
  const esc = A.esc;
  const ID = "accWindow";

  // ------------------------------------------------------------ the frame

  /**
   * Open a window. parts: [{n, title, body}] on the left, preview on the
   * right (optional). save() returns the API result; done(res) fills the
   * done view. Returns the element.
   */
  function open({ title, subtitle = "", icon, size = "modal-xl", parts, preview = "", save, saveLabel = "Save", done, danger = false, onReady }) {
    document.getElementById(ID)?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal att-modal acc-modal${danger ? " is-danger" : ""}" id="${ID}" tabindex="-1" aria-labelledby="${ID}Title">
        <div class="modal-dialog modal-dialog-centered ${size} modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon"><i class="${icon}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="${ID}Title">${esc(title)}</h5><div class="app-modal-subtitle">${esc(subtitle)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <div class="att-entry${preview ? "" : " acc-entry-full"}">
              <div class="att-entry-main">${parts.map((p, i) => `<section class="att-entry-section${i === parts.length - 1 ? " mb-0" : ""}"><div class="att-entry-title"><span>${i + 1}</span>${esc(p.title)}${p.hint ? `<small class="acc-part-hint">${esc(p.hint)}</small>` : ""}</div>${p.body}</section>`).join("")}</div>
              ${preview ? `<aside class="att-entry-preview acc-preview" aria-live="polite">${preview}</aside>` : ""}
            </div>
          </div>
          <div class="modal-footer"><div class="me-auto acc-foot-note" id="${ID}Note"></div><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn ${danger ? "btn-danger" : "btn-primary"}" id="${ID}Go"><i class="ri-check-line me-1"></i>${esc(saveLabel)}</button></div>
          <div class="app-modal-state is-busy-view" role="status"><div class="app-modal-spinner"></div><div class="fw-semibold">Saving...</div></div>
          <div class="app-modal-state is-done-view"><div class="app-modal-tick"><i class="ri-check-line"></i></div><div class="fs-5 fw-bold" id="${ID}DoneTitle">Done</div><div class="app-modal-facts" id="${ID}DoneFacts"></div><div class="d-flex flex-wrap justify-content-center gap-2" id="${ID}DoneActions"></div></div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById(ID);
    el.addEventListener("hidden.bs.modal", () => el.remove());
    onReady?.(el);
    bootstrap.Modal.getOrCreateInstance(el).show();
    el.querySelector(`#${ID}Go`).addEventListener("click", async () => {
      el.querySelectorAll(".is-invalid").forEach((x) => x.classList.remove("is-invalid"));
      el.classList.add("is-busy");
      const res = await save(el);
      el.classList.remove("is-busy");
      if (!res) return;
      if (!res.ok) {
        markErrors(el, res.errors);
        return Toast.error(res.message);
      }
      Toast.success(res.message);
      if (done) {
        const d = done(res);
        el.querySelector(`#${ID}DoneTitle`).textContent = d.title;
        el.querySelector(`#${ID}DoneFacts`).innerHTML = (d.facts || []).map(([k, v]) => `<div><span>${esc(k)}</span><strong>${v}</strong></div>`).join("");
        el.querySelector(`#${ID}DoneActions`).innerHTML = d.actions || '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Done</button>';
        d.wire?.(el);
        el.classList.add("is-done");
      } else {
        bootstrap.Modal.getInstance(el)?.hide();
      }
    });
    return el;
  }

  /** Server errors onto their fields: data-field="lines.0.amount" etc. */
  function markErrors(el, errors) {
    Object.keys(errors || {}).forEach((k) => {
      const f = el.querySelector(`[data-field="${k}"]`) || el.querySelector(`[data-field="${k.split(".")[0]}"]`);
      f?.classList.add("is-invalid");
    });
  }

  const close = () => bootstrap.Modal.getInstance(document.getElementById(ID))?.hide();
  const val = (el, sel) => (el.querySelector(sel)?.value || "").trim();
  const n = (v) => Math.round(parseFloat(String(v).replace(/[^0-9.]/g, "")) * 100) / 100 || 0;

  // ------------------------------------------------------------ pickers

  const option = (a, selected) => `<option value="${a.id}"${String(selected) === String(a.id) ? " selected" : ""}${a.cash_kind ? ` data-icon="${A.kind(a.cash_kind).icon}" data-color="${A.kind(a.cash_kind).color}"` : ""}>${esc(a.code)} · ${esc(a.name)}</option>`;
  const accountSelect = (list, selected, field, attrs = "") => `<select class="form-select" data-field="${field}" ${attrs}><option value="">Pick one</option>${list.map((a) => option(a, selected)).join("")}</select>`;
  const fundSelect = (funds, selected, field) =>
    `<select class="form-select" data-field="${field}" data-role="fund">${funds.map((f) => `<option value="${f.id}"${String(selected || "") === String(f.id) || (!selected && f.code === "GEN") ? " selected" : ""} data-color="${f.is_restricted ? "warning" : "success"}">${esc(f.name)}</option>`).join("")}</select>`;

  /** Big tiles to pick the money account (cash, bank, M-Pesa). */
  const cashTiles = (accounts, selected, name) =>
    `<div class="acc-tiles" role="radiogroup">${accounts
      .map((a, i) => {
        const k = A.kind(a.cash_kind);
        const on = selected ? String(selected) === String(a.id) : i === 0;
        return `<label class="acc-tile" style="--q: var(--${k.color}-rgb)"><input type="radio" name="${name}" value="${a.id}" data-kind="${a.cash_kind}"${on ? " checked" : ""}><span class="acc-tile-icon"><i class="${k.icon}"></i></span><span class="acc-tile-text"><strong>${esc(a.name)}</strong><small>${esc(k.label)}</small></span></label>`;
      })
      .join("")}</div>`;

  /** Lines: what a receipt is for / what a voucher pays for. */
  function linesBlock(kind) {
    return `<div class="acc-lines" data-lines="${kind}"></div><button type="button" class="btn btn-sm btn-outline-primary mt-2" data-add-line><i class="ri-add-line me-1"></i>Add another</button>`;
  }

  function lineRow(o, list, line = {}) {
    return `<div class="acc-line" data-line>
      <div class="acc-line-account">${accountSelect(list, line.account_id, "lines.account_id", 'data-role="account"')}</div>
      <div class="acc-line-fund">${fundSelect(o.funds, line.fund_id, "lines.fund_id")}</div>
      <div class="acc-line-amount"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end fw-semibold" data-role="amount" data-field="lines.amount" placeholder="0.00" value="${line.amount ? esc(line.amount) : ""}"></div></div>
      <div class="acc-line-memo"><input type="text" class="form-control" data-role="memo" maxlength="255" placeholder="Note (optional)" value="${esc(line.memo || line.description || "")}"></div>
      <button type="button" class="btn btn-icon btn-sm btn-outline-danger acc-line-x" data-remove-line aria-label="Remove this line"><i class="ri-close-line"></i></button>
    </div>`;
  }

  function wireLines(el, o, list, lines, onChange) {
    const box = el.querySelector("[data-lines]");
    const add = (line = {}) => {
      box.insertAdjacentHTML("beforeend", lineRow(o, list, line));
      const row = box.lastElementChild;
      row.querySelectorAll("select").forEach((s) => UI.enhanceSelect(s, { search: s.dataset.role === "account" }));
      row.querySelectorAll("select, input").forEach((x) => x.addEventListener(x.tagName === "SELECT" ? "change" : "input", onChange));
      sync();
    };
    const sync = () => {
      const rows = box.querySelectorAll("[data-line]");
      rows.forEach((r) => (r.querySelector("[data-remove-line]").hidden = rows.length < 2));
      onChange();
    };
    (lines.length ? lines : [{}]).forEach(add);
    el.querySelector("[data-add-line]").addEventListener("click", () => add());
    box.addEventListener("click", (e) => {
      const x = e.target.closest("[data-remove-line]");
      if (!x) return;
      x.closest("[data-line]").remove();
      sync();
    });
  }

  const readLines = (el) =>
    [...el.querySelectorAll("[data-line]")].map((r) => ({
      account_id: Number(r.querySelector('[data-role="account"]').value) || null,
      fund_id: Number(r.querySelector('[data-role="fund"]').value) || null,
      amount: n(r.querySelector('[data-role="amount"]').value),
      memo: r.querySelector('[data-role="memo"]').value.trim() || null,
      name: r.querySelector('[data-role="account"]').selectedOptions[0]?.textContent.split(" · ").slice(1).join(" · ") || "",
    }));

  function dateField(el, sel, quick = ["today", "yesterday", "lastSunday"]) {
    const input = el.querySelector(sel);
    if (window.DateField) DateField.enhance(input, { quick });
  }

  /** Files picked in the window, sent once the document exists. */
  const filePick = (label) => `<label class="budget-receipt-pick mb-0"><i class="ri-attachment-2"></i><span data-file-name>${esc(label)}</span><input type="file" data-file accept="image/jpeg,image/png,image/webp,application/pdf" multiple hidden></label>`;
  function wireFiles(el) {
    const input = el.querySelector("[data-file]");
    input?.addEventListener("change", () => {
      el.querySelector("[data-file-name]").textContent = input.files.length ? [...input.files].map((f) => f.name).join(", ") : "Attach files";
    });
  }
  async function sendFiles(el, send) {
    const files = [...(el.querySelector("[data-file]")?.files || [])];
    for (const f of files) {
      const r = await send(f);
      if (!r.ok) Toast.error(`${f.name}: ${r.message}`);
    }
  }

  // ------------------------------------------------------------ receipt

  async function receipt({ onDone, prefill = {} } = {}) {
    const o = await A.options();
    if (!o) return;
    const cash = o.cash;
    if (!cash.length) return Toast.error("Add a money account first.");
    const into = prefill.account_id || cash[0].id;
    const takeFrom = [...o.income, ...o.other];
    open({
      title: "Write a receipt",
      subtitle: `An official receipt for money ${o.place.name} received`,
      icon: "ri-bill-line",
      parts: [
        { title: "Received from", body: `<div class="row g-2"><div class="col-sm-7"><input type="text" class="form-control" data-field="party_name" id="rcFrom" maxlength="150" placeholder="Name, or e.g. Sunday service" value="${esc(prefill.party_name || "")}"></div><div class="col-sm-5"><input type="tel" class="form-control" id="rcPhone" maxlength="30" placeholder="Phone (optional)"></div></div>` },
        { title: "Into which account?", body: cashTiles(cash, into, "rcInto") },
        { title: "What was it for?", hint: "Several things on one receipt? Add a line for each", body: linesBlock("receipt") },
        {
          title: "When, and how?",
          body: `<div class="row g-2"><div class="col-sm-5"><input type="date" class="form-control" id="rcDate" data-field="date" value="${o.today}" max="${o.today}"></div><div class="col-sm-7"><input type="text" class="form-control" id="rcRef" maxlength="100" placeholder="M-Pesa code / cheque / slip no. (optional)"></div>
          <div class="col-12"><input type="text" class="form-control" id="rcNote" maxlength="255" placeholder="Note on the receipt (optional)"></div><div class="col-12">${filePick("Attach a photo or PDF (optional)")}</div></div>`,
        },
      ],
      preview: `<div class="att-preview-label">Receipt</div><div class="att-preview-what" id="rcPvFrom">-</div><div class="att-preview-total budget-fit-amount"><small>KES</small><span id="rcPvTotal">0.00</span></div><ul class="att-preview-list" id="rcPvList"></ul><div class="att-preview-compare" id="rcPvInto"></div>`,
      saveLabel: "Write receipt",
      onReady: (el) => {
        const upd = () => {
          const lines = readLines(el).filter((l) => l.amount > 0);
          const total = lines.reduce((s, l) => s + l.amount, 0);
          el.querySelector("#rcPvFrom").textContent = val(el, "#rcFrom") || "Who paid?";
          el.querySelector("#rcPvTotal").textContent = A.amount(total) || "0.00";
          el.querySelector("#rcPvList").innerHTML = lines.map((l) => `<li><span>${esc(l.name || "Pick what for")}</span><strong>${A.amount(l.amount)}</strong></li>`).join("");
          const acc = cash.find((a) => String(a.id) === el.querySelector('input[name="rcInto"]:checked')?.value);
          el.querySelector("#rcPvInto").innerHTML = acc ? `Goes into <strong>${esc(acc.name)}</strong>` : "";
        };
        wireLines(el, o, takeFrom, prefill.lines || [{ account_id: o.income.find((a) => a.code === "4000")?.id }], upd);
        el.querySelector("#rcFrom").addEventListener("input", upd);
        el.querySelectorAll('input[name="rcInto"]').forEach((r) => r.addEventListener("change", upd));
        dateField(el, "#rcDate");
        wireFiles(el);
        upd();
        setTimeout(() => el.querySelector("#rcFrom").focus(), 350);
      },
      save: async (el) => {
        const into = el.querySelector('input[name="rcInto"]:checked');
        const res = await API.receipt({
          date: val(el, "#rcDate"),
          account_id: Number(into?.value),
          party_name: val(el, "#rcFrom"),
          party_phone: val(el, "#rcPhone") || null,
          reference: val(el, "#rcRef") || null,
          narration: val(el, "#rcNote") || null,
          lines: readLines(el).filter((l) => l.account_id || l.amount).map(({ name, ...l }) => l),
        });
        if (res.ok) await sendFiles(el, (f) => API.addJournalFile(res.data.id, f));
        return res;
      },
      done: (res) => ({
        title: `Receipt ${res.data.number}`,
        facts: [["Received from", esc(res.data.party_name)], ["Amount", A.money(res.data.amount)], ["Date", A.day(res.data.date)]],
        actions: `<button type="button" class="btn btn-primary" data-again><i class="ri-add-line me-1"></i>Write another</button><button type="button" class="btn btn-outline-primary" data-view><i class="ri-eye-line me-1"></i>Open it</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Done</button>`,
        wire: (el) => {
          onDone?.(res.data);
          el.querySelector("[data-again]").onclick = () => receipt({ onDone });
          el.querySelector("[data-view]").onclick = () => viewJournal(res.data.id, { onChange: onDone });
        },
      }),
    });
  }

  // ------------------------------------------------------------ voucher

  async function voucher({ voucher: pv = null, onDone } = {}) {
    const o = await A.options();
    if (!o) return;
    const payFor = [...o.expense, ...o.other];
    const firstExpense = o.expense.find((a) => a.code === "5990")?.id;
    open({
      title: pv ? `Change ${pv.number}` : "Prepare a payment voucher",
      subtitle: pv ? "Saving sends it to be authorised again" : "Someone else authorises it before it is paid",
      icon: "ri-file-list-3-line",
      parts: [
        { title: "Pay to", body: `<div class="row g-2"><div class="col-sm-7"><input type="text" class="form-control" id="pvPayee" data-field="payee_name" maxlength="150" placeholder="Who is being paid?" value="${esc(pv?.payee_name || "")}"></div><div class="col-sm-5"><input type="tel" class="form-control" id="pvPhone" maxlength="30" placeholder="Phone (optional)" value="${esc(pv?.payee_phone || "")}"></div><div class="col-12"><input type="text" class="form-control" id="pvWhat" data-field="narration" maxlength="255" placeholder="What is it for? e.g. October electricity" value="${esc(pv?.narration || "")}"></div></div>` },
        { title: "Pay from which account?", body: cashTiles(o.cash, pv?.pay_from?.id || o.cash[0]?.id, "pvFrom") },
        { title: "Charge it to", hint: "The expense (or advance, bill...) it is for", body: linesBlock("voucher") },
        { title: "Date and papers", body: `<div class="row g-2"><div class="col-sm-5"><input type="date" class="form-control" id="pvDate" data-field="date" value="${esc(pv?.date || o.today)}"></div><div class="col-sm-7">${filePick("Attach the invoice or quote (optional)")}</div></div>` },
      ],
      preview: `<div class="att-preview-label">Payment voucher</div><div class="att-preview-what" id="pvPvTo">-</div><div class="att-preview-total budget-fit-amount"><small>KES</small><span id="pvPvTotal">0.00</span></div><ul class="att-preview-list" id="pvPvList"></ul><div class="att-preview-compare"><i class="ri-shield-check-line me-1"></i>Next: someone else authorises it, then it is paid.</div>`,
      saveLabel: pv ? "Save and send again" : "Prepare voucher",
      onReady: (el) => {
        const upd = () => {
          const lines = readLines(el).filter((l) => l.amount > 0);
          el.querySelector("#pvPvTo").textContent = val(el, "#pvPayee") || "To whom?";
          el.querySelector("#pvPvTotal").textContent = A.amount(lines.reduce((s, l) => s + l.amount, 0)) || "0.00";
          el.querySelector("#pvPvList").innerHTML = lines.map((l) => `<li><span>${esc(l.name || "Pick what for")}</span><strong>${A.amount(l.amount)}</strong></li>`).join("");
        };
        wireLines(el, o, payFor, pv ? pv.lines.map((l) => ({ account_id: l.account.id, fund_id: l.fund?.id, amount: l.amount, memo: l.description })) : [{ account_id: firstExpense }], upd);
        el.querySelector("#pvPayee").addEventListener("input", upd);
        dateField(el, "#pvDate", ["today", "yesterday"]);
        wireFiles(el);
        upd();
      },
      save: async (el) => {
        const res = await API.saveVoucher(pv?.id, {
          date: val(el, "#pvDate"),
          payee_name: val(el, "#pvPayee"),
          payee_phone: val(el, "#pvPhone") || null,
          narration: val(el, "#pvWhat"),
          pay_from_account_id: Number(el.querySelector('input[name="pvFrom"]:checked')?.value),
          lines: readLines(el).filter((l) => l.account_id || l.amount).map(({ name, memo, ...l }) => ({ ...l, description: memo })),
        });
        if (res.ok) await sendFiles(el, (f) => API.addVoucherFile(res.data.id, f));
        return res;
      },
      done: (res) => ({
        title: `${res.data.number} prepared`,
        facts: [["Pay to", esc(res.data.payee_name)], ["Amount", A.money(res.data.amount)], ["Now", A.voucherPill(res.data.status, true)]],
        actions: `<button type="button" class="btn btn-outline-primary" data-view><i class="ri-eye-line me-1"></i>Open it</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Done</button>`,
        wire: (el) => {
          onDone?.(res.data);
          el.querySelector("[data-view]").onclick = () => viewVoucher(res.data.id, { onChange: onDone });
        },
      }),
    });
  }

  // ------------------------------------------------------------ transfer

  async function transfer({ onDone, from = null, to = null } = {}) {
    const o = await A.options();
    if (!o) return;
    if (o.cash.length < 2) return Toast.info ? Toast.info("Add a bank or M-Pesa account first - Cash & bank, Add an account.") : Toast.error("Add a bank or M-Pesa account first.");
    const fromId = from || o.cash[0].id;
    const toId = to || (o.cash.find((a) => a.cash_kind === "bank") || o.cash[1]).id;
    open({
      title: "Move money between our accounts",
      subtitle: "Banking the Sunday cash, topping up petty cash, withdrawing - not income or spending",
      icon: "ri-arrow-left-right-line",
      size: "modal-lg",
      parts: [
        { title: "From", body: cashTiles(o.cash, fromId, "trFrom") },
        { title: "To", body: cashTiles(o.cash, toId, "trTo") },
        {
          title: "How much, and when?",
          body: `<div class="row g-2"><div class="col-sm-6"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end fw-semibold" id="trAmount" data-field="amount" placeholder="0.00"></div></div><div class="col-sm-6"><input type="date" class="form-control" id="trDate" data-field="date" value="${o.today}"></div>
          <div class="col-sm-6"><input type="text" class="form-control" id="trRef" maxlength="100" placeholder="Deposit slip / reference (optional)"></div><div class="col-sm-6"><input type="text" class="form-control" id="trNote" maxlength="255" placeholder="Note (optional)"></div><div class="col-12">${filePick("Attach the deposit slip (optional)")}</div></div>`,
        },
      ],
      saveLabel: "Record transfer",
      onReady: (el) => {
        dateField(el, "#trDate");
        wireFiles(el);
      },
      save: async (el) => {
        const res = await API.transfer({
          date: val(el, "#trDate"),
          from_account_id: Number(el.querySelector('input[name="trFrom"]:checked')?.value),
          to_account_id: Number(el.querySelector('input[name="trTo"]:checked')?.value),
          amount: n(val(el, "#trAmount")),
          reference: val(el, "#trRef") || null,
          narration: val(el, "#trNote") || null,
        });
        if (res.ok) await sendFiles(el, (f) => API.addJournalFile(res.data.id, f));
        return res;
      },
      done: (res) => ({ title: `Transfer ${res.data.number}`, facts: [["Amount", A.money(res.data.amount)], ["Date", A.day(res.data.date)]], wire: () => onDone?.(res.data) }),
    });
  }

  // ------------------------------------------------------------ journal voucher

  async function journal({ onDone, opening = false } = {}) {
    const o = await A.options();
    if (!o) return;
    const all = [...o.cash, ...o.income, ...o.expense, ...o.other].sort((a, b) => String(a.code).localeCompare(String(b.code)));
    const general = o.other.find((a) => a.code === "3000")?.id;
    const row = (l = {}) => `<div class="acc-jline" data-jline>
      <div class="acc-line-account">${accountSelect(all, l.account_id, "lines.account_id", 'data-role="account"')}</div>
      <div class="acc-line-fund">${fundSelect(o.funds, l.fund_id, "lines.fund_id")}</div>
      <input type="text" inputmode="decimal" class="form-control text-end" data-role="debit" placeholder="Debit" value="${l.debit || ""}">
      <input type="text" inputmode="decimal" class="form-control text-end" data-role="credit" placeholder="Credit" value="${l.credit || ""}">
      <button type="button" class="btn btn-icon btn-sm btn-outline-danger" data-remove-line aria-label="Remove this line"><i class="ri-close-line"></i></button>
    </div>`;
    open({
      title: opening ? "Enter opening balances" : "Post a journal",
      subtitle: opening ? "What each account held on the day you start - the difference goes to the General fund" : "Balanced lines: the debits must equal the credits",
      icon: "ri-book-2-line",
      parts: [
        { title: "Date and what it is for", body: `<div class="row g-2"><div class="col-sm-4"><input type="date" class="form-control" id="jvDate" data-field="date" value="${o.today}"></div><div class="col-sm-8"><input type="text" class="form-control" id="jvNote" data-field="narration" maxlength="255" placeholder="e.g. Opening balances on 1 Oct" value="${opening ? "Opening balances" : ""}"></div></div>` },
        {
          title: "Lines",
          hint: "Money in an account (cash, bank, an asset) is a debit",
          body: `<div class="acc-jhead"><span>Account</span><span>Fund</span><span>Debit</span><span>Credit</span><span></span></div><div data-jlines></div><button type="button" class="btn btn-sm btn-outline-primary mt-2" data-add-jline><i class="ri-add-line me-1"></i>Add a line</button>
          <div class="acc-jtotal" id="jvTotal"></div>`,
        },
      ],
      saveLabel: "Post journal",
      onReady: (el) => {
        const box = el.querySelector("[data-jlines]");
        const upd = () => {
          let d = 0;
          let c = 0;
          box.querySelectorAll("[data-jline]").forEach((r) => {
            d += n(r.querySelector('[data-role="debit"]').value);
            c += n(r.querySelector('[data-role="credit"]').value);
          });
          const diff = Math.round((d - c) * 100) / 100;
          el.querySelector("#jvTotal").innerHTML = `<span>Debits <strong>${A.amount(d) || "0.00"}</strong></span><span>Credits <strong>${A.amount(c) || "0.00"}</strong></span>${diff === 0 && d > 0 ? '<span class="badge bg-success">Balanced</span>' : `<span class="badge bg-danger">Off by ${A.amount(Math.abs(diff)) || "0.00"}</span>`}${opening && diff !== 0 && general ? '<button type="button" class="btn btn-sm btn-outline-primary" data-balance>Put the difference in the General fund</button>' : ""}`;
        };
        const add = (l) => {
          box.insertAdjacentHTML("beforeend", row(l));
          const r = box.lastElementChild;
          r.querySelectorAll("select").forEach((s) => UI.enhanceSelect(s, { search: s.dataset.role === "account" }));
          r.querySelectorAll("input").forEach((x) => x.addEventListener("input", upd));
          upd();
        };
        (opening ? [{ account_id: o.cash.find((a) => a.cash_kind === "cash")?.id }, ...o.cash.filter((a) => a.cash_kind !== "cash").map((a) => ({ account_id: a.id }))] : [{}, {}]).forEach(add);
        el.querySelector("[data-add-jline]").addEventListener("click", () => add());
        box.addEventListener("click", (e) => {
          const x = e.target.closest("[data-remove-line]");
          if (x && box.querySelectorAll("[data-jline]").length > 2) {
            x.closest("[data-jline]").remove();
            upd();
          }
        });
        el.querySelector("#jvTotal").addEventListener("click", (e) => {
          if (!e.target.closest("[data-balance]")) return;
          let d = 0;
          let c = 0;
          box.querySelectorAll("[data-jline]").forEach((r) => {
            d += n(r.querySelector('[data-role="debit"]').value);
            c += n(r.querySelector('[data-role="credit"]').value);
          });
          const diff = Math.round((d - c) * 100) / 100;
          add(diff > 0 ? { account_id: general, credit: diff } : { account_id: general, debit: -diff });
        });
        dateField(el, "#jvDate", ["today"]);
      },
      save: (el) =>
        API.journalVoucher({
          date: val(el, "#jvDate"),
          narration: val(el, "#jvNote"),
          lines: [...el.querySelectorAll("[data-jline]")]
            .map((r) => ({ account_id: Number(r.querySelector('[data-role="account"]').value) || null, fund_id: Number(r.querySelector('[data-role="fund"]').value) || null, debit: n(r.querySelector('[data-role="debit"]').value), credit: n(r.querySelector('[data-role="credit"]').value) }))
            .filter((l) => l.account_id && (l.debit || l.credit)),
        }),
      done: (res) => ({ title: `Journal ${res.data.number} posted`, facts: [["Total", A.money(res.data.amount)], ["Date", A.day(res.data.date)]], wire: () => onDone?.(res.data) }),
    });
  }

  // ------------------------------------------------------------ account

  function account({ account: acc = null, kind = "bank", onDone } = {}) {
    const k = acc?.cash_kind || kind;
    open({
      title: acc ? `Change ${acc.name}` : k === "mpesa" ? "Add an M-Pesa account" : k === "airtel" ? "Add an Airtel Money account" : "Add a bank account",
      subtitle: acc ? acc.code : "Only our books use it; it sits under the diocese's chart",
      icon: A.kind(k).icon,
      size: "modal-lg",
      parts: [
        ...(acc ? [] : [{ title: "What kind?", body: `<div class="mw-days" role="radiogroup"><label><input type="radio" name="acKind" value="bank"${k === "bank" ? " checked" : ""}><span><i class="ri-bank-line me-1"></i>Bank</span></label><label><input type="radio" name="acKind" value="mpesa"${k === "mpesa" ? " checked" : ""}><span class="d-inline-flex align-items-center gap-1">${A.methodLogo("mpesa", "xs")}M-Pesa</span></label><label><input type="radio" name="acKind" value="airtel"${k === "airtel" ? " checked" : ""}><span class="d-inline-flex align-items-center gap-1">${A.methodLogo("airtel", "xs")}Airtel Money</span></label></div>` }]),
        {
          title: "Details",
          body: `<div class="row g-2"><div class="col-12"><input type="text" class="form-control" id="acName" data-field="name" maxlength="150" placeholder="Name, e.g. Equity - Main account" value="${esc(acc?.name || "")}"></div>
          <div class="col-sm-6" data-for="bank"><input type="text" class="form-control" id="acBank" maxlength="100" placeholder="Bank" value="${esc(acc?.bank_name || "")}"></div>
          <div class="col-sm-6" data-for="bank"><input type="text" class="form-control" id="acBranch" maxlength="100" placeholder="Branch" value="${esc(acc?.branch || "")}"></div>
          <div class="col-sm-6" data-for="bank"><input type="text" class="form-control" id="acNo" maxlength="50" placeholder="Account number" value="${esc(acc?.account_number || "")}"></div>
          <div class="col-sm-6" data-for="mpesa airtel"><input type="text" class="form-control" id="acMpesa" maxlength="30" placeholder="Till, paybill or phone number" value="${esc(acc?.mpesa_number || "")}"></div>
          ${acc ? `<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="acActive"${acc.is_active ? " checked" : ""}><label class="form-check-label" for="acActive">In use</label></div></div>` : ""}</div>`,
        },
      ],
      saveLabel: acc ? "Save" : "Add account",
      onReady: (el) => {
        const show = () => {
          const kk = el.querySelector('input[name="acKind"]:checked')?.value || k;
          el.querySelectorAll("[data-for]").forEach((x) => (x.hidden = !x.dataset.for.split(" ").includes(kk)));
        };
        el.querySelectorAll('input[name="acKind"]').forEach((r) => r.addEventListener("change", show));
        show();
      },
      save: (el) =>
        API.saveAccount(acc?.id, {
          cash_kind: el.querySelector('input[name="acKind"]:checked')?.value || k,
          name: val(el, "#acName"),
          bank_name: val(el, "#acBank") || null,
          branch: val(el, "#acBranch") || null,
          account_number: val(el, "#acNo") || null,
          mpesa_number: val(el, "#acMpesa") || null,
          ...(acc ? { is_active: el.querySelector("#acActive").checked } : {}),
        }),
      done: (res) => ({ title: acc ? "Saved" : `${res.data.name} added`, facts: [["Code", esc(res.data.code)]], wire: () => onDone?.(res.data) }),
    });
  }

  // ------------------------------------------------------------ views

  /** A plain view window (no form): header band, body, footer buttons. */
  function viewFrame({ title, subtitle, icon, body, foot, hero, tone = "primary" }) {
    document.getElementById(ID)?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal acc-tone-${tone}" id="${ID}" tabindex="-1" aria-labelledby="${ID}Title"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="${icon}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="${ID}Title">${esc(title)}</h5><div class="app-modal-subtitle">${esc(subtitle)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body" id="${ID}Body">${hero ? heroStrip(hero) : ""}${body}</div>
        <div class="modal-footer" id="${ID}Foot">${foot}</div>
      </div></div></div>`,
    );
    const el = document.getElementById(ID);
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  /** The strip at the top of a view window: the amount in full, where it stands, and three facts. */
  const heroStrip = ({ amount, label = "Amount", status = "", facts = [], dir = "" }) =>
    `<div class="acc-hero"><div class="acc-hero-main"><span class="acc-hero-label">${esc(label)}</span><div class="acc-hero-amount${dir ? ` is-${dir}` : ""}">${A.figure(amount)}</div>${status ? `<div class="mt-2">${status}</div>` : ""}</div><div class="acc-hero-facts">${facts.filter(Boolean).map(([k, v]) => `<div><span>${esc(k)}</span><strong>${v || "-"}</strong></div>`).join("")}</div></div>`;

  const factGrid = (facts) => `<div class="acc-facts">${facts.filter(Boolean).map(([k, v]) => `<div><span>${esc(k)}</span><strong>${v || "-"}</strong></div>`).join("")}</div>`;
  const part = (icon, title, body, extra = "", tone = "") => `<section class="app-modal-part"${tone ? ` data-tone="${tone}"` : ""}><div class="app-modal-part-head"><i class="${icon}"></i>${esc(title)}${extra}</div>${body}</section>`;

  function filesPart(files, { canAdd, canRemove, label = "Receipts and papers" }) {
    const list = files.length
      ? `<div class="acc-files">${files.map((f) => `<div class="acc-file"><i class="${f.mime === "application/pdf" ? "ri-file-pdf-line text-danger" : "ri-image-line text-primary"}"></i><button type="button" class="btn btn-link p-0 text-start flex-fill" data-open-file="${f.id}">${esc(f.name)}</button>${canRemove ? `<button type="button" class="btn btn-icon btn-sm btn-outline-danger" data-remove-file="${f.id}" aria-label="Remove ${esc(f.name)}"><i class="ri-delete-bin-line"></i></button>` : ""}</div>`).join("")}</div>`
      : '<p class="mb-0 acc-muted-line">No files attached.</p>';
    const add = canAdd ? `<label class="btn btn-sm btn-outline-primary mt-2 mb-0"><i class="ri-attachment-2 me-1"></i>Attach a file<input type="file" data-add-file accept="image/jpeg,image/png,image/webp,application/pdf" hidden></label>` : "";
    return part("ri-attachment-2", label, list + add, "", "pink");
  }

  function wireFilesView(el, { add, remove, openUrl, reload }) {
    el.querySelector("[data-add-file]")?.addEventListener("change", async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      const r = await add(f);
      r.ok ? (Toast.success("Attached."), reload()) : Toast.error(r.message);
    });
    el.addEventListener("click", async (e) => {
      const o = e.target.closest("[data-open-file]");
      if (o) {
        const w = window.open("", "_blank");
        const u = await openUrl(Number(o.dataset.openFile));
        u ? (w.location = u) : (w.close(), Toast.error("That file could not be opened."));
        return;
      }
      const r = e.target.closest("[data-remove-file]");
      if (r && confirm("Remove this file?")) {
        const res = await remove(Number(r.dataset.removeFile));
        res.ok ? (Toast.success("Removed."), reload()) : Toast.error(res.message);
      }
    });
  }

  /** One posted document: its lines (debits and credits), files, and Reverse. */
  async function viewJournal(id, { onChange } = {}) {
    const res = await API.journal(id);
    if (!res.ok) return Toast.error(res.message);
    const j = res.data;
    const own = j.can.own;
    const lines = `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Account</th><th class="d-none d-sm-table-cell">Fund</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>${j.lines
      .map((l) => `<tr><td><span class="fw-semibold">${esc(l.account.name)}</span><div class="acc-sub">${esc(l.account.code)}${l.budget_line ? ` · Budget: ${esc(l.budget_line)}` : ""}${l.memo ? ` · ${esc(l.memo)}` : ""}</div></td><td class="d-none d-sm-table-cell">${l.fund ? `<span class="soft-chip soft-${l.fund.code === "GEN" ? "success" : "warning"}">${esc(l.fund.name)}</span>` : ""}</td><td class="text-end">${A.amount(l.debit)}</td><td class="text-end">${A.amount(l.credit)}</td></tr>`)
      .join("")}</tbody><tfoot><tr><th>Total</th><th class="d-none d-sm-table-cell"></th><th class="text-end">${A.amount(j.amount)}</th><th class="text-end">${A.amount(j.amount)}</th></tr></tfoot></table></div>`;
    const status =
      j.status === "reversed" ? `<div class="alert alert-danger d-flex gap-2 align-items-start mb-3"><i class="ri-arrow-go-back-line mt-1"></i><div>Reversed by <strong>${esc(j.reversed_by?.number)}</strong> on ${A.day(j.reversed_by?.date)}${j.reverse_reason ? `: ${esc(j.reverse_reason)}` : ""}.</div></div>` : j.reverses ? `<div class="alert alert-secondary mb-3">This reverses <strong>${esc(j.reverses.number)}</strong>.</div>` : "";
    const source = j.source === "budget_entry" ? '<div class="alert alert-info mb-3"><i class="ri-links-line me-1"></i>Recorded in Budgets - change or remove it there and the books follow.</div>' : j.voucher ? `<div class="alert alert-info mb-3"><i class="ri-file-list-3-line me-1"></i>The payment of voucher <strong>${esc(j.voucher)}</strong>.</div>` : "";
    const body =
      status +
      source +
      part("ri-information-line", "Details", factGrid([j.party_phone && ["Phone", esc(j.party_phone)], ["Reference", esc(j.reference)], ["Posted by", A.person(j.posted_by)], j.narration && ["Note", esc(j.narration)]]), "", "primary") +
      part("ri-scales-3-line", "In the books", lines, "", "purple") +
      filesPart(j.files, { canAdd: own && (j.can.receipt || j.can.journal || j.can.pay || j.can.prepare), canRemove: own && (j.can.receipt || j.can.journal) });
    const foot = `${j.can.reverse ? '<button type="button" class="btn btn-outline-danger me-auto" data-reverse><i class="ri-arrow-go-back-line me-1"></i>Reverse</button>' : ""}${j.doc_type === "receipt" ? '<button type="button" class="btn btn-outline-primary" data-print><i class="ri-file-pdf-2-line me-1"></i>Receipt (PDF)</button>' : ""}<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>`;
    const dir = { receipt: "in", payment: "out", bill: "out", payroll: "out" }[j.doc_type] || "";
    const hero = { amount: j.amount, dir, status: `${A.docPill(j.doc_type)}${j.status === "reversed" ? ` ${A.reversedChip()}` : ""}`, facts: [["Date", A.dateChip(j.date)], [j.doc_type === "payment" ? "Paid to" : j.doc_type === "receipt" ? "Received from" : "From", esc(j.party_name)], ["How", A.methodChip(j.method, j.method_label) || "-"]] };
    const el = viewFrame({ title: `${A.doc(j.doc_type).label} ${j.number}`, subtitle: j.place.name, icon: A.doc(j.doc_type).icon, body, foot, hero, tone: A.doc(j.doc_type).color });
    wireFilesView(el, { add: (f) => API.addJournalFile(j.id, f), remove: (m) => API.removeJournalFile(j.id, m), openUrl: (m) => API.journalFileUrl(j.id, m), reload: () => viewJournal(id, { onChange }) });
    el.querySelector("[data-print]")?.addEventListener("click", () => printReceipt(j));
    el.querySelector("[data-reverse]")?.addEventListener("click", () =>
      reasonWindow({
        title: `Reverse ${j.number}`,
        subtitle: "A mirror-image entry is posted today; the original stays, marked reversed",
        go: "Reverse it",
        run: (reason) => API.reverse(j.id, { reason }),
        onDone: () => {
          onChange?.();
          viewJournal(id, { onChange });
        },
      }),
    );
  }

  /** A short window asking why - used to reverse, send back. */
  function reasonWindow({ title, subtitle, go, run, onDone, placeholder = "Why?" }) {
    const el = PeopleKit.confirmWindow({
      title,
      subtitle,
      icon: "ri-arrow-go-back-line",
      danger: true,
      go: `<i class="ri-check-line me-1"></i>${esc(go)}`,
      body: PeopleKit.parts([{ icon: "ri-chat-3-line", title: "Reason", body: `<textarea class="form-control" id="accReason" rows="3" maxlength="255" placeholder="${esc(placeholder)}"></textarea>` }]),
      run: async () => {
        const res = await run(document.getElementById("accReason").value.trim());
        if (res.ok) setTimeout(() => onDone?.(res.data), 300);
        return res;
      },
    });
    setTimeout(() => el.querySelector("#accReason")?.focus(), 350);
  }

  /** The official receipt: the diocese PDF (accounting.receipt). */
  const printReceipt = (j) => A.pdf("accounting.receipt", { record_id: j.id }, `Receipt ${j.number}`);
  const HOW = { cash: "Cash", mpesa: "M-Pesa", airtel: "Airtel Money", bank: "Bank", cheque: "Cheque", card: "Card" };
  const how = (m) => HOW[m] || m || "";

  /** A voucher's journey: prepared, each approval stage (or "Authorised"), paid, in the books. */
  function voucherSteps(v) {
    const authorised = ["authorised", "paid"].includes(v.status);
    const steps = [{ title: "Prepared", state: "done", icon: "ri-edit-line", who: v.prepared_by, when: v.prepared_at }];
    if (v.approval) steps.push(...A.approvalSteps(v.approval));
    else if (v.status === "rejected") steps.push({ title: "Sent back", state: "stopped", who: v.rejected_by, when: v.rejected_at, note: v.reject_reason });
    else steps.push({ title: "Authorised", state: authorised ? "done" : v.status === "prepared" ? "now" : "next", icon: "ri-shield-check-line", who: v.authorised_by || (v.status === "prepared" ? "Waiting for the authoriser" : ""), when: v.authorised_at });
    if (v.status === "cancelled") steps.push({ title: "Cancelled", state: "stopped", note: "It will not be paid" });
    else {
      steps.push({ title: "Paid", state: v.status === "paid" ? "done" : v.status === "authorised" ? "now" : "next", icon: "ri-hand-coin-line", who: v.status === "paid" ? v.paid_by : v.status === "authorised" ? "Waiting for the treasurer" : "", when: v.paid_on });
      steps.push({ title: "In the books", state: v.journal_number ? "done" : "next", icon: "ri-book-2-line", who: v.journal_number || "" });
    }
    return steps;
  }

  /** What happens next on a voucher, in one sentence. */
  function voucherNext(v, actions = "") {
    const c = v.can;
    if (v.status === "paid") return A.nextCard({ tone: "done", title: `Paid on ${A.day(v.paid_on)}`, text: `${how(v.method)}${v.reference ? ` ${v.reference}` : ""} - posted as ${v.journal_number || "a payment"}. Nothing more to do.` });
    if (v.status === "cancelled") return A.nextCard({ tone: "stopped", title: "Cancelled", text: "This voucher will not be paid." });
    if (v.status === "rejected") return A.nextCard({ actions, tone: "stopped", title: `Sent back${v.rejected_by ? ` by ${v.rejected_by}` : ""}`, text: `${v.reject_reason || ""} Whoever prepared it can change it and send it again.`.trim() });
    if (v.status === "prepared") {
      const who = v.approval?.waiting_on?.join(", ");
      return c.authorise_this
        ? A.nextCard({ actions, tone: "mine", title: "Check it and approve", text: "Look at what it pays for and the invoice below, then approve it or send it back with a note." })
        : A.nextCard({ actions, tone: "wait", title: `Waiting for ${who || "the authoriser"}`, text: v.approval?.stage ? `Stage: ${v.approval.stage.name}.` : "Someone other than whoever prepared it must authorise it." });
    }
    return c.pay_this
      ? A.nextCard({ actions, tone: "mine", title: "Authorised - pay it", text: `Pay ${A.money(v.amount)} to ${v.payee_name} from ${v.pay_from?.name || "the account"}, then it posts to the books.` })
      : A.nextCard({ actions, tone: "wait", title: "Authorised - waiting to be paid", text: `The treasurer pays it from ${v.pay_from?.name || "the account"}.` });
  }

  /** A payment voucher: where it stands, what it pays for, and the next step. */
  async function viewVoucher(id, { onChange } = {}) {
    const res = await API.voucher(id);
    if (!res.ok) return Toast.error(res.message);
    const v = res.data;
    const c = v.can;
    const reload = () => {
      onChange?.();
      viewVoucher(id, { onChange });
    };
    const alert =
      v.status === "rejected" ? `<div class="alert alert-danger mb-3"><strong>Sent back:</strong> ${esc(v.reject_reason)}</div>` : v.status === "cancelled" ? '<div class="alert alert-secondary mb-3">Cancelled - it will not be paid.</div>' : c.own_voucher && v.status === "prepared" && c.authorise ? '<div class="alert alert-info mb-3"><i class="ri-information-line me-1"></i>You prepared this voucher, so someone else must authorise it.</div>' : "";
    const lines = `<div class="table-responsive"><table class="table acc-lines-table mb-0"><thead><tr><th>Charged to</th><th class="d-none d-sm-table-cell">Fund</th><th class="text-end">KES</th></tr></thead><tbody>${v.lines
      .map((l) => `<tr><td><span class="fw-semibold">${esc(l.account.name)}</span><div class="acc-sub">${esc(l.account.code)}${l.budget_line ? ` · Budget: ${esc(l.budget_line)}` : ""}${l.description ? ` · ${esc(l.description)}` : ""}</div></td><td class="d-none d-sm-table-cell">${l.fund ? `<span class="soft-chip soft-${l.fund.code === "GEN" ? "success" : "warning"}">${esc(l.fund.name)}</span>` : ""}</td><td class="text-end">${A.amount(l.amount)}</td></tr>`)
      .join("")}</tbody><tfoot><tr><th>Total</th><th class="d-none d-sm-table-cell"></th><th class="text-end">${A.amount(v.amount)}</th></tr></tfoot></table></div>`;
    const body =
      alert +
      part("ri-route-line", "Where it stands", A.journey(voucherSteps(v)) + voucherNext(v), v.approval?.workflow ? `<small>${esc(v.approval.workflow)}</small>` : "", "warning") +
      part("ri-information-line", "Details", factGrid([v.payee_phone && ["Phone", esc(v.payee_phone)], ["For", esc(v.narration)], v.authorise_note && ["Authoriser's note", esc(v.authorise_note)], v.status === "paid" && ["Paid by", `${A.methodChip(v.method)} ${esc(v.reference || "")}`], v.journal_number && ["In the books", esc(v.journal_number)]]), "", "primary") +
      part("ri-list-check-2", "What it pays for", lines, "", "purple") +
      filesPart(v.files, { canAdd: c.own && (c.prepare || c.pay || c.journal), canRemove: c.own && v.status !== "paid" && (c.prepare || c.pay), label: "Invoice, quote and receipt" });
    const btn = (key, cls, icon, label) => `<button type="button" class="btn ${cls}" data-act="${key}"><i class="${icon} me-1"></i>${label}</button>`;
    const foot = [
      c.cancel_this ? btn("cancel", "btn-outline-danger me-auto", "ri-close-circle-line", "Cancel voucher") : "",
      c.reverse_this ? btn("reverse", "btn-outline-danger me-auto", "ri-arrow-go-back-line", "Reverse payment") : "",
      c.edit ? btn("edit", "btn-outline-primary", "ri-edit-line", "Change") : "",
      c.reject_this ? btn("reject", "btn-outline-danger", "ri-arrow-go-back-line", "Send back") : "",
      c.authorise_this ? btn("authorise", "btn-success", "ri-shield-check-line", v.approval && v.approval.status === "pending" ? "Approve" : "Authorise") : "",
      c.pay_this ? btn("pay", "btn-primary", "ri-hand-coin-line", "Pay") : "",
      btn("pdf", "btn-outline-primary", "ri-file-pdf-2-line", "Voucher (PDF)"),
      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>',
    ].join("");
    const hero = { amount: v.amount, dir: "out", status: A.voucherPill(v.status, true), facts: [["Pay to", esc(v.payee_name)], ["Date", A.dateChip(v.date)], ["Pay from", A.accountChip(v.pay_from)]] };
    const el = viewFrame({ title: `Voucher ${v.number}`, subtitle: v.narration, icon: "ri-file-list-3-line", body, foot, hero, tone: A.VOUCHER[v.status]?.color || "primary" });
    wireFilesView(el, { add: (f) => API.addVoucherFile(v.id, f), remove: (m) => API.removeVoucherFile(v.id, m), openUrl: (m) => API.voucherFileUrl(v.id, m), reload: () => viewVoucher(id, { onChange }) });
    el.querySelector("#" + ID + "Foot").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-act]");
      if (!b) return;
      const act = b.dataset.act;
      if (act === "pdf") return A.pdf("accounting.voucher", { record_id: v.id }, `Voucher ${v.number}`);
      if (act === "edit") return voucher({ voucher: v, onDone: () => onChange?.() });
      if (act === "reject") return reasonWindow({ title: `Send ${v.number} back`, subtitle: "Say what needs fixing - whoever prepared it can change it and send it again", go: "Send back", placeholder: "e.g. Attach the invoice", run: (r) => API.reject(v.id, r), onDone: reload });
      if (act === "reverse") return reasonWindow({ title: `Reverse the payment on ${v.number}`, subtitle: "The money goes back into the account; the voucher can be paid again", go: "Reverse payment", run: (r) => API.reversePayment(v.id, r), onDone: reload });
      if (act === "pay") return payWindow(v, reload);
      if (act === "cancel" && !confirm(`Cancel ${v.number}? It will not be paid.`)) return;
      UI.setButtonLoading(b, "...");
      const r = act === "authorise" ? await API.authorise(v.id) : await API.cancelVoucher(v.id);
      UI.restoreButton(b);
      r.ok ? (Toast.success(r.message), reload()) : Toast.error(r.message);
    });
  }

  function payWindow(v, onDone) {
    const today = new Date().toISOString().slice(0, 10);
    const def = { cash: "cash", petty_cash: "cash", bank: "bank", mpesa: "mpesa", airtel: "airtel" }[v.pay_from?.kind] || "cash";
    const el = PeopleKit.confirmWindow({
      title: `Pay ${v.number}`,
      subtitle: `${A.money(v.amount)} to ${v.payee_name} from ${v.pay_from?.name || ""}`,
      icon: "ri-hand-coin-line",
      go: '<i class="ri-check-line me-1"></i>Pay and post',
      body: PeopleKit.parts([
        { icon: "ri-calendar-line", title: "Paid on", body: `<input type="date" class="form-control" id="payOn" value="${today}" max="${today}">` },
        { icon: "ri-bank-card-line", title: "How", body: `<div class="mw-days" role="radiogroup">${[["cash", "Cash"], ["mpesa", "M-Pesa"], ["airtel", "Airtel Money"], ["bank", "Bank"], ["cheque", "Cheque"]].map(([k, l]) => `<label><input type="radio" name="payHow" value="${k}"${k === def ? " checked" : ""}><span>${l}</span></label>`).join("")}</div><input type="text" class="form-control mt-2" id="payRef" maxlength="100" placeholder="M-Pesa code / cheque no. / bank reference">` },
      ]),
      run: async () => {
        const res = await API.pay(v.id, { paid_on: document.getElementById("payOn").value, method: document.querySelector('input[name="payHow"]:checked').value, reference: document.getElementById("payRef").value.trim() || null });
        if (res.ok) setTimeout(onDone, 300);
        return res;
      },
    });
    if (window.DateField) DateField.enhance(el.querySelector("#payOn"), { quick: ["today", "yesterday"] });
  }

  // ------------------------------------------------------------ A2: count cash

  const NOTES = ["1000", "500", "200", "100", "50"];
  const COINS = ["40", "20", "10", "5", "1"];

  /**
   * Count the cash: the notes and coins add up live against the book. If it
   * agrees, it is recorded as balanced; if not, say why - someone else approves.
   * accounts: [{id, name, cash_kind, balance}] (cash and petty cash only).
   */
  function countCash({ accounts, accountId = null, onDone }) {
    const boxes = accounts.filter((a) => a.cash_kind === "cash" || a.cash_kind === "petty_cash");
    if (!boxes.length) return Toast.error("No cash accounts to count.");
    const today = new Date().toISOString().slice(0, 10);
    const grid = (list, label) => `<div class="acc-den-group"><div class="acc-den-head">${label}</div>${list.map((d) => `<label class="acc-den"><span class="acc-den-face">${Number(d).toLocaleString("en-GB")}</span><span class="acc-den-x">×</span><input type="number" min="0" step="1" inputmode="numeric" class="form-control" data-den="${d}" placeholder="0"><span class="acc-den-sum" data-sum="${d}">-</span></label>`).join("")}</div>`;
    open({
      title: "Count the cash",
      subtitle: "Count every note and coin - it is checked against the book",
      icon: "ri-calculator-line",
      parts: [
        { title: "Which cash?", body: cashTiles(boxes.map((a) => ({ ...a, name: a.name })), accountId || boxes[0].id, "ccBox") },
        { title: "When", body: `<div class="row g-2 align-items-center"><div class="col-sm-6"><input type="date" class="form-control" id="ccDate" data-field="counted_on" value="${today}" max="${today}"></div><div class="col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="ccSurprise"><label class="form-check-label" for="ccSurprise">A surprise count</label></div></div></div>` },
        { title: "Notes and coins", hint: "How many of each", body: `<div class="acc-den-grid">${grid(NOTES, "Notes")}${grid(COINS, "Coins")}</div>` },
        { title: "If it doesn't agree", body: `<textarea class="form-control" id="ccReason" data-field="reason" rows="2" maxlength="255" placeholder="Why is it different? e.g. Change given twice on Sunday"></textarea>` },
      ],
      preview: `<div class="att-preview-label">Count</div><div class="att-preview-what" id="ccPvBox">-</div><div class="att-preview-total budget-fit-amount"><small>KES</small><span id="ccPvTotal">0</span></div><ul class="att-preview-list" id="ccPvList"></ul><div class="acc-cc-verdict" id="ccVerdict"></div>`,
      saveLabel: "Record the count",
      onReady: (el) => {
        // The book on the day counted (not today's), fetched when the cash or the date changes.
        const books = {};
        let pending = null;
        const bookOn = (id, date) => {
          const key = `${id}:${date}`;
          if (books[key] !== undefined) return books[key];
          if (pending !== key) {
            pending = key;
            API.cashbook({ account_id: id, from: date, to: date }).then((r) => {
              books[key] = r.ok ? r.data.closing : null;
              upd();
            });
          }
          return null;
        };
        const upd = () => {
          let total = 0;
          el.querySelectorAll("[data-den]").forEach((i) => {
            const v = Math.max(0, parseInt(i.value || "0", 10) || 0) * Number(i.dataset.den);
            total += v;
            el.querySelector(`[data-sum="${i.dataset.den}"]`).textContent = v ? v.toLocaleString("en-GB") : "-";
          });
          const box = boxes.find((a) => String(a.id) === el.querySelector('input[name="ccBox"]:checked')?.value);
          const fetched = box ? bookOn(box.id, el.querySelector("#ccDate").value) : null;
          const book = fetched ?? Number(box?.balance || 0);
          const diff = Math.round((total - book) * 100) / 100;
          el.querySelector("#ccPvBox").textContent = box?.name || "-";
          el.querySelector("#ccPvTotal").textContent = total.toLocaleString("en-GB");
          el.querySelector("#ccPvList").innerHTML = `<li><span>The book says${fetched === null ? " (checking...)" : ""}</span><strong>${A.amount(book) || "0.00"}</strong></li><li><span>Counted</span><strong>${A.amount(total) || "0.00"}</strong></li>`;
          el.querySelector("#ccVerdict").innerHTML = diff === 0 ? '<span class="badge bg-success"><i class="ri-check-line me-1"></i>Agrees with the book</span>' : `<span class="badge bg-${diff < 0 ? "danger" : "warning text-dark"}">${diff < 0 ? "Short" : "Over"} by ${A.money(Math.abs(diff))}</span><div class="acc-sub mt-1">Say why - someone else approves it.</div>`;
          el.querySelector("#ccReason").closest(".att-entry-section").hidden = diff === 0;
        };
        el.querySelectorAll("[data-den]").forEach((i) => i.addEventListener("input", upd));
        el.querySelectorAll('input[name="ccBox"]').forEach((r) => r.addEventListener("change", upd));
        el.querySelector("#ccDate").addEventListener("change", upd);
        dateField(el, "#ccDate", ["today", "yesterday", "lastSunday"]);
        upd();
      },
      save: (el) => {
        const denominations = {};
        el.querySelectorAll("[data-den]").forEach((i) => {
          const v = parseInt(i.value || "0", 10) || 0;
          if (v > 0) denominations[i.dataset.den] = v;
        });
        return API.countCash({
          account_id: Number(el.querySelector('input[name="ccBox"]:checked')?.value),
          counted_on: val(el, "#ccDate"),
          denominations,
          counted_total: Object.keys(denominations).length ? undefined : 0,
          reason: val(el, "#ccReason") || null,
          is_surprise: el.querySelector("#ccSurprise").checked,
        });
      },
      done: (res) => ({
        title: res.data.status === "balanced" ? "It agrees with the book" : "Counted - waiting for approval",
        facts: [["Counted", A.money(res.data.counted)], ["The book", A.money(res.data.book)], ["Difference", res.data.difference ? A.money(res.data.difference, { sign: true }) : "None"]],
        wire: () => onDone?.(res.data),
      }),
    });
  }

  // ------------------------------------------------------------ A2: petty cash

  async function pettySpend({ onDone } = {}) {
    const o = await A.options();
    if (!o) return;
    const st = await API.petty();
    if (!st.ok) return Toast.error(st.message);
    open({
      title: "Spend from petty cash",
      subtitle: `A petty cash voucher - ${A.money(st.data.balance)} in the box`,
      icon: "ri-wallet-3-line",
      parts: [
        { title: "Paid to", body: `<div class="row g-2"><div class="col-sm-7"><input type="text" class="form-control" id="pcPayee" data-field="payee" maxlength="150" placeholder="Who was paid? e.g. Mama Duka"></div><div class="col-sm-5"><input type="date" class="form-control" id="pcDate" data-field="date" value="${o.today}" max="${o.today}"></div><div class="col-12"><input type="text" class="form-control" id="pcNote" maxlength="255" placeholder="What for? (optional)"></div></div>` },
        { title: "What was bought", body: linesBlock("petty") },
        { title: "Receipt", body: filePick("Attach the receipt (photo or PDF)") },
      ],
      preview: `<div class="att-preview-label">Petty cash voucher</div><div class="att-preview-what" id="pcPvTo">-</div><div class="att-preview-total budget-fit-amount"><small>KES</small><span id="pcPvTotal">0.00</span></div><ul class="att-preview-list" id="pcPvList"></ul><div class="att-preview-compare" id="pcPvLeft"></div>`,
      saveLabel: "Write voucher",
      onReady: (el) => {
        const upd = () => {
          const lines = readLines(el).filter((l) => l.amount > 0);
          const total = lines.reduce((t, l) => t + l.amount, 0);
          el.querySelector("#pcPvTo").textContent = val(el, "#pcPayee") || "To whom?";
          el.querySelector("#pcPvTotal").textContent = A.amount(total) || "0.00";
          el.querySelector("#pcPvList").innerHTML = lines.map((l) => `<li><span>${esc(l.name || "Pick what for")}</span><strong>${A.amount(l.amount)}</strong></li>`).join("");
          const left = st.data.balance - total;
          el.querySelector("#pcPvLeft").innerHTML = left < 0 ? `<span class="text-danger fw-semibold">Only ${A.money(st.data.balance)} in the box</span>` : `Leaves <strong>${A.money(left)}</strong> in the box`;
        };
        wireLines(el, o, o.expense, [{ account_id: o.expense.find((a) => a.code === "5500")?.id }], upd);
        el.querySelector("#pcPayee").addEventListener("input", upd);
        dateField(el, "#pcDate", ["today", "yesterday"]);
        wireFiles(el);
        upd();
      },
      save: async (el) => {
        const res = await API.pettySpend({ date: val(el, "#pcDate"), payee: val(el, "#pcPayee"), narration: val(el, "#pcNote") || null, lines: readLines(el).filter((l) => l.account_id || l.amount).map(({ name, ...l }) => l) });
        if (res.ok) await sendFiles(el, (f) => API.addJournalFile(res.data.id, f));
        return res;
      },
      done: (res) => ({ title: `Voucher ${res.data.number}`, facts: [["Paid to", esc(res.data.party_name)], ["Amount", A.money(res.data.amount)]], wire: () => onDone?.(res.data) }),
    });
  }

  async function setFloat({ onDone } = {}) {
    const st = await API.petty();
    if (!st.ok) return Toast.error(st.message);
    const d = st.data;
    const el = PeopleKit.confirmWindow({
      title: "Petty cash float",
      subtitle: "A fixed amount kept for small spends, topped back up for exactly what was spent",
      icon: "ri-wallet-3-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: PeopleKit.parts([
        { icon: "ri-money-dollar-circle-line", title: "The float", body: `<div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end fw-semibold" id="pfFloat" value="${d.float ?? 5000}"></div>` },
        { icon: "ri-user-line", title: "Kept by", body: `<select class="form-select" id="pfWho"><option value="">Nobody named</option>${d.people.map((p) => `<option value="${p.id}"${d.custodian?.id === p.id ? " selected" : ""}>${esc(p.name)}</option>`).join("")}</select>` },
      ]),
      run: async () => {
        const res = await API.setFloat({ imprest_float: n(document.getElementById("pfFloat").value), custodian_id: Number(document.getElementById("pfWho").value) || null });
        if (res.ok) setTimeout(() => onDone?.(res.data), 300);
        return res;
      },
    });
    UI.enhanceSelect(el.querySelector("#pfWho"));
  }

  async function topUp({ onDone } = {}) {
    const [st, o] = await Promise.all([API.petty(), A.options()]);
    if (!st.ok) return Toast.error(st.message);
    const d = st.data;
    const from = o.cash.filter((a) => a.cash_kind !== "petty_cash");
    PeopleKit.confirmWindow({
      title: "Top up petty cash",
      subtitle: `Back to its float of ${A.money(d.float)}: ${A.money(d.top_up)} for ${d.vouchers_since} vouchers`,
      icon: "ri-refresh-line",
      go: '<i class="ri-check-line me-1"></i>Prepare the voucher',
      body: PeopleKit.parts([{ icon: "ri-bank-line", title: "Pay it from", hint: "A payment voucher is prepared - it is authorised and paid like any other", body: cashTiles(from, from.find((a) => a.cash_kind === "bank")?.id || from[0]?.id, "tuFrom") }]),
      run: async () => {
        const res = await API.topUp(Number(document.querySelector('input[name="tuFrom"]:checked')?.value));
        if (res.ok) setTimeout(() => onDone?.(res.data), 300);
        return res;
      },
    });
  }

  return { receipt, voucher, transfer, journal, account, viewJournal, viewVoucher, voucherSteps, voucherNext, viewFrame, heroStrip, part, factGrid, filesPart, close, countCash, pettySpend, setFloat, topUp, reasonWindow, cashTiles };
})();

window.AccountingWindows = AccountingWindows;
