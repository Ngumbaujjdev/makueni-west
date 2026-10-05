/**
 * SETTINGS - Communication (church and region, S6b): the "How your messages
 * go out" card above the form. It shows, view only, what the diocese's
 * email and SMS send as for this place - or its own account - and previews
 * the From line and SMS signature as you type them. The form itself is the
 * generic registry form (fields.js); this is its 'extra' card.
 */
(function () {
  "use strict";

  const F = window.SettingsFields;
  const esc = F.esc;

  F.extras.communication = function (root, payload) {
    // The message log goes last, after "Check it works" (S6c).
    const after = () => {
      root.insertAdjacentHTML("beforeend", '<div id="commsMessages"></div>');
      window.SettingsMessages?.mount(root.querySelector("#commsMessages"));
    };
    const links = [{ id: "card-messages", label: "Messages" }];
    // The diocese only chooses (and locks) how churches and regions send - and sees every message.
    if (SettingsRail.data?.level === "diocese") return { after, links };
    const c = payload.extra;
    root.insertAdjacentHTML(
      "afterbegin",
      F.card({
        id: "card-how-it-goes",
        title: "How your messages go out",
        icon: "ri-route-line",
        colour: "primary",
        sub: "What people receive from you, right now",
        body: `
          <div id="commsNotes"></div>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="comms-way h-100">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <span class="avatar avatar-sm bg-primary text-white"><i class="ri-mail-line"></i></span>
                  <span class="fw-semibold flex-fill">Email</span>
                  <span id="commsEmailVia"></span>
                </div>
                <div class="comms-line"><span>From</span><b id="commsFrom"></b></div>
                <div class="comms-line"><span>Replies to</span><b id="commsReply"></b></div>
                <div class="mt-2" id="commsEmailState"></div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="comms-way h-100">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <span class="avatar avatar-sm bg-pink text-white"><i class="ri-message-3-line"></i></span>
                  <span class="fw-semibold flex-fill">SMS</span>
                  <span id="commsSmsVia"></span>
                </div>
                <div class="comms-line"><span>Sender</span><b id="commsSender"></b></div>
                <div class="comms-bubble mt-2" id="commsBubble"></div>
                <div class="mt-2" id="commsSmsState"></div>
              </div>
            </div>
          </div>`,
      }),
    );

    const $ = (id) => root.querySelector(`#${id}`);
    const via = (v) => (v === "own" ? '<span class="badge bg-success">Your own account</span>' : '<span class="badge bg-primary">Through the diocese</span>');
    const state = (sends) =>
      sends ? '<span class="soft-chip soft-success"><i class="ri-check-line me-1"></i>Sends for real</span>' : '<span class="soft-chip soft-warning"><i class="ri-flask-line me-1"></i>Test inbox / log only - nothing reaches phones or inboxes yet</span>';

    function update(values = {}) {
      // What's typed wins (a live preview); before the form is read, what's saved.
      const typed = (key, saved) => (key in values ? String(values[key] ?? "").trim() : saved || "");
      const name = typed("comms.display_name", "") || SettingsRail.data?.place?.name || c.display_name;
      const reply = typed("comms.reply_to", c.reply_to);
      const signature = typed("comms.sms_signature", c.sms_signature);
      const mode = values["comms.mode"] || c.mode;

      $("commsEmailVia").innerHTML = via(c.email.via);
      $("commsSmsVia").innerHTML = via(c.sms.via);
      $("commsFrom").textContent = `${name} <${c.email.from_address || "not set"}>`;
      $("commsReply").textContent = reply || "Not set - replies go to the From address";
      $("commsSender").textContent = c.sms.sender_id || "The provider's default sender";
      $("commsBubble").innerHTML = `<span>Your message…</span>${signature ? `<span class="d-block mt-1">- ${esc(signature)}</span>` : ""}`;
      $("commsEmailState").innerHTML = state(c.email.sends);
      $("commsSmsState").innerHTML = state(c.sms.sends);

      const notes = [];
      if (c.locked_by) notes.push(`<div class="alert alert-primary py-2 d-flex gap-2 align-items-center"><i class="ri-lock-line"></i><span>The ${esc(c.locked_by.type)} keeps everyone on the diocese's email and SMS, so this is view only.</span></div>`);
      if (mode !== c.mode) notes.push(`<div class="alert alert-warning py-2 d-flex gap-2 align-items-center"><i class="ri-save-line"></i><span>Save to switch to ${mode === "own" ? "your own account" : "the diocese's email and SMS"}.</span></div>`);
      else if (c.own_incomplete?.length)
        notes.push(`<div class="alert alert-warning py-2 d-flex gap-2 align-items-center"><i class="ri-information-line"></i><span>Until your own ${c.own_incomplete.map((x) => (x === "sms" ? "SMS account" : "email server")).join(" and ")} ${c.own_incomplete.length > 1 ? "are" : "is"} filled in, ${c.own_incomplete.length > 1 ? "they" : "it"} still go${c.own_incomplete.length > 1 ? "" : "es"} through the diocese's.</span></div>`);
      $("commsNotes").innerHTML = notes.join("");
    }

    update({});
    return { update, after, links };
  };
})();
