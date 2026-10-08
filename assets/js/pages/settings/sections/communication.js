/**
 * SETTINGS - Communication (S6b; v1-events look, 2026-10-08).
 *
 * The form is still the generic registry form (fields.js) - every input keeps
 * its data-key, so saving, Reset, "Changed" and the save bar work as before.
 * This extra arranges it the v1-events way:
 *   - "What applies now" (v1's ns-what): email and SMS side by side, each with
 *     how it goes, the From line / sender, and whether it really sends;
 *   - "How we send" as two big choice cards over the comms.mode select;
 *   - "How it looks": the fields beside a live preview - an email header and
 *     v1's phone with the sender and the signature (+ a 30-character counter).
 * The test card and the message log come after (fields.js / messages.js).
 */
(function () {
  "use strict";

  const F = window.SettingsFields;
  const esc = F.esc;

  const MODES = {
    diocese: { title: "Through the diocese", text: "The diocese's email and SMS. Nothing to set up.", icon: "ri-building-4-line", tag: "Recommended" },
    own: { title: "Our own account", text: "Your own email server and SMS account, under your own name.", icon: "ri-key-2-line", tag: "" },
  };

  F.extras.communication = function (root, payload) {
    const after = () => {
      root.insertAdjacentHTML("beforeend", '<div id="commsMessages"></div>');
      window.SettingsMessages?.mount(root.querySelector("#commsMessages"));
    };
    const links = [{ id: "card-messages", label: "Messages" }];
    const c = payload.extra || {};
    const $ = (id) => root.querySelector(`#${id}`);

    // ------------------------------------------------ How we send: choice cards (every level)
    const modeSel = root.querySelector('select[data-key="comms.mode"]');
    if (modeSel) {
      const field = modeSel.closest("[data-field]");
      const locked = modeSel.disabled;
      field.querySelector(".form-label")?.closest("div")?.classList.add("visually-hidden");
      field.insertAdjacentHTML(
        "afterbegin",
        `<div class="cm-modes" role="radiogroup" aria-label="How we send">${Object.entries(MODES)
          .map(
            ([k, m], i) => `<label class="cm-mode${locked ? " is-locked" : ""}" style="--q: var(--${i ? "purple" : "primary"}-rgb)">
            <input type="radio" name="cm_mode_ui" value="${k}"${modeSel.value === k ? " checked" : ""}${locked ? " disabled" : ""}>
            <span class="cm-mode-icon"><i class="${m.icon}"></i></span>
            <span class="cm-mode-text"><strong>${m.title}${m.tag ? ` <span class="badge bg-success list-pill ms-1">${m.tag}</span>` : ""}</strong><small>${m.text}</small></span>
            <span class="cm-mode-tick"><i class="ri-check-line"></i></span>
          </label>`,
          )
          .join("")}</div>
        ${locked && c.locked_by ? `<div class="cm-note is-primary mt-2"><i class="ri-lock-line"></i><span>The ${esc(c.locked_by.type)} keeps everyone on the diocese's email and SMS, so this is view only.</span></div>` : ""}`,
      );
      field.querySelectorAll('input[name="cm_mode_ui"]').forEach((r) =>
        r.addEventListener("change", () => {
          modeSel.value = r.value;
          window.DemographicsUI?.syncSelect?.(modeSel);
          modeSel.dispatchEvent(new Event("change", { bubbles: true }));
        }),
      );
    }

    // The diocese chooses (and locks) how churches and regions send - and sees every message.
    if (SettingsRail.data?.level === "diocese") return { after, links };

    // ------------------------------------------------ What applies now (v1's ns-what)
    root.insertAdjacentHTML(
      "afterbegin",
      F.card({
        id: "card-how-it-goes",
        title: "What applies now",
        icon: "ri-route-line",
        colour: "primary",
        sub: "What people receive from you, right now",
        body: `
          <div id="commsNotes"></div>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="cm-what h-100" style="--q: var(--primary-rgb)">
                <div class="cm-what-head"><span class="ev-tile is-sm" style="--q: var(--primary-rgb)"><i class="ri-mail-line"></i></span><strong>Email</strong><span id="commsEmailVia" class="ms-auto"></span></div>
                <div class="comms-line"><span>From</span><b id="commsFrom"></b></div>
                <div class="comms-line"><span>Replies to</span><b id="commsReply"></b></div>
                <div class="mt-2" id="commsEmailState"></div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="cm-what h-100" style="--q: var(--pink-rgb)">
                <div class="cm-what-head"><span class="ev-tile is-sm is-soft" style="--q: var(--pink-rgb)"><i class="ri-message-3-line"></i></span><strong>SMS</strong><span id="commsSmsVia" class="ms-auto"></span></div>
                <div class="comms-line"><span>Sender</span><b id="commsSender"></b></div>
                <div class="comms-line"><span>Signature</span><b id="commsSig"></b></div>
                <div class="mt-2" id="commsSmsState"></div>
              </div>
            </div>
          </div>`,
      }),
    );

    // ------------------------------------------------ How it looks: fields beside a live preview
    const lookCard = root.querySelector('[data-field="comms.display_name"]')?.closest(".card");
    if (lookCard) {
      const body = lookCard.querySelector(".card-body");
      const fieldsRow = body.querySelector(".row");
      fieldsRow.querySelectorAll("[data-field]").forEach((col) => (col.className = "col-12"));
      body.innerHTML = `<div class="row g-4"><div class="col-lg-7" id="cmLookFields"></div><div class="col-lg-5" id="cmLookPreview"></div></div>`;
      $("cmLookFields").appendChild(fieldsRow);
      $("cmLookPreview").innerHTML = `
        <div class="cm-preview-label">How it lands</div>
        <div class="cm-email">
          <div class="cm-email-row"><span>From</span><b id="cmPvFrom"></b></div>
          <div class="cm-email-row"><span>Reply to</span><b id="cmPvReply"></b></div>
          <div class="cm-email-row"><span>Subject</span><b>Youth convention this Saturday</b></div>
        </div>
        <div class="nw-phone mt-3">
          <div class="nw-phone-from"><i class="ri-message-3-line me-1"></i><span id="cmPvSender"></span></div>
          <div class="nw-bubble" id="cmPvBubble"></div>
        </div>
        <div class="nw-seg" id="cmPvSeg"></div>`;
      const sig = root.querySelector('[data-key="comms.sms_signature"]');
      if (sig) {
        sig.setAttribute("maxlength", "30");
        sig.insertAdjacentHTML("afterend", '<div class="cm-count" id="cmSigCount"></div>');
      }
      const ph = { "comms.display_name": "e.g. CCI Sultan Hamud", "comms.reply_to": "e.g. office@yourchurch.org", "comms.sms_signature": "e.g. CCI Sultan Hamud" };
      Object.entries(ph).forEach(([k, v]) => {
        const el = root.querySelector(`[data-key="${k}"]`);
        if (el && !el.placeholder) el.placeholder = v;
      });
      // The live preview follows what's typed, not only what's saved.
      fieldsRow.querySelectorAll("[data-key]").forEach((el) => el.addEventListener("input", () => update(readNow())));
    }

    const readNow = () => {
      const v = {};
      root.querySelectorAll("[data-key]").forEach((el) => (v[el.dataset.key] = el.type === "checkbox" ? el.checked : el.value));
      return v;
    };
    const via = (v) => (v === "own" ? '<span class="badge bg-success list-pill">Your own account</span>' : '<span class="soft-chip soft-primary">Through the diocese</span>');
    const state = (sends) =>
      sends ? '<span class="badge bg-success list-pill"><i class="ri-check-line me-1"></i>Sends for real</span>' : '<span class="soft-chip soft-warning"><i class="ri-flask-line"></i>Test inbox / log only - nothing reaches phones or inboxes yet</span>';

    function update(values = {}) {
      // What's typed wins (a live preview); before the form is read, what's saved.
      const typed = (key, saved) => (key in values ? String(values[key] ?? "").trim() : saved || "");
      const name = typed("comms.display_name", "") || SettingsRail.data?.place?.name || c.display_name;
      const reply = typed("comms.reply_to", c.reply_to);
      const signature = typed("comms.sms_signature", c.sms_signature);
      const mode = values["comms.mode"] || c.mode;
      const sender = c.sms?.sender_id || "The provider's default sender";
      const from = `${name} <${c.email?.from_address || "not set"}>`;

      $("commsEmailVia").innerHTML = via(c.email?.via);
      $("commsSmsVia").innerHTML = via(c.sms?.via);
      $("commsFrom").textContent = from;
      $("commsReply").textContent = reply || "Not set - replies go to the From address";
      $("commsSender").textContent = sender;
      $("commsSig").textContent = signature ? `- ${signature}` : "None";
      $("commsEmailState").innerHTML = state(c.email?.sends);
      $("commsSmsState").innerHTML = state(c.sms?.sends);

      if ($("cmPvFrom")) {
        $("cmPvFrom").textContent = from;
        $("cmPvReply").textContent = reply || "the From address";
        $("cmPvSender").textContent = sender;
        const text = `Dear Stephen, the youth convention starts this Saturday at 9am.${signature ? `\n- ${signature}` : ""}`;
        $("cmPvBubble").textContent = text;
        const p = window.MessagesUI?.smsParts ? MessagesUI.smsParts(text) : { characters: text.length, parts: Math.ceil(text.length / 160) || 1 };
        $("cmPvSeg").className = `nw-seg${p.parts > 1 ? " is-over" : ""}`;
        $("cmPvSeg").innerHTML = `<b>${p.characters}</b> characters · <b>${p.parts}</b> ${p.parts === 1 ? "text" : "texts"}`;
      }
      if ($("cmSigCount")) {
        const n = signature.length;
        $("cmSigCount").textContent = `${n} / 30`;
        $("cmSigCount").classList.toggle("is-full", n >= 30);
      }
      root.querySelectorAll('input[name="cm_mode_ui"]').forEach((r) => (r.checked = r.value === mode));

      const notes = [];
      if (mode !== c.mode) notes.push(`<div class="cm-note is-warning"><i class="ri-save-line"></i><span>Save to switch to ${mode === "own" ? "your own account" : "the diocese's email and SMS"}.</span></div>`);
      else if (c.own_incomplete?.length)
        notes.push(`<div class="cm-note is-warning"><i class="ri-information-line"></i><span>Until your own ${c.own_incomplete.map((x) => (x === "sms" ? "SMS account" : "email server")).join(" and ")} ${c.own_incomplete.length > 1 ? "are" : "is"} filled in, ${c.own_incomplete.length > 1 ? "they" : "it"} still go${c.own_incomplete.length > 1 ? "" : "es"} through the diocese's.</span></div>`);
      $("commsNotes").innerHTML = notes.join("");
    }

    update({});
    return { update, after, links };
  };
})();
