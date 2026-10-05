/**
 * ============================================================================
 * MESSAGES - send a message (church, region, diocese)
 * ============================================================================
 * Who: people here by role; the places below (all, by subregion/region, or
 * picked) by role and/or their own contact; typed numbers or emails. The
 * message: SMS / email / both / in the app, a part counter, saved messages
 * and {name}/{place}/{sender}. A live preview says how many it reaches and
 * how it reads. Send now or schedule; it can be opened filled in from an
 * event ("Invite by message") or the reports below ("Remind").
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
    when: "now",
    sendAt: "",
  };

  const audience = () => ({
    own: { roles: s.own },
    below: { scope: s.scope, levels: s.levels, group_ids: s.groups, place_ids: s.places, roles: s.rolesBelow, place_contacts: s.contacts },
    typed: s.typed.trim() ? [s.typed] : [],
  });

  // ================================================================ who
  function chip(value, label, count, on, group) {
    return `<button type="button" class="cal-layer${on ? " active" : ""}" data-colour="primary" data-chip="${group}" data-value="${M.esc(value)}" aria-pressed="${on}">${M.esc(label)}${count != null ? ` <b>${count}</b>` : ""}</button>`;
  }

  function renderWho() {
    const b = opts.below;
    const groupWord = b?.group_type === "region" ? "region" : "subregion";
    $("whoBody").innerHTML = `
      <div class="msg-who">
        <h6 class="ev-sub">Here at ${M.esc(opts.place.name)}</h6>
        <div class="d-flex flex-wrap gap-2" id="ownChips">
          ${chip("*", "Everyone here", null, s.own.includes("*"), "own")}
          ${opts.own_roles.map((r) => chip(r.name, r.name, r.people, s.own.includes(r.name), "own")).join("")}
        </div>
      </div>
      ${
        b
          ? `<div class="msg-who">
        <h6 class="ev-sub">The places below</h6>
        <div id="scopeWrap">${UI.renderSegmented("scopeSeg", [
          { value: "none", label: "None" },
          { value: "all", label: "All of them" },
          ...(b.groups.length ? [{ value: "groups", label: `By ${groupWord}` }] : []),
          { value: "picked", label: "Pick places" },
        ], s.scope, { ariaLabel: "Which places" })}</div>
        <div class="row g-3 mt-1" id="belowFields" ${s.scope === "none" ? "hidden" : ""}>
          ${CTX.level === "diocese" ? `<div class="col-12"><div class="d-flex flex-wrap gap-3"><div class="form-check"><input class="form-check-input" type="checkbox" id="lvRegion" ${s.levels.includes("region") ? "checked" : ""}><label class="form-check-label" for="lvRegion">Regions</label></div><div class="form-check"><input class="form-check-input" type="checkbox" id="lvChurch" ${s.levels.includes("church") ? "checked" : ""}><label class="form-check-label" for="lvChurch">Churches</label></div></div></div>` : ""}
          <div class="col-12" id="groupsWrap" ${s.scope === "groups" ? "" : "hidden"}><label class="form-label" for="groupsPick">Which ${groupWord}s</label><select class="form-select" id="groupsPick" multiple>${b.groups.map((g) => `<option value="${g.id}"${s.groups.includes(g.id) ? " selected" : ""}>${M.esc(g.name)}</option>`).join("")}</select></div>
          <div class="col-12" id="placesWrap" ${s.scope === "picked" ? "" : "hidden"}><label class="form-label" for="placesPick">Which places</label><select class="form-select" id="placesPick" multiple>${placeOptions()}</select></div>
          <div class="col-12"><label class="form-label mb-1">Who at those places</label><div class="d-flex flex-wrap gap-2" id="belowChips">
            ${chip("*", "All leaders", null, s.rolesBelow.includes("*"), "below")}
            ${b.roles.slice(0, 12).map((r) => chip(r.name, r.name, r.people, s.rolesBelow.includes(r.name), "below")).join("")}
          </div></div>
          <div class="col-12"><div class="ev-switch-row"><div><strong>The places' own phone and email too</strong><span>As set in each place's profile</span></div><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="contactsSw" ${s.contacts ? "checked" : ""} aria-label="The places' own contacts"></div></div></div>
        </div>
      </div>`
          : ""
      }
      <div class="msg-who">
        <h6 class="ev-sub">Numbers or emails typed in</h6>
        <textarea class="form-control" id="typedIn" rows="2" placeholder="One per line, e.g. 0712 345 678 or someone@example.com">${M.esc(s.typed)}</textarea>
        <div class="form-text">For this message only - they aren't saved anywhere else.</div>
      </div>`;

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
      UI.wireSegmented("scopeSeg", (v) => {
        s.scope = v;
        if (v !== "none" && !s.rolesBelow.length) s.rolesBelow = b.roles.some((r) => r.name === "Senior Pastor") ? ["Senior Pastor"] : ["*"];
        renderWho();
        queuePreview();
      });
      ["groupsPick", "placesPick"].forEach((id) => {
        const el = $(id);
        if (!el) return;
        UI.enhanceSelect(el, { placeholder: id === "groupsPick" ? "Pick them" : "Search and pick places", closeOnSelect: false, search: true });
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
    b.places.filter((p) => p.type === "church").forEach((p) => {
      const k = groups.get(p.group_id) || "Churches";
      if (!byGroup.has(k)) byGroup.set(k, []);
      byGroup.get(k).push(p);
    });
    const opt = (p) => `<option value="${p.id}" data-icon="${p.type === "region" ? "ri-map-2-line" : "ri-home-heart-line"}" data-color="${p.type === "region" ? "purple" : "success"}"${s.places.includes(p.id) ? " selected" : ""}>${M.esc(p.name)}</option>`;
    return (regions.length ? `<optgroup label="Regions">${regions.map(opt).join("")}</optgroup>` : "") + [...byGroup.entries()].map(([g, list]) => `<optgroup label="${M.esc(g)}">${list.map(opt).join("")}</optgroup>`).join("");
  }

  // ================================================================ the message
  function renderMessage() {
    $("messageBody").innerHTML = `
      <div id="channelWrap">${UI.renderSegmented("channelSeg", Object.entries(M.CHANNELS).map(([k, c]) => ({ value: k, label: `<i class="${c.icon} me-1"></i>${c.label}` })), s.channel, { ariaLabel: "How it is sent" })}</div>
      <div class="form-text mb-3" id="channelHint"></div>
      <div class="mb-3" id="subjectWrap"><label class="form-label" for="subjectIn">Subject</label><input class="form-control" id="subjectIn" maxlength="120" value="${M.esc(s.subject)}" placeholder="e.g. Youth convention this Saturday"></div>
      <label class="form-label" for="bodyIn">Message</label>
      <textarea class="form-control" id="bodyIn" rows="6" maxlength="10000" placeholder="Dear {name}, ...">${M.esc(s.body)}</textarea>
      <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <span class="fw-semibold me-1 fs-13">Insert</span>
        ${["{name}", "{place}", "{sender}"].map((v) => `<button type="button" class="soft-chip soft-primary border-0" data-insert="${v}">${v}</button>`).join("")}
        <span class="ms-auto fs-13 fw-semibold" id="counter"></span>
      </div>`;
    UI.wireSegmented("channelSeg", (v) => {
      s.channel = v;
      syncChannel();
      queuePreview();
    });
    $("subjectIn").addEventListener("input", () => {
      s.subject = $("subjectIn").value;
      renderPreview();
    });
    $("bodyIn").addEventListener("input", () => {
      s.body = $("bodyIn").value;
      count();
      queuePreview();
    });
    $("messageBody").querySelectorAll("[data-insert]").forEach((b) =>
      b.addEventListener("click", () => {
        const el = $("bodyIn");
        const [a, z] = [el.selectionStart ?? el.value.length, el.selectionEnd ?? el.value.length];
        el.value = el.value.slice(0, a) + b.dataset.insert + el.value.slice(z);
        el.focus();
        el.selectionStart = el.selectionEnd = a + b.dataset.insert.length;
        el.dispatchEvent(new Event("input"));
      }),
    );
    syncChannel();
    count();

    $("templatePickWrap").innerHTML = opts.templates.length
      ? `<select class="form-select" id="templatePick" aria-label="Use a saved message" style="min-width:13rem"><option value="">Use a saved message</option>${opts.templates.map((t) => `<option value="${t.id}">${M.esc(t.name)}</option>`).join("")}</select>`
      : "";
    if ($("templatePick")) {
      UI.enhanceSelect("templatePick", { search: false });
      $("templatePick").addEventListener("change", () => useTemplate(Number($("templatePick").value)));
    }
  }

  function syncChannel() {
    $("subjectWrap").hidden = s.channel === "sms";
    $("channelHint").textContent = {
      app: "Only in the app: the Inbox and the bell of everyone with a login.",
      sms: "By SMS, and in the app for those with a login.",
      email: "By email, and in the app for those with a login.",
      both: "By SMS and email, and in the app for those with a login.",
    }[s.channel];
  }

  function count() {
    const p = M.smsParts(s.body);
    const sms = s.channel === "sms" || s.channel === "both";
    $("counter").textContent = sms ? `${p.characters} characters · ${p.parts} ${p.parts === 1 ? "SMS" : "SMS parts"}${p.unicode ? " (special characters)" : ""}` : `${p.characters} characters`;
    $("counter").classList.toggle("text-danger", sms && p.characters > 1600);
  }

  function useTemplate(id) {
    const t = opts.templates.find((x) => x.id === id);
    if (!t) return;
    s.channel = t.channel;
    s.subject = t.subject || "";
    s.body = t.body;
    renderMessage();
    queuePreview();
    Toast.success(`"${t.name}" filled in - change anything before you send.`);
  }

  // ================================================================ preview
  function queuePreview() {
    clearTimeout(previewTimer);
    $("reachChip").innerHTML = `<span class="soft-chip soft-primary"><i class="ri-loader-4-line ri-spin"></i>Counting</span>`;
    previewTimer = setTimeout(loadPreview, 450);
  }

  async function loadPreview() {
    const res = await MessagesAPI.preview({ audience: audience(), channel: s.channel, body: s.body });
    preview = res.ok ? res.data : null;
    if (!res.ok) {
      $("reachChip").innerHTML = `<span class="soft-chip soft-danger">${M.esc(res.message)}</span>`;
      return;
    }
    renderPreview();
  }

  function renderPreview() {
    const p = preview;
    const sms = s.channel === "sms" || s.channel === "both";
    const email = s.channel === "email" || s.channel === "both";
    const parts = M.smsParts(p?.names?.[0] ? s.body.replaceAll("{name}", p.names[0].split(" ")[0]) : s.body);
    $("reachChip").innerHTML = p ? `<span class="soft-chip soft-${p.people ? "success" : "danger"}"><i class="ri-group-line"></i>Reaches ${M.num(p.people)}</span>` : "";
    const sample = (s.body || "Your message shows here.").replaceAll("{name}", p?.names?.[0]?.split(" ")[0] || "friend").replaceAll("{place}", opts.place.name).replaceAll("{sender}", opts.place.name);
    $("previewBody").innerHTML = `
      ${p && p.summary ? `<div class="fw-semibold mb-2">${M.esc(p.summary)}</div>` : ""}
      ${p ? `<ul class="ev-facts mb-2">
        ${sms ? `<li><i class="ri-message-2-line"></i><span><b>${M.num(p.with_phone)}</b> by SMS · ${parts.parts} ${parts.parts === 1 ? "part" : "parts"} each</span></li>` : ""}
        ${email ? `<li><i class="ri-mail-line"></i><span><b>${M.num(p.with_email)}</b> by email</span></li>` : ""}
        <li><i class="ri-notification-3-line"></i><span><b>${M.num(p.with_login)}</b> in the app</span></li>
      </ul>` : ""}
      ${p?.invalid?.length ? `<div class="alert alert-danger py-2 mb-2">Not a phone number or email: ${p.invalid.map(M.esc).join(", ")}</div>` : ""}
      ${p?.names?.length ? `<div class="d-flex flex-wrap gap-1 mb-3">${p.names.map((n) => `<span class="soft-chip soft-primary">${M.esc(n)}</span>`).join("")}${p.people > p.names.length ? `<span class="soft-chip soft-purple">+${p.people - p.names.length} more</span>` : ""}</div>` : ""}
      ${sms ? `<div class="msg-phone"><div class="msg-phone-head"><i class="ri-message-2-line"></i>${M.esc(opts.place.name)}</div><div class="msg-bubble is-them"><p class="mb-0">${M.esc(sample)}</p></div></div>` : ""}
      ${email || s.channel === "app" ? `<div class="msg-email mt-2"><div class="msg-email-head"><small>${M.esc(opts.place.name)}</small><strong>${M.esc(s.subject || "(No subject)")}</strong></div><p class="mb-0">${M.esc(sample)}</p></div>` : ""}`;
    $("sendBtn").disabled = !p || !p.people || !s.body.trim() || (p.invalid || []).length > 0;
  }

  function renderWhen() {
    $("whenBox").innerHTML = `
      ${UI.renderSegmented("whenSeg", [{ value: "now", label: "Send now" }, { value: "later", label: "Schedule" }], s.when, { ariaLabel: "When" })}
      <div class="mt-2" id="laterWrap" ${s.when === "later" ? "" : "hidden"}><label class="form-label" for="sendAtIn">Send on</label><input type="datetime-local" class="form-control" id="sendAtIn" value="${s.sendAt}"></div>`;
    UI.wireSegmented("whenSeg", (v) => {
      s.when = v;
      $("laterWrap").hidden = v !== "later";
      $("sendBtn").innerHTML = v === "later" ? '<i class="ri-time-line me-1"></i>Schedule it' : '<i class="ri-send-plane-line me-1"></i>Send now';
    });
    $("sendAtIn").addEventListener("input", () => (s.sendAt = $("sendAtIn").value));
  }

  // ================================================================ send / save
  async function send() {
    if (!preview) return;
    const later = s.when === "later";
    if (later && !s.sendAt) return Toast.warning("Pick when to send it.");
    const how = M.CHANNELS[s.channel].label.toLowerCase();
    const ok = await M.ask({
      title: later ? "Schedule this message?" : "Send this message?",
      text: `<b>${M.esc(preview.summary)}</b> - ${M.num(preview.people)} ${preview.people === 1 ? "person" : "people"}, ${s.channel === "app" ? "in the app" : `by ${M.esc(how)}`}${later ? `, on ${M.esc(new Date(s.sendAt).toLocaleString("en-GB", { weekday: "short", day: "numeric", month: "short", hour: "numeric", minute: "2-digit" }))}` : ""}.`,
      icon: "ri-send-plane-line",
      color: "success",
      action: later ? "Schedule it" : "Send it",
      actionColor: "success",
    });
    if (!ok) return;
    UI.setButtonLoading($("sendBtn"), later ? "Scheduling..." : "Sending...");
    const res = await MessagesAPI.send({ audience: audience(), channel: s.channel, subject: s.subject || null, body: s.body, send_at: later ? new Date(s.sendAt).toISOString() : null });
    UI.restoreButton($("sendBtn"));
    if (!res.ok) return Toast.error(res.message);
    sessionStorage.setItem("mwd-messages-flash", res.message);
    window.location.href = `${CTX.baseUrl}/message?id=${res.data.id}`;
  }

  async function saveForLater() {
    if (!s.body.trim()) return Toast.warning("Write the message first.");
    const name = await askName(s.subject || s.body.slice(0, 40));
    if (!name) return;
    const res = await MessagesAPI.saveTemplate(null, { name: name.slice(0, 80), channel: s.channel, subject: s.subject || null, body: s.body });
    if (!res.ok) return Toast.error(res.message);
    opts.templates.push(res.data);
    renderMessage();
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
          <div class="modal-body"><label class="form-label" for="nameIn">Name</label><input class="form-control" id="nameIn" maxlength="80" value="${M.esc(suggested)}"><div class="form-text">It shows under Saved messages, and in "Use a saved message".</div></div>
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
    const tpl = opts.templates.find((t) => t.id === Number(q.get("template")));
    if (tpl) Object.assign(s, { channel: tpl.channel, subject: tpl.subject || "", body: tpl.body });
    if (!s.own.length && s.scope === "none" && !opts.below) s.own = ["*"];
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const res = await MessagesAPI.options();
    if (!res.ok) {
      $("composer").innerHTML = `<div class="col-12"><div class="card custom-card"><div class="card-body">${M.empty("ri-lock-line", "You can't send messages here", M.esc(res.message), "danger")}</div></div></div>`;
      return;
    }
    opts = res.data;
    prefill();
    renderWho();
    renderMessage();
    renderWhen();
    $("sendBtn").addEventListener("click", send);
    $("saveTemplateBtn").addEventListener("click", saveForLater);
    queuePreview();
  });
})();
