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
  };
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
    const chip = (id, label, icon) => `<button type="button" class="pb-tpl-chip${s.template === id ? " active" : ""}" data-tpl="${id ?? ""}"><i class="${icon} me-1"></i>${M.esc(label)}</button>`;
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
    `<button type="button" class="pb-tpl-chip${on ? " active" : ""}" data-chip="${group}" data-value="${M.esc(value)}" aria-pressed="${on}">${M.esc(label)}${count != null ? `<b>${count}</b>` : ""}</button>`;

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

  /** v1's template writer phone: the message as it lands, and how many texts it takes. */
  function renderPhone() {
    $("phoneFrom").innerHTML = `<i class="${M.CHANNELS[s.channel].icon} me-1"></i>${M.esc(opts.place.name)}`;
    $("phoneBubble").textContent = (email() || s.channel === "app") && s.subject ? `${s.subject}\n\n${sample()}` : sample();
    const p = M.smsParts(sample());
    $("phoneSeg").className = `nw-seg${sms() && p.parts > 1 ? " is-over" : ""}`;
    $("phoneSeg").innerHTML = sms() ? `<b>${p.characters}</b> characters · <b>${p.parts}</b> ${p.parts === 1 ? "text" : "texts"} each` : `<b>${p.characters}</b> characters`;
  }

  // ================================================================ review -> sending -> done
  let confirmModal = null;
  function state(name) {
    document.querySelectorAll("#pbConfirmModal [data-pb-state]").forEach((el) => el.classList.toggle("d-none", el.dataset.pbState !== name));
  }

  function review() {
    if (!preview) return;
    const later = s.when === "later";
    if (later && !s.sendAt) return Toast.warning("Pick when to send it.");
    const p = M.smsParts(sample());
    const rows = [
      ["How", M.CHANNELS[s.channel].label],
      ["To", preview.summary || `${M.num(preview.people)} people`],
      ["People", M.num(preview.people)],
      ...(sms() ? [["SMS", `${M.num(preview.with_phone)} · ${p.parts} ${p.parts === 1 ? "text" : "texts"} each`]] : []),
      ...(email() ? [["Emails", M.num(preview.with_email)]] : []),
      ["In the app", M.num(preview.with_login)],
      ...(s.subject && s.channel !== "sms" ? [["Subject", s.subject]] : []),
      ["When", later ? new Date(s.sendAt).toLocaleString("en-GB", { weekday: "short", day: "numeric", month: "short", hour: "numeric", minute: "2-digit" }) : "Now"],
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
    });
    $("bodyIn").addEventListener("input", () => {
      s.body = $("bodyIn").value;
      count();
      queuePreview();
    });
    document.querySelectorAll("[data-token]").forEach((b) => b.addEventListener("click", () => insertToken(b.dataset.token)));
    document.querySelectorAll('input[name="whenUi"]').forEach((i) =>
      i.addEventListener("change", () => {
        s.when = i.value;
        $("laterWrap").hidden = s.when !== "later";
      }),
    );
    $("sendAtIn").addEventListener("input", () => (s.sendAt = $("sendAtIn").value));
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
