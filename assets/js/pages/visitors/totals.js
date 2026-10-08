/**
 * ============================================================================
 * VISITORS - the region's and diocese's totals (people/visitors.php)
 * ============================================================================
 * Counts per church from their own visitor records - never a name. Cards
 * for the whole place, then a row per church below.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const $ = (id) => document.getElementById(id);

  function render(t) {
    const cards = [
      { icon: "ri-user-heart-line", label: "Recording visitors", value: `${M.num(t.recording)} of ${M.num(t.churches)}`, color: "primary", sub: "Churches with visitors recorded" },
      { icon: "ri-group-line", label: "Visitors this month", value: M.num(t.visitors_this_month), color: "pink", sub: `${M.num(t.first_timers_this_month)} first-timers` },
      { icon: "ri-star-smile-line", label: "First-timers this year", value: M.num(t.first_timers_this_year), color: "success", sub: "Across our churches" },
      { icon: "ri-home-heart-line", label: "Became members", value: M.num(t.became_members_this_year), color: "purple", sub: t.conversion === null ? "This year" : `${t.conversion}% of this year's first-timers` },
    ];
    PeopleKit.statRow($("statCardsRow"), cards);

    if (!t.rows.length) {
      $("totalFilters").hidden = true;
      $("totalRows").innerHTML = `<tr><td colspan="7">${M.empty("ri-community-line", "No churches below", "Churches under this place show here.")}</td></tr>`;
      return;
    }
    $("totalRows").innerHTML = t.rows
      .map(
        (r) => `<tr>
          <td class="fw-semibold">${M.esc(r.church.name)}</td>
          <td data-search="${r.records_visitors ? "yes" : "no"}">${r.records_visitors ? '<span class="badge bg-success">Recording</span>' : '<span class="soft-chip soft-warning">Not yet</span>'}</td>
          <td data-order="${r.visitors_this_month}">${M.num(r.visitors_this_month)}</td>
          <td data-order="${r.first_timers_this_month}">${M.num(r.first_timers_this_month)}</td>
          <td data-order="${r.first_timers_this_year}">${M.num(r.first_timers_this_year)}</td>
          <td data-order="${r.became_members_this_year}">${M.num(r.became_members_this_year)}</td>
          <td data-order="${r.conversion ?? -1}">${r.conversion === null ? '<span class="mb-sub">-</span>' : `${r.conversion}%`}</td>
        </tr>`,
      )
      .join("");
    const filters = [{ id: "fRec", label: "Recording or not", options: [{ value: "yes", label: "Recording", color: "success" }, { value: "no", label: "Not yet", color: "warning" }], columnIndex: 1, exact: true }];
    UI.renderFilterToolbar("totalFilters", { searchPlaceholder: "Search churches...", filters });
    const table = UI.initListDataTable("totalTable", { hideDefaultSearch: true, order: [[2, "desc"]], pageLength: 25 });
    UI.enhanceSelect($("fRec"), { search: false });
    UI.wireFilterToolbar("totalFilters", table, filters, { noun: "churches" });
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("totalRows").innerHTML = UI.renderTableLoading(7);
    const res = await VisitorsAPI.totals();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      $("totalTableWrap").innerHTML = "";
      return;
    }
    render(res.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
