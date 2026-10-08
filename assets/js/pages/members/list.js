/**
 * ============================================================================
 * MEMBERS - the list (the church's private register)
 * ============================================================================
 * Four cards (active, new this month, Sunday school, leaving this year), then
 * the register with our usual filter strip: search by name, phone or area,
 * and Sunday school / main church and status - in place and kept in the
 * URL. A row opens the member's page. "Archived" shows the people taken off
 * the lists.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  let list = params.get("list") === "archived" ? "archived" : "current";
  let table = null;

  // -------------------------------------------------------------- the cards
  function renderCards(o) {
    const joinsSeries = { labels: o.months, data: o.joins };
    const cards = [
      { icon: "ri-group-line", label: "Active members", value: M.num(o.active), color: "primary", series: { labels: o.months, data: o.active_series }, sub: o.archived ? `${M.num(o.archived)} archived` : "Everyone listed as a member" },
      { icon: "ri-user-add-line", label: "New this month", value: M.num(o.new_this_month), color: "success", delta: UI.periodDelta(o.new_this_month, o.new_last_month), series: joinsSeries, sub: `${M.num(o.new_last_month)} last month` },
      { icon: "ri-book-open-line", label: "Sunday school", value: M.num(o.sunday_school), color: "pink", sub: o.active ? `${Math.round((o.sunday_school / o.active) * 100)}% of members · ${M.num(o.baptised)} baptised` : "Of our members" },
      { icon: "ri-logout-box-r-line", label: "Leaving this year", value: M.num(o.leaving_this_year), color: "secondary", series: { labels: o.months, data: o.leaves }, sub: "Transfers out, inactive and passed on" },
    ];
    const row = $("statCardsRow");
    row.innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
    UI.mountSparklines(row);
  }

  // -------------------------------------------------------------- the table
  function rowHtml(p) {
    const href = `${CTX.baseUrl}/member?id=${p.id}`;
    return `<tr class="mb-row" data-href="${href}">
      <td data-search="${M.esc(`${p.name} ${p.phone || ""} ${p.area || ""}`)}">
        <div class="d-flex align-items-center gap-2">
          ${M.avatar(p, "sm")}
          <div class="min-w-0"><a class="fw-semibold mb-link" href="${href}">${M.esc(p.name)}</a><div class="mb-sub">${M.esc(p.phone || "No phone")}</div></div>
        </div>
      </td>
      <td>${p.area ? M.esc(p.area) : '<span class="mb-sub">Not given</span>'}</td>
      <td data-search="${p.congregation || "none"}">${p.congregation ? M.CONGREGATIONS[p.congregation].label : '<span class="mb-sub">Not set</span>'}</td>
      <td class="d-none d-md-table-cell">${p.gender ? (p.gender === "male" ? "Male" : "Female") : "-"}</td>
      <td data-search="${p.status}">${M.statusPill(p.status)}</td>
      <td class="d-none d-lg-table-cell" data-order="${p.joined_on || ""}">${M.day(p.joined_on)}</td>
      <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
    </tr>`;
  }

  function toolbar() {
    const filters = [
      { id: "fGroup", label: "Sunday school or main church", options: [...Object.entries(M.CONGREGATIONS).map(([value, g]) => ({ value, label: g.label, color: g.chip })), { value: "none", label: "Not set", color: "secondary" }], columnIndex: 2, exact: true },
      { id: "fStatus", label: "All statuses", options: Object.entries(M.STATUS).filter(([k]) => k !== "visitor").map(([value, s]) => ({ value, label: s.label, color: s.color })), columnIndex: 4, exact: true },
    ];
    UI.renderFilterToolbar("memberFilters", { searchPlaceholder: "Search by name, phone or area...", filters });
    return filters;
  }

  async function loadList() {
    $("memberRows").innerHTML = UI.renderTableLoading(7);
    const res = await MembersAPI.list({ archived: list === "archived" ? 1 : "" });
    if (!res.ok) {
      $("memberTableWrap").innerHTML = M.errorBox(res.message);
      return;
    }
    const items = res.data.items;
    const filters = toolbar();
    if (!items.length) {
      table?.destroy?.();
      table = null;
      $("memberFilters").hidden = true;
      $("memberRows").innerHTML = `<tr><td colspan="7">${
        list === "archived"
          ? M.empty("ri-archive-line", "No one archived", "People you archive leave the lists but stay counted. You can bring them back.")
          : M.empty("ri-contacts-book-2-line", "No members yet", "Add your members one by one - only your church's leaders will ever see their names.", CTX.can.manage ? `<a class="btn btn-primary" href="${CTX.baseUrl}/new"><i class="ri-user-add-line me-1"></i>Add your first member</a>` : "")
      }</td></tr>`;
      return;
    }
    $("memberFilters").hidden = false;
    $("memberRows").innerHTML = items.map(rowHtml).join("");
    table = UI.initListDataTable("memberTable", { hideDefaultSearch: true, order: [[0, "asc"]], nonSortableColumns: [6], noun: "members", pageLength: 25 });
    filters.forEach((f) => UI.enhanceSelect($(f.id), { search: false }));
    UI.wireFilterToolbar("memberFilters", table, filters, { noun: list === "archived" ? "archived" : "members" });
    M.loadPhotos($("memberRows"));
  }

  function wireListSwitch() {
    const btns = [...document.querySelectorAll("#listSwitch [data-list]")];
    const paint = () => btns.forEach((b) => {
      b.classList.toggle("btn-primary", b.dataset.list === list);
      b.classList.toggle("btn-outline-primary", b.dataset.list !== list);
    });
    paint();
    btns.forEach((b) =>
      b.addEventListener("click", () => {
        if (b.dataset.list === list) return;
        list = b.dataset.list;
        paint();
        const q = new URLSearchParams(window.location.search);
        list === "archived" ? q.set("list", "archived") : q.delete("list");
        history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
        loadList();
      }),
    );
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-lg-6 col-md-6");
    wireListSwitch();
    // A row anywhere opens the member (the name is also a real link).
    $("memberRows").addEventListener("click", (e) => {
      if (e.target.closest("a")) return;
      const tr = e.target.closest("tr[data-href]");
      if (tr) window.location.href = tr.dataset.href;
    });
    const [o] = await Promise.all([MembersAPI.overview(), loadList()]);
    if (!o.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(o.message)}</div>`;
      return;
    }
    renderCards(o.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
