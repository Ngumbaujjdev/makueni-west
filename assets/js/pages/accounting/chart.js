/**
 * ============================================================================
 * ACCOUNTING - Chart of accounts (chart.php, the diocese)
 * ============================================================================
 * The standard accounts every place posts to, by type: add one, rename one,
 * switch one off (those the books rely on stay on); and the funds.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const K = PeopleKit;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  let type = "asset";
  const TYPES = [
    ["asset", "Assets", "ri-safe-2-line", "primary", "1"],
    ["liability", "Liabilities", "ri-hand-coin-line", "danger", "2"],
    ["fund", "Funds", "ri-hand-heart-line", "warning", "3"],
    ["income", "Income", "ri-arrow-down-circle-line", "success", "4"],
    ["expense", "Expenses", "ri-arrow-up-circle-line", "purple", "5"],
  ];

  function render() {
    const std = data.chart.filter((a) => !a.own);
    $("typePills").innerHTML = `<div class="pp-pills" role="tablist">${TYPES.map(([k, l, i, c]) => `<button type="button" class="pp-pill${k === type ? " is-on" : ""}" style="--q: var(--${c}-rgb)" data-type="${k}" role="tab" aria-selected="${k === type}"><i class="${i}"></i>${l}<span class="pp-pill-count">${std.filter((a) => a.type === k && !a.is_header).length}</span></button>`).join("")}</div>`;
    A.tableKit({
      tableId: "stdChartTable",
      items: std.filter((a) => a.type === type),
      noun: "accounts",
      search: "Search code or name...",
      pills: [
        { key: "on", label: "In use", icon: "ri-checkbox-circle-line", color: "success", test: (a) => a.is_active && !a.is_header },
        { key: "off", label: "Off", icon: "ri-forbid-line", color: "danger", test: (a) => !a.is_active },
        { key: "needed", label: "Needed by the books", icon: "ri-lock-line", color: "primary", test: (a) => !!a.system_key },
        { key: "group", label: "Groups", icon: "ri-folder-line", color: "purple", test: (a) => a.is_header },
      ],
      sorts: [
        { key: "code", label: "By code", order: [[0, "asc"]] },
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
      ],
      nonSortable: [3],
      rowHtml: (a) => `<tr data-pills="${a.is_header ? "group" : a.is_active ? "on" : "off"}${a.system_key ? " needed" : ""}"${a.is_header ? ' class="acc-group-row"' : ""}><td data-order="${esc(a.code)}">${esc(a.code)}</td><td data-order="${esc(a.name)}"><span class="fw-semibold">${esc(a.name)}</span>${a.is_header ? ' <span class="soft-chip soft-purple"><i class="ri-folder-line"></i>Group</span>' : ""}${a.is_active ? "" : ' <span class="soft-chip soft-danger">Off</span>'}${a.system_key && !a.is_header ? ' <span class="soft-chip soft-primary" title="The books rely on it"><i class="ri-lock-line"></i>Needed</span>' : ""}${a.description ? `<div class="acc-sub">${esc(a.description)}</div>` : ""}</td><td class="d-none d-md-table-cell">${a.cash_kind ? A.methodChip(a.cash_kind === "petty_cash" ? "cash" : a.cash_kind, A.kind(a.cash_kind).label) : `<span class="acc-sub">${esc(a.type_label)}</span>`}</td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-edit="${a.id}"><i class="ri-edit-line me-1"></i>Change</button></td></tr>`,
    });
    $("fundRows").innerHTML = `<div class="acc-fund-list">${data.funds.map((f) => `<div class="acc-fund"><span class="avatar avatar-sm avatar-rounded bg-${f.is_restricted ? "warning text-dark" : "success text-white"}"><i class="${f.is_restricted ? "ri-lock-line" : "ri-hand-heart-line"}"></i></span><div class="flex-fill min-w-0"><div class="fw-semibold">${esc(f.name)}</div><div class="acc-sub">${esc(f.code)} · ${f.is_restricted ? "Restricted" : "Free to use"}${f.description ? ` · ${esc(f.description)}` : ""}</div></div></div>`).join("")}</div>`;
  }

  function edit(a = null) {
    const t = TYPES.find((x) => x[0] === (a?.type || type));
    K.confirmWindow({
      title: a ? `Change ${a.code} ${a.name}` : "Add an account to the chart",
      subtitle: a ? "Every place sees the new name" : `${t[1]} codes start with ${t[4]}`,
      icon: "ri-book-3-line",
      go: `<i class="ri-check-line me-1"></i>${a ? "Save" : "Add account"}`,
      body: K.parts([
        ...(a ? [] : [{ icon: "ri-price-tag-3-line", title: "Type and code", body: `<div class="row g-2"><div class="col-sm-6"><select class="form-select" id="chType">${TYPES.map(([k, l]) => `<option value="${k}"${k === t[0] ? " selected" : ""}>${l}</option>`).join("")}</select></div><div class="col-sm-6"><input type="text" class="form-control" id="chCode" maxlength="20" placeholder="e.g. ${t[4]}240" inputmode="numeric"></div></div>` }]),
        { icon: "ri-edit-line", title: "Name", body: `<input type="text" class="form-control" id="chName" maxlength="150" value="${esc(a?.name || "")}" placeholder="e.g. Fuel"><input type="text" class="form-control mt-2" id="chAbout" maxlength="255" value="${esc(a?.description || "")}" placeholder="What it is for (optional)">` },
        ...(a && !a.is_header ? [{ icon: "ri-toggle-line", title: "In use", body: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="chActive"${a.is_active ? " checked" : ""}${a.system_key ? " disabled" : ""}><label class="form-check-label" for="chActive">${a.system_key ? "The books rely on this account" : "Places can post to it"}</label></div>` }] : []),
      ]),
      run: async () => {
        const body = { name: $("chName").value.trim(), description: $("chAbout").value.trim() || null };
        if (!a) Object.assign(body, { type: $("chType").value, code: $("chCode").value.trim() });
        if (a && $("chActive") && !a.system_key) body.is_active = $("chActive").checked;
        const res = await AccountingAPI.saveChart(a?.id, body);
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
    $("typePills").addEventListener("click", (e) => {
      const b = e.target.closest("[data-type]");
      if (!b) return;
      type = b.dataset.type;
      render();
    });
    $("chartRows").addEventListener("click", (e) => {
      const b = e.target.closest("[data-edit]");
      if (b) edit(data.chart.find((a) => a.id === Number(b.dataset.edit)));
    });
    load();
  });
})();
