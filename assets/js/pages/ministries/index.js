/**
 * ============================================================================
 * MINISTRIES - the page (index.php)
 * ============================================================================
 * Four crisp cards (ministries, people serving, leaders, gatherings this
 * month), then a card per ministry - its icon, when it meets, members,
 * average attendance from its gathering, the next meeting and its leaders.
 * Those who manage ministries can add one, and see the switched-off ones.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const N = MinistriesUI;
  const K = PeopleKit;
  const CTX = window.MIN_CTX;
  const $ = (id) => document.getElementById(id);
  let show = new URLSearchParams(window.location.search).get("show") === "all" && CTX.can.manage ? "all" : "active";

  function cards(o) {
    const pct = o.members_total ? Math.round((o.serving / o.members_total) * 100) : 0;
    K.statRow($("statCardsRow"), [
      { icon: "ri-team-line", label: "Ministries", sub: o.with_leader === o.ministries ? "Each one has a leader" : `${N.num(o.ministries - o.with_leader)} without a leader`, value: N.num(o.ministries), color: "primary" },
      { icon: "ri-group-line", label: "People serving", sub: `Of ${N.num(o.members_total)} members`, value: N.num(o.serving), color: "success", bar: { pct, text: `${pct}% serve` } },
      { icon: "ri-user-star-line", label: "Leaders", sub: "Leaders, assistants and secretaries", value: N.num(o.leaders), color: "purple" },
      { icon: "ri-bar-chart-box-line", label: "Gatherings this month", sub: `${N.num(o.gatherings_last_month)} last month`, value: N.num(o.gatherings_this_month), color: "pink", delta: UI.periodDelta(o.gatherings_this_month, o.gatherings_last_month), series: { labels: o.months, data: o.gatherings_series } },
    ]);
  }

  function grid(o) {
    $("gridSub").textContent = show === "all" ? "Every ministry, with the ones switched off" : "Who leads each one, who serves, and how its gatherings are going";
    if (!o.items.length) {
      $("minGrid").innerHTML = `<div class="col-12">${MembersUI.empty("ri-team-line", "No ministries running", "Add one, or switch one back on.", CTX.can.manage ? '<button type="button" class="btn btn-primary" data-first><i class="ri-add-line me-1"></i>Add ministry</button>' : "")}</div>`;
      return;
    }
    $("minGrid").innerHTML = o.items.map((m) => N.card(m, { baseUrl: CTX.baseUrl, months: o.months })).join("");
    UI.mountSparklines($("minGrid"));
  }

  async function load() {
    const res = await MinistriesAPI.overview(show === "all");
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      $("minGrid").innerHTML = "";
      return;
    }
    cards(res.data);
    grid(res.data);
  }

  function add() {
    N.ministryWindow({ onDone: (m) => (window.location.href = `${CTX.baseUrl}/ministry?id=${m.id}`) });
  }

  function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("addBtn")?.addEventListener("click", add);
    $("minGrid").addEventListener("click", (e) => e.target.closest("[data-first]") && add());
    const btns = [...document.querySelectorAll("#gridSwitch [data-show]")];
    const paint = () =>
      btns.forEach((b) => {
        b.classList.toggle("btn-primary", b.dataset.show === show);
        b.classList.toggle("btn-outline-primary", b.dataset.show !== show);
      });
    paint();
    btns.forEach((b) =>
      b.addEventListener("click", () => {
        if (b.dataset.show === show) return;
        show = b.dataset.show;
        paint();
        const q = new URLSearchParams(window.location.search);
        show === "all" ? q.set("show", "all") : q.delete("show");
        history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
        load();
      }),
    );
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
