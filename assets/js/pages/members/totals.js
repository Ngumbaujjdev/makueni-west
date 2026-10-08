/**
 * ============================================================================
 * MEMBERS - the region's and diocese's totals (people/members.php)
 * ============================================================================
 * Counts per church from their private registers - never a name. Cards for
 * the whole place, then a row per church below.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const $ = (id) => document.getElementById(id);

  function render(t) {
    const cards = [
      { icon: "ri-contacts-book-2-line", label: "Keeping a register", value: `${M.num(t.keeping_register)} of ${M.num(t.churches)}`, color: "primary", sub: "Churches with members recorded" },
      { icon: "ri-group-line", label: "Active members", value: M.num(t.active), color: "success", sub: `${M.num(t.new_this_month)} new this month` },
      { icon: "ri-drop-line", label: "Baptised", value: M.num(t.baptised), color: "purple", sub: t.active ? `${Math.round((t.baptised / t.active) * 100)}% of members` : "Of members" },
      { icon: "ri-arrow-left-right-line", label: "Transfers this year", value: `${M.num(t.transfers_in)} in · ${M.num(t.transfers_out)} out`, color: "secondary", sub: "Between churches" },
    ];
    $("statCardsRow").innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");

    if (!t.rows.length) {
      $("totalFilters").hidden = true;
      $("totalRows").innerHTML = `<tr><td colspan="7">${M.empty("ri-community-line", "No churches below", "Churches under this place show here.")}</td></tr>`;
      return;
    }
    $("totalRows").innerHTML = t.rows
      .map(
        (r) => `<tr>
          <td class="fw-semibold">${M.esc(r.church.name)}</td>
          <td data-search="${r.keeps_register ? "yes" : "no"}">${r.keeps_register ? '<span class="badge bg-success">Keeping one</span>' : '<span class="soft-chip soft-warning">Not yet</span>'}</td>
          <td data-order="${r.active}">${M.num(r.active)}</td>
          <td data-order="${r.new_this_month}">${M.num(r.new_this_month)}</td>
          <td data-order="${r.baptised}">${M.num(r.baptised)}</td>
          <td data-order="${r.transfers_in}">${M.num(r.transfers_in)}</td>
          <td data-order="${r.transfers_out}">${M.num(r.transfers_out)}</td>
        </tr>`,
      )
      .join("");
    const filters = [{ id: "fReg", label: "Register or not", options: [{ value: "yes", label: "Keeping one", color: "success" }, { value: "no", label: "Not yet", color: "warning" }], columnIndex: 1, exact: true }];
    UI.renderFilterToolbar("totalFilters", { searchPlaceholder: "Search churches...", filters });
    const table = UI.initListDataTable("totalTable", { hideDefaultSearch: true, order: [[2, "desc"]], pageLength: 25 });
    UI.enhanceSelect($("fReg"), { search: false });
    UI.wireFilterToolbar("totalFilters", table, filters, { noun: "churches" });
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-lg-6 col-md-6");
    $("totalRows").innerHTML = UI.renderTableLoading(7);
    const res = await MembersAPI.totals();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      $("totalTableWrap").innerHTML = "";
      return;
    }
    render(res.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
