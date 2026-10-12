/**
 * ============================================================================
 * ACCOUNTING - Start here (start.php, every level)
 * ============================================================================
 * Setting a place's books up for the first time, one step at a time:
 *   1 where the money is kept (Cash at hand is already there; bank, M-Pesa,
 *     Airtel and a petty cash box from templates),
 *   2 what each held on the day you start (the total becomes the General fund),
 *   3 who does each job here,
 *   4 extras from templates (a fund of our own, a finer income line),
 *   5 a look over it all, then a done screen with the first things to do.
 * Each step saves through the endpoints the Accounting pages already use, so
 * leaving half-way and coming back picks up where the books are.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const W = AccountingWindows;
  const API = AccountingAPI;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const n = (v) => Math.round(parseFloat(String(v ?? "").replace(/[^0-9.]/g, "")) * 100) / 100 || 0;
  const STEPS = 5;
  const BANKS = ["Equity Bank", "KCB", "Co-operative Bank", "Family Bank", "NCBA", "Absa"];
  const KIND = {
    cash: ["ri-money-dollar-circle-line", "success", "Cash at hand", "The money the treasurer holds - every church has it"],
    petty_cash: ["ri-wallet-3-line", "warning", "Petty cash", "A small box for little spends"],
    bank: ["ri-bank-line", "primary", "Bank account", "Where the church banks"],
    mpesa: ["ri-smartphone-line", "success", "M-Pesa", "A till, paybill or phone"],
    airtel: ["ri-smartphone-line", "danger", "Airtel Money", "An Airtel Money number"],
  };
  const FUND_TEMPLATES = [
    ["Harambee fund", "ri-hand-heart-line", "Money raised for one project"],
    ["Choir fund", "ri-music-2-line", "Kept for the choir"],
    ["Church van fund", "ri-bus-line", "Saving for or running a van"],
    ["Welfare fund", "ri-heart-pulse-line", "Helping members in need"],
  ];
  const LINE_TEMPLATES = [
    ["Youth offering", "4010", "Under Offerings"],
    ["Sunday school offering", "4010", "Under Offerings"],
    ["Thanksgiving", "4020", "Under Special collections"],
  ];
  const place = () => s?.place?.name || CTX.place?.name || "this place";
  let step = 1;
  let s = null; // GET accounting/setup
  let o = null; // the options: accounts, funds, today
  let petty = null; // GET accounting/petty-cash
  let chart = []; // the place's chart (own lines)
  let opened = null; // which template form is open in step 1

  const done = (key) => !!s?.steps.find((x) => x.key === key)?.done;
  const cash = () => chart.filter((a) => a.cash_kind && !a.is_header && a.is_active);
  const ownFunds = () => (o?.funds || []).filter((f) => f.owner && Number(f.owner.id) === Number(s?.place?.id));
  const ownLines = () => chart.filter((a) => a.own && !a.cash_kind && ["income", "expense"].includes(a.type));

  // ------------------------------------------------------------ loading

  async function refresh() {
    const [su, op, pt, ch] = await Promise.all([API.setup(), A.options(true), API.petty(), API.accounts()]);
    if (!su.ok) {
      $("startForm").innerHTML = `<div class="col-12">${A.errorBox(su.message)}</div>`;
      return false;
    }
    s = su.data;
    o = op;
    petty = pt.ok ? pt.data : null;
    chart = ch.ok ? ch.data.chart : [];
    soFar();
    return true;
  }

  /** Where to pick up: a step from the address, else the first one not done. */
  function firstStep() {
    const asked = Number(new URLSearchParams(window.location.search).get("step"));
    if (asked >= 1 && asked <= STEPS) return asked;
    if (!done("accounts") && !s.started) return 1;
    if (!done("opening")) return 2;
    return 3;
  }

  // ------------------------------------------------------------ the steps

  function go(k) {
    step = k;
    document.querySelectorAll("#startApp .intake-step").forEach((x) => (x.hidden = Number(x.dataset.step) !== k));
    document.querySelectorAll("#startApp .intake-step-btn").forEach((b) => {
      const i = Number(b.dataset.go);
      b.classList.toggle("is-on", i === k);
      b.classList.toggle("is-done", i < k);
    });
    $("intakeStepsBar").style.width = `${(k / STEPS) * 100}%`;
    $("intakeStepsMobile").textContent = `Step ${k} of ${STEPS} · ${document.querySelector(`[data-go="${k}"] strong`).textContent}`;
    $("backBtn").hidden = k === 1;
    $("nextBtn").hidden = k === STEPS;
    $("finishBtn").hidden = k !== STEPS;
    const p = new URLSearchParams(window.location.search);
    p.set("step", k);
    history.replaceState(null, "", `${window.location.pathname}?${p}`);
    ({ 1: money, 2: opening, 3: jobs, 4: extras, 5: review })[k]();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  /** Step 1: what we have, then templates to add more. */
  function money() {
    const have = cash()
      .map((a) => {
        const [icon, color, label] = KIND[a.cash_kind] || KIND.bank;
        const shared = a.cash_kind === "cash" || a.cash_kind === "petty_cash";
        const sub = a.cash_kind === "petty_cash" ? (petty?.float ? `Float ${A.money(petty.float, { cents: false })}` : "No float set yet") : a.cash_kind === "cash" ? "Already here" : [label, a.number_masked || a.mpesa_number || a.account_number].filter(Boolean).join(" · ");
        return `<div class="acc-start-have">
          <span class="avatar avatar-md avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon} fs-18"></i></span>
          <div class="flex-fill min-w-0"><span class="d-block fw-semibold text-truncate">${esc(a.name)}</span><span class="d-block fs-12 fw-medium">${esc(sub)}</span></div>
          ${a.cash_kind === "petty_cash" ? `<button type="button" class="btn btn-sm btn-outline-warning" data-float>${petty?.float ? "Change" : "Set a float"}</button>` : shared ? '<span class="badge bg-success text-white"><i class="ri-check-line me-1"></i>Ready</span>' : `<span class="badge bg-primary text-white"><i class="ri-check-line me-1"></i>Added</span><button type="button" class="btn btn-sm btn-icon btn-light border" data-off="${a.id}" title="Remove it" aria-label="Remove ${esc(a.name)}"><i class="ri-close-line"></i></button>`}
        </div>`;
      })
      .join("");
    const tpl = (kind) => {
      const [icon, color, label, about] = KIND[kind];
      const isOpen = opened === kind;
      return `<div class="acc-start-tpl${isOpen ? " is-open" : ""}" data-tpl="${kind}">
        <button type="button" class="acc-start-tpl-head" data-open="${kind}"><span class="avatar avatar-md avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon} fs-18"></i></span><span class="flex-fill text-start"><strong>${label}</strong><small>${about}</small></span><i class="ri-add-circle-fill fs-22 text-${color}"></i></button>
        ${isOpen ? tplForm(kind) : ""}
      </div>`;
    };
    $("startStep1").innerHTML = `
      <div class="acc-start-label">What ${esc(place())} has now</div>
      <div class="acc-start-haves">${have}</div>
      <div class="acc-start-label mt-4">Add another - pick one</div>
      <div class="acc-start-tpls">${["bank", "mpesa", "airtel"].map(tpl).join("")}</div>
      <p class="fs-12 fw-medium mt-3 mb-0"><i class="ri-information-line me-1 text-primary"></i>Only cash? That's fine - press Next. You can add a bank or M-Pesa any time.</p>`;
    if (opened === "bank") $("tplName")?.focus();
  }

  function tplForm(kind) {
    const suggest = kind === "bank" ? `Equity Bank - ${place()}` : kind === "mpesa" ? `M-Pesa - ${place()}` : `Airtel Money - ${place()}`;
    return `<div class="acc-start-tpl-form">
      ${kind === "bank" ? `<div class="acc-start-label">Which bank?</div><div class="d-flex flex-wrap gap-2 mb-3">${BANKS.map((b, i) => `<label class="acc-start-chip"><input type="radio" name="tplBank" value="${esc(b)}"${i ? "" : " checked"}><span>${esc(b)}</span></label>`).join("")}<label class="acc-start-chip"><input type="radio" name="tplBank" value=""><span>Another bank</span></label></div>` : ""}
      <div class="row g-2">
        <div class="col-sm-7"><label class="form-label" for="tplName">Its name in the books</label><input type="text" class="form-control" id="tplName" maxlength="150" value="${esc(suggest)}" placeholder="e.g. ${esc(suggest)}"></div>
        <div class="col-sm-5"><label class="form-label" for="tplNumber">${kind === "bank" ? "Account number" : kind === "mpesa" ? "Till, paybill or phone" : "Phone number"}</label><input type="text" class="form-control" id="tplNumber" maxlength="50" inputmode="numeric" placeholder="${kind === "bank" ? "e.g. 0123456789" : kind === "mpesa" ? "e.g. 552718" : "e.g. 0733 123 456"}"></div>
        ${kind === "bank" ? '<div class="col-sm-7" id="tplOtherWrap" hidden><label class="form-label" for="tplOther">The bank</label><input type="text" class="form-control" id="tplOther" maxlength="100" placeholder="e.g. Sidian Bank"></div>' : ""}
      </div>
      <div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-primary" data-add="${kind}"><i class="ri-check-line me-1"></i>Add it</button><button type="button" class="btn btn-light" data-open="">Cancel</button></div>
    </div>`;
  }

  async function addTemplate(kind, btn) {
    const name = $("tplName").value.trim();
    const number = $("tplNumber").value.trim();
    const picked = document.querySelector('input[name="tplBank"]:checked')?.value;
    const bank = kind === "bank" ? picked || $("tplOther")?.value.trim() || null : null;
    if (!name) return Toast.error("Give it a name.");
    DemographicsUI.setButtonLoading?.(btn, "Adding...");
    const res = await API.saveAccount(null, { cash_kind: kind, name, bank_name: bank, account_number: kind === "bank" ? number || null : null, mpesa_number: kind === "bank" ? null : number || null });
    DemographicsUI.restoreButton?.(btn);
    if (!res.ok) return Toast.error(res.message);
    Toast.success(res.message);
    opened = null;
    await refresh();
    money();
  }

  /** Step 2: one amount per account; the total is the General fund. */
  function opening() {
    if (done("opening")) {
      $("startStep2").innerHTML = `<div class="acc-start-ok"><span class="avatar avatar-lg avatar-rounded bg-success text-white"><i class="ri-check-double-line fs-22"></i></span><div><strong class="d-block">The opening balances are in</strong><span class="fs-13 fw-medium">Need to change them? Post a correcting journal in Journals.</span></div><a class="btn btn-sm btn-outline-primary ms-auto" href="${A.link("journals.php")}">Journals</a></div>`;
      return;
    }
    const first = `${(o?.today || new Date().toISOString().slice(0, 10)).slice(0, 8)}01`;
    $("startStep2").innerHTML = `
      <p class="mb-3">Count what each place held on the day you start keeping these books. Leave a box empty if it held nothing.</p>
      <div class="row g-2 mb-3"><div class="col-sm-5"><label class="form-label" for="obDate">The day you start</label><input type="date" class="form-control" id="obDate" value="${first}"></div></div>
      <div class="acc-start-obs">${cash()
        .map((a) => {
          const [icon, color] = KIND[a.cash_kind] || KIND.bank;
          return `<label class="acc-start-ob"><span class="avatar avatar-sm avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon}"></i></span><span class="flex-fill fw-semibold">${esc(a.name)}</span><span class="input-group" style="max-width: 14rem"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end fw-semibold" data-ob="${a.id}" placeholder="0"></span></label>`;
        })
        .join("")}</div>
      <div class="acc-start-total"><span>All together - this becomes your <strong>General fund</strong></span><strong class="fs-18 text-success" id="obTotal">KES 0</strong></div>`;
    const upd = () => ($("obTotal").textContent = A.money([...document.querySelectorAll("[data-ob]")].reduce((t, x) => t + n(x.value), 0), { cents: false }));
    document.querySelectorAll("[data-ob]").forEach((x) => x.addEventListener("input", upd));
    if (window.DateField) DateField.enhance($("obDate"), { quick: ["today"] });
  }

  /** Posts the opening balances (if any were typed). Returns false to stay on the step. */
  async function saveOpening(btn) {
    if (done("opening") || !document.querySelector("[data-ob]")) return true;
    const amounts = [...document.querySelectorAll("[data-ob]")].map((x) => ({ account_id: Number(x.dataset.ob), amount: n(x.value) })).filter((l) => l.amount > 0);
    if (!amounts.length) return true; // nothing held - we start at zero
    const total = amounts.reduce((t, l) => t + l.amount, 0);
    DemographicsUI.setButtonLoading?.(btn, "Saving...");
    // The opening balances endpoint: the treasurer, or a pastor setting the books up.
    const res = await API.openingBalances({ date: $("obDate").value, amounts });
    DemographicsUI.restoreButton?.(btn);
    if (!res.ok) return Toast.error(res.message), false;
    Toast.success(`Opening balances in - ${A.money(total, { cents: false })}`);
    await refresh();
    return true;
  }

  /** Step 3: who holds each job - nothing to type. */
  function jobs() {
    $("startStep3").innerHTML = `
      <p class="mb-3">Each job needs someone with the permission for it. They come with roles - the treasurer writes receipts, the pastor authorises payments.</p>
      <div class="acc-start-jobs">${s.jobs
        .map((j) => `<div class="acc-start-job${j.held ? "" : " is-empty"}"><span class="avatar avatar-sm avatar-rounded bg-${j.held ? "success" : "danger"} text-white"><i class="${j.held ? "ri-check-line" : "ri-user-unfollow-line"}"></i></span><div class="flex-fill min-w-0"><span class="d-block fw-semibold">${esc(j.label)}</span>${j.held ? `<div class="d-flex flex-wrap gap-1 mt-1">${j.holders.map((h) => A.person(h)).join("")}</div>` : '<span class="d-block fs-12 fw-medium text-danger">Nobody yet</span>'}</div></div>`)
        .join("")}</div>
      ${s.unheld ? `<div class="alert alert-warning d-flex align-items-center gap-2 mt-3 mb-0"><i class="ri-error-warning-line"></i><span class="flex-fill">${s.unheld} ${s.unheld === 1 ? "job has" : "jobs have"} nobody. ${CTX.canAdmin ? "Give someone the role in Roles & permissions." : "Ask the diocese administrator to give someone the role."}</span>${CTX.canAdmin ? `<a class="btn btn-sm btn-warning text-dark" href="${CTX.siteUrl}/diocese/settings/admin/role-management.php">Roles & permissions</a>` : ""}</div>` : ""}`;
  }

  /** Step 4: optional templates - a fund of our own, a finer income line. */
  function extras() {
    const funds = ownFunds();
    const lines = ownLines();
    const hasFund = (name) => funds.some((f) => f.name.toLowerCase() === name.toLowerCase());
    const hasLine = (name) => lines.some((a) => a.name.toLowerCase() === name.toLowerCase());
    const parent = (code) => (o.income || []).find((a) => String(a.code) === code);
    $("startStep4").innerHTML = `
      <p class="mb-3">All optional - add what ${esc(place())} uses now, skip the rest. Tap one to add it.</p>
      <div class="acc-start-label">A fund of our own <span class="fw-medium text-lowercase">- money kept for one purpose</span></div>
      <div class="acc-start-picks">${FUND_TEMPLATES.map(([name, icon, about]) => `<button type="button" class="acc-start-pick${hasFund(name) ? " is-on" : ""}" data-fund="${esc(name)}"${hasFund(name) ? " disabled" : ""}><span class="avatar avatar-sm avatar-rounded bg-purple text-white"><i class="${icon}"></i></span><span class="flex-fill text-start"><strong>${esc(name)}</strong><small>${esc(about)}</small></span><i class="${hasFund(name) ? "ri-checkbox-circle-fill text-success" : "ri-add-circle-line text-purple"} fs-20"></i></button>`).join("")}</div>
      <div class="acc-start-label mt-4">Finer income lines <span class="fw-medium text-lowercase">- to see one kind of giving on its own</span></div>
      <div class="acc-start-picks">${LINE_TEMPLATES.filter(([, code]) => parent(code))
        .map(([name, code, about]) => `<button type="button" class="acc-start-pick${hasLine(name) ? " is-on" : ""}" data-line="${esc(name)}" data-parent="${parent(code).id}"${hasLine(name) ? " disabled" : ""}><span class="avatar avatar-sm avatar-rounded bg-success text-white"><i class="ri-git-branch-line"></i></span><span class="flex-fill text-start"><strong>${esc(name)}</strong><small>${esc(about)}</small></span><i class="${hasLine(name) ? "ri-checkbox-circle-fill text-success" : "ri-add-circle-line text-success"} fs-20"></i></button>`)
        .join("")}</div>
      <div class="acc-start-label mt-4">When you're ready</div>
      <div class="acc-start-picks">
        <a class="acc-start-pick" href="${A.link("chart.php", { add: 1 })}"><span class="avatar avatar-sm avatar-rounded bg-primary text-white"><i class="ri-book-3-line"></i></span><span class="flex-fill"><strong>Something else</strong><small>Anything - in the Chart of accounts</small></span><i class="ri-arrow-right-line fs-18 text-primary"></i></a>
        <a class="acc-start-pick" href="${A.link("procurement.php")}"><span class="avatar avatar-sm avatar-rounded bg-warning text-dark"><i class="ri-store-2-line"></i></span><span class="flex-fill"><strong>Your suppliers</strong><small>Those you buy from regularly</small></span><i class="ri-arrow-right-line fs-18 text-warning"></i></a>
        <a class="acc-start-pick" href="${CTX.siteUrl}/${CTX.level}/hr/"><span class="avatar avatar-sm avatar-rounded bg-pink text-white"><i class="ri-team-line"></i></span><span class="flex-fill"><strong>The people you pay</strong><small>In Staff - payroll pays from there</small></span><i class="ri-arrow-right-line fs-18 text-pink"></i></a>
      </div>`;
  }

  /** Step 5: look it over, with a way back to each step. */
  function review() {
    const group = (k, title, body) => `<div class="acc-start-review"><div class="d-flex align-items-center justify-content-between mb-2"><strong>${title}</strong><button type="button" class="btn btn-sm btn-link p-0" data-go-step="${k}"><i class="ri-edit-line me-1"></i>Change</button></div>${body}</div>`;
    const chips = (list) => (list.length ? `<div class="d-flex flex-wrap gap-1">${list.join("")}</div>` : '<span class="fs-13 fw-medium">None</span>');
    $("startStep5").innerHTML =
      group(1, "Where the money is kept", chips(cash().map((a) => `<span class="soft-chip soft-primary"><i class="${(KIND[a.cash_kind] || KIND.bank)[0]}"></i>${esc(a.name)}</span>`))) +
      group(2, "Opening balances", done("opening") ? '<span class="badge bg-success text-white"><i class="ri-check-line me-1"></i>Posted</span>' : '<span class="soft-chip soft-warning">Not entered - you start at zero</span>') +
      group(3, "Who does what", s.unheld ? `<span class="badge bg-warning text-dark">${s.unheld} ${s.unheld === 1 ? "job has" : "jobs have"} nobody</span>` : '<span class="badge bg-success text-white"><i class="ri-check-line me-1"></i>Every job has someone</span>') +
      group(4, "Extras", chips([...ownFunds().map((f) => `<span class="soft-chip soft-purple"><i class="ri-safe-2-line"></i>${esc(f.name)}</span>`), ...ownLines().map((a) => `<span class="soft-chip soft-success"><i class="ri-git-branch-line"></i>${esc(a.name)}</span>`)])) +
      `<div class="alert alert-primary d-flex gap-2 mt-3 mb-0"><i class="ri-calendar-check-line"></i><span>At the end of your first month: count the cash and match the bank and M-Pesa, then close the month. The overview will remind you.</span></div>`;
  }

  /** The panel on the right: what the books have so far. */
  function soFar() {
    const row = (icon, color, label, value, ok) => `<li class="d-flex align-items-center gap-2 mb-3"><span class="avatar avatar-sm avatar-rounded bg-${ok ? color : "light"} ${ok ? A.textOn(color) : "text-dark border"}"><i class="${ok ? icon : "ri-time-line"}"></i></span><div class="flex-fill min-w-0"><span class="d-block fs-12 fw-medium">${label}</span><span class="d-block fw-semibold">${value}</span></div>${ok ? '<i class="ri-checkbox-circle-fill text-success fs-18"></i>' : ""}</li>`;
    const own = cash().filter((a) => !["cash", "petty_cash"].includes(a.cash_kind));
    $("startSoFar").innerHTML = `<ul class="list-unstyled mb-0">
      ${row("ri-bank-line", "primary", "Money places", `${cash().length} - ${own.length ? `${own.length} of our own` : "cash only"}`, true)}
      ${row("ri-scales-3-line", "success", "Opening balances", done("opening") ? "Posted" : "Not yet", done("opening"))}
      ${row("ri-team-line", "purple", "Jobs with someone", `${s.jobs.filter((j) => j.held).length} of ${s.jobs.length}`, !s.unheld)}
      ${row("ri-magic-line", "warning", "Extras", `${ownFunds().length} funds · ${ownLines().length} lines`, ownFunds().length + ownLines().length > 0)}
    </ul>
    <div class="acc-start-sofar-note"><i class="ri-shield-check-line me-1 text-success"></i>Each step saves as you go - you can stop and come back.</div>`;
  }

  // ------------------------------------------------------------ finishing

  function finish() {
    $("intakeSteps").hidden = true;
    $("startForm").hidden = true;
    const next = (icon, color, title, text, attrs) => `<button type="button" class="acc-start-next" ${attrs}><span class="avatar avatar-lg avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon} fs-22"></i></span><strong>${title}</strong><small>${text}</small></button>`;
    $("startDone").innerHTML = `<div class="card-body text-center py-5">
      <span class="avatar avatar-xxl avatar-rounded bg-success text-white mb-3"><i class="ri-check-double-line fs-1"></i></span>
      <h3 class="fw-bold mb-1">${esc(place())}'s books are ready</h3>
      <p class="fw-medium mb-4">Here is what to do first.</p>
      <div class="acc-start-nexts">
        ${next("ri-bill-line", "primary", "Write a receipt", "Money that came in", "data-first=receipt")}
        ${CTX.level === "church" ? next("ri-hand-heart-line", "success", "Record Sunday collections", "Tithes and offerings by kind", `data-href="${A.link("collections.php")}"`) : ""}
        ${next("ri-file-list-3-line", "warning", "Prepare a payment", "Money going out", "data-first=voucher")}
      </div>
      <a class="btn btn-outline-primary mt-4" href="${CTX.baseUrl}/"><i class="ri-dashboard-line me-1"></i>Go to the overview</a>
    </div>`;
    $("startDone").hidden = false;
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", async () => {
    A.placeLine?.($("accPlaceLine"), CTX.place);
    if (!(await refresh())) return;
    go(firstStep());

    $("intakeSteps").addEventListener("click", (e) => {
      const b = e.target.closest("[data-go]");
      if (b) go(Number(b.dataset.go));
    });
    $("backBtn").addEventListener("click", () => go(Math.max(1, step - 1)));
    $("nextBtn").addEventListener("click", async (e) => {
      if (step === 2 && !(await saveOpening(e.currentTarget))) return;
      go(Math.min(STEPS, step + 1));
    });
    $("finishBtn").addEventListener("click", finish);

    $("startApp").addEventListener("click", async (e) => {
      const t = e.target;
      const op = t.closest("[data-open]");
      if (op) {
        opened = op.dataset.open || null;
        return money();
      }
      const add = t.closest("[data-add]");
      if (add) return addTemplate(add.dataset.add, add);
      if (t.closest("[data-float]")) return W.setFloat({ onDone: async () => (await refresh()) && money() });
      const off = t.closest("[data-off]");
      if (off) {
        const a = cash().find((x) => String(x.id) === off.dataset.off);
        const res = await API.saveAccount(a.id, { name: a.name, is_active: false });
        if (!res.ok) return Toast.error(res.message);
        Toast.success(`${a.name} removed`);
        return (await refresh()) && money();
      }
      const fund = t.closest("[data-fund]");
      if (fund && !fund.disabled) {
        fund.disabled = true;
        const res = await API.saveAccount(null, { kind: "fund", name: fund.dataset.fund });
        res.ok ? Toast.success(res.message) : Toast.error(res.message);
        return (await refresh()) && extras();
      }
      const line = t.closest("[data-line]");
      if (line && !line.disabled) {
        line.disabled = true;
        const res = await API.saveAccount(null, { parent_id: Number(line.dataset.parent), name: line.dataset.line });
        res.ok ? Toast.success(res.message) : Toast.error(res.message);
        return (await refresh()) && extras();
      }
      const back = t.closest("[data-go-step]");
      if (back) return go(Number(back.dataset.goStep));
      const first = t.closest("[data-first]");
      if (first) return first.dataset.first === "receipt" ? W.receipt({ onDone: () => (window.location.href = `${CTX.baseUrl}/`) }) : W.voucher({ onDone: () => (window.location.href = `${CTX.baseUrl}/`) });
      const href = t.closest("[data-href]");
      if (href) window.location.href = href.dataset.href;
    });
    $("startApp").addEventListener("change", (e) => {
      if (e.target.name === "tplBank" && $("tplOtherWrap")) {
        $("tplOtherWrap").hidden = e.target.value !== "";
        if (e.target.value) $("tplName").value = `${e.target.value} - ${place()}`;
      }
    });
  });
})();
