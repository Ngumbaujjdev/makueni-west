/**
 * ============================================================================
 * PASTORAL CARE - one case (case.php?id=)
 * ============================================================================
 * The hero (who, the kind, status, urgent, when, the hospital) and what you
 * can do - Add a contact, Close or Answered, Home from hospital, Edit; then
 * Timeline (the record and each contact), Details and History. A
 * confidential note shows as a lock to those who may not read it. The tab is
 * kept in the URL; ?act=contact opens Add a contact straight away.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const C = CareUI;
  const CTX = window.CARE_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("id"));
  const TABS = ["timeline", "details", "history"];
  const state = { r: null, tab: TABS.includes(params.get("tab")) ? params.get("tab") : "timeline", history: null };
  const row = (icon, label, html) => `<li class="mr-row"><div class="mr-row-head"><span class="ev-tile is-sm is-soft" style="--q: var(--primary-rgb)"><i class="${icon}"></i></span>${label}</div><p class="mr-row-body">${html}</p></li>`;

  function hero() {
    const r = state.r;
    const t = C.TYPES[r.type] || C.TYPES.concern;
    const open = r.status === "open";
    const can = r.can.change;
    const who = r.person ? `<a class="mb-link" href="${r.person.kind === "visitor" ? CTX.visitorsUrl + "/visitor" : CTX.membersUrl + "/member"}?id=${r.person.id}">${C.esc(r.who)}</a>` : C.esc(r.who);
    $("crHero").innerHTML = `<div class="card-body">
      <div class="ev-hero-row">
        ${C.typeTile(r.type, "xl")}
        <div class="flex-fill min-w-0">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${who}</h2>${C.statusPill(r.status)}${r.priority === "high" && open ? '<span class="badge bg-danger">Urgent</span>' : ""}${r.confidential ? '<span class="soft-chip soft-purple"><i class="ri-lock-2-line"></i>Confidential</span>' : ""}</div>
          <div class="d-flex flex-wrap gap-1 mb-1"><span class="soft-chip soft-${t.color === "secondary" ? "primary" : t.color}"><i class="${t.icon}"></i>${t.label}</span><span class="soft-chip soft-primary"><i class="ri-calendar-line"></i>${C.day(r.on)}</span>${r.hospital ? `<span class="soft-chip soft-danger"><i class="ri-hospital-line"></i>${C.esc(r.hospital)}</span>` : ""}${open && r.next_on ? C.dueChip(r.next_on) : ""}</div>
          <div class="ev-card-meta"><span><i class="ri-team-line"></i>${r.carers.map((c) => C.esc(c.name)).join(", ") || "Nobody named"}</span>${r.person?.area ? `<span><i class="ri-map-pin-line"></i>${C.esc(r.person.area)}</span>` : ""}</div>
        </div>
        <div class="ev-hero-actions">
          ${can ? `<button type="button" class="btn btn-primary" data-act="contact"><i class="ri-add-line me-1"></i>${r.type === "hospital" && open ? "Visit now" : "Add a contact"}</button>` : ""}
          ${can && open && r.type === "hospital" && !r.discharged_on ? '<button type="button" class="btn btn-outline-primary" data-act="discharge"><i class="ri-home-heart-line me-1"></i>Home</button>' : ""}
          ${can && open ? `<button type="button" class="btn btn-outline-primary" data-act="close"><i class="${r.type === "prayer" ? "ri-hand-heart-line" : "ri-checkbox-circle-line"} me-1"></i>${r.type === "prayer" ? "Answered" : "Close"}</button>` : ""}
          ${can ? '<button type="button" class="btn btn-outline-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Edit</button>' : ""}
        </div>
      </div>
      ${!r.can.change && r.confidential ? '<div class="alert alert-secondary d-flex gap-2 mt-3 mb-0"><i class="ri-lock-2-line fs-16"></i><span>This is confidential. Only the pastor who recorded it and the Senior Pastor can read or change it.</span></div>' : ""}
    </div>`;
    $("tabContacts").textContent = r.contacts.length ? `${r.contacts.length} ${r.contacts.length === 1 ? "contact" : "contacts"}` : "Each visit and call";
  }

  function side() {
    const r = state.r;
    $("crSide").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">At a glance</div></div><div class="card-body"><div class="mb-glance">
      <div><span>Opened</span><strong>${C.day(r.on)}</strong></div>
      <div><span>Status</span><strong>${C.STATUS[r.status]?.label || r.status}</strong></div>
      <div><span>Contacts</span><strong>${r.contacts.length}</strong></div>
      <div><span>Next step</span><strong>${r.status === "open" && r.next_on ? C.day(r.next_on) : "-"}</strong></div>
    </div></div></div>
    ${r.status === "answered" && r.testimony ? `<div class="card custom-card"><div class="card-header"><div class="card-title">Testimony</div></div><div class="card-body"><p class="mb-2">${C.esc(r.testimony)}</p>${r.share_testimony ? '<span class="soft-chip soft-success"><i class="ri-share-line"></i>Offered for the monthly report</span>' : '<span class="soft-chip soft-primary"><i class="ri-lock-2-line"></i>Not shared</span>'}</div></div>` : ""}`;
  }

  function timeline() {
    const r = state.r;
    const t = C.TYPES[r.type] || C.TYPES.concern;
    const events = [
      ...r.contacts.map((c) => ({ on: c.on, title: C.CONTACTS[c.type]?.label || "Contact", icon: C.CONTACTS[c.type]?.icon || "ri-more-line", color: "primary", by: c.by, body: c.note ? `<p class="vs-note">${C.esc(c.note)}</p>` : c.note_hidden ? `<p class="cr-note is-locked"><i class="ri-lock-2-line"></i>Confidential</p>` : "", next: c.next_on })),
      { on: r.on, title: `${t.label}${r.hospital ? ` · ${r.hospital}` : ""}`, icon: t.icon, color: t.color, by: r.author, body: C.noteHtml(r), next: null },
    ];
    $("crMain").innerHTML = `<div class="card custom-card"><div class="card-header justify-content-between"><div class="card-title">Timeline</div><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div><div class="card-body">
      <ol class="ev-timeline vs-timeline">${events
        .map((e) => `<li style="--q: var(--${e.color}-rgb)"><span class="ev-timeline-dot"></span><div class="flex-fill min-w-0"><span class="ev-timeline-when">${C.day(e.on)}${e.by ? ` · ${C.esc(e.by)}` : ""}</span><span class="ev-timeline-what fw-semibold"><i class="${e.icon} me-1"></i>${C.esc(e.title)}</span>${e.body}${e.next ? `<span class="mb-sub"><i class="ri-arrow-right-line me-1"></i>Next step ${C.day(e.next)}</span>` : ""}</div></li>`)
        .join("")}</ol></div></div>`;
    side();
  }

  function details() {
    const r = state.r;
    $("crMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">Details</div></div><div class="card-body"><ul class="mr-rows">${[
      row("ri-user-3-line", "Who", C.esc(r.who) + (r.person ? "" : ' <span class="mb-sub">(not in the register)</span>')),
      row("ri-heart-pulse-line", "Kind", C.esc(C.TYPES[r.type]?.label || r.type)),
      row("ri-calendar-line", "When", C.day(r.on)),
      r.type === "hospital" ? row("ri-hospital-line", "Hospital", `${C.esc(r.hospital || "Not given")}${r.discharged_on ? ` · home ${C.day(r.discharged_on)}` : ""}`) : "",
      row("ri-team-line", "Who went", r.carers.map((c) => C.esc(c.name)).join(", ") || "Nobody named"),
      row("ri-lock-2-line", "Confidential", r.confidential ? "Yes - only the author and the Senior Pastor read the notes" : "No"),
      row("ri-sticky-note-line", "Note", r.note ? C.esc(r.note) : r.note_hidden ? "Confidential" : '<span class="mb-sub">None</span>'),
    ].join("")}</ul></div></div>`;
    side();
  }

  async function historyTab() {
    $("crSide").innerHTML = "";
    $("crMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">History</div></div><div class="card-body" id="historyBody"><span class="skel skel-line"></span></div></div>`;
    if (!state.history) {
      const res = await CareAPI.history(id);
      if (!res.ok) return ($("historyBody").innerHTML = MembersUI.errorBox(res.message));
      state.history = res.data;
    }
    $("historyBody").innerHTML = state.history.length
      ? `<ul class="ev-history">${state.history.map((h) => `<li><span class="ev-history-dot bg-${UI.colorFor(h.who)}"></span><div><strong>${C.esc(h.sentence)}</strong><small>${new Date(h.at).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" })}</small></div></li>`).join("")}</ul>`
      : '<p class="mb-0 fw-semibold">Nothing recorded yet.</p>';
  }

  function show() {
    document.querySelectorAll("#crTabs [data-tab]").forEach((b) => b.classList.toggle("active", b.dataset.tab === state.tab));
    const q = new URLSearchParams(window.location.search);
    state.tab === "timeline" ? q.delete("tab") : q.set("tab", state.tab);
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    ({ timeline, details, history: historyTab })[state.tab]();
  }

  function refresh(r) {
    state.r = r;
    state.history = null;
    hero();
    show();
  }

  function edit() {
    const r = state.r;
    const el = C.windowEl({
      title: "Edit care",
      subtitle: r.who,
      icon: "ri-edit-line",
      body: PeopleKit.parts([
        {
          icon: "ri-heart-pulse-line",
          title: "What",
          body: `<div class="row g-3">
            <div class="col-md-5"><label class="form-label" for="edOn">When</label><input type="date" class="form-control" id="edOn" value="${r.on}" max="${C.todayIso()}"></div>
            ${r.type === "hospital" ? `<div class="col-md-7"><label class="form-label" for="edHospital">Hospital</label><input class="form-control" id="edHospital" maxlength="120" value="${C.esc(r.hospital || "")}"></div>` : ""}
            <div class="col-12"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="edUrgent"${r.priority === "high" ? " checked" : ""}><label class="form-check-label" for="edUrgent">Urgent</label></div></div>
          </div>`,
        },
        { icon: "ri-sticky-note-line", title: "Note", body: `<textarea class="form-control" id="edNote" rows="3" maxlength="2000" aria-label="Note">${C.esc(r.note || "")}</textarea><div class="form-check form-switch mt-2 mb-0"><input class="form-check-input" type="checkbox" role="switch" id="edConf"${r.confidential ? " checked" : ""}${r.type === "counselling" ? " disabled" : ""}><label class="form-check-label" for="edConf">Confidential</label></div>` },
        { icon: "ri-calendar-event-line", title: "Next step", hint: "Optional", body: `<input type="date" class="form-control vs-date" id="edNext" value="${r.next_on || ""}" aria-label="Next step">` },
      ]),
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="edSave"><i class="ri-check-line me-1"></i>Save</button>`,
    });
    el.querySelector("#edSave").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Saving...");
      const body = { on: el.querySelector("#edOn").value, priority: el.querySelector("#edUrgent").checked ? "high" : "normal", note: el.querySelector("#edNote").value.trim() || null, confidential: el.querySelector("#edConf").checked, next_on: el.querySelector("#edNext").value || null };
      if (el.querySelector("#edHospital")) body.hospital = el.querySelector("#edHospital").value.trim() || null;
      const res = await CareAPI.update(id, body);
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message);
      refresh(res.data);
    });
  }

  function act(name) {
    const done = (r) => refresh(r);
    ({ contact: () => C.contactWindow(state.r, { type: state.r.type === "prayer" ? "prayer" : "visit", onDone: done }), close: () => C.closeWindow(state.r, { onDone: done }), discharge: () => C.dischargeWindow(state.r, { onDone: done }), edit })[name]?.();
  }

  async function init() {
    if (!id) return ($("crHero").innerHTML = `<div class="card-body">${MembersUI.errorBox("Open a case from Pastoral care.", `location.href='${CTX.baseUrl}/'`)}</div>`);
    const res = await CareAPI.get(id);
    if (!res.ok) return ($("crHero").innerHTML = `<div class="card-body">${MembersUI.errorBox(res.message, `location.href='${CTX.baseUrl}/'`)}</div>`);
    document.title = `${res.data.who} - Pastoral care - Makueni West Diocese`;
    $("crTabs").hidden = false;
    refresh(res.data);
    $("crTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b || b.dataset.tab === state.tab) return;
      state.tab = b.dataset.tab;
      show();
    });
    $("crHero").addEventListener("click", (e) => {
      const b = e.target.closest("[data-act]");
      if (b) act(b.dataset.act);
    });
    if (params.get("act") === "contact" && state.r.can.change) {
      act("contact");
      const q = new URLSearchParams(window.location.search);
      q.delete("act");
      history.replaceState(null, "", `${window.location.pathname}?${q}`);
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
