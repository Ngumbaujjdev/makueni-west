/**
 * ============================================================================
 * FACILITIES - shared look and windows (P5)
 * ============================================================================
 * Rooms in their colours, conditions and repair statuses, and the windows -
 * in numbered steps beside a live preview, saving through a spinner to a
 * done view (the MinistriesUI window kit):
 *   bookWindow()     - book a room: that room's day drawn beside the form, the
 *                      new booking green when free, red with the clash named
 *   bookingWindow()  - one booking: who, when, for what; change or cancel
 *   roomsWindow()    - add and change rooms (those who manage)
 *   itemWindow()     - add or change a piece of equipment: what it is, where,
 *                      what it cost and where it was bought, its photos
 *   lendWindow()     - lend it to someone in the register or a name - or, with
 *                      ask: true, ask to borrow it (a manager answers)
 *   askRow()         - one ask to borrow, with Agree / Decline / Take back
 *   decideAsk()      - agree to an ask, or decline it with a reason
 *   recordPurchase() - the Budgets Record money window, filled in for it
 *   linkWindow()     - link a Budgets entry already recorded
 *   reportWindow()   - report a repair (anyone who sees the facilities)
 *   repairWindow()   - move a repair along, who is on it, what it cost
 *   rotaWindow()     - who is on one duty at one service
 * ============================================================================
 */
const FacilitiesUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const N = MinistriesUI;
  const esc = N.esc;
  const num = N.num;
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");
  const money = (v) => (v === null || v === undefined ? "-" : `KES ${Number(v).toLocaleString("en-GB", { maximumFractionDigits: 0 })}`);
  /** Money in a small space: KES 7.5k, KES 1.2m. */
  const short = (v) => (v === null || v === undefined ? "-" : v >= 1000000 ? `KES ${(v / 1000000).toFixed(1).replace(/\.0$/, "")}m` : v >= 1000 ? `KES ${(v / 1000).toFixed(1).replace(/\.0$/, "")}k` : money(v));
  const pad = (n) => String(n).padStart(2, "0");
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const todayIso = () => iso(new Date());
  const day = (s, opts = { weekday: "short", day: "numeric", month: "short" }) => (s ? new Date(`${s.slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", opts) : "-");
  const ampm = (t) => {
    if (!t) return "";
    const [h, m] = t.split(":").map(Number);
    return `${((h + 11) % 12) + 1}:${pad(m)} ${h < 12 ? "am" : "pm"}`;
  };
  const minutes = (t) => {
    const [h, m] = String(t || "0:0").split(":").map(Number);
    return h * 60 + (m || 0);
  };

  const CONDITION = { good: ["Good", "success"], fair: ["Fair", "primary"], poor: ["Poor", "warning"], broken: ["Broken", "danger"] };
  const conditionPill = (c) => `<span class="badge bg-${(CONDITION[c] || CONDITION.good)[1]} ${textOn((CONDITION[c] || CONDITION.good)[1])}">${(CONDITION[c] || CONDITION.good)[0]}</span>`;
  const STATUS = { reported: ["Reported", "ri-flag-line", "warning"], in_progress: ["In progress", "ri-tools-line", "primary"], done: ["Done", "ri-checkbox-circle-line", "success"] };
  const statusPill = (s) => `<span class="badge bg-${(STATUS[s] || STATUS.reported)[2]} ${textOn((STATUS[s] || STATUS.reported)[2])}">${(STATUS[s] || STATUS.reported)[0]}</span>`;
  const roomDot = (r) => (r ? `<span class="fx-room"><i class="bg-${r.colour}"></i>${esc(r.name)}</span>` : '<span class="mb-sub">No room</span>');
  const tile = (icon, color, size = "md") => `<span class="avatar avatar-${size} avatar-rounded bg-${color} ${textOn(color)} flex-shrink-0"><i class="${icon}"></i></span>`;

  let optionsCache = null;
  async function options(fresh = false) {
    if (!optionsCache || fresh) {
      const res = await FacilitiesAPI.options();
      if (!res.ok) {
        Toast.error(res.message);
        return null;
      }
      optionsCache = res.data;
    }
    return optionsCache;
  }

  /** A person picker: search the register (members and visitors), or type a name. picked: [{person_id?, name}] */
  function personPicker(el, { id, picked = [], many = true, placeholder = "Search the register - or type a name" }) {
    const box = el.querySelector(`#${id}`);
    const paint = () => {
      box.querySelector("[data-chips]").innerHTML = picked.length
        ? picked.map((p, i) => `<span class="pp-chip"><span>${esc(p.name)}</span><button type="button" data-unpick="${i}" aria-label="Remove ${esc(p.name)}">&times;</button></span>`).join("")
        : '<span class="mb-sub">Nobody yet</span>';
    };
    box.innerHTML = `<div class="pp-picker-search"><i class="ri-search-line"></i><input type="search" class="form-control mw-input" data-q placeholder="${esc(placeholder)}" autocomplete="off"></div>
      <div class="pp-picker-list" data-found></div><div class="pp-chips" data-chips></div>`;
    const input = box.querySelector("[data-q]");
    let found = [];
    let t = null;
    const add = (p) => {
      if (!many) picked.length = 0;
      if (!picked.some((x) => (p.person_id && x.person_id === p.person_id) || (!p.person_id && x.name.toLowerCase() === p.name.toLowerCase()))) picked.push(p);
      input.value = "";
      box.querySelector("[data-found]").innerHTML = "";
      paint();
      box.dispatchEvent(new Event("change", { bubbles: true }));
    };
    input.addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        const q = input.value.trim();
        if (q.length < 2) return (box.querySelector("[data-found]").innerHTML = "");
        const res = await FacilitiesAPI.people(q);
        found = res.ok ? res.data : [];
        box.querySelector("[data-found]").innerHTML =
          found.map((p) => `<button type="button" class="pp-picker-row w-100 border-0 text-start" data-pick="${p.id}"><span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(p.name)} text-white flex-shrink-0">${esc(p.initials)}</span><span class="flex-fill min-w-0"><strong>${esc(p.name)}</strong><small>${esc([p.kind === "visitor" ? "Visitor" : "Member", p.area].filter(Boolean).join(" · "))}</small></span><i class="ri-add-line"></i></button>`).join("") +
          `<button type="button" class="pp-picker-row w-100 border-0 text-start" data-typed><span class="avatar avatar-sm avatar-rounded bg-light text-dark flex-shrink-0"><i class="ri-edit-2-line"></i></span><span class="flex-fill min-w-0"><strong>Use "${esc(q)}"</strong><small>Not in the register - just the name</small></span></button>`;
      }, 250);
    });
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && input.value.trim().length >= 2) {
        e.preventDefault();
        add({ name: input.value.trim() });
      }
    });
    box.addEventListener("click", (e) => {
      const pick = e.target.closest("[data-pick]");
      if (pick) {
        const p = found.find((x) => x.id === Number(pick.dataset.pick));
        return add({ person_id: p.id, name: p.name });
      }
      if (e.target.closest("[data-typed]")) return add({ name: input.value.trim() });
      const un = e.target.closest("[data-unpick]");
      if (un) {
        picked.splice(Number(un.dataset.unpick), 1);
        paint();
        box.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });
    paint();
    return picked;
  }

  // ---------------------------------------------------------------- book a room
  /**
   * Book a room - or change a booking. defaults {room_id, date, start, end, purpose, ministry_id, repeat, repeat_until}
   */
  async function bookWindow({ booking = null, defaults = {}, onDone } = {}) {
    const o = await options();
    if (!o) return;
    const rooms = o.rooms.filter((r) => r.bookable && r.active);
    if (!rooms.length) return Toast.error("No rooms can be booked yet - add one under Rooms.");
    const b = booking || {};
    const v = {
      room_id: b.room?.id || defaults.room_id || rooms[0].id,
      date: b.first_date || b.date || defaults.date || todayIso(),
      start: b.start_time || defaults.start || "10:00",
      end: b.end_time || defaults.end || "12:00",
      purpose: b.purpose || defaults.purpose || "",
      ministry_id: b.ministry?.id || defaults.ministry_id || "",
      repeat: b.repeat || defaults.repeat || "none",
      repeat_until: b.repeat_until || defaults.repeat_until || "",
    };
    const quick = [
      ["Morning", "09:00", "12:00"],
      ["Afternoon", "14:00", "17:00"],
      ["Evening", "18:00", "20:00"],
    ];
    const el = N.windowEl({
      id: "fcModal",
      title: booking ? "Change this booking" : "Book a room",
      subtitle: booking ? b.purpose : "Pick the room and the time - you'll see straight away if it's free",
      icon: "ri-calendar-check-line",
      size: "modal-xl",
      bodyClass: "p-0",
      body: `<div class="mw-grid">
        <div class="mw-form">
          ${N.step(1, {
            icon: "ri-door-open-line",
            color: "primary",
            title: "Which room",
            help: "Rooms that can be booked",
            body: `<div class="fx-rooms" role="radiogroup" aria-label="Room">${rooms.map((r) => `<label><input type="radio" name="bkRoom" value="${r.id}"${r.id === v.room_id ? " checked" : ""}><span><i class="fx-room-swatch bg-${r.colour}"></i><strong>${esc(r.name)}</strong><small>${r.capacity ? `Holds ${num(r.capacity)}` : "Any size"}</small></span></label>`).join("")}</div>`,
          })}
          ${N.step(2, {
            icon: "ri-time-line",
            color: "warning",
            title: "When",
            help: `Rooms can be booked ${ampm(o.hours.from)} to ${ampm(o.hours.to)}`,
            body: `<div class="mw-fields">
              ${N.field("Date", N.affix("ri-calendar-line", `<input type="date" class="form-control mw-input" id="bkDate" value="${v.date}" min="${booking ? "" : todayIso()}">`), { id: "bkDate" })}
              ${N.field("From", N.affix("ri-time-line", `<input type="time" class="form-control mw-input" id="bkStart" value="${v.start}" step="900">`), { id: "bkStart", wide: false })}
              ${N.field("To", N.affix("ri-time-line", `<input type="time" class="form-control mw-input" id="bkEnd" value="${v.end}" step="900">`), { id: "bkEnd", wide: false })}
              ${N.field("Quick times", `<div class="d-flex flex-wrap gap-2">${quick.map(([l, s, e]) => `<button type="button" class="btn btn-sm btn-light border" data-quick="${s}-${e}">${l} <span class="mb-sub">${ampm(s)}-${ampm(e)}</span></button>`).join("")}</div>`)}
              ${N.field("How often", `<div class="mw-days" role="radiogroup" aria-label="How often"><label><input type="radio" name="bkRepeat" value="none"${v.repeat !== "weekly" ? " checked" : ""}><span>Just this once</span></label><label><input type="radio" name="bkRepeat" value="weekly"${v.repeat === "weekly" ? " checked" : ""}><span>Every week</span></label></div>`, { wide: false })}
              <div class="mw-field" id="bkUntilWrap"${v.repeat === "weekly" ? "" : " hidden"}><label for="bkUntil">Until</label>${N.affix("ri-calendar-event-line", `<input type="date" class="form-control mw-input" id="bkUntil" value="${v.repeat_until}">`)}</div>
            </div>`,
          })}
          ${N.step(3, {
            icon: "ri-file-text-line",
            color: "success",
            title: "What for",
            body: `<div class="mw-fields">
              ${N.field("Purpose", N.affix("ri-edit-2-line", `<input class="form-control mw-input" id="bkPurpose" maxlength="160" value="${esc(v.purpose)}" placeholder="e.g. Choir practice, committee meeting">`), { id: "bkPurpose" })}
              ${N.field("For a ministry", `<select class="form-select" id="bkMinistry"><option value="">None</option>${o.ministries.map((m) => `<option value="${m.id}" data-icon="${m.icon}" data-color="${m.colour}"${String(m.id) === String(v.ministry_id) ? " selected" : ""}>${esc(m.name)}</option>`).join("")}</select>`, { id: "bkMinistry", optional: true })}
            </div>`,
          })}
        </div>
        <aside class="mw-preview" aria-live="polite"><div class="mw-sticky">
          <small class="mw-preview-label" id="bkDayLabel">That day</small>
          <div class="fx-day" id="bkDay"><span class="skel skel-line"></span></div>
          <div class="fx-verdict" id="bkVerdict"></div>
        </div></aside>
      </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="bkSave"><i class="ri-check-line me-1"></i>${booking ? "Save changes" : "Book it"}</button>`,
    });
    UI.enhanceSelect(el.querySelector("#bkMinistry"));
    const read = () => ({
      room_id: Number(el.querySelector('input[name="bkRoom"]:checked').value),
      date: el.querySelector("#bkDate").value,
      start: el.querySelector("#bkStart").value,
      end: el.querySelector("#bkEnd").value,
      repeat: el.querySelector('input[name="bkRepeat"]:checked').value,
      repeat_until: el.querySelector("#bkUntil").value || null,
      purpose: el.querySelector("#bkPurpose").value.trim(),
      ministry_id: el.querySelector("#bkMinistry").value ? Number(el.querySelector("#bkMinistry").value) : null,
    });
    let clash = null;
    let t = null;
    const draw = (data, body) => {
      const room = rooms.find((r) => r.id === body.room_id);
      const [from, to] = [minutes(o.hours.from), minutes(o.hours.to)];
      const span = Math.max(60, to - from);
      const pos = (s, e) => `top:${((Math.max(from, minutes(s)) - from) / span) * 100}%;height:${Math.max(4, ((Math.min(to, minutes(e)) - Math.max(from, minutes(s))) / span) * 100)}%`;
      const hours = [];
      for (let h = Math.ceil(from / 60); h * 60 <= to; h += 2) hours.push(h);
      el.querySelector("#bkDayLabel").textContent = `${room ? room.name : "The room"} · ${day(body.date, { weekday: "long", day: "numeric", month: "short" })}`;
      el.querySelector("#bkDay").innerHTML = `<div class="fx-day-grid">${hours.map((h) => `<span class="fx-day-hour" style="top:${((h * 60 - from) / span) * 100}%">${ampm(`${pad(h)}:00`)}</span>`).join("")}
        ${(data?.day || []).map((x) => `<div class="fx-day-block" style="${pos(x.start_time, x.end_time)}"><strong>${esc(x.purpose)}</strong><small>${ampm(x.start_time)}-${ampm(x.end_time)}</small></div>`).join("")}
        ${body.start && body.end && body.end > body.start ? `<div class="fx-day-block is-new${clash ? " is-clash" : ""}" style="${pos(body.start, body.end)}"><strong>${esc(body.purpose || "Your booking")}</strong><small>${ampm(body.start)}-${ampm(body.end)}</small></div>` : ""}</div>`;
      el.querySelector("#bkVerdict").innerHTML = !data
        ? ""
        : clash
          ? `<div class="fx-verdict-box is-clash"><i class="ri-error-warning-line"></i><div><strong>Already booked then</strong><small>${esc(clash.purpose)} · ${day(clash.date)} ${ampm(clash.start_time)}-${ampm(clash.end_time)}${clash.booker ? ` · ${esc(clash.booker)}` : ""}</small></div></div>`
          : `<div class="fx-verdict-box is-free"><i class="ri-checkbox-circle-line"></i><div><strong>Free - ready to book</strong><small>${data.dates > 1 ? `Checked on all ${data.dates} weeks` : "Nothing else in the room then"}</small></div></div>`;
    };
    const check = () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        const body = read();
        el.querySelector("#bkUntilWrap").hidden = body.repeat !== "weekly";
        if (!body.date || !body.start || !body.end || body.end <= body.start || (body.repeat === "weekly" && !body.repeat_until)) {
          clash = null;
          return draw(null, body);
        }
        const res = await FacilitiesAPI.check({ ...body, except: booking ? booking.id : "" });
        clash = res.ok ? res.data.clash : null;
        draw(res.ok ? res.data : null, body);
        if (!res.ok) el.querySelector("#bkVerdict").innerHTML = `<div class="fx-verdict-box is-clash"><i class="ri-error-warning-line"></i><div><strong>Can't book that</strong><small>${esc(res.message)}</small></div></div>`;
      }, 250);
    };
    el.querySelector(".mw-form").addEventListener("input", check);
    el.querySelector(".mw-form").addEventListener("change", check);
    el.querySelectorAll("[data-quick]").forEach((q) =>
      q.addEventListener("click", () => {
        const [s, e] = q.dataset.quick.split("-");
        el.querySelector("#bkStart").value = s;
        el.querySelector("#bkEnd").value = e;
        check();
      }),
    );
    check();
    el.querySelector("#bkSave").addEventListener("click", () => {
      const body = read();
      if (!body.purpose) {
        el.querySelector("#bkPurpose").focus();
        return Toast.error("Say what the room is for.");
      }
      N.submit(
        el,
        async () => {
          const res = booking ? await FacilitiesAPI.changeBooking(booking.id, body) : await FacilitiesAPI.book(body);
          if (res.status === 409) {
            clash = res.raw?.data?.clash || null;
            check();
          }
          return res;
        },
        (saved, message) => {
          onDone?.(saved);
          return {
            title: message,
            facts: [
              { icon: "ri-door-open-line", text: saved.room?.name || "Room", color: "primary" },
              { icon: "ri-calendar-line", text: `${day(saved.date)} ${ampm(saved.start_time)}-${ampm(saved.end_time)}`, color: "warning" },
              ...(saved.repeat === "weekly" ? [{ icon: "ri-repeat-line", text: `Weekly until ${day(saved.repeat_until)}`, color: "success" }] : []),
            ],
            actions: [
              ...(booking ? [] : [{ label: "Book another", icon: "ri-add-line", run: () => (N.close(el), setTimeout(() => bookWindow({ onDone }), 350)) }]),
              { label: "Done", icon: "ri-check-line", primary: true, run: () => N.close(el) },
            ],
          };
        },
      );
    });
  }

  /** One booking: who, when, what for - and change or cancel it (the booker or a manager). */
  function bookingWindow(b, { onDone } = {}) {
    const ctx = window.FAC_CTX;
    const fact = (icon, color, label, value) => `<div class="pp-fact" style="--q: var(--${color}-rgb)"><span class="pp-fact-icon"><i class="${icon}"></i></span><div class="min-w-0"><span>${label}</span><strong>${value}</strong></div></div>`;
    const el = N.windowEl({
      id: "fcModal",
      title: b.purpose,
      subtitle: b.room ? b.room.name : "",
      icon: "ri-calendar-check-line",
      size: "modal-lg",
      body: `<div class="pp-facts">
          ${fact("ri-calendar-line", "warning", "When", `${day(b.date, { weekday: "long", day: "numeric", month: "long" })}<small>${ampm(b.start_time)} - ${ampm(b.end_time)}</small>`)}
          ${fact("ri-door-open-line", b.room?.colour || "primary", "Room", esc(b.room?.name || "-"))}
          ${fact("ri-repeat-line", "success", "How often", b.repeat === "weekly" ? `Every week<small>Until ${day(b.repeat_until)}</small>` : "Just this once")}
          ${fact("ri-user-line", "purple", "Booked by", esc(b.booker || "-"))}
          ${b.ministry ? fact(b.ministry.icon, b.ministry.colour, "Ministry", `<a class="mb-link" href="${ctx.ministriesUrl}/ministry?id=${b.ministry.id}">${esc(b.ministry.name)}</a>`) : ""}
          ${b.activity ? fact("ri-calendar-event-line", "pink", "For the event", `<a class="mb-link" href="${ctx.eventsUrl}/event?id=${b.activity.id}">${esc(b.activity.title)}</a><small>It moves with the event</small>`) : ""}
        </div>`,
      foot: `${b.can_change ? '<button type="button" class="btn btn-outline-danger me-auto" data-cancel><i class="ri-close-circle-line me-1"></i>Cancel booking</button><button type="button" class="btn btn-outline-primary" data-edit><i class="ri-edit-line me-1"></i>Change</button>' : ""}<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>`,
    });
    el.querySelector("[data-edit]")?.addEventListener("click", () => {
      N.close(el);
      setTimeout(() => bookWindow({ booking: b, onDone }), 350);
    });
    el.querySelector("[data-cancel]")?.addEventListener("click", () => {
      const foot = el.querySelector(".modal-footer");
      foot.innerHTML = `<span class="me-auto fw-semibold text-danger">Cancel ${b.repeat === "weekly" ? "every week of " : ""}this booking?</span><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Keep it</button><button type="button" class="btn btn-danger" data-yes><i class="ri-close-circle-line me-1"></i>Yes, cancel</button>`;
      foot.querySelector("[data-yes]").addEventListener("click", () => N.submit(el, () => FacilitiesAPI.cancelBooking(b.id), (d) => (onDone?.(d), null)));
    });
  }

  /** Rooms: add one, or change one (those who manage). */
  async function roomsWindow({ onDone } = {}) {
    const o = await options(true);
    if (!o) return;
    const row = (r) => `<div class="fx-room-row" data-room="${r ? r.id : ""}">
        <div class="mn-swatches" role="radiogroup" aria-label="Colour">${o.colours.map((c) => `<label><input type="radio" name="rc${r ? r.id : "new"}" value="${c}"${(r?.colour || "primary") === c ? " checked" : ""}><span class="bg-${c}"></span></label>`).join("")}</div>
        <input class="form-control mw-input" data-name maxlength="80" value="${esc(r?.name || "")}" placeholder="${r ? "" : "A new room, e.g. Youth room"}" aria-label="Name">
        <input type="number" class="form-control mw-input" data-cap min="1" value="${r?.capacity || ""}" placeholder="Holds" aria-label="Capacity">
        <div class="form-check form-switch mb-0" title="Can be booked"><input class="form-check-input" type="checkbox" role="switch" data-bookable${!r || r.bookable ? " checked" : ""} aria-label="Can be booked"></div>
        <button type="button" class="btn btn-sm ${r ? "btn-outline-primary" : "btn-primary"}" data-save>${r ? "Save" : '<i class="ri-add-line me-1"></i>Add'}</button>
      </div>`;
    const el = N.windowEl({
      id: "fcModal",
      title: "Rooms",
      subtitle: "Their names, how many they hold, and whether they can be booked",
      icon: "ri-door-open-line",
      size: "modal-lg",
      body: `<div class="mw-form">${N.step(1, { icon: "ri-door-open-line", color: "primary", title: "Our rooms", help: "Switch off \"can be booked\" for rooms like a store or the kitchen", body: `<div class="fx-room-head"><span>Colour</span><span>Name</span><span>Holds</span><span>Bookable</span><span></span></div><div id="rmList">${o.rooms.map(row).join("")}${row(null)}</div>` })}</div>`,
      foot: `<button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="ri-check-line me-1"></i>Done</button>`,
    });
    el.addEventListener("hidden.bs.modal", () => onDone?.());
    el.querySelector("#rmList").addEventListener("click", async (e) => {
      const btn = e.target.closest("[data-save]");
      if (!btn) return;
      const r = btn.closest("[data-room]");
      const id = r.dataset.room ? Number(r.dataset.room) : null;
      const body = { name: r.querySelector("[data-name]").value.trim(), capacity: r.querySelector("[data-cap]").value ? Number(r.querySelector("[data-cap]").value) : null, bookable: r.querySelector("[data-bookable]").checked, colour: r.querySelector('input[type="radio"]:checked')?.value };
      if (!body.name) return Toast.error("Give the room a name.");
      UI.setButtonLoading(btn, "...");
      const res = await FacilitiesAPI.saveRoom(id, body);
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      optionsCache = null;
      if (!id) {
        r.outerHTML = row(res.data) + row(null);
      }
    });
  }

  // ---------------------------------------------------------------- equipment
  /** Add a piece of equipment, or change one - beside a preview of its row. */
  async function itemWindow({ item = null, onDone } = {}) {
    const o = await options();
    if (!o) return;
    const it = item || { category: "sound", condition: "good", quantity: 1 };
    const el = N.windowEl({
      id: "fcModal",
      title: item ? `Change ${item.name}` : "Add equipment",
      subtitle: item ? "Where it is kept, how many and its condition" : "Something the church owns - a mic, chairs, a generator...",
      icon: item ? "ri-edit-line" : "ri-add-box-line",
      size: "modal-xl",
      bodyClass: "p-0",
      body: `<div class="mw-grid">
        <div class="mw-form">
          ${N.step(1, {
            icon: "ri-archive-line",
            color: "primary",
            title: "What it is",
            body: `<div class="mw-fields">${N.field("Name", N.affix("ri-edit-2-line", `<input class="form-control mw-input" id="eqName" maxlength="120" value="${esc(it.name || "")}" placeholder="e.g. Wireless microphone">`), { id: "eqName" })}
              ${N.field("Kind", `<div class="mw-kinds" role="radiogroup" aria-label="Kind">${o.categories.map((c) => `<label><input type="radio" name="eqCat" value="${c.key}"${c.key === it.category ? " checked" : ""}><span><i class="${c.icon}"></i>${esc(c.label)}</span></label>`).join("")}</div>`)}</div>`,
          })}
          ${N.step(2, {
            icon: "ri-map-pin-line",
            color: "warning",
            title: "Where, how many and its condition",
            body: `<div class="mw-fields">
              ${N.field("Kept in", `<select class="form-select" id="eqRoom"><option value="">No room</option>${o.rooms.map((r) => `<option value="${r.id}" data-color="${r.colour}"${it.room?.id === r.id ? " selected" : ""}>${esc(r.name)}</option>`).join("")}</select>`, { id: "eqRoom", wide: false })}
              ${N.field("How many", N.affix("ri-hashtag", `<input type="number" class="form-control mw-input" id="eqQty" min="1" value="${it.quantity || 1}">`), { id: "eqQty", wide: false })}
              ${N.field("Condition", `<div class="mw-days" role="radiogroup" aria-label="Condition">${o.conditions.map((c) => `<label><input type="radio" name="eqCond" value="${c.key}"${c.key === it.condition ? " checked" : ""}><span>${esc(c.label)}</span></label>`).join("")}</div>`)}
            </div>`,
          })}
          ${N.step(3, {
            icon: "ri-money-dollar-circle-line",
            color: "success",
            title: "What it cost",
            help: "So we know what our things are worth - leave out what you don't know",
            body: `<div class="mw-fields">
              ${N.field("Price each (KES)", N.affix("ri-money-dollar-circle-line", `<input type="number" class="form-control mw-input" id="eqValue" min="0" step="100" value="${it.value ?? ""}" placeholder="What one cost">`), { id: "eqValue", optional: true, wide: false })}
              ${N.field("Bought on", N.affix("ri-calendar-line", `<input type="date" class="form-control mw-input" id="eqBought" value="${it.bought_on || ""}" max="${todayIso()}">`), { id: "eqBought", optional: true, wide: false })}
              ${N.field("Where it was bought", N.affix("ri-store-2-line", `<input class="form-control mw-input" id="eqSupplier" maxlength="120" value="${esc(it.supplier || "")}" placeholder="e.g. a music shop in Nairobi">`), { id: "eqSupplier", optional: true })}
            </div>`,
          })}
          ${N.step(4, {
            icon: "ri-camera-line",
            color: "purple",
            title: item ? "Records" : "Photos and records",
            help: item ? "Optional" : "Optional - a photo helps everyone know which one it is",
            body: `<div class="mw-fields">
              ${item ? "" : N.field("Photos", `<label class="gal-drop mb-0" for="eqPhotos"><span class="gal-drop-icon"><i class="ri-image-add-line"></i></span><div><strong>Choose up to 4 photos</strong><small>JPG, PNG or WebP - we stand them upright and make them small.</small></div></label><input type="file" id="eqPhotos" accept="image/png,image/jpeg,image/webp" multiple hidden><div class="fx-pick-thumbs" id="eqPicked"></div>`, { optional: true })}
              ${N.field("Serial number", N.affix("ri-barcode-line", `<input class="form-control mw-input" id="eqSerial" maxlength="80" value="${esc(it.serial || "")}">`), { id: "eqSerial", optional: true, wide: false })}
              ${N.field("Notes", `<textarea class="form-control" id="eqNotes" rows="2" maxlength="500">${esc(it.notes || "")}</textarea>`, { id: "eqNotes", optional: true })}
            </div>`,
          })}
        </div>
        <aside class="mw-preview"><div class="mw-sticky"><small class="mw-preview-label">In the equipment list</small><div class="card custom-card mb-0"><div class="card-body" id="eqPreview"></div></div></div></aside>
      </div>`,
      foot: `${item ? '<button type="button" class="btn btn-outline-danger me-auto" data-remove><i class="ri-delete-bin-line me-1"></i>Remove</button>' : ""}<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="eqSave"><i class="ri-check-line me-1"></i>${item ? "Save changes" : "Add it"}</button>`,
    });
    UI.enhanceSelect(el.querySelector("#eqRoom"));
    const read = () => ({
      name: el.querySelector("#eqName").value.trim(),
      category: el.querySelector('input[name="eqCat"]:checked').value,
      room_id: el.querySelector("#eqRoom").value ? Number(el.querySelector("#eqRoom").value) : null,
      quantity: Number(el.querySelector("#eqQty").value || 1),
      condition: el.querySelector('input[name="eqCond"]:checked').value,
      bought_on: el.querySelector("#eqBought").value || null,
      value: el.querySelector("#eqValue").value === "" ? null : Number(el.querySelector("#eqValue").value),
      serial: el.querySelector("#eqSerial").value.trim() || null,
      notes: el.querySelector("#eqNotes").value.trim() || null,
      supplier: el.querySelector("#eqSupplier").value.trim() || null,
    });
    // Photos picked for a new item - uploaded once it is saved.
    let files = [];
    el.querySelector("#eqPhotos")?.addEventListener("change", (e) => {
      files = [...e.target.files].filter((f) => /^image\//.test(f.type)).slice(0, 4);
      if (e.target.files.length > 4) Toast.warning("Up to 4 photos - the first 4 are kept.");
      el.querySelector("#eqPicked").innerHTML = files.map((f) => `<img src="${URL.createObjectURL(f)}" alt="">`).join("");
      preview();
    });
    const preview = () => {
      const b = read();
      const c = o.categories.find((x) => x.key === b.category);
      const room = o.rooms.find((r) => r.id === b.room_id);
      const pic = files[0] ? URL.createObjectURL(files[0]) : item?.photo?.thumb_url;
      el.querySelector("#eqPreview").innerHTML = `<div class="d-flex align-items-center gap-3">${pic ? `<img class="fx-thumb fx-thumb-lg" src="${pic}" alt="">` : tile(c.icon, c.color)}<div class="flex-fill min-w-0"><strong class="d-block">${esc(b.name || "Your item")}</strong><small class="mb-sub">${esc(c.label)} · ${room ? esc(room.name) : "No room"}</small></div>${conditionPill(b.condition)}</div>
        <div class="mn-facts"><div><small>How many</small><strong>${num(b.quantity)}</strong></div><div><small>Price each</small><strong>${b.value === null ? "-" : short(b.value)}</strong></div><div><small>In all</small><strong>${b.value === null ? "-" : short(b.value * b.quantity)}</strong></div></div>
        <p class="mb-sub mt-2 mb-0">${b.supplier ? `<i class="ri-store-2-line me-1"></i>${esc(b.supplier)}` : "Where it was bought - not said"}${b.bought_on ? ` · ${day(b.bought_on, { day: "numeric", month: "short", year: "numeric" })}` : ""}</p>`;
    };
    el.querySelector(".mw-form").addEventListener("input", preview);
    el.querySelector(".mw-form").addEventListener("change", preview);
    $(el.querySelector("#eqRoom")).on("change", preview);
    preview();
    el.querySelector("[data-remove]")?.addEventListener("click", () => {
      const foot = el.querySelector(".modal-footer");
      foot.innerHTML = `<span class="me-auto fw-semibold text-danger">Remove ${esc(item.name)}? Its history stays.</span><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Keep it</button><button type="button" class="btn btn-danger" data-yes><i class="ri-delete-bin-line me-1"></i>Yes, remove</button>`;
      foot.querySelector("[data-yes]").addEventListener("click", () => N.submit(el, () => FacilitiesAPI.removeItem(item.id), () => (window.location.href = `${window.FAC_CTX.baseUrl}/equipment`)));
    });
    el.querySelector("#eqSave").addEventListener("click", () => {
      const b = read();
      if (!b.name) return Toast.error("Give it a name.");
      N.submit(el, async () => {
        const res = await FacilitiesAPI.saveItem(item ? item.id : null, b);
        if (!res.ok || !files.length) return res;
        const up = await FacilitiesAPI.addPhotos(res.data.id, files);
        if (!up.ok) Toast.warning(`Saved - but the photos didn't go up: ${up.message}`);
        return up.ok ? { ...up, message: res.message } : res;
      }, (saved) => {
        onDone?.(saved);
        return {
          title: item ? `${saved.name} saved` : `${saved.name} added`,
          facts: [{ icon: "ri-hashtag", text: `${num(saved.quantity)} ${saved.quantity === 1 ? "item" : "items"}`, color: "primary" }, { icon: "ri-map-pin-line", text: saved.room ? saved.room.name : "No room", color: "warning" }],
          actions: [...(item ? [] : [{ label: "Add another", icon: "ri-add-line", run: () => (N.close(el), setTimeout(() => itemWindow({ onDone }), 350)) }, { label: "Open it", icon: "ri-arrow-right-line", run: () => (window.location.href = `${window.FAC_CTX.baseUrl}/item?id=${saved.id}`) }]), { label: "Done", icon: "ri-check-line", primary: true, run: () => N.close(el) }],
        };
      });
    });
  }

  /**
   * Lend it out - to someone in the register, or a name (those who manage).
   * ask: true - anyone asks to borrow it for themselves: how many, from when,
   * until when and what for; a manager agrees or declines.
   */
  async function lendWindow(item, { onDone, ask = false } = {}) {
    const o = await options();
    if (!o) return;
    const due = new Date(Date.now() + o.loan_days * 86400000);
    const picked = [];
    const max = ask ? item.quantity : item.available;
    const el = N.windowEl({
      id: "fcModal",
      title: ask ? `Ask to borrow ${item.name}` : `Lend ${item.name} out`,
      subtitle: ask ? "The facilities manager will say yes or no - you'll see the answer on this item" : `${num(item.available)} of ${num(item.quantity)} here to lend`,
      icon: ask ? "ri-question-answer-line" : "ri-hand-coin-line",
      size: "modal-lg",
      body: `<div class="mw-form">
        ${ask ? "" : N.step(1, { icon: "ri-user-line", color: "primary", title: "Who is borrowing it", body: '<div id="lnWho"></div>' })}
        ${N.step(ask ? 1 : 2, {
          icon: "ri-calendar-line",
          color: "warning",
          title: ask ? "How many, and for when" : "How many, and when it comes back",
          body: `<div class="mw-fields">
            ${N.field("How many", N.affix("ri-hashtag", `<input type="number" class="form-control mw-input" id="lnQty" min="1" max="${max}" value="1">`), { id: "lnQty", wide: false })}
            ${ask ? N.field("From", N.affix("ri-calendar-line", `<input type="date" class="form-control mw-input" id="lnFrom" min="${todayIso()}" value="${todayIso()}">`), { id: "lnFrom", wide: false }) : ""}
            ${N.field("Back by", N.affix("ri-calendar-event-line", `<input type="date" class="form-control mw-input" id="lnDue" min="${todayIso()}" value="${iso(due)}">`), { id: "lnDue", wide: false })}
            ${N.field(ask ? "What it's for" : "Note", `<input class="form-control mw-input" id="lnNote" maxlength="300" placeholder="e.g. For the youth outreach">`, { id: "lnNote", optional: !ask })}
          </div>`,
        })}
      </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="lnSave"><i class="ri-${ask ? "send-plane" : "check"}-line me-1"></i>${ask ? "Send my ask" : "Lend it"}</button>`,
    });
    if (!ask) personPicker(el, { id: "lnWho", picked, many: false });
    el.querySelector("#lnSave").addEventListener("click", () => {
      const qty = Number(el.querySelector("#lnQty").value || 1);
      const dueOn = el.querySelector("#lnDue").value || null;
      const note = el.querySelector("#lnNote").value.trim() || null;
      const done = (d, message) => (onDone?.(d), { title: message, facts: [{ icon: "ri-calendar-event-line", text: `Back by ${day(dueOn)}`, color: "warning" }, { icon: "ri-hashtag", text: `${num(qty)} ${qty === 1 ? "item" : "items"}`, color: "primary" }], actions: [{ label: "Done", icon: "ri-check-line", primary: true, run: () => N.close(el) }] });
      if (ask) {
        if (!note) return Toast.error("Say what it's for.");
        return N.submit(el, () => FacilitiesAPI.ask(item.id, { quantity: qty, from: el.querySelector("#lnFrom").value || null, due_on: dueOn, note }), done);
      }
      if (!picked.length) return Toast.error("Pick who is borrowing it, or type their name.");
      const p = picked[0];
      N.submit(el, () => FacilitiesAPI.lend(item.id, { to_person_id: p.person_id || null, to_name: p.person_id ? null : p.name, quantity: qty, due_on: dueOn, note }), done);
    });
  }

  /** One ask to borrow: who, how many, when, what for - and Agree / Decline (managers) or Take back (the one who asked). */
  function askRow(l, { showItem = false, itemUrl = "" } = {}) {
    const what = showItem ? `<a class="fw-semibold mb-link" href="${itemUrl}">${esc(l.equipment)}${l.quantity > 1 ? ` ×${l.quantity}` : ""}</a>` : `<strong>${esc(l.to_name)}${l.quantity > 1 ? ` · ${num(l.quantity)}` : ""}</strong>`;
    return `<div class="fx-loan fx-ask" data-ask="${l.id}">
      <span class="avatar avatar-sm avatar-rounded bg-warning text-dark flex-shrink-0"><i class="ri-question-answer-line"></i></span>
      <div class="flex-fill min-w-0">${what}<small>${showItem ? `${esc(l.to_name)} · ` : ""}${day(l.out_on, { day: "numeric", month: "short" })} → ${day(l.due_on, { day: "numeric", month: "short" })}${l.note ? ` · ${esc(l.note)}` : ""}</small></div>
      <span class="badge bg-warning text-dark">Asked</span>
      ${l.can_decide || l.can_cancel ? `<div class="fx-ask-acts">${l.can_decide ? `<button type="button" class="btn btn-sm btn-outline-danger" data-decline="${l.id}">Decline</button><button type="button" class="btn btn-sm btn-success" data-agree="${l.id}"><i class="ri-check-line me-1"></i>Agree</button>` : `<button type="button" class="btn btn-sm btn-outline-danger" data-takeback="${l.id}">Take back</button>`}</div>` : ""}
    </div>`;
  }

  /** Agree to an ask, decline it (with a reason), or take it back - wired on a container of askRow()s. */
  function wireAsks(box, onDone) {
    box.addEventListener("click", async (e) => {
      const agree = e.target.closest("[data-agree]");
      const decline = e.target.closest("[data-decline]");
      const back = e.target.closest("[data-takeback]");
      if (agree) {
        UI.setButtonLoading(agree, "...");
        const r = await FacilitiesAPI.approve(Number(agree.dataset.agree));
        UI.restoreButton(agree);
        r.ok ? (Toast.success(r.message), onDone(r.data)) : Toast.error(r.message);
      }
      if (decline) {
        PeopleKit.confirmWindow({
          title: "Decline this ask",
          subtitle: "They'll see it was declined, and why",
          icon: "ri-close-circle-line",
          go: '<i class="ri-close-line me-1"></i>Decline',
          body: PeopleKit.parts([{ icon: "ri-chat-3-line", title: "Why (optional)", body: '<input class="form-control mw-input" id="dcWhy" maxlength="200" placeholder="e.g. We need them on Sunday">' }]),
          run: async () => {
            const r = await FacilitiesAPI.decline(Number(decline.dataset.decline), document.getElementById("dcWhy").value.trim() || null);
            if (r.ok) onDone(r.data);
            return r;
          },
        });
      }
      if (back) {
        UI.setButtonLoading(back, "...");
        const r = await FacilitiesAPI.cancelAsk(Number(back.dataset.takeback));
        UI.restoreButton(back);
        r.ok ? (Toast.success(r.message), onDone(r.data)) : Toast.error(r.message);
      }
    });
  }

  /** The Budgets Record money window, filled in for what it cost - then the entry is linked to it. */
  function recordPurchase(item, budget, onDone) {
    if (typeof BudgetsEntryModal === "undefined") return;
    if (!budget) return Toast.error("No budget is in use today - start this month's budget first, or link an entry already recorded.");
    BudgetsEntryModal.open({
      budgetId: budget.id,
      direction: "out",
      prefill: { amount: item.total, description: `Bought: ${item.name}${item.quantity > 1 ? ` ×${item.quantity}` : ""}`, counterparty: item.supplier || "" },
      onSaved: async (entry) => {
        if (!entry?.id) return onDone?.();
        const res = await FacilitiesAPI.linkItemExpense(item.id, entry.id);
        res.ok ? Toast.success(res.message) : Toast.error(res.message);
        onDone?.(res.ok ? res.data : null);
      },
    });
  }

  /** Link a Budgets entry already recorded: our church's money paid out, latest first, searchable. */
  function linkWindow(item, { onDone } = {}) {
    let chosen = null;
    const el = N.windowEl({
      id: "fcModal",
      title: "Link what was paid in Budgets",
      subtitle: `The entry that paid for ${item.name}`,
      icon: "ri-links-line",
      size: "modal-lg",
      body: `<div class="mw-form">${N.step(1, {
        icon: "ri-search-line",
        color: "success",
        title: "Find the entry",
        help: "Money paid out, the latest first - search by what it was for, who was paid or the reference",
        body: `<div class="pp-picker-search mb-2"><i class="ri-search-line"></i><input type="search" class="form-control mw-input" id="lkQ" placeholder="e.g. guitar, music shop" value="${esc(item.name.split(" ")[0])}"></div><div class="fx-entries" id="lkList"></div>`,
      })}</div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="lkSave" disabled><i class="ri-links-line me-1"></i>Link it</button>`,
    });
    const list = el.querySelector("#lkList");
    let t = null;
    const search = async () => {
      list.innerHTML = '<div class="skel skel-line"></div><div class="skel skel-line mt-2"></div>';
      const res = await FacilitiesAPI.expenses(el.querySelector("#lkQ").value.trim());
      if (!res.ok) return (list.innerHTML = `<p class="mb-0 text-danger">${esc(res.message)}</p>`);
      list.innerHTML = res.data.length
        ? res.data
            .map((x) => `<label class="fx-entry${x.taken ? " is-taken" : ""}"><input type="radio" name="lkEntry" value="${x.id}"${x.taken ? " disabled" : ""}><span class="flex-fill min-w-0"><strong>${esc(x.description)}</strong><small>${day(x.date, { day: "numeric", month: "short", year: "numeric" })}${x.counterparty ? ` · ${esc(x.counterparty)}` : ""}${x.reference ? ` · ${esc(x.reference)}` : ""}${x.taken ? " · already linked to another item" : ""}</small></span><strong class="text-nowrap">${money(x.amount)}</strong></label>`)
            .join("")
        : '<p class="mb-0 fw-semibold">Nothing paid out matches - try another word, or record it in Budgets.</p>';
    };
    el.querySelector("#lkQ").addEventListener("input", () => (clearTimeout(t), (t = setTimeout(search, 300))));
    list.addEventListener("change", (e) => {
      chosen = Number(e.target.value);
      el.querySelector("#lkSave").disabled = false;
    });
    el.querySelector("#lkSave").addEventListener("click", () => N.submit(el, () => FacilitiesAPI.linkItemExpense(item.id, chosen), (d) => (onDone?.(d), null)));
    search();
  }

  // ---------------------------------------------------------------- repairs
  /** Report a repair - anyone who sees the facilities. about: {equipment_id} or {room_id} */
  async function reportWindow({ about = {}, onDone } = {}) {
    const o = await options();
    if (!o) return;
    const eq = await FacilitiesAPI.equipment();
    const items = eq.ok ? eq.data.items : [];
    const el = N.windowEl({
      id: "fcModal",
      title: "Report a repair",
      subtitle: "Something broken, leaking or not working",
      icon: "ri-tools-line",
      size: "modal-lg",
      body: `<div class="mw-form">
        ${N.step(1, { icon: "ri-error-warning-line", color: "danger", title: "What's wrong", body: `<div class="mw-fields">${N.field("In a few words", N.affix("ri-edit-2-line", '<input class="form-control mw-input" id="rpTitle" maxlength="160" placeholder="e.g. Generator will not start">'), { id: "rpTitle" })}${N.field("More detail", '<textarea class="form-control" id="rpDetail" rows="2" maxlength="1000"></textarea>', { id: "rpDetail", optional: true })}${N.field("How urgent", '<div class="mw-days" role="radiogroup"><label><input type="radio" name="rpPri" value="normal" checked><span>Can wait a bit</span></label><label><input type="radio" name="rpPri" value="urgent"><span>Urgent</span></label></div>')}</div>` })}
        ${N.step(2, {
          icon: "ri-map-pin-line",
          color: "warning",
          title: "What it's about",
          help: "A piece of equipment, or a room",
          body: `<div class="mw-fields">${N.field("Equipment", `<select class="form-select" id="rpItem"><option value="">None</option>${items.map((i) => `<option value="${i.id}" data-icon="${i.icon}" data-color="${i.colour}"${about.equipment_id === i.id ? " selected" : ""}>${esc(i.name)}</option>`).join("")}</select>`, { id: "rpItem", optional: true, wide: false })}${N.field("Room", `<select class="form-select" id="rpRoom"><option value="">None</option>${o.rooms.map((r) => `<option value="${r.id}" data-color="${r.colour}"${about.room_id === r.id ? " selected" : ""}>${esc(r.name)}</option>`).join("")}</select>`, { id: "rpRoom", optional: true, wide: false })}</div>`,
        })}
      </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rpSave"><i class="ri-check-line me-1"></i>Report it</button>`,
    });
    UI.enhanceSelect(el.querySelector("#rpItem"));
    UI.enhanceSelect(el.querySelector("#rpRoom"));
    el.querySelector("#rpSave").addEventListener("click", () => {
      const title = el.querySelector("#rpTitle").value.trim();
      if (!title) return Toast.error("Say what's wrong.");
      N.submit(el, () => FacilitiesAPI.report({ title, detail: el.querySelector("#rpDetail").value.trim() || null, priority: el.querySelector('input[name="rpPri"]:checked').value, equipment_id: el.querySelector("#rpItem").value ? Number(el.querySelector("#rpItem").value) : null, room_id: el.querySelector("#rpRoom").value ? Number(el.querySelector("#rpRoom").value) : null }), (d) => (onDone?.(d), { title: "Repair reported", facts: [{ icon: "ri-flag-line", text: d.priority === "urgent" ? "Urgent" : "Can wait a bit", color: d.priority === "urgent" ? "danger" : "warning" }], actions: [{ label: "Done", icon: "ri-check-line", primary: true, run: () => N.close(el) }] }));
    });
  }

  /** Move a repair along: its status, who is on it, what it cost. */
  async function repairWindow(job, { onDone, budget = null } = {}) {
    const o = await options();
    if (!o) return;
    const el = N.windowEl({
      id: "fcModal",
      title: job.title,
      subtitle: [job.equipment?.name, job.room?.name, job.reported_by ? `Reported by ${job.reported_by}` : null].filter(Boolean).join(" · "),
      icon: "ri-tools-line",
      size: "modal-lg",
      body: `<div class="mw-form">
        ${job.detail ? `<p class="cr-note mb-3">${esc(job.detail)}</p>` : ""}
        ${N.step(1, { icon: "ri-flag-line", color: "warning", title: "Where it is", body: `<div class="mw-days" role="radiogroup">${o.statuses.map((s) => `<label><input type="radio" name="rjStatus" value="${s.key}"${s.key === job.status ? " checked" : ""}><span>${esc(s.label)}</span></label>`).join("")}</div>` })}
        ${N.step(2, {
          icon: "ri-user-settings-line",
          color: "primary",
          title: "Who is on it, and what it cost",
          body: `<div class="mw-fields">${N.field("On it", `<select class="form-select" id="rjWho"><option value="">Nobody yet</option>${o.leaders.map((u) => `<option value="${u.id}"${job.assigned_to === u.id ? " selected" : ""}>${esc(u.name)}</option>`).join("")}</select>`, { id: "rjWho", optional: true, wide: false })}${N.field("Cost (KES)", N.affix("ri-money-dollar-circle-line", `<input type="number" class="form-control mw-input" id="rjCost" min="0" step="50" value="${job.cost ?? ""}">`), { id: "rjCost", optional: true, wide: false })}${N.field("How urgent", `<div class="mw-days" role="radiogroup"><label><input type="radio" name="rjPri" value="normal"${job.priority !== "urgent" ? " checked" : ""}><span>Can wait a bit</span></label><label><input type="radio" name="rjPri" value="urgent"${job.priority === "urgent" ? " checked" : ""}><span>Urgent</span></label></div>`)}</div>`,
        })}
        ${job.budget_entry_id ? '<div class="alert alert-success d-flex gap-2 mb-0"><i class="ri-checkbox-circle-line fs-16"></i><span>The cost is recorded in the budget.</span></div>' : ""}
      </div>`,
      foot: `${!job.budget_entry_id && budget ? '<button type="button" class="btn btn-outline-success me-auto" data-money><i class="ri-money-dollar-circle-line me-1"></i>Record the cost in the budget</button>' : ""}<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rjSave"><i class="ri-check-line me-1"></i>Save</button>`,
    });
    UI.enhanceSelect(el.querySelector("#rjWho"));
    el.querySelector("#rjSave").addEventListener("click", () =>
      N.submit(el, () => FacilitiesAPI.updateRepair(job.id, { status: el.querySelector('input[name="rjStatus"]:checked').value, priority: el.querySelector('input[name="rjPri"]:checked').value, assigned_to: el.querySelector("#rjWho").value ? Number(el.querySelector("#rjWho").value) : null, cost: el.querySelector("#rjCost").value === "" ? null : Number(el.querySelector("#rjCost").value) }), (d) => (onDone?.(d), null)),
    );
    el.querySelector("[data-money]")?.addEventListener("click", () => {
      N.close(el);
      recordCost(job, budget, onDone);
    });
  }

  /** The Budgets Record money window, filled in for the repair - then the entry is linked to it. */
  function recordCost(job, budget, onDone) {
    if (typeof BudgetsEntryModal === "undefined") return;
    if (!budget) return Toast.error("No budget is in use today - start this month's budget first.");
    BudgetsEntryModal.open({
      budgetId: budget.id,
      direction: "out",
      prefill: { amount: job.cost, description: `Repair: ${job.title}`, counterparty: job.assignee || "" },
      onSaved: async (entry) => {
        if (!entry?.id) return onDone?.();
        const res = await FacilitiesAPI.linkExpense(job.id, entry.id);
        res.ok ? Toast.success(res.message) : Toast.error(res.message);
        onDone?.(res.data);
      },
    });
  }

  // ---------------------------------------------------------------- the rota
  /** Who is on one duty at one service. */
  function rotaWindow({ date, service, duty, people = [], onDone }) {
    const picked = people.map((p) => ({ person_id: p.person_id || null, name: p.name }));
    const el = N.windowEl({
      id: "fcModal",
      title: `${duty.label} · ${service}`,
      subtitle: day(date, { weekday: "long", day: "numeric", month: "long" }),
      icon: duty.icon,
      size: "modal-lg",
      body: `<div class="mw-form">${N.step(1, { icon: duty.icon, color: duty.color, title: "Who is on duty", help: "From the register - or type a name for someone who isn't in it", body: '<div id="rtWho"></div>' })}</div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rtSave"><i class="ri-check-line me-1"></i>Save</button>`,
    });
    personPicker(el, { id: "rtWho", picked });
    el.querySelector("#rtSave").addEventListener("click", () => N.submit(el, () => FacilitiesAPI.saveRota({ on: date, service, duty: duty.key, people: picked }), (d) => (onDone?.(d), null)));
  }

  return { esc, num, money, short, day, ampm, iso, todayIso, minutes, tile, roomDot, conditionPill, statusPill, CONDITION, STATUS, options, personPicker, bookWindow, bookingWindow, roomsWindow, itemWindow, lendWindow, askRow, wireAsks, recordPurchase, linkWindow, reportWindow, repairWindow, recordCost, rotaWindow };
})();

window.FacilitiesUI = FacilitiesUI;
