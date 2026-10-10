/**
 * ============================================================================
 * ACCOUNTING - Chart of accounts (chart.php, the diocese)
 * ============================================================================
 * The standard accounts every place posts to, in plain kinds - Money in,
 * Money out, What we own, What we owe, Funds - grouped under their headings.
 * Adding one asks what kind it is, which group, and its name; the code is
 * suggested (the next free number in that group) and can be changed.
 * Accounts the books rely on can be renamed but not switched off.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const K = PeopleKit;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  const KINDS = [
    { key: "income", label: "Money in", one: "Money that comes in", about: "Tithes, offerings, donations, hire...", icon: "ri-arrow-down-circle-line", color: "success", digit: "4" },
    { key: "expense", label: "Money out", one: "Money we spend", about: "Salaries, electricity, transport...", icon: "ri-arrow-up-circle-line", color: "danger", digit: "5" },
    { key: "asset", label: "What we own", one: "Something we own", about: "Cash, bank, things we bought, money owed to us", icon: "ri-safe-2-line", color: "primary", digit: "1" },
    { key: "liability", label: "What we owe", one: "Something we owe", about: "Bills not yet paid, money held for others", icon: "ri-hand-coin-line", color: "warning", digit: "2" },
    { key: "fund", label: "Funds", one: "A fund", about: "Money kept for a purpose", icon: "ri-hand-heart-line", color: "purple", digit: "3" },
  ];
  let type = KINDS.some((k) => k.key === new URLSearchParams(window.location.search).get("kind")) ? new URLSearchParams(window.location.search).get("kind") : "income";
  const kindOf = (k) => KINDS.find((x) => x.key === k);
  const std = () => data.chart.filter((a) => !a.own);

  function tabs() {
    $("typeTabs").innerHTML = KINDS.map(
      (k) => `<button class="nav-link section-tab${k.key === type ? " active" : ""}" data-type="${k.key}" type="button" role="tab" aria-selected="${k.key === type}">
        <span class="section-tab-icon bg-${k.color}"><i class="${k.icon}"></i></span>
        <span class="section-tab-text"><strong>${k.label}</strong><small>${std().filter((a) => a.type === k.key && !a.is_header && a.is_active).length} in use</small></span>
      </button>`,
    ).join("");
  }

  /** Each heading followed by the accounts under it - the order the table keeps. */
  function grouped(list) {
    const heads = list.filter((a) => a.is_header);
    const order = new Map();
    let n = 0;
    list
      .filter((a) => !a.parent_id || !list.some((h) => h.id === a.parent_id))
      .sort((x, y) => x.code.localeCompare(y.code))
      .forEach((a) => {
        order.set(a.id, n++);
        if (a.is_header) list.filter((c) => c.parent_id === a.id).sort((x, y) => x.code.localeCompare(y.code)).forEach((c) => order.set(c.id, n++));
      });
    list.forEach((a) => !order.has(a.id) && order.set(a.id, n++));
    return { order, heads };
  }

  function render() {
    const k = kindOf(type);
    tabs();
    $("typeTitle").textContent = k.label;
    const list = std().filter((a) => a.type === type);
    const { order } = grouped(list);
    A.tableKit({
      tableId: "stdChartTable",
      items: list,
      noun: "accounts",
      search: "Search an account...",
      pills: [
        { key: "on", label: "In use", icon: "ri-checkbox-circle-line", color: "success", test: (a) => a.is_active && !a.is_header },
        { key: "off", label: "Off", icon: "ri-forbid-line", color: "secondary", test: (a) => !a.is_active },
        { key: "group", label: "Groups", icon: "ri-folder-line", color: "purple", test: (a) => a.is_header },
      ],
      sorts: [
        { key: "group", label: "By group", order: [[0, "asc"]] },
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
      ],
      nonSortable: [3],
      rowHtml: (a) => {
        const child = a.parent_id && list.some((h) => h.id === a.parent_id);
        return `<tr class="acc-row${a.is_header ? " acc-group-row" : ""}${a.is_active ? "" : " opacity-75"}" data-edit="${a.id}" data-pills="${a.is_header ? "group" : a.is_active ? "on" : "off"}">
          <td data-order="${String(order.get(a.id)).padStart(4, "0")}"><div class="d-flex align-items-center gap-2${child ? " ps-4" : ""}">${a.is_header ? `<span class="avatar avatar-xs avatar-rounded bg-${k.color} ${A.textOn(k.color)}"><i class="ri-folder-line"></i></span>` : ""}<div class="min-w-0"><span class="${a.is_header ? "fw-bold" : "fw-semibold"}">${esc(a.name)}</span>${a.system_key && !a.is_header ? ' <i class="ri-lock-line text-primary" title="The books rely on it"></i>' : ""}<div class="acc-sub">${a.is_header ? "Group" : ""} <span class="opacity-75">${esc(a.code)}</span></div></div></div></td>
          <td class="d-none d-md-table-cell" data-order="${esc(a.name)}">${a.cash_kind ? A.methodChip(a.cash_kind === "petty_cash" ? "cash" : a.cash_kind, A.kind(a.cash_kind).label) : a.description ? esc(a.description) : '<span class="acc-sub">-</span>'}</td>
          <td>${a.is_header ? '<span class="acc-sub">-</span>' : a.is_active ? '<span class="badge bg-success text-white">In use</span>' : '<span class="badge bg-secondary text-dark">Off</span>'}</td>
          <td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-edit="${a.id}" title="Change" aria-label="Change ${esc(a.name)}"><i class="ri-edit-line"></i></button></td>
        </tr>`;
      },
    });
    const settings = `${window.ACC_CTX?.siteUrl || ""}/${window.ACC_CTX?.level || "diocese"}/settings/?section=givingoptions`;
    $("fundRows").innerHTML = `<div class="acc-fund-list">${data.funds.map((f) => `<div class="acc-fund"><span class="avatar avatar-sm avatar-rounded bg-${f.is_restricted ? "warning text-dark" : "success text-white"}"><i class="${f.is_restricted ? "ri-lock-line" : "ri-hand-heart-line"}"></i></span><div class="flex-fill min-w-0"><div class="fw-semibold">${esc(f.name)}</div><div class="acc-sub">${f.is_restricted ? "Kept apart" : "Free to use"}</div></div></div>`).join("")}</div><a class="btn btn-sm btn-outline-primary mt-3" href="${settings}"><i class="ri-settings-3-line me-1"></i>Set up funds</a>`;
  }

  /**
   * The next free code: after the last account in that group (or kind), in
   * steps of 10, inside the kind's own range (4000-4999 for money in); when
   * the end of the range is used, the first gap in it.
   */
  function suggestCode(kind, parentId) {
    const k = kindOf(kind);
    const lo = Number(`${k.digit}000`);
    const hi = lo + 999;
    const taken = new Set(std().map((a) => String(a.code)));
    const num = (a) => parseInt(String(a.code).replace(/\D/g, ""), 10);
    const parent = std().find((a) => a.id === parentId);
    const group = std().filter((a) => (parent ? a.parent_id === parentId : a.type === kind && !a.parent_id)).map(num).filter((n) => n >= lo && n <= hi);
    const start = group.length ? Math.max(...group) + 10 : parent ? num(parent) + 10 : lo;
    const free = (from) => {
      for (let n = Math.ceil(from / 10) * 10; n <= hi; n += 10) if (!taken.has(String(n))) return String(n);
      return null;
    };
    return free(start) || free(lo) || "";
  }

  function edit(a = null) {
    if (a) return change(a);
    let kind = type;
    const el = K.confirmWindow({
      title: "Add an account",
      subtitle: "Three questions - the code is filled in for you",
      icon: "ri-book-3-line",
      go: '<i class="ri-check-line me-1"></i>Add account',
      body: K.parts([
        { icon: "ri-question-line", title: "1. What kind of account?", body: `<div class="go-kind-group">${KINDS.map((k) => `<label class="go-kind${k.key === kind ? " is-on" : ""}"><input type="radio" name="chKind" value="${k.key}"${k.key === kind ? " checked" : ""}><span><strong>${k.one}</strong><small>${k.about}</small></span></label>`).join("")}</div>` },
        { icon: "ri-folder-line", title: "2. Which group does it belong to?", body: `<select class="form-select" id="chParent"></select><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" id="chHeader"><label class="form-check-label" for="chHeader">It is a group itself - other accounts go under it</label></div>` },
        { icon: "ri-edit-line", title: "3. Its name", body: `<input type="text" class="form-control" id="chName" maxlength="150" placeholder="e.g. Fuel, Choir uniforms, Hall hire"><input type="text" class="form-control mt-2" id="chAbout" maxlength="255" placeholder="What it is for - e.g. Fuel for the church van (optional)"><div class="d-flex align-items-center gap-2 mt-3"><span class="soft-chip soft-secondary"><i class="ri-hashtag"></i>Code <b id="chCodeShow"></b></span><button type="button" class="btn btn-link btn-sm p-0" id="chCodeEdit">Change the code</button></div><input type="text" class="form-control mt-2" id="chCode" maxlength="20" inputmode="numeric" placeholder="e.g. 5240" hidden>` },
      ]),
      run: async () => {
        const res = await AccountingAPI.saveChart(null, {
          type: kind,
          code: $("chCode").value.trim(),
          parent_id: Number($("chParent").value) || null,
          is_header: $("chHeader").checked,
          name: $("chName").value.trim(),
          description: $("chAbout").value.trim() || null,
        });
        if (res.ok) {
          type = kind;
          load();
        }
        return res;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    const fill = () => {
      const s = $("chParent");
      s.innerHTML = `<option value="">On its own</option>${std().filter((x) => x.is_header && x.type === kind).map((x) => `<option value="${x.id}" data-icon="ri-folder-line" data-color="${kindOf(kind).color}">${esc(x.name)}</option>`).join("")}`;
      DemographicsUI.syncSelect?.(s);
      code();
    };
    const code = () => {
      if (!$("chCode").hidden) return;
      const c = suggestCode(kind, Number($("chParent").value) || null);
      $("chCode").value = c;
      $("chCodeShow").textContent = c || "-";
    };
    el.querySelectorAll('input[name="chKind"]').forEach((r) =>
      r.addEventListener("change", () => {
        kind = r.value;
        el.querySelectorAll('input[name="chKind"]').forEach((x) => x.closest(".go-kind").classList.toggle("is-on", x.checked));
        fill();
      }),
    );
    DemographicsUI.enhanceSelect($("chParent"));
    window.jQuery?.($("chParent")).on("change", code);
    $("chCodeEdit").addEventListener("click", () => {
      $("chCode").hidden = false;
      $("chCodeEdit").hidden = true;
      $("chCode").focus();
    });
    fill();
  }

  /** Rename an account, or switch it off. */
  function change(a) {
    K.confirmWindow({
      title: `Change ${a.name}`,
      subtitle: `${kindOf(a.type)?.label || ""} · code ${a.code} - every place sees the new name`,
      icon: "ri-book-3-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: "ri-edit-line", title: "Name", body: `<input type="text" class="form-control" id="chName" maxlength="150" value="${esc(a.name)}" placeholder="e.g. Fuel"><input type="text" class="form-control mt-2" id="chAbout" maxlength="255" value="${esc(a.description || "")}" placeholder="What it is for (optional)">` },
        ...(a.is_header ? [] : [{ icon: "ri-toggle-line", title: "In use", body: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="chActive"${a.is_active ? " checked" : ""}${a.system_key ? " disabled" : ""}><label class="form-check-label" for="chActive">${a.system_key ? "The books rely on this one - it stays on" : "Places can post to it"}</label></div>` }]),
      ]),
      run: async () => {
        const body = { name: $("chName").value.trim(), description: $("chAbout").value.trim() || null };
        if ($("chActive") && !a.system_key) body.is_active = $("chActive").checked;
        const res = await AccountingAPI.saveChart(a.id, body);
        if (res.ok) load();
        return res;
      },
    });
  }

  async function load() {
    const res = await AccountingAPI.accounts();
    if (!res.ok) {
      $("chartRows").innerHTML = `<tr><td colspan="4">${A.errorBox(res.message)}</td></tr>`;
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    render();
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("chartRows").innerHTML = DemographicsUI.renderTableLoading(4);
    $("addBtn").addEventListener("click", () => edit());
    $("typeTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-type]");
      if (!b) return;
      type = b.dataset.type;
      const p = new URLSearchParams(window.location.search);
      type === "income" ? p.delete("kind") : p.set("kind", type);
      ["q", "pill", "sort"].forEach((x) => p.delete(x));
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      render();
    });
    // On the card: the table is redrawn on each kind.
    $("stdChartTable").closest(".card").addEventListener("click", (e) => {
      const b = e.target.closest("[data-edit]");
      if (b && !e.target.closest("a, input")) edit(data.chart.find((a) => a.id === Number(b.dataset.edit)));
    });
    load();
  });
})();
