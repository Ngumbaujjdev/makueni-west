/**
 * ============================================================================
 * PASTORAL CARE - the region's and diocese's totals (people/care.php)
 * ============================================================================
 * Counts per church from their own pastoral care - never a name or a note.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const $ = (id) => document.getElementById(id);
  const num = (n) => Number(n || 0).toLocaleString("en-GB");

  function render(t) {
    PeopleKit.statRow($("statCardsRow"), [
      { icon: "ri-heart-pulse-line", label: "Recording care", sub: "Churches with pastoral care recorded", value: `${num(t.recording)} of ${num(t.churches)}`, color: "primary" },
      { icon: "ri-home-heart-line", label: "Visits this month", sub: `${num(t.visits_this_year)} this year`, value: num(t.visits_this_month), color: "success" },
      { icon: "ri-folder-open-line", label: "Open cases", sub: "Across our churches", value: num(t.open), color: "purple" },
      { icon: "ri-hospital-line", label: "In hospital now", sub: "Known to our churches", value: num(t.in_hospital), color: "danger" },
    ]);
    if (!t.rows.length) {
      $("totalRows").innerHTML = `<tr><td colspan="6">${MembersUI.empty("ri-community-line", "No churches below", "Churches under this place show here.")}</td></tr>`;
      return;
    }
    $("totalRows").innerHTML = t.rows
      .map(
        (r) => `<tr>
          <td class="fw-semibold">${MembersUI.esc(r.church.name)}</td>
          <td data-search="${r.recording ? "yes" : "no"}">${r.recording ? '<span class="badge bg-success">Recording</span>' : '<span class="soft-chip soft-warning">Not yet</span>'}</td>
          <td data-order="${r.visits_this_month}">${num(r.visits_this_month)}</td>
          <td data-order="${r.visits_this_year}">${num(r.visits_this_year)}</td>
          <td data-order="${r.open}">${num(r.open)}</td>
          <td data-order="${r.in_hospital}">${num(r.in_hospital)}</td>
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
    $("totalRows").innerHTML = UI.renderTableLoading(6);
    const res = await CareAPI.totals();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      $("totalTableWrap").innerHTML = "";
      return;
    }
    render(res.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
