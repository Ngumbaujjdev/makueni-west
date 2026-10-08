/**
 * ============================================================================
 * MEMBERS - the list (the church's private register)
 * ============================================================================
 * Four crisp cards (active, new this month, Sunday school, leaving this
 * year), then the register: pills with counts (main church, Sunday school,
 * inactive, transferred out), a search (name, phone or area), a Ministry
 * menu and a Sort menu, a tick box per row and a floating "N selected" bar -
 * Send message, Add to ministry, Mark inactive, Archive. All in place and
 * kept in the URL. "Archived" shows the people taken off the lists.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const K = PeopleKit;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  let list = params.get("list") === "archived" ? "archived" : "current";
  let kit = null;
  let byId = new Map();

  // -------------------------------------------------------------- the cards
  function renderCards(o) {
    const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
    K.statRow($("statCardsRow"), [
      { icon: "ri-group-line", label: "Active members", sub: o.archived ? `${M.num(o.archived)} archived` : "Everyone listed as a member", value: M.num(o.active), color: "primary", series: { labels: o.months, data: o.active_series } },
      { icon: "ri-user-add-line", label: "New this month", sub: `${M.num(o.new_last_month)} last month`, value: M.num(o.new_this_month), color: "success", delta: UI.periodDelta(o.new_this_month, o.new_last_month), series: { labels: o.months, data: o.joins } },
      { icon: "ri-book-open-line", label: "Sunday school", sub: `${M.num(o.baptised)} members baptised`, value: M.num(o.sunday_school), color: "pink", bar: { pct: pct(o.sunday_school, o.active), text: `${pct(o.sunday_school, o.active)}% of members` } },
      { icon: "ri-logout-box-r-line", label: "Leaving this year", sub: "Transfers out, inactive and passed on", value: M.num(o.leaving_this_year), color: "warning", series: { labels: o.months, data: o.leaves } },
    ]);
  }

  // -------------------------------------------------------------- the table
  function rowHtml(p) {
    const href = `${CTX.baseUrl}/member?id=${p.id}`;
    const ministries = p.ministries || [];
    return `<tr class="mb-row" data-id="${p.id}" data-href="${href}" data-pills="${[p.congregation || "", p.status].join(" ")}" data-f-ministry="${ministries.length ? ministries.map((m) => m.id).join(" ") : "none"}">
      ${K.checkCell(p.id, p.name)}
      <td data-search="${M.esc(`${p.name} ${p.phone || ""} ${p.area || ""}`)}" data-order="${M.esc(p.name.toLowerCase())}">
        <div class="d-flex align-items-center gap-2">
          ${M.avatar(p, "sm")}
          <div class="min-w-0"><a class="fw-semibold mb-link" href="${href}">${M.esc(p.name)}</a><div class="mb-sub">${M.esc(p.phone || "No phone")}</div></div>
        </div>
      </td>
      <td data-order="${M.esc((p.area || "~").toLowerCase())}">${p.area ? M.esc(p.area) : '<span class="mb-sub">Not given</span>'}</td>
      <td>${p.congregation ? M.groupChip(p.congregation) : '<span class="mb-sub">Not set</span>'}${ministries.length ? `<div class="mb-sub mt-1">${ministries.map((m) => M.esc(m.name)).join(" · ")}</div>` : ""}</td>
      <td class="d-none d-md-table-cell">${p.gender ? (p.gender === "male" ? "Male" : "Female") : "-"}</td>
      <td>${M.statusPill(p.status)}</td>
      <td class="d-none d-lg-table-cell" data-order="${p.joined_on || ""}">${M.day(p.joined_on)}</td>
      <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
    </tr>`;
  }

  function actions() {
    const send = { key: "sms", label: "Send message", icon: "ri-chat-3-line", primary: true, run: (ids) => K.messagePeople(CTX.messagesUrl, ids.map((id) => byId.get(id)).filter(Boolean)) };
    const toMinistry = { key: "ministry", label: "Add to ministry", icon: "ri-team-line", run: (ids) => MinistriesUI.addToMinistryWindow(ids, { onDone: () => load() }) };
    if (list === "archived") return [send];
    if (!CTX.can.manage) return CTX.can.ministries_manage ? [send, toMinistry] : [send];
    const change = (action, title, icon, danger, text) => ({
      key: action,
      label: title,
      icon,
      run: (ids) =>
        K.confirmWindow({
          title,
          subtitle: `${ids.length} ${ids.length === 1 ? "member" : "members"} picked`,
          icon,
          danger,
          go: `<i class="${icon} me-1"></i>${title}`,
          body: K.parts([{ icon, title: "What happens", body: `<p class="mb-0">${text}</p>` }]),
          run: async () => {
            const res = await MembersAPI.bulk(ids, action);
            if (res.ok) load();
            return res;
          },
        }),
    });
    return [
      send,
      ...(CTX.can.ministries_manage ? [toMinistry] : []),
      change("inactive", "Mark inactive", "ri-user-unfollow-line", false, "They stay in the register as inactive - you can change it back on each person's page."),
      change("archive", "Archive", "ri-archive-line", true, "They leave the lists but stay counted. You can bring each one back from Archived."),
    ];
  }

  /** The Ministry menu: each ministry these people serve in, and "Not in a ministry". */
  function ministrySelect(items) {
    const seen = new Map();
    items.forEach((p) => (p.ministries || []).forEach((m) => seen.set(m.id, m)));
    return {
      key: "ministry",
      label: "Any ministry",
      options: [...[...seen.values()].sort((a, b) => a.name.localeCompare(b.name)).map((m) => ({ value: String(m.id), label: m.name, icon: m.icon, color: m.colour })), { value: "none", label: "Not in a ministry", icon: "ri-user-add-line", color: "warning" }],
    };
  }

  async function load() {
    kit?.destroy();
    $("memberRows").innerHTML = UI.renderTableLoading(8);
    const res = await MembersAPI.list({ archived: list === "archived" ? 1 : "" });
    if (!res.ok) {
      $("memberTableWrap").innerHTML = M.errorBox(res.message);
      return;
    }
    const items = res.data.items;
    byId = new Map(items.map((p) => [p.id, p]));
    if (!items.length) {
      $("memberFilters").innerHTML = "";
      $("memberPills").innerHTML = "";
      $("memberRows").innerHTML = `<tr><td colspan="8">${
        list === "archived"
          ? M.empty("ri-archive-line", "No one archived", "People you archive leave the lists but stay counted. You can bring them back.")
          : M.empty("ri-contacts-book-2-line", "No members yet", "Add your members one by one - only your church's leaders will ever see their names.", CTX.can.manage ? `<a class="btn btn-primary" href="${CTX.baseUrl}/new"><i class="ri-user-add-line me-1"></i>Add your first member</a>` : "")
      }</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "memberTable",
      stripId: "memberFilters",
      pillsId: "memberPills",
      rowsId: "memberRows",
      items,
      rowHtml,
      noun: list === "archived" ? "archived" : "members",
      searchPlaceholder: "Search by name, phone or area...",
      pills: [
        { key: "main_church", label: "Main church", icon: "ri-community-line", color: "primary", test: (p) => p.congregation === "main_church" },
        { key: "sunday_school", label: "Sunday school", icon: "ri-book-open-line", color: "pink", test: (p) => p.congregation === "sunday_school" },
        { key: "inactive", label: "Inactive", icon: "ri-user-unfollow-line", color: "warning", test: (p) => p.status === "inactive" },
        { key: "transferred_out", label: "Transferred out", icon: "ri-logout-box-r-line", color: "purple", test: (p) => p.status === "transferred_out" },
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "newest", label: "Joined - newest", order: [[6, "desc"]] },
        { key: "oldest", label: "Joined - longest", order: [[6, "asc"]] },
        { key: "area", label: "Area", order: [[2, "asc"], [1, "asc"]] },
      ],
      nonSortable: [7],
      selects: CTX.can.ministries && list !== "archived" ? [ministrySelect(items)] : [],
      actions: actions(),
    });
  }

  function wireListSwitch() {
    const btns = [...document.querySelectorAll("#listSwitch [data-list]")];
    const paint = () =>
      btns.forEach((b) => {
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
        load();
      }),
    );
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    wireListSwitch();
    // A row opens the member (not when ticking it or following a link).
    $("memberRows").addEventListener("click", (e) => {
      if (e.target.closest("a, input, .pp-check")) return;
      const tr = e.target.closest("tr[data-href]");
      if (tr) window.location.href = tr.dataset.href;
    });
    const [o] = await Promise.all([MembersAPI.overview(), load()]);
    if (!o.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(o.message)}</div>`;
      return;
    }
    renderCards(o.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
