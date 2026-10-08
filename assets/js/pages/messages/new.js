/**
 * ============================================================================
 * MESSAGES - send a message (church, region, diocese)
 * ============================================================================
 * The page is v1-events' "Send campaign" composer (includes/messages/body-new.php):
 * channel cards, a saved message to start from (template chips), the message
 * with placeholder chips and the SMS counter, a test send; on the right who
 * gets it, the live counter, how it reads on a phone, and when. Review & send
 * opens its window: review -> sending -> done (or error).
 *
 * Who: people here by role; the places below (all, by subregion/region, or
 * picked) by role and/or their own contact; typed numbers or emails. It can be
 * opened filled in from an event ("Invite by message"), the reports below
 * ("Remind") or a saved message (?template=).
 *
 * 2026-10-08: pills in two colours - saved messages teal, people purple;
 * "About an event": one of our upcoming events fills [event], [date], [time]
 * and [venue] in (and ?event= opens with it); Schedule in its own window
 * (quick picks, the event's own times, or a set day and time, 5 minutes to 90
 * days ahead); how it reads in the real phone and mail-app frames.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const CTX = window.MESSAGES_CTX;
  const $ = (id) => document.getElementById(id);
  const q = new URLSearchParams(window.location.search);
  let opts = null;
  let preview = null;
  let previewTimer = null;
  let lastField = "bodyIn";
  const s = {
    own: [],
    scope: "none",
    levels: ["church"],
    groups: [],
    places: [],
    rolesBelow: [],
    contacts: false,
    typed: "",
    channel: "sms",
    subject: "",
    body: "",
    template: null,
    when: "now",
    sendAt: "",
    event: null,
  };
  let events = null; // upcoming events we can write about (null: the role can't see events)
  let frames = { sender: "", signature: "", from: "", html: "", subject: "" };
  let framesTimer = null;
  let readsView = "phone";
  const HINTS = {
    app: ["Only in the app", "The Inbox and the bell of everyone with a login."],
    sms: ["SMS", "And in the app for those with a login."],
    email: ["Email", "And in the app for those with a login."],
    both: ["SMS and email", "Both at once, and in the app."],
  };
  const sms = () => s.channel === "sms" || s.channel === "both";
  const email = () => s.channel === "email" || s.channel === "both";

  const audience = () => ({
    own: { roles: s.own },
    below: { scope: s.scope, levels: s.levels, group_ids: s.groups, place_ids: s.places, roles: s.rolesBelow, place_contacts: s.contacts },
    typed: s.typed.trim() ? [s.typed] : [],
  });

  // ================================================================ channel
  function renderChannels() {
    $("channelCards").innerHTML = Object.entries(M.CHANNELS)
      .map(
        ([k, c]) => `<label class="pb-channel">
          <input type="radio" name="channel" value="${k}"${s.channel === k ? " checked" : ""}>
          <span class="pb-channel-body"><i class="${c.icon}"></i>
            <span><span class="d-block fw-semibold fs-13">${HINTS[k][0]}</span><span class="d-block fs-11 text-muted">${HINTS[k][1]}</span></span>
          </span>
        </label>`,
      )
      .join("");
    $("channelCards").querySelectorAll("input").forEach((i) =>
      i.addEventListener("change", () => {
        s.channel = i.value;
        syncChannel();
        queuePreview();
      }),
    );
    syncChannel();
  }

  function syncChannel() {
    $("subjectWrap").hidden = s.channel === "sms";
    $("channelHint").innerHTML =
      s.channel === "app"
        ? '<i class="ri-information-line me-1"></i>Nobody gets an SMS or email - only those with a login see it.'
        : `<i class="ri-information-line me-1"></i>Everyone with a login also gets it in the app, whatever the channel.`;
    document.querySelectorAll("[data-channel-block]").forEach((b) => (b.hidden = b.dataset.channelBlock !== "app" && !(b.dataset.channelBlock === "sms" ? sms() : email())));
    $("testHint").textContent = s.channel === "app" ? "A test needs SMS or email - pick one above." : "Goes only to you, as it will read. It shows under Sent, marked [TEST].";
    $("testSendBtn").disabled = s.channel === "app";
    count();
  }

  // ================================================================ the message
  function renderTemplates() {
    const chip = (id, label, icon) => `<button type="button" class="pb-tpl-chip ${id === null ? "is-plain" : "is-tpl"}${s.template === id ? " active" : ""}" data-tpl="${id ?? ""}"><i class="${icon} me-1"></i>${M.esc(label)}</button>`;
    $("tplChips").innerHTML = opts.templates.length
      ? opts.templates.map((t) => chip(t.id, t.name, M.CHANNELS[t.channel]?.icon || "ri-bookmark-line")).join("") + chip(null, "Write my own", "ri-edit-line")
      : `<span class="fs-12 text-muted">No saved messages yet - use "Save for later use" below to keep one.</span>`;
  }

  async function useTemplate(id) {
    if (id === s.template) return;
    const t = opts.templates.find((x) => x.id === id);
    if (s.body.trim() && !(await M.ask({ title: t ? `Use "${t.name}"?` : "Start again?", text: "What you've written so far will be replaced.", icon: "ri-bookmark-line", action: t ? "Use it" : "Clear it" }))) return;
    s.template = id;
    s.channel = t ? t.channel : s.channel;
    s.subject = t ? t.subject || "" : "";
    s.body = t ? t.body : "";
    $("subjectIn").value = s.subject;
    $("bodyIn").value = s.body;
    renderChannels();
    renderTemplates();
    queuePreview();
  }

  function count() {
    const p = M.smsParts(s.body);
    const el = $("counter");
    el.textContent = sms() ? `${p.characters} / ${p.unicode ? 70 : 160} · ${p.parts || 1} SMS${p.unicode ? " (special characters)" : ""}` : `${p.characters} characters`;
    el.className = `fw-semibold text-nowrap ${sms() && p.parts > 2 ? "text-danger" : sms() && p.parts > 1 ? "text-warning" : "text-muted"}`;
  }

  /** v1's placeholder chips: insert at the cursor of the field used last (subject or message). */
  function insertToken(token) {
    const el = $(lastField) && !$(lastField).closest("[hidden]") ? $(lastField) : $("bodyIn");
    const [a, z] = [el.selectionStart ?? el.value.length, el.selectionEnd ?? el.value.length];
    el.value = el.value.slice(0, a) + token + el.value.slice(z);
    el.focus();
    el.selectionStart = el.selectionEnd = a + token.length;
    el.dispatchEvent(new Event("input"));
  }

  // ================================================================ who gets it
  const roleChip = (value, label, count, on, group) =>
    `<button type="button" class="pb-tpl-chip is-who${on ? " active" : ""}" data-chip="${group}" data-value="${M.esc(value)}" aria-pressed="${on}">${M.esc(label)}${count != null ? `<b>${count}</b>` : ""}</button>`;

  function renderWho() {
    const b = opts.below;
    const groupWord = b?.group_type === "region" ? "region" : "subregion";
    const seg = (name, items, value) =>
      `<div class="pb-segment" role="radiogroup">${items.map(([v, l]) => `<input type="radio" name="${name}" id="${name}-${v}" value="${v}"${value === v ? " checked" : ""}><label for="${name}-${v}">${l}</label>`).join("")}</div>`;
    $("whoBody").innerHTML = `
      <div class="pb-sub">Here at ${M.esc(opts.place.name)}</div>
      <div class="d-flex flex-wrap gap-1 mb-3">
        ${roleChip("*", "Everyone here", null, s.own.includes("*"), "own")}
        ${opts.own_roles.map((r) => roleChip(r.name, r.name, r.people, s.own.includes(r.name), "own")).join("")}
      </div>
      ${
        b
          ? `<div class="pb-sub">The places below</div>
        ${seg("scopeUi", [["none", "None"], ["all", "All"], ...(b.groups.length ? [["groups", `By ${groupWord}`]] : []), ["picked", "Pick"]], s.scope)}
        <div class="mt-2" id="belowFields" ${s.scope === "none" ? "hidden" : ""}>
          ${CTX.level === "diocese" ? `<div class="d-flex flex-wrap gap-3 mb-2"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="lvRegion" ${s.levels.includes("region") ? "checked" : ""}><label class="form-check-label fs-12" for="lvRegion">Regions</label></div><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="lvChurch" ${s.levels.includes("church") ? "checked" : ""}><label class="form-check-label fs-12" for="lvChurch">Churches</label></div></div>` : ""}
          <div class="mb-2" id="groupsWrap" ${s.scope === "groups" ? "" : "hidden"}><select class="form-select form-select-sm" id="groupsPick" multiple aria-label="Which ${groupWord}s">${b.groups.map((g) => `<option value="${g.id}"${s.groups.includes(g.id) ? " selected" : ""}>${M.esc(g.name)}</option>`).join("")}</select></div>
          <div class="mb-2" id="placesWrap" ${s.scope === "picked" ? "" : "hidden"}><select class="form-select form-select-sm" id="placesPick" multiple aria-label="Which places">${placeOptions()}</select></div>
          <div class="fs-12 text-muted mb-1">Who at those places</div>
          <div class="d-flex flex-wrap gap-1 mb-2">
            ${roleChip("*", "All leaders", null, s.rolesBelow.includes("*"), "below")}
            ${b.roles.slice(0, 12).map((r) => roleChip(r.name, r.name, r.people, s.rolesBelow.includes(r.name), "below")).join("")}
          </div>
          <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="contactsSw" ${s.contacts ? "checked" : ""}><label class="form-check-label fs-12" for="contactsSw">The places' own phone and email too</label></div>
        </div>
        <div class="mb-3"></div>`
          : ""
      }
      <div class="pb-sub">Numbers or emails typed in</div>
      <textarea class="form-control form-control-sm" id="typedIn" rows="2" placeholder="One per line, e.g. 0712 345 678 or someone@example.com">${M.esc(s.typed)}</textarea>
      <div class="fs-11 text-muted mt-1">For this message only - they aren't saved anywhere else.</div>`;

    $("whoBody").querySelectorAll("[data-chip]").forEach((btn) =>
      btn.addEventListener("click", () => {
        const list = btn.dataset.chip === "own" ? "own" : "rolesBelow";
        const v = btn.dataset.value;
        if (v === "*") s[list] = s[list].includes("*") ? [] : ["*"];
        else s[list] = s[list].includes(v) ? s[list].filter((x) => x !== v) : [...s[list].filter((x) => x !== "*"), v];
        renderWho();
        queuePreview();
      }),
    );
    if (b) {
      $("whoBody").querySelectorAll('input[name="scopeUi"]').forEach((i) =>
        i.addEventListener("change", () => {
          s.scope = i.value;
          if (s.scope !== "none" && !s.rolesBelow.length) s.rolesBelow = b.roles.some((r) => r.name === "Senior Pastor") ? ["Senior Pastor"] : ["*"];
          renderWho();
          queuePreview();
        }),
      );
      ["groupsPick", "placesPick"].forEach((id) => {
        const el = $(id);
        if (!el) return;
        UI.enhanceSelect(el, { placeholder: id === "groupsPick" ? `Pick ${groupWord}s` : "Search and pick places", closeOnSelect: false, search: true });
        el.addEventListener("change", () => {
          s[id === "groupsPick" ? "groups" : "places"] = [...el.selectedOptions].map((o) => Number(o.value));
          queuePreview();
        });
      });
      ["lvRegion", "lvChurch"].forEach((id) =>
        $(id)?.addEventListener("change", () => {
          s.levels = [$("lvRegion").checked && "region", $("lvChurch").checked && "church"].filter(Boolean);
          queuePreview();
        }),
      );
      $("contactsSw").addEventListener("change", () => {
        s.contacts = $("contactsSw").checked;
        queuePreview();
      });
    }
    $("typedIn").addEventListener("input", () => {
      s.typed = $("typedIn").value;
      queuePreview();
    });
  }

  function placeOptions() {
    const b = opts.below;
    const groups = new Map(b.groups.map((g) => [g.id, g.name]));
    const regions = b.places.filter((p) => p.type === "region");
    const byGroup = new Map();
    b.places
      .filter((p) => p.type === "church")
      .forEach((p) => {
        const k = groups.get(p.group_id) || "Churches";
        if (!byGroup.has(k)) byGroup.set(k, []);
        byGroup.get(k).push(p);
      });
    const opt = (p) => `<option value="${p.id}" data-icon="${p.type === "region" ? "ri-map-2-line" : "ri-home-heart-line"}" data-color="${p.type === "region" ? "purple" : "success"}"${s.places.includes(p.id) ? " selected" : ""}>${M.esc(p.name)}</option>`;
    return (regions.length ? `<optgroup label="Regions">${regions.map(opt).join("")}</optgroup>` : "") + [...byGroup.entries()].map(([g, list]) => `<optgroup label="${M.esc(g)}">${list.map(opt).join("")}</optgroup>`).join("");
  }

  // ================================================================ counter and phone
  function queuePreview() {
    clearTimeout(previewTimer);
    $("pbCounterSpin").classList.remove("d-none");
    renderPhone();
    queueFrames();
    previewTimer = setTimeout(loadPreview, 450);
  }

  async function loadPreview() {
    const res = await MessagesAPI.preview({ audience: audience(), channel: s.channel, body: s.body });
    $("pbCounterSpin").classList.add("d-none");
    preview = res.ok ? res.data : null;
    if (!res.ok) {
      $("counterNotes").innerHTML = `<div class="pb-note text-danger"><i class="ri-error-warning-line"></i><span>${M.esc(res.message)}</span></div>`;
      $("reviewBtn").disabled = true;
      return;
    }
    renderCounter();
  }

  function renderCounter() {
    const p = preview;
    $("countApp").textContent = M.num(p.with_login);
    $("countSms").textContent = M.num(p.with_phone);
    $("countEmail").textContent = M.num(p.with_email);
    $("countTotal").innerHTML = p.people ? `<b>${M.num(p.people)}</b> ${p.people === 1 ? "person" : "people"}${p.summary ? ` · ${M.esc(p.summary)}` : ""}` : "Nobody yet - pick who gets it above.";
    const notes = [];
    if (p.invalid?.length) notes.push(["text-danger", "ri-error-warning-line", `Not a phone number or email: ${p.invalid.map(M.esc).join(", ")}`]);
    if (sms() && p.people > p.with_phone) notes.push(["text-warning", "ri-information-line", `${M.num(p.people - p.with_phone)} have no phone - they get it ${email() && p.with_email ? "by email or " : ""}in the app if they have a login.`]);
    if (email() && p.people > p.with_email) notes.push(["text-warning", "ri-information-line", `${M.num(p.people - p.with_email)} have no email.`]);
    $("counterNotes").innerHTML =
      notes.map(([c, i, t]) => `<div class="pb-note ${c}"><i class="${i}"></i><span>${t}</span></div>`).join("") +
      (p.names?.length ? `<div class="d-flex flex-wrap gap-1 mt-2">${p.names.map((n) => `<span class="soft-chip soft-primary">${M.esc(n)}</span>`).join("")}${p.people > p.names.length ? `<span class="soft-chip soft-purple">+${p.people - p.names.length} more</span>` : ""}</div>` : "");
    renderPhone();
    $("reviewBtn").disabled = !p.people || !s.body.trim() || (p.invalid || []).length > 0;
  }

  const sample = () =>
    (s.body || "Your message shows here as you write it.")
      .replaceAll("{name}", preview?.names?.[0]?.split(" ")[0] || "Stephen")
      .replaceAll("{place}", opts.place.name)
      .replaceAll("{sender}", opts.place.name);

  // ================================================================ how it reads: the real frames
  const F = window.MessageFrames;

  /** The phone (and, for email, the mail app) as people really see it. */
  function renderPhone() {
    if (!$("readsPhoneStage").firstElementChild) $("readsPhoneStage").innerHTML = F.phoneHtml({ sender: frames.sender || opts.place.name, time: nowTime() });
    const stage = $("readsPhoneStage");
    const text = (email() || s.channel === "app") && s.subject ? `${s.subject}\n\n${sample()}` : sample();
    const signed = sms() && frames.signature ? `${text}\n- ${frames.signature}` : text;
    stage.querySelector('[data-pv="bubble"]').textContent = signed;
    stage.querySelector('[data-pv="sender"]').textContent = s.channel === "app" ? "In the app" : frames.sender || opts.place.name;
    stage.querySelector('[data-pv="initials"]').innerHTML = s.channel === "app" ? '<i class="ri-notification-3-line"></i>' : F.initials(frames.sender || opts.place.name);
    const p = M.smsParts(signed);
    $("phoneSeg").className = `nw-seg${sms() && p.parts > 1 ? " is-over" : ""}`;
    $("phoneSeg").innerHTML = sms() ? `<b>${p.characters}</b> characters · <b>${p.parts}</b> ${p.parts === 1 ? "text" : "texts"} each` : `<b>${p.characters}</b> characters`;
    // SMS and email: a toggle; email only: the mail app.
    $("readsSeg").hidden = s.channel !== "both";
    const view = s.channel === "email" ? "email" : s.channel === "both" ? readsView : "phone";
    $("readsPhoneStage").hidden = view !== "phone";
    $("readsEmailStage").hidden = view !== "email";
    $("phoneSeg").hidden = view !== "phone";
  }

  const nowTime = () => new Date().toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });

  /** The real email for what's written (debounced) - and once, the sender ID and the SMS signature. */
  function queueFrames() {
    clearTimeout(framesTimer);
    framesTimer = setTimeout(loadFrames, 500);
  }
  async function loadFrames() {
    if (!email()) return;
    const res = await MessagesAPI.emailPreview(s.subject || "", s.body || "");
    if (!res.ok) return;
    const d = res.data;
    const m = String(d.from || "").match(/^(.*?)\s*<([^>]*)>$/);
    if (!$("readsEmailStage").firstElementChild) {
      $("readsEmailStage").innerHTML = F.mailHtml({ subject: d.subject, fromName: m ? m[1] : d.from, fromAddr: m ? m[2] : "", to: preview?.names?.[0] || "Stephen Mutua", time: nowTime() });
      F.autoFit($("readsEmailStage").querySelector('[data-pv="frame"]'));
    }
    const stage = $("readsEmailStage");
    stage.querySelector('[data-pv="subject"]').textContent = d.subject;
    stage.querySelector('[data-pv="frame"]').srcdoc = d.html;
  }
  async function loadSender() {
    const res = await MessagesAPI.emailPreview("", "x");
    if (!res.ok) return;
    frames.sender = res.data.sender || "";
    const t = res.data.sms?.text || "";
    frames.signature = t.startsWith("x\n- ") ? t.slice(4) : "";
    renderPhone();
  }

  // ================================================================ about an event
  const dayName = (d) => d.toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" });
  const shortDay = (d) => d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
  const clock = (d) => d.toLocaleTimeString("en-GB", { hour: "numeric", minute: "2-digit", hour12: true });
  const dateTile = (d) => `<span class="pb-ev-date"><b>${d.getDate()}</b><small>${d.toLocaleDateString("en-GB", { month: "short" })}</small></span>`;
  function inWords(d) {
    const mins = Math.round((d - Date.now()) / 60000);
    if (mins < 60) return `in ${mins} minute${mins === 1 ? "" : "s"}`;
    if (mins < 60 * 24) {
      const h = Math.round(mins / 60);
      return `in ${h} hour${h === 1 ? "" : "s"}`;
    }
    const days = Math.round((new Date(d).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) / 86400000);
    return days === 1 ? "tomorrow" : `in ${days} days`;
  }
  const evFacts = (ev) => {
    const d = new Date(ev.starts_at);
    return { title: ev.title, day: dayName(d), time: clock(d), venue: ev.venue || "" };
  };

  function renderEventRow() {
    $("eventRow").hidden = !events;
    if (!events) return;
    if (!s.event) {
      $("eventPicked").innerHTML = `<button type="button" class="pb-event-pick" id="eventPickBtn"><i class="ri-calendar-event-line"></i>Pick an event</button><span class="fs-12 text-muted">${events.length ? `${events.length} coming up` : "No upcoming events"}</span>`;
    } else {
      const d = new Date(s.event.starts_at);
      $("eventPicked").innerHTML = `
        <div class="pb-event-chip">${dateTile(d)}<span class="min-w-0"><strong>${M.esc(s.event.title)}</strong><small>${M.esc(shortDay(d))} · ${M.esc(clock(d))}${s.event.venue ? ` · ${M.esc(s.event.venue)}` : ""}</small></span>
          <button type="button" class="pb-event-x" id="eventClear" aria-label="Not about this event"><i class="ri-close-line"></i></button></div>
        <button type="button" class="btn btn-sm btn-link px-1" id="eventPickBtn">Change</button>`;
    }
    $("eventPickBtn")?.addEventListener("click", openEvents);
    $("eventClear")?.addEventListener("click", () => {
      s.event = null;
      renderEventRow();
      renderEventTokens();
    });
  }

  function renderEventTokens() {
    if (!s.event) {
      $("eventTokens").innerHTML = "";
      return;
    }
    const f = evFacts(s.event);
    $("eventTokens").innerHTML = [["Event name", f.title], ["Date", f.day], ["Time", f.time], ...(f.venue ? [["Venue", f.venue]] : [])]
      .map(([l, v]) => `<button type="button" class="pb-token is-event" data-insert="${M.esc(v)}" title="${M.esc(v)}"><i class="ri-calendar-event-line"></i>${l}</button>`)
      .join("");
    $("eventTokens").querySelectorAll("[data-insert]").forEach((b) => b.addEventListener("click", () => insertToken(b.dataset.insert)));
  }

  /** The event's name, day, time and venue go where the message says [event], [date], [time], [venue]. */
  function pickEvent(ev, { fill = true } = {}) {
    s.event = ev;
    if (fill) {
      const f = evFacts(ev);
      const put = (t) => t.replace(/\[event\]/gi, f.title).replace(/\[date\]/gi, f.day).replace(/\[time\]/gi, f.time).replace(/\[venue\]/gi, f.venue || "the venue (to be confirmed)");
      if (!s.body.trim()) {
        s.body = `Dear {name},\n\nYou are invited to ${f.title} on ${f.day} at ${f.time}${f.venue ? `, ${f.venue}` : ""}.\n\n{sender}`;
        if (!s.subject.trim()) s.subject = f.title;
      } else {
        s.body = put(s.body);
        s.subject = put(s.subject);
      }
      $("subjectIn").value = s.subject;
      $("bodyIn").value = s.body;
      count();
      queuePreview();
    }
    renderEventRow();
    renderEventTokens();
  }

  let evModal = null;
  function openEvents() {
    const draw = () => {
      const q = $("evPickSearch").value.trim().toLowerCase();
      const list = events.filter((e) => !q || `${e.title} ${e.venue || ""} ${e.owner?.name || ""}`.toLowerCase().includes(q));
      $("evPickList").innerHTML = list.length
        ? list
            .map((e) => {
              const d = new Date(e.starts_at);
              const from = e.relation === "own" ? "Ours" : `From ${e.owner?.name || "above"}`;
              return `<button type="button" class="pb-ev-item${s.event?.id === e.id ? " active" : ""}" data-ev="${e.id}">${dateTile(d)}
                <span class="pb-ev-text"><strong>${M.esc(e.title)}</strong><small>${M.esc(shortDay(d))} · ${M.esc(clock(d))}${e.venue ? ` · ${M.esc(e.venue)}` : ""}</small><small>${M.esc(e.type_label || "Event")} · ${M.esc(from)}</small></span>
                <span class="soft-chip soft-purple">${inWords(d)}</span></button>`;
            })
            .join("")
        : M.empty("ri-calendar-event-line", q ? "No event matches" : "No upcoming events", q ? "Try another word." : "Published events of yours, and those shared with you, show here.", "purple");
    };
    if (!evModal) {
      evModal = new bootstrap.Modal($("evPickModal"));
      $("evPickSearch").addEventListener("input", draw);
      $("evPickList").addEventListener("click", (e) => {
        const b = e.target.closest("[data-ev]");
        if (!b) return;
        pickEvent(events.find((x) => x.id === Number(b.dataset.ev)));
        evModal.hide();
      });
    }
    $("evPickSearch").value = "";
    draw();
    evModal.show();
  }

  // ================================================================ when: now, or scheduled in its own window
  const MIN_AHEAD = 5 * 60000;
  const MAX_AHEAD = 90 * 86400000;
  const pad = (n) => String(n).padStart(2, "0");
  const toLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const at = (base, days, h, m = 0) => {
    const d = new Date(base);
    d.setDate(d.getDate() + days);
    d.setHours(h, m, 0, 0);
    return d;
  };
  const problem = (d) => {
    if (!d || isNaN(d)) return "Pick a day and a time.";
    const ahead = d - Date.now();
    if (ahead < 0) return "That time has passed.";
    if (ahead < MIN_AHEAD) return "Give it at least 5 minutes from now.";
    if (ahead > MAX_AHEAD) return "Up to 90 days ahead.";
    return null;
  };
  const nextWeekday = (wd, h) => {
    for (let i = 0; i < 8; i++) {
      const d = at(new Date(), i, h);
      if (d.getDay() === wd && d - Date.now() > MIN_AHEAD) return d;
    }
    return at(new Date(), 7, h);
  };

  function renderWhen() {
    if (s.when !== "later" || !s.sendAt) {
      $("whenBox").innerHTML = `
        <div class="pb-when-card">
          <span class="ev-tile is-soft" style="--q: var(--primary-rgb)"><i class="ri-send-plane-line"></i></span>
          <div class="flex-fill min-w-0"><strong>Sends straight away</strong><small>As soon as you review and confirm it.</small></div>
          <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="whenSchedule"><i class="ri-time-line me-1"></i>Schedule</button>
        </div>`;
      $("whenSchedule").addEventListener("click", openSchedule);
      return;
    }
    const d = new Date(s.sendAt);
    $("whenBox").innerHTML = `
      <div class="pb-when-card is-later">
        ${dateTile(d)}
        <div class="flex-fill min-w-0"><strong>Scheduled · ${M.esc(shortDay(d))}, ${M.esc(clock(d))}</strong><small>${inWords(d)}${problem(d) ? ` · <span class="text-danger">${problem(d)}</span>` : ""}</small></div>
        <div class="d-flex flex-wrap gap-1 justify-content-end">
          <button type="button" class="btn btn-sm btn-light border" id="whenChange"><i class="ri-edit-line me-1"></i>Change</button>
          <button type="button" class="btn btn-sm btn-light border" id="whenNowBtn">Send now instead</button>
        </div>
      </div>`;
    $("whenChange").addEventListener("click", openSchedule);
    $("whenNowBtn").addEventListener("click", () => {
      s.when = "now";
      s.sendAt = "";
      renderWhen();
    });
  }

  let schedModal = null;
  function openSchedule() {
    const tile = (d, icon, label, name) => {
      const bad = problem(d);
      return `<label class="ec-choice${bad ? " is-off" : ""}"${name === "ev" ? ' style="--q: var(--purple-rgb)"' : ""} title="${bad || ""}">
        <input type="radio" name="schedPick" value="${toLocal(d)}"${bad ? " disabled" : ""}${s.sendAt === toLocal(d) ? " checked" : ""}>
        <span class="ec-choice-icon"><i class="${icon}"></i></span>
        <span class="min-w-0"><strong>${label}</strong><small>${bad ? M.esc(bad) : `${M.esc(shortDay(d))} · ${M.esc(clock(d))}`}</small></span>
        <span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`;
    };
    const soon = new Date(Math.ceil((Date.now() + 3600000) / 300000) * 300000);
    $("schedPicks").innerHTML = [
      [soon, "ri-timer-line", "In an hour"],
      [at(new Date(), 0, 18), "ri-moon-line", "This evening"],
      [at(new Date(), 1, 8), "ri-sun-line", "Tomorrow morning"],
      [nextWeekday(6, 9), "ri-calendar-line", "Saturday morning"],
      [nextWeekday(0, 13), "ri-home-heart-line", "Sunday, after service"],
    ]
      .map(([d, i, l]) => tile(d, i, l, "q"))
      .join("");
    $("schedEventWrap").hidden = !s.event;
    if (s.event) {
      const st = new Date(s.event.starts_at);
      $("schedEventTitle").textContent = `Around ${s.event.title}`;
      $("schedEventPicks").innerHTML = [
        [at(st, -7, 18), "ri-calendar-2-line", "A week before"],
        [at(st, -1, 18), "ri-notification-3-line", "The day before"],
        [at(st, 0, 7), "ri-alarm-line", "The morning of"],
      ]
        .map(([d, i, l]) => tile(d, i, l, "ev"))
        .join("");
    }
    const start = s.sendAt ? new Date(s.sendAt) : at(new Date(), 1, 8);
    $("schedDate").value = toLocal(start).slice(0, 10);
    $("schedTime").value = toLocal(start).slice(11);
    $("schedDate").min = toLocal(new Date()).slice(0, 10);
    $("schedDate").max = toLocal(new Date(Date.now() + MAX_AHEAD)).slice(0, 10);
    if (!schedModal) {
      schedModal = new bootstrap.Modal($("schedModal"));
      $("schedModal").addEventListener("change", (e) => {
        if (e.target.name === "schedPick") {
          $("schedDate").value = e.target.value.slice(0, 10);
          $("schedTime").value = e.target.value.slice(11);
        } else if (e.target.id === "schedDate" || e.target.id === "schedTime") {
          $("schedModal").querySelectorAll('input[name="schedPick"]').forEach((r) => (r.checked = r.value === `${$("schedDate").value}T${$("schedTime").value}`));
        }
        schedLine();
      });
      $("schedTime").addEventListener("input", schedLine);
      $("schedSave").addEventListener("click", () => {
        s.when = "later";
        s.sendAt = `${$("schedDate").value}T${$("schedTime").value}`;
        renderWhen();
        schedModal.hide();
      });
    }
    schedLine();
    schedModal.show();
  }

  function schedLine() {
    const d = $("schedDate").value && $("schedTime").value ? new Date(`${$("schedDate").value}T${$("schedTime").value}`) : null;
    const bad = problem(d);
    $("schedSave").disabled = !!bad;
    $("schedLine").className = `pb-sched-line${bad ? " is-bad" : ""}`;
    $("schedLine").innerHTML = bad
      ? `<i class="ri-error-warning-line"></i><span>${bad}</span>`
      : `<i class="ri-time-line"></i><span>Sends <b>${M.esc(dayName(d))} at ${M.esc(clock(d))}</b> - ${inWords(d)}${preview?.people ? `, to ${M.num(preview.people)} ${preview.people === 1 ? "person" : "people"}` : ""}.</span>`;
  }

  // ================================================================ review -> sending -> done
  let confirmModal = null;
  function state(name) {
    document.querySelectorAll("#pbConfirmModal [data-pb-state]").forEach((el) => el.classList.toggle("d-none", el.dataset.pbState !== name));
  }

  function review() {
    if (!preview) return;
    const later = s.when === "later";
    if (later && problem(new Date(s.sendAt))) {
      Toast.warning(`${problem(new Date(s.sendAt))} Change when it goes out.`);
      return openSchedule();
    }
    const p = M.smsParts(sample());
    const rows = [
      ["How", M.CHANNELS[s.channel].label],
      ["To", preview.summary || `${M.num(preview.people)} people`],
      ["People", M.num(preview.people)],
      ...(sms() ? [["SMS", `${M.num(preview.with_phone)} · ${p.parts} ${p.parts === 1 ? "text" : "texts"} each`]] : []),
      ...(email() ? [["Emails", M.num(preview.with_email)]] : []),
      ["In the app", M.num(preview.with_login)],
      ...(s.subject && s.channel !== "sms" ? [["Subject", s.subject]] : []),
      ["When", later ? `${shortDay(new Date(s.sendAt))}, ${clock(new Date(s.sendAt))}` : "Now"],
    ];
    $("pbSummary").innerHTML = rows.map(([k, v]) => `<div class="pb-summary-row"><span>${k}</span><span>${M.esc(v)}</span></div>`).join("");
    $("pbSummaryNotes").innerHTML = $("counterNotes").querySelector(".pb-note") ? [...$("counterNotes").querySelectorAll(".pb-note")].map((n) => n.outerHTML).join("") : "";
    $("pbConfirmTitle").textContent = later ? "Ready to schedule?" : "Ready to send?";
    $("pbConfirmSub").textContent = s.channel === "app" ? "It goes to their Inbox and bell." : "Once sent, an SMS or email can't be called back.";
    $("pbSendBtn").innerHTML = later ? '<i class="ri-time-line me-1"></i>Yes, schedule it' : '<i class="ri-send-plane-line me-1"></i>Yes, send it';
    state("confirm");
    confirmModal.show();
  }

  async function send() {
    const later = s.when === "later";
    state("sending");
    $("pbSendingMsg").textContent = later ? "Scheduling..." : "Sending...";
    const res = await MessagesAPI.send({ audience: audience(), channel: s.channel, subject: s.subject || null, body: s.body, send_at: later ? new Date(s.sendAt).toISOString() : null });
    if (!res.ok) {
      $("pbErrorBody").innerHTML = `<p class="mb-0 text-center">${M.esc(res.message)}</p>`;
      return state("error");
    }
    const b = res.data;
    $("pbDoneTitle").textContent = later ? "Scheduled" : "Sent";
    $("pbDoneSub").textContent = later ? `It goes out ${M.when(b.scheduled_at || new Date(s.sendAt).toISOString())}.` : res.message || "On its way.";
    const tiles = [
      ["People", preview.people],
      ...(sms() ? [["SMS", preview.with_phone]] : []),
      ...(email() ? [["Emails", preview.with_email]] : []),
      ["In the app", preview.with_login],
    ];
    $("pbDoneTiles").innerHTML = tiles.map(([k, v]) => `<div class="col"><div class="pb-result-tile"><strong>${M.num(v)}</strong>${k}</div></div>`).join("");
    $("pbDoneOpen").href = `${CTX.baseUrl}/message?id=${b.id}`;
    state("done");
  }

  async function testSend() {
    const to = $("testTo").value.trim();
    if (!to) return Toast.warning("Put in your phone or email.");
    if (!s.body.trim()) return Toast.warning("Write the message first.");
    UI.setButtonLoading($("testSendBtn"), "Sending...");
    const res = await MessagesAPI.send({ audience: { own: { roles: [] }, below: { scope: "none", levels: [], group_ids: [], place_ids: [], roles: [], place_contacts: false }, typed: [to] }, channel: s.channel, subject: `[TEST] ${s.subject || "Test message"}`, body: sample(), send_at: null });
    UI.restoreButton($("testSendBtn"));
    $("testResult").innerHTML = res.ok
      ? `<div class="pb-note text-success"><i class="ri-checkbox-circle-line"></i><span>Test sent to ${M.esc(to)}.</span></div>`
      : `<div class="pb-note text-danger"><i class="ri-error-warning-line"></i><span>${M.esc(res.message)}</span></div>`;
  }

  async function saveForLater() {
    if (!s.body.trim()) return Toast.warning("Write the message first.");
    const name = await askName(s.subject || s.body.slice(0, 40));
    if (!name) return;
    const res = await MessagesAPI.saveTemplate(null, { name: name.slice(0, 80), channel: s.channel, subject: s.subject || null, body: s.body });
    if (!res.ok) return Toast.error(res.message);
    opts.templates.push(res.data);
    s.template = res.data.id;
    renderTemplates();
    Toast.success(res.message);
  }

  /** A small window asking for the saved message's name; resolves to it, or null. */
  function askName(suggested) {
    document.getElementById("nameModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="nameModal" tabindex="-1" aria-labelledby="nameTitle">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon bg-purple text-white"><i class="ri-bookmark-line"></i></span><div class="flex-fill"><h5 class="modal-title" id="nameTitle">Save for later use</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body"><label class="form-label" for="nameIn">Name</label><input class="form-control" id="nameIn" maxlength="80" value="${M.esc(suggested)}"><div class="form-text">It shows under Saved, and here as a saved message to start from.</div></div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Not now</button><button type="button" class="btn btn-primary" id="nameGo"><i class="ri-check-line me-1"></i>Save</button></div>
        </div></div>
      </div>`,
    );
    const el = $("nameModal");
    const modal = new bootstrap.Modal(el);
    return new Promise((resolve) => {
      let answer = null;
      $("nameGo").addEventListener("click", () => {
        answer = $("nameIn").value.trim() || null;
        modal.hide();
      });
      el.addEventListener("shown.bs.modal", () => $("nameIn").select());
      el.addEventListener("hidden.bs.modal", () => {
        el.remove();
        resolve(answer);
      });
      modal.show();
    });
  }

  // ================================================================ start
  function prefill() {
    if (q.get("own")) s.own = q.get("own").split(",");
    if (q.get("places") && opts.below) {
      s.scope = "picked";
      s.places = q.get("places").split(",").map(Number).filter(Boolean);
    } else if (q.get("scope") && opts.below) {
      s.scope = q.get("scope");
    }
    if (q.get("levels")) s.levels = q.get("levels").split(",");
    if (q.get("roles") && opts.below) s.rolesBelow = q.get("roles").split(",");
    if (q.get("channel") && M.CHANNELS[q.get("channel")]) s.channel = q.get("channel");
    if (q.get("subject")) s.subject = q.get("subject");
    if (q.get("body")) s.body = q.get("body");
    // A number from a member's page or a birthday (Members, docs/specs/people-and-care-spec.md).
    if (q.get("typed")) s.typed = q.get("typed");
    const tpl = opts.templates.find((t) => t.id === Number(q.get("template")));
    if (tpl) Object.assign(s, { template: tpl.id, channel: tpl.channel, subject: tpl.subject || "", body: tpl.body });
    if (!s.own.length && s.scope === "none" && !opts.below) s.own = ["*"];
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const res = await MessagesAPI.options();
    if (!res.ok) {
      $("composer").innerHTML = `<div class="card-body">${M.empty("ri-lock-line", "You can't send messages here", M.esc(res.message), "danger")}</div>`;
      return;
    }
    opts = res.data;
    prefill();
    $("subjectIn").value = s.subject;
    $("bodyIn").value = s.body;
    renderChannels();
    renderTemplates();
    renderWho();
    confirmModal = new bootstrap.Modal($("pbConfirmModal"));

    $("tplChips").addEventListener("click", (e) => {
      const c = e.target.closest("[data-tpl]");
      if (c) useTemplate(c.dataset.tpl ? Number(c.dataset.tpl) : null);
    });
    ["subjectIn", "bodyIn"].forEach((id) => $(id).addEventListener("focus", () => (lastField = id)));
    $("subjectIn").addEventListener("input", () => {
      s.subject = $("subjectIn").value;
      renderPhone();
      queueFrames();
    });
    $("bodyIn").addEventListener("input", () => {
      s.body = $("bodyIn").value;
      count();
      queuePreview();
    });
    document.querySelectorAll("[data-token]").forEach((b) => b.addEventListener("click", () => insertToken(b.dataset.token)));
    document.querySelectorAll('input[name="readsUi"]').forEach((i) =>
      i.addEventListener("change", () => {
        readsView = i.value;
        renderPhone();
        if (readsView === "email") loadFrames();
      }),
    );
    renderWhen();
    loadSender();
    // Our upcoming events, to write about (and ?event= from an event's "Invite by message").
    MessagesAPI.events().then((list) => {
      events = list;
      const ev = list?.find((e) => e.id === Number(q.get("event")));
      if (ev) pickEvent(ev, { fill: /\[(event|date|time|venue)\]/i.test(s.body + s.subject) });
      else renderEventRow();
    });
    $("testTo").value = (() => {
      try {
        const me = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.USER_DATA) || "null");
        return me?.phone || me?.email || "";
      } catch (e) {
        return "";
      }
    })();
    $("testSendBtn").addEventListener("click", testSend);
    $("saveTemplateBtn").addEventListener("click", saveForLater);
    $("reviewBtn").addEventListener("click", review);
    $("pbSendBtn").addEventListener("click", send);
    $("pbBackBtn").addEventListener("click", () => confirmModal.hide());
    $("pbErrorBackBtn").addEventListener("click", () => confirmModal.hide());
    queuePreview();
  });
})();
