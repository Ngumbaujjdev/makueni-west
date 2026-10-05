/**
 * ============================================================================
 * EVENTS AND INITIATIVES - shared look (list, form and event pages)
 * ============================================================================
 * Status pills, a type's icon and colour, the date block, money and dates in
 * words, the event card of the list, and a small confirm / text window.
 * ============================================================================
 */
const EventsUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const CTX = window.EVENTS_CTX || {};
  const IS_INITIATIVE = CTX.kind === "initiative";
  /** The words for this page's kind. */
  const NOUN = IS_INITIATIVE
    ? { one: "initiative", One: "Initiative", many: "initiatives", Many: "Initiatives", page: "initiative" }
    : { one: "event", One: "Event", many: "events", Many: "Events", page: "event" };
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");

  const STATUS = {
    draft: { label: "Draft", color: "secondary", icon: "ri-draft-line" },
    published: { label: IS_INITIATIVE ? "Running" : "Open", color: "primary", icon: "ri-broadcast-line" },
    completed: { label: "Done", color: "success", icon: "ri-checkbox-circle-line" },
    cancelled: { label: "Cancelled", color: "danger", icon: "ri-close-circle-line" },
  };
  const TYPE_ICONS = {
    sunday_service: "ri-sun-line", midweek: "ri-book-open-line", prayer_meeting: "ri-hand-heart-line", youth_kesha: "ri-moon-clear-line",
    revival: "ri-fire-line", wedding: "ri-hearts-line", funeral: "ri-leaf-line", fundraising: "ri-hand-coin-line",
    special_service: "ri-star-line", conference: "ri-team-line", leadership_meeting: "ri-group-line", youth_convention: "ri-user-star-line",
    womens: "ri-women-line", mens: "ri-men-line", prayer_conference: "ri-hand-heart-line", worship_night: "ri-music-2-line",
    outreach: "ri-road-map-line", training: "ri-book-2-line", celebration: "ri-cake-2-line", other: "ri-calendar-event-line",
    bible_study: "ri-book-open-line", discipleship: "ri-user-heart-line", prayer_group: "ri-hand-heart-line", youth_programme: "ri-user-star-line",
    children_programme: "ri-emotion-happy-line", welfare: "ri-heart-pulse-line", pastors_training: "ri-book-2-line",
    leadership_training: "ri-team-line", evangelism: "ri-volume-up-line",
  };
  const GROUPS = { youth: "Youth", adults: "Adults", children: "Children", leaders: "Leaders" };
  const GROUP_COLORS = { youth: "purple", adults: "primary", children: "secondary", leaders: "success" };

  const typeIcon = (type) => TYPE_ICONS[type] || "ri-calendar-event-line";
  const typeColor = (type) => UI.colorFor(type);
  const statusPill = (status) => {
    const s = STATUS[status] || STATUS.draft;
    return UI.pill(s.label, s.color, s.icon);
  };

  const money = (v) => `KES ${Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
  const num = (v) => Number(v || 0).toLocaleString();
  const d = (iso) => new Date(iso);
  const sameDay = (a, b) => a.toDateString() === b.toDateString();
  const time = (dt) => dt.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  const longDate = (dt) => dt.toLocaleDateString(undefined, { weekday: "short", day: "numeric", month: "short", year: "numeric" });
  const shortDate = (dt) => dt.toLocaleDateString(undefined, { day: "numeric", month: "short" });

  /** "Sat 14 Mar 2026 · 9:00 AM - 4:00 PM", or the two days when it runs over several. */
  function when(startsIso, endsIso) {
    const s = d(startsIso);
    const e = d(endsIso);
    return sameDay(s, e) ? `${longDate(s)} · ${time(s)} - ${time(e)}` : `${shortDate(s)} - ${longDate(e)}`;
  }

  /** "in 5 days", "tomorrow", "today", "3 days ago" */
  function relative(iso) {
    const start = new Date(d(iso).toDateString());
    const today = new Date(new Date().toDateString());
    const days = Math.round((start - today) / 86400000);
    if (days === 0) return "Today";
    if (days === 1) return "Tomorrow";
    if (days === -1) return "Yesterday";
    return days > 0 ? `In ${days} days` : `${-days} days ago`;
  }

  function dateBlock(iso, color = "primary") {
    const dt = d(iso);
    return `<div class="ev-date bg-${color} ${textOn(color)}"><span>${dt.toLocaleDateString(undefined, { month: "short" })}</span><strong>${dt.getDate()}</strong></div>`;
  }

  /** One event in the list: date block, type, status, where and who's coming. */
  function eventCard(it) {
    const color = typeColor(it.type);
    const past = d(it.ends_at) < new Date();
    const coming = it.totals
      ? it.totals.came != null
        ? `<span><i class="ri-user-follow-line"></i>${num(it.totals.came)} came</span>`
        : it.totals.places
          ? `<span><i class="ri-group-line"></i>${num(it.totals.expected)} ${IS_INITIATIVE ? "taking part" : "coming"} from ${num(it.totals.places)} ${it.totals.places === 1 ? "place" : "places"}</span>`
          : ""
      : "";
    let mine = "";
    if (it.relation === "invited") {
      mine = it.mine?.status === "registered"
        ? `<span class="soft-chip soft-success"><i class="ri-checkbox-circle-line"></i>${IS_INITIATIVE ? "Joined" : "Registered"} · ${num(it.mine.expected)}</span>`
        : it.registration_open
          ? `<span class="soft-chip soft-danger"><i class="ri-mail-unread-line"></i>${IS_INITIATIVE ? "Not joined" : "Not registered"}${it.register_by ? ` · by ${shortDate(d(it.register_by))}` : ""}</span>`
          : "";
    }
    const from = it.relation === "own" ? "" : `<span><i class="ri-building-4-line"></i>${esc(it.owner.name)}</span>`;
    const s = it.sessions;
    const progress =
      IS_INITIATIVE && s
        ? `<div class="ev-progress mt-2"><div class="d-flex justify-content-between"><span>${num(s.held)} of ${num(s.total)} sessions held</span>${s.next ? `<span>Next ${shortDate(d(s.next + "T12:00:00"))}</span>` : ""}</div><div class="progress progress-sm mt-1"><div class="progress-bar bg-${color}" style="width:${s.total ? Math.round((s.held / s.total) * 100) : 0}%"></div></div></div>`
        : "";
    return `
      <div class="col-xxl-4 col-lg-6" data-ev-card data-status="${it.status}" data-type="${it.type}" data-search="${esc(`${it.title} ${it.type_label} ${it.venue || ""} ${it.owner.name}`.toLowerCase())}">
        <a class="card custom-card ev-card h-100${past ? " is-past" : ""}" href="${CTX.baseUrl}/${NOUN.page}?id=${it.id}">
          <div class="card-body d-flex gap-3">
            ${dateBlock(it.starts_at, color)}
            <div class="flex-fill" style="min-width:0">
              <div class="d-flex align-items-start gap-2 mb-1">
                <h6 class="ev-card-title flex-fill mb-0">${esc(it.title)}</h6>
                ${statusPill(it.status)}
              </div>
              <div class="ev-card-meta">
                <span class="soft-chip soft-${color}"><i class="${typeIcon(it.type)}"></i>${esc(it.type_label)}</span>
                ${IS_INITIATIVE ? `<span><i class="ri-repeat-line"></i>${esc(meets(it))}</span>` : `<span><i class="ri-time-line"></i>${relative(it.starts_at)}</span>`}
                ${it.venue ? `<span><i class="ri-map-pin-line"></i>${esc(it.venue)}</span>` : ""}
                ${from}
                ${coming}
              </div>
              ${progress}
              ${mine ? `<div class="mt-2">${mine}</div>` : ""}
            </div>
          </div>
        </a>
      </div>`;
  }

  function empty(icon, title, text, action = "") {
    return `<div class="col-12"><div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-primary text-white mb-2"><i class="${icon} fs-20"></i></span><h6 class="mb-1">${title}</h6><p class="mb-3">${text}</p>${action}</div></div>`;
  }

  /** A small window: a message, an optional text box, and one action. Resolves to the text (or true), or null when closed. */
  function ask({ title, text, icon = "ri-question-line", color = "primary", action = "OK", actionColor = "primary", textarea = null, input = null }) {
    document.getElementById("evAskModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="evAskModal" tabindex="-1" aria-labelledby="evAskTitle">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon bg-${color} ${textOn(color)}"><i class="${icon}"></i></span>
              <div class="flex-fill"><h5 class="modal-title" id="evAskTitle">${esc(title)}</h5></div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <p class="mb-${textarea || input ? "3" : "0"}">${text}</p>
              ${input ? `<label class="form-label" for="evAskInput">${esc(input.label)}</label><input type="number" class="form-control" id="evAskInput" min="0" step="1" value="${esc(input.value ?? "")}">` : ""}
              ${textarea ? `<label class="form-label" for="evAskText">${esc(textarea.label)}</label><textarea class="form-control" id="evAskText" rows="5" maxlength="5000" placeholder="${esc(textarea.placeholder || "")}"></textarea>` : ""}
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Not now</button>
              <button type="button" class="btn btn-${actionColor}" id="evAskGo">${action}</button>
            </div>
          </div>
        </div>
      </div>`,
    );
    const el = document.getElementById("evAskModal");
    const modal = new bootstrap.Modal(el);
    return new Promise((resolve) => {
      let answer = null;
      el.querySelector("#evAskGo").addEventListener("click", () => {
        answer = input ? el.querySelector("#evAskInput").value : textarea ? el.querySelector("#evAskText").value.trim() : true;
        modal.hide();
      });
      el.addEventListener("hidden.bs.modal", () => {
        el.remove();
        resolve(answer);
      });
      el.addEventListener("shown.bs.modal", () => el.querySelector("#evAskInput, #evAskText")?.focus());
      modal.show();
    });
  }

  /** "Every Wednesday · 6:00 PM" */
  function meets(item) {
    const days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    const parts = [item.frequency_label || ""];
    if ((item.frequency === "weekly" || item.frequency === "fortnightly") && item.meeting_day != null) parts[0] = `${item.frequency === "weekly" ? "Every" : "Every other"} ${days[item.meeting_day]}`;
    if (item.starts_at) parts.push(time(d(item.starts_at)));
    return parts.filter(Boolean).join(" · ");
  }

  return { IS_INITIATIVE, NOUN, meets, esc, textOn, STATUS, GROUPS, GROUP_COLORS, typeIcon, typeColor, statusPill, money, num, when, relative, longDate, shortDate, dateBlock, eventCard, empty, ask };
})();
