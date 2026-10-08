/**
 * ============================================================================
 * FACILITIES - the page (index.php)
 * ============================================================================
 * Four cards (bookings this week, repairs, equipment, loans), today and this
 * week's bookings by day, who is on duty at the next service, what needs
 * attention (urgent repairs, broken things, loans not back) and our rooms.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const K = PeopleKit;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  let data = null;

  function cards(o) {
    const good = o.equipment_kinds ? Math.round(((o.equipment_kinds - o.equipment_poor) / o.equipment_kinds) * 100) : 100;
    K.statRow($("statCardsRow"), [
      { icon: "ri-calendar-check-line", label: "Bookings this week", sub: `${F.num(o.bookings_last_week)} last week`, value: F.num(o.bookings_this_week), color: "primary", delta: UI.periodDelta(o.bookings_this_week, o.bookings_last_week, { prevLabel: "last week" }), series: { labels: o.weeks, data: o.bookings_series } },
      { icon: "ri-tools-line", label: "Repairs to do", sub: o.repairs_urgent ? `${F.num(o.repairs_urgent)} urgent` : `${F.num(o.repairs_done_month)} fixed this month`, value: F.num(o.repairs_open), color: "danger" },
      { icon: "ri-archive-line", label: "Equipment", sub: `${F.num(o.equipment_kinds)} kinds · ${F.num(o.equipment_poor)} poor or broken`, value: F.num(o.equipment_items), color: "success", bar: { pct: good, text: `${good}% in good shape` } },
      { icon: "ri-hand-coin-line", label: "Out on loan", sub: o.loans_overdue ? `${F.num(o.loans_overdue)} not back on time` : "All on time", value: F.num(o.loans_out), color: "purple" },
    ]);
  }

  function agenda(o) {
    if (!o.agenda.length) {
      $("agenda").innerHTML = MembersUI.empty("ri-calendar-line", "Nothing booked this week", "Book a room for a meeting, a practice or an event.", CTX.can.book ? '<button type="button" class="btn btn-primary" data-book><i class="ri-calendar-check-line me-1"></i>Book a room</button>' : "");
      return;
    }
    const byDay = {};
    o.agenda.forEach((b) => (byDay[b.date] = byDay[b.date] || []).push(b));
    const today = F.todayIso();
    $("agenda").innerHTML = Object.entries(byDay)
      .map(
        ([d, list]) => `<div class="fx-agenda-day">
          <div class="fx-agenda-head"><strong>${d === today ? "Today" : F.day(d, { weekday: "long" })}</strong><span>${F.day(d, { day: "numeric", month: "short" })}</span><span class="badge bg-primary ms-auto">${list.length}</span></div>
          <ul class="fx-agenda-list">${list
            .map(
              (b) => `<li data-key="${b.key}" role="button" tabindex="0">
                <span class="fx-agenda-time">${F.ampm(b.start_time)}<small>${F.ampm(b.end_time)}</small></span>
                <i class="fx-agenda-bar bg-${b.room?.colour || "primary"}"></i>
                <div class="flex-fill min-w-0"><strong>${F.esc(b.purpose)}</strong><small>${F.esc(b.room?.name || "")}${b.booker ? ` · ${F.esc(b.booker)}` : ""}</small></div>
                ${b.ministry ? `<span class="soft-chip soft-${b.ministry.colour}"><i class="${b.ministry.icon}"></i>${F.esc(b.ministry.name)}</span>` : b.activity ? '<span class="soft-chip soft-pink"><i class="ri-calendar-event-line"></i>Event</span>' : ""}
                ${b.repeat === "weekly" ? '<i class="ri-repeat-line text-primary" title="Every week"></i>' : ""}
              </li>`,
            )
            .join("")}</ul>
        </div>`,
      )
      .join("");
  }

  function duty(o) {
    const d = o.duty;
    $("dutySub").textContent = `${F.day(d.date, { weekday: "long", day: "numeric", month: "short" })}`;
    if (!d.rows.length) {
      $("duty").innerHTML = '<p class="mb-0 fw-semibold">No service that day.</p>';
      return;
    }
    $("duty").innerHTML = d.rows
      .map((r) => {
        const tiles = o.duties
          .map((x) => {
            const people = d.cells[`${r.date}|${r.service}|${x.key}`] || [];
            return `<div class="fx-duty-row"><span class="avatar avatar-sm avatar-rounded bg-${x.color} ${x.color === "warning" ? "text-dark" : "text-white"} flex-shrink-0"><i class="${x.icon}"></i></span><div class="min-w-0 flex-fill"><small>${F.esc(x.label)}</small><div class="fx-duty-people">${people.length ? people.map((p) => `<span class="fx-person"><span class="avatar avatar-xs avatar-rounded bg-${UI.colorFor(p.name)} text-white">${F.esc(p.initials)}</span>${F.esc(p.name.split(" ")[0])}</span>`).join("") : '<span class="fx-nobody">Nobody yet</span>'}</div></div></div>`;
          })
          .join("");
        return `<div class="fx-duty-service"><div class="fx-duty-service-head"><i class="ri-building-4-line"></i>${F.esc(r.service)} <span class="mb-sub">· ${F.ampm(r.start)}</span></div>${tiles}</div>`;
      })
      .join("");
  }

  function attention(o) {
    const a = o.attention;
    const group = (title, icon, color, rows) =>
      `<div class="fx-attn"><div class="fx-attn-head"><span class="avatar avatar-sm avatar-rounded bg-${color} ${color === "warning" ? "text-dark" : "text-white"}"><i class="${icon}"></i></span><strong>${title}</strong><span class="badge bg-primary ms-auto">${rows.length}</span></div>${rows.length ? `<ul class="mb-mini-list">${rows.join("")}</ul>` : '<p class="mb-0 mb-sub ps-1">Nothing here.</p>'}</div>`;
    $("attention").innerHTML = `<div class="fx-attn-grid">
      ${group("Urgent repairs", "ri-alarm-warning-line", "danger", a.repairs.map((j) => `<li><div class="flex-fill min-w-0"><a class="fw-semibold mb-link" href="${CTX.baseUrl}/repairs">${F.esc(j.title)}</a><small>${F.esc(j.equipment?.name || j.room?.name || "")}${j.days_open !== null ? ` · ${j.days_open} ${j.days_open === 1 ? "day" : "days"} open` : ""}</small></div>${F.statusPill(j.status)}</li>`))}
      ${group("Broken", "ri-error-warning-line", "warning", a.broken.map((e) => `<li><div class="flex-fill min-w-0"><a class="fw-semibold mb-link" href="${CTX.baseUrl}/item?id=${e.id}">${F.esc(e.name)}</a></div>${F.conditionPill("broken")}</li>`))}
      ${group("Loans not back", "ri-hand-coin-line", "purple", a.overdue.map((l) => `<li><div class="flex-fill min-w-0"><a class="fw-semibold mb-link" href="${CTX.baseUrl}/item?id=${l.equipment_id}">${F.esc(l.equipment)}</a><small>${F.esc(l.to_name)} · due ${F.day(l.due_on)}</small></div><span class="badge bg-danger">Late</span></li>`))}
    </div>`;
  }

  function rooms(o) {
    $("rooms").innerHTML = o.rooms.length
      ? `<div class="fx-room-tiles">${o.rooms
          .map((r) => `<a class="fx-room-tile" href="${CTX.baseUrl}/bookings?room=${r.id}"><i class="fx-room-swatch bg-${r.colour}"></i><div class="min-w-0"><strong>${F.esc(r.name)}</strong><small>${r.capacity ? `Holds ${F.num(r.capacity)}` : "Any size"}${r.bookable ? "" : " · not booked"}</small></div><span class="fx-room-count${r.today ? " is-busy" : ""}">${r.today}<small>today</small></span></a>`)
          .join("")}</div>`
      : MembersUI.empty("ri-door-open-line", "No rooms yet", "Add the church's rooms to book them.", CTX.can.manage ? '<button type="button" class="btn btn-primary" data-rooms><i class="ri-add-line me-1"></i>Add rooms</button>' : "");
  }

  async function load() {
    const res = await FacilitiesAPI.overview();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      return;
    }
    data = res.data;
    cards(data);
    agenda(data);
    duty(data);
    attention(data);
    rooms(data);
  }

  function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    const book = () => F.bookWindow({ onDone: () => load() });
    $("bookBtn")?.addEventListener("click", book);
    $("reportBtn").addEventListener("click", () => F.reportWindow({ onDone: () => load() }));
    $("roomsBtn")?.addEventListener("click", () => F.roomsWindow({ onDone: () => load() }));
    document.addEventListener("click", (e) => {
      if (e.target.closest("[data-book]")) book();
      if (e.target.closest("[data-rooms]")) F.roomsWindow({ onDone: () => load() });
      const li = e.target.closest("#agenda [data-key]");
      if (li && data) F.bookingWindow(data.agenda.find((b) => b.key === li.dataset.key), { onDone: () => load() });
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
