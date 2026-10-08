/**
 * ============================================================================
 * MEMBERS - the list (the church's private register)
 * ============================================================================
 * Four cards (active, new this month, baptised, leaving this year), then the
 * register: search by name or phone, filter by status, gender, age band,
 * baptised and year joined - all in place and kept in the URL. A row opens
 * the member's page. "Archived" shows the people taken off the lists.
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
      { icon: "ri-drop-line", label: "Baptised", value: M.num(o.baptised), color: "purple", sub: o.active ? `${o.baptised_share}% of our members` : "Of our members" },
      { icon: "ri-logout-box-r-line", label: "Leaving this year", value: M.num(o.leaving_this_year), color: "secondary", series: { labels: o.months, data: o.leaves }, sub: "Transfers out, inactive and passed on" },
    ];
    const row = $("statCardsRow");
    row.innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
    UI.mountSparklines(row);
  }

  // -------------------------------------------------------------- the table
  function rowHtml(p) {
    const band = p.age_band ? M.BANDS[p.age_band] : "No date of birth";
    const year = p.joined_on ? p.joined_on.slice(0, 4) : "";
    return `<tr class="mb-row" data-href="${CTX.baseUrl}/member?id=${p.id}">
      <td data-search="${M.esc(`${p.name} ${p.phone || ""}`)}">
        <div class="d-flex align-items-center gap-2">
          ${M.avatar(p, "sm")}
          <div class="min-w-0"><a class="fw-semibold mb-link" href="${CTX.baseUrl}/member?id=${p.id}">${M.esc(p.name)}</a><div class="mb-sub">${M.esc(p.phone || "No phone")}</div></div>
        </div>
      </td>
      <td data-order="${p.age ?? -1}">${p.age ?? "-"}</td>
      <td>${p.gender ? (p.gender === "male" ? "Male" : "Female") : "-"}</td>
      <td data-search="${p.status}">${M.statusPill(p.status)}</td>
      <td data-order="${p.joined_on || ""}">${M.day(p.joined_on)}</td>
      <td data-search="${p.baptised ? "yes" : "no"}">${p.baptised ? '<i class="ri-check-line text-success fs-16" aria-label="Baptised"></i>' : '<span class="mb-sub">Not yet</span>'}</td>
      <td class="d-none" data-search="${p.age_band || "none"}">${M.esc(band)}</td>
      <td class="d-none" data-search="${year}">${year}</td>
    </tr>`;
  }

  function toolbar(items) {
    const years = [...new Set(items.map((p) => (p.joined_on || "").slice(0, 4)).filter(Boolean))].sort().reverse();
    const filters = [
      { id: "fStatus", label: "Any status", options: Object.entries(M.STATUS).filter(([k]) => k !== "visitor").map(([value, s]) => ({ value, label: s.label, color: s.color })), columnIndex: 3, exact: true },
      { id: "fGender", label: "Any gender", options: [{ value: "Male", label: "Male", color: "primary" }, { value: "Female", label: "Female", color: "pink" }], columnIndex: 2, exact: true },
      { id: "fBand", label: "Any age", options: [...Object.entries(M.BANDS).map(([value, label]) => ({ value, label, color: "primary" })), { value: "none", label: "No date of birth", color: "secondary" }], columnIndex: 6, exact: true },
      { id: "fBaptised", label: "Baptised or not", options: [{ value: "yes", label: "Baptised", color: "purple" }, { value: "no", label: "Not yet baptised", color: "secondary" }], columnIndex: 5, exact: true },
      { id: "fYear", label: "Any year joined", options: years.map((y) => ({ value: y, label: `Joined ${y}`, color: "success" })), columnIndex: 7, exact: true },
    ];
    UI.renderFilterToolbar("memberFilters", { searchPlaceholder: "Search by name or phone...", filters });
    return filters;
  }

  async function loadList() {
    $("memberRows").innerHTML = UI.renderTableLoading(8);
    const res = await MembersAPI.list({ archived: list === "archived" ? 1 : "" });
    if (!res.ok) {
      $("memberTableWrap").innerHTML = M.errorBox(res.message);
      return;
    }
    const items = res.data.items;
    const filters = toolbar(items);
    if (!items.length) {
      table?.destroy?.();
      table = null;
      $("memberFilters").hidden = true;
      $("memberRows").innerHTML = `<tr><td colspan="8">${
        list === "archived"
          ? M.empty("ri-archive-line", "No one archived", "People you archive leave the lists but stay counted. You can bring them back.")
          : M.empty("ri-contacts-book-2-line", "No members yet", "Add your members one by one - only your church's leaders will ever see their names.", CTX.can.manage ? `<a class="btn btn-primary" href="${CTX.baseUrl}/new"><i class="ri-user-add-line me-1"></i>Add your first member</a>` : "")
      }</td></tr>`;
      return;
    }
    $("memberFilters").hidden = false;
    $("memberRows").innerHTML = items.map(rowHtml).join("");
    table = UI.initListDataTable("memberTable", { hideDefaultSearch: true, order: [[0, "asc"]], pageLength: 25 });
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
