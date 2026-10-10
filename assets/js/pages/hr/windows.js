/**
 * ============================================================================
 * STAFF - the windows (hr/, every level)
 * ============================================================================
 * docs/specs/hr-spec.md: add or change someone (from a member, a login or a
 * name; a position fills in its package here), move them to another place,
 * they leave, remove someone never paid. Shared by the Staff list and a
 * person's own page. Every field says what goes in it.
 * ============================================================================
 */
const HrWindows = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const API = HrAPI;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const n = (v) => Math.round(parseFloat(String(v ?? "").replace(/[^0-9.]/g, "")) * 100) / 100 || 0;
  const CTX = window.HR_CTX;
  const nn = (v) => (String(v ?? "").trim() === "" ? null : Math.round(parseFloat(String(v).replace(/[^0-9.]/g, "")) * 100) / 100 || 0);
  const ITEM = {
    position: { one: "position", add: "Add a position", icon: "ri-briefcase-4-line" },
    grade: { one: "grade", add: "Add a grade", icon: "ri-bar-chart-box-line" },
    allowance: { one: "allowance", add: "Add an allowance", icon: "ri-hand-coin-line" },
  };
  const LEVELS = { church: "Churches", region: "Regions", diocese: "The diocese" };
  let opts = null;
  let done = () => {};

  /** The lists for the windows (GET /hr/options), and what to do after a change. */
  function setup(options, onDone) {
    opts = options;
    done = onDone || done;
  }

  // ------------------------------------------------------------ add or change

  function personWindow(e = null) {
    if (!opts) return Toast.error("The lists couldn't load - refresh the page.");
    const f = (id, label, v, type = "text", col = "col-sm-6", extra = "", ph = "") => `<div class="${col}"><label class="form-label" for="${id}">${label}</label><input type="${type}" class="form-control" id="${id}" value="${esc(v ?? "")}" placeholder="${esc(ph)}"${extra}></div>`;
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
            <div class="row g-2">${f("hrName", "Name", e?.name, "text", "col-sm-12", ' maxlength="150"', "Their full name, e.g. Ruth Mwende")}${f("hrPhone", "Phone", e?.phone, "tel", "col-sm-6", "", "e.g. 0712 345 678")}${f("hrEmail", "Email", e?.email, "email", "col-sm-6", "", "e.g. name@example.com (optional)")}</div>`,
        },
        {
          icon: "ri-briefcase-4-line",
          title: "The job",
          hint: "Positions and grades come from Positions & pay",
          body: `<div class="row g-2">${sel("hrPos", "Position", [...opts.positions, { id: "other", name: "Something else (type it)" }], otherPos ? "other" : e?.position_id, "Pick a position…")}${sel("hrGrade", "Grade", opts.grades, e?.grade?.id, "No grade (optional)")}<div class="col-12" id="hrPosOtherWrap" hidden><input type="text" class="form-control" id="hrPosOther" maxlength="100" placeholder="e.g. Choir trainer" value="${esc(otherPos || "")}"></div>${sel("hrType", "Type", opts.types, e?.employment_type || "full_time", null)}${f("hrStart", "Started", e?.start_date, "date", "col-sm-6", "", "Pick the day they started")}${f("hrContract", "Contract ends (if any)", e?.contract_end, "date", "col-sm-6", "", "Pick a date (optional)")}</div>`,
        },
        {
          icon: "ri-money-dollar-box-line",
          title: "Pay a month",
          hint: "No deductions here - payroll takes any SACCO or loan each month",
          body: `<div class="row g-2">${f("hrBasic", "Basic pay (KES)", e ? e.basic_pay : "", "text", "col-sm-6", ' inputmode="decimal"', "e.g. 15,000")}<div class="col-sm-6 d-flex align-items-end"><span class="acc-sub" id="hrRange"></span></div></div><div class="mt-2" id="hrAllow"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="hrAddAllow"><i class="ri-add-line me-1"></i>Add an allowance</button>`,
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
        if (out.ok) done();
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
      // The position's package here: its usual pay and allowances, when nothing is entered yet.
      if (p?.default_pay && !el.querySelector("#hrBasic").value) el.querySelector("#hrBasic").value = p.default_pay;
      if (p?.allowances?.length && !el.querySelector("[data-allow]")) p.allowances.forEach((a) => addAllow({ type_id: a.type_id, amount: a.amount ?? types[a.type_id]?.default_amount }));
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
        `<div class="row g-2 mb-2 align-items-center" data-allow><div class="col-5"><select class="form-select" data-at aria-label="Allowance">${opts.allowances.map((t) => `<option value="${t.id}"${String(t.id) === String(cur) ? " selected" : ""}>${esc(t.name)}</option>`).join("")}<option value="other"${cur === "other" ? " selected" : ""}>Something else</option></select></div><div class="col-4" data-anw${cur === "other" ? "" : " hidden"}><input type="text" class="form-control" data-an maxlength="60" placeholder="Name it, e.g. Lunch" value="${esc(cur === "other" ? a.name || "" : "")}"></div><div class="col"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" data-aa placeholder="Amount" value="${a.amount ?? (types[cur]?.default_amount || "")}"></div></div><div class="col-auto"><button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-unallow aria-label="Remove"><i class="ri-close-line"></i></button></div></div>`,
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
        { icon: "ri-map-pin-line", title: "To where, and from when", body: `<div class="row g-2"><div class="col-sm-7"><label class="form-label" for="mvTo">New place</label><select class="form-select" id="mvTo">${places.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join("")}</select></div><div class="col-sm-5"><label class="form-label" for="mvDate">From</label><input type="date" class="form-control" id="mvDate" placeholder="Pick the day they start there" value="${new Date().toISOString().slice(0, 10)}"></div><div class="col-12"><label class="form-label" for="mvNote">Why (optional)</label><input type="text" class="form-control" id="mvNote" maxlength="255" placeholder="e.g. Posted by the Regional Overseer"></div></div>` },
      ]),
      run: async () => {
        const out = await API.transfer(e.id, { to_territory_id: Number(el.querySelector("#mvTo").value), date: el.querySelector("#mvDate").value, note: el.querySelector("#mvNote").value.trim() || null });
        if (out.ok) done();
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
      body: K.parts([{ icon: "ri-calendar-line", title: "When, and why", body: `<div class="row g-2"><div class="col-sm-5"><label class="form-label" for="endDate">Last day</label><input type="date" class="form-control" id="endDate" placeholder="Pick their last day" value="${new Date().toISOString().slice(0, 10)}"></div><div class="col-sm-7"><label class="form-label" for="endWhy">Why (optional)</label><input type="text" class="form-control" id="endWhy" maxlength="255" placeholder="e.g. Contract ended"></div></div>` }]),
      run: async () => {
        const out = await API.end(e.id, { date: el.querySelector("#endDate").value, reason: el.querySelector("#endWhy").value.trim() || null });
        if (out.ok) done();
        return out;
      },
    });
  }

  function removeWindow(e, after = null) {
    K.confirmWindow({
      title: `Remove ${e.name}?`,
      subtitle: "Only for someone added by mistake - they have never been paid",
      icon: "ri-delete-bin-line",
      danger: true,
      go: '<i class="ri-delete-bin-line me-1"></i>Remove',
      body: `<p class="mb-0">${esc(e.name)} and their papers will be removed. Someone who worked here should be marked as leaving instead.</p>`,
      run: async () => {
        const out = await API.remove(e.id);
        if (out.ok) (after || done)();
        return out;
      },
    });
  }

  // ------------------------------------------------------------ positions, grades, allowances

  function itemWindow(kind, r = null, gradesList = [], after = null) {
    const k = ITEM[kind];
    const f = (id, label, v, col = "col-sm-6", extra = "") => `<div class="${col}"><label class="form-label" for="${id}">${label}</label><input type="text" class="form-control" id="${id}" value="${esc(v ?? "")}"${extra}></div>`;
    const grades = gradesList.filter((g) => g.is_active && !g.hidden);
    const body = {
      position: `<div class="row g-2">${f("itName", "Name", r?.name, "col-sm-12", ' maxlength="100" placeholder="e.g. Church secretary"')}<div class="col-sm-6"><label class="form-label">Used at</label><div class="d-flex flex-wrap gap-3">${Object.entries(LEVELS).map(([l, label]) => `<label class="form-check mb-0"><input class="form-check-input" type="checkbox" data-level="${l}"${r?.levels?.includes(l) ? " checked" : ""}><span class="form-check-label">${label}</span></label>`).join("")}</div><small class="acc-sub">None ticked: any level</small></div><div class="col-sm-6"><label class="form-label" for="itGrade">Usual grade</label><select class="form-select" id="itGrade"><option value="">No usual grade</option>${grades.map((g) => `<option value="${g.id}"${String(g.id) === String(r?.grade_id) ? " selected" : ""}>${esc(`${g.code} · ${g.name}`)}</option>`).join("")}</select></div></div>`,
      grade: `<div class="row g-2">${f("itCode", "Code", r?.code, "col-sm-4", ' maxlength="20" placeholder="e.g. G3"')}${f("itName", "Name", r?.name, "col-sm-8", ' maxlength="100" placeholder="e.g. Grade 3 - support staff"')}${f("itMin", "Least a month (KES)", r?.min_pay, "col-sm-4", ' inputmode="decimal" placeholder="e.g. 10,000"')}${f("itMax", "Most a month (KES)", r?.max_pay, "col-sm-4", ' inputmode="decimal" placeholder="e.g. 20,000"')}${f("itUsual", "Usual basic pay (KES)", r?.default_pay, "col-sm-4", ' inputmode="decimal" placeholder="e.g. 15,000"')}</div>`,
      allowance: `<div class="row g-2">${f("itName", "Name", r?.name, "col-sm-7", ' maxlength="60" placeholder="e.g. House"')}${f("itAmount", "Usual amount a month (KES)", r?.default_amount, "col-sm-5", ' inputmode="decimal" placeholder="Optional"')}</div>`,
    }[kind];
    const el = K.confirmWindow({
      title: r ? `Change ${r.name}` : k.add,
      subtitle: kind === "allowance" ? "Allowances add to pay - there are no deductions here" : `Used at ${CTX.place.name || "this place"}${CTX.level !== "church" ? " and the places below" : ""}`,
      icon: k.icon,
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: k.icon, title: `The ${k.one}`, body },
        { icon: "ri-information-line", title: "More", body: `<div class="row g-2"><div class="col-12"><input type="text" class="form-control" id="itAbout" maxlength="255" placeholder="What it is for - optional" value="${esc(r?.description || "")}"></div></div>${r ? `<label class="form-check form-switch mt-3 mb-0"><input class="form-check-input" type="checkbox" id="itOn"${r.is_active ? " checked" : ""}><span class="form-check-label">In use (switched off, nobody new can be given it)</span></label>` : ""}` },
      ]),
      run: async () => {
        const v = (id) => el.querySelector(`#${id}`)?.value.trim() ?? null;
        const payload = { name: v("itName"), description: v("itAbout") || null, ...(r ? { is_active: el.querySelector("#itOn").checked } : {}) };
        if (kind === "position") Object.assign(payload, { levels: [...el.querySelectorAll("[data-level]:checked")].map((x) => x.dataset.level), grade_id: v("itGrade") ? Number(v("itGrade")) : null });
        if (kind === "grade") Object.assign(payload, { code: v("itCode"), min_pay: nn(v("itMin")), max_pay: nn(v("itMax")), default_pay: nn(v("itUsual")) });
        if (kind === "allowance") payload.default_amount = nn(v("itAmount"));
        const out = await API.saveItem(kind, r?.id, payload);
        if (out.ok) (after || done)();
        return out;
      },
    });
    if (el.querySelector("#itGrade")) UI.enhanceSelect(el.querySelector("#itGrade"), { search: false });
  }


  /** Our own version of one set above us: a position's pay package, a grade's range, an allowance's amount. d: GET /hr/setup/{kind}/{id}. */
  function oursWindow(kind, d, after = null) {
    const cur = d.our_version || d;
    const f = (id, label, v, col, ph, extra = "") => `<div class="${col}"><label class="form-label" for="${id}">${label}</label><input type="text" class="form-control" id="${id}" value="${esc(v ?? "")}" placeholder="${esc(ph)}"${extra}></div>`;
    const grades = (opts?.grades || []);
    const types = Object.fromEntries((opts?.allowances || []).map((a) => [a.id, a]));
    const body = {
      position: K.parts([
        { icon: "ri-bar-chart-box-line", title: "Grade and usual pay", hint: "Filled in when someone is given this position here", body: `<div class="row g-2"><div class="col-sm-6"><label class="form-label" for="ouGrade">Usual grade</label><select class="form-select" id="ouGrade"><option value="">No usual grade</option>${grades.map((g) => `<option value="${g.id}"${String(g.id) === String(cur.grade_id) ? " selected" : ""}>${esc(`${g.code} · ${g.name}`)}</option>`).join("")}</select></div>${f("ouPay", "Usual basic pay a month (KES)", cur.default_pay, "col-sm-6", "e.g. 30,000", ' inputmode="decimal"')}<div class="col-12 acc-sub" id="ouRange"></div></div>` },
        { icon: "ri-hand-coin-line", title: "Allowances that come with it", hint: "Leave an amount empty to use the allowance's usual amount", body: `<div id="ouAllow"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="ouAddAllow"${(opts?.allowances || []).length ? "" : " disabled"}><i class="ri-add-line me-1"></i>Add an allowance</button>${(opts?.allowances || []).length ? "" : '<div class="acc-sub mt-1">Add allowance types under Positions & pay first.</div>'}` },
        { icon: "ri-file-text-line", title: "Duties and notes", body: `<label class="form-label" for="ouDuties">What they do here</label><textarea class="form-control" id="ouDuties" rows="3" maxlength="1000" placeholder="e.g. Leads Sunday services, visits the sick, chairs the church committee">${esc(cur.duties || "")}</textarea><label class="form-label mt-2" for="ouNotes">Notes</label><input type="text" class="form-control" id="ouNotes" maxlength="500" placeholder="e.g. Housed in the church manse (optional)" value="${esc(cur.notes || "")}">` },
      ]),
      grade: K.parts([{ icon: "ri-bar-chart-box-line", title: "Our range", body: `<div class="row g-2">${f("ouMin", "Least a month (KES)", cur.min_pay, "col-sm-4", "e.g. 10,000", ' inputmode="decimal"')}${f("ouMax", "Most a month (KES)", cur.max_pay, "col-sm-4", "e.g. 20,000", ' inputmode="decimal"')}${f("ouUsual", "Usual basic pay (KES)", cur.default_pay, "col-sm-4", "e.g. 15,000", ' inputmode="decimal"')}</div>` }]),
      allowance: K.parts([{ icon: "ri-hand-coin-line", title: "Our amount", body: f("ouAmount", "Usual amount a month here (KES)", cur.default_amount, "col-12", "e.g. 5,000", ' inputmode="decimal"') }]),
    }[kind];
    const el = K.confirmWindow({
      title: `Our ${d.name}`,
      subtitle: `${d.place?.name || CTX.place.name || "Here"}'s own version - ${d.owner.name}'s stays as it is for everyone else`,
      icon: ITEM[kind].icon,
      go: '<i class="ri-check-line me-1"></i>Save our version',
      body,
      run: async () => {
        const v = (id) => el.querySelector(`#${id}`)?.value.trim() ?? null;
        const payload =
          kind === "position"
            ? {
                grade_id: v("ouGrade") ? Number(v("ouGrade")) : null,
                default_pay: nn(v("ouPay")),
                allowances: [...el.querySelectorAll("[data-oa]")].map((x) => ({ type_id: Number(x.querySelector("[data-ot]").value), amount: nn(x.querySelector("[data-om]").value) })),
                duties: v("ouDuties") || null,
                notes: v("ouNotes") || null,
              }
            : kind === "grade"
              ? { min_pay: nn(v("ouMin")), max_pay: nn(v("ouMax")), default_pay: nn(v("ouUsual")) }
              : { default_amount: nn(v("ouAmount")) };
        const out = await API.setOurs(kind, d.id, payload);
        if (out.ok) (after || done)();
        return out;
      },
    });
    if (kind !== "position") return;
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    const add = (a = {}) => {
      const t = a.type_id || opts.allowances[0]?.id;
      el.querySelector("#ouAllow").insertAdjacentHTML(
        "beforeend",
        `<div class="row g-2 mb-2 align-items-center" data-oa><div class="col-6"><select class="form-select" data-ot aria-label="Allowance">${opts.allowances.map((x) => `<option value="${x.id}"${String(x.id) === String(t) ? " selected" : ""}>${esc(x.name)}</option>`).join("")}</select></div><div class="col"><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end" data-om value="${a.follows ? "" : a.amount ?? ""}" placeholder="${types[t]?.default_amount ? `Usual: ${Number(types[t].default_amount).toLocaleString("en-GB")}` : "Amount"}"></div></div><div class="col-auto"><button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-unoa aria-label="Remove"><i class="ri-close-line"></i></button></div></div>`,
      );
    };
    (cur.allowances || []).forEach(add);
    el.querySelector("#ouAddAllow").addEventListener("click", () => add());
    el.querySelector("#ouAllow").addEventListener("click", (ev) => ev.target.closest("[data-unoa]")?.closest("[data-oa]").remove());
    el.querySelector("#ouAllow").addEventListener("change", (ev) => {
      const s = ev.target.closest("[data-ot]");
      if (s) s.closest("[data-oa]").querySelector("[data-om]").placeholder = types[s.value]?.default_amount ? `Usual: ${Number(types[s.value].default_amount).toLocaleString("en-GB")}` : "Amount";
    });
    const range = () => {
      const g = grades.find((x) => String(x.id) === el.querySelector("#ouGrade").value);
      el.querySelector("#ouRange").textContent = g && (g.min_pay !== null || g.max_pay !== null) ? `${g.code} pays ${A.money(g.min_pay ?? 0, { cents: false })} to ${A.money(g.max_pay ?? 0, { cents: false })} here.` : "";
    };
    el.querySelector("#ouGrade").addEventListener("change", range);
    UI.enhanceSelect(el.querySelector("#ouGrade"), { search: false });
    range();
  }

  return { setup, person: personWindow, move: moveWindow, end: endWindow, remove: removeWindow, item: itemWindow, ours: oursWindow, ITEM, LEVELS };
})();

window.HrWindows = HrWindows;
