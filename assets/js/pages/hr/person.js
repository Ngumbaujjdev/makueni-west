/**
 * ============================================================================
 * STAFF - one person's page (hr/person.php?id=, every level)
 * ============================================================================
 * docs/specs/hr-spec.md: who they are (their member record, their login),
 * their job and pay and how they are paid; every payslip; where they have
 * served; their papers - and, for whoever manages staff here, change, move,
 * they leave, remove. ID numbers and KRA PINs only ever come masked.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const API = HrAPI;
  const CTX = window.HR_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const params = new URLSearchParams(window.location.search);
  const ID = Number(params.get("id"));
  let p = null;
  let tab = ["payslips", "history", "papers"].includes(params.get("tab")) ? params.get("tab") : "overview";

  const ST = {
    active: ["success", "ri-user-follow-line", "Working"],
    starting: ["primary", "ri-calendar-event-line", "Starting"],
    off: ["secondary", "ri-pause-circle-line", "Off the payroll"],
    left: ["secondary", "ri-user-unfollow-line", "Left"],
  };
  const TYPE_COLOR = { full_time: "primary", part_time: "info", contract: "warning", casual: "purple" };
  const RUN = { posted: ["primary", "Approved - to pay"], paid: ["success", "Paid"] };
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const row = (icon, label, html) => `<li class="mr-row"><div class="mr-row-head"><span class="ev-tile is-sm is-soft" style="--q: var(--primary-rgb)"><i class="${icon}"></i></span>${label}</div><p class="mr-row-body">${html}</p></li>`;
  const none = '<span class="acc-sub">Not given</span>';
  const card = (title, body, extra = "") => `<div class="card custom-card"><div class="card-header justify-content-between"><div class="card-title">${title}</div>${extra}</div><div class="card-body">${body}</div></div>`;
  const glance = (icon, color, label, value, sub = "") =>
    `<div class="glance-tile" style="--q: var(--${color}-rgb)"><span class="glance-tile-icon${value ? " is-solid" : ""}"><i class="${icon}"></i></span><div class="min-w-0"><span class="glance-tile-label">${label}</span>${value ? `<strong>${esc(value)}</strong>` : '<span class="glance-tile-none">None yet</span>'}${sub ? `<small>${esc(sub)}</small>` : ""}</div></div>`;
  const back = () => `${CTX.baseUrl}/${params.get("territory_id") ? `?territory_id=${params.get("territory_id")}` : ""}`;

  // ------------------------------------------------------------ the hero

  function hero() {
    const can = p.can;
    const chips = [
      p.position ? `<span class="soft-chip soft-primary"><i class="ri-briefcase-4-line"></i>${esc(p.position)}${p.grade ? ` · ${esc(p.grade.code)}` : ""}</span>` : "",
      `<span class="soft-chip soft-${TYPE_COLOR[p.employment_type] || "secondary"}"><i class="ri-time-line"></i>${esc(p.employment_type_label)}</span>`,
      `<span class="soft-chip soft-secondary"><i class="ri-map-pin-line"></i>${esc(p.place.name)}</span>`,
      p.person ? '<span class="soft-chip soft-success"><i class="ri-contacts-book-2-line"></i>Member</span>' : "",
      p.user ? '<span class="soft-chip soft-purple"><i class="ri-user-settings-line"></i>Has a login</span>' : "",
    ].join("");
    const more = [
      can.transfer && p.status !== "left" ? '<li><button class="dropdown-item" type="button" data-act="move"><i class="ri-arrow-left-right-line me-2"></i>Move to another place</button></li>' : "",
      can.end ? '<li><button class="dropdown-item" type="button" data-act="end"><i class="ri-logout-box-r-line me-2"></i>They leave</button></li>' : "",
      can.delete && p.can_delete ? '<li><button class="dropdown-item text-danger" type="button" data-act="remove"><i class="ri-delete-bin-line me-2"></i>Remove</button></li>' : "",
    ].join("");
    $("hrHero").innerHTML = `<div class="card-body">
      <div class="ev-hero-row">
        <span class="mb-hero-photo is-static">${A.avatar(p.name, "xxl")}</span>
        <div class="flex-fill" style="min-width:0">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${esc(p.name)}</h2>${pill(p.status)}</div>
          <div class="d-flex flex-wrap gap-1 mb-1">${chips}</div>
          <div class="ev-card-meta">${p.phone ? `<span><i class="ri-phone-line"></i>${esc(p.phone)}</span>` : ""}${p.email ? `<span><i class="ri-mail-line"></i>${esc(p.email)}</span>` : ""}${p.start_date ? `<span><i class="ri-calendar-line"></i>Since ${A.day(p.start_date)}</span>` : ""}</div>
        </div>
        <div class="ev-hero-actions">
          ${can.edit ? '<button type="button" class="btn btn-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Change</button>' : ""}
          <a class="btn btn-outline-primary" href="${back()}"><i class="ri-arrow-left-line me-1"></i>All staff</a>
          ${more ? `<div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">More</button><ul class="dropdown-menu dropdown-menu-end">${more}</ul></div>` : ""}
        </div>
      </div>
      ${p.status === "left" ? `<div class="alert alert-secondary d-flex gap-2 mt-3 mb-0"><i class="ri-information-line"></i><span>Left on ${A.day(p.end_date)} - their record and payslips are kept.</span></div>` : ""}
      ${!can.edit && p.place.id !== CTX.place.id ? `<div class="alert alert-light border d-flex gap-2 mt-3 mb-0"><i class="ri-eye-line"></i><span>Viewing only - ${esc(p.place.name)} keeps this record.</span></div>` : ""}
    </div>`;
    $("hrSlipsFigure").textContent = p.payslips.length ? `${p.payslips.length} ${p.payslips.length === 1 ? "month" : "months"}` : "None yet";
    $("hrHistFigure").textContent = `${p.postings.length} ${p.postings.length === 1 ? "entry" : "entries"}`;
    $("hrDocsFigure").textContent = p.documents.length ? `${p.documents.length} of 5` : "None yet";
  }

  // ------------------------------------------------------------ the tabs

  function overview() {
    const pay = `<div class="d-flex justify-content-between py-1"><span>Basic pay</span><span>${A.money(p.basic_pay)}</span></div>${p.allowances.map((a) => `<div class="d-flex justify-content-between py-1"><span>${esc(a.name)}</span><span>${A.money(a.amount)}</span></div>`).join("")}<div class="d-flex justify-content-between py-2 border-top mt-1"><strong>Pay a month</strong><strong>${A.money(p.gross)}</strong></div><p class="acc-sub mb-0">Before any SACCO or loan payroll takes in a month.</p>`;
    $("hrMain").innerHTML = `
      ${card('<i class="ri-briefcase-4-line me-1 text-primary"></i>The job', `<ul class="mr-rows">
        ${row("ri-briefcase-4-line", "Position", p.position ? esc(p.position) : none)}
        ${row("ri-bar-chart-box-line", "Grade", p.grade ? `${esc(p.grade.code)} · ${esc(p.grade.name)}` : none)}
        ${row("ri-time-line", "Type", esc(p.employment_type_label))}
        ${row("ri-calendar-check-line", "Started", p.start_date ? A.day(p.start_date) : none)}
        ${row("ri-file-warning-line", "Contract ends", p.contract_end ? A.day(p.contract_end) : '<span class="acc-sub">No end date</span>')}
        ${p.end_date ? row("ri-logout-box-r-line", "Left", A.day(p.end_date)) : ""}
      </ul>`)}
      ${card('<i class="ri-money-dollar-box-line me-1 text-success"></i>Pay a month', pay)}
      ${card('<i class="ri-bank-card-line me-1 text-purple"></i>How they are paid', `<ul class="mr-rows">
        ${row("ri-bank-card-line", "Paid by", `${A.methodChip({ paybill: "mpesa", till: "mpesa" }[p.pay_method] || p.pay_method, p.pay_method_label)}`)}
        ${row("ri-send-plane-line", "Pay to", p.pay_to ? esc(p.pay_to) : none)}
        ${row("ri-lock-line", "ID number", p.id_number ? esc(p.id_number) : none)}
        ${row("ri-lock-line", "KRA PIN", p.kra_pin ? esc(p.kra_pin) : none)}
      </ul><p class="acc-sub mb-0 mt-2"><i class="ri-shield-keyhole-line me-1"></i>Kept encrypted - only the last four are ever shown.</p>`)}`;
    const memberLink = p.member_link ? `<a class="btn btn-sm btn-outline-primary mt-2" href="${CTX.membersUrl}/member?id=${p.member_link}"><i class="ri-contacts-book-2-line me-1"></i>Open their member record</a>` : "";
    $("hrSide").innerHTML = `
      ${card("At a glance", `<div class="glance-tiles">
        ${glance("ri-wallet-3-line", "success", "Paid this year", p.totals.this_year ? A.money(p.totals.this_year) : null, "Net, after any deduction")}
        ${glance("ri-calendar-check-line", "primary", "Months paid", p.totals.months ? String(p.totals.months) : null, p.paid.last_month ? `Last: ${p.payslips[0]?.label || p.paid.last_month}` : "")}
        ${glance("ri-money-dollar-circle-line", "purple", "Paid in all", p.totals.all ? A.money(p.totals.all) : null)}
        ${glance("ri-file-warning-line", p.contract_end ? "warning" : "secondary", "Contract ends", p.contract_end ? A.day(p.contract_end) : null)}
      </div>`)}
      ${card("Who they are", `<ul class="mr-rows">
        ${row("ri-contacts-book-2-line", "Member record", p.person ? `${esc(p.person.name)}<br><span class="acc-sub">${esc(p.person.church || "")}</span>` : '<span class="acc-sub">Not linked</span>')}
        ${row("ri-user-settings-line", "Login", p.user ? esc(p.user.name) : '<span class="acc-sub">None</span>')}
      </ul>${memberLink}`)}`;
  }

  function payslips() {
    $("hrSide").innerHTML = "";
    $("hrMain").innerHTML = card(
      '<i class="ri-file-list-3-line me-1 text-success"></i>Payslips',
      p.payslips.length
        ? `<div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>Month</th><th class="d-none d-md-table-cell">Place</th><th class="text-end d-none d-sm-table-cell">Gross</th><th class="text-end d-none d-sm-table-cell">Deductions</th><th class="text-end">Net</th><th>Status</th><th class="text-end">PDF</th></tr></thead><tbody>${p.payslips
            .map((s) => `<tr><td><div class="fw-semibold">${esc(s.label)}</div><div class="acc-sub">${esc(s.position || "")}</div></td><td class="d-none d-md-table-cell">${esc(s.place || "")}</td><td class="text-end d-none d-sm-table-cell">${A.money(s.gross)}</td><td class="text-end d-none d-sm-table-cell">${s.deductions ? A.money(s.deductions) : '<span class="acc-sub">None</span>'}</td><td class="text-end"><strong>${A.money(s.net)}</strong></td><td><span class="badge bg-${RUN[s.status]?.[0] || "secondary"} text-white">${RUN[s.status]?.[1] || esc(s.status)}</span></td><td class="text-end">${A.pdfButton("accounting.payslips", { record_id: s.run_id }, `Payslips ${s.label}`, "Payslip")}</td></tr>`)
            .join("")}</tbody></table></div>`
        : A.empty("ri-file-list-3-line", "Not paid yet", "Their payslips show here once a month's payroll is approved."),
      p.totals.months ? `<span class="soft-chip soft-success">${A.money(p.totals.all)} in all</span>` : "",
    );
  }

  function served() {
    $("hrSide").innerHTML = "";
    const COLOR = { hired: "success", transferred: "purple", changed: "primary", left: "secondary" };
    $("hrMain").innerHTML = card(
      '<i class="ri-route-line me-1 text-purple"></i>Where they have served',
      p.postings.length
        ? `<ol class="ev-timeline">${p.postings
            .map((x) => `<li style="--q: var(--${COLOR[x.reason]}-rgb)"><span class="ev-timeline-dot"></span><div><span class="ev-timeline-when">${A.day(x.from)}${x.reason !== "left" ? ` - ${x.to ? A.day(x.to) : "now"}` : ""}</span><span class="ev-timeline-what fw-semibold">${esc(x.reason_label)} · ${esc(x.place || "")}</span><div class="acc-sub">${esc(x.position || "")}${x.note ? ` · ${esc(x.note)}` : ""}</div></div></li>`)
            .join("")}</ol>`
        : '<p class="mb-0">No history yet.</p>',
    );
  }

  function papers() {
    $("hrSide").innerHTML = "";
    const list = p.documents.length
      ? `<ul class="list-unstyled mb-3">${p.documents.map((d) => `<li class="d-flex align-items-center gap-2 py-2 border-bottom"><i class="${d.mime === "application/pdf" ? "ri-file-pdf-line text-danger" : "ri-image-line text-primary"} fs-20"></i><a href="#" data-doc="${d.id}" class="flex-fill text-truncate fw-semibold">${esc(d.name)}</a><span class="acc-sub d-none d-sm-inline">${A.day(d.added_at)}</span>${p.can.edit ? `<button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-undoc="${d.id}" aria-label="Remove ${esc(d.name)}"><i class="ri-delete-bin-line"></i></button>` : ""}</li>`).join("")}</ul>`
      : '<p class="mb-3">No papers yet - a contract or an ID copy.</p>';
    const add = p.can.edit && p.documents.length < 5 ? `<div class="row g-2"><div class="col-sm-5"><label class="form-label" for="docName">What is it?</label><input type="text" class="form-control" id="docName" maxlength="100" placeholder="e.g. Contract, ID copy"></div><div class="col-sm-7"><label class="form-label" for="docFile">The file</label><input type="file" class="form-control" id="docFile" accept=".jpg,.jpeg,.png,.webp,.pdf"></div><div class="col-12 acc-sub">A photo or a PDF, up to 5 MB.</div></div>` : "";
    $("hrMain").innerHTML = card('<i class="ri-attachment-2 me-1 text-warning"></i>Papers', list + add, `<span class="soft-chip soft-warning">${p.documents.length} of 5</span>`);
  }

  function showTab() {
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    ({ overview, payslips, history: served, papers })[tab]();
    // Only the overview has a side column; the rest use the full width.
    $("hrMain").className = tab === "overview" ? "col-xl-8" : "col-12";
    $("hrSide").hidden = tab !== "overview";
  }

  async function load() {
    const res = await API.person(ID);
    if (!res.ok) {
      $("hrHero").innerHTML = `<div class="card-body">${A.errorBox(res.message)}</div>`;
      return;
    }
    p = res.data;
    document.title = `${p.name} - Staff - Makueni West Diocese`;
    document.querySelector(".breadcrumb-item.active") && (document.querySelector(".breadcrumb-item.active").textContent = p.name);
    $("hrTabs").hidden = false;
    hero();
    showTab();
  }

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", async () => {
    if (!ID) {
      $("hrHero").innerHTML = `<div class="card-body">${A.errorBox("Open someone from the Staff list.")}</div>`;
      return;
    }
    const o = await API.options();
    if (o.ok) HrWindows.setup(o.data, load);
    load();
    $("hrTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const q = new URLSearchParams(window.location.search);
      tab === "overview" ? q.delete("tab") : q.set("tab", tab);
      history.replaceState(null, "", `${window.location.pathname}?${q}`);
      showTab();
    });
    $("hrHero").addEventListener("click", (e) => {
      const b = e.target.closest("[data-act]");
      if (!b || !p) return;
      if (b.dataset.act === "edit") return HrWindows.person(p);
      if (b.dataset.act === "move") return HrWindows.move(p);
      if (b.dataset.act === "end") return HrWindows.end(p);
      if (b.dataset.act === "remove") return HrWindows.remove(p, () => (window.location.href = back()));
    });
    $("hrPanes").addEventListener("click", async (e) => {
      const d = e.target.closest("[data-doc]");
      if (d) {
        e.preventDefault();
        return API.openDocument(p.id, d.dataset.doc);
      }
      const u = e.target.closest("[data-undoc]");
      if (u) {
        const out = await API.removeDocument(p.id, u.dataset.undoc);
        out.ok ? Toast.success(out.message) : Toast.error(out.message);
        if (out.ok) load();
      }
    });
    $("hrPanes").addEventListener("change", async (e) => {
      if (e.target.id !== "docFile" || !e.target.files[0]) return;
      const out = await API.addDocument(p.id, e.target.files[0], $("docName").value.trim());
      out.ok ? Toast.success(out.message) : Toast.error(out.message);
      if (out.ok) load();
    });
  });
})();
