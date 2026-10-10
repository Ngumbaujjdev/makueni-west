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
      <div class="acc-rec-facts">
        <div><span>Position</span><strong>${esc(p.position || "None")}</strong></div>
        <div><span>Grade</span><strong>${p.grade ? esc(p.grade.code) : "None"}</strong></div>
        <div><span>Place</span><strong>${esc(p.place.name)}</strong></div>
        <div><span>Pay a month</span><strong>${A.money(p.gross)}</strong></div>
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
    const kv = (rows) => `<table class="table table-borderless table-sm mb-0">${rows.filter(Boolean).map(([k, v]) => `<tr><td class="acc-sub ps-0" style="width:40%">${k}</td><td class="fw-semibold text-end pe-0">${v}</td></tr>`).join("")}</table>`;
    $("hrMain").innerHTML = `<div class="row g-3">
      <div class="col-lg-6">${card('<i class="ri-briefcase-4-line me-1 text-primary"></i>The job', kv([
        ["Position", p.position ? esc(p.position) : none],
        ["Grade", p.grade ? `${esc(p.grade.code)} · ${esc(p.grade.name)}` : none],
        ["Type", esc(p.employment_type_label)],
        ["Started", p.start_date ? A.day(p.start_date) : none],
        ["Contract ends", p.contract_end ? A.day(p.contract_end) : "No end date"],
        p.end_date && ["Left", A.day(p.end_date)],
      ]))}</div>
      <div class="col-lg-6">${card('<i class="ri-money-dollar-box-line me-1 text-success"></i>Pay a month', kv([["Basic pay", A.money(p.basic_pay)], ...p.allowances.map((a) => [esc(a.name), A.money(a.amount)])]) + `<div class="d-flex justify-content-between border-top pt-2 mt-1"><strong>Total</strong><strong class="fs-16">${A.money(p.gross)}</strong></div>`)}</div>
      <div class="col-12">${card('<i class="ri-bank-card-line me-1 text-purple"></i>How they are paid', `<div class="row g-3"><div class="col-md-6">${kv([["Paid by", A.methodChip({ paybill: "mpesa", till: "mpesa" }[p.pay_method] || p.pay_method, p.pay_method_label)], ["Pay to", p.pay_to ? esc(p.pay_to) : none]])}</div><div class="col-md-6">${kv([["ID number", p.id_number ? esc(p.id_number) : none], ["KRA PIN", p.kra_pin ? esc(p.kra_pin) : none]])}</div></div>`)}</div>
    </div>`;
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

  /** A table with search, pills, sort and pages (the Accounting kit, one prefix per table). */
  const tableCard = (title, icon, color, id, head, extra = "") =>
    card(`<span class="d-inline-flex align-items-center gap-2"><span class="avatar avatar-xs avatar-rounded bg-${color} text-white"><i class="${icon}"></i></span>${title}</span>`, `<div class="table-responsive"><table class="table table-hover mb-0 acc-table" id="${id}"><thead><tr>${head}</tr></thead><tbody></tbody></table></div>`, extra).replace('<div class="card-body">', '<div class="card-body p-0">');

  function payslips() {
    $("hrSide").innerHTML = "";
    const years = [...new Set(p.payslips.map((s) => s.month.slice(0, 4)))];
    const byYear = years.map((y) => {
      const list = p.payslips.filter((s) => s.month.startsWith(y));
      return `<div class="col-sm-4 col-lg-3"><div class="border rounded-3 p-3 h-100"><span class="acc-sub">${y}</span><div class="fw-semibold fs-16">${A.money(list.reduce((t, s) => t + s.net, 0), { cents: false })}</div><span class="acc-sub">${list.length} ${list.length === 1 ? "month" : "months"} paid</span></div></div>`;
    });
    $("hrMain").innerHTML = (years.length ? `<div class="row g-3 mb-3">${byYear.join("")}</div>` : "") + tableCard("Payslips", "ri-file-list-3-line", "success", "hrSlips", '<th>Month</th><th class="d-none d-md-table-cell">Place</th><th class="text-end d-none d-sm-table-cell">Gross</th><th class="text-end d-none d-sm-table-cell">Deductions</th><th class="text-end">Net</th><th>Status</th><th class="text-end">PDF</th>');
    A.tableKit({
      tableId: "hrSlips",
      prefix: "s_",
      items: p.payslips,
      noun: "months",
      search: "Search a month or place...",
      pills: years.map((y, i) => ({ key: `y${y}`, label: y, icon: "ri-calendar-line", color: ["primary", "success", "purple", "warning"][i % 4], test: (s) => s.month.startsWith(y) })),
      sorts: [
        { key: "new", label: "Newest first", order: [[0, "desc"]] },
        { key: "old", label: "Oldest first", order: [[0, "asc"]] },
        { key: "net", label: "Highest net", order: [[4, "desc"]] },
      ],
      nonSortable: [6],
      empty: A.empty("ri-file-list-3-line", "Not paid yet", "Payslips show here once a month's payroll is approved."),
      rowHtml: (s) => `<tr data-pills="y${s.month.slice(0, 4)}"><td data-order="${s.month}"><div class="fw-semibold">${esc(s.label)}</div><div class="acc-sub">${esc(s.position || "")}</div></td><td class="d-none d-md-table-cell">${esc(s.place || "")}</td><td class="text-end d-none d-sm-table-cell" data-order="${s.gross}">${A.money(s.gross)}</td><td class="text-end d-none d-sm-table-cell" data-order="${s.deductions}">${s.deductions ? A.money(s.deductions) : '<span class="acc-sub">None</span>'}</td><td class="text-end" data-order="${s.net}"><strong>${A.money(s.net)}</strong></td><td><span class="badge bg-${RUN[s.status]?.[0] || "secondary"} text-white">${RUN[s.status]?.[1] || esc(s.status)}</span></td><td class="text-end">${A.pdfButton("accounting.payslips", { record_id: s.run_id }, `Payslips ${s.label}`, "Payslip")}</td></tr>`,
    });
  }

  function served() {
    $("hrSide").innerHTML = "";
    const COLOR = { hired: "success", transferred: "purple", changed: "primary", left: "secondary" };
    const reasons = [...new Set(p.postings.map((x) => x.reason))];
    $("hrMain").innerHTML = tableCard("Where they have served", "ri-route-line", "purple", "hrPosts", '<th>Place</th><th>Position</th><th>From</th><th>To</th><th>Why</th>');
    A.tableKit({
      tableId: "hrPosts",
      prefix: "h_",
      items: p.postings,
      noun: "entries",
      search: "Search a place or position...",
      pills: reasons.length > 1 ? reasons.map((r) => ({ key: r, label: p.postings.find((x) => x.reason === r).reason_label, icon: "ri-route-line", color: COLOR[r] || "primary", test: (x) => x.reason === r })) : [],
      sorts: [
        { key: "new", label: "Newest first", order: [[2, "desc"]] },
        { key: "old", label: "Oldest first", order: [[2, "asc"]] },
      ],
      empty: A.empty("ri-route-line", "No history yet", ""),
      rowHtml: (x) => `<tr data-pills="${x.reason}"><td><div class="fw-semibold">${esc(x.place || "")}</div>${x.note ? `<div class="acc-sub">${esc(x.note)}</div>` : ""}</td><td>${esc(x.position || "-")}</td><td data-order="${x.from}">${A.dateChip(x.from)}</td><td data-order="${x.to || "9999"}">${x.reason === "left" ? "-" : x.to ? A.dateChip(x.to) : '<span class="soft-chip soft-success">Now</span>'}</td><td><span class="badge bg-${COLOR[x.reason] || "secondary"} ${A.textOn(COLOR[x.reason] || "secondary")}">${esc(x.reason_label)}</span></td></tr>`,
    });
  }

  function papers() {
    $("hrSide").innerHTML = "";
    const files = p.documents
      .map((d) => `<div class="col-sm-6 col-lg-4"><div class="border rounded-3 p-3 d-flex align-items-center gap-3 h-100"><span class="avatar avatar-md avatar-rounded bg-${d.mime === "application/pdf" ? "danger" : "primary"} text-white flex-shrink-0"><i class="${d.mime === "application/pdf" ? "ri-file-pdf-line" : "ri-image-line"} fs-18"></i></span><div class="min-w-0 flex-fill"><a href="#" data-doc="${d.id}" class="fw-semibold d-block text-truncate">${esc(d.name)}</a><span class="acc-sub">${A.day(d.added_at)} · ${Math.max(1, Math.round((d.size || 0) / 1024))} KB</span></div>${p.can.edit ? `<button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-undoc="${d.id}" title="Remove" aria-label="Remove ${esc(d.name)}"><i class="ri-delete-bin-line"></i></button>` : ""}</div></div>`)
      .join("");
    const add = p.can.edit && p.documents.length < 5 ? `<div class="col-sm-6 col-lg-4"><label class="border border-2 border-dashed rounded-3 p-3 d-flex flex-column gap-2 h-100 mb-0" for="docFile" style="cursor:pointer"><span class="fw-semibold"><i class="ri-upload-cloud-2-line me-1 text-primary"></i>Add a paper</span><input type="text" class="form-control form-control-sm" id="docName" maxlength="100" placeholder="What is it? e.g. Contract"><input type="file" class="form-control form-control-sm" id="docFile" accept=".jpg,.jpeg,.png,.webp,.pdf"><span class="acc-sub">Photo or PDF, up to 5 MB</span></label></div>` : "";
    $("hrMain").innerHTML = card('<span class="d-inline-flex align-items-center gap-2"><span class="avatar avatar-xs avatar-rounded bg-warning text-dark"><i class="ri-attachment-2"></i></span>Papers</span>', files || add ? `<div class="row g-3">${files}${add}</div>` : A.empty("ri-attachment-2", "No papers", ""), `<span class="soft-chip soft-warning">${p.documents.length} of 5</span>`);
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
