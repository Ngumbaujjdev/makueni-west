/**
 * ============================================================================
 * STAFF - Positions & pay (hr/positions.php, every level)
 * ============================================================================
 * docs/specs/hr-spec.md: the positions, grades and allowances this place
 * uses - its own, which it adds and changes, and those set by the diocese or
 * its region, which it can switch off here. No deductions: payroll takes any
 * SACCO or loan each month.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const K = PeopleKit;
  const API = HrAPI;
  const CTX = window.HR_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const n = (v) => (String(v ?? "").trim() === "" ? null : Math.round(parseFloat(String(v).replace(/[^0-9.]/g, "")) * 100) / 100 || 0);
  let data = null;
  let tab = ["position", "grade", "allowance"].includes(new URLSearchParams(window.location.search).get("tab")) ? new URLSearchParams(window.location.search).get("tab") : "position";

  const KIND = {
    position: { key: "positions", noun: "positions", one: "position", title: "Positions", add: "Add a position", sub: "The jobs people hold. Those set by the diocese or region are shown too - switch one off if you don't use it", icon: "ri-briefcase-4-line" },
    grade: { key: "grades", noun: "grades", one: "grade", title: "Grades", add: "Add a grade", sub: "Pay bands: the least and most a grade pays, and its usual basic pay", icon: "ri-bar-chart-box-line" },
    allowance: { key: "allowances", noun: "allowances", one: "allowance", title: "Allowances", add: "Add an allowance", sub: "House, transport and the like - with the usual amount, changed per person when needed", icon: "ri-hand-coin-line" },
  };
  const LEVELS = { church: "Churches", region: "Regions", diocese: "The diocese" };
  const money = (v) => (v === null || v === undefined ? "-" : A.money(v, { cents: false }));
  const owner = (r) => (r.own ? '<span class="soft-chip soft-success"><i class="ri-home-4-line"></i>Ours</span>' : `<span class="soft-chip soft-${r.owner.level === "diocese" ? "primary" : "purple"}"><i class="${r.owner.level === "diocese" ? "ri-government-line" : "ri-map-pin-line"}"></i>${esc(r.owner.name)}</span>`);
  const state = (r) => (!r.is_active ? '<span class="badge bg-secondary text-dark">Switched off</span>' : r.hidden ? '<span class="badge bg-secondary text-dark">Not used here</span>' : '<span class="badge bg-success text-white"><i class="ri-check-line me-1"></i>In use</span>');

  function details(kind, r) {
    if (kind === "position") return `${r.levels.length ? r.levels.map((l) => LEVELS[l]).join(", ") : "Any level"}${r.grade ? ` · ${esc(r.grade)}` : ""}`;
    if (kind === "grade") return r.min_pay !== null || r.max_pay !== null ? `${money(r.min_pay)} to ${money(r.max_pay)}${r.default_pay ? ` · usually ${money(r.default_pay)}` : ""}` : r.default_pay ? `Usually ${money(r.default_pay)}` : "No range";
    return r.default_amount ? `Usually ${money(r.default_amount)}` : "Amount set per person";
  }

  function actions(kind, r) {
    if (r.can.edit) return `<button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-edit="${r.id}" aria-label="Change ${esc(r.name)}"><i class="ri-edit-line"></i></button>${r.in_use ? "" : ` <button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-remove="${r.id}" aria-label="Remove ${esc(r.name)}"><i class="ri-delete-bin-line"></i></button>`}`;
    if (r.can.toggle_here && r.is_active) return `<label class="form-check form-switch mb-0 d-inline-flex justify-content-end" title="Use it here"><input class="form-check-input" type="checkbox" data-here="${r.id}"${r.hidden ? "" : " checked"} aria-label="Use ${esc(r.name)} here"></label>`;
    return "";
  }

  function render() {
    const k = KIND[tab];
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("setTitle").textContent = k.title;
    $("setSub").textContent = k.sub;
    if ($("addLabel")) $("addLabel").textContent = k.add;
    const rows = data[k.key];
    if (window.jQuery?.fn?.DataTable?.isDataTable("#setTable")) window.jQuery("#setTable").DataTable().destroy();
    $("setHead").innerHTML = `<th>${tab === "grade" ? "Grade" : "Name"}</th><th class="d-none d-md-table-cell">${tab === "position" ? "Used at" : tab === "grade" ? "Pays a month" : "Amount"}</th><th>Set by</th><th class="d-none d-sm-table-cell">Here</th><th class="text-end d-none d-lg-table-cell">People</th><th class="text-end">Action</th>`;
    A.tableKit({
      tableId: "setTable",
      items: rows,
      noun: k.noun,
      search: `Search ${k.noun}...`,
      pills: [
        { key: "ours", label: "Ours", icon: "ri-home-4-line", color: "success", test: (r) => r.own },
        { key: "above", label: "Set above us", icon: "ri-git-branch-line", color: "primary", test: (r) => !r.own },
        { key: "off", label: "Not used here", icon: "ri-eye-off-line", color: "secondary", test: (r) => r.hidden || !r.is_active },
      ],
      sorts: [{ key: "name", label: "Name A-Z", order: [[0, "asc"]] }],
      nonSortable: [5],
      empty: A.empty(k.icon, `No ${k.noun} yet`, CTX.can.setup ? `Add the ${k.noun} this place uses - the diocese's show here too once it adds them.` : `None set up yet.`),
      rowHtml: (r) => `<tr data-pills="${r.own ? "ours" : "above"}${r.hidden || !r.is_active ? " off" : ""}"${r.hidden || !r.is_active ? ' class="opacity-75"' : ""}>
          <td data-order="${esc(r.code || r.name)}"><div class="fw-semibold">${tab === "grade" ? `${esc(r.code)} · ` : ""}${esc(r.name)}</div>${r.description ? `<div class="acc-sub">${esc(r.description)}</div>` : ""}<div class="acc-sub d-md-none">${details(tab, r)}</div></td>
          <td class="d-none d-md-table-cell">${details(tab, r)}</td>
          <td>${owner(r)}</td>
          <td class="d-none d-sm-table-cell">${state(r)}</td>
          <td class="text-end d-none d-lg-table-cell">${r.in_use || "-"}</td>
          <td class="text-end">${actions(tab, r)}</td>
        </tr>`,
    });
  }

  async function load() {
    $("setRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.setup();
    if (!res.ok) {
      $("setWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    const used = (list) => list.filter((r) => r.is_active && !r.hidden).length;
    $("figPosition").textContent = `${used(data.positions)} in use`;
    $("figGrade").textContent = `${used(data.grades)} in use`;
    $("figAllowance").textContent = `${used(data.allowances)} in use`;
    render();
  }

  // ------------------------------------------------------------ add or change

  function itemWindow(kind, r = null) {
    const k = KIND[kind];
    const f = (id, label, v, col = "col-sm-6", extra = "") => `<div class="${col}"><label class="form-label" for="${id}">${label}</label><input type="text" class="form-control" id="${id}" value="${esc(v ?? "")}"${extra}></div>`;
    const grades = data.grades.filter((g) => g.is_active && !g.hidden);
    const body = {
      position: `<div class="row g-2">${f("itName", "Name", r?.name, "col-sm-12", ' maxlength="100" placeholder="e.g. Church secretary"')}<div class="col-sm-6"><label class="form-label">Used at</label><div class="d-flex flex-wrap gap-3">${Object.entries(LEVELS).map(([l, label]) => `<label class="form-check mb-0"><input class="form-check-input" type="checkbox" data-level="${l}"${r?.levels?.includes(l) ? " checked" : ""}><span class="form-check-label">${label}</span></label>`).join("")}</div><small class="acc-sub">None ticked: any level</small></div><div class="col-sm-6"><label class="form-label" for="itGrade">Usual grade</label><select class="form-select" id="itGrade"><option value="">None</option>${grades.map((g) => `<option value="${g.id}"${String(g.id) === String(r?.grade_id) ? " selected" : ""}>${esc(`${g.code} · ${g.name}`)}</option>`).join("")}</select></div></div>`,
      grade: `<div class="row g-2">${f("itCode", "Code", r?.code, "col-sm-4", ' maxlength="20" placeholder="e.g. G3"')}${f("itName", "Name", r?.name, "col-sm-8", ' maxlength="100" placeholder="e.g. Grade 3 - support staff"')}${f("itMin", "Least a month (KES)", r?.min_pay, "col-sm-4", ' inputmode="decimal"')}${f("itMax", "Most a month (KES)", r?.max_pay, "col-sm-4", ' inputmode="decimal"')}${f("itUsual", "Usual basic pay (KES)", r?.default_pay, "col-sm-4", ' inputmode="decimal"')}</div>`,
      allowance: `<div class="row g-2">${f("itName", "Name", r?.name, "col-sm-7", ' maxlength="60" placeholder="e.g. House"')}${f("itAmount", "Usual amount a month (KES)", r?.default_amount, "col-sm-5", ' inputmode="decimal" placeholder="Optional"')}</div>`,
    }[kind];
    const el = K.confirmWindow({
      title: r ? `Change ${r.name}` : k.add,
      subtitle: kind === "allowance" ? "Allowances add to pay - there are no deductions here" : `Used at ${CTX.place.name || "this place"}${CTX.level !== "church" ? " and the places below" : ""}`,
      icon: k.icon,
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: k.icon, title: `The ${k.one}`, body },
        { icon: "ri-information-line", title: "More", body: `<div class="row g-2"><div class="col-12"><input type="text" class="form-control" id="itAbout" maxlength="255" placeholder="A note (optional)" value="${esc(r?.description || "")}"></div></div>${r ? `<label class="form-check form-switch mt-3 mb-0"><input class="form-check-input" type="checkbox" id="itOn"${r.is_active ? " checked" : ""}><span class="form-check-label">In use (switched off, nobody new can be given it)</span></label>` : ""}` },
      ]),
      run: async () => {
        const v = (id) => el.querySelector(`#${id}`)?.value.trim() ?? null;
        const payload = { name: v("itName"), description: v("itAbout") || null, ...(r ? { is_active: el.querySelector("#itOn").checked } : {}) };
        if (kind === "position") Object.assign(payload, { levels: [...el.querySelectorAll("[data-level]:checked")].map((x) => x.dataset.level), grade_id: v("itGrade") ? Number(v("itGrade")) : null });
        if (kind === "grade") Object.assign(payload, { code: v("itCode"), min_pay: n(v("itMin")), max_pay: n(v("itMax")), default_pay: n(v("itUsual")) });
        if (kind === "allowance") payload.default_amount = n(v("itAmount"));
        const out = await API.saveItem(kind, r?.id, payload);
        if (out.ok) load();
        return out;
      },
    });
    if (el.querySelector("#itGrade")) UI.enhanceSelect(el.querySelector("#itGrade"), { search: false });
  }

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", () => {
    $("addBtn")?.addEventListener("click", () => itemWindow(tab));
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) =>
      b.addEventListener("click", () => {
        tab = b.dataset.tab;
        const p = new URLSearchParams(window.location.search);
        tab === "position" ? p.delete("tab") : p.set("tab", tab);
        ["q", "pill", "sort"].forEach((x) => p.delete(x));
        history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
        render();
      }),
    );
    $("setTable").addEventListener("click", async (ev) => {
      const list = data[KIND[tab].key];
      const ed = ev.target.closest("[data-edit]");
      if (ed) return itemWindow(tab, list.find((r) => String(r.id) === ed.dataset.edit));
      const rm = ev.target.closest("[data-remove]");
      if (rm) {
        const r = list.find((x) => String(x.id) === rm.dataset.remove);
        return K.confirmWindow({
          title: `Remove ${r.name}?`,
          subtitle: "Nobody uses it, so it can go",
          icon: "ri-delete-bin-line",
          danger: true,
          go: '<i class="ri-delete-bin-line me-1"></i>Remove',
          body: `<p class="mb-0">${esc(r.name)} will no longer be offered${CTX.level !== "church" ? " here or in the places below" : ""}.</p>`,
          run: async () => {
            const out = await API.removeItem(tab, r.id);
            if (out.ok) load();
            return out;
          },
        });
      }
    });
    $("setTable").addEventListener("change", async (ev) => {
      const sw = ev.target.closest("[data-here]");
      if (!sw) return;
      const out = await API.here(tab, sw.dataset.here, sw.checked);
      if (!out.ok) {
        sw.checked = !sw.checked;
        return Toast.error(out.message);
      }
      Toast.success(out.message);
      load();
    });
    load();
  });
})();
