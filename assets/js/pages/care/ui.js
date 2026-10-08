/**
 * ============================================================================
 * PASTORAL CARE - shared look and windows (P3)
 * ============================================================================
 * The kinds of care (one colour each), status pills, dates in words, and
 * the windows every page uses - Record care, Add a contact, Close / Answered
 * and Home from hospital - as navy windows in titled parts (PeopleKit). The
 * Record care window also opens from a member's or visitor's page.
 * ============================================================================
 */
const CareUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");

  const TYPES = {
    home_visit: { label: "Home visit", icon: "ri-home-heart-line", color: "success" },
    hospital: { label: "Hospital", icon: "ri-hospital-line", color: "danger" },
    counselling: { label: "Counselling", icon: "ri-chat-heart-line", color: "purple" },
    prayer: { label: "Prayer", icon: "ri-hand-heart-line", color: "pink" },
    phone_call: { label: "Phone call", icon: "ri-phone-line", color: "primary" },
    bereavement: { label: "Bereavement", icon: "ri-heart-2-line", color: "secondary" },
    concern: { label: "Concern", icon: "ri-error-warning-line", color: "warning" },
  };
  const CONTACTS = { visit: { label: "Visit", icon: "ri-home-heart-line" }, call: { label: "Call", icon: "ri-phone-line" }, prayer: { label: "Prayed together", icon: "ri-hand-heart-line" }, other: { label: "Other", icon: "ri-more-line" } };
  const STATUS = { open: { label: "Open", color: "primary" }, closed: { label: "Closed", color: "secondary" }, answered: { label: "Answered", color: "success" } };

  const typePill = (t) => {
    const m = TYPES[t] || { label: t, icon: "ri-heart-line", color: "primary" };
    return `<span class="soft-chip soft-${m.color === "secondary" ? "primary" : m.color}"><i class="${m.icon}"></i>${esc(m.label)}</span>`;
  };
  const statusPill = (s) => {
    const m = STATUS[s] || { label: s, color: "light" };
    return `<span class="badge bg-${m.color} ${textOn(m.color)}">${esc(m.label)}</span>`;
  };
  const typeTile = (t, size = "md") => {
    const m = TYPES[t] || TYPES.concern;
    return `<span class="avatar avatar-${size} avatar-rounded bg-${m.color} ${textOn(m.color)} flex-shrink-0"><i class="${m.icon}"></i></span>`;
  };
  const day = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "-");
  const todayIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  };
  /** "Due today", "2 days late", "Due Fri 10 Oct". */
  function dueChip(iso) {
    if (!iso) return "";
    const d = Math.round((new Date(`${iso}T12:00:00`) - new Date(`${todayIso()}T12:00:00`)) / 86400000);
    if (d < 0) return `<span class="vs-due is-late"><i class="ri-alarm-warning-line"></i>${-d} ${d === -1 ? "day" : "days"} late</span>`;
    if (d === 0) return '<span class="vs-due is-today"><i class="ri-time-line"></i>Due today</span>';
    return `<span class="vs-due"><i class="ri-time-line"></i>Due ${new Date(`${iso}T12:00:00`).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" })}</span>`;
  }
  /** The note, or the lock that stands in for it. */
  const noteHtml = (r) => (r.note ? `<p class="cr-note">${esc(r.note)}</p>` : r.note_hidden ? `<p class="cr-note is-locked"><i class="ri-lock-2-line"></i>Confidential - recorded by ${esc(r.author || "the pastor")}</p>` : "");
  const nextStepPart = (id = "crNext") => ({
    icon: "ri-calendar-event-line",
    title: "Next step",
    hint: "Optional",
    body: `<div class="row g-3"><div class="col-md-6"><input type="date" class="form-control" id="${id}" min="${todayIso()}" aria-label="Next step" data-quick="in3,nextSunday,in14"></div>
      <div class="col-md-6"><p class="cr-field-note mb-0"><i class="ri-information-line"></i>When someone should go again - it shows in Needs care and on the calendar.</p></div></div>`,
  });

  // ---------------------------------------------------------------- a window
  function windowEl({ id = "crModal", title, subtitle = "", icon, danger = false, size = "", body, foot }) {
    document.getElementById(id)?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal${danger ? " is-danger" : ""}" id="${id}" tabindex="-1" aria-labelledby="${id}Title">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ${size}"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon"><i class="${icon}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="${id}Title">${esc(title)}</h5>${subtitle ? `<div class="app-modal-subtitle">${esc(subtitle)}</div>` : ""}</div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">${body}</div>
          <div class="modal-footer">${foot}</div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById(id);
    el.addEventListener("hidden.bs.modal", () => el.remove());
    // Dates read "8 Oct 2026", with the one-tap chips each field asks for (data-quick).
    if (typeof DateField !== "undefined") el.querySelectorAll('input[type="date"]').forEach((i) => DateField.enhance(i, { quick: (i.dataset.quick || "").split(",").filter(Boolean) }));
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }
  async function submit(el, btn, call, done) {
    UI.setButtonLoading(btn, "Saving...");
    const res = await call();
    UI.restoreButton(btn);
    if (!res.ok) return Toast.error(res.message);
    bootstrap.Modal.getInstance(el)?.hide();
    Toast.success(res.message);
    done?.(res.data);
  }

  let optionsCache = null;
  const options = async () => {
    if (!optionsCache) {
      const res = await CareAPI.options();
      optionsCache = res.ok ? res.data : { types: Object.entries(TYPES).map(([key, t]) => ({ key, ...t })), carers: [], contact_types: [] };
    }
    return optionsCache;
  };

  /**
   * Record care. person: {id, name} when it's for someone already chosen
   * (a member's or visitor's page); type: a kind to start on.
   */
  async function recordWindow({ person = null, type = "home_visit", userId = 0, onDone = null } = {}) {
    const o = await options();
    const types = o.types.length ? o.types : Object.entries(TYPES).map(([key, t]) => ({ key, ...t }));
    if (!types.some((t) => t.key === type)) type = types[0].key;
    let picked = person;
    // Each kind in its own colour - the same colours as the chart and the lists.
    const choice = (t) => {
      const c = TYPES[t.key]?.color || t.color || "primary";
      return `<label class="ec-choice${c === "secondary" || c === "warning" ? " is-dark" : ""}" style="--q: var(--${c}-rgb)"><input type="radio" name="crType" value="${t.key}"${t.key === type ? " checked" : ""}><span class="ec-choice-icon"><i class="${t.icon}"></i></span><strong>${esc(t.label)}</strong><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`;
    };
    const el = windowEl({
      title: "Record care",
      subtitle: person ? person.name : "A visit, a call, counselling, prayer...",
      icon: "ri-heart-pulse-line",
      // Wide - about three-quarters of a laptop screen - so the kinds sit four to a row.
      size: "modal-xl",
      body: PeopleKit.parts([
        {
          icon: "ri-user-3-line",
          title: "Who",
          body: person
            ? `<div class="cr-who-fixed">${typeof MembersUI !== "undefined" ? MembersUI.avatar({ id: person.id, initials: person.initials || person.name.split(" ").map((w) => w[0]).slice(0, 2).join("") }, "sm") : ""}<strong>${esc(person.name)}</strong></div>`
            : `<div class="pp-picker-search"><i class="ri-search-line"></i><input type="search" class="form-control" id="crSearch" placeholder="Search the register - name, phone or area" autocomplete="off" aria-label="Search the register"></div>
               <div class="pp-picker-list" id="crFound"></div>
               <div class="pp-chips" id="crPicked"></div>
               <div class="mt-3"><label class="form-label" for="crName">Not in our register? Type their name</label><input class="form-control" id="crName" maxlength="120" placeholder="e.g. Mama Mwende (a neighbour)"></div>`,
        },
        {
          icon: "ri-heart-pulse-line",
          title: "What",
          body: `<div class="ec-choices is-varied is-tinted cr-kinds mb-3" role="radiogroup" aria-label="Kind of care">${types.map(choice).join("")}</div>
            <div class="row g-3 align-items-start">
              <div class="col-md-6"><label class="form-label" for="crOn">When</label><input type="date" class="form-control" id="crOn" value="${todayIso()}" max="${todayIso()}" data-quick="today,yesterday,lastSunday"></div>
              <div class="col-md-6"><span class="form-label d-block">Priority</span><label class="cr-urgent" for="crUrgent"><span class="cr-urgent-icon"><i class="ri-alarm-warning-line"></i></span><span class="flex-fill min-w-0"><strong>Urgent</strong><small>Put it at the top of Needs care</small></span><span class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="crUrgent"></span></label></div>
              <div class="col-12" id="crHospitalWrap"><label class="form-label" for="crHospital">Which hospital</label><input class="form-control" id="crHospital" maxlength="120" placeholder="e.g. Makueni County Referral"></div>
            </div>`,
        },
        { icon: "ri-team-line", title: "Who went", hint: "You're added", body: `<select class="form-select" id="crCarers" multiple aria-label="Who went">${(o.carers || []).filter((c) => c.id !== userId).map((c) => `<option value="${c.id}" data-color="${UI.colorFor(c.name)}">${esc(c.name)}</option>`).join("")}</select>` },
        {
          icon: "ri-sticky-note-line",
          title: "Note",
          hint: "Optional - only our leaders see it",
          body: `<textarea class="form-control" id="crNote" rows="3" maxlength="2000" placeholder="A few words - what happened, what they need" aria-label="Note"></textarea>
            <div class="form-check form-switch mt-2 mb-0"><input class="form-check-input" type="checkbox" role="switch" id="crConf"><label class="form-check-label" for="crConf" id="crConfLabel">Confidential - only I and the Senior Pastor can read the note</label></div>`,
        },
        nextStepPart("crNext"),
      ]),
      foot: `<span class="me-auto cr-foot-urgent" id="crUrgentChip" hidden><i class="ri-alarm-warning-line"></i>Urgent</span><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="crSave"><i class="ri-check-line me-1"></i>Record it</button>`,
    });
    UI.enhanceSelect(el.querySelector("#crCarers"), { placeholder: "Anyone else who went", closeOnSelect: false });
    el.querySelector("#crUrgent").addEventListener("change", (e) => {
      el.querySelector("#crUrgentChip").hidden = !e.target.checked;
      e.target.closest(".cr-urgent").classList.toggle("is-on", e.target.checked);
    });
    const sync = () => {
      const t = el.querySelector('input[name="crType"]:checked').value;
      el.querySelector("#crHospitalWrap").hidden = t !== "hospital";
      const conf = el.querySelector("#crConf");
      conf.disabled = t === "counselling";
      if (t === "counselling") conf.checked = true;
      el.querySelector("#crConfLabel").textContent = t === "counselling" ? "Counselling is always confidential - only you and the Senior Pastor read the note" : "Confidential - only I and the Senior Pastor can read the note";
    };
    el.querySelectorAll('input[name="crType"]').forEach((r) => r.addEventListener("change", sync));
    sync();

    if (!person) {
      let timer = null;
      let found = [];
      const paint = () => {
        el.querySelector("#crPicked").innerHTML = picked ? `<span class="pp-chip"><span>${esc(picked.name)}</span><button type="button" data-unpick aria-label="Remove">&times;</button></span>` : "";
        el.querySelector("#crName").closest("div").hidden = !!picked;
      };
      el.querySelector("#crSearch").addEventListener("input", (e) => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
          const q = e.target.value.trim();
          if (q.length < 2) return (el.querySelector("#crFound").innerHTML = "");
          const res = await CareAPI.search(q);
          found = res.ok ? res.data : [];
          el.querySelector("#crFound").innerHTML = found.length
            ? found.map((p) => `<button type="button" class="pp-picker-row w-100 border-0 text-start" data-pick="${p.id}"><span class="flex-fill min-w-0"><strong>${esc(p.name)}</strong><small>${esc([p.kind === "visitor" ? "Visitor" : "Member", p.area].filter(Boolean).join(" · "))}</small></span><i class="ri-add-line"></i></button>`).join("")
            : `<div class="pp-picker-none">Nobody found - type their name below.</div>`;
        }, 300);
      });
      el.querySelector("#crFound").addEventListener("click", (e) => {
        const b = e.target.closest("[data-pick]");
        if (!b) return;
        picked = found.find((p) => p.id === Number(b.dataset.pick));
        el.querySelector("#crFound").innerHTML = "";
        el.querySelector("#crSearch").value = "";
        paint();
      });
      el.querySelector("#crPicked").addEventListener("click", (e) => {
        if (!e.target.closest("[data-unpick]")) return;
        picked = null;
        paint();
      });
    }

    el.querySelector("#crSave").addEventListener("click", (e) => {
      const name = el.querySelector("#crName")?.value.trim();
      if (!picked && !name) return Toast.error("Pick someone from the register, or type their name.");
      const t = el.querySelector('input[name="crType"]:checked').value;
      submit(
        el,
        e.currentTarget,
        () =>
          CareAPI.create({
            person_id: picked ? picked.id : null,
            person_name: picked ? null : name,
            type: t,
            on: el.querySelector("#crOn").value,
            hospital: t === "hospital" ? el.querySelector("#crHospital").value.trim() || null : null,
            priority: el.querySelector("#crUrgent").checked ? "high" : "normal",
            carers: [...el.querySelector("#crCarers").selectedOptions].map((x) => Number(x.value)),
            note: el.querySelector("#crNote").value.trim() || null,
            confidential: el.querySelector("#crConf").checked,
            next_on: el.querySelector("#crNext").value || null,
          }),
        onDone,
      );
    });
    return el;
  }

  /** Another visit, call or prayer on an open case. */
  function contactWindow(record, { type = "visit", onDone } = {}) {
    const choice = (k, t) => `<label class="ec-choice"><input type="radio" name="ctType" value="${k}"${k === type ? " checked" : ""}><span class="ec-choice-icon"><i class="${t.icon}"></i></span><strong>${t.label}</strong><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`;
    const el = windowEl({
      title: type === "visit" && record.type === "hospital" ? "Visit now" : "Add a contact",
      subtitle: record.who,
      icon: CONTACTS[type]?.icon || "ri-add-line",
      body: PeopleKit.parts([
        {
          icon: "ri-heart-pulse-line",
          title: "What happened",
          body: `<div class="ec-choices mb-3">${Object.entries(CONTACTS).map(([k, t]) => choice(k, t)).join("")}</div>
            <div class="row g-3"><div class="col-md-6"><label class="form-label" for="ctOn">When</label><input type="date" class="form-control" id="ctOn" value="${todayIso()}" max="${todayIso()}" data-quick="today,yesterday"></div></div>`,
        },
        { icon: "ri-sticky-note-line", title: "Note", hint: record.confidential ? "Confidential" : "Optional", body: '<textarea class="form-control" id="ctNote" rows="3" maxlength="2000" aria-label="Note" placeholder="How they are, what they need"></textarea>' },
        nextStepPart("ctNext"),
      ]),
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="ctSave"><i class="ri-check-line me-1"></i>Add</button>`,
    });
    el.querySelector("#ctSave").addEventListener("click", (e) =>
      submit(el, e.currentTarget, () => CareAPI.contact(record.id, { type: el.querySelector('input[name="ctType"]:checked').value, on: el.querySelector("#ctOn").value, note: el.querySelector("#ctNote").value.trim() || null, next_on: el.querySelector("#ctNext").value || null }), onDone),
    );
  }

  /** Close a case, or mark a prayer answered (with a testimony that may be shared). */
  function closeWindow(record, { onDone } = {}) {
    const prayer = record.type === "prayer";
    const el = windowEl({
      title: prayer ? "Prayer answered" : "Close this case",
      subtitle: record.who,
      icon: prayer ? "ri-hand-heart-line" : "ri-checkbox-circle-line",
      body: prayer
        ? PeopleKit.parts([
            { icon: "ri-chat-smile-2-line", title: "Testimony", hint: "Optional", body: '<textarea class="form-control" id="clTestimony" rows="3" maxlength="1000" placeholder="How God answered" aria-label="Testimony"></textarea>' },
            { icon: "ri-share-line", title: "Share it", body: '<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="clShare"><label class="form-check-label" for="clShare">They agreed it may be shared - offer it for the monthly report\'s testimonies</label></div>' },
          ])
        : PeopleKit.parts([{ icon: "ri-checkbox-circle-line", title: "Done", body: '<p class="mb-0">It leaves Needs care and the open cases. You can add a contact later to open it again.</p>' }]),
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="clGo"><i class="ri-check-line me-1"></i>${prayer ? "Mark answered" : "Close it"}</button>`,
    });
    el.querySelector("#clGo").addEventListener("click", (e) =>
      submit(el, e.currentTarget, () => CareAPI.close(record.id, prayer ? { status: "answered", testimony: el.querySelector("#clTestimony").value.trim() || null, share_testimony: el.querySelector("#clShare").checked } : { status: "closed" }), onDone),
    );
  }

  /** Home from hospital. */
  function dischargeWindow(record, { onDone } = {}) {
    const el = windowEl({
      title: "Home from hospital",
      subtitle: `${record.who} · ${record.hospital || "Hospital"}`,
      icon: "ri-home-heart-line",
      body: PeopleKit.parts([{ icon: "ri-calendar-check-line", title: "When they went home", body: `<div class="row"><div class="col-md-7"><input type="date" class="form-control" id="dcOn" value="${todayIso()}" max="${todayIso()}" aria-label="When they went home" data-quick="today,yesterday"></div></div>` }]),
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="dcGo"><i class="ri-check-line me-1"></i>They're home</button>`,
    });
    el.querySelector("#dcGo").addEventListener("click", (e) => submit(el, e.currentTarget, () => CareAPI.discharge(record.id, { on: el.querySelector("#dcOn").value }), onDone));
  }

  /**
   * A member's or visitor's Care tab: their pastoral care, newest first,
   * with Record care. opts {careUrl, canManage, userId, onChange}
   */
  async function personPanel(main, person, opts) {
    main.innerHTML = `<div class="card custom-card"><div class="card-header justify-content-between flex-wrap gap-2"><div class="card-title">Pastoral care</div>${opts.canManage ? '<button type="button" class="btn btn-sm btn-primary" data-care-record><i class="ri-add-line me-1"></i>Record care</button>' : ""}</div><div class="card-body" data-care-list><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:60%"></span></div></div>`;
    main.querySelector("[data-care-record]")?.addEventListener("click", () => recordWindow({ person, userId: opts.userId, onDone: () => personPanel(main, person, opts) }));
    const res = await CareAPI.forPerson(person.id);
    const box = main.querySelector("[data-care-list]");
    if (!res.ok) return (box.innerHTML = `<p class="mb-0 fw-semibold">${esc(res.message)}</p>`);
    box.innerHTML = res.data.length
      ? `<ol class="ev-timeline vs-timeline">${res.data
          .map((r) => {
            const t = TYPES[r.type] || TYPES.concern;
            return `<li style="--q: var(--${t.color}-rgb)"><span class="ev-timeline-dot"></span><div class="flex-fill min-w-0">
              <span class="ev-timeline-when">${day(r.on)}${r.author ? ` · ${esc(r.author)}` : ""}</span>
              <span class="ev-timeline-what fw-semibold"><a class="mb-link" href="${opts.careUrl}/case?id=${r.id}"><i class="${t.icon} me-1"></i>${t.label}${r.hospital ? ` · ${esc(r.hospital)}` : ""}</a> ${statusPill(r.status)}</span>
              ${noteHtml(r)}
              ${r.status === "open" && r.next_on ? dueChip(r.next_on) : ""}
            </div></li>`;
          })
          .join("")}</ol>`
      : `<p class="mb-0 fw-semibold">No pastoral care recorded for ${esc(person.name.split(" ")[0])} yet.</p>`;
  }

  return { personPanel, TYPES, CONTACTS, STATUS, esc, textOn, typePill, statusPill, typeTile, day, todayIso, dueChip, noteHtml, windowEl, options, recordWindow, contactWindow, closeWindow, dischargeWindow };
})();

window.CareUI = CareUI;
