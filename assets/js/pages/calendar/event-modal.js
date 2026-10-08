/**
 * ============================================================================
 * CALENDAR - the event windows (docs/specs/calendar-spec.md)
 * ============================================================================
 * CalendarMeta: what each layer (CCI, Diocese, Region, Ours, Below) and kind
 * looks like. CalendarEventModal.details(occurrence) shows one event -
 * "CCI calendar event" when it is one - with Edit / Delete when the place
 * may change it; CalendarEventModal.form(event, opts) adds or edits one.
 * ============================================================================
 */
const CalendarMeta = (function () {
  "use strict";

  const LAYERS = {
    cci: { label: "CCI calendar", short: "CCI", colour: "danger", icon: "ri-government-line" },
    diocese: { label: "Diocese", short: "Diocese", colour: "primary", icon: "ri-building-4-line" },
    region: { label: "Region", short: "Region", colour: "purple", icon: "ri-map-2-line" },
    ours: { label: "Ours", short: "Ours", colour: "success", icon: "ri-home-heart-line" },
    below: { label: "Churches below", short: "Below", colour: "secondary", icon: "ri-community-line" },
  };
  const KIND_ICONS = {
    conference: "ri-team-line",
    fasting_prayer: "ri-hand-heart-line",
    service: "ri-book-open-line",
    meeting: "ri-group-line",
    deadline: "ri-alarm-warning-line",
    holiday: "ri-sun-line",
    celebration: "ri-cake-2-line",
    other: "ri-calendar-line",
    // Church life items (C3) - read from Events, Initiatives, Settings and Budgets.
    event: "ri-calendar-check-line",
    session: "ri-seedling-line",
    service: "ri-book-open-line",
    due: "ri-alarm-warning-line",
  };
  /** What the calendar can show besides its own events (docs/specs/calendar-spec.md, C3). */
  const SOURCES = {
    calendar: { label: "Calendar", icon: "ri-calendar-event-line", colour: "primary" },
    events: { label: "Events", icon: "ri-calendar-check-line", colour: "purple" },
    sessions: { label: "Initiative sessions", icon: "ri-seedling-line", colour: "success" },
    services: { label: "Services", icon: "ri-book-open-line", colour: "pink" },
    due: { label: "Due dates", icon: "ri-alarm-warning-line", colour: "secondary" },
  };
  const REPEATS = { none: "Doesn't repeat", weekly: "Every week", monthly: "Every month", yearly: "Every year" };

  return { LAYERS, KIND_ICONS, REPEATS, SOURCES };
})();

