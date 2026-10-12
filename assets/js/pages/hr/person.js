/**
 * ============================================================================
 * STAFF - one person's page (hr/person.php?id=, every level)
 * ============================================================================
 * docs/specs/hr-spec.md. The template's candidate page (job-candidate-
 * details): a profile card on the left - who they are, their job, how they
 * are paid, what they can be given - and on the right their pay (with a chart
 * of the months paid), every payslip, where they have served and their
 * papers. ID numbers and KRA PINs only ever come masked.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const API = HrAPI;
  const CTX = window.HR_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const params = new URLSearchParams(window.location.search);
  const ID = Number(params.get("id"));
  let p = null;
  let chart = null;
  let tab = ["payslips", "history", "papers"].includes(params.get("tab")) ? params.get("tab") : "overview";

  const ST = {
    active: ["success", "ri-user-follow-line", "Working"],
    starting: ["primary", "ri-calendar-event-line", "Starting"],
    off: ["secondary", "ri-pause-circle-line", "Off the payroll"],
    left: ["danger", "ri-user-unfollow-line", "Left"],
  };
  const TYPE_COLOR = { full_time: "primary", part_time: "success", contract: "warning", casual: "purple" };
  const RUN = { posted: ["primary", "ri-checkbox-circle-line", "Approved - to pay"], paid: ["success", "ri-check-double-line", "Paid"] };
  const CYCLE = ["warning", "purple", "pink", "info", "success", "danger"];
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const money = (v) => A.money(v, { cents: false });
  const tile = (icon, color, size = "sm") => `<span class="avatar avatar-${size} avatar-rounded bg-${color} ${A.textOn(color)} flex-shrink-0"><i class="${icon}"></i></span>`;
  const none = '<span class="soft-chip soft-secondary">Not given</span>';
  const card = (title, icon, color, body, extra = "", flush = false) =>
    `<div class="card custom-card flex-fill"><div class="card-header justify-content-between"><div class="card-title d-flex align-items-center gap-2">${tile(icon, color, "xs")}${title}</div>${extra}</div><div class="card-body${flush ? " p-0" : ""}">${body}</div></div>`;
  const back = () => `${CTX.baseUrl}/${params.get("territory_id") ? `?territory_id=${params.get("territory_id")}` : ""}`;
  const monthName = (m, opts = { month: "short" }) => new Date(`${m}-01T12:00:00`).toLocaleDateString("en-GB", opts);

  // ------------------------------------------------------------ the profile card

  /** How long they have served: "1 yr 9 mo", "4 mo", "New". */
  function tenure(from, to) {
    const a = new Date(`${from}T12:00:00`);
    const b = to ? new Date(`${to}T12:00:00`) : new Date();
    const months = (b.getFullYear() - a.getFullYear()) * 12 + b.getMonth() - a.getMonth() - (b.getDate() < a.getDate() ? 1 : 0);
    if (months < 1) return "New";
    const y = Math.floor(months / 12);
    const m = months % 12;
    return [y ? `${y} yr` : "", m ? `${m} mo` : ""].filter(Boolean).join(" ");
  }

  /** One fact on the profile card: a coloured tile, a small label and the value. */
  const fact = (icon, color, label, value) => `<li class="d-flex align-items-center gap-3 mb-3">${tile(icon, color)}<div class="min-w-0 flex-fill"><span class="d-block fs-12 fw-medium">${label}</span><span class="d-block fw-semibold text-break">${value}</span></div></li>`;

  function profile() {
    const can = p.can;
    const sms = p.phone ? `sms:${p.phone.replace(/\s+/g, "")}` : null;
    const more = [
      can.transfer && p.status !== "left" ? '<li><button class="dropdown-item" type="button" data-act="move"><i class="ri-arrow-left-right-line me-2 text-purple"></i>Move to another place</button></li>' : "",
      can.end ? '<li><button class="dropdown-item" type="button" data-act="end"><i class="ri-logout-box-r-line me-2 text-warning"></i>They leave</button></li>' : "",
      can.delete && p.can_delete ? '<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger" type="button" data-act="remove"><i class="ri-delete-bin-line me-2"></i>Remove</button></li>' : "",
    ].join("");
    const paid = { mpesa: "mpesa", paybill: "mpesa", till: "mpesa" }[p.pay_method] || p.pay_method;
    $("hrProfile").innerHTML = `<div class="card-body p-0">
      <div class="p-4 border-bottom text-center">
        <span class="d-inline-block mb-3">${A.avatar(p.name, "xxl")}</span>
        <h5 class="fw-semibold mb-1">${esc(p.name)}</h5>
        <p class="mb-2 fw-medium"><i class="ri-briefcase-4-line me-1 text-primary"></i>${esc(p.position || "No position")}${p.grade ? ` · ${esc(p.grade.code)}` : ""}</p>
        <div class="d-flex justify-content-center flex-wrap gap-1 mb-3">${pill(p.status)}<span class="badge bg-${TYPE_COLOR[p.employment_type] || "secondary"} ${A.textOn(TYPE_COLOR[p.employment_type] || "secondary")}">${esc(p.employment_type_label)}</span></div>
        <div class="btn-list d-flex justify-content-center flex-wrap gap-1">
          ${can.edit ? '<button type="button" class="btn btn-sm btn-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Change</button>' : ""}
          ${can.edit ? '<button type="button" class="btn btn-sm btn-success" data-act="pay"><i class="ri-hand-coin-line me-1"></i>Pay</button>' : ""}
          ${sms ? `<a class="btn btn-sm btn-purple" href="${sms}"><i class="ri-chat-3-line me-1"></i>Text</a>` : ""}
          ${more ? `<div class="dropdown d-inline-block"><button class="btn btn-sm btn-icon btn-outline-primary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="ri-more-2-fill"></i></button><ul class="dropdown-menu dropdown-menu-end">${more}</ul></div>` : ""}
        </div>
      </div>
      <div class="p-4 border-bottom">
        <span class="d-block fs-12 fw-semibold text-uppercase mb-1">Pay a month</span>
        <span class="d-block fs-20 fw-bold text-success text-nowrap mb-2">${A.money(p.gross)}</span>
        <span class="acc-method">${A.methodLogo(paid, "xs")}<span>${esc(p.pay_method_label)}${p.pay_to ? ` · ${esc(p.pay_to)}` : ""}</span></span>
        <div class="d-flex flex-wrap gap-1 mt-2"><span class="soft-chip soft-primary">Basic <b>${money(p.basic_pay)}</b></span>${p.allowances.map((a, i) => `<span class="soft-chip soft-${CYCLE[i % CYCLE.length] === "info" ? "primary" : CYCLE[i % CYCLE.length]}">${esc(a.name)} <b>${money(a.amount)}</b></span>`).join("")}</div>
      </div>
      <div class="p-4 border-bottom"><ul class="list-unstyled mb-0">
        ${fact("ri-map-pin-line", "purple", "Place", esc(p.place.name))}
        ${fact("ri-phone-line", "success", "Phone", p.phone ? `<a href="tel:${esc(p.phone)}">${esc(p.phone)}</a>` : none)}
        ${p.email ? fact("ri-mail-line", "primary", "Email", `<a href="mailto:${esc(p.email)}">${esc(p.email)}</a>`) : ""}
        ${fact("ri-calendar-line", "warning", "Started", p.start_date ? `${A.day(p.start_date)} <span class="soft-chip soft-warning ms-1">${tenure(p.start_date, p.end_date)}</span>` : none)}
        ${fact("ri-file-warning-line", p.contract_end ? "danger" : "info", "Contract ends", p.contract_end ? A.day(p.contract_end) : "No end date")}
        ${p.end_date ? fact("ri-logout-box-r-line", "danger", "Left", A.day(p.end_date)) : ""}
      </ul></div>
      <div class="p-4"><ul class="list-unstyled mb-0">
        ${fact("ri-contacts-book-2-line", p.person ? "success" : "secondary", "Member record", p.person ? (p.member_link ? `<a href="${CTX.membersUrl}/member?id=${p.member_link}">${esc(p.person.name)}</a>` : esc(p.person.name)) : "Not linked")}
        ${fact("ri-user-settings-line", p.user ? "primary" : "secondary", "Login", p.user ? esc(p.user.name) : "None")}
        ${fact("ri-shield-keyhole-line", "pink", "ID number", p.id_number ? esc(p.id_number) : "Not given")}
        ${fact("ri-government-line", "info", "KRA PIN", p.kra_pin ? esc(p.kra_pin) : "Not given")}
      </ul></div>
      ${p.status === "left" ? `<div class="alert alert-danger d-flex gap-2 m-3 mt-0"><i class="ri-information-line"></i><span>Left on ${A.day(p.end_date)} - their record and payslips are kept.</span></div>` : ""}
      ${!can.edit && p.place.id !== CTX.place.id ? `<div class="alert alert-primary d-flex gap-2 m-3 mt-0"><i class="ri-eye-line"></i><span>Viewing only - ${esc(p.place.name)} keeps this record.</span></div>` : ""}
    </div>`;
    $("hrPayFigure").textContent = `${money(p.gross)} a month`;
    $("hrSlipsFigure").textContent = p.payslips.length ? `${p.payslips.length} ${p.payslips.length === 1 ? "month" : "months"}` : "None yet";
    $("hrHistFigure").textContent = `${p.postings.length} ${p.postings.length === 1 ? "entry" : "entries"}`;
    $("hrDocsFigure").textContent = p.documents.length ? `${p.documents.length} of 5` : "None yet";
  }

  // ------------------------------------------------------------ overview

  /** A small figure card (the template's HRM figures). */
  const figure = (icon, color, label, value, sub) =>
    `<div class="col-md-4 d-flex"><div class="card custom-card flex-fill"><div class="card-body d-flex align-items-center gap-3">${tile(icon, color, "md")}<div class="min-w-0"><span class="d-block fw-medium fs-12">${label}</span><span class="d-block fs-18 fw-semibold">${value}</span>${sub ? `<span class="d-block fs-12 fw-medium">${sub}</span>` : ""}</div></div></div></div>`;

  function overview() {
    const last12 = p.payslips.slice(0, 12).reverse();
    const COLOR = { hired: "success", transferred: "purple", changed: "primary", left: "danger" };
    $("hrMain").innerHTML = `
      <div class="row">
        ${figure("ri-wallet-3-line", "success", `Paid in ${new Date().getFullYear()}`, money(p.totals.this_year), "Net, after any deduction")}
        ${figure("ri-calendar-check-line", "primary", "Months paid", A.num(p.totals.months), p.payslips[0] ? `Last: ${esc(p.payslips[0].label)}` : "Not paid yet")}
        ${figure("ri-money-dollar-circle-line", "purple", "Paid in all", money(p.totals.all), p.start_date ? `Since ${A.day(p.start_date, { month: "short", year: "numeric" })}` : "")}
      </div>
      <div class="row">
        <div class="col-lg-7 d-flex">${card("Pay over time", "ri-bar-chart-2-line", "primary", last12.length ? '<div id="hrPayChart" style="min-height: 250px"></div>' : A.empty("ri-bar-chart-2-line", "Not paid yet", "Their months show here once a payroll is approved."), last12.length ? `<span class="soft-chip soft-primary">Last ${last12.length} ${last12.length === 1 ? "month" : "months"}</span>` : "")}</div>
        <div class="col-lg-5 d-flex">${card(
          "Pay a month",
          "ri-money-dollar-box-line",
          "success",
          `<ul class="list-unstyled mb-0">
            <li class="d-flex align-items-center justify-content-between gap-2 pb-2 mb-2 border-bottom"><span class="d-flex align-items-center gap-2 fw-medium">${tile("ri-money-dollar-box-line", "primary", "xs")}Basic pay</span><span class="fw-semibold">${A.money(p.basic_pay)}</span></li>
            ${p.allowances.map((a, i) => `<li class="d-flex align-items-center justify-content-between gap-2 pb-2 mb-2 border-bottom"><span class="d-flex align-items-center gap-2 fw-medium">${tile("ri-hand-coin-line", CYCLE[i % CYCLE.length], "xs")}${esc(a.name)}</span><span class="fw-semibold">${A.money(a.amount)}</span></li>`).join("")}
            <li class="d-flex align-items-center justify-content-between gap-2 pt-1"><span class="fw-bold">Total</span><span class="fs-18 fw-bold text-success">${A.money(p.gross)}</span></li>
          </ul>
          <div class="d-flex align-items-center gap-2 mt-3 p-2 rounded-3 bg-light"><span class="fw-medium">Paid to</span><span class="acc-method">${A.methodLogo({ mpesa: "mpesa", paybill: "mpesa", till: "mpesa" }[p.pay_method] || p.pay_method, "xs")}<span>${esc(p.pay_to || p.pay_method_label)}</span></span></div>`,
          p.can.edit ? '<button type="button" class="btn btn-sm btn-success" data-act="pay"><i class="ri-edit-line me-1"></i>Change pay</button>' : "",
        )}</div>
      </div>
      ${card(
        "Where they have served",
        "ri-route-line",
        "purple",
        `<ul class="list-unstyled timeline-widget mb-0 my-2">${p.postings
          .slice(0, 3)
          .map((x) => {
            const d = new Date(`${x.from}T12:00:00`);
            return `<li class="timeline-widget-list"><div class="d-flex align-items-top"><div class="me-5 text-center"><span class="d-block fs-20 fw-semibold text-primary">${d.toLocaleDateString("en-GB", { month: "short" })}</span><span class="d-block fs-12 fw-medium">${d.getFullYear()}</span></div><div class="flex-fill"><p class="mb-1 fw-semibold">${esc(x.place || "")}${x.position ? ` · ${esc(x.position)}` : ""}</p><p class="mb-0 fs-12 lh-1"><span class="badge bg-${COLOR[x.reason] || "secondary"} ${A.textOn(COLOR[x.reason] || "secondary")} me-2">${esc(x.reason_label)}</span>${x.to ? `until ${A.day(x.to)}` : x.reason === "left" ? "" : "now"}</p></div></div></li>`;
          })
          .join("")}</ul>`,
        p.postings.length > 3 ? '<button type="button" class="soft-chip soft-purple border-0" data-goto="history">All of it<i class="ri-arrow-right-line"></i></button>' : "",
      )}`;
    if (last12.length) {
      if (chart) chart.destroy();
      chart = new ApexCharts($("hrPayChart"), {
        series: [{ name: "Net pay", data: last12.map((s) => s.net) }],
        chart: { type: "bar", height: 250, toolbar: { show: false }, fontFamily: "Inter, sans-serif" },
        colors: [UI.cssColor("success")],
        plotOptions: { bar: { columnWidth: "45%", borderRadius: 3 } },
        dataLabels: { enabled: false },
        grid: { borderColor: "rgba(0, 0, 0, .08)", strokeDashArray: 3 },
        xaxis: { categories: last12.map((s) => `${monthName(s.month)} ${s.month.slice(2, 4)}`) },
        yaxis: { labels: { formatter: (v) => (v >= 1000 ? `${Math.round(v / 1000)}K` : v) } },
        tooltip: { y: { formatter: (v) => A.money(v) } },
      });
      chart.render();
    }
  }

  // ------------------------------------------------------------ payslips

  /** A table with search, pills, sort and pages (the Accounting kit, one prefix per table). */
  const tableCard = (title, icon, color, id, head, extra = "") => card(title, icon, color, `<div class="table-responsive"><table class="table table-hover text-nowrap mb-0 acc-table" id="${id}"><thead><tr>${head}</tr></thead><tbody></tbody></table></div>`, extra, true);

  function payslips() {
    const years = [...new Set(p.payslips.map((s) => s.month.slice(0, 4)))];
    const YC = ["success", "primary", "purple", "warning"];
    const byYear = years.map((y, i) => {
      const list = p.payslips.filter((s) => s.month.startsWith(y));
      const months = Array.from({ length: 12 }, (_, m) => list.find((s) => Number(s.month.slice(5)) === m + 1)?.net || 0);
      const c = YC[i % YC.length];
      return `<div class="col-xl-4 col-md-6 d-flex"><div class="card custom-card flex-fill"><div class="card-body"><div class="d-flex align-items-center gap-2 mb-2">${tile("ri-calendar-2-line", c)}<span class="fw-semibold flex-fill">${y}</span><span class="soft-chip soft-${c === "warning" ? "warning" : c}">${list.length} ${list.length === 1 ? "month" : "months"}</span></div><div class="d-flex align-items-end justify-content-between gap-2"><span class="fs-18 fw-bold text-success">${money(list.reduce((t, s) => t + s.net, 0))}</span><div class="kpi-spark" style="width: 96px" data-spark='${JSON.stringify({ labels: ["J", "F", "M", "A", "M", "J", "J", "A", "S", "O", "N", "D"], data: months })}' data-spark-color="${c}" data-spark-height="36"></div></div></div></div></div>`;
    });
    $("hrMain").innerHTML =
      (years.length ? `<div class="row">${byYear.slice(0, 3).join("")}</div>` : "") +
      tableCard("Payslips", "ri-file-list-3-line", "success", "hrSlips", '<th>Month</th><th class="d-none d-md-table-cell">Place</th><th class="text-end d-none d-sm-table-cell">Gross</th><th class="text-end d-none d-sm-table-cell">Deductions</th><th class="text-end">Net</th><th>Status</th><th class="text-end"></th>');
    UI.mountSparklines($("hrMain"));
    A.tableKit({
      tableId: "hrSlips",
      prefix: "s_",
      items: p.payslips,
      noun: "months",
      search: "Search a month or place...",
      pills: years.map((y, i) => ({ key: `y${y}`, label: y, icon: "ri-calendar-line", color: YC[i % YC.length], test: (s) => s.month.startsWith(y) })),
      sorts: [
        { key: "new", label: "Newest first", order: [[0, "desc"]] },
        { key: "old", label: "Oldest first", order: [[0, "asc"]] },
        { key: "net", label: "Highest net", order: [[4, "desc"]] },
      ],
      nonSortable: [6],
      empty: A.empty("ri-file-list-3-line", "Not paid yet", "Payslips show here once a month's payroll is approved."),
      rowHtml: (s) => {
        const [c, i, l] = RUN[s.status] || ["secondary", "ri-question-line", s.status];
        return `<tr data-pills="y${s.month.slice(0, 4)}">
          <td data-order="${s.month}"><div class="d-flex align-items-center gap-2"><span class="acc-date-tile"><strong>${monthName(s.month)}</strong><small>${s.month.slice(0, 4)}</small></span><div><span class="d-block fw-semibold">${esc(s.label)}</span><span class="d-block fs-12 fw-medium"><i class="ri-briefcase-4-line me-1 text-primary"></i>${esc(s.position || "-")}</span></div></div></td>
          <td class="d-none d-md-table-cell"><span class="d-inline-flex align-items-center gap-2">${tile("ri-map-pin-line", "purple", "xs")}${esc(s.place || "")}</span></td>
          <td class="text-end d-none d-sm-table-cell fw-medium" data-order="${s.gross}">${A.money(s.gross)}</td>
          <td class="text-end d-none d-sm-table-cell" data-order="${s.deductions}">${s.deductions ? `<span class="fw-semibold text-danger">- ${A.money(s.deductions)}</span>` : '<span class="soft-chip soft-secondary">None</span>'}</td>
          <td class="text-end fw-bold text-success" data-order="${s.net}">${A.money(s.net)}</td>
          <td><span class="badge bg-${c} ${A.textOn(c)}"><i class="${i} me-1"></i>${esc(l)}</span></td>
          <td class="text-end">${A.pdfButton("accounting.payslips", { record_id: s.run_id }, `Payslips ${s.label}`, "Payslip")}</td>
        </tr>`;
      },
    });
  }

  // ------------------------------------------------------------ where they served

  function served() {
    const COLOR = { hired: "success", transferred: "purple", changed: "primary", left: "danger" };
    const ICON = { hired: "ri-user-add-line", transferred: "ri-arrow-left-right-line", changed: "ri-briefcase-4-line", left: "ri-logout-box-r-line" };
    const reasons = [...new Set(p.postings.map((x) => x.reason))];
    $("hrMain").innerHTML = tableCard("Where they have served", "ri-route-line", "purple", "hrPosts", "<th>Place</th><th>Position</th><th>From</th><th>To</th><th>Why</th>");
    A.tableKit({
      tableId: "hrPosts",
      prefix: "h_",
      items: p.postings,
      noun: "entries",
      search: "Search a place or position...",
      pills: reasons.length > 1 ? reasons.map((r) => ({ key: r, label: p.postings.find((x) => x.reason === r).reason_label, icon: ICON[r] || "ri-route-line", color: COLOR[r] || "primary", test: (x) => x.reason === r })) : [],
      sorts: [
        { key: "new", label: "Newest first", order: [[2, "desc"]] },
        { key: "old", label: "Oldest first", order: [[2, "asc"]] },
      ],
      empty: A.empty("ri-route-line", "No history yet", ""),
      rowHtml: (x) => `<tr data-pills="${x.reason}">
        <td><div class="d-flex align-items-center gap-2">${tile(ICON[x.reason] || "ri-map-pin-line", COLOR[x.reason] || "primary")}<div><span class="d-block fw-semibold">${esc(x.place || "")}</span>${x.note ? `<span class="d-block fs-12 fw-medium">${esc(x.note)}</span>` : ""}</div></div></td>
        <td><span class="fw-medium"><i class="ri-briefcase-4-line me-1 text-primary"></i>${esc(x.position || "-")}</span></td>
        <td data-order="${x.from}">${A.dateChip(x.from)}</td>
        <td data-order="${x.to || "9999"}">${x.reason === "left" ? "-" : x.to ? A.dateChip(x.to) : '<span class="soft-chip soft-success">Now</span>'}</td>
        <td><span class="badge bg-${COLOR[x.reason] || "secondary"} ${A.textOn(COLOR[x.reason] || "secondary")}"><i class="${ICON[x.reason] || "ri-route-line"} me-1"></i>${esc(x.reason_label)}</span></td></tr>`,
    });
  }

  // ------------------------------------------------------------ papers

  function papers() {
    const files = p.documents
      .map((d) => `<div class="col-sm-6 col-xxl-4"><div class="border rounded-3 p-3 d-flex align-items-center gap-3 h-100">${tile(d.mime === "application/pdf" ? "ri-file-pdf-line" : "ri-image-line", d.mime === "application/pdf" ? "danger" : "primary", "md")}<div class="min-w-0 flex-fill"><a href="#" data-doc="${d.id}" class="fw-semibold d-block text-truncate">${esc(d.name)}</a><span class="fs-12 fw-medium">${A.day(d.added_at)} · ${Math.max(1, Math.round((d.size || 0) / 1024))} KB</span></div>${p.can.edit ? `<button type="button" class="btn btn-sm btn-icon btn-danger" data-undoc="${d.id}" title="Remove" aria-label="Remove ${esc(d.name)}"><i class="ri-delete-bin-line"></i></button>` : ""}</div></div>`)
      .join("");
    const add = p.can.edit && p.documents.length < 5 ? `<div class="col-sm-6 col-xxl-4"><label class="border border-2 border-dashed border-primary rounded-3 p-3 d-flex flex-column gap-2 h-100 mb-0" for="docFile" style="cursor:pointer"><span class="fw-semibold"><i class="ri-upload-cloud-2-line me-1 text-primary"></i>Add a paper</span><input type="text" class="form-control form-control-sm" id="docName" maxlength="100" placeholder="What is it? e.g. Contract"><input type="file" class="form-control form-control-sm" id="docFile" accept=".jpg,.jpeg,.png,.webp,.pdf"><span class="fs-12 fw-medium">Photo or PDF, up to 5 MB</span></label></div>` : "";
    $("hrMain").innerHTML = card("Papers", "ri-attachment-2", "warning", files || add ? `<div class="row g-3">${files}${add}</div>` : A.empty("ri-attachment-2", "No papers", ""), `<span class="soft-chip soft-warning">${p.documents.length} of 5</span>`);
  }

  function showTab() {
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    ({ overview, payslips, history: served, papers })[tab]();
  }

  function goTo(t) {
    tab = t;
    const q = new URLSearchParams(window.location.search);
    tab === "overview" ? q.delete("tab") : q.set("tab", tab);
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    showTab();
  }

  async function load() {
    const res = await API.person(ID);
    if (!res.ok) {
      $("hrProfile").innerHTML = `<div class="card-body">${A.errorBox(res.message)}</div>`;
      return;
    }
    p = res.data;
    document.title = `${p.name} - Staff - Makueni West Diocese`;
    document.querySelector(".breadcrumb-item.active") && (document.querySelector(".breadcrumb-item.active").textContent = p.name);
    $("hrTabs").hidden = false;
    profile();
    showTab();
  }

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", async () => {
    if (!ID) {
      $("hrProfile").innerHTML = `<div class="card-body">${A.errorBox("Open someone from the Staff list.")}</div>`;
      return;
    }
    $("hrMain").innerHTML = '<div class="card custom-card"><div class="card-body"><span class="skel skel-title"></span><span class="skel skel-line mt-3"></span><span class="skel skel-line mt-2" style="width:60%"></span></div></div>';
    const o = await API.options();
    if (o.ok) HrWindows.setup(o.data, load);
    load();
    $("hrTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (b) goTo(b.dataset.tab);
    });
    // Actions live on the profile card and in the panes.
    document.querySelector(".hr-page-person").addEventListener("click", async (e) => {
      const g = e.target.closest("[data-goto]");
      if (g) return goTo(g.dataset.goto);
      const b = e.target.closest("[data-act]");
      if (b && p) {
        if (b.dataset.act === "edit" || b.dataset.act === "pay") return HrWindows.person(p);
        if (b.dataset.act === "move") return HrWindows.move(p);
        if (b.dataset.act === "end") return HrWindows.end(p);
        if (b.dataset.act === "remove") return HrWindows.remove(p, () => (window.location.href = back()));
      }
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
