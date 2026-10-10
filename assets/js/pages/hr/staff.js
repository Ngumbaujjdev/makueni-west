/**
 * ============================================================================
 * STAFF - the people a place employs (hr/, every level)
 * ============================================================================
 * docs/specs/hr-spec.md: our staff - who they are (a member, a login, or a
 * name), their job, grade and pay, how they are paid, where they have served
 * and their papers - and, for a region or the diocese, the staff of the
 * places below, with moves between places. Payroll pays from these records.
 * ID numbers and KRA PINs only ever come masked.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const API = HrAPI;
  const CTX = window.HR_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const n = (v) => Math.round(parseFloat(String(v ?? "").replace(/[^0-9.]/g, "")) * 100) / 100 || 0;
  let opts = null;
  let tab = new URLSearchParams(window.location.search).get("tab") === "below" && CTX.can.below ? "below" : "ours";

  const ST = {
    active: ["success", "ri-user-follow-line", "Working"],
    starting: ["primary", "ri-calendar-event-line", "Starting"],
    off: ["secondary", "ri-pause-circle-line", "Off the payroll"],
    left: ["secondary", "ri-user-unfollow-line", "Left"],
  };
  const TYPE_COLOR = { full_time: "primary", part_time: "info", contract: "warning", casual: "purple" };
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const typeChip = (e) => `<span class="soft-chip soft-${TYPE_COLOR[e.employment_type] || "secondary"}">${esc(e.employment_type_label)}</span>`;
  const job = (e) => `${esc(e.position || "No position")}${e.grade ? ` <span class="acc-sub">· ${esc(e.grade.code)}</span>` : ""}`;
  const paidBy = (e) => `${A.methodChip({ paybill: "mpesa", till: "mpesa" }[e.pay_method] || e.pay_method, e.pay_method_label)}<div class="acc-sub mt-1">${esc(e.pay_to || "")}</div>`;
  const pay = (e) => `<strong>${A.money(e.gross)}</strong>${e.allowances.length ? `<div class="acc-sub">basic ${A.money(e.basic_pay)} + ${e.allowances.length} ${e.allowances.length === 1 ? "allowance" : "allowances"}</div>` : ""}`;
  const linked = (e) => (e.person ? '<span class="soft-chip soft-success" title="From the member register"><i class="ri-contacts-book-2-line"></i>Member</span>' : e.user ? '<span class="soft-chip soft-primary" title="Has a login"><i class="ri-user-settings-line"></i>Login</span>' : "");

  // ------------------------------------------------------------ the page

  function cards(here, ours) {
    const working = ours.filter((e) => e.status === "active" || e.status === "starting");
    K.statRow($("statCardsRow"), [
      { icon: "ri-team-line", label: "On the staff", sub: `${here.by_type.full_time || 0} full time · ${(here.by_type.part_time || 0) + (here.by_type.casual || 0)} part time or casual`, value: A.num(here.staff), color: "primary" },
      { icon: "ri-money-dollar-box-line", label: "Pay a month", sub: "Basic and allowances, before any deduction", value: A.figure(here.monthly_pay), color: "success" },
      { icon: "ri-contacts-book-2-line", label: "From the register", sub: "Linked to their member record or login", value: A.num(working.filter((e) => e.person || e.user).length), color: "purple" },
      { icon: "ri-file-warning-line", label: "Contracts ending", sub: "In the next 60 days", value: A.num(here.contracts_ending), color: here.contracts_ending ? "warning" : "info" },
    ]);
    $("hrOursFigure").textContent = `${here.staff} working`;
  }

  function table(id, items, withPlace) {
    const types = Object.keys(TYPE_COLOR).filter((k) => items.some((e) => e.employment_type === k && e.status !== "left"));
    A.tableKit({
      tableId: id,
      prefix: withPlace ? "b_" : "",
      items,
      noun: "people",
      search: withPlace ? "Search name, job, place..." : "Search name, job, phone...",
      pills: [
        { key: "working", label: "Working", icon: "ri-user-follow-line", color: "success", test: (e) => e.status !== "left" },
        ...types.map((k) => ({ key: k, label: items.find((e) => e.employment_type === k).employment_type_label, icon: "ri-time-line", color: TYPE_COLOR[k], test: (e) => e.employment_type === k && e.status !== "left" })),
        { key: "left", label: "Left", icon: "ri-user-unfollow-line", color: "secondary", test: (e) => e.status === "left" },
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[0, "asc"]] },
        { key: "big", label: "Highest pay first", order: [[3, "desc"]] },
        ...(withPlace ? [{ key: "place", label: "By place", order: [[1, "asc"]] }] : []),
      ],
      empty: withPlace
        ? A.empty("ri-community-line", "No staff in the places below yet", "As churches add the people they employ, they show here.")
        : A.empty("ri-team-line", "Nobody on the staff yet", "Add the people this place employs - a pastor, a secretary, a caretaker.", CTX.can.manage ? '<button type="button" class="btn btn-primary" data-first><i class="ri-user-add-line me-1"></i>Add a person</button>' : ""),
      rowHtml: (e) => `<tr class="acc-row${e.status === "left" ? " opacity-75" : ""}" data-id="${e.id}" data-pills="${e.status === "left" ? "left" : `working ${e.employment_type}`}">
          <td data-order="${esc(e.name)}"><div class="d-flex align-items-center gap-2">${A.avatar(e.name, "sm")}<div class="min-w-0"><div class="fw-semibold">${esc(e.name)} ${linked(e)}</div><div class="acc-sub">${esc(e.phone || "")}${e.start_date ? `${e.phone ? " · " : ""}since ${A.day(e.start_date)}` : ""}</div></div></div></td>
          ${withPlace ? `<td data-order="${esc(e.place.name)}">${esc(e.place.name)}</td>` : ""}
          <td class="d-none d-md-table-cell">${job(e)}<div class="mt-1">${typeChip(e)}</div></td>
          ${withPlace ? "" : `<td class="d-none d-lg-table-cell">${paidBy(e)}</td>`}
          <td class="text-end" data-order="${e.gross}">${pay(e)}</td>
          <td>${pill(e.status)}${e.contract_end && e.status !== "left" ? `<div class="acc-sub mt-1">Contract to ${A.day(e.contract_end)}</div>` : ""}</td>
        </tr>`,
    });
  }

  function showTab() {
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("hrOursPane").hidden = tab !== "ours";
    if ($("hrBelowPane")) $("hrBelowPane").hidden = tab !== "below";
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("hrOursRows").innerHTML = UI.renderTableLoading(5);
    const [ov, ours, below, o] = await Promise.all([API.overview(), API.staff({ status: "all", per: 200 }), CTX.can.below ? API.staff({ status: "all", per: 200, below: 1 }) : null, opts ? { ok: true, data: opts } : API.options()]);
    if (!ov.ok || !ours.ok) {
      $("hrOursWrap").innerHTML = A.errorBox((ov.ok ? ours : ov).message);
      return;
    }
    opts = o.ok ? o.data : null;
    cards(ov.data.here, ours.data.rows);
    table("hrOursTable", ours.data.rows, false);
    if (below?.ok) {
      const rows = below.data.rows.filter((e) => e.place.id !== ov.data.place.id);
      table("hrBelowTable", rows, true);
      const places = ov.data.below.length;
      $("hrBelowFigure").textContent = `${rows.filter((e) => e.status !== "left").length} in ${places} ${places === 1 ? "place" : "places"}`;
    }
    showTab();
  }

  // ------------------------------------------------------------ one person

  async function view(id) {
    const res = await API.person(id);
    if (!res.ok) return Toast.error(res.message);
    const e = res.data;
    const fact = (label, value) => (value ? `<div class="col-sm-6"><div class="acc-sub">${esc(label)}</div><div>${value}</div></div>` : "");
    const postings = e.postings
      .map((p) => `<li class="d-flex gap-3 py-2 border-bottom"><span class="avatar avatar-sm avatar-rounded bg-${{ hired: "success", transferred: "purple", changed: "primary", left: "secondary" }[p.reason]} text-white flex-shrink-0"><i class="${{ hired: "ri-user-add-line", transferred: "ri-arrow-left-right-line", changed: "ri-briefcase-4-line", left: "ri-logout-box-r-line" }[p.reason]}"></i></span><div class="min-w-0"><div class="fw-semibold">${esc(p.reason_label)} - ${esc(p.place || "")}</div><div class="acc-sub">${esc(p.position || "")}${p.position ? " · " : ""}${A.day(p.from)}${p.reason !== "left" ? ` to ${p.to ? A.day(p.to) : "now"}` : ""}${p.note ? ` · ${esc(p.note)}` : ""}</div></div></li>`)
      .join("");
    const docs = e.documents.length
      ? `<ul class="list-unstyled mb-2">${e.documents.map((d) => `<li class="d-flex align-items-center gap-2 py-1"><i class="${d.mime === "application/pdf" ? "ri-file-pdf-line text-danger" : "ri-image-line text-primary"} fs-18"></i><a href="#" data-doc="${d.id}" class="flex-fill text-truncate">${esc(d.name)}</a>${e.can.edit ? `<button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-undoc="${d.id}" aria-label="Remove ${esc(d.name)}"><i class="ri-delete-bin-line"></i></button>` : ""}</li>`).join("")}</ul>`
      : '<p class="acc-sub mb-2">No papers yet - a contract or an ID copy.</p>';
    const el = K.confirmWindow({
      title: e.name,
      subtitle: `${e.position || "No position"} · ${e.place.name}`,
      icon: "ri-user-line",
      go: '<i class="ri-edit-line me-1"></i>Change',
      body: K.parts([
        { icon: "ri-user-line", title: "Who", body: `<div class="row g-3">${fact("Status", pill(e.status))}${fact("Member record", e.person ? `${esc(e.person.name)} <span class="acc-sub">· ${esc(e.person.church || "")}</span>` : "")}${fact("Login", e.user ? esc(e.user.name) : "")}${fact("Phone", esc(e.phone || ""))}${fact("Email", esc(e.email || ""))}${fact("ID number", esc(e.id_number || ""))}${fact("KRA PIN", esc(e.kra_pin || ""))}</div>` },
        { icon: "ri-briefcase-4-line", title: "The job", body: `<div class="row g-3">${fact("Position", job(e))}${fact("Type", typeChip(e))}${fact("Started", e.start_date ? A.dateChip(e.start_date) : "")}${fact("Contract ends", e.contract_end ? A.dateChip(e.contract_end) : "")}${fact("Left", e.end_date ? A.dateChip(e.end_date) : "")}</div>` },
        { icon: "ri-money-dollar-box-line", title: "Pay a month", hint: e.paid.slips ? `Paid ${e.paid.slips} ${e.paid.slips === 1 ? "month" : "months"}, last ${e.paid.last_month || "-"}` : "Not paid yet", body: `<div class="d-flex justify-content-between py-1"><span>Basic pay</span><span>${A.money(e.basic_pay)}</span></div>${e.allowances.map((a) => `<div class="d-flex justify-content-between py-1"><span>${esc(a.name)}</span><span>${A.money(a.amount)}</span></div>`).join("")}<div class="d-flex justify-content-between py-1 border-top mt-1"><strong>Pay</strong><strong>${A.money(e.gross)}</strong></div><div class="mt-2">${paidBy(e)}</div>` },
        { icon: "ri-route-line", title: "Where they have served", body: postings ? `<ul class="list-unstyled mb-0">${postings}</ul>` : '<p class="acc-sub mb-0">No history yet.</p>' },
        { icon: "ri-attachment-2", title: "Papers", hint: "Up to 5 - a photo or a PDF, 5 MB each", body: `${docs}${e.can.edit && e.documents.length < 5 ? `<div class="row g-2"><div class="col-sm-5"><input type="text" class="form-control" id="docName" maxlength="100" placeholder="What is it? e.g. Contract"></div><div class="col-sm-7"><input type="file" class="form-control" id="docFile" accept=".jpg,.jpeg,.png,.webp,.pdf"></div></div>` : ""}` },
      ]),
      run: async () => {
        bootstrap.Modal.getInstance($("ppModal"))?.hide();
        setTimeout(() => personWindow(e), 300);
        return null;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    if (!e.can.edit) el.querySelector("#ppGo").remove();
    const footer = el.querySelector(".modal-footer");
    const extra = [
      e.can.transfer && e.status !== "left" && opts?.transfer_to?.length ? '<button type="button" class="btn btn-outline-primary me-auto" data-act="move"><i class="ri-arrow-left-right-line me-1"></i>Move to another place</button>' : "",
      e.can.end ? '<button type="button" class="btn btn-outline-warning" data-act="end"><i class="ri-logout-box-r-line me-1"></i>They leave</button>' : "",
      e.can.delete && e.can_delete ? '<button type="button" class="btn btn-outline-danger" data-act="remove"><i class="ri-delete-bin-line me-1"></i>Remove</button>' : "",
    ].join("");
    footer.insertAdjacentHTML("afterbegin", extra);
    footer.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-act]");
      if (!b) return;
      bootstrap.Modal.getInstance(el)?.hide();
      setTimeout(() => ({ move: moveWindow, end: endWindow, remove: removeWindow })[b.dataset.act](e), 300);
    });
    el.addEventListener("click", async (ev) => {
      const d = ev.target.closest("[data-doc]");
      if (d) {
        ev.preventDefault();
        return API.openDocument(e.id, d.dataset.doc);
      }
      const u = ev.target.closest("[data-undoc]");
      if (u) {
        const out = await API.removeDocument(e.id, u.dataset.undoc);
        if (!out.ok) return Toast.error(out.message);
        Toast.success(out.message);
        bootstrap.Modal.getInstance(el)?.hide();
        setTimeout(() => view(e.id), 300);
      }
    });
    el.querySelector("#docFile")?.addEventListener("change", async (ev) => {
      const file = ev.target.files[0];
      if (!file) return;
      const out = await API.addDocument(e.id, file, el.querySelector("#docName").value.trim());
      if (!out.ok) return Toast.error(out.message);
      Toast.success(out.message);
      bootstrap.Modal.getInstance(el)?.hide();
      setTimeout(() => view(e.id), 300);
    });
  }

  // ------------------------------------------------------------ add or change

  function personWindow(e = null) {
    if (!opts) return Toast.error("The lists couldn't load - refresh the page.");
    const f = (id, label, v, type = "text", col = "col-sm-6", extra = "") => `<div class="${col}"><label class="form-label" for="${id}">${label}</label><input type="${type}" class="form-control" id="${id}" value="${esc(v ?? "")}"${extra}></div>`;
    const secret = (id, label, masked) => `<div class="col-sm-6"><label class="form-label" for="${id}">${label}</label><input type="text" class="form-control" id="${id}" maxlength="20" autocomplete="off" placeholder="${masked ? `Saved: ${esc(masked)} - type to replace` : "Not given"}"></div>`;
    const sel = (id, label, items, cur, none, col = "col-sm-6") => `<div class="${col}"><label class="form-label" for="${id}">${label}</label><select class="form-select" id="${id}">${none !== null ? `<option value="">${esc(none)}</option>` : ""}${items.map((i) => `<option value="${i.id ?? i.key}"${String(i.id ?? i.key) === String(cur ?? "") ? " selected" : ""}>${esc(i.label ?? (i.code ? `${i.code} · ${i.name}` : i.name))}</option>`).join("")}</select></div>`;
    const source = e?.person ? "member" : e?.user ? "login" : e ? "name" : "member";
    const churches = opts.churches || [];
    const otherPos = e && !e.position_id && e.position;
    const el = K.confirmWindow({
      title: e ? `Change ${e.name}` : "Add a person to the staff",
      subtitle: "Who they are, their job and what they are paid",
      icon: "ri-user-add-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        {
          icon: "ri-user-line",
          title: "Who",
          hint: "A member of the church, someone with a login, or just a name",
          body: `<div class="btn-group mb-3" role="group" aria-label="Who">${[["member", "A member", "ri-contacts-book-2-line"], ["login", "Has a login", "ri-user-settings-line"], ["name", "Just a name", "ri-edit-line"]].map(([k, l, i]) => `<input type="radio" class="btn-check" name="hrSrc" id="hrSrc_${k}" value="${k}"${k === source ? " checked" : ""}${k === "member" && !churches.length ? " disabled" : ""}><label class="btn btn-outline-primary" for="hrSrc_${k}"><i class="${i} me-1"></i>${l}</label>`).join("")}</div>
            <div data-src="member" class="mb-2">${churches.length > 1 ? `<select class="form-select mb-2" id="hrChurch" aria-label="Their church">${churches.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join("")}</select>` : ""}<input type="search" class="form-control" id="hrFindMember" placeholder="Search the member register by name or phone" autocomplete="off"><div class="list-group mt-1" id="hrMemberHits"></div></div>
            <div data-src="login" class="mb-2"><input type="search" class="form-control" id="hrFindLogin" placeholder="Search people with a role here or below" autocomplete="off"><div class="list-group mt-1" id="hrLoginHits"></div></div>
            <div id="hrPicked" class="mb-2"></div>
            <div class="row g-2">${f("hrName", "Name", e?.name, "text", "col-sm-12", ' maxlength="150"')}${f("hrPhone", "Phone", e?.phone, "tel")}${f("hrEmail", "Email", e?.email, "email")}</div>`,
        },
        {
          icon: "ri-briefcase-4-line",
          title: "The job",
          hint: "Positions and grades come from Positions & pay",
          body: `<div class="row g-2">${sel("hrPos", "Position", [...opts.positions, { id: "other", name: "Something else (type it)" }], otherPos ? "other" : e?.position_id, "No position")}${sel("hrGrade", "Grade", opts.grades, e?.grade?.id, "No grade")}<div class="col-12" id="hrPosOtherWrap" hidden><input type="text" class="form-control" id="hrPosOther" maxlength="100" placeholder="e.g. Choir trainer" value="${esc(otherPos || "")}"></div>${sel("hrType", "Type", opts.types, e?.employment_type || "full_time", null)}${f("hrStart", "Started", e?.start_date, "date")}${f("hrContract", "Contract ends (if any)", e?.contract_end, "date")}</div>`,
        },
        {
          icon: "ri-money-dollar-box-line",
          title: "Pay a month",
          hint: "No deductions here - payroll takes any SACCO or loan each month",
          body: `<div class="row g-2">${f("hrBasic", "Basic pay (KES)", e ? e.basic_pay : "", "text", "col-sm-6", ' inputmode="decimal"')}<div class="col-sm-6 d-flex align-items-end"><span class="acc-sub" id="hrRange"></span></div></div><div class="mt-2" id="hrAllow"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="hrAddAllow"><i class="ri-add-line me-1"></i>Add an allowance</button>`,
        },
        { icon: "ri-bank-card-line", title: "Paid by", hint: "Where their pay goes", body: W.payeeFields("hrTo", e?.payee || (e ? { method: e.pay_method } : { method: "mpesa" }), { optional: false }) },
        { icon: "ri-lock-line", title: "Private numbers", hint: "Kept encrypted; only the last four are ever shown", body: `<div class="row g-2">${secret("hrId", "ID number", e?.id_number)}${secret("hrKra", "KRA PIN", e?.kra_pin)}</div>` },
      ]),
      run: async () => {
        const v = (id) => el.querySelector(`#${id}`)?.value.trim() || null;
        const src = el.querySelector('input[name="hrSrc"]:checked').value;
        const pos = v("hrPos");
        const body = {
          person_id: src === "member" ? picked.person?.id || null : null,
          user_id: src === "login" ? picked.user?.id || null : null,
          name: v("hrName"),
          phone: v("hrPhone"),
          email: v("hrEmail"),
          position_id: pos && pos !== "other" ? Number(pos) : null,
          position: pos === "other" ? v("hrPosOther") : null,
          grade_id: v("hrGrade") ? Number(v("hrGrade")) : null,
          employment_type: v("hrType"),
          start_date: v("hrStart"),
          contract_end: v("hrContract"),
          basic_pay: v("hrBasic") === null ? null : n(v("hrBasic")),
          allowances: [...el.querySelectorAll("[data-allow]")]
            .map((x) => {
              const t = x.querySelector("[data-at]").value;
              return { type_id: t && t !== "other" ? Number(t) : null, name: t === "other" ? x.querySelector("[data-an]").value.trim() : null, amount: x.querySelector("[data-aa]").value.trim() === "" ? null : n(x.querySelector("[data-aa]").value) };
            })
            .filter((a) => a.type_id || a.name || a.amount),
          payee: W.readPayee(el, "hrTo"),
          id_number: v("hrId"),
          kra_pin: v("hrKra"),
        };
        const out = await API.save(e?.id, body);
        if (out.ok) load();
        return out;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    W.wirePayee(el, "hrTo");
    const picked = { person: e?.person || null, user: e?.user || null };

    // Who: one source at a time.
    const showSrc = () => {
      const src = el.querySelector('input[name="hrSrc"]:checked').value;
      el.querySelectorAll("[data-src]").forEach((x) => (x.hidden = x.dataset.src !== src));
      const p = src === "member" ? picked.person : src === "login" ? picked.user : null;
      el.querySelector("#hrPicked").innerHTML = p ? `<span class="soft-chip soft-success"><i class="ri-check-line"></i>${esc(p.name)}${p.church ? ` · ${esc(p.church)}` : ""}</span>` : "";
    };
    el.querySelectorAll('input[name="hrSrc"]').forEach((r) => r.addEventListener("change", showSrc));
    const finder = (input, hits, search, pick) => {
      let t = null;
      el.querySelector(input).addEventListener("input", (ev) => {
        clearTimeout(t);
        const q = ev.target.value.trim();
        if (q.length < 2) return (el.querySelector(hits).innerHTML = "");
        t = setTimeout(async () => {
          const res = await search(q);
          el.querySelector(hits).innerHTML = res.ok
            ? res.data.length
              ? res.data.map((r, i) => `<button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2" data-hit="${i}">${A.avatar(r.name, "xs")}<span class="flex-fill">${esc(r.name)}<span class="acc-sub d-block">${esc([r.phone, r.role, r.area].filter(Boolean).join(" · "))}</span></span>${r.staff ? '<span class="soft-chip soft-warning">Already staff</span>' : ""}</button>`).join("")
              : '<div class="list-group-item acc-sub">Nobody found</div>'
            : `<div class="list-group-item text-danger">${esc(res.message)}</div>`;
          el.querySelector(hits).onclick = (c) => {
            const b = c.target.closest("[data-hit]");
            if (!b) return;
            const r = res.data[Number(b.dataset.hit)];
            pick(r);
            el.querySelector(hits).innerHTML = "";
            el.querySelector(input).value = "";
            if (!el.querySelector("#hrName").value) el.querySelector("#hrName").value = r.name;
            if (!el.querySelector("#hrPhone").value && r.phone) el.querySelector("#hrPhone").value = r.phone;
            if (!el.querySelector("#hrEmail").value && r.email) el.querySelector("#hrEmail").value = r.email;
            showSrc();
          };
        }, 250);
      });
    };
    finder("#hrFindMember", "#hrMemberHits", (q) => API.people(q, el.querySelector("#hrChurch")?.value || churches[0]?.id), (r) => (picked.person = { id: r.id, name: r.name, church: el.querySelector("#hrChurch")?.selectedOptions[0]?.textContent || churches[0]?.name }));
    finder("#hrFindLogin", "#hrLoginHits", (q) => API.logins(q), (r) => (picked.user = { id: r.id, name: r.name }));
    showSrc();

    // The job: a position suggests its grade; a grade suggests its pay and shows its range.
    const grades = Object.fromEntries(opts.grades.map((g) => [g.id, g]));
    const range = () => {
      const g = grades[el.querySelector("#hrGrade").value];
      const m = (v) => A.money(v, { cents: false });
      el.querySelector("#hrRange").textContent = g ? (g.min_pay !== null && g.max_pay !== null ? `${g.code}: ${m(g.min_pay)} to ${m(g.max_pay)}` : g.min_pay !== null ? `${g.code}: at least ${m(g.min_pay)}` : g.max_pay !== null ? `${g.code}: up to ${m(g.max_pay)}` : "") : "";
      if (g?.default_pay && !el.querySelector("#hrBasic").value) el.querySelector("#hrBasic").value = g.default_pay;
    };
    el.querySelector("#hrPos").addEventListener("change", () => {
      const pos = el.querySelector("#hrPos").value;
      el.querySelector("#hrPosOtherWrap").hidden = pos !== "other";
      const p = opts.positions.find((x) => String(x.id) === pos);
      if (p?.grade_id && grades[p.grade_id]) {
        el.querySelector("#hrGrade").value = p.grade_id;
        UI.syncSelect(el.querySelector("#hrGrade"));
      }
      range();
    });
    el.querySelector("#hrGrade").addEventListener("change", range);
    el.querySelector("#hrPosOtherWrap").hidden = !otherPos;
    range();

    // Allowances: a type from the list (with its usual amount), or something else typed in.
    const types = Object.fromEntries(opts.allowances.map((a) => [a.id, a]));
    const addAllow = (a = {}) => {
      const cur = a.type_id && types[a.type_id] ? a.type_id : a.name ? "other" : opts.allowances[0]?.id || "other";
      el.querySelector("#hrAllow").insertAdjacentHTML(
        "beforeend",
        `<div class="row g-2 mb-2 align-items-center" data-allow><div class="col-5"><select class="form-select" data-at aria-label="Allowance">${opts.allowances.map((t) => `<option value="${t.id}"${String(t.id) === String(cur) ? " selected" : ""}>${esc(t.name)}</option>`).join("")}<option value="other"${cur === "other" ? " selected" : ""}>Something else</option></select></div><div class="col-4" data-anw${cur === "other" ? "" : " hidden"}><input type="text" class="form-control" data-an maxlength="60" placeholder="Name it" value="${esc(cur === "other" ? a.name || "" : "")}"></div><div class="col"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" data-aa value="${a.amount ?? (types[cur]?.default_amount || "")}"></div></div><div class="col-auto"><button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-unallow aria-label="Remove"><i class="ri-close-line"></i></button></div></div>`,
      );
    };
    (e?.allowances || []).forEach(addAllow);
    el.querySelector("#hrAddAllow").addEventListener("click", () => addAllow());
    el.querySelector("#hrAllow").addEventListener("change", (ev) => {
      const s = ev.target.closest("[data-at]");
      if (!s) return;
      const row = s.closest("[data-allow]");
      row.querySelector("[data-anw]").hidden = s.value !== "other";
      if (types[s.value]?.default_amount) row.querySelector("[data-aa]").value = types[s.value].default_amount;
    });
    el.querySelector("#hrAllow").addEventListener("click", (ev) => ev.target.closest("[data-unallow]")?.closest("[data-allow]").remove());
    ["#hrPos", "#hrGrade", "#hrType", "#hrChurch"].forEach((s) => el.querySelector(s) && UI.enhanceSelect(el.querySelector(s), { search: s === "#hrChurch" || s === "#hrPos" }));
    if (window.DateField) ["#hrStart", "#hrContract"].forEach((s) => DateField.enhance(el.querySelector(s), { quick: ["today"] }));
  }

  // ------------------------------------------------------------ moving, leaving, removing

  function moveWindow(e) {
    const places = (opts.transfer_to || []).filter((p) => p.id !== e.place.id);
    const el = K.confirmWindow({
      title: `Move ${e.name}`,
      subtitle: `From ${e.place.name} to another place - their new place pays from the month they arrive`,
      icon: "ri-arrow-left-right-line",
      go: '<i class="ri-check-line me-1"></i>Move them',
      body: K.parts([
        { icon: "ri-map-pin-line", title: "To where, and from when", body: `<div class="row g-2"><div class="col-sm-7"><label class="form-label" for="mvTo">New place</label><select class="form-select" id="mvTo">${places.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join("")}</select></div><div class="col-sm-5"><label class="form-label" for="mvDate">From</label><input type="date" class="form-control" id="mvDate" value="${new Date().toISOString().slice(0, 10)}"></div><div class="col-12"><label class="form-label" for="mvNote">Why (optional)</label><input type="text" class="form-control" id="mvNote" maxlength="255" placeholder="e.g. Posted by the Regional Overseer"></div></div>` },
      ]),
      run: async () => {
        const out = await API.transfer(e.id, { to_territory_id: Number(el.querySelector("#mvTo").value), date: el.querySelector("#mvDate").value, note: el.querySelector("#mvNote").value.trim() || null });
        if (out.ok) load();
        return out;
      },
    });
    UI.enhanceSelect(el.querySelector("#mvTo"), { search: places.length > 8 });
  }

  function endWindow(e) {
    const el = K.confirmWindow({
      title: `${e.name} leaves`,
      subtitle: "They are paid for the month they leave, not after - their record and payslips stay",
      icon: "ri-logout-box-r-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([{ icon: "ri-calendar-line", title: "When, and why", body: `<div class="row g-2"><div class="col-sm-5"><label class="form-label" for="endDate">Last day</label><input type="date" class="form-control" id="endDate" value="${new Date().toISOString().slice(0, 10)}"></div><div class="col-sm-7"><label class="form-label" for="endWhy">Why (optional)</label><input type="text" class="form-control" id="endWhy" maxlength="255" placeholder="e.g. Contract ended"></div></div>` }]),
      run: async () => {
        const out = await API.end(e.id, { date: el.querySelector("#endDate").value, reason: el.querySelector("#endWhy").value.trim() || null });
        if (out.ok) load();
        return out;
      },
    });
  }

  function removeWindow(e) {
    K.confirmWindow({
      title: `Remove ${e.name}?`,
      subtitle: "Only for someone added by mistake - they have never been paid",
      icon: "ri-delete-bin-line",
      danger: true,
      go: '<i class="ri-delete-bin-line me-1"></i>Remove',
      body: `<p class="mb-0">${esc(e.name)} and their papers will be removed. Someone who worked here should be marked as leaving instead.</p>`,
      run: async () => {
        const out = await API.remove(e.id);
        if (out.ok) load();
        return out;
      },
    });
  }

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", () => {
    $("addBtn")?.addEventListener("click", () => personWindow());
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) =>
      b.addEventListener("click", () => {
        tab = b.dataset.tab;
        const p = new URLSearchParams(window.location.search);
        tab === "ours" ? p.delete("tab") : p.set("tab", tab);
        history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
        showTab();
      }),
    );
    document.addEventListener("click", (ev) => {
      if (ev.target.closest("[data-first]")) return personWindow();
      const row = ev.target.closest("#hrOursTable tr[data-id], #hrBelowTable tr[data-id]");
      if (row && !ev.target.closest("a, button")) view(Number(row.dataset.id));
    });
    load();
  });
})();
