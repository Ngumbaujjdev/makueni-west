/**
 * ============================================================================
 * EVENTS - the list (church, region, diocese)
 * ============================================================================
 * Year switch, four KPI cards, events by month, and three tabs: our events,
 * invitations from above, and the places below. Filters, search, tab and
 * year update in place and are kept in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const E = EventsUI;
  const CTX = window.EVENTS_CTX;
  const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  const params = new URLSearchParams(window.location.search);
  const thisYear = new Date().getFullYear();
  const state = {
    year: Number(params.get("year")) || thisYear,
    scope: ["own", "invited", "below"].includes(params.get("tab")) && (params.get("tab") !== "below" || CTX.can.below) ? params.get("tab") : "own",
    q: params.get("q") || "",
    status: params.get("status") || "",
    type: params.get("type") || "",
    items: [],
    overview: null,
  };
  let heroChart = null;
  let loadToken = 0;

  function syncUrl() {
    const q = new URLSearchParams(window.location.search);
    const set = (k, v, dflt = "") => (v && String(v) !== String(dflt) ? q.set(k, v) : q.delete(k));
    set("year", state.year, thisYear);
    set("tab", state.scope, "own");
    set("q", state.q);
    set("status", state.status);
    set("type", state.type);
    const qs = q.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
  }

  // -------------------------------------------------------------- figures
  function renderOverview(o) {
    const total = o.by_month.reduce((a, b) => a + b, 0);
    const cards = [
      { icon: "ri-calendar-event-line", label: "Coming up", value: E.num(o.upcoming), color: "primary", sub: "Ours and invitations, still ahead" },
      { icon: "ri-group-line", label: "People expected", value: E.num(o.expected), color: "purple", sub: "Registered for our events" },
      { icon: "ri-user-follow-line", label: "People who came", value: E.num(o.came), color: "success", sub: o.expected ? `${Math.round((o.came / o.expected) * 100)}% of those expected` : "Once events have happened" },
      { icon: "ri-hand-coin-line", label: "Money raised", value: E.money(o.raised), color: "secondary", sub: "Recorded against our events" },
    ];
    const row = document.getElementById("statCardsRow");
    row.innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
    UI.mountSparklines(row);

    const busiest = total ? MONTHS[o.by_month.indexOf(Math.max(...o.by_month))] : null;
    document.getElementById("heroChips").innerHTML = [
      `<span class="soft-chip soft-primary"><i class="ri-calendar-2-line"></i>${E.num(total)} ${total === 1 ? "event" : "events"} in ${o.year}</span>`,
      busiest ? `<span class="soft-chip soft-purple"><i class="ri-fire-line"></i>Busiest · ${busiest}</span>` : "",
    ].join("");
    const el = document.getElementById("heroChart");
    el.classList.remove("skel-chart");
    heroChart?.destroy();
    el.innerHTML = "";
    heroChart = total
      ? UI.renderTrendChart("heroChart", { categories: MONTHS, series: [{ name: "Events", data: o.by_month }], type: "bar", color: "primary" })
      : null;
    if (!total) {
      el.innerHTML = `<div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-primary text-white mb-2"><i class="ri-bar-chart-2-line fs-20"></i></span><h6 class="mb-1">No events of ours in ${o.year}</h6><p class="mb-0">Once you add events, this shows how they spread across the year.</p></div>`;
    }

    const figure = (k, text) => {
      const f = document.querySelector(`[data-tab-figure="${k}"]`);
      if (f) f.textContent = text;
    };
    figure("own", `${E.num(o.counts.own)} this year`);
    figure("invited", o.counts.invited_new ? `${E.num(o.counts.invited_new)} new` : `${E.num(o.counts.invited)} this year`);
    figure("below", `${E.num(o.counts.below)} this year`);
  }

  // -------------------------------------------------------------- the grid
  function filtersFor(items) {
    const types = [...new Map(items.map((i) => [i.type, i.type_label])).entries()].map(([value, label]) => ({ value, label, color: E.typeColor(value) }));
    return [
      { id: "evStatus", label: "Any status", options: Object.entries(E.STATUS).map(([value, s]) => ({ value, label: s.label, color: s.color })) },
      { id: "evType", label: "Any kind", options: types },
    ];
  }

  function renderToolbar() {
    UI.renderFilterToolbar("eventFilters", { searchPlaceholder: "Search events, places, venues...", filters: filtersFor(state.items) });
    const search = document.getElementById("eventFiltersSearch");
    const status = document.getElementById("evStatus");
    const type = document.getElementById("evType");
    search.value = state.q;
    status.value = state.status;
    type.value = [...type.options].some((o) => o.value === state.type) ? state.type : "";
    [status, type].forEach((s) => UI.enhanceSelect(s, { search: false }));
    search.addEventListener("input", () => {
      state.q = search.value.trim();
      applyFilters();
    });
    status.addEventListener("change", () => {
      state.status = status.value;
      applyFilters();
    });
    type.addEventListener("change", () => {
      state.type = type.value;
      applyFilters();
    });
    document.getElementById("eventFiltersClear").addEventListener("click", () => {
      state.q = state.status = state.type = "";
      search.value = "";
      status.value = type.value = "";
      UI.syncSelect(status);
      UI.syncSelect(type);
      applyFilters();
    });
  }

  function applyFilters() {
    const q = state.q.toLowerCase();
    let shown = 0;
    document.querySelectorAll("[data-ev-card]").forEach((c) => {
      const on = (!q || c.dataset.search.includes(q)) && (!state.status || c.dataset.status === state.status) && (!state.type || c.dataset.type === state.type);
      c.hidden = !on;
      if (on) shown++;
    });
    const filtered = !!(state.q || state.status || state.type);
    document.getElementById("eventFiltersCount").textContent = `${shown} of ${state.items.length} ${state.items.length === 1 ? "event" : "events"}`;
    document.getElementById("eventFiltersClear").classList.toggle("d-none", !filtered);
    const none = document.getElementById("evNoMatch");
    if (none) none.hidden = shown > 0 || !state.items.length;
    syncUrl();
  }

  function emptyFor(scope) {
    if (scope === "invited") return E.empty("ri-mail-open-line", "No invitations", `Nothing from the places above you in ${state.year} yet. When the region or diocese opens an event to you, it shows here.`);
    if (scope === "below") return E.empty("ri-community-line", "Nothing from the places below", `No published events from the places under you in ${state.year}.`);
    return E.empty(
      "ri-calendar-event-line",
      "No events yet",
      `Nothing planned for ${state.year}. Add one and decide who it's open to.`,
      CTX.can.manage ? `<a class="btn btn-primary" href="${CTX.baseUrl}/new"><i class="ri-add-line me-1"></i>New event</a>` : "",
    );
  }

  function renderGrid() {
    const grid = document.getElementById("eventsGrid");
    if (!state.items.length) {
      grid.innerHTML = emptyFor(state.scope);
      document.getElementById("eventFilters").hidden = true;
      return;
    }
    document.getElementById("eventFilters").hidden = false;
    // Coming up first (soonest), then the past (most recent first).
    const now = new Date();
    const ahead = state.items.filter((i) => new Date(i.ends_at) >= now);
    const past = state.items.filter((i) => new Date(i.ends_at) < now).reverse();
    const head = (label, n) => `<div class="col-12"><div class="ev-group-head"><span>${label}</span><span class="soft-chip soft-primary">${n}</span></div></div>`;
    grid.innerHTML =
      (ahead.length ? head("Coming up", ahead.length) + ahead.map(E.eventCard).join("") : "") +
      (past.length ? head("Already happened", past.length) + past.map(E.eventCard).join("") : "") +
      `<div class="col-12" id="evNoMatch" hidden>${E.empty("ri-search-line", "No events match", "Try another word, or reset the filters.")}</div>`;
    renderToolbar();
    applyFilters();
  }

  function gridSkeleton() {
    document.getElementById("eventsGrid").innerHTML = Array.from(
      { length: 3 },
      () => `<div class="col-xxl-4 col-lg-6"><div class="card custom-card" aria-hidden="true"><div class="card-body d-flex gap-3"><span class="skel skel-tile" style="width:56px;height:60px"></span><div class="flex-fill"><span class="skel skel-line" style="width:70%"></span><span class="skel skel-line skel-line-sm mt-2" style="width:45%"></span><span class="skel skel-line skel-line-sm mt-2" style="width:55%"></span></div></div></div></div>`,
    ).join("");
  }

  async function loadList() {
    const token = ++loadToken;
    gridSkeleton();
    const res = await EventsAPI.list({ scope: state.scope, year: state.year });
    if (token !== loadToken) return;
    if (!res.ok) {
      document.getElementById("eventsGrid").innerHTML = E.empty("ri-error-warning-line", "Couldn't load the events", E.esc(res.message));
      return;
    }
    state.items = res.data || [];
    renderGrid();
  }

  async function loadOverview() {
    const res = await EventsAPI.overview(state.year);
    if (!res.ok) {
      Toast.error(res.message);
      return;
    }
    state.overview = res.data;
    renderOverview(res.data);
  }

  function initTabs() {
    document.querySelectorAll("#eventTabs [data-scope]").forEach((tab) => {
      const on = tab.dataset.scope === state.scope;
      tab.classList.toggle("active", on);
      tab.setAttribute("aria-selected", on);
      tab.addEventListener("click", () => {
        if (tab.dataset.scope === state.scope) return;
        document.querySelectorAll("#eventTabs [data-scope]").forEach((t) => {
          t.classList.toggle("active", t === tab);
          t.setAttribute("aria-selected", t === tab);
        });
        state.scope = tab.dataset.scope;
        state.status = state.type = "";
        syncUrl();
        loadList();
      });
    });
  }

  function initYears() {
    const years = [thisYear - 1, thisYear, thisYear + 1];
    if (!years.includes(state.year)) years.unshift(state.year);
    document.getElementById("yearSwitchWrap").innerHTML = UI.renderSegmented("yearSwitch", years.map((y) => ({ value: y, label: y })), state.year, { ariaLabel: "Year" });
    UI.wireSegmented("yearSwitch", (y) => {
      state.year = Number(y);
      document.getElementById("exportReportBtn").dataset.year = state.year;
      syncUrl();
      loadOverview();
      loadList();
    });
    document.getElementById("exportReportBtn").dataset.year = state.year;
  }

  document.addEventListener("DOMContentLoaded", () => {
    initYears();
    initTabs();
    syncUrl();
    loadOverview();
    loadList();
  });
})();
