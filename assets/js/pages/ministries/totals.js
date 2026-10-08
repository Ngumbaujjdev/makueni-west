/**
 * ============================================================================
 * MINISTRIES - the region's and diocese's totals (people/ministries.php)
 * ============================================================================
 * Counts per church from their own ministries - never a name.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const $ = (id) => document.getElementById(id);
  const num = (n) => Number(n || 0).toLocaleString("en-GB");

  function render(t) {
    PeopleKit.statRow($("statCardsRow"), [
      { icon: "ri-team-line", label: "Churches set up", sub: "Churches that have opened Ministries", value: `${num(t.set_up)} of ${num(t.churches)}`, color: "primary" },
      { icon: "ri-apps-2-line", label: "Ministries", sub: "Running across our churches", value: num(t.ministries), color: "purple" },
      { icon: "ri-group-line", label: "People serving", sub: "In at least one ministry", value: num(t.serving), color: "success" },
      { icon: "ri-bar-chart-box-line", label: "Gatherings this month", sub: "Recorded in Attendance", value: num(t.gatherings_this_month), color: "pink" },
    ]);
    if (!t.rows.length) {
      $("totalRows").innerHTML = `<tr><td colspan="6">${MembersUI.empty("ri-community-line", "No churches below", "Churches under this place show here.")}</td></tr>`;
      return;
    }
    $("totalRows").innerHTML = t.rows
      .map(
        (r) => `<tr>
          <td class="fw-semibold">${MembersUI.esc(r.church.name)}</td>
          <td data-search="${r.ministries ? "yes" : "no"}">${r.ministries ? '<span class="badge bg-success">Set up</span>' : '<span class="soft-chip soft-warning">Not yet</span>'}</td>
          <td data-order="${r.ministries}">${num(r.ministries)}</td>
          <td data-order="${r.serving}">${num(r.serving)}</td>
          <td data-order="${r.gatherings_this_month}">${num(r.gatherings_this_month)}</td>
          <td data-order="${r.average_this_month ?? -1}">${r.average_this_month === null ? '<span class="mb-sub">-</span>' : num(r.average_this_month)}</td>
        </tr>`,
      )
      .join("");
    const filters = [{ id: "fSet", label: "Set up or not", options: [{ value: "yes", label: "Set up", color: "success" }, { value: "no", label: "Not yet", color: "warning" }], columnIndex: 1, exact: true }];
    UI.renderFilterToolbar("totalFilters", { searchPlaceholder: "Search churches...", filters });
    const table = UI.initListDataTable("totalTable", { hideDefaultSearch: true, order: [[3, "desc"]], pageLength: 25 });
    UI.enhanceSelect($("fSet"), { search: false });
    UI.wireFilterToolbar("totalFilters", table, filters, { noun: "churches" });
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("totalRows").innerHTML = UI.renderTableLoading(6);
    const res = await MinistriesAPI.totals();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      $("totalTableWrap").innerHTML = "";
      return;
    }
    render(res.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