const CalendarEventModal = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");

  function shell() {
    let el = document.getElementById("calModal");
    if (el) return el;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="calModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="calModalTitle">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon bg-primary" id="calModalIcon"><i class="ri-calendar-event-line"></i></span>
              <div class="flex-fill" style="min-width:0"><h5 class="modal-title" id="calModalTitle">Event</h5><div class="app-modal-subtitle" id="calModalSub">&nbsp;</div></div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="calModalBody"></div>
            <div class="modal-footer" id="calModalFoot"></div>
          </div>
        </div>
      </div>`,
    );
    return document.getElementById("calModal");
  }

  function open({ icon, colour, title, sub, body, foot }) {
    const el = shell();
    const ic = el.querySelector("#calModalIcon");
    ic.className = `app-modal-icon bg-${colour} ${textOn(colour)}`;
    ic.innerHTML = `<i class="${icon}"></i>`;
    el.querySelector("#calModalTitle").textContent = title;
    el.querySelector("#calModalSub").innerHTML = sub;
    el.querySelector("#calModalBody").innerHTML = body;
    el.querySelector("#calModalFoot").innerHTML = foot;
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  const fmtDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", year: "numeric" });
  const dayBefore = (iso) => {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() - 1);
    return d.toISOString().slice(0, 10);
  };

  /** "Mon 14 Aug 2026 - Thu 17 Aug 2026" / "Fri 20 Mar 2026, 09:00 - 15:00" */
  function whenText(o) {
    if (o.all_day) {
      const last = dayBefore(o.end);
      return last === o.start ? fmtDate(o.start) : `${fmtDate(o.start)} - ${fmtDate(last)}`;
    }
    const [sd, st] = o.start.split("T");
    const [ed, et] = o.end.split("T");
    return sd === ed ? `${fmtDate(sd)}, ${st}${et && et !== st ? ` - ${et}` : ""}` : `${fmtDate(sd)} ${st} - ${fmtDate(ed)} ${et}`;
  }

  /** One event, view only - with Edit / Delete when this place may change it. */
  function details(o, { kinds, onEdit, onDelete }) {
    const layer = CalendarMeta.LAYERS[o.layer] || CalendarMeta.LAYERS.below;
    const kindLabel = kinds[o.kind] || o.kind;
    const facts = [
      ["When", whenText(o)],
      ["Kind", kindLabel],
      ...(o.repeats !== "none" ? [["Repeats", CalendarMeta.REPEATS[o.repeats]]] : []),
      ...(o.location ? [["Where", o.location]] : []),
      ["From", o.owner?.name || layer.label],
    ];
    const el = open({
      icon: CalendarMeta.KIND_ICONS[o.kind] || "ri-calendar-line",
      colour: layer.colour,
      title: o.title,
      sub:
        o.layer === "cci"
          ? `<span class="badge bg-danger"><i class="ri-government-line me-1"></i>CCI calendar event</span> · ${esc(o.owner?.name || "Christian Church International")}`
          : `<span class="badge bg-${layer.colour} ${textOn(layer.colour)}">${layer.label}</span> · ${esc(o.owner?.name || "")}`,
      body: `
        <div class="cal-facts">${facts.map(([k, v]) => `<div><span>${esc(k)}</span><b>${esc(v)}</b></div>`).join("")}</div>
        ${o.description ? `<p class="mt-3 mb-0 cal-description">${esc(o.description)}</p>` : ""}
        ${!o.can_edit ? `<div class="alert alert-primary d-flex gap-2 align-items-center mt-3 mb-0"><i class="ri-eye-line"></i><span>View only - ${o.layer === "cci" ? "the CCI calendar is kept by the national office." : `only ${esc(o.owner?.name || "the place that added it")} can change it.`}</span></div>` : ""}`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>${
        o.can_edit ? '<button type="button" class="btn btn-danger" id="calDelete"><i class="ri-delete-bin-6-line me-1"></i>Delete</button><button type="button" class="btn btn-primary" id="calEdit"><i class="ri-edit-line me-1"></i>Edit</button>' : ""
      }`,
    });
    el.querySelector("#calEdit")?.addEventListener("click", () => onEdit?.(o));
    el.querySelector("#calDelete")?.addEventListener("click", () => {
      Toast.confirm(
        `Delete "${esc(o.title)}"${o.repeats !== "none" ? " and every time it repeats" : ""}?`,
        async () => {
          const res = await CalendarAPI.remove(o.event_id);
          if (!res.ok) return Toast.error(res.message);
          bootstrap.Modal.getInstance(el)?.hide();
          Toast.success("Event deleted.");
          onDelete?.();
        },
        null,
        { title: "Delete event", confirmText: "Delete", cancelText: "Keep it", type: "error" },
      );
    });
  }

  /** Where a church-life item's button leads, worded for what you'll do there. */
  function actionFor(o) {
    const url = o.url || "";
    if (o.source === "events") return { label: "Open the event", icon: "ri-calendar-check-line" };
    if (o.source === "sessions") return { label: "Open the initiative", icon: "ri-seedling-line" };
    if (o.source === "services") return { label: "See service times", icon: "ri-time-line" };
    if (url.includes("monthly-reports")) return { label: o.status === "late" ? "Write it now" : "Open the report", icon: "ri-file-text-line" };
    if (url.includes("contributions")) return { label: "Record what was sent", icon: "ri-hand-coin-line" };
    if (url.includes("budget")) return { label: "Prepare the budget", icon: "ri-wallet-3-line" };
    return { label: "Open", icon: "ri-arrow-right-line" };
  }

  /**
   * Events, initiative sessions, services and due dates: a preview first,
   * then one button to the page where it's handled (they used to jump
   * straight there, so a click on the calendar seemed to do nothing).
   */
  function preview(o, { siteUrl = "", colour = "primary" } = {}) {
    const src = CalendarMeta.SOURCES[o.source] || CalendarMeta.SOURCES.calendar;
    const action = actionFor(o);
    const facts = [
      ["When", whenText(o)],
      ["What", o.source === "due" ? "Due date" : src.label.replace(/s$/, "")],
      ...(o.location ? [["Where", o.location]] : []),
      ...(o.owner?.name ? [["From", o.owner.name]] : []),
      ...(o.status === "late" ? [["Status", "Late"]] : o.status === "draft" ? [["Status", "Draft"]] : []),
    ];
    open({
      icon: CalendarMeta.KIND_ICONS[o.kind] || src.icon,
      colour,
      title: o.title,
      sub: `<span class="badge bg-${colour} ${textOn(colour)}">${esc(src.label)}</span>${o.status === "late" ? ' <span class="badge bg-danger">Late</span>' : ""}`,
      body: `
        <div class="cal-facts">${facts.map(([k, v]) => `<div><span>${esc(k)}</span><b>${esc(v)}</b></div>`).join("")}</div>
        ${o.description ? `<p class="mt-3 mb-0 cal-description">${esc(o.description)}</p>` : ""}`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>${
        o.url ? `<a class="btn btn-primary" href="${esc(siteUrl + o.url)}"><i class="${action.icon} me-1"></i>${esc(action.label)}</a>` : ""
      }`,
    });
  }

  // One colour (2026-10-08): every section of the window in the brand colour.
  function panel(icon, colour, title, body) {
    colour = "primary";
    return `
      <section class="cal-panel">
        <div class="cal-panel-head"><span class="avatar avatar-sm bg-${colour} ${textOn(colour)}"><i class="${icon}"></i></span><span class="fw-semibold">${title}</span></div>
        ${body}
      </section>`;
  }

  /**
   * Add (event = null) or edit an event.
   * opts: {kinds, level, cci (a CCI calendar event), date (preset day), onSaved}
   */
  function form(event, { kinds, level, cci = false, date = null, onSaved }) {
    const e = event || {};
    const start = e.starts_on || date || new Date().toISOString().slice(0, 10);
    const allDay = e.all_day ?? true;
    const repeats = e.repeats || "none";
    // The diocese and the CCI calendar always share; a church or region chooses.
    const askShare = !cci && level !== "diocese";
    const shared = e.shared_below ?? level !== "church";
    const el = open({
      icon: cci ? "ri-government-line" : "ri-calendar-event-line",
      colour: cci ? "danger" : "primary",
      title: event ? `Edit "${e.title}"` : cci ? "Add to the CCI calendar" : "Add an event",
      sub: cci ? "Every church, region and the diocese will see it, marked as a CCI calendar event" : "On your calendar - and, if you choose, the places below",
      body: `
        ${panel("ri-file-text-line", "primary", "What", `
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label" for="evTitle">Title</label><input class="form-control" id="evTitle" name="title" maxlength="160" placeholder="${cci ? "e.g. National Prayer and Fasting Week" : "e.g. Harvest Thanksgiving"}" value="${esc(e.title || "")}"><div class="invalid-feedback" data-error-for="title"></div></div>
            <div class="col-md-4"><label class="form-label" for="evKind">Kind</label><select class="form-select" id="evKind" name="kind">${Object.entries(kinds)
              .map(([k, l]) => `<option value="${k}" data-icon="${CalendarMeta.KIND_ICONS[k]}" data-color="primary"${(e.kind || "other") === k ? " selected" : ""}>${esc(l)}</option>`)
              .join("")}</select></div>
            <div class="col-12"><label class="form-label" for="evDesc">Details <span class="fw-normal">(optional)</span></label><textarea class="form-control" id="evDesc" name="description" rows="2" maxlength="2000" placeholder="What people should know">${esc(e.description || "")}</textarea></div>
          </div>`)}
        ${panel("ri-time-line", "purple", "When", `
          <div class="row g-3">
            <div class="col-sm-6"><label class="form-label" for="evStart">Starts</label><input type="date" class="form-control" id="evStart" name="starts_on" value="${esc(start)}"><div class="invalid-feedback" data-error-for="starts_on"></div></div>
            <div class="col-sm-6"><label class="form-label" for="evEnd">Ends <span class="fw-normal">(for more than a day)</span></label><input type="date" class="form-control" id="evEnd" name="ends_on" value="${esc(e.ends_on && e.ends_on !== start ? e.ends_on : "")}"><div class="invalid-feedback" data-error-for="ends_on"></div></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="evAllDay"${allDay ? " checked" : ""}><label class="form-check-label" for="evAllDay">All day</label></div></div>
            <div class="col-sm-6 ev-times"${allDay ? " hidden" : ""}><label class="form-label" for="evStartTime">From</label><input type="time" class="form-control" id="evStartTime" name="start_time" value="${esc(e.start_time || "")}"><div class="invalid-feedback" data-error-for="start_time"></div></div>
            <div class="col-sm-6 ev-times"${allDay ? " hidden" : ""}><label class="form-label" for="evEndTime">To</label><input type="time" class="form-control" id="evEndTime" name="end_time" value="${esc(e.end_time || "")}"><div class="invalid-feedback" data-error-for="end_time"></div></div>
            <div class="col-sm-6"><label class="form-label" for="evRepeats">Repeats</label><select class="form-select" id="evRepeats" name="repeats">${Object.entries(CalendarMeta.REPEATS)
              .map(([k, l]) => `<option value="${k}"${repeats === k ? " selected" : ""}>${l}</option>`)
              .join("")}</select></div>
            <div class="col-sm-6 ev-until"${repeats === "none" ? " hidden" : ""}><label class="form-label" for="evUntil">Until <span class="fw-normal" id="evUntilNote">${repeats === "yearly" ? "(optional)" : ""}</span></label><input type="date" class="form-control" id="evUntil" name="repeat_until" value="${esc(e.repeat_until || "")}"><div class="invalid-feedback" data-error-for="repeat_until"></div></div>
          </div>`)}
        ${panel("ri-map-pin-line", "success", "Where", `<input class="form-control" id="evLocation" name="location" maxlength="160" placeholder="e.g. Church grounds, or Nairobi" value="${esc(e.location || "")}">`)}
        ${
          askShare
            ? panel("ri-eye-line", "pink", "Who sees it", `
          <div class="d-flex flex-wrap gap-4">
            <div class="form-check"><input class="form-check-input" type="radio" name="evShare" id="evShareNo" value="0"${shared ? "" : " checked"}><label class="form-check-label" for="evShareNo">Us only</label></div>
            <div class="form-check"><input class="form-check-input" type="radio" name="evShare" id="evShareYes" value="1"${shared ? " checked" : ""}><label class="form-check-label" for="evShareYes">Us and ${level === "region" ? "our churches" : "the places below"}</label></div>
          </div>`)
            : ""
        }`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn ${cci ? "btn-danger" : "btn-primary"}" id="evSave"><i class="ri-check-line me-1"></i>${event ? "Save changes" : cci ? "Add to the CCI calendar" : "Add event"}</button>`,
    });
    const $ = (s) => el.querySelector(s);
    UI.enhanceSelect($("#evKind"), { dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    UI.enhanceSelect($("#evRepeats"), { dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    $("#evAllDay").addEventListener("change", () => el.querySelectorAll(".ev-times").forEach((x) => (x.hidden = $("#evAllDay").checked)));
    $("#evRepeats").addEventListener("change", () => {
      const r = $("#evRepeats").value;
      $(".ev-until").hidden = r === "none";
      $("#evUntilNote").textContent = r === "yearly" ? "(optional)" : "";
    });
    setTimeout(() => $("#evTitle").focus(), 300);

    $("#evSave").addEventListener("click", async (ev) => {
      const btn = ev.currentTarget;
      el.querySelectorAll("[data-error-for]").forEach((x) => (x.textContent = ""));
      el.querySelectorAll(".is-invalid").forEach((x) => x.classList.remove("is-invalid"));
      const allDayNow = $("#evAllDay").checked;
      const body = {
        title: $("#evTitle").value.trim(),
        kind: $("#evKind").value,
        description: $("#evDesc").value.trim() || null,
        starts_on: $("#evStart").value,
        ends_on: $("#evEnd").value || null,
        all_day: allDayNow,
        start_time: allDayNow ? null : $("#evStartTime").value || null,
        end_time: allDayNow ? null : $("#evEndTime").value || null,
        location: $("#evLocation").value.trim() || null,
        repeats: $("#evRepeats").value,
        repeat_until: $("#evRepeats").value === "none" ? null : $("#evUntil").value || null,
        ...(askShare ? { shared_below: el.querySelector('input[name="evShare"]:checked')?.value === "1" } : {}),
        ...(cci && !event ? { cci: true } : {}),
      };
      UI.setButtonLoading(btn, "Saving…");
      const res = event ? await CalendarAPI.update(event.id, body) : await CalendarAPI.create(body);
      UI.restoreButton(btn);
      if (!res.ok) {
        Object.entries(res.errors || {}).forEach(([k, msgs]) => {
          el.querySelector(`[name="${k}"]`)?.classList.add("is-invalid");
          const box = el.querySelector(`[data-error-for="${k}"]`);
          if (box) box.textContent = [].concat(msgs)[0];
        });
        if (!res.errors) Toast.error(res.message);
        return;
      }
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message || "Saved.");
      onSaved?.(res.data);
    });
  }

  /** The event fields of an occurrence the API marked editable (its "base" is the event, not this occurrence). */
  function eventFromOccurrence(o) {
    return { id: o.event_id, title: o.title, kind: o.kind, description: o.description, location: o.location, repeats: o.repeats, all_day: o.all_day, shared_below: o.shared_below, ...(o.base || {}) };
  }

  return { details, preview, form, eventFromOccurrence, whenText, esc };
})();

window.CalendarMeta = CalendarMeta;
window.CalendarEventModal = CalendarEventModal;
