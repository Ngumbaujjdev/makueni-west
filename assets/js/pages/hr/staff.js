/**
 * ============================================================================
 * STAFF - the people a place employs (hr/, every level)
 * ============================================================================
 * docs/specs/hr-spec.md: our staff - who they are (a member, a login, or a
 * name), their job, grade and pay, how they are paid, where they have served
 * and their papers - and, for a region or the diocese, the staff of the
 * places below, with moves between places. Payroll pays from these records.
 * ID numbers and KRA PINs only ever come masked.
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
  let opts = null;
  let tab = new URLSearchParams(window.location.search).get("tab") === "below" && CTX.can.below ? "below" : "ours";

  const ST = {
    active: ["success", "ri-user-follow-line", "Working"],
    starting: ["primary", "ri-calendar-event-line", "Starting"],
    off: ["secondary", "ri-pause-circle-line", "Off the payroll"],
    left: ["secondary", "ri-user-unfollow-line", "Left"],
  };
  const TYPE_COLOR = { full_time: "primary", part_time: "info", contract: "warning", casual: "purple" };
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const typeChip = (e) => `<span class="soft-chip soft-${TYPE_COLOR[e.employment_type] || "secondary"}">${esc(e.employment_type_label)}</span>`;
  const job = (e) => `${esc(e.position || "No position")}${e.grade ? ` <span class="acc-sub">· ${esc(e.grade.code)}</span>` : ""}`;
  const paidBy = (e) => `${A.methodChip({ paybill: "mpesa", till: "mpesa" }[e.pay_method] || e.pay_method, e.pay_method_label)}<div class="acc-sub mt-1">${esc(e.pay_to || "")}</div>`;
  const pay = (e) => `<strong>${A.money(e.gross)}</strong>${e.allowances.length ? `<div class="acc-sub">basic ${A.money(e.basic_pay)} + ${e.allowances.length} ${e.allowances.length === 1 ? "allowance" : "allowances"}</div>` : ""}`;
  const linked = (e) => (e.person ? '<span class="soft-chip soft-success" title="From the member register"><i class="ri-contacts-book-2-line"></i>Member</span>' : e.user ? '<span class="soft-chip soft-primary" title="Has a login"><i class="ri-user-settings-line"></i>Login</span>' : "");

  // ------------------------------------------------------------ the page

  function cards(here, ours) {
    const working = ours.filter((e) => e.status === "active" || e.status === "starting");
    K.statRow($("statCardsRow"), [
      { icon: "ri-team-line", label: "On the staff", sub: `${here.by_type.full_time || 0} full time · ${(here.by_type.part_time || 0) + (here.by_type.casual || 0)} part time or casual`, value: A.num(here.staff), color: "primary" },
      { icon: "ri-money-dollar-box-line", label: "Pay a month", sub: "Basic and allowances, before any deduction", value: A.figure(here.monthly_pay), color: "success" },
      { icon: "ri-contacts-book-2-line", label: "From the register", sub: "Linked to their member record or login", value: A.num(working.filter((e) => e.person || e.user).length), color: "purple" },
      { icon: "ri-file-warning-line", label: "Contracts ending", sub: "In the next 60 days", value: A.num(here.contracts_ending), color: here.contracts_ending ? "warning" : "info" },
    ]);
    $("hrOursFigure").textContent = `${here.staff} working`;
  }

  function table(id, items, withPlace) {
    const types = Object.keys(TYPE_COLOR).filter((k) => items.some((e) => e.employment_type === k && e.status !== "left"));
    A.tableKit({
      tableId: id,
      prefix: withPlace ? "b_" : "",
      items,
      noun: "people",
      search: withPlace ? "Search name, job, place..." : "Search name, job, phone...",
      pills: [
        { key: "working", label: "Working", icon: "ri-user-follow-line", color: "success", test: (e) => e.status !== "left" },
        ...types.map((k) => ({ key: k, label: items.find((e) => e.employment_type === k).employment_type_label, icon: "ri-time-line", color: TYPE_COLOR[k], test: (e) => e.employment_type === k && e.status !== "left" })),
        { key: "left", label: "Left", icon: "ri-user-unfollow-line", color: "secondary", test: (e) => e.status === "left" },
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[0, "asc"]] },
        { key: "big", label: "Highest pay first", order: [[3, "desc"]] },
        ...(withPlace ? [{ key: "place", label: "By place", order: [[1, "asc"]] }] : []),
      ],
      empty: withPlace
        ? A.empty("ri-community-line", "No staff in the places below yet", "As churches add the people they employ, they show here.")
        : A.empty("ri-team-line", "Nobody on the staff yet", "Add the people this place employs - a pastor, a secretary, a caretaker.", CTX.can.manage ? '<button type="button" class="btn btn-primary" data-first><i class="ri-user-add-line me-1"></i>Add a person</button>' : ""),
      rowHtml: (e) => `<tr class="acc-row${e.status === "left" ? " opacity-75" : ""}" data-id="${e.id}" data-pills="${e.status === "left" ? "left" : `working ${e.employment_type}`}">
          <td data-order="${esc(e.name)}"><div class="d-flex align-items-center gap-2">${A.avatar(e.name, "sm")}<div class="min-w-0"><div><a class="fw-semibold mb-link" href="${personUrl(e.id)}">${esc(e.name)}</a> ${linked(e)}</div><div class="acc-sub">${esc(e.phone || "")}${e.start_date ? `${e.phone ? " · " : ""}since ${A.day(e.start_date)}` : ""}</div></div></div></td>
          ${withPlace ? `<td data-order="${esc(e.place.name)}">${esc(e.place.name)}</td>` : ""}
          <td class="d-none d-md-table-cell">${job(e)}<div class="mt-1">${typeChip(e)}</div></td>
          ${withPlace ? "" : `<td class="d-none d-lg-table-cell">${paidBy(e)}</td>`}
          <td class="text-end" data-order="${e.gross}">${pay(e)}</td>
          <td>${pill(e.status)}${e.contract_end && e.status !== "left" ? `<div class="acc-sub mt-1">Contract to ${A.day(e.contract_end)}</div>` : ""}</td>
        </tr>`,
    });
  }

  function showTab() {
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("hrOursPane").hidden = tab !== "ours";
    if ($("hrBelowPane")) $("hrBelowPane").hidden = tab !== "below";
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("hrOursRows").innerHTML = UI.renderTableLoading(5);
    const [ov, ours, below, o] = await Promise.all([API.overview(), API.staff({ status: "all", per: 200 }), CTX.can.below ? API.staff({ status: "all", per: 200, below: 1 }) : null, opts ? { ok: true, data: opts } : API.options()]);
    if (!ov.ok || !ours.ok) {
      $("hrOursWrap").innerHTML = A.errorBox((ov.ok ? ours : ov).message);
      return;
    }
    opts = o.ok ? o.data : null;
    if (opts) HrWindows.setup(opts, load);
    cards(ov.data.here, ours.data.rows);
    table("hrOursTable", ours.data.rows, false);
    if (below?.ok) {
      const rows = below.data.rows.filter((e) => e.place.id !== ov.data.place.id);
      table("hrBelowTable", rows, true);
      const places = ov.data.below.length;
      $("hrBelowFigure").textContent = `${rows.filter((e) => e.status !== "left").length} in ${places} ${places === 1 ? "place" : "places"}`;
    }
    showTab();
  }

  /** A person's own page, keeping the place being viewed. */
  const personUrl = (id) => {
    const t = new URLSearchParams(window.location.search).get("territory_id");
    return `${CTX.baseUrl}/person.php?id=${id}${t ? `&territory_id=${t}` : ""}`;
  };

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", () => {
    $("addBtn")?.addEventListener("click", () => HrWindows.person());
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) =>
      b.addEventListener("click", () => {
        tab = b.dataset.tab;
        const p = new URLSearchParams(window.location.search);
        tab === "ours" ? p.delete("tab") : p.set("tab", tab);
        history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
        showTab();
      }),
    );
    document.addEventListener("click", (ev) => {
      if (ev.target.closest("[data-first]")) return HrWindows.person();
      const row = ev.target.closest("#hrOursTable tr[data-id], #hrBelowTable tr[data-id]");
      if (row && !ev.target.closest("a, button")) window.location.href = personUrl(row.dataset.id);
    });
    load();
  });
})();
