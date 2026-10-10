/**
 * ============================================================================
 * ACCOUNTING - Payroll (payroll.php, every level)
 * ============================================================================
 * The people a place pays and the monthly run (docs/specs/accounting-spec.md,
 * A7): start the month, check and change the draft (pay, allowances, any
 * other deduction - the churches don't deduct PAYE, NSSF, SHIF or the
 * Housing Levy), send it for approval (approved = posted), then pay the staff
 * with a voucher already authorised. Prints payslips; downloads the register.
 * ID numbers and KRA PINs only ever come masked.
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
  let tab = params.get("tab") === "people" ? "people" : "runs";

  const ST = {
    draft: ["secondary", "ri-draft-line", "Draft"],
    submitted: ["warning", "ri-time-line", "Waiting for approval"],
    returned: ["danger", "ri-arrow-go-back-line", "Sent back"],
    posted: ["primary", "ri-shield-check-line", "Approved - to pay"],
    paid: ["success", "ri-checkbox-circle-line", "Paid"],
    cancelled: ["secondary", "ri-close-line", "Cancelled"],
  };
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const monthLabel = (m) => new Date(`${m}-15T12:00:00`).toLocaleDateString("en-GB", { month: "long", year: "numeric" });
  const METHOD = { mpesa: "M-Pesa", bank: "Bank", cash: "Cash" };

  // ------------------------------------------------------------ the page

  function cards() {
    const active = data.employees.filter((e) => e.is_active);
    const last = data.runs.find((r) => r.status !== "cancelled");
    const toPay = data.runs.filter((r) => r.status === "posted");
    K.statRow($("statCardsRow"), [
      { icon: "ri-team-line", label: "On the payroll", sub: `${A.money(active.reduce((t, e) => t + e.gross, 0))} a month gross`, value: A.num(active.length), color: "success" },
      { icon: "ri-calendar-check-line", label: last ? last.label : "No run yet", sub: last ? ST[last.status][2] : "Start the first month", value: last ? A.figure(last.net) : "-", color: "primary" },
      { icon: "ri-bank-card-line", label: "Approved - to pay", sub: toPay.length ? `${toPay.length} ${toPay.length === 1 ? "run" : "runs"} waiting` : "Nothing waiting", value: A.figure(toPay.reduce((t, r) => t + r.net, 0)), color: "warning" },
      { icon: "ri-scissors-cut-line", label: "Other deductions", sub: last ? `SACCO, loans - ${last.label}` : "SACCO, loans", value: last ? A.figure(last.deductions) : "-", color: "purple" },
    ]);
    $("pyRunsFigure").textContent = data.runs.length ? `${data.runs.filter((r) => r.status === "paid").length} paid` : "None yet";
    $("pyPeopleFigure").textContent = `${active.length} active`;
    if ($("startLabel")) $("startLabel").textContent = `Start ${monthLabel(data.next_month)}`;
  }

  /** Started → Submitted → Approved → Paid, as far as each month has got. */
  const RUN_AT = { draft: 1, submitted: 2, returned: 1, posted: 3, paid: 4, cancelled: 1 };
  const runSteps = (r) => A.mini(["Started", "Submitted", "Approved", "Paid"], RUN_AT[r.status] ?? 0, { stop: r.status === "returned" ? "Sent back" : r.status === "cancelled" ? "Cancelled" : null });
  const monthTile = (ym) => {
    const d = new Date(`${ym}-01T12:00:00`);
    return `<span class="acc-month-tile"><small>${d.toLocaleDateString("en-GB", { month: "short" })}</small><strong>${String(d.getFullYear()).slice(2)}</strong></span>`;
  };

  function runs() {
    $("pyRunRows").innerHTML = data.runs.length
      ? data.runs
          .map(
            (r) => `<tr class="acc-row" data-id="${r.id}">
              <td><div class="d-flex align-items-center gap-3">${monthTile(r.month)}<div class="min-w-0"><div class="fw-semibold">${esc(r.label)}</div><div class="acc-sub"><i class="ri-group-line me-1"></i>${r.people} ${r.people === 1 ? "person" : "people"}</div></div></div></td>
              <td class="text-end d-none d-md-table-cell">${A.money(r.gross)}</td>
              <td class="text-end d-none d-lg-table-cell">${r.deductions ? A.money(r.deductions) : '<span class="acc-sub">None</span>'}</td>
              <td class="text-end"><strong>${A.money(r.net)}</strong></td>
              <td class="acc-steps-cell">${runSteps(r)}${r.can.decide ? '<span class="badge bg-warning text-dark mt-1"><i class="ri-flashlight-line me-1"></i>Your turn</span>' : ""}</td>
            </tr>`,
          )
          .join("")
      : `<tr><td colspan="5">${A.empty("ri-calendar-check-line", "No payroll yet", data.employees.length ? "Start the month - everyone's pay and deductions are worked out for you to check." : "Add the people this place pays first, then start the month.")}</td></tr>`;
  }

  function people() {
    $("pyPeopleRows").innerHTML = data.employees.length
      ? data.employees
          .map(
            (e) => `<tr class="${e.is_active ? "" : "opacity-50"}"><td><div class="fw-semibold">${esc(e.name)}${e.is_active ? "" : ' <span class="badge bg-secondary">Left</span>'}</div><div class="acc-sub">${esc(e.position || "")}${e.start_date ? ` · since ${A.day(e.start_date)}` : ""}</div></td><td class="d-none d-md-table-cell">${esc(e.pay_method_label)}<div class="acc-sub">${esc(e.pay_to || "")}</div></td><td class="d-none d-lg-table-cell">${esc(e.kra_pin || "-")}</td><td class="text-end"><strong>${A.money(e.gross)}</strong>${e.allowances.length ? `<div class="acc-sub">basic ${A.money(e.basic_pay)} + ${e.allowances.length} ${e.allowances.length === 1 ? "allowance" : "allowances"}</div>` : ""}</td><td class="text-end">${data.can.manage && !A.viewingBelow() ? `<button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-person="${e.id}" aria-label="Change"><i class="ri-edit-line"></i></button>` : ""}</td></tr>`,
          )
          .join("")
      : `<tr><td colspan="5">${A.empty("ri-team-line", "Nobody on the payroll yet", "Add the people this place pays - staff, a caretaker, someone paid an allowance.", data.can.manage && !A.viewingBelow() ? '<button type="button" class="btn btn-primary" data-firstperson><i class="ri-user-add-line me-1"></i>Add a person</button>' : "")}</td></tr>`;
  }

  function showTab() {
    document.querySelectorAll("#pyTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("pyRunsPane").hidden = tab !== "runs";
    $("pyPeoplePane").hidden = tab !== "people";
  }

  async function load() {
    A.ownOnly();
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("pyRunRows").innerHTML = UI.renderTableLoading(5);
    const res = await API.payroll();
    if (!res.ok) {
      $("pyRunsWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    runs();
    people();
    showTab();
  }

  // ------------------------------------------------------------ a person

  function personWindow(e = null) {
    const f = (id, label, v, type = "text", col = "col-sm-6", extra = "") => `<div class="${col}"><label class="form-label" for="${id}">${label}</label><input type="${type}" class="form-control" id="${id}" value="${esc(v ?? "")}"${extra}></div>`;
    const secret = (id, label, masked) => `<div class="col-sm-6"><label class="form-label" for="${id}">${label}</label><input type="text" class="form-control" id="${id}" maxlength="20" autocomplete="off" placeholder="${masked ? `Saved: ${esc(masked)} - type to replace` : "Not given"}"></div>`;
    const el = K.confirmWindow({
      title: e ? `Change ${e.name}` : "Add a person to the payroll",
      subtitle: "What they are paid a month and how",
      icon: "ri-user-add-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: "ri-user-line", title: "Who", body: `<div class="row g-2">${f("pnName", "Name", e?.name)}${f("pnPos", "Position", e?.position)}${f("pnPhone", "Phone", e?.phone, "tel")}${f("pnEmail", "Email", e?.email, "email")}${f("pnStart", "Started", e?.start_date, "date")}${f("pnEnd", "Left (if they have)", e?.end_date, "date")}</div>` },
        { icon: "ri-money-dollar-box-line", title: "Pay a month", body: `<div class="row g-2">${f("pnBasic", "Basic pay (KES)", e?.basic_pay || "", "text", "col-sm-6", ' inputmode="decimal"')}</div><div class="mt-2" id="pnAllow"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="pnAddAllow"><i class="ri-add-line me-1"></i>Add an allowance</button>` },
        { icon: "ri-bank-card-line", title: "Paid by", body: `<div class="row g-2"><div class="col-sm-4"><select class="form-select" id="pnMethod">${Object.entries(METHOD).map(([k, v]) => `<option value="${k}"${(e?.pay_method || "mpesa") === k ? " selected" : ""}>${v}</option>`).join("")}</select></div>${f("pnTo", "", e?.pay_to, "text", "col-sm-8", ' placeholder="M-Pesa number, or bank and account" aria-label="Paid to"').replace('<label class="form-label" for="pnTo"></label>', "")}</div>` },
        { icon: "ri-lock-line", title: "Private numbers", hint: "Kept encrypted; only the last four are ever shown", body: `<div class="row g-2">${secret("pnId", "ID number", e?.id_number)}${secret("pnKra", "KRA PIN", e?.kra_pin)}</div>${e ? `<label class="form-check form-switch mt-3 mb-0"><input class="form-check-input" type="checkbox" id="pnActive"${e.is_active ? " checked" : ""}><span class="form-check-label">On the payroll (off once they leave)</span></label>` : ""}` },
      ]),
      run: async () => {
        const v = (id) => el.querySelector(`#${id}`).value.trim() || null;
        const body = {
          name: v("pnName") || "",
          position: v("pnPos"),
          phone: v("pnPhone"),
          email: v("pnEmail"),
          start_date: v("pnStart"),
          end_date: v("pnEnd"),
          basic_pay: n(v("pnBasic")),
          allowances: [...el.querySelectorAll("[data-allow]")].map((x) => ({ name: x.querySelector("[data-an]").value.trim(), amount: n(x.querySelector("[data-aa]").value) })).filter((a) => a.name || a.amount),
          pay_method: el.querySelector("#pnMethod").value,
          pay_to: v("pnTo"),
          id_number: v("pnId"),
          kra_pin: v("pnKra"),
          ...(e ? { is_active: el.querySelector("#pnActive").checked } : {}),
        };
        const out = await API.saveEmployee(e?.id, body);
        if (out.ok) load();
        return out;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    const add = (a = {}) => {
      el.querySelector("#pnAllow").insertAdjacentHTML("beforeend", `<div class="row g-2 mb-2" data-allow><div class="col-7"><input type="text" class="form-control" data-an maxlength="60" placeholder="e.g. House" value="${esc(a.name || "")}"></div><div class="col-5"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" data-aa value="${a.amount || ""}"></div></div></div>`);
    };
    (e?.allowances || []).forEach(add);
    el.querySelector("#pnAddAllow").addEventListener("click", () => add());
    UI.enhanceSelect(el.querySelector("#pnMethod"), { search: false });
    if (window.DateField) ["#pnStart", "#pnEnd"].forEach((s) => DateField.enhance(el.querySelector(s), { quick: ["today"] }));
  }

  // ------------------------------------------------------------ the run

  async function startRun() {
    const out = await API.startRun(data.next_month);
    if (!out.ok) return Toast.error(out.message);
    Toast.success(out.message);
    await load();
    runWindow(out.data.id);
  }

  async function runWindow(id) {
    const res = await API.payrollRun(id);
    if (!res.ok) return Toast.error(res.message);
    const r = res.data;
    const c = r.can;
    const own = !A.viewingBelow();
    document.getElementById("pyWindow")?.remove();
    const tot = (k) => r.payslips.reduce((t, p) => t + p[k], 0);
    const grid = `<div class="table-responsive"><table class="table table-sm mb-0 acc-table"><thead><tr><th>Person</th><th class="text-end">Pay</th><th class="text-end">Other</th><th class="text-end">Net</th>${c.edit && own ? "<th></th>" : ""}</tr></thead><tbody>${r.payslips
      .map(
        (p) => `<tr><td><div class="fw-semibold">${esc(p.name)}</div><div class="acc-sub">${esc(p.position || "")}${p.allowances?.length ? ` · basic ${A.money(p.basic)} + allowances` : ""}</div></td><td class="text-end">${A.amount(p.gross)}</td><td class="text-end">${A.amount(p.other) || "-"}${p.other_note ? `<div class="acc-sub">${esc(p.other_note)}</div>` : ""}</td><td class="text-end fw-semibold">${A.amount(p.net)}</td>${c.edit && own ? `<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-slip="${p.id}" aria-label="Change ${esc(p.name)}"><i class="ri-edit-line"></i></button> <button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-unslip="${p.id}" aria-label="Take ${esc(p.name)} off"><i class="ri-close-line"></i></button></td>` : ""}</tr>`,
      )
      .join("")}<tr class="fw-semibold"><td>Total</td><td class="text-end">${A.amount(tot("gross"))}</td><td class="text-end">${A.amount(tot("other")) || "-"}</td><td class="text-end">${A.amount(tot("net"))}</td>${c.edit && own ? "<td></td>" : ""}</tr></tbody></table></div>`;
    const notIn = data.employees.filter((e) => e.is_active && !r.payslips.some((p) => p.employee_id === e.id));
    const addRow = c.edit && own && notIn.length ? `<div class="d-flex gap-2 mt-2"><select class="form-select" id="pyAddWho"><option value="">Add someone to this run</option>${notIn.map((e) => `<option value="${e.id}">${esc(e.name)}</option>`).join("")}</select><button type="button" class="btn btn-outline-primary" id="pyAddGo"><i class="ri-add-line"></i></button></div>` : "";
    const v = r.payment;
    const pays = ["posted", "paid"].includes(r.status)
      ? `<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-bank-card-line"></i>Paying the staff<small>One voucher, a line per person - already authorised; you pay it next</small></div><div class="acc-quote${v?.status === "paid" ? " is-chosen" : ""}"><div class="min-w-0 flex-fill"><div class="fw-semibold">Net pay</div><div class="acc-sub">${r.people} ${r.people === 1 ? "person" : "people"}${v ? ` · voucher ${esc(v.number)} (${esc(v.status)})` : ""}</div></div><strong>${A.money(r.net)}</strong>${v ? (v.status === "paid" ? '<span class="badge bg-success">Paid</span>' : `<a class="btn btn-sm btn-outline-primary" href="${A.link("payments.php", { voucher: v.id })}">Open</a>`) : c.pay && own ? '<button type="button" class="btn btn-sm btn-primary" data-pay><i class="ri-hand-coin-line me-1"></i>Pay the staff</button>' : ""}</div></section>`
      : "";
    const btn = (k, cls, icon, label) => `<button type="button" class="btn ${cls}" data-act="${k}"><i class="${icon} me-1"></i>${label}</button>`;
    const foot = [
      own && c.cancel ? btn("cancel", "btn-outline-danger me-auto", "ri-close-circle-line", "Cancel") : "",
      btn("print", "btn-outline-primary", "ri-file-pdf-2-line", "Payslips (PDF)"),
      btn("register", "btn-outline-primary", "ri-file-pdf-2-line", "Register (PDF)"),
      btn("csv", "btn-outline-primary", "ri-download-2-line", "Register"),
      own && ["posted", "paid"].includes(r.status) && c.pay ? btn("refs", "btn-outline-primary", "ri-hashtag", "References") : "",
      own && c.edit ? btn("recalculate", "btn-outline-secondary", "ri-refresh-line", "Recalculate") : "",
      own && c.submit ? btn("submit", "btn-primary", "ri-send-plane-line", "Send for approval") : "",
      c.decide ? btn("reject", "btn-outline-danger", "ri-close-line", "Reject") + btn("return", "btn-outline-warning", "ri-arrow-go-back-line", "Send back") + btn("approve", "btn-success", "ri-check-line", "Approve") : "",
      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>',
    ].join("");
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="pyWindow" tabindex="-1" aria-labelledby="pyTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-money-dollar-box-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="pyTitle">Payroll · ${esc(r.label)}</h5><div class="app-modal-subtitle">${r.people} ${r.people === 1 ? "person" : "people"} · net ${A.money(r.net)} · ${esc(r.status_label)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          ${r.status === "returned" && r.decision_note ? `<div class="alert alert-warning mb-3"><strong>Sent back:</strong> ${esc(r.decision_note)}</div>` : ""}
          <div class="acc-facts mb-3"><div><span>Pay</span><strong>${A.money(r.gross)}</strong></div><div><span>Other deductions</span><strong>${A.money(r.deductions)}</strong></div><div><span>Net pay</span><strong>${A.money(r.net)}</strong></div></div>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-file-list-3-line"></i>Payslips${c.edit ? "<small>Change pay or add a deduction (e.g. SACCO) with the pencil</small>" : ""}</div>${grid}${addRow}</section>
          ${pays}
          ${r.approval ? `<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Approval<small>${esc(r.approval.workflow || "")}</small></div>${A.approvalTimeline(r.approval)}</section>` : r.status === "submitted" ? '<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Approval</div><p class="mb-0">No rule covers it - anyone who authorises payments here (not who prepared it) approves it.</p></section>' : ""}
          ${c.decide ? '<section class="app-modal-part mb-0"><div class="app-modal-part-head"><i class="ri-chat-3-line"></i>Your decision<small>A comment is needed to send back or reject</small></div><textarea class="form-control" id="pyComment" rows="2" maxlength="500" placeholder="Comment"></textarea></section>' : ""}
        </div>
        <div class="modal-footer" id="pyFoot">${foot}</div>
      </div></div></div>`,
    );
    const el = document.getElementById("pyWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    if (el.querySelector("#pyAddWho")) UI.enhanceSelect(el.querySelector("#pyAddWho"), { search: true });
    const again = () => {
      bootstrap.Modal.getInstance(el)?.hide();
      load();
      setTimeout(() => runWindow(r.id), 350);
    };
    el.addEventListener("click", async (e) => {
      const slip = e.target.closest("[data-slip]");
      const unslip = e.target.closest("[data-unslip]");
      const pay = e.target.closest("[data-pay]");
      const act = e.target.closest("[data-act]")?.dataset.act;
      if (slip) return slipWindow(r, r.payslips.find((p) => p.id === Number(slip.dataset.slip)), again);
      if (unslip) {
        const p = r.payslips.find((x) => x.id === Number(unslip.dataset.unslip));
        if (!confirm(`Take ${p.name} off this month's payroll?`)) return;
        const out = await API.removeSlip(r.id, p.id);
        return out.ok ? again() : Toast.error(out.message);
      }
      if (e.target.closest("#pyAddGo")) {
        const who = Number(el.querySelector("#pyAddWho").value);
        if (!who) return;
        const out = await API.addSlip(r.id, who);
        return out.ok ? again() : Toast.error(out.message);
      }
      if (pay) return payWindow(r);
      if (!act) return;
      if (act === "print") return printSlips(r);
      if (act === "register") return A.pdf("accounting.payroll", { record_id: r.id }, `Payroll register ${r.label}`);
      if (act === "csv") return register(r);
      if (act === "refs") return referencesWindow(r, again);
      if (act === "cancel" && !confirm(`Cancel the payroll for ${r.label}?`)) return;
      const comment = el.querySelector("#pyComment")?.value.trim() || null;
      if ((act === "reject" || act === "return") && !comment) {
        el.querySelector("#pyComment").classList.add("is-invalid");
        return Toast.error("Say why.");
      }
      const b = e.target.closest("[data-act]");
      UI.setButtonLoading(b, "...");
      const out = await API.runAct(r.id, act, ["approve", "reject", "return"].includes(act) ? { comment } : undefined);
      UI.restoreButton(b);
      if (!out.ok) return Toast.error(out.message);
      Toast.success(out.message);
      again();
    });
  }

  function slipWindow(r, p, done) {
    const el = K.confirmWindow({
      title: `${p.name} - ${r.label}`,
      subtitle: "Change the pay, or add a deduction such as a SACCO or a loan",
      icon: "ri-edit-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: "ri-money-dollar-box-line", title: "Pay this month", body: `<div class="row g-2"><div class="col-sm-6"><label class="form-label" for="slBasic">Basic (KES)</label><input type="text" inputmode="decimal" class="form-control text-end" id="slBasic" value="${p.basic}"></div></div><div class="mt-2" id="slAllow">${(p.allowances || []).map((a) => `<div class="row g-2 mb-2" data-allow><div class="col-7"><input type="text" class="form-control" data-an value="${esc(a.name)}"></div><div class="col-5"><input type="text" inputmode="decimal" class="form-control text-end" data-aa value="${a.amount}"></div></div>`).join("")}</div>` },
        { icon: "ri-scissors-cut-line", title: "Deduction", hint: "e.g. a SACCO or a loan - held and paid on with a voucher", body: `<div class="row g-2"><div class="col-sm-4"><input type="text" inputmode="decimal" class="form-control text-end" id="slOther" value="${p.other || ""}" placeholder="0"></div><div class="col-sm-8"><input type="text" class="form-control" id="slNote" maxlength="150" value="${esc(p.other_note || "")}" placeholder="What it is"></div></div>` },
      ]),
      run: async () => {
        const v = (id) => el.querySelector(`#${id}`)?.value;
        const out = await API.saveSlip(r.id, p.id, {
          basic: n(v("slBasic")),
          allowances: [...el.querySelectorAll("#slAllow [data-allow]")].map((x) => ({ name: x.querySelector("[data-an]").value.trim(), amount: n(x.querySelector("[data-aa]").value) })),
          other: n(v("slOther")),
          other_note: v("slNote")?.trim() || null,
        });
        if (out.ok) done();
        return out;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
  }

  async function payWindow(r) {
    K.confirmWindow({
      title: `Pay the staff - ${r.label}`,
      subtitle: `${A.money(r.net)} to ${r.people} ${r.people === 1 ? "person" : "people"} - the voucher is already authorised; you pay it next`,
      icon: "ri-hand-coin-line",
      go: '<i class="ri-check-line me-1"></i>Make the voucher',
      body: K.parts([{ icon: "ri-bank-line", title: "Pay it from", body: W.cashTiles(data.cash, data.cash.find((a) => a.cash_kind === "bank")?.id || data.cash[0]?.id, "pyFrom") }]),
      run: async () => {
        const out = await API.payRun(r.id, Number(document.querySelector('input[name="pyFrom"]:checked')?.value));
        if (out.ok) setTimeout(() => (window.location.href = A.link("payments.php", { voucher: out.data.voucher_id })), 500);
        return out;
      },
    });
  }

  function referencesWindow(r, done) {
    const el = K.confirmWindow({
      title: `Payment references - ${r.label}`,
      subtitle: "The M-Pesa code or bank reference each person was paid with",
      icon: "ri-hashtag",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([{ icon: "ri-team-line", title: "Who was paid", body: r.payslips.map((p) => `<div class="row g-2 mb-2 align-items-center"><div class="col-6"><div class="fw-semibold">${esc(p.name)}</div><div class="acc-sub">${A.money(p.net)} · ${esc(METHOD[p.pay_method])} ${esc(p.pay_to || "")}</div></div><div class="col-6"><input type="text" class="form-control" data-ref="${p.id}" maxlength="60" value="${esc(p.reference || "")}" placeholder="e.g. QWE123ABC"></div></div>`).join("") }]),
      run: async () => {
        const refs = Object.fromEntries([...el.querySelectorAll("[data-ref]")].map((x) => [x.dataset.ref, x.value.trim() || null]));
        const out = await API.runReferences(r.id, refs);
        if (out.ok) done();
        return out;
      },
    });
  }

  // ------------------------------------------------------------ outputs

  /** Every payslip of the run, one to a page. */
  /** The month's payslips: the diocese PDF (accounting.payslips). */
  const printSlips = (r) => A.pdf("accounting.payslips", { record_id: r.id }, `Payslips ${r.label}`);
  /** The run register, as a CSV. */
  function register(r) {
    const cols = ["Name", "Position", "Paid by", "Paid to", "Basic", "Allowances", "Pay", "Deduction", "Deduction for", "Net", "Reference"];
    const q = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
    const lines = r.payslips.map((p) => [p.name, p.position, METHOD[p.pay_method], p.pay_to, p.basic, (p.allowances || []).reduce((t, a) => t + a.amount, 0), p.gross, p.other, p.other_note, p.net, p.reference].map(q).join(","));
    const blob = new Blob([[cols.map(q).join(","), ...lines].join("\n")], { type: "text/csv" });
    const a = Object.assign(document.createElement("a"), { href: URL.createObjectURL(blob), download: `payroll-${r.month}.csv` });
    a.click();
    URL.revokeObjectURL(a.href);
  }

  // ------------------------------------------------------------ start

  function init() {
    $("personBtn")?.addEventListener("click", () => personWindow());
    $("startBtn")?.addEventListener("click", startRun);
    $("pyRunRows").addEventListener("click", (e) => {
      const tr = e.target.closest("tr[data-id]");
      if (tr) window.location.href = A.link("record.php", { type: "payroll", id: tr.dataset.id });
    });
    $("pyPeopleRows").addEventListener("click", (e) => {
      if (e.target.closest("[data-firstperson]")) return personWindow();
      const b = e.target.closest("[data-person]");
      if (b) personWindow(data.employees.find((x) => x.id === Number(b.dataset.person)));
    });
    $("pyTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "runs" ? p.delete("tab") : p.set("tab", tab);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      showTab();
    });
    A.placePicker($("accPlacePick"), load);
    load().then(() => params.get("run") && runWindow(Number(params.get("run"))));
  }

  document.addEventListener("DOMContentLoaded", init);
})();
