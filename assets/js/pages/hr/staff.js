/**
 * ============================================================================
 * STAFF - the people a place employs (hr/, every level)
 * ============================================================================
 * docs/specs/hr-spec.md: one directory - ours and, for a region or the
 * diocese, the places below - as a list or as cards, with filters (status,
 * type, position, place, how paid), sorting, pages, and ticks to download or
 * message. Each person opens their own page. Pay is private.
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
  let items = [];
  let kit = null;
  let view = new URLSearchParams(window.location.search).get("view") === "cards" ? "cards" : "list";
  const byId = new Map();

  const ST = {
    active: ["success", "ri-user-follow-line", "Working"],
    starting: ["primary", "ri-calendar-event-line", "Starting"],
    off: ["secondary", "ri-pause-circle-line", "Off payroll"],
    left: ["secondary", "ri-user-unfollow-line", "Left"],
  };
  const TYPE_COLOR = { full_time: "primary", part_time: "info", contract: "warning", casual: "purple" };
  const PAID = { mpesa: "M-Pesa", airtel: "Airtel Money", paybill: "Paybill", till: "Till", bank: "Bank", cash: "Cash" };
  const pill = (s) => `<span class="badge bg-${ST[s][0]} ${A.textOn(ST[s][0])}"><i class="${ST[s][1]} me-1"></i>${ST[s][2]}</span>`;
  const typeChip = (e) => `<span class="soft-chip soft-${TYPE_COLOR[e.employment_type] || "secondary"}">${esc(e.employment_type_label)}</span>`;
  const slug = (s) => String(s || "").toLowerCase().replace(/[^a-z0-9]+/g, "-") || "none";

  /** A person's own page, keeping the place being viewed. */
  const personUrl = (id) => {
    const t = new URLSearchParams(window.location.search).get("territory_id");
    return `${CTX.baseUrl}/person.php?id=${id}${t ? `&territory_id=${t}` : ""}`;
  };

  // ------------------------------------------------------------ the page

  function cards(here, below) {
    const people = below.reduce((t, p) => t + p.staff, 0);
    K.statRow($("statCardsRow"), [
      { icon: "ri-team-line", label: "Staff", sub: `${here.by_type.full_time || 0} full time · ${(here.by_type.part_time || 0) + (here.by_type.casual || 0) + (here.by_type.contract || 0)} other`, value: A.num(here.staff), color: "primary" },
      { icon: "ri-money-dollar-box-line", label: "Monthly pay", sub: "Basic and allowances", value: A.figure(here.monthly_pay), color: "success" },
      CTX.can.below
        ? { icon: "ri-community-line", label: "In the places below", sub: `${below.length} ${below.length === 1 ? "place" : "places"}`, value: A.num(people), color: "purple" }
        : { icon: "ri-contacts-book-2-line", label: "Linked", sub: "To a member or a login", value: A.num(items.filter((e) => e.status !== "left" && (e.person || e.user)).length), color: "purple" },
      { icon: "ri-file-warning-line", label: "Contracts ending", sub: "Next 60 days", value: A.num(here.contracts_ending), color: "warning" },
    ]);
  }

  function rowHtml(e) {
    const href = personUrl(e.id);
    return `<tr class="mb-row" data-id="${e.id}" data-href="${href}" data-pills="${e.status === "left" ? "left" : `working ${e.employment_type}`}" data-f-position="${slug(e.position)}" data-f-place="${e.place.id}" data-f-paid="${e.pay_method}">
      ${K.checkCell(e.id, e.name)}
      <td data-search="${esc(`${e.name} ${e.phone || ""} ${e.position || ""} ${e.place.name}`)}" data-order="${esc(e.name.toLowerCase())}">
        <div class="d-flex align-items-center gap-2">${A.avatar(e.name, "sm")}<div class="min-w-0"><a class="fw-semibold mb-link" href="${href}">${esc(e.name)}</a><div class="mb-sub">${e.person ? '<i class="ri-contacts-book-2-line me-1"></i>Member' : e.user ? '<i class="ri-user-settings-line me-1"></i>Has a login' : esc(e.email || "")}</div></div></div>
      </td>
      <td data-order="${esc((e.position || "~").toLowerCase())}"><div class="fw-semibold">${esc(e.position || "No position")}</div><div class="d-flex flex-wrap gap-1 mt-1">${typeChip(e)}${e.grade ? `<span class="soft-chip soft-secondary">${esc(e.grade.code)}</span>` : ""}</div></td>
      ${CTX.can.below ? `<td data-order="${esc(e.place.name)}">${e.place.id === CTX.place.id ? '<span class="soft-chip soft-success"><i class="ri-home-4-line"></i>Ours</span>' : esc(e.place.name)}</td>` : ""}
      <td class="d-none d-lg-table-cell">${e.phone ? `<i class="ri-phone-line me-1 text-primary"></i>${esc(e.phone)}` : '<span class="mb-sub">-</span>'}</td>
      <td class="text-end" data-order="${e.gross}"><strong>${A.money(e.gross)}</strong><div class="mb-sub">${A.methodChip({ paybill: "mpesa", till: "mpesa" }[e.pay_method] || e.pay_method, PAID[e.pay_method])}</div></td>
      <td data-order="${e.start_date || ""}">${pill(e.status)}</td>
      <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
    </tr>`;
  }

  /** The cards view: the people on the current page of the list, as cards. */
  function drawCards() {
    if (view !== "cards" || !kit?.table) return;
    const ids = kit.table.rows({ page: "current", search: "applied" }).nodes().toArray().map((tr) => Number(tr.dataset.id));
    $("hrCards").innerHTML = ids.length
      ? ids
          .map((id) => byId.get(id))
          .map(
            (e) => `<div class="col-xxl-3 col-xl-4 col-sm-6"><div class="card custom-card border h-100 mb-0"><div class="card-body text-center">
              <a href="${personUrl(e.id)}" class="d-inline-block mb-2">${A.avatar(e.name, "xl")}</a>
              <div><a class="fw-semibold fs-15 mb-link" href="${personUrl(e.id)}">${esc(e.name)}</a></div>
              <div class="mb-sub mb-2">${esc(e.position || "No position")}${CTX.can.below && e.place.id !== CTX.place.id ? ` · ${esc(e.place.name)}` : ""}</div>
              <div class="d-flex justify-content-center flex-wrap gap-1 mb-3">${pill(e.status)}${typeChip(e)}</div>
              <div class="d-flex justify-content-between border-top pt-2"><span class="mb-sub">A month</span><strong>${A.money(e.gross)}</strong></div>
            </div></div></div>`,
          )
          .join("")
      : `<div class="col-12">${A.empty("ri-team-line", "Nobody matches", "Change the filters to see more people.")}</div>`;
  }

  function setView(v) {
    view = v;
    document.querySelectorAll("[data-view]").forEach((b) => {
      b.classList.toggle("btn-primary", b.dataset.view === v);
      b.classList.toggle("btn-outline-primary", b.dataset.view !== v);
    });
    $("hrCards").hidden = v !== "cards";
    $("hrTable").querySelectorAll("thead, tbody").forEach((x) => (x.hidden = v === "cards"));
    const p = new URLSearchParams(window.location.search);
    v === "cards" ? p.set("view", "cards") : p.delete("view");
    history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
    drawCards();
  }

  function downloadCsv(ids) {
    const rows = [["Name", "Position", "Grade", "Type", "Place", "Phone", "Basic pay", "Allowances", "A month", "Paid by", "Pay to", "Status", "Started"]];
    ids.map((id) => byId.get(id)).forEach((e) =>
      rows.push([e.name, e.position || "", e.grade?.code || "", e.employment_type_label, e.place.name, e.phone || "", e.basic_pay, e.allowances.map((a) => `${a.name} ${a.amount}`).join("; "), e.gross, PAID[e.pay_method] || e.pay_method, e.pay_to || "", ST[e.status][2], e.start_date || ""]),
    );
    const csv = rows.map((r) => r.map((c) => `"${String(c ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
    a.download = `staff-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
  }

  function table() {
    $("hrCount").textContent = items.filter((e) => e.status !== "left").length;
    if (!items.length) {
      $("hrPills").innerHTML = "";
      $("hrFilters").innerHTML = "";
      $("hrRows").innerHTML = `<tr><td colspan="8">${A.empty("ri-team-line", "Nobody on the staff yet", "Add the people this place employs.", CTX.can.manage ? '<button type="button" class="btn btn-primary" data-first><i class="ri-user-add-line me-1"></i>Add a person</button>' : "")}</td></tr>`;
      return;
    }
    const distinct = (f) => [...new Map(items.map((e) => [f(e)[0], f(e)])).values()].sort((a, b) => a[1].localeCompare(b[1]));
    const placeCol = CTX.can.below ? 1 : 0;
    const types = Object.keys(TYPE_COLOR).filter((k) => items.some((e) => e.employment_type === k && e.status !== "left"));
    kit = K.listTable({
      tableId: "hrTable",
      stripId: "hrFilters",
      pillsId: "hrPills",
      rowsId: "hrRows",
      items,
      rowHtml,
      noun: "people",
      searchPlaceholder: "Search name, job, phone...",
      defaultPill: "working",
      pills: [
        { key: "working", label: "Working", icon: "ri-user-follow-line", color: "success", test: (e) => e.status !== "left" },
        ...types.map((k) => ({ key: k, label: items.find((e) => e.employment_type === k).employment_type_label, icon: "ri-time-line", color: TYPE_COLOR[k], test: (e) => e.employment_type === k && e.status !== "left" })),
        { key: "left", label: "Left", icon: "ri-user-unfollow-line", color: "secondary", test: (e) => e.status === "left" },
      ],
      selects: [
        { key: "position", label: "Every position", options: distinct((e) => [slug(e.position), e.position || "No position"]).map(([value, label]) => ({ value, label, icon: "ri-briefcase-4-line", color: "primary" })) },
        ...(CTX.can.below ? [{ key: "place", label: "Every place", options: distinct((e) => [String(e.place.id), e.place.id === CTX.place.id ? `${e.place.name} (ours)` : e.place.name]).map(([value, label]) => ({ value, label, icon: "ri-map-pin-line", color: "purple" })) }] : []),
        { key: "paid", label: "Any way of paying", options: distinct((e) => [e.pay_method, PAID[e.pay_method] || e.pay_method]).map(([value, label]) => ({ value, label, icon: "ri-bank-card-line", color: "success" })) },
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "pay", label: "Highest pay", order: [[4 + placeCol, "desc"]] },
        { key: "newest", label: "Newest first", order: [[5 + placeCol, "desc"]] },
        ...(CTX.can.below ? [{ key: "place", label: "By place", order: [[3, "asc"], [1, "asc"]] }] : []),
      ],
      nonSortable: [6 + placeCol],
      actions: [
        { key: "csv", label: "Download", icon: "ri-download-2-line", primary: true, run: downloadCsv },
        {
          key: "sms",
          label: "Message",
          icon: "ri-chat-3-line",
          run: (ids) => {
            const people = ids.map((id) => byId.get(id)).filter((e) => e.person).map((e) => ({ id: e.person.id, name: e.name }));
            if (!people.length) return Toast.error("Only people linked to the member register can be messaged from here.");
            K.messagePeople(`${CTX.siteUrl}/${CTX.level}/messages/new`, people);
          },
        },
      ],
      onDraw: drawCards,
    });
    setView(view);
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("hrRows").innerHTML = UI.renderTableLoading(8);
    const [ov, ours, below, o] = await Promise.all([API.overview(), API.staff({ status: "all", per: 200 }), CTX.can.below ? API.staff({ status: "all", per: 200, below: 1 }) : null, opts ? { ok: true, data: opts } : API.options()]);
    if (!ov.ok || !ours.ok) {
      $("hrWrap").innerHTML = A.errorBox((ov.ok ? ours : ov).message);
      return;
    }
    opts = o.ok ? o.data : null;
    if (opts) HrWindows.setup(opts, () => { HrWindows.fresh("hrTable"); load(); });
    items = below?.ok ? below.data.rows : ours.data.rows;
    byId.clear();
    items.forEach((e) => byId.set(e.id, e));
    cards(ov.data.here, ov.data.below);
    table();
  }

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", () => {
    $("addBtn")?.addEventListener("click", () => HrWindows.person());
    document.querySelectorAll("[data-view]").forEach((b) => b.addEventListener("click", () => setView(b.dataset.view)));
    document.addEventListener("click", (ev) => {
      if (ev.target.closest("[data-first]")) return HrWindows.person();
      const row = ev.target.closest("#hrTable tr[data-href]");
      if (row && !ev.target.closest("a, button, input, label")) window.location.href = row.dataset.href;
    });
    load();
  });
})();
