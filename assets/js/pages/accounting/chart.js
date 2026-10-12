/**
 * ============================================================================
 * ACCOUNTING - Chart of accounts (chart.php, every level)
 * ============================================================================
 * The accounts in plain kinds - Money in, Money out, What we own, What we
 * owe, Funds - grouped under their headings. At the diocese: the standard
 * accounts every place posts to; adding one asks its kind, group and name,
 * and the code is suggested. At a church or region ("place mode"): the
 * standard accounts with the place's own under them, and "Add an account"
 * adds its own line (money in or out, a thing it owns, a debt), a bank or
 * M-Pesa account, or a fund - the code is made for it.
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
  // A church or region: the standard chart is read here, and the place adds its own.
  const PLACE = !window.ACC_CTX?.can?.chart;
  const canAdd = () => (PLACE ? !!data?.can?.accounts && !A.viewingBelow() : true);
  const shown = () => (PLACE ? data.chart : std());
  const CASH = [["bank", "A bank account", "ri-bank-line"], ["mpesa", "An M-Pesa till, paybill or phone", "ri-smartphone-line"], ["airtel", "An Airtel Money number", "ri-smartphone-line"]];

  function tabs() {
    $("typeTabs").innerHTML = KINDS.map(
      (k) => `<button class="nav-link section-tab${k.key === type ? " active" : ""}" data-type="${k.key}" type="button" role="tab" aria-selected="${k.key === type}">
        <span class="section-tab-icon bg-${k.color}"><i class="${k.icon}"></i></span>
        <span class="section-tab-text"><strong>${k.label}</strong><small>${PLACE ? `${data.chart.filter((a) => a.type === k.key && a.own).length} of ours` : `${std().filter((a) => a.type === k.key && !a.is_header && a.is_active).length} in use`}</small></span>
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
        const under = (p) => list.filter((c) => c.parent_id === p.id).sort((x, y) => x.code.localeCompare(y.code)).forEach((c) => {
          order.set(c.id, n++);
          under(c);
        });
        under(a);
      });
    list.forEach((a) => !order.has(a.id) && order.set(a.id, n++));
    return { order, heads };
  }

  function render() {
    const k = kindOf(type);
    tabs();
    $("typeTitle").textContent = k.label;
    if (PLACE) {
      $("chartSub").textContent = "The standard accounts, with ours under them - press + to add our own line under one";
      $("chartBalHead").textContent = "Balance";
      if ($("addBtn")) $("addBtn").hidden = !canAdd();
    }
    const list = shown().filter((a) => a.type === type);
    const { order } = grouped(list);
    A.tableKit({
      tableId: "stdChartTable",
      items: list,
      noun: "accounts",
      search: "Search an account...",
      pills: [
        ...(PLACE ? [{ key: "ours", label: "Ours", icon: "ri-home-4-line", color: "primary", test: (a) => a.own }] : []),
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
        const editable = !PLACE || (a.own && canAdd());
        return `<tr class="acc-row${a.is_header ? " acc-group-row" : ""}${a.is_active ? "" : " opacity-75"}"${editable ? ` data-edit="${a.id}"` : ""} data-pills="${[a.is_header ? "group" : a.is_active ? "on" : "off", a.own ? "ours" : ""].join(" ")}">
          <td data-order="${String(order.get(a.id)).padStart(4, "0")}"><div class="d-flex align-items-center gap-2${child ? " ps-4" : ""}">${a.is_header ? `<span class="avatar avatar-xs avatar-rounded bg-${k.color} ${A.textOn(k.color)}"><i class="ri-folder-line"></i></span>` : ""}<div class="min-w-0"><span class="${a.is_header ? "fw-bold" : "fw-semibold"}">${esc(a.name)}</span>${a.own ? ' <span class="badge bg-primary text-white ms-1">Ours</span>' : ""}${a.system_key && !a.is_header ? ' <i class="ri-lock-line text-primary" title="The books rely on it"></i>' : ""}<div class="acc-sub">${a.is_header ? "Group" : ""} <span class="opacity-75">${esc(a.code)}</span></div></div></div></td>
          <td class="d-none d-md-table-cell" data-order="${esc(a.name)}">${a.cash_kind ? A.methodChip(a.cash_kind === "petty_cash" ? "cash" : a.cash_kind, A.kind(a.cash_kind).label) : a.description ? esc(a.description) : '<span class="acc-sub">-</span>'}</td>
          <td>${PLACE ? (a.is_header ? '<span class="acc-sub">-</span>' : `<span class="fw-semibold${(a.balance || 0) < 0 ? " text-danger" : (a.balance || 0) > 0 ? " text-success" : ""}">${a.balance ? A.money(a.balance, { cents: false }) : "-"}</span>`) : a.is_header ? '<span class="acc-sub">-</span>' : a.is_active ? '<span class="badge bg-success text-white">In use</span>' : '<span class="badge bg-secondary text-dark">Off</span>'}</td>
          <td class="text-end">${editable ? `<button type="button" class="btn btn-sm btn-icon btn-warning" data-edit="${a.id}" title="Change" aria-label="Change ${esc(a.name)}"><i class="ri-edit-line"></i></button>` : !a.is_header && a.can_parent && canAdd() ? `<button type="button" class="btn btn-sm btn-icon btn-primary" data-under="${a.id}" title="Add our own line under it" aria-label="Add our own line under ${esc(a.name)}"><i class="ri-add-line"></i></button>` : ""}</td>
        </tr>`;
      },
    });
    const settings = `${window.ACC_CTX?.siteUrl || ""}/${window.ACC_CTX?.level || "diocese"}/settings/?section=givingoptions`;
    const mine = (f) => f.owner && Number(f.owner.id) === Number(window.ACC_CTX?.place?.id);
    $("fundRows").innerHTML = `<div class="acc-fund-list">${data.funds.map((f) => `<div class="acc-fund"><span class="avatar avatar-sm avatar-rounded bg-${f.is_restricted ? "warning text-dark" : "success text-white"}"><i class="${f.is_restricted ? "ri-lock-line" : "ri-hand-heart-line"}"></i></span><div class="flex-fill min-w-0"><div class="fw-semibold">${esc(f.name)}${PLACE && mine(f) ? ' <span class="badge bg-primary text-white ms-1">Ours</span>' : ""}</div><div class="acc-sub">${f.is_restricted ? "Kept apart" : "Free to use"}${f.owner && !mine(f) ? ` · ${esc(f.owner.name)}` : ""}</div></div></div>`).join("")}</div><div class="d-flex flex-wrap gap-2 mt-3">${PLACE && canAdd() ? '<button type="button" class="btn btn-sm btn-primary" data-add-fund><i class="ri-add-line me-1"></i>Add a fund</button>' : ""}<a class="btn btn-sm btn-outline-primary" href="${settings}"><i class="ri-settings-3-line me-1"></i>Set up funds</a></div>`;
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

  function edit(a = null, opts = {}) {
    if (a) return a.own ? changeOwn(a) : change(a);
    if (PLACE) return addOwn(opts);
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

  /**
   * A church or region adds its own account: a line under a standard one
   * (money in or out, a thing it owns, a debt), a bank or M-Pesa account, or
   * a fund. Three questions; the code is made for it.
   */
  function addOwn({ kind: startKind = type, parentId = null } = {}) {
    let kind = startKind;
    const parents = () => std().filter((x) => x.can_parent && x.type === kind);
    const el = K.confirmWindow({
      title: "Add an account",
      subtitle: `Our own - ${data.place?.name || "this place"} - the code is made for you`,
      icon: "ri-book-3-line",
      go: '<i class="ri-check-line me-1"></i>Add account',
      body: K.parts([
        { icon: "ri-question-line", title: "1. What kind of account?", body: `<div class="go-kind-group">${KINDS.map((k) => `<label class="go-kind${k.key === kind ? " is-on" : ""}"><input type="radio" name="chKind" value="${k.key}"${k.key === kind ? " checked" : ""}><span><strong>${k.one}</strong><small>${k.about}</small></span></label>`).join("")}</div>` },
        { icon: "ri-folder-line", title: "2. Where does it go?", body: `<div id="chWhereWrap"><select class="form-select" id="chParent" aria-label="Where it goes"></select></div><div id="chFundWrap" hidden><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="chApart" checked><label class="form-check-label" for="chApart">Keep it apart - only for this purpose</label></div><span class="fs-12 fw-medium d-block mt-2">It sits under Other funds in the books</span></div>` },
        { icon: "ri-edit-line", title: "3. Its name", body: `<input type="text" class="form-control" id="chName" maxlength="150" placeholder="e.g. Choir uniforms, Church van, SACCO loan"><input type="text" class="form-control mt-2" id="chAbout" maxlength="255" placeholder="What it is for (optional)"><div class="d-flex align-items-center gap-2 mt-3"><span class="badge bg-primary text-white"><i class="ri-hashtag me-1"></i>Code <span id="chCodeShow"></span></span><span class="fs-12 fw-medium" id="chCodeNote"></span></div>` },
      ]),
      run: async () => {
        const name = $("chName").value.trim();
        const where = $("chParent").value;
        let body;
        if (kind === "fund") body = { kind: "fund", name, is_restricted: $("chApart").checked };
        else if (where.startsWith("cash:")) body = { cash_kind: where.slice(5), name, description: $("chAbout").value.trim() || null };
        else body = { parent_id: Number(where) || null, name, description: $("chAbout").value.trim() || null };
        if (!name) return Toast.error("Give it a name."), null;
        if (kind !== "fund" && !where) return Toast.error("Pick where it goes."), null;
        const res = await AccountingAPI.saveAccount(null, body);
        if (res.ok) {
          type = kind;
          load();
        }
        return res;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-lg");
    const code = () => {
      const where = $("chParent").value;
      const p = std().find((x) => String(x.id) === where);
      const n = p ? data.chart.filter((x) => x.own && x.parent_id === p.id).length + 1 : 0;
      $("chCodeShow").textContent = kind === "fund" ? "made from the name" : p ? `${p.code}-${String(n).padStart(2, "0")}` : where.startsWith("cash:") ? "given when saved" : "-";
      $("chCodeNote").textContent = p ? `Counts under ${p.name}` : "";
    };
    const fill = () => {
      const fund = kind === "fund";
      $("chWhereWrap").hidden = fund;
      $("chFundWrap").hidden = !fund;
      const s = $("chParent");
      const list = parents();
      s.innerHTML = fund
        ? ""
        : `<option value="">Pick where it goes…</option>${kind === "asset" ? CASH.map(([v, l, i]) => `<option value="cash:${v}" data-icon="${i}" data-color="primary">${l}</option>`).join("") : ""}${list.map((x) => `<option value="${x.id}" data-icon="ri-folder-line" data-color="${kindOf(kind).color}"${x.id === parentId ? " selected" : ""}>Under ${esc(x.name)}</option>`).join("")}`;
      DemographicsUI.syncSelect?.(s);
      code();
    };
    el.querySelectorAll('input[name="chKind"]').forEach((r) =>
      r.addEventListener("change", () => {
        kind = r.value;
        parentId = null;
        el.querySelectorAll('input[name="chKind"]').forEach((x) => x.closest(".go-kind").classList.toggle("is-on", x.checked));
        fill();
      }),
    );
    DemographicsUI.enhanceSelect($("chParent"), { search: true });
    window.jQuery?.($("chParent")).on("change", code);
    fill();
  }

  /** Our own account: rename, or switch it off (a bank or M-Pesa one opens on Cash & bank). */
  function changeOwn(a) {
    if (a.cash_kind) return (window.location.href = `${window.ACC_CTX?.baseUrl || ""}/account.php?id=${a.id}`);
    K.confirmWindow({
      title: `Change ${a.name}`,
      subtitle: `Ours · code ${a.code}`,
      icon: "ri-book-3-line",
      go: '<i class="ri-check-line me-1"></i>Save',
      body: K.parts([
        { icon: "ri-edit-line", title: "Name", body: `<input type="text" class="form-control" id="chName" maxlength="150" value="${esc(a.name)}" placeholder="e.g. Choir uniforms"><input type="text" class="form-control mt-2" id="chAbout" maxlength="255" value="${esc(a.description || "")}" placeholder="What it is for (optional)">` },
        { icon: "ri-toggle-line", title: "In use", body: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="chActive"${a.is_active ? " checked" : ""}><label class="form-check-label" for="chActive">We post to it</label></div>` },
      ]),
      run: async () => {
        const res = await AccountingAPI.saveAccount(a.id, { name: $("chName").value.trim(), description: $("chAbout").value.trim() || null, is_active: $("chActive").checked });
        if (res.ok) load();
        return res;
      },
    });
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
    // From "Add an account" on the overview (or ?add=1&kind=fund): open the window straight away, once.
    const p = new URLSearchParams(window.location.search);
    if (p.get("add")) {
      p.delete("add");
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      edit();
    }
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
      const u = e.target.closest("[data-under]");
      if (u) {
        const p = data.chart.find((a) => a.id === Number(u.dataset.under));
        return addOwn({ kind: p.type, parentId: p.id });
      }
      const b = e.target.closest("[data-edit]");
      if (b && !e.target.closest("a, input")) edit(data.chart.find((a) => a.id === Number(b.dataset.edit)));
    });
    $("fundRows").addEventListener("click", (e) => e.target.closest("[data-add-fund]") && addOwn({ kind: "fund" }));
    load();
  });
})();
