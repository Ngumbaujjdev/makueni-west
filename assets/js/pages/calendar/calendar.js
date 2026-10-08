/**
 * ============================================================================
 * CALENDAR - the page (church, region and diocese; docs/specs/calendar-spec.md)
 * ============================================================================
 * The clean month (2026-10-08): a left column beside FullCalendar
 * (month / week / list) -
 *   - a small month with a dot per kind of date, to jump around;
 *   - the chosen day (click any day, "+N more" or the small month) with its
 *     dates, or "Next: ..." when it's empty, and + to add a date on it;
 *   - Show: what comes in (Calendar, Events, Initiative sessions, Services,
 *     Due dates) as a checklist, and the kind of calendar date;
 *   - Whose dates: CCI · Diocese · Region · Ours · Churches below as switches;
 *   - Needs attention: late and coming due dates.
 * Timed dates sit on the grid as a dot, the time and the title; all-day ones
 * as solid bars. Events come from GET /calendar/events for the range on
 * screen; clicking one opens its window. The view, date, chosen day, layers,
 * sources and kind live in the URL, so a refresh or a shared link keeps them.
 * At the diocese, global admins also get the CCI national calendar tab.
 * ============================================================================
 */
const CalendarPage = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => CalendarEventModal.esc(s);
  const isoDay = (d) => CalendarEventModal.isoLocal(d);
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
  let today = isoDay(new Date());
  let selectedDay = today;
  let loaded = []; // the occurrences FullCalendar has for the range on screen

  const params = () => new URLSearchParams(window.location.search);

  function syncUrl() {
    const p = params();
    const view = Object.keys(VIEWS).find((k) => VIEWS[k] === calendar?.view.type) || "month";
    p.set("view", view);
    if (calendar) p.set("date", isoDay(calendar.getDate()));
    selectedDay === today ? p.delete("day") : p.set("day", selectedDay);
    p.set("layers", layers.join(","));
    kind ? p.set("kind", kind) : p.delete("kind");
    sources.length === ALL_SOURCES.length ? p.delete("sources") : p.set("sources", sources.join(","));
    history.replaceState(null, "", `${window.location.pathname}?${p}`);
  }

  // --------------------------------------------------------------- helpers

  const dayLabel = (iso, opts = { weekday: "short", day: "numeric", month: "short" }) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", opts);
  const longDay = (iso) => dayLabel(iso, { weekday: "long", day: "numeric", month: "long" });
  const rgb = (name) => `rgb(var(--${name}-rgb))`;

  /** Late is red, due dates gold, services pink; everything else takes its layer's colour. */
  function colourOf(o) {
    if (o.tone) return o.tone;
    if (o.source === "due") return "secondary";
    if (o.source === "services") return "pink";
    return (CalendarMeta.LAYERS[o.layer] || CalendarMeta.LAYERS.below).colour;
  }

  /** Is this occurrence on that day? All-day ends are exclusive (FullCalendar's), timed ones inclusive. */
  function covers(o, iso) {
    const s = o.start.slice(0, 10);
    if (!o.end) return iso === s;
    const e = o.end.slice(0, 10);
    return o.all_day ? iso >= s && iso < (e > s ? e : s + "~") : iso >= s && iso <= e;
  }
  const sortDay = (a, b) => (a.all_day === b.all_day ? a.start.localeCompare(b.start) : a.all_day ? -1 : 1);

  /** What the list on the left says under a title: where, and whose when it isn't ours. */
  function subOf(o) {
    const whose = o.layer !== "ours" ? (CalendarMeta.LAYERS[o.layer] || CalendarMeta.LAYERS.below).short : "";
    return [whose, o.location || (o.source === "due" ? o.description : "")].filter(Boolean).join(" · ");
  }

  // ------------------------------------------------------- the small month

  function drawMini() {
    const box = document.getElementById("calMini");
    if (!calendar || !box) return;
    const anchor = calendar.view.type === "timeGridWeek" ? new Date(`${selectedDay}T12:00:00`) : calendar.getDate();
    const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    const start = new Date(first);
    start.setDate(1 - ((first.getDay() + 6) % 7)); // back to Monday
    const days = [];
    for (let i = 0; i < 42; i++) {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      const iso = isoDay(d);
      const colours = [...new Set(loaded.filter((o) => covers(o, iso)).map(colourOf))].slice(0, 3);
      const cls = [d.getMonth() !== first.getMonth() ? "is-out" : "", iso === today ? "is-today" : "", iso === selectedDay ? "is-selected" : ""].filter(Boolean).join(" ");
      days.push(
        `<button type="button" class="cal-mini-day ${cls}" data-day="${iso}" aria-label="${esc(longDay(iso))}" aria-pressed="${iso === selectedDay}"><span class="cal-mini-num">${d.getDate()}</span><span class="cal-mini-dots">${colours.map((c) => `<i style="background:${rgb(c)}"></i>`).join("")}</span></button>`,
      );
    }
    box.innerHTML = `
      <div class="cal-mini-head">
        <strong>${first.toLocaleDateString("en-GB", { month: "long", year: "numeric" })}</strong>
        <div class="d-flex gap-1">
          <button type="button" class="cal-mini-nav" data-nav="-1" aria-label="Previous month"><i class="ri-arrow-left-s-line"></i></button>
          <button type="button" class="cal-mini-nav" data-nav="1" aria-label="Next month"><i class="ri-arrow-right-s-line"></i></button>
        </div>
      </div>
      <div class="cal-mini-grid">${["M", "T", "W", "T", "F", "S", "S"].map((w) => `<span class="cal-mini-wd">${w}</span>`).join("")}${days.join("")}</div>`;
    box.onclick = (ev) => {
      const nav = ev.target.closest("[data-nav]");
      if (nav) return calendar.gotoDate(new Date(first.getFullYear(), first.getMonth() + Number(nav.dataset.nav), 1));
      const day = ev.target.closest("[data-day]");
      if (day) selectDay(day.dataset.day);
    };
  }

  // --------------------------------------------------------- the chosen day

  let dayOccs = [];

  function drawDay() {
    const box = document.getElementById("calDay");
    if (!box) return;
    dayOccs = loaded.filter((o) => covers(o, selectedDay)).sort(sortDay);
    const add = info.can.manage ? `<button type="button" class="cal-day-add" data-add aria-label="Add a date on ${esc(longDay(selectedDay))}"><i class="ri-add-line"></i></button>` : "";
    let body;
    if (dayOccs.length) {
      body = `<div class="cal-day-list">${dayOccs
        .map((o, i) => {
          const time = o.all_day ? "All day" : o.start.slice(11, 16);
          const end = !o.all_day && o.end && o.end.length > 10 ? o.end.slice(11, 16) : "";
          const sub = subOf(o);
          return `<button type="button" class="cal-day-item" data-i="${i}">
            <span class="cal-day-time">${time}${end ? `<small>${end}</small>` : ""}</span>
            <span class="cal-dot" style="background:${rgb(colourOf(o))}"></span>
            <span class="cal-day-text"><strong>${esc(o.title)}</strong>${sub ? `<small>${esc(sub)}</small>` : ""}</span>
          </button>`;
        })
        .join("")}</div>`;
    } else {
      const next = loaded.filter((o) => o.start.slice(0, 10) > selectedDay).sort((a, b) => a.start.localeCompare(b.start))[0];
      const nextDay = next ? next.start.slice(0, 10) : null;
      const n = nextDay ? loaded.filter((o) => covers(o, nextDay)).length : 0;
      body = `<div class="cal-day-empty"><p class="mb-2 fw-semibold">Nothing on this day.</p>${
        nextDay ? `<button type="button" class="cal-day-next" data-next="${nextDay}">Next: ${esc(dayLabel(nextDay))} · ${n} ${n === 1 ? "date" : "dates"}<i class="ri-arrow-right-line"></i></button>` : ""
      }</div>`;
    }
    box.innerHTML = `
      <div class="cal-day-head">
        <div><span class="cal-kicker">${selectedDay === today ? "Today" : "Chosen day"}</span><strong>${esc(longDay(selectedDay))}</strong></div>
        ${add}
      </div>
      ${body}`;
    box.onclick = (ev) => {
      if (ev.target.closest("[data-add]")) return openNew(selectedDay);
      const next = ev.target.closest("[data-next]");
      if (next) return selectDay(next.dataset.next);
      const item = ev.target.closest("[data-i]");
      if (item) openItem(dayOccs[Number(item.dataset.i)]);
    };
  }

  function selectDay(iso) {
    selectedDay = iso;
    const v = calendar.view;
    if (iso < isoDay(v.activeStart) || iso >= isoDay(v.activeEnd)) {
      calendar.gotoDate(iso); // datesSet and eventsSet redraw the rest
    }
    markSelected();
    drawMini();
    drawDay();
    syncUrl();
  }

  /** FullCalendar draws day classes once, so the chosen day's outline is moved by hand. */
  function markSelected() {
    document.querySelectorAll("#calendar .fc-daygrid-day[data-date], #calendar .fc-timegrid-col[data-date]").forEach((c) => c.classList.toggle("cal-day-selected", c.dataset.date === selectedDay));
  }

  // --------------------------------------------------------------- filters

  function drawFilters() {
    const available = LAYERS_BY_LEVEL[ctx.level] || LAYERS_BY_LEVEL.church;
    document.getElementById("calShow").innerHTML = `
      <div class="cal-checks-title">Show</div>
      ${ALL_SOURCES.map((k) => {
        const m = CalendarMeta.SOURCES[k];
        const on = sources.includes(k);
        return `<button type="button" class="cal-check" data-source="${k}" aria-pressed="${on}" style="--c: var(--${m.colour}-rgb)">
          <span class="cal-check-box" data-colour="${m.colour}"><i class="ri-check-line"></i></span>
          <span class="flex-fill">${m.label}</span>
          <span class="cal-check-count" data-count="${k}"></span>
        </button>`;
      }).join("")}
      <div class="cal-kind-filter mt-2 px-1">
        <label class="form-label mb-1" for="calKind">Kind of calendar date</label>
        <select class="form-select" id="calKind">
          <option value="">All kinds</option>
          ${Object.entries(info.kinds)
            .map(([k, l]) => `<option value="${k}" data-icon="${CalendarMeta.KIND_ICONS[k]}" data-color="primary"${kind === k ? " selected" : ""}>${esc(l)}</option>`)
            .join("")}
        </select>
      </div>`;

    const subs = {
      cci: "National office",
      diocese: "The diocese",
      region: ctx.level === "church" ? "Our region" : "The region",
      ours: info.place?.name || "Us",
      below: ctx.level === "region" ? "Our churches" : "Regions and churches",
    };
    document.getElementById("calWhose").innerHTML = `
      <div class="cal-checks-title">Whose dates</div>
      ${["ours", "region", "diocese", "cci", "below"]
        .filter((l) => available.includes(l))
        .map((l) => {
          const m = CalendarMeta.LAYERS[l];
          const on = layers.includes(l);
          return `<button type="button" class="cal-switch-row" data-layer="${l}" role="switch" aria-checked="${on}">
            <span class="cal-dot" style="background:${rgb(m.colour)}"></span>
            <span class="flex-fill"><strong>${l === "below" ? m.label : m.short}</strong><small>${esc(subs[l])}</small></span>
            <span class="cal-switch"><i></i></span>
          </button>`;
        })
        .join("")}`;

    document.getElementById("calShow").onclick = (ev) => {
      const b = ev.target.closest("[data-source]");
      if (!b) return;
      const k = b.dataset.source;
      sources = sources.includes(k) ? sources.filter((x) => x !== k) : [...sources, k];
      b.setAttribute("aria-pressed", sources.includes(k));
      calendar.refetchEvents();
      syncUrl();
    };
    document.getElementById("calWhose").onclick = (ev) => {
      const b = ev.target.closest("[data-layer]");
      if (!b) return;
      const l = b.dataset.layer;
      layers = layers.includes(l) ? layers.filter((x) => x !== l) : [...layers, l];
      b.setAttribute("aria-checked", layers.includes(l));
      calendar.refetchEvents();
      syncUrl();
    };
    const sel = document.getElementById("calKind");
    UI.enhanceSelect(sel, { search: false });
    sel.addEventListener("change", () => {
      kind = sel.value;
      calendar.refetchEvents();
      syncUrl();
    });
  }

  /** The counts beside Show, and the "N dates this month" chip on the grid. */
  function drawCounts() {
    const v = calendar.view;
    const from = isoDay(v.currentStart);
    const to = isoDay(v.currentEnd);
    const inView = loaded.filter((o) => o.start.slice(0, 10) >= from && o.start.slice(0, 10) < to);
    ALL_SOURCES.forEach((k) => {
      const el = document.querySelector(`[data-count="${k}"]`);
      if (el) el.textContent = sources.includes(k) ? inView.filter((o) => (o.source || "calendar") === k).length : "";
    });
    const chunk = document.querySelector("#calendar .fc-header-toolbar .fc-toolbar-chunk:last-child");
    if (chunk) chunk.innerHTML = `<span class="soft-chip soft-primary">${inView.length} ${inView.length === 1 ? "date" : "dates"} this ${v.type === "timeGridWeek" ? "week" : "month"}</span>`;
    const fig = document.querySelector('[data-tab-figure="calendar"]');
    if (fig && info) fig.textContent = `${info.this_month} this month`;
  }

  // -------------------------------------------------------------- calendar

  async function fetchEvents(fetchInfo, success, failure) {
    if (!layers.length || !sources.length) return success([]);
    const end = new Date(fetchInfo.end);
    end.setDate(end.getDate() - 1);
    const res = await CalendarAPI.events({ from: isoDay(fetchInfo.start), to: isoDay(end), layers, kinds: kind ? [kind] : [], sources });
    if (!res.ok) {
      Toast.error(res.message);
      return failure(new Error(res.message));
    }
    success(
      res.data.map((o) => {
        const name = colourOf(o);
        const colour = UI.cssColor(name);
        return {
          id: o.key,
          title: o.title,
          start: o.start,
          end: o.end,
          allDay: o.all_day,
          backgroundColor: colour,
          borderColor: colour,
          textColor: name === "secondary" || name === "pink" ? "#0d0d0d" : "#ffffff",
          classNames: [`cal-ev-${o.layer}`, `cal-src-${o.source || "calendar"}`, ...(o.status === "draft" ? ["cal-ev-draft"] : [])],
          extendedProps: { o },
        };
      }),
    );
  }

  function renderCalendar(view) {
    const p = params();
    calendar = new FullCalendar.Calendar(document.getElementById("calendar"), {
      initialView: view,
      initialDate: p.get("date") || selectedDay,
      headerToolbar: { left: "prev,next today", center: "title", right: "" },
      height: "auto",
      firstDay: 1,
      dayMaxEvents: 3,
      eventDisplay: "auto", // timed dates as a dot, the time and the title; all-day ones as bars
      nowIndicator: true,
      noEventsContent: "Nothing on the calendar here - try more of Show and Whose dates, or another month.",
      events: fetchEvents,
      eventContent: (arg) => {
        const o = arg.event.extendedProps.o;
        const badge = o.layer === "cci" ? '<span class="cal-ev-badge">CCI</span>' : o.status === "draft" ? '<span class="cal-ev-badge">Draft</span>' : o.status === "late" ? '<span class="cal-ev-badge">Late</span>' : "";
        const timed = !o.all_day && arg.view.type !== "listMonth";
        const dot = timed ? `<span class="cal-dot" style="background:${rgb(colourOf(o))}"></span>` : "";
        const time = timed ? `<span class="cal-ev-time">${esc(o.start.slice(11, 16))}</span>` : "";
        return { html: `<div class="cal-ev">${dot}${badge}${time}<span class="cal-ev-title">${esc(o.title)}</span></div>` };
      },
      eventClick: (arg) => {
        arg.jsEvent.preventDefault();
        openItem(arg.event.extendedProps.o);
      },
      // A click on a day (or "+N more") chooses it - its dates show on the left, with + to add one.
      dateClick: (arg) => selectDay(arg.dateStr.slice(0, 10)),
      moreLinkClick: (arg) => {
        selectDay(isoDay(arg.date));
        return true; // neither a popover nor another view
      },
      dayCellClassNames: (arg) => (isoDay(arg.date) === selectedDay ? ["cal-day-selected"] : []),
      datesSet: (arg) => {
        // The chosen day follows the month on screen: today if it's there, else the first day.
        const from = isoDay(arg.view.currentStart);
        const to = isoDay(arg.view.currentEnd);
        if (selectedDay < from || selectedDay >= to) selectedDay = today >= from && today < to ? today : from;
        markSelected();
        drawMini();
        drawDay();
        syncUrl();
      },
      eventsSet: (events) => {
        loaded = events.map((e) => e.extendedProps.o).filter(Boolean);
        drawMini();
        drawDay();
        drawCounts();
        markSelected();
      },
    });
    calendar.render();
  }

  /** Any item - grid, the chosen day or Needs attention - opens a window first. */
  function openItem(o) {
    if (!o) return;
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

  function openNew(date = null) {
    CalendarEventModal.form(null, { kinds: info.kinds, level: ctx.level, date: date || selectedDay, links: ctx.newLinks || null, onSaved: changed });
  }

  async function reloadStats() {
    const res = await CalendarAPI.overview();
    if (res.ok) {
      info = { ...info, ...res.data };
      if (calendar) drawCounts();
    }
  }

  // ------------------------------------------------------- needs attention

  let attOccs = [];

  async function loadSide() {
    const from = new Date();
    from.setDate(from.getDate() - 90);
    const to = new Date();
    to.setDate(to.getDate() + 30);
    const available = (LAYERS_BY_LEVEL[ctx.level] || LAYERS_BY_LEVEL.church).filter((l) => l !== "below");
    const res = await CalendarAPI.events({ from: isoDay(from), to: isoDay(to), layers: available, sources: ["due"] });
    const card = document.getElementById("calAttentionCard");
    if (!res.ok) return card.classList.add("d-none");
    attOccs = res.data
      .filter((o) => o.source === "due" && (o.status === "late" || o.start.slice(0, 10) >= today))
      .sort((a, b) => ((a.status === "late") === (b.status === "late") ? a.start.localeCompare(b.start) : a.status === "late" ? -1 : 1));
    card.classList.toggle("d-none", !attOccs.length);
    if (!attOccs.length) return;
    const late = attOccs.filter((o) => o.status === "late").length;
    const daysTo = (iso) => Math.round((new Date(`${iso}T12:00:00`) - new Date(`${today}T12:00:00`)) / 864e5);
    document.getElementById("calAttention").innerHTML = `
      <div class="cal-checks-title d-flex justify-content-between align-items-center">Needs attention${late ? UI.pill(`${late} late`, "danger") : ""}</div>
      ${attOccs
        .map((o, i) => {
          const d = daysTo(o.start.slice(0, 10));
          const pill = o.status === "late" ? UI.pill("Late", "danger") : o.status === "draft" ? UI.pill("Draft", "primary") : UI.pill(d === 0 ? "Today" : d === 1 ? "Tomorrow" : `In ${d} days`, "secondary");
          return `<button type="button" class="cal-att-item" data-att="${i}">
            <span class="cal-dot" style="background:${rgb(o.status === "late" ? "danger" : "secondary")}"></span>
            <span class="flex-fill cal-day-text"><strong>${esc(o.title)}</strong><small>${o.status === "late" ? "Was due" : "Due"} ${esc(dayLabel(o.start))}</small></span>
            ${pill}
          </button>`;
        })
        .join("")}`;
    document.getElementById("calAttention").onclick = (ev) => {
      const b = ev.target.closest("[data-att]");
      if (b) openItem(attOccs[Number(b.dataset.att)]);
    };
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

  /** After an add, edit or delete: the calendar, the figures, Needs attention and (if open) the CCI list. */
  function changed() {
    calendar?.refetchEvents();
    reloadStats();
    loadSide();
    if (cciMounted) CalendarCci.reload();
  }

  // ------------------------------------------------------------------ init

  async function init() {
    ctx = window.CALENDAR_CTX || { level: "church", tab: "calendar" };
    today = isoDay(new Date());
    const res = await CalendarAPI.overview();
    if (!res.ok) {
      document.querySelector(".cal-layout").innerHTML = `<div class="col-12"><div class="alert alert-danger d-flex align-items-center gap-2 mb-0"><i class="ri-error-warning-line"></i><span class="flex-fill">${esc(res.message)}</span><button type="button" class="btn btn-sm btn-danger" onclick="location.reload()">Try again</button></div></div>`;
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
    if (/^\d{4}-\d{2}-\d{2}$/.test(p.get("day") || "")) selectedDay = p.get("day");

    drawFilters();

    // A phone gets the list unless the link says otherwise - a month grid is too tight there.
    const view = Object.keys(VIEWS).includes(p.get("view")) ? p.get("view") : window.innerWidth < 768 ? "list" : "month";
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
      calendar.changeView(VIEWS[v], selectedDay);
      syncUrl();
    });

    const add = document.getElementById("addEventBtn");
    if (info.can.manage) {
      add.classList.remove("d-none");
      add.addEventListener("click", () => openNew());
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

    renderCalendar(VIEWS[view]);
    loadSide();
    document.getElementById("icsBtn").addEventListener("click", downloadIcs);

    // The menu's "New date" (?add=1): the calendar with the form open, once.
    const here = new URL(window.location.href);
    if (info.can.manage && here.searchParams.get("add") === "1") {
      here.searchParams.delete("add");
      history.replaceState(null, "", here.pathname + here.search + here.hash);
      openNew();
    }
  }

  return { init };
})();

window.CalendarPage = CalendarPage;
