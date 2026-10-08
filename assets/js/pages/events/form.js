/**
 * ============================================================================
 * EVENTS AND INITIATIVES - new / edit (church, region, diocese)
 * ============================================================================
 * Four steps (what and when, where and who, registration and money, check)
 * with a live preview of how the invited places will see it. Save as a
 * draft, or save and publish - publishing tells the places it is open to.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const E = EventsUI;
  const CTX = window.EVENTS_CTX;
  const INIT = E.IS_INITIATIVE;
  const N = E.NOUN;
  const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const $ = (id) => document.getElementById(id);
  const editId = Number(new URLSearchParams(window.location.search).get("id")) || null;
  const STEP_OF = {
    title: 1, type: 1, audience: 1, starts_at: 1, ends_at: 1, description: 1, frequency: 1, meeting_day: 1, meeting_time: 1, certificate: 1,
    venue: 2, capacity: 2, coordinator: 2, speakers: 2, agenda: 2, open_to: 2, invitees: 2,
    registration: 3, register_by: 3, fee_per_person: 3, planned_income: 3, planned_spend: 3,
  };
  const OPEN_ICONS = { own: "ri-home-heart-line", region: "ri-map-2-line", below: "ri-community-line", selected: "ri-checkbox-multiple-line" };
  const FIELD_IDS = { fee_per_person: "f_fee", starts_at: "f_start_date", ends_at: "f_end_date" };

  let ov = null; // overview: types, audiences, open_to, invitable
  let event = null; // the event being edited
  let step = 1;
  let dirty = false;
  let saving = false;

  const iso = (dt) => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, "0")}-${String(dt.getDate()).padStart(2, "0")}`;
  const hm = (dt) => `${String(dt.getHours()).padStart(2, "0")}:${String(dt.getMinutes()).padStart(2, "0")}`;
  const instant = (dateId, timeId, fallback) => {
    const day = $(dateId).value;
    const dt = day ? new Date(`${day}T${$(timeId).value || fallback}:00`) : null;
    return dt && !isNaN(dt) ? dt.toISOString() : "";
  };
  const openTo = () => document.querySelector('input[name="open_to"]:checked')?.value || "";
  const reaches = () => openTo() && openTo() !== "own";

  // -------------------------------------------------------------- fill the form
  function fillOptions() {
    $("f_type").innerHTML = `<option value="">Pick one</option>` + Object.entries(ov.types).map(([k, label]) => `<option value="${k}">${E.esc(label)}</option>`).join("");
    // Kind as cards (v1-events' ec-choices): picking one sets the select and counts as a change.
    $("typeChoices").innerHTML = Object.entries(ov.types)
      .map(
        ([k, label]) => `<label class="ec-choice">
          <input type="radio" name="type_ui" value="${k}">
          <span class="ec-choice-icon"><i class="${E.typeIcon(k)}"></i></span>
          <strong>${E.esc(label)}</strong>
          <span class="ec-choice-tick"><i class="ri-check-line"></i></span>
        </label>`,
      )
      .join("");
    $("typeChoices").addEventListener("change", (ev) => {
      if (ev.target.name !== "type_ui") return;
      $("f_type").value = ev.target.value;
      $("f_type").dispatchEvent(new Event("change", { bubbles: true }));
    });
    $("f_audience").innerHTML = Object.entries(ov.audiences).map(([k, label]) => `<option value="${k}">${E.esc(label)}</option>`).join("");
    $("f_open_to").innerHTML = Object.entries(ov.open_to)
      .map(
        ([k, label]) => `
        <label class="ev-choice">
          <input type="radio" name="open_to" value="${k}">
          <span class="ev-choice-icon"><i class="${OPEN_ICONS[k] || "ri-group-line"}"></i></span>
          <span class="ev-choice-text"><strong>${E.esc(label)}</strong><small>${openHint(k)}</small></span>
        </label>`,
      )
      .join("");
    const regions = ov.invitable.filter((p) => p.type === "region");
    const churches = ov.invitable.filter((p) => p.type === "church");
    const group = (label, list, icon, color) => (list.length ? `<optgroup label="${label}">${list.map((p) => `<option value="${p.id}" data-icon="${icon}" data-color="${color}">${E.esc(p.name)}</option>`).join("")}</optgroup>` : "");
    $("f_invitees").innerHTML = group("Regions", regions, "ri-map-2-line", "purple") + group("Churches", churches, "ri-home-heart-line", "success");
    if (INIT) {
      $("f_frequency").innerHTML = Object.entries(ov.frequencies || {}).map(([k, label]) => `<option value="${k}">${E.esc(label)}</option>`).join("");
      UI.enhanceSelect("f_frequency", { search: false });
      UI.enhanceSelect("f_meeting_day", { search: false });
    }
    UI.enhanceSelect("f_audience", { search: false });
    UI.enhanceSelect("f_invitees", { placeholder: "Pick regions or churches", closeOnSelect: false, search: true });
  }

  function openHint(k) {
    return {
      own: "Just us - nobody else is told",
      region: "The other churches of our region can register",
      below: CTX.level === "diocese" ? "Every region and church is told" : "Every church of our region is told",
      selected: "Only the places you pick are told",
    }[k] || "";
  }

  function setValue(id, v) {
    const el = $(id);
    el.value = v ?? "";
    if (el.tagName === "SELECT") UI.syncSelect(el);
  }

  function fill(e) {
    const start = e ? new Date(e.starts_at) : new Date(Date.now() + 14 * 86400000);
    // A new initiative runs for eight weeks to start with.
    const end = e ? new Date(e.ends_at) : INIT ? new Date(start.getTime() + 56 * 86400000) : start;
    setValue("f_title", e?.title);
    setValue("f_type", e?.type);
    setValue("f_audience", e?.audience || "everyone");
    setValue("f_description", e?.description);
    $("f_start_date").value = iso(start);
    $("f_end_date").value = iso(end);
    if (e) {
      $("f_start_time").value = hm(start);
      $("f_end_time").value = hm(end);
    }
    ["venue", "capacity", "coordinator", "speakers", "agenda"].forEach((k) => setValue(`f_${k}`, e?.[k]));
    const open = e?.open_to || Object.keys(ov.open_to)[0];
    const radio = document.querySelector(`input[name="open_to"][value="${open}"]`);
    if (radio) radio.checked = true;
    $("f_invitees").querySelectorAll("option").forEach((o) => (o.selected = !!e?.invitees?.some((i) => String(i.id) === o.value)));
    UI.syncSelect($("f_invitees"));
    $("f_registration").checked = e ? !!e.registration : true;
    setValue("f_register_by", e?.register_by);
    setValue("f_fee", e?.fee_per_person);
    setValue("f_planned_income", e?.planned_income);
    setValue("f_planned_spend", e?.planned_spend);
    if (INIT) {
      setValue("f_frequency", e?.frequency || "weekly");
      setValue("f_meeting_day", e?.meeting_day ?? start.getDay());
      $("f_certificate").checked = !!e?.certificate;
      if (!e) {
        $("f_start_time").value = "18:00";
        $("f_end_time").value = "20:00";
      }
    }
    syncDependents();
  }

  /** How long it runs, from the Starts / Ends cards - red when it ends before it starts. */
  function syncDuration() {
    const badge = $("whenDuration");
    const a = new Date(`${$("f_start_date").value}T${$("f_start_time").value || "00:00"}`);
    const b = new Date(`${$("f_end_date").value}T${$("f_end_time").value || "00:00"}`);
    if (isNaN(a) || isNaN(b)) return (badge.hidden = true);
    badge.hidden = false;
    const mins = Math.round((b - a) / 60000);
    badge.classList.toggle("is-bad", mins <= 0);
    if (mins <= 0) return (badge.innerHTML = '<i class="ri-error-warning-line"></i>It ends before it starts');
    const days = Math.round((new Date($("f_end_date").value) - new Date($("f_start_date").value)) / 86400000) + 1;
    const hours = Math.floor(mins / 60);
    const text = days > 1 ? (days >= 14 ? `${Math.round(days / 7)} weeks` : `${days} days`) : hours ? `${hours} hour${hours === 1 ? "" : "s"}${mins % 60 ? ` ${mins % 60} min` : ""}` : `${mins} min`;
    badge.innerHTML = `<i class="ri-time-line"></i>${text}`;
  }

  function syncDependents() {
    const kind = document.querySelector(`input[name="type_ui"][value="${$("f_type").value}"]`);
    document.querySelectorAll('input[name="type_ui"]').forEach((r) => (r.checked = r === kind));
    syncDuration();
    $("inviteesWrap").hidden = openTo() !== "selected";
    document.querySelectorAll(".ev-choice").forEach((c) => c.classList.toggle("is-on", c.querySelector("input").checked));
    $("regBox").hidden = !reaches();
    $("regOff").classList.toggle("d-none", reaches());
    $("regFields").hidden = !$("f_registration").checked;
    // An initiative can be joined until its last day.
    $("f_register_by").max = (INIT ? $("f_end_date").value : $("f_start_date").value) || "";
    if (INIT) $("meetingDayWrap").hidden = !["weekly", "fortnightly"].includes($("f_frequency").value);
  }

  // -------------------------------------------------------------- read it back
  function body() {
    const reg = reaches() && $("f_registration").checked;
    const n = (id) => ($(id).value === "" ? null : Number($(id).value));
    return {
      title: $("f_title").value.trim(),
      type: $("f_type").value,
      audience: $("f_audience").value,
      // The moment in time (the API keeps UTC) - typed as the person's own local time.
      starts_at: instant("f_start_date", "f_start_time", "00:00"),
      ends_at: instant("f_end_date", "f_end_time", "23:59"),
      description: $("f_description").value.trim() || null,
      venue: $("f_venue").value.trim() || null,
      capacity: n("f_capacity"),
      coordinator: $("f_coordinator").value.trim() || null,
      speakers: $("f_speakers").value.trim() || null,
      agenda: $("f_agenda").value.trim() || null,
      open_to: openTo(),
      invitees: openTo() === "selected" ? [...$("f_invitees").selectedOptions].map((o) => Number(o.value)) : [],
      registration: reg,
      register_by: reg ? $("f_register_by").value || null : null,
      fee_per_person: reg ? n("f_fee") : null,
      planned_income: n("f_planned_income"),
      planned_spend: n("f_planned_spend"),
      ...(INIT
        ? {
            frequency: $("f_frequency").value,
            meeting_day: ["weekly", "fortnightly"].includes($("f_frequency").value) ? Number($("f_meeting_day").value) : null,
            // Sessions start at the time the first day starts.
            meeting_time: $("f_start_time").value || null,
            certificate: $("f_certificate").checked,
          }
        : {}),
    };
  }

  /** The days it will meet - the same rules the server uses (docs/specs/events-initiatives-spec.md). */
  function sessionDates(b) {
    if (!INIT || !b.frequency || !$("f_start_date").value || !$("f_end_date").value) return [];
    const at = (v) => new Date(`${v}T12:00:00`);
    const start = at($("f_start_date").value);
    const end = at($("f_end_date").value);
    const out = [];
    if (b.frequency === "once") return [start];
    if (b.frequency === "weekly" || b.frequency === "fortnightly") {
      const day = new Date(start);
      while (b.meeting_day != null && day.getDay() !== b.meeting_day) day.setDate(day.getDate() + 1);
      for (; day <= end && out.length < 104; day.setDate(day.getDate() + (b.frequency === "weekly" ? 7 : 14))) out.push(new Date(day));
      return out;
    }
    const step = b.frequency === "monthly" ? 1 : 3;
    for (let i = 0; out.length < 104; i++) {
      const first = new Date(start.getFullYear(), start.getMonth() + i * step, 1, 12);
      const last = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
      const day = new Date(first.getFullYear(), first.getMonth(), Math.min(start.getDate(), last), 12);
      if (day > end) break;
      out.push(day);
    }
    return out;
  }

  /** The checks the API makes too, per step, so the person isn't sent back later. */
  function problems(n) {
    const b = body();
    const out = {};
    if (n === 1) {
      if (!b.title) out.title = `Give the ${N.one} a name.`;
      if (!b.type) out.type = `Pick a kind of ${N.one}.`;
      if (INIT && !b.frequency) out.frequency = "Say how often it meets.";
      if (!b.starts_at) out.starts_at = "Pick the day it starts.";
      if (!b.ends_at) out.ends_at = "Pick the day it ends.";
      if (b.starts_at && b.ends_at && b.ends_at < b.starts_at) out.ends_at = "It can't end before it starts.";
    }
    if (n === 2) {
      if (!b.open_to) out.open_to = "Say who it is open to.";
      if (b.open_to === "selected" && !b.invitees.length) out.invitees = "Pick the places it's open to.";
    }
    const closeBy = INIT ? $("f_end_date").value : $("f_start_date").value;
    if (n === 3 && b.register_by && closeBy && b.register_by > closeBy) out.register_by = INIT ? "Joining has to close by the last day." : "Registration has to close by the day it starts.";
    return out;
  }

  function showErrors(errors) {
    document.querySelectorAll(".intake-errors").forEach((box) => {
      box.hidden = true;
      box.innerHTML = "";
    });
    document.querySelectorAll("#eventFormCard .is-invalid").forEach((el) => el.classList.remove("is-invalid"));
    document.querySelectorAll("#eventFormCard [data-field].has-error").forEach((el) => el.classList.remove("has-error"));
    const byStep = {};
    Object.entries(errors).forEach(([field, msg]) => {
      const key = field.split(".")[0];
      const s = STEP_OF[key] || 1;
      (byStep[s] ||= []).push(Array.isArray(msg) ? msg[0] : msg);
      $(FIELD_IDS[key] || `f_${key}`)?.classList.add("is-invalid");
      document.querySelector(`#eventFormCard [data-field="${key}"]`)?.classList.add("has-error");
    });
    Object.entries(byStep).forEach(([s, msgs]) => {
      const box = document.querySelector(`[data-errors-for="${s}"]`);
      box.innerHTML = `<i class="ri-error-warning-line"></i><div><strong>Check this step</strong><ul>${msgs.map((m) => `<li>${E.esc(m)}</li>`).join("")}</ul></div>`;
      box.hidden = false;
    });
    return Number(Object.keys(byStep).sort()[0]) || null;
  }

  // -------------------------------------------------------------- steps
  function go(n) {
    step = n;
    document.querySelectorAll(".intake-step").forEach((s) => (s.hidden = Number(s.dataset.step) !== n));
    document.querySelectorAll(".intake-step-btn").forEach((b) => {
      const k = Number(b.dataset.go);
      b.classList.toggle("is-on", k === n);
      b.classList.toggle("is-done", k < n);
    });
    $("intakeStepsBar").style.width = `${(n / 4) * 100}%`;
    $("intakeStepsMobile").textContent = `Step ${n} of 4 · ${document.querySelector(`[data-go="${n}"] strong`).textContent}`;
    $("backBtn").hidden = n === 1;
    $("nextBtn").hidden = n === 4;
    $("publishBtn").hidden = n !== 4;
    if (n === 4) renderReview();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function next() {
    const errs = problems(step);
    if (Object.keys(errs).length) {
      showErrors(errs);
      return;
    }
    showErrors({});
    go(step + 1);
  }

  // -------------------------------------------------------------- preview and review
  function renderPreview() {
    const b = body();
    const label = ov.types[b.type] || `Kind of ${N.one}`;
    const color = b.type ? E.typeColor(b.type) : "primary";
    const start = b.starts_at ? new Date(b.starts_at) : null;
    const invited = b.open_to === "selected" ? [...$("f_invitees").selectedOptions].map((o) => o.textContent) : [];
    $("previewStatus").innerHTML = E.statusPill(event?.status || "draft");
    $("previewCard").innerHTML = `
      <div class="d-flex gap-3">
        ${start ? E.dateBlock(b.starts_at, color) : `<div class="ev-date bg-light"><span>&nbsp;</span><strong>?</strong></div>`}
        <div style="min-width:0">
          <div class="fw-bold text-break">${E.esc(b.title) || `Name of the ${N.one}`}</div>
          <div class="ev-card-meta mt-1">
            <span class="soft-chip soft-${color}"><i class="${E.typeIcon(b.type)}"></i>${E.esc(label)}</span>
            ${b.audience && b.audience !== "everyone" ? `<span class="soft-chip soft-primary">${E.esc(ov.audiences[b.audience])}</span>` : ""}
          </div>
        </div>
      </div>
      <ul class="ev-facts mt-3">
        <li><i class="ri-time-line"></i><span>${b.starts_at && b.ends_at ? E.when(b.starts_at, b.ends_at) : "Pick the dates"}</span></li>
        ${INIT && b.frequency ? `<li><i class="ri-repeat-line"></i><span>${E.esc(E.meets({ frequency: b.frequency, frequency_label: ov.frequencies[b.frequency], meeting_day: b.meeting_day, starts_at: b.starts_at }))}</span></li>` : ""}
        <li><i class="ri-map-pin-line"></i><span>${E.esc(b.venue) || "Venue not set"}</span></li>
        <li><i class="${OPEN_ICONS[b.open_to] || "ri-group-line"}"></i><span>${E.esc(ov.open_to[b.open_to] || "Who it is open to")}${invited.length ? ` · ${invited.length} ${invited.length === 1 ? "place" : "places"}` : ""}</span></li>
        ${b.registration ? `<li><i class="ri-user-add-line"></i><span>${INIT ? "Join" : "Register"}${b.register_by ? ` by ${E.shortDate(new Date(b.register_by))}` : ""} · ${b.fee_per_person ? `${E.money(b.fee_per_person)} a person` : "Free"}</span></li>` : ""}
        ${b.planned_income || b.planned_spend ? `<li><i class="ri-hand-coin-line"></i><span>Plan: raise ${E.money(b.planned_income)}, spend ${E.money(b.planned_spend)}</span></li>` : ""}
      </ul>
      ${invited.length ? `<div class="d-flex flex-wrap gap-1 mt-2">${invited.slice(0, 8).map((n) => `<span class="soft-chip soft-purple">${E.esc(n)}</span>`).join("")}${invited.length > 8 ? `<span class="soft-chip soft-primary">+${invited.length - 8} more</span>` : ""}</div>` : ""}
      ${sessionsPreview(b)}
      ${b.description ? `<p class="mt-3 mb-0 fs-13 text-break">${E.esc(b.description).slice(0, 280)}${b.description.length > 280 ? "..." : ""}</p>` : ""}`;
  }

  function sessionsPreview(b) {
    const dates = sessionDates(b);
    if (!INIT) return "";
    if (!dates.length) return `<div class="ev-sub mt-3">Sessions</div><p class="mb-0 fs-13 fw-semibold">Pick the days to see the sessions.</p>`;
    return `<div class="ev-sub mt-3">${dates.length} ${dates.length === 1 ? "session" : "sessions"}${dates.length >= 104 ? " (the most)" : ""}</div>
      <div class="d-flex flex-wrap gap-1 mt-1">${dates.slice(0, 8).map((d) => `<span class="soft-chip soft-primary">${d.toLocaleDateString(undefined, { day: "numeric", month: "short" })}</span>`).join("")}${dates.length > 8 ? `<span class="soft-chip soft-purple">+${dates.length - 8} more</span>` : ""}</div>`;
  }

  function renderReview() {
    const all = { ...problems(1), ...problems(2), ...problems(3) };
    const b = body();
    const told = b.open_to === "own" ? "Nobody else is told - it's for your own place." : `When you publish, the leaders of ${b.open_to === "selected" ? `the ${b.invitees.length} ${b.invitees.length === 1 ? "place" : "places"} you picked` : E.esc(ov.open_to[b.open_to]).toLowerCase()} get a notification.`;
    $("reviewBody").innerHTML = Object.keys(all).length
      ? `<div class="alert alert-danger mb-0"><strong>A few things are missing.</strong><ul class="mb-0 mt-1">${Object.values(all).map((m) => `<li>${E.esc(m)}</li>`).join("")}</ul></div>`
      : `<div class="ev-review">
          <div class="ev-review-row"><span>Name</span><strong>${E.esc(b.title)}</strong></div>
          <div class="ev-review-row"><span>Kind</span><strong>${E.esc(ov.types[b.type])} · ${E.esc(ov.audiences[b.audience])}</strong></div>
          <div class="ev-review-row"><span>When</span><strong>${E.when(b.starts_at, b.ends_at)}</strong></div>
          ${INIT ? `<div class="ev-review-row"><span>Meets</span><strong>${E.esc(E.meets({ frequency: b.frequency, frequency_label: ov.frequencies[b.frequency], meeting_day: b.meeting_day, starts_at: b.starts_at }))} · ${sessionDates(b).length} sessions</strong></div>` : ""}
          <div class="ev-review-row"><span>Where</span><strong>${E.esc(b.venue) || "Not set"}</strong></div>
          <div class="ev-review-row"><span>Open to</span><strong>${E.esc(ov.open_to[b.open_to])}</strong></div>
          <div class="ev-review-row"><span>${INIT ? "Joining" : "Registration"}</span><strong>${b.registration ? `Yes${b.register_by ? `, by ${E.shortDate(new Date(b.register_by))}` : ""} · ${b.fee_per_person ? `${E.money(b.fee_per_person)} a person` : "free"}` : "No"}</strong></div>
        </div>
        <div class="alert alert-primary d-flex gap-2 mt-3 mb-0"><i class="ri-notification-3-line fs-16"></i><span>${told}</span></div>`;
  }

  // -------------------------------------------------------------- save
  async function save(publish) {
    if (saving) return;
    const all = { ...problems(1), ...problems(2), ...problems(3) };
    if (Object.keys(all).length) {
      go(showErrors(all) || 1);
      return;
    }
    saving = true;
    const btn = publish ? $("publishBtn") : $("saveDraftBtn");
    UI.setButtonLoading(btn, publish ? "Saving..." : "Saving...");
    const res = editId ? await EventsAPI.update(editId, body()) : await EventsAPI.create(body());
    if (!res.ok) {
      saving = false;
      UI.restoreButton(btn);
      if (res.errors) go(showErrors(res.errors) || step);
      Toast.error(res.message);
      return;
    }
    let message = res.message;
    if (publish && res.data.status === "draft") {
      const pub = await EventsAPI.publish(res.data.id);
      message = pub.ok ? pub.message : `Saved as a draft, but not published: ${pub.message}`;
      if (!pub.ok) Toast.warning(message);
    }
    dirty = false;
    sessionStorage.setItem("mwd-events-flash", message);
    window.location.href = `${CTX.baseUrl}/${N.page}?id=${res.data.id}`;
  }

  // -------------------------------------------------------------- start
  function markDirty() {
    dirty = true;
    $("intakeSaved").textContent = "Not saved yet";
    $("intakeSaved").className = "intake-saved me-auto is-dirty";
    syncDependents();
    renderPreview();
  }

  async function init() {
    const [o, e] = await Promise.all([EventsAPI.overview(new Date().getFullYear()), editId ? EventsAPI.get(editId) : Promise.resolve(null)]);
    if (!o.ok) {
      // Not a blank form: say what went wrong, with Try again.
      $("eventFormCard").innerHTML = `<div class="card-body">${E.empty("ri-error-warning-line", "Couldn't open the form", E.esc(o.message), '<button type="button" class="btn btn-primary" id="formRetry"><i class="ri-refresh-line me-1"></i>Try again</button>')}</div>`;
      $("formRetry").addEventListener("click", () => window.location.reload());
      return;
    }
    ov = o.data;
    if (!ov.can.manage) {
      Toast.warning(`Your role can't add ${N.many} here.`);
      window.location.href = `${CTX.baseUrl}/`;
      return;
    }
    if (e) {
      if (!e.ok || !e.data.can.edit) {
        Toast.warning(e.ok ? `This ${N.one} can't be changed any more.` : e.message);
        window.location.href = editId && e.ok ? `${CTX.baseUrl}/${N.page}?id=${editId}` : `${CTX.baseUrl}/`;
        return;
      }
      event = e.data;
      $("intakeSaved").textContent = "Saved";
      $("intakeSaved").className = "intake-saved me-auto is-saved";
      if (event.status === "published") {
        $("saveDraftBtn").hidden = true;
        $("publishBtn").innerHTML = '<i class="ri-save-line me-1"></i>Save changes';
      } else {
        $("publishBtn").innerHTML = '<i class="ri-send-plane-line me-1"></i>Save and publish';
      }
    }
    fillOptions();
    fill(event);
    renderPreview();

    const form = $("eventFormCard");
    form.addEventListener("input", markDirty);
    form.addEventListener("change", markDirty);
    form.addEventListener("submit", (ev) => ev.preventDefault());
    $("nextBtn").addEventListener("click", next);
    $("backBtn").addEventListener("click", () => go(step - 1));
    $("saveDraftBtn").addEventListener("click", () => save(false));
    $("publishBtn").addEventListener("click", () => save(!event || event.status === "draft"));
    document.querySelectorAll(".intake-step-btn").forEach((b) =>
      b.addEventListener("click", () => {
        const to = Number(b.dataset.go);
        if (to <= step) return go(to);
        for (let s = step; s < to; s++) {
          const errs = problems(s);
          if (Object.keys(errs).length) {
            go(s);
            return showErrors(errs);
          }
        }
        showErrors({});
        go(to);
      }),
    );
    window.addEventListener("beforeunload", (ev) => {
      if (dirty && !saving) {
        ev.preventDefault();
        ev.returnValue = "";
      }
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
