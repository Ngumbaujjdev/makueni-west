/**
 * ============================================================================
 * ACCOUNTING - Payroll (payroll.php, every level)
 * ============================================================================
 * The people a place pays and the monthly run (docs/specs/accounting-spec.md,
 * A7): start the month (payslips worked out with the rates in Settings),
 * check and change the draft, send it for approval (approved = posted), then
 * pay net pay and remit each authority - vouchers already authorised. Prints
 * payslips; downloads the register. Personal numbers only ever come masked.
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
  let tab = ["people", "rates"].includes(params.get("tab")) ? params.get("tab") : "runs";

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
      { icon: "ri-team-line", label: "On the payroll", sub: `${A.short(active.reduce((t, e) => t + e.gross, 0))} a month gross`, value: A.num(active.length), color: "success" },
      { icon: "ri-calendar-check-line", label: last ? last.label : "No run yet", sub: last ? ST[last.status][2] : "Start the first month", value: last ? A.short(last.net) : "-", color: "primary" },
      { icon: "ri-bank-card-line", label: "Approved - to pay", sub: toPay.length ? `${toPay.length} ${toPay.length === 1 ? "run" : "runs"} not fully paid` : "Nothing waiting", value: A.short(toPay.reduce((t, r) => t + r.net + r.deductions + r.employer, 0)), color: "warning" },
      { icon: "ri-building-line", label: "Employer's share", sub: last ? `NSSF and Housing Levy, ${last.label}` : "NSSF and Housing Levy", value: last ? A.short(last.employer) : "-", color: "purple" },
    ]);
    $("pyRunsFigure").textContent = data.runs.length ? `${data.runs.filter((r) => r.status === "paid").length} paid` : "None yet";
    $("pyPeopleFigure").textContent = `${active.length} active`;
    if ($("startLabel")) $("startLabel").textContent = `Start ${monthLabel(data.next_month)}`;
  }

  function runs() {
    $("pyRunRows").innerHTML = data.runs.length
      ? data.runs
          .map((r) => `<tr class="acc-row" data-id="${r.id}"><td><div class="fw-semibold">${esc(r.label)}</div><div class="acc-sub">${r.people} ${r.people === 1 ? "person" : "people"}</div></td><td class="text-end d-none d-md-table-cell">${A.money(r.gross)}</td><td class="text-end d-none d-lg-table-cell">${A.money(r.deductions)}</td><td class="text-end"><strong>${A.money(r.net)}</strong></td><td>${pill(r.status)}${r.can.decide ? ' <span class="badge bg-success">Your turn</span>' : ""}</td></tr>`)
          .join("")
      : `<tr><td colspan="5">${A.empty("ri-calendar-check-line", "No payroll yet", data.employees.length ? "Start the month - everyone's pay and deductions are worked out for you to check." : "Add the people this place pays first, then start the month.")}</td></tr>`;
  }

  function people() {
    $("pyPeopleRows").innerHTML = data.employees.length
      ? data.employees
          .map(
            (e) => `<tr class="${e.is_active ? "" : "opacity-50"}"><td><div class="fw-semibold">${esc(e.name)}${e.is_active ? "" : ' <span class="badge bg-secondary">Left</span>'}${e.statutory ? "" : ' <span class="soft-chip soft-warning ms-1">No deductions</span>'}</div><div class="acc-sub">${esc(e.position || "")}${e.start_date ? ` · since ${A.day(e.start_date)}` : ""}</div></td><td class="d-none d-md-table-cell">${esc(e.pay_method_label)}<div class="acc-sub">${esc(e.pay_to || "")}</div></td><td class="d-none d-lg-table-cell">${esc(e.kra_pin || "-")}</td><td class="text-end"><strong>${A.money(e.gross)}</strong>${e.allowances.length ? `<div class="acc-sub">basic ${A.short(e.basic_pay)} + ${e.allowances.length} ${e.allowances.length === 1 ? "allowance" : "allowances"}</div>` : ""}</td><td class="text-end">${data.can.manage && !A.viewingBelow() ? `<button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-person="${e.id}" aria-label="Change"><i class="ri-edit-line"></i></button>` : ""}</td></tr>`,
          )
          .join("")
      : `<tr><td colspan="5">${A.empty("ri-team-line", "Nobody on the payroll yet", "Add the people this place pays - staff, a caretaker, someone paid an allowance.", data.can.manage && !A.viewingBelow() ? '<button type="button" class="btn btn-primary" data-firstperson><i class="ri-user-add-line me-1"></i>Add a person</button>' : "")}</td></tr>`;
  }

  function rates() {
    const r = data.rates;
    const band = (from, to, rate) => `<tr><td>${from ? `${A.num(from + 1)} - ` : "Up to "}${to ? A.num(to) : "and above"}</td><td class="text-end fw-semibold">${rate}%</td></tr>`;
    $("pyRates").innerHTML = `<div class="row g-3">
      <div class="col-lg-6"><div class="app-modal-part-head"><i class="ri-government-line"></i>PAYE (a month)</div><table class="table table-sm acc-table mb-2"><tbody>${band(0, r.paye_upto_1, r.paye_rate_1)}${band(r.paye_upto_1, r.paye_upto_2, r.paye_rate_2)}${band(r.paye_upto_2, r.paye_upto_3, r.paye_rate_3)}${band(r.paye_upto_3, r.paye_upto_4, r.paye_rate_4)}<tr><td>Above ${A.num(r.paye_upto_4)}</td><td class="text-end fw-semibold">${r.paye_rate_5}%</td></tr></tbody></table><div class="acc-sub">Less personal relief of ${A.money(r.relief)} a month. Worked out on pay after NSSF, SHIF and the Housing Levy.</div></div>
      <div class="col-lg-6"><div class="app-modal-part-head"><i class="ri-shield-user-line"></i>Contributions</div><div class="acc-facts"><div><span>NSSF</span><strong>${r.nssf_rate}% of pay up to ${A.num(r.nssf_uel)} (Tier I to ${A.num(r.nssf_lel)}) - the employer pays the same</strong></div><div><span>SHIF</span><strong>${r.shif_rate}% of gross, at least ${A.money(r.shif_min)}</strong></div><div><span>Housing Levy</span><strong>${r.ahl_rate}% of gross - the employer pays the same</strong></div></div>${CTX.level === "diocese" ? `<a class="btn btn-sm btn-outline-primary mt-2" href="${CTX.siteUrl}/diocese/settings/?section=payroll"><i class="ri-settings-3-line me-1"></i>Change in Settings</a>` : ""}</div></div>`;
  }

  function showTab() {
    document.querySelectorAll("#pyTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("pyRunsPane").hidden = tab !== "runs";
    $("pyPeoplePane").hidden = tab !== "people";
    $("pyRatesPane").hidden = tab !== "rates";
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
    rates();
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
        { icon: "ri-money-dollar-box-line", title: "Pay a month", body: `<div class="row g-2">${f("pnBasic", "Basic pay (KES)", e?.basic_pay || "", "text", "col-sm-6", ' inputmode="decimal"')}</div><div class="mt-2" id="pnAllow"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="pnAddAllow"><i class="ri-add-line me-1"></i>Add an allowance</button><label class="form-check form-switch mt-3 mb-0"><input class="form-check-input" type="checkbox" id="pnStat"${e?.statutory === false ? "" : " checked"}><span class="form-check-label">PAYE, NSSF, SHIF and Housing Levy are deducted (off for someone paid an allowance only)</span></label>` },
        { icon: "ri-bank-card-line", title: "Paid by", body: `<div class="row g-2"><div class="col-sm-4"><select class="form-select" id="pnMethod">${Object.entries(METHOD).map(([k, v]) => `<option value="${k}"${(e?.pay_method || "mpesa") === k ? " selected" : ""}>${v}</option>`).join("")}</select></div>${f("pnTo", "", e?.pay_to, "text", "col-sm-8", ' placeholder="M-Pesa number, or bank and account" aria-label="Paid to"').replace('<label class="form-label" for="pnTo"></label>', "")}</div>` },
        { icon: "ri-lock-line", title: "Private numbers", hint: "Kept encrypted; only the last four are ever shown", body: `<div class="row g-2">${secret("pnId", "ID number", e?.id_number)}${secret("pnKra", "KRA PIN", e?.kra_pin)}${secret("pnNssf", "NSSF number", e?.nssf_no)}${secret("pnShif", "SHIF number", e?.shif_no)}</div>${e ? `<label class="form-check form-switch mt-3 mb-0"><input class="form-check-input" type="checkbox" id="pnActive"${e.is_active ? " checked" : ""}><span class="form-check-label">On the payroll (off once they leave)</span></label>` : ""}` },
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
          statutory: el.querySelector("#pnStat").checked,
          pay_method: el.querySelector("#pnMethod").value,
          pay_to: v("pnTo"),
          id_number: v("pnId"),
          kra_pin: v("pnKra"),
          nssf_no: v("pnNssf"),
          shif_no: v("pnShif"),
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
    const grid = `<div class="table-responsive"><table class="table table-sm mb-0 acc-table py-grid"><thead><tr><th>Person</th><th class="text-end">Gross</th><th class="text-end d-none d-md-table-cell">NSSF</th><th class="text-end d-none d-md-table-cell">SHIF</th><th class="text-end d-none d-lg-table-cell">Housing</th><th class="text-end d-none d-md-table-cell">PAYE</th><th class="text-end d-none d-lg-table-cell">Other</th><th class="text-end">Net</th>${c.edit && own ? "<th></th>" : ""}</tr></thead><tbody>${r.payslips
      .map(
        (p) => `<tr><td><div class="fw-semibold">${esc(p.name)}${p.manual ? ' <span class="soft-chip soft-warning ms-1">Changed</span>' : ""}</div><div class="acc-sub">${esc(p.position || "")}${p.statutory ? "" : " · no deductions"}</div></td><td class="text-end">${A.amount(p.gross)}</td><td class="text-end d-none d-md-table-cell">${A.amount(p.nssf) || "-"}</td><td class="text-end d-none d-md-table-cell">${A.amount(p.shif) || "-"}</td><td class="text-end d-none d-lg-table-cell">${A.amount(p.ahl) || "-"}</td><td class="text-end d-none d-md-table-cell">${A.amount(p.paye) || "-"}</td><td class="text-end d-none d-lg-table-cell">${A.amount(p.other) || "-"}</td><td class="text-end fw-semibold">${A.amount(p.net)}</td>${c.edit && own ? `<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-slip="${p.id}" aria-label="Change ${esc(p.name)}"><i class="ri-edit-line"></i></button> <button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-unslip="${p.id}" aria-label="Take ${esc(p.name)} off"><i class="ri-close-line"></i></button></td>` : ""}</tr>`,
      )
      .join("")}<tr class="fw-semibold"><td>Total</td><td class="text-end">${A.amount(tot("gross"))}</td><td class="text-end d-none d-md-table-cell">${A.amount(tot("nssf"))}</td><td class="text-end d-none d-md-table-cell">${A.amount(tot("shif"))}</td><td class="text-end d-none d-lg-table-cell">${A.amount(tot("ahl"))}</td><td class="text-end d-none d-md-table-cell">${A.amount(tot("paye"))}</td><td class="text-end d-none d-lg-table-cell">${A.amount(tot("other"))}</td><td class="text-end">${A.amount(tot("net"))}</td>${c.edit && own ? "<td></td>" : ""}</tr></tbody></table></div>`;
    const notIn = data.employees.filter((e) => e.is_active && !r.payslips.some((p) => p.employee_id === e.id));
    const addRow = c.edit && own && notIn.length ? `<div class="d-flex gap-2 mt-2"><select class="form-select" id="pyAddWho"><option value="">Add someone to this run</option>${notIn.map((e) => `<option value="${e.id}">${esc(e.name)}</option>`).join("")}</select><button type="button" class="btn btn-outline-primary" id="pyAddGo"><i class="ri-add-line"></i></button></div>` : "";
    const pays = ["posted", "paid"].includes(r.status)
      ? `<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-bank-card-line"></i>Paying it<small>Each is a voucher already authorised - you pay it next</small></div><div class="acc-quotes">${r.payments
          .filter((p) => p.amount > 0)
          .map((p) => `<div class="acc-quote${p.voucher?.status === "paid" ? " is-chosen" : ""}"><div class="min-w-0 flex-fill"><div class="fw-semibold">${esc(p.label)}</div><div class="acc-sub">To ${esc(p.to)}${p.voucher ? ` · voucher ${esc(p.voucher.number)} (${esc(p.voucher.status)})` : ""}</div></div><strong>${A.money(p.amount)}</strong>${p.voucher ? (p.voucher.status === "paid" ? '<span class="badge bg-success">Paid</span>' : `<a class="btn btn-sm btn-outline-primary" href="${A.link("payments.php", { voucher: p.voucher.id })}">Open</a>`) : c.pay && own ? `<button type="button" class="btn btn-sm btn-primary" data-pay="${p.kind}"><i class="ri-hand-coin-line me-1"></i>${p.kind === "net" ? "Pay the staff" : "Remit"}</button>` : ""}</div>`)
          .join("")}</div></section>`
      : "";
    const btn = (k, cls, icon, label) => `<button type="button" class="btn ${cls}" data-act="${k}"><i class="${icon} me-1"></i>${label}</button>`;
    const foot = [
      own && c.cancel ? btn("cancel", "btn-outline-danger me-auto", "ri-close-circle-line", "Cancel") : "",
      btn("print", "btn-outline-primary", "ri-printer-line", "Payslips"),
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
          <div class="acc-facts mb-3"><div><span>Gross pay</span><strong>${A.money(r.gross)}</strong></div><div><span>Deductions</span><strong>${A.money(r.deductions)}</strong></div><div><span>Net pay</span><strong>${A.money(r.net)}</strong></div><div><span>Employer NSSF and Housing Levy</span><strong>${A.money(r.employer)}</strong></div></div>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-file-list-3-line"></i>Payslips${c.edit ? "<small>Change one with the pencil - typed-over figures are marked</small>" : ""}</div>${grid}${addRow}</section>
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
      if (pay) return payWindow(r, pay.dataset.pay);
      if (!act) return;
      if (act === "print") return printSlips(r);
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
    const fig = (k, label) => `<div class="col-6 col-md-3"><label class="form-label" for="sl_${k}">${label}</label><input type="text" inputmode="decimal" class="form-control text-end" id="sl_${k}" value="${p[k]}"></div>`;
    const el = K.confirmWindow({
      title: `${p.name} - ${r.label}`,
      subtitle: "Change the pay or type over a figure; the rest is worked out again",
      icon: "ri-edit-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: "ri-money-dollar-box-line", title: "Pay this month", body: `<div class="row g-2"><div class="col-sm-6"><label class="form-label" for="slBasic">Basic (KES)</label><input type="text" inputmode="decimal" class="form-control text-end" id="slBasic" value="${p.basic}"></div></div><div class="mt-2" id="slAllow">${(p.allowances || []).map((a) => `<div class="row g-2 mb-2" data-allow><div class="col-7"><input type="text" class="form-control" data-an value="${esc(a.name)}"></div><div class="col-5"><input type="text" inputmode="decimal" class="form-control text-end" data-aa value="${a.amount}"></div></div>`).join("")}</div>` },
        { icon: "ri-scissors-cut-line", title: "Other deduction", hint: "e.g. a SACCO or a loan - held and paid on with a voucher", body: `<div class="row g-2"><div class="col-sm-4"><input type="text" inputmode="decimal" class="form-control text-end" id="slOther" value="${p.other || ""}" placeholder="0"></div><div class="col-sm-8"><input type="text" class="form-control" id="slNote" maxlength="150" value="${esc(p.other_note || "")}" placeholder="What it is"></div></div>` },
        { icon: "ri-government-line", title: "Statutory figures", hint: p.statutory ? "Worked out - type over one only if you must" : "This person has no deductions", body: p.statutory ? `<div class="row g-2">${fig("nssf", "NSSF")}${fig("shif", "SHIF")}${fig("ahl", "Housing Levy")}${fig("paye", "PAYE")}</div>` : "" },
      ]),
      run: async () => {
        const v = (id) => el.querySelector(`#${id}`)?.value;
        const body = {
          basic: n(v("slBasic")),
          allowances: [...el.querySelectorAll("#slAllow [data-allow]")].map((x) => ({ name: x.querySelector("[data-an]").value.trim(), amount: n(x.querySelector("[data-aa]").value) })),
          other: n(v("slOther")),
          other_note: v("slNote")?.trim() || null,
        };
        // Only figures actually changed are sent as typed-over.
        if (p.statutory) {
          const changed = Object.fromEntries(["nssf", "shif", "ahl", "paye"].filter((k) => n(v(`sl_${k}`)) !== p[k]).map((k) => [k, n(v(`sl_${k}`))]));
          if (Object.keys(changed).length) body.overrides = changed;
        }
        const out = await API.saveSlip(r.id, p.id, body);
        if (out.ok) done();
        return out;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
  }

  async function payWindow(r, kind) {
    const p = r.payments.find((x) => x.kind === kind);
    K.confirmWindow({
      title: kind === "net" ? `Pay the staff - ${r.label}` : `Remit ${p.label} - ${r.label}`,
      subtitle: `${A.money(p.amount)} to ${p.to} - the voucher is already authorised; you pay it next`,
      icon: "ri-hand-coin-line",
      go: '<i class="ri-check-line me-1"></i>Make the voucher',
      body: K.parts([{ icon: "ri-bank-line", title: "Pay it from", body: W.cashTiles(data.cash, data.cash.find((a) => a.cash_kind === "bank")?.id || data.cash[0]?.id, "pyFrom") }]),
      run: async () => {
        const out = await API.payRun(r.id, kind, Number(document.querySelector('input[name="pyFrom"]:checked')?.value));
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
  function printSlips(r) {
    const w = window.open("", "_blank");
    if (!w) return Toast.error("Allow pop-ups to print.");
    const row = (k, v, b = false) => (v ? `<tr${b ? ' class="tot"' : ""}><td>${esc(k)}</td><td style="text-align:right">${A.amount(v)}</td></tr>` : "");
    const slips = r.payslips
      .map(
        (p) => `<section style="page-break-after:always"><h1>Christian Church International - ${esc(r.place?.name || "")}</h1><h2>PAYSLIP - ${esc(r.label)}</h2>
        <div class="meta"><div><b>Name:</b> ${esc(p.name)}</div><div><b>Position:</b> ${esc(p.position || "-")}</div><div><b>Paid by:</b> ${esc(METHOD[p.pay_method])} ${esc(p.pay_to || "")}</div><div><b>Reference:</b> ${esc(p.reference || "-")}</div></div>
        <table><tbody>${row("Basic pay", p.basic)}${(p.allowances || []).map((a) => row(a.name, a.amount)).join("")}${row("Gross pay", p.gross, true)}${row("NSSF", p.nssf)}${row("SHIF", p.shif)}${row("Housing Levy", p.ahl)}${row("Taxable pay", p.taxable)}${row("PAYE", p.paye)}${row(p.other_note || "Other deduction", p.other)}${row("Total deductions", p.total_deductions, true)}${row("NET PAY", p.net, true)}</tbody></table>
        <p style="font-size:12px">Employer's contributions (not deducted): NSSF ${A.amount(p.employer_nssf) || "0.00"} · Housing Levy ${A.amount(p.employer_ahl) || "0.00"}</p></section>`,
      )
      .join("");
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Payslips ${esc(r.label)}</title><style>body{font-family:Inter,Arial,sans-serif;color:#0D0D0D;max-width:640px;margin:32px auto;padding:0 16px}h1{font-size:18px;margin:0}h2{font-size:14px;font-weight:600;margin:4px 0 18px}table{width:100%;border-collapse:collapse;margin:16px 0}td{padding:7px 8px;border-bottom:1px solid #ddd;font-size:14px}.tot td{font-weight:700;border-top:2px solid #0D0D0D}.meta{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:14px}</style></head><body>${slips}<script>window.onload=()=>window.print()<\/script></body></html>`);
    w.document.close();
  }

  /** The run register and the deductions schedule, as a CSV. */
  function register(r) {
    const cols = ["Name", "Position", "Paid by", "Paid to", "Basic", "Allowances", "Gross", "NSSF", "SHIF", "Housing Levy", "Taxable", "PAYE", "Other", "Total deductions", "Net", "Employer NSSF", "Employer Housing Levy", "Reference"];
    const q = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
    const lines = r.payslips.map((p) => [p.name, p.position, METHOD[p.pay_method], p.pay_to, p.basic, (p.allowances || []).reduce((t, a) => t + a.amount, 0), p.gross, p.nssf, p.shif, p.ahl, p.taxable, p.paye, p.other, p.total_deductions, p.net, p.employer_nssf, p.employer_ahl, p.reference].map(q).join(","));
    const schedule = ["", q("Deductions to remit"), ...r.payments.filter((p) => p.kind !== "net").map((p) => [q(p.label), q(p.to), q(p.amount)].join(","))];
    const blob = new Blob([[cols.map(q).join(","), ...lines, ...schedule].join("\n")], { type: "text/csv" });
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
      if (tr) runWindow(Number(tr.dataset.id));
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
