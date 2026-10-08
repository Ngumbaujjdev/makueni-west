/**
 * ============================================================================
 * CALENDAR - the event windows (docs/specs/calendar-spec.md)
 * ============================================================================
 * CalendarMeta: what each layer (CCI, Diocese, Region, Ours, Below) and kind
 * looks like. CalendarEventModal.details(occurrence) shows one event -
 * "CCI calendar" when it is one - with Edit / Delete when the place may
 * change it; preview() shows an event, session, service or due date with one
 * button to its page; form(event, opts) adds or edits one. The two view
 * windows (2026-10-08) open with a solid header in the entry's colour and
 * list their facts as rows with icon tiles.
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
    calendar: { label: "Calendar", one: "Calendar date", icon: "ri-calendar-event-line", colour: "primary" },
    events: { label: "Events", one: "Event", icon: "ri-calendar-check-line", colour: "purple" },
    sessions: { label: "Initiative sessions", one: "Initiative session", icon: "ri-seedling-line", colour: "success" },
    services: { label: "Services", one: "Service", icon: "ri-book-open-line", colour: "pink" },
    due: { label: "Due dates", one: "Due date", icon: "ri-alarm-warning-line", colour: "secondary" },
  };
  const REPEATS = { none: "Doesn't repeat", weekly: "Every week", monthly: "Every month", yearly: "Every year" };

  return { LAYERS, KIND_ICONS, REPEATS, SOURCES };
})();

const CalendarEventModal = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" || c === "pink" ? "text-dark" : "text-white");

  function shell() {
    let el = document.getElementById("calModal");
    if (el) return el;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="calModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="calModalTitle">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down" id="calModalDialog">
          <div class="modal-content">
            <div class="modal-header" id="calModalHead"></div>
            <div class="modal-body" id="calModalBody"></div>
            <div class="modal-footer" id="calModalFoot"></div>
          </div>
        </div>
      </div>`,
    );
    return document.getElementById("calModal");
  }

  /**
   * banner: the view windows - a solid header in the entry's colour with a
   * pill, the title and when. Without it: the form's usual icon + title.
   */
  function open({ icon, colour, title, sub, body, foot, banner = null }) {
    const el = shell();
    const head = el.querySelector("#calModalHead");
    el.querySelector("#calModalDialog").classList.toggle("cal-ev-dialog", !!banner);
    el.querySelector("#calModalDialog").classList.toggle("modal-lg", !banner);
    if (banner) {
      head.className = `modal-header cal-ev-head bg-${colour} ${textOn(colour)}`;
      head.innerHTML = `
        <div class="cal-ev-head-top">
          <span class="cal-ev-pill" style="--c: var(--${colour}-rgb)"><i class="${icon}"></i>${banner.pill}</span>
          <button type="button" class="cal-ev-close" data-bs-dismiss="modal" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <h5 class="modal-title" id="calModalTitle">${esc(title)}</h5>
        <div class="cal-ev-when">${esc(banner.when)}</div>`;
    } else {
      head.className = "modal-header";
      head.innerHTML = `
        <span class="app-modal-icon bg-${colour} ${textOn(colour)}"><i class="${icon}"></i></span>
        <div class="flex-fill" style="min-width:0"><h5 class="modal-title" id="calModalTitle">${esc(title)}</h5><div class="app-modal-subtitle">${sub}</div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>`;
    }
    el.querySelector("#calModalBody").innerHTML = body;
    el.querySelector("#calModalFoot").innerHTML = foot;
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  const pad = (n) => String(n).padStart(2, "0");
  /** A Date as YYYY-MM-DD in local time (toISOString would shift it to UTC - a day early in Nairobi). */
  const isoLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const fmtDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", year: "numeric" });
  const dayBefore = (iso) => {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() - 1);
    return isoLocal(d);
  };

  /** "Mon 14 Aug 2026 - Thu 17 Aug 2026" / "Fri 20 Mar 2026, 09:00 - 15:00" */
  function whenText(o) {
    if (o.all_day) {
      // The API's all-day end is the day after (FullCalendar's exclusive end).
      const last = o.end ? dayBefore(o.end.slice(0, 10)) : o.start;
      return last <= o.start ? fmtDate(o.start) : `${fmtDate(o.start)} - ${fmtDate(last)}`;
    }
    const [sd, st] = o.start.split("T");
    const [ed, et] = (o.end || o.start).split("T");
    return sd === ed ? `${fmtDate(sd)}, ${st}${et && et !== st ? ` - ${et}` : ""}` : `${fmtDate(sd)} ${st} - ${fmtDate(ed)} ${et}`;
  }

  /** The facts as rows with an icon tile each, tinted in the entry's colour. */
  function rows(list, colour) {
    return `<div class="cal-rows" data-colour="${colour}" style="--c: var(--${colour}-rgb)">${list
      .map(([icon, k, v]) => `<div class="cal-row"><span class="cal-row-icon"><i class="${icon}"></i></span><div><span>${esc(k)}</span><b>${esc(v)}</b></div></div>`)
      .join("")}</div>`;
  }
  const note = (colour, icon, text) => `<div class="cal-note" data-colour="${colour}" style="--c: var(--${colour}-rgb)"><i class="${icon}"></i><span>${text}</span></div>`;

  /** One event, view only - with Edit / Delete when this place may change it. */
  function details(o, { kinds, onEdit, onDelete }) {
    const layer = CalendarMeta.LAYERS[o.layer] || CalendarMeta.LAYERS.below;
    const kindLabel = kinds[o.kind] || o.kind;
    const facts = [
      ["ri-time-line", "When", whenText(o)],
      ["ri-price-tag-3-line", "Kind", kindLabel],
      ...(o.location ? [["ri-map-pin-line", "Where", o.location]] : []),
      ["ri-building-line", "Kept by", o.owner?.name || layer.label],
      ...(o.repeats && o.repeats !== "none" ? [["ri-repeat-line", "Repeats", CalendarMeta.REPEATS[o.repeats]]] : []),
    ];
    const el = open({
      icon: o.layer === "cci" ? layer.icon : CalendarMeta.KIND_ICONS[o.kind] || "ri-calendar-line",
      colour: layer.colour,
      title: o.title,
      banner: { pill: esc(layer.label), when: whenText(o) },
      body: `
        ${rows(facts, layer.colour)}
        ${o.description ? `<p class="mt-3 mb-0 cal-description">${esc(o.description)}</p>` : ""}
        ${!o.can_edit ? note(layer.colour, "ri-eye-line", `View only - ${o.layer === "cci" ? "the CCI calendar is kept by the national office." : `only ${esc(o.owner?.name || "the place that added it")} can change it.`}`) : ""}`,
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

  /** What the note under a church-life item says about where it's kept. */
  function keptNote(o) {
    if (o.source === "services") return "Service times are set in Settings › Service times.";
    if (o.source === "sessions") return "Attendance for this session is taken on the initiative page.";
    if (o.source === "due") return "Due dates come from Monthly reports and Budgets.";
    return "";
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
      ["ri-time-line", "When", whenText(o)],
      ["ri-price-tag-3-line", "What", src.one],
      ...(o.location ? [["ri-map-pin-line", "Where", o.location]] : []),
      ...(o.owner?.name ? [["ri-building-line", "Kept by", o.owner.name]] : []),
      ...(o.status === "late" ? [["ri-alarm-warning-line", "Status", "Late"]] : o.status === "draft" ? [["ri-draft-line", "Status", "Draft"]] : []),
    ];
    const kept = keptNote(o);
    open({
      icon: CalendarMeta.KIND_ICONS[o.kind] || src.icon,
      colour,
      title: o.title,
      banner: { pill: `${esc(src.one)}${o.status === "late" ? " · Late" : ""}`, when: whenText(o) },
      body: `
        ${rows(facts, colour)}
        ${o.description ? `<p class="mt-3 mb-0 cal-description">${esc(o.description)}</p>` : ""}
        ${kept ? note(colour, "ri-information-line", esc(kept)) : ""}`,
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

  /** New date: a calendar date here, or an event / initiative on their own forms. */
  function choices(links) {
    const opt = (value, icon, title, sub, checked) => `
      <label class="ec-choice">
        <input type="radio" name="calWhat" value="${value}"${checked ? " checked" : ""}>
        <span class="ec-choice-icon"><i class="${icon}"></i></span>
        <span><strong class="d-block">${title}</strong><small>${sub}</small></span>
        <span class="ec-choice-tick"><i class="ri-check-line"></i></span>
      </label>`;
    return `
      <div class="ec-choices cal-what mb-3" role="radiogroup" aria-label="What are you adding?">
        ${opt("date", "ri-calendar-event-line", "Calendar date", "Made right here", true)}
        ${links.event ? opt("event", "ri-calendar-check-line", "Event", "With registrations") : ""}
        ${links.initiative ? opt("initiative", "ri-seedling-line", "Initiative", "A run of sessions") : ""}
      </div>
      <div class="cal-what-other mb-3" id="calWhatOther" hidden>
        <strong class="d-block mb-1" id="calWhatTitle"></strong>
        <span id="calWhatBody"></span>
      </div>`;
  }
  const OTHER = {
    event: { title: "Events have their own form", body: "Name, poster, capacity and registration all live on the event form. It shows on this calendar once it's saved.", go: "Continue to the event form" },
    initiative: { title: "Initiatives have their own form", body: "Plan every session at once - for example 20 Wednesdays at 18:00. Each one shows on this calendar.", go: "Continue to the initiative form" },
  };

  /**
   * Add (event = null) or edit an event.
   * opts: {kinds, level, cci (a CCI calendar event), date (preset day),
   *        links ({event, initiative} - the New date choices), onSaved}
   */
  function form(event, { kinds, level, cci = false, date = null, links = null, onSaved }) {
    const e = event || {};
    const start = e.starts_on || date || isoLocal(new Date());
    const allDay = e.all_day ?? true;
    const repeats = e.repeats || "none";
    // The diocese and the CCI calendar always share; a church or region chooses.
    const askShare = !cci && level !== "diocese";
    const shared = e.shared_below ?? level !== "church";
    const withChoices = !event && !cci && links && (links.event || links.initiative);
    const el = open({
      icon: cci ? "ri-government-line" : "ri-calendar-event-line",
      colour: cci ? "danger" : "primary",
      title: event ? `Edit "${e.title}"` : cci ? "Add to the CCI calendar" : "New date",
      sub: cci ? "Every church, region and the diocese will see it, marked as a CCI calendar event" : event ? "On your calendar - and, if you choose, the places below" : `On ${esc(fmtDate(start))}, for ${level === "church" ? "our church" : level === "region" ? "our region" : "the diocese"}`,
      body: `
        ${withChoices ? choices(links) : ""}
        <div id="evFields">
        ${panel("ri-file-text-line", "primary", "What", `
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label" for="evTitle">Title</label><input class="form-control" id="evTitle" name="title" maxlength="160" placeholder="${cci ? "e.g. National Prayer and Fasting Week" : "e.g. Church Council meeting"}" value="${esc(e.title || "")}"><div class="invalid-feedback" data-error-for="title"></div></div>
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
        }
        </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><a class="btn btn-primary d-none" id="evContinue" href="#"><i class="ri-arrow-right-line me-1"></i><span>Continue</span></a><button type="button" class="btn ${cci ? "btn-danger" : "btn-primary"}" id="evSave"><i class="ri-check-line me-1"></i>${event ? "Save changes" : cci ? "Add to the CCI calendar" : "Save date"}</button>`,
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
    // The choices: a calendar date stays here; an event or initiative goes to its own form.
    el.querySelectorAll('input[name="calWhat"]').forEach((r) =>
      r.addEventListener("change", () => {
        const what = el.querySelector('input[name="calWhat"]:checked').value;
        const other = OTHER[what];
        $("#evFields").hidden = !!other;
        $("#calWhatOther").hidden = !other;
        $("#evSave").classList.toggle("d-none", !!other);
        $("#evContinue").classList.toggle("d-none", !other);
        if (other) {
          $("#calWhatTitle").textContent = other.title;
          $("#calWhatBody").textContent = other.body;
          $("#evContinue span").textContent = other.go;
          $("#evContinue").href = links[what];
        }
      }),
    );
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

  return { details, preview, form, eventFromOccurrence, whenText, esc, isoLocal };
})();

window.CalendarMeta = CalendarMeta;
window.CalendarEventModal = CalendarEventModal;
