/**
 * ============================================================================
 * CALENDAR - the page (church, region and diocese; docs/specs/calendar-spec.md)
 * ============================================================================
 * KPI cards, layer chips (CCI · Diocese · Region · Ours · Churches below) and
 * a kind filter over FullCalendar (month / week / list). Events come from
 * GET /calendar/events for the range on screen; clicking one opens it,
 * clicking a day adds one (when this place may). The view, date, layers and
 * kind live in the URL, so a refresh or a shared link keeps them. At the
 * diocese, global admins also get the CCI national calendar tab.
 * ============================================================================
 */
const CalendarPage = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => CalendarEventModal.esc(s);
  const LAYERS_BY_LEVEL = { church: ["cci", "diocese", "region", "ours"], region: ["cci", "diocese", "ours", "below"], diocese: ["cci", "ours", "below"] };
  const VIEWS = { month: "dayGridMonth", week: "timeGridWeek", list: "listMonth" };

  let ctx = null;
  let info = null; // GET /calendar/overview
  let calendar = null;
  let layers = [];
  let kind = "";
  let cciMounted = false;

  const params = () => new URLSearchParams(window.location.search);

  function syncUrl() {
    const p = params();
    const view = Object.keys(VIEWS).find((k) => VIEWS[k] === calendar?.view.type) || "month";
    p.set("view", view);
    if (calendar) p.set("date", calendar.getDate().toISOString().slice(0, 10));
    p.set("layers", layers.join(","));
    kind ? p.set("kind", kind) : p.delete("kind");
    history.replaceState(null, "", `${window.location.pathname}?${p}`);
  }

  // ------------------------------------------------------------------ KPIs

  function drawStats() {
    const months = info.by_month.map((_, i) => new Date(info.year, i, 1).toLocaleDateString("en-GB", { month: "short" }));
    const next = info.next_cci;
    const cards = [
      UI.renderSparkCard({ icon: "ri-calendar-event-line", label: "Events this month", value: String(info.this_month), color: "primary", delta: UI.periodDelta(info.this_month, info.last_month), series: { labels: months, data: info.by_month } }),
      UI.renderSparkCard({ icon: "ri-calendar-check-line", label: "This week", value: String(info.this_week), color: "purple", sub: "Everything you can see" }),
      UI.renderSparkCard({ icon: "ri-government-line", label: "Next CCI event", value: next ? new Date(`${next.start.slice(0, 10)}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "None yet", color: "danger", sub: next ? next.title : "The CCI calendar is empty" }),
      UI.renderSparkCard({ icon: "ri-home-heart-line", label: "Our next 30 days", value: String(info.ours_upcoming), color: "success", sub: "Events we added" }),
    ];
    document.getElementById("calStats").innerHTML = cards.map((c) => `<div class="col-xxl-3 col-md-6">${c}</div>`).join("");
    UI.mountSparklines(document.getElementById("calStats"));
    const fig = document.querySelector('[data-tab-figure="calendar"]');
    if (fig) fig.textContent = `${info.this_month} this month`;
  }

  // --------------------------------------------------------------- filters

  function drawFilters() {
    const available = LAYERS_BY_LEVEL[ctx.level] || LAYERS_BY_LEVEL.church;
    document.getElementById("calFilters").innerHTML = `
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-1">Show</span>
        ${available
          .map((l) => {
            const m = CalendarMeta.LAYERS[l];
            return `<button type="button" class="cal-layer${layers.includes(l) ? " active" : ""}" data-layer="${l}" data-colour="${m.colour}" aria-pressed="${layers.includes(l)}"><span class="cal-layer-dot bg-${m.colour}"></span><i class="${m.icon}"></i>${m.label}</button>`;
          })
          .join("")}
        <div class="ms-auto cal-kind-filter">
          <select class="form-select" id="calKind" aria-label="Kind of event">
            <option value="">All kinds</option>
            ${Object.entries(info.kinds)
              .map(([k, l]) => `<option value="${k}" data-icon="${CalendarMeta.KIND_ICONS[k]}" data-color="primary"${kind === k ? " selected" : ""}>${esc(l)}</option>`)
              .join("")}
          </select>
        </div>
      </div>`;
    document.querySelectorAll(".cal-layer").forEach((b) =>
      b.addEventListener("click", () => {
        const l = b.dataset.layer;
        layers = layers.includes(l) ? layers.filter((x) => x !== l) : [...layers, l];
        b.classList.toggle("active", layers.includes(l));
        b.setAttribute("aria-pressed", layers.includes(l));
        calendar.refetchEvents();
        syncUrl();
      }),
    );
    const sel = document.getElementById("calKind");
    UI.enhanceSelect(sel, { search: false });
    sel.addEventListener("change", () => {
      kind = sel.value;
      calendar.refetchEvents();
      syncUrl();
    });
  }

  // -------------------------------------------------------------- calendar

  async function fetchEvents(fetchInfo, success, failure) {
    if (!layers.length) return success([]);
    const end = new Date(fetchInfo.end);
    end.setDate(end.getDate() - 1);
    const res = await CalendarAPI.events({ from: fetchInfo.startStr.slice(0, 10), to: end.toISOString().slice(0, 10), layers, kinds: kind ? [kind] : [] });
    if (!res.ok) {
      Toast.error(res.message);
      return failure(new Error(res.message));
    }
    success(
      res.data.map((o) => {
        const m = CalendarMeta.LAYERS[o.layer] || CalendarMeta.LAYERS.below;
        const colour = UI.cssColor(m.colour);
        return {
          id: o.key,
          title: o.title,
          start: o.start,
          end: o.end,
          allDay: o.all_day,
          backgroundColor: colour,
          borderColor: colour,
          textColor: m.colour === "secondary" ? "#0d0d0d" : "#ffffff",
          classNames: [`cal-ev-${o.layer}`],
          extendedProps: { o },
        };
      }),
    );
  }

  function renderCalendar() {
    const p = params();
    const view = VIEWS[p.get("view")] || VIEWS.month;
    calendar = new FullCalendar.Calendar(document.getElementById("calendar"), {
      initialView: view,
      initialDate: p.get("date") || undefined,
      headerToolbar: { left: "prev,next today", center: "title", right: "" },
      height: "auto",
      firstDay: 1,
      dayMaxEvents: 3,
      eventDisplay: "block", // timed events as coloured blocks too, not just a dot
      nowIndicator: true,
      noEventsContent: "Nothing on the calendar here - try more layers, or another month.",
      events: fetchEvents,
      eventContent: (arg) => {
        const o = arg.event.extendedProps.o;
        const icon = CalendarMeta.KIND_ICONS[o.kind] || "ri-calendar-line";
        const badge = o.layer === "cci" ? '<span class="cal-ev-badge">CCI</span>' : "";
        const time = !o.all_day && arg.view.type !== "listMonth" ? `<span class="cal-ev-time">${esc(o.start.slice(11, 16))}</span>` : "";
        return { html: `<div class="cal-ev"><i class="${icon}"></i>${badge}${time}<span class="cal-ev-title">${esc(o.title)}</span></div>` };
      },
      eventClick: (arg) => {
        arg.jsEvent.preventDefault();
        const o = arg.event.extendedProps.o;
        CalendarEventModal.details(o, {
          kinds: info.kinds,
          onEdit: () => CalendarEventModal.form(CalendarEventModal.eventFromOccurrence(o), { kinds: info.kinds, level: ctx.level, cci: o.layer === "cci", onSaved: changed }),
          onDelete: changed,
        });
      },
      dateClick: (arg) => {
        if (!info.can.manage) return;
        CalendarEventModal.form(null, { kinds: info.kinds, level: ctx.level, date: arg.dateStr.slice(0, 10), onSaved: changed });
      },
      datesSet: syncUrl,
    });
    calendar.render();
  }

  async function reloadStats() {
    const res = await CalendarAPI.overview();
    if (res.ok) {
      info = { ...info, ...res.data };
      drawStats();
    }
  }

  /** After an add, edit or delete: the calendar, the figures and (if open) the CCI list. */
  function changed() {
    calendar?.refetchEvents();
    reloadStats();
    if (cciMounted) CalendarCci.reload();
  }

  // ------------------------------------------------------------------ init

  async function init() {
    ctx = window.CALENDAR_CTX || { level: "church", tab: "calendar" };
    document.getElementById("calStats").innerHTML = UI.skeletonCards(4, "col-xxl-3 col-md-6");
    const res = await CalendarAPI.overview();
    if (!res.ok) {
      document.getElementById("calStats").innerHTML = `<div class="col-12"><div class="alert alert-danger">${esc(res.message)}</div></div>`;
      return;
    }
    info = res.data;
    if (info.place?.name) document.getElementById("placeLine").textContent = `Calendar for ${info.place.name}`;

    const p = params();
    const available = LAYERS_BY_LEVEL[ctx.level] || LAYERS_BY_LEVEL.church;
    const fromUrl = (p.get("layers") || "").split(",").filter((l) => available.includes(l));
    layers = p.has("layers") ? fromUrl : available.filter((l) => l !== "below");
    kind = info.kinds[p.get("kind")] ? p.get("kind") : "";

    drawStats();
    drawFilters();

    const view = Object.keys(VIEWS).includes(p.get("view")) ? p.get("view") : "month";
    document.getElementById("viewSwitchWrap").innerHTML = UI.renderSegmented(
      "calView",
      [
        { value: "month", label: '<i class="ri-calendar-2-line me-1"></i>Month' },
        { value: "week", label: '<i class="ri-calendar-todo-line me-1"></i>Week' },
        { value: "list", label: '<i class="ri-list-check-2 me-1"></i>List' },
      ],
      view,
      { ariaLabel: "Calendar view" },
    );
    UI.wireSegmented("calView", (v) => {
      calendar.changeView(VIEWS[v]);
      syncUrl();
    });

    const add = document.getElementById("addEventBtn");
    if (info.can.manage) {
      add.classList.remove("d-none");
      add.addEventListener("click", () => CalendarEventModal.form(null, { kinds: info.kinds, level: ctx.level, onSaved: changed }));
    }

    // The CCI national calendar tab: the diocese, global admins only.
    const tabs = document.getElementById("calendarTabs");
    if (ctx.level === "diocese" && info.can.cci) {
      tabs.classList.remove("d-none");
      const mountCci = () => {
        if (cciMounted) return;
        cciMounted = true;
        CalendarCci.mount(document.getElementById("cciBody"), { kinds: info.kinds, onChanged: changed });
      };
      tabs.querySelectorAll("[data-tab]").forEach((b) =>
        b.addEventListener("shown.bs.tab", () => {
          const q = params();
          b.dataset.tab === "cci" ? q.set("tab", "cci") : q.delete("tab");
          history.replaceState(null, "", `${window.location.pathname}?${q}`);
          if (b.dataset.tab === "cci") mountCci();
          else calendar?.updateSize();
        }),
      );
      if (ctx.tab === "cci") mountCci();
    } else if (ctx.tab === "cci") {
      // Not a global admin: the CCI tab isn't theirs - show the calendar.
      document.getElementById("tab-cci").classList.remove("show", "active");
      document.getElementById("tab-calendar").classList.add("show", "active");
    }

    renderCalendar();
  }

  return { init };
})();

window.CalendarPage = CalendarPage;
