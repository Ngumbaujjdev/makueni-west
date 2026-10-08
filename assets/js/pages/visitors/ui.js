/**
 * ============================================================================
 * VISITORS - shared look (docs/specs/people-and-care-spec.md, P2)
 * ============================================================================
 * The stages (one colour each, the board's columns), follow-up types and
 * outcomes, and "when is it due" in words. Avatars, dates and empty states
 * come from MembersUI.
 * ============================================================================
 */
const VisitorsUI = (function () {
  "use strict";

  const M = MembersUI;

  const STAGES = {
    new: { label: "New", icon: "ri-star-smile-line", color: "primary", hint: "First visit - nobody has followed up yet" },
    contacted: { label: "Contacted", icon: "ri-phone-line", color: "info", hint: "Someone has reached them" },
    returning: { label: "Returning", icon: "ri-repeat-line", color: "warning", hint: "They came back" },
    regular: { label: "Regular", icon: "ri-calendar-check-line", color: "success", hint: "Here most Sundays" },
    member: { label: "Became a member", icon: "ri-home-heart-line", color: "purple", hint: "Joined the church" },
  };
  const TYPES = {
    call: { label: "Phone call", icon: "ri-phone-line", color: "primary" },
    sms: { label: "SMS", icon: "ri-chat-3-line", color: "info" },
    visit: { label: "Home visit", icon: "ri-home-heart-line", color: "success" },
    met: { label: "Met at church", icon: "ri-community-line", color: "purple" },
  };
  const OUTCOMES = {
    reached: { label: "Reached them", color: "success" },
    will_come: { label: "Will come again", color: "success" },
    no_answer: { label: "No answer", color: "secondary" },
    not_interested: { label: "Not interested", color: "danger" },
    sent: { label: "Message sent", color: "info" },
    other: { label: "Other", color: "secondary" },
  };

  const stagePill = (s) => {
    const m = STAGES[s] || { label: s, color: "light" };
    return `<span class="badge bg-${m.color} ${M.textOn(m.color)}">${M.esc(m.label)}</span>`;
  };

  const todayIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  };
  const daysBetween = (a, b) => Math.round((new Date(`${b}T12:00:00`) - new Date(`${a}T12:00:00`)) / 86400000);

  /** "Due today", "2 days late", "Due Fri 10 Oct" - and how it should look. */
  function due(iso) {
    if (!iso) return { text: "Nothing due", tone: "none", key: "none" };
    const d = daysBetween(todayIso(), iso);
    if (d < 0) return { text: `${-d} ${d === -1 ? "day" : "days"} late`, tone: "late", key: "due" };
    if (d === 0) return { text: "Due today", tone: "today", key: "due" };
    if (d === 1) return { text: "Due tomorrow", tone: "soon", key: "later" };
    return { text: `Due ${new Date(`${iso}T12:00:00`).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" })}`, tone: "soon", key: "later" };
  }
  const dueChip = (iso) => {
    const d = due(iso);
    if (d.tone === "none") return "";
    const cls = d.tone === "late" ? "vs-due is-late" : d.tone === "today" ? "vs-due is-today" : "vs-due";
    return `<span class="${cls}"><i class="${d.tone === "late" ? "ri-alarm-warning-line" : "ri-time-line"}"></i>${d.text}</span>`;
  };
  const ordinal = (n) => `${n}${["th", "st", "nd", "rd"][n % 10 > 3 || Math.floor((n % 100) / 10) === 1 ? 0 : n % 10]}`;
  const privateChip = () => '<span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span>';

  /** An .app-modal window (removed when closed); returns the element. */
  function modal({ title, subtitle = "", icon, color = "primary", body, foot, size = "", danger = false }) {
    document.getElementById("vsModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal${danger ? " is-danger" : ""}" id="vsModal" tabindex="-1" aria-labelledby="vsModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down ${size}"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon bg-${color} ${M.textOn(color)}"><i class="${icon}"></i></span><div class="flex-fill"><h5 class="modal-title" id="vsModalTitle">${title}</h5>${subtitle ? `<div class="app-modal-subtitle">${M.esc(subtitle)}</div>` : ""}</div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">${body}</div>
          <div class="modal-footer">${foot}</div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById("vsModal");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  /**
   * "Became a member": the same person moves into the register - their name,
   * phone and area come with them; the window asks only gender and Sunday
   * school or main church. onDone(person) runs once they're a member;
   * onCancel when the window closes without it.
   */
  function becomeMember(person, membersUrl, { onDone = () => {}, onCancel = () => {} } = {}) {
    let done = false;
    const first = (person.name || "").split(" ")[0];
    const el = modal({
      title: "Became a member",
      subtitle: person.name,
      icon: "ri-home-heart-line",
      color: "purple",
      body: `<p class="fw-semibold">${M.esc(first)} moves into the member register - the same record, with their visits and follow-ups kept.</p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label mb-2">Gender</label><div class="mb-choice-row" role="radiogroup" aria-label="Gender"><label class="mb-choice"><input type="radio" name="bmGender" value="female"><i class="ri-women-line"></i>Female</label><label class="mb-choice"><input type="radio" name="bmGender" value="male"><i class="ri-men-line"></i>Male</label></div></div>
          <div class="col-md-6"><label class="form-label mb-2">Part of</label><div class="mb-choice-row" role="radiogroup" aria-label="Sunday school or main church"><label class="mb-choice"><input type="radio" name="bmPart" value="main_church" checked><i class="ri-community-line"></i>Main church</label><label class="mb-choice"><input type="radio" name="bmPart" value="sunday_school"><i class="ri-book-open-line"></i>Sunday school</label></div></div>
          <div class="col-md-6"><label class="form-label" for="bmOn">Member from</label><input type="date" class="form-control" id="bmOn" value="${todayIso()}" max="${todayIso()}"></div>
          <div class="col-md-6"><label class="form-label" for="bmHow">How they joined</label><select class="form-select" id="bmHow">${Object.entries(M.HOW_JOINED).map(([k, v]) => `<option value="${k}"${k === "conversion" ? " selected" : ""}>${v}</option>`).join("")}</select></div>
        </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Not yet</button><button type="button" class="btn btn-primary" id="bmGo"><i class="ri-home-heart-line me-1"></i>Make ${M.esc(first)} a member</button>`,
    });
    DemographicsUI.enhanceSelect(document.getElementById("bmHow"), { search: false });
    el.addEventListener("hidden.bs.modal", () => !done && onCancel());
    document.getElementById("bmGo").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      DemographicsUI.setButtonLoading(btn, "Saving...");
      const pick = (n) => document.querySelector(`input[name="${n}"]:checked`)?.value || null;
      const res = await VisitorsAPI.becomeMember(person.id, { joined_on: document.getElementById("bmOn").value || null, how_joined: document.getElementById("bmHow").value, gender: pick("bmGender"), congregation: pick("bmPart") });
      DemographicsUI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      done = true;
      onDone(res.data);
      el.querySelector(".modal-body").innerHTML = `<div class="text-center py-2"><span class="avatar avatar-lg avatar-rounded bg-success text-white mb-2"><i class="ri-check-line fs-22"></i></span><h6 class="mb-1">${M.esc(person.name)} is a member now</h6><p class="mb-0">They're in Members with their phone and area. Baptism and other dates can be added there later.</p></div>`;
      el.querySelector(".modal-footer").innerHTML = `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button><a class="btn btn-primary" href="${membersUrl}/member?id=${person.id}"><i class="ri-contacts-book-2-line me-1"></i>Open in Members</a>`;
    });
    return el;
  }

  /**
   * Where a visit was: our weekly services ("Sunday morning · Sun 09:00",
   * from Settings > Service times) then the other gatherings - a select with
   * groups. On a date that is a service's day, that service is picked.
   */
  function gatheringOptions(choices, dateIso, selected = null) {
    const day = dateIso ? new Date(`${dateIso}T12:00:00`).getDay() : null;
    const pick = selected ?? choices.find((c) => c.day !== null && c.day === day)?.key ?? "";
    const groups = [...new Set(choices.map((c) => c.group))];
    return `<option value="">Not at a gathering</option>${groups
      .map((g) => `<optgroup label="${M.esc(g)}">${choices.filter((c) => c.group === g).map((c) => `<option value="${M.esc(c.key)}"${c.key === pick ? " selected" : ""}>${M.esc(c.label)}</option>`).join("")}</optgroup>`)
      .join("")}`;
  }

  return { STAGES, TYPES, OUTCOMES, stagePill, due, dueChip, todayIso, daysBetween, ordinal, privateChip, modal, becomeMember, gatheringOptions };
})();

window.VisitorsUI = VisitorsUI;
