/**
 * ============================================================================
 * FACILITIES - Bookings (bookings.php)
 * ============================================================================
 * The week by the hour (FullCalendar time grid; a list on a phone), each room
 * in its colour, room pills with this week's counts to show one room. Drag
 * across a free time to book it; tap a booking to see, change or cancel it.
 * ?date= opens that week, ?room= that room, ?new=1&ministry= the Book window
 * filled in for a ministry's weekly meeting (from its page).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const state = { room: params.get("room") ? Number(params.get("room")) : null, view: window.innerWidth < 768 ? "list" : params.get("view") || "week", items: [], range: null };
  let cal = null;
  let opts = null;

  function syncUrl() {
    const q = new URLSearchParams(window.location.search);
    state.room ? q.set("room", state.room) : q.delete("room");
    state.view !== "week" ? q.set("view", state.view) : q.delete("view");
    ["new", "ministry"].forEach((k) => q.delete(k));
    if (cal) q.set("date", F.iso(cal.getDate()));
    history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
  }

  function pills() {
    const count = (id) => state.items.filter((b) => !id || b.room?.id === id).length;
    const rooms = opts.rooms.filter((r) => r.active);
    $("roomPills").innerHTML = `<div class="pp-pills" role="tablist" aria-label="Room">
      <button type="button" class="pp-pill${state.room ? "" : " is-on"}" style="--q: var(--primary-rgb)" data-room=""><i class="ri-apps-2-line"></i>All rooms<span class="pp-pill-count">${count(null)}</span></button>
      ${rooms.map((r) => `<button type="button" class="pp-pill${state.room === r.id ? " is-on" : ""}" style="--q: var(--${r.colour}-rgb)" data-room="${r.id}"><i class="fx-room-swatch bg-${r.colour}"></i>${F.esc(r.name)}<span class="pp-pill-count">${count(r.id)}</span></button>`).join("")}
    </div>`;
  }

  async function fetchEvents(info, success, failure) {
    const res = await FacilitiesAPI.bookings({ from: F.iso(info.start), to: F.iso(info.end) });
    if (!res.ok) {
      Toast.error(res.message);
      return failure(new Error(res.message));
    }
    state.items = res.data.items;
    pills();
    syncUrl();
    success(
      state.items
        .filter((b) => !state.room || b.room?.id === state.room)
        .map((b) => {
          const colour = UI.cssColor(b.room?.colour || "primary");
          return { id: b.key, title: b.purpose, start: b.starts_at, end: b.ends_at, backgroundColor: colour, borderColor: colour, textColor: b.room?.colour === "warning" ? "#1d1d1f" : "#fff", extendedProps: { b } };
        }),
    );
  }

  function render() {
    $("fcCal").innerHTML = "";
    const start = params.get("date") || undefined;
    cal = new FullCalendar.Calendar($("fcCal"), {
      initialView: state.view === "list" ? "listWeek" : "timeGridWeek",
      initialDate: start,
      headerToolbar: { left: "prev,next today", center: "title", right: "" },
      firstDay: 1,
      height: "auto",
      allDaySlot: false,
      nowIndicator: true,
      slotMinTime: `${opts.hours.from}:00`,
      slotMaxTime: `${opts.hours.to}:00`,
      slotDuration: "00:30:00",
      expandRows: true,
      selectable: CTX.can.book,
      selectMirror: true,
      selectOverlap: false,
      eventTimeFormat: { hour: "numeric", minute: "2-digit", meridiem: "short" },
      slotLabelFormat: { hour: "numeric", meridiem: "short" },
      events: fetchEvents,
      eventContent: (arg) => {
        const b = arg.event.extendedProps.b;
        return { html: `<div class="fx-bk"><strong>${F.esc(b.purpose)}</strong><small>${F.esc(b.room?.name || "")}${b.repeat === "weekly" ? ' · <i class="ri-repeat-line"></i>' : ""}</small></div>` };
      },
      select: (info) => {
        cal.unselect();
        const pad = (n) => String(n).padStart(2, "0");
        const t = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
        F.bookWindow({ defaults: { room_id: state.room || undefined, date: F.iso(info.start), start: t(info.start), end: t(info.end) }, onDone: () => cal.refetchEvents() });
      },
      eventClick: (info) => F.bookingWindow(info.event.extendedProps.b, { onDone: () => cal.refetchEvents() }),
      datesSet: () => syncUrl(),
    });
    cal.render();
  }

  function views() {
    $("viewSwitchWrap").innerHTML = UI.renderSegmented(
      "bkView",
      [
        { value: "week", label: '<i class="ri-calendar-2-line me-1"></i>Week' },
        { value: "list", label: '<i class="ri-list-check me-1"></i>List' },
      ],
      state.view,
      { ariaLabel: "View" },
    );
    UI.wireSegmented("bkView", (v) => {
      state.view = v;
      cal.changeView(v === "list" ? "listWeek" : "timeGridWeek");
      syncUrl();
    });
  }

  async function init() {
    opts = await F.options();
    if (!opts) return;
    $("hoursChip").innerHTML = `<i class="ri-time-line"></i>Rooms can be booked ${F.ampm(opts.hours.from)} - ${F.ampm(opts.hours.to)}`;
    views();
    render();
    $("roomPills").addEventListener("click", (e) => {
      const b = e.target.closest("[data-room]");
      if (!b) return;
      state.room = b.dataset.room ? Number(b.dataset.room) : null;
      cal.refetchEvents();
    });
    $("bookBtn")?.addEventListener("click", () => F.bookWindow({ defaults: { room_id: state.room || undefined }, onDone: () => cal.refetchEvents() }));
    $("roomsBtn")?.addEventListener("click", () => F.roomsWindow({ onDone: async () => ((opts = await F.options(true)), cal.refetchEvents()) }));
    // From a ministry's page: its weekly meeting, ready to book.
    if (params.get("new") && CTX.can.book) {
      const m = opts.ministries.find((x) => String(x.id) === params.get("ministry"));
      const next = new Date();
      if (m && m.meets_day !== null) next.setDate(next.getDate() + ((m.meets_day - next.getDay() + 7) % 7));
      const end = m?.meets_time ? `${String(Number(m.meets_time.slice(0, 2)) + 2).padStart(2, "0")}:${m.meets_time.slice(3, 5)}` : "12:00";
      const until = new Date(next.getTime() + 12 * 7 * 86400000);
      F.bookWindow({ defaults: m ? { purpose: m.name, ministry_id: m.id, date: F.iso(next), start: m.meets_time || "10:00", end, repeat: "weekly", repeat_until: F.iso(until) } : {}, onDone: () => cal.refetchEvents() });
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
