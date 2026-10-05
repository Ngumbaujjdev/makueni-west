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
 * Church life (C3): events, initiative sessions, our services and due dates
 * come in too (the "Include" chips), open their own pages, and fill the
 * Coming up / Due soon column; Download (.ics) gives what's on screen.
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
  const ALL_SOURCES = Object.keys(CalendarMeta.SOURCES);
  let sources = [...ALL_SOURCES];
  let cciMounted = false;

  const params = () => new URLSearchParams(window.location.search);

  function syncUrl() {
    const p = params();
    const view = Object.keys(VIEWS).find((k) => VIEWS[k] === calendar?.view.type) || "month";
    p.set("view", view);
    if (calendar) p.set("date", calendar.getDate().toISOString().slice(0, 10));
    p.set("layers", layers.join(","));
    kind ? p.set("kind", kind) : p.delete("kind");
    sources.length === ALL_SOURCES.length ? p.delete("sources") : p.set("sources", sources.join(","));
    history.replaceState(null, "", `${window.location.pathname}?${p}`);
  }

  // ------------------------------------------------------------------ KPIs

  function drawStats() {
    const months = info.by_month.map((_, i) => new Date(info.year, i, 1).toLocaleDateString("en-GB", { month: "short" }));
    const next = info.next_cci;
    const cards = [
      UI.renderSparkCard({ icon: "ri-calendar-event-line", label: "Events this month", value: String(info.this_month), color: "primary", delta: UI.periodDelta(info.this_month, info.last_month), series: { labels: months, data: info.by_month } }),
      UI.renderSparkCard({ icon: "ri-calendar-check-line", label: "This week", value: String(info.this_week), color: "purple", sub: "Events and sessions you can see" }),
      UI.renderSparkCard({ icon: "ri-government-line", label: "Next CCI event", value: next ? new Date(`${next.start.slice(0, 10)}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "None yet", color: "danger", sub: next ? next.title : "The CCI calendar is empty" }),
      UI.renderSparkCard({ icon: "ri-home-heart-line", label: "Our next 30 days", value: String(info.ours_upcoming), color: "success", sub: "Our events, sessions and dates" }),
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
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <span class="fw-semibold me-1">Include</span>
        ${ALL_SOURCES.map((k) => {
          const m = CalendarMeta.SOURCES[k];
          return `<button type="button" class="cal-layer cal-source${sources.includes(k) ? " active" : ""}" data-source="${k}" data-colour="${m.colour}" aria-pressed="${sources.includes(k)}"><span class="cal-layer-dot bg-${m.colour}"></span><i class="${m.icon}"></i>${m.label}</button>`;
        }).join("")}
      </div>`;
    document.querySelectorAll(".cal-source").forEach((b) =>
      b.addEventListener("click", () => {
        const k = b.dataset.source;
        sources = sources.includes(k) ? sources.filter((x) => x !== k) : [...sources, k];
        b.classList.toggle("active", sources.includes(k));
        b.setAttribute("aria-pressed", sources.includes(k));
        calendar.refetchEvents();
        syncUrl();
      }),
    );
    document.querySelectorAll(".cal-layer[data-layer]").forEach((b) =>
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
    if (!layers.length || !sources.length) return success([]);
    const end = new Date(fetchInfo.end);
    end.setDate(end.getDate() - 1);
    const res = await CalendarAPI.events({ from: fetchInfo.startStr.slice(0, 10), to: end.toISOString().slice(0, 10), layers, kinds: kind ? [kind] : [], sources });
    if (!res.ok) {
      Toast.error(res.message);
      return failure(new Error(res.message));
    }
    success(
      res.data.map((o) => {
        const name = colourOf(o);
        const m = { colour: name };
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
          classNames: [`cal-ev-${o.layer}`, `cal-src-${o.source || "calendar"}`, ...(o.status === "draft" ? ["cal-ev-draft"] : [])],
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
        const badge = o.layer === "cci" ? '<span class="cal-ev-badge">CCI</span>' : o.status === "draft" ? '<span class="cal-ev-badge">Draft</span>' : o.status === "late" ? '<span class="cal-ev-badge">Late</span>' : "";
        const time = !o.all_day && arg.view.type !== "listMonth" ? `<span class="cal-ev-time">${esc(o.start.slice(11, 16))}</span>` : "";
        return { html: `<div class="cal-ev"><i class="${icon}"></i>${badge}${time}<span class="cal-ev-title">${esc(o.title)}</span></div>` };
      },
      eventClick: (arg) => {
        arg.jsEvent.preventDefault();
        openItem(arg.event.extendedProps.o);
      },
      dateClick: (arg) => {
        if (!info.can.manage) return;
        CalendarEventModal.form(null, { kinds: info.kinds, level: ctx.level, date: arg.dateStr.slice(0, 10), onSaved: changed });
      },
      datesSet: syncUrl,
    });
    calendar.render();
  }

  /** Any item - grid, Coming up or Due soon - opens a window first. */
  function openItem(o) {
    if (o.source && o.source !== "calendar") {
      CalendarEventModal.preview(o, { siteUrl: ctx.siteUrl || "", colour: colourOf(o) });
      return;
    }
    CalendarEventModal.details(o, {
      kinds: info.kinds,
      onEdit: () => CalendarEventModal.form(CalendarEventModal.eventFromOccurrence(o), { kinds: info.kinds, level: ctx.level, cci: o.layer === "cci", onSaved: changed }),
      onDelete: changed,
    });
  }

  async function reloadStats() {
    const res = await CalendarAPI.overview();
    if (res.ok) {
      info = { ...info, ...res.data };
      drawStats();
    }
  }

  /** Late is red, due dates gold, services pink; everything else takes its layer's colour. */
  function colourOf(o) {
    if (o.tone) return o.tone;
    if (o.source === "due") return "secondary";
    if (o.source === "services") return "pink";
    return (CalendarMeta.LAYERS[o.layer] || CalendarMeta.LAYERS.below).colour;
  }

  // ------------------------------------------------- coming up / due soon

  const isoDay = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const dayLabel = (iso) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });

  function sideItem(o) {
    const src = CalendarMeta.SOURCES[o.source] || CalendarMeta.SOURCES.calendar;
    const colour = colourOf(o);
    const time = !o.all_day && o.start.length > 10 ? ` · ${o.start.slice(11, 16)}` : "";
    const tag = o.status === "late" ? UI.pill("Late", "danger") : o.layer === "cci" ? UI.pill("CCI", "danger") : "";
    const inner = `
      <span class="avatar avatar-sm avatar-rounded bg-${colour} ${colour === "secondary" ? "text-dark" : "text-white"} flex-shrink-0"><i class="${CalendarMeta.KIND_ICONS[o.kind] || src.icon}"></i></span>
      <span class="flex-fill" style="min-width:0"><strong class="d-block text-break">${esc(o.title)}</strong><small>${dayLabel(o.start)}${time}${o.description && o.source === "due" ? ` · ${esc(o.description)}` : ""}</small></span>
      ${tag}`;
    sideOccs.push(o);
    return `<button type="button" class="cal-side-item" data-side="${sideOccs.length - 1}">${inner}</button>`;
  }
  let sideOccs = [];

  async function loadSide() {
    const today = new Date();
    const from = new Date(today);
    from.setDate(from.getDate() - 90);
    const to = new Date(today);
    to.setDate(to.getDate() + 30);
    const week = new Date(today);
    week.setDate(week.getDate() + 7);
    const available = (LAYERS_BY_LEVEL[ctx.level] || LAYERS_BY_LEVEL.church).filter((l) => l !== "below");
    const res = await CalendarAPI.events({ from: isoDay(from), to: isoDay(to), layers: available, sources: ALL_SOURCES });
    const due = document.getElementById("dueSoon");
    const coming = document.getElementById("comingUp");
    if (!res.ok) {
      due.innerHTML = coming.innerHTML = `<p class="mb-0 fw-semibold">${esc(res.message)}</p>`;
      return;
    }
    sideOccs = [];
    const t = isoDay(today);
    const w = isoDay(week);
    const dues = res.data
      .filter((o) => o.source === "due" && (o.status === "late" || o.start.slice(0, 10) >= t))
      .sort((a, b) => (a.status === "late") === (b.status === "late") ? a.start.localeCompare(b.start) : a.status === "late" ? -1 : 1);
    const next = res.data.filter((o) => o.source !== "due" && o.start.slice(0, 10) >= t && o.start.slice(0, 10) <= w).slice(0, 12);
    const late = dues.filter((o) => o.status === "late").length;
    document.getElementById("dueCount").innerHTML = dues.length ? `<span class="soft-chip soft-${late ? "danger" : "secondary"}">${late ? `${late} late` : `${dues.length} coming`}</span>` : "";
    due.innerHTML = dues.length
      ? `<div class="cal-side">${dues.map(sideItem).join("")}</div>`
      : `<div class="cal-side-empty"><span class="avatar avatar-md avatar-rounded bg-success text-white mb-2"><i class="ri-checkbox-circle-line"></i></span><p class="mb-0 fw-semibold">Nothing due - the diocese share is sent and next month's budget is ready.</p></div>`;
    coming.innerHTML = next.length
      ? `<div class="cal-side">${next.map(sideItem).join("")}</div>`
      : `<div class="cal-side-empty"><p class="mb-0 fw-semibold">Nothing in the next 7 days.</p></div>`;
    [due, coming].forEach((box) => {
      box.onclick = (ev) => {
        const btn = ev.target.closest("[data-side]");
        if (btn) openItem(sideOccs[Number(btn.dataset.side)]);
      };
    });
  }

  async function downloadIcs() {
    const btn = document.getElementById("icsBtn");
    const start = calendar.view.activeStart;
    const end = new Date(calendar.view.activeEnd);
    end.setDate(end.getDate() - 1);
    UI.setButtonLoading(btn, "Preparing...");
    const ok = await CalendarAPI.ics({ from: isoDay(start), to: isoDay(end), layers, sources, kinds: kind ? [kind] : [] }, `calendar-${isoDay(start)}-to-${isoDay(end)}.ics`);
    UI.restoreButton(btn);
    ok ? Toast.success("Downloaded - open it to add these to Google or Outlook.") : Toast.error("Couldn't download the calendar. Please try again.");
  }

  /** After an add, edit or delete: the calendar, the figures and (if open) the CCI list. */
  function changed() {
    calendar?.refetchEvents();
    reloadStats();
    loadSide();
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
    if (p.has("sources")) sources = p.get("sources").split(",").filter((k) => ALL_SOURCES.includes(k));

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
      // The menu's "New date" (?add=1): the calendar with the form open, once.
      const here = new URL(window.location.href);
      if (here.searchParams.get("add") === "1") {
        here.searchParams.delete("add");
        history.replaceState(null, "", here.pathname + here.search + here.hash);
        add.click();
      }
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
    loadSide();
    document.getElementById("icsBtn").addEventListener("click", downloadIcs);
  }

  return { init };
})();

window.CalendarPage = CalendarPage;
