/**
 * SETTINGS - Communication (S6b; a workspace since 2026-10-08).
 *
 * Five tabs in one row, each with a live figure, and the tab in the URL
 * (&tab=): Overview | Templates | Campaigns | Sending | Log.
 *   - Overview: what applies now (email and SMS, how each goes and whether it
 *     really sends), this month's figures and the quick ways in;
 *   - Templates: the diocese's shared templates and ours (templates.js);
 *   - Campaigns: send a campaign, and the latest ones (campaigns.js);
 *   - Sending: the settings form - "How we send" as two choice cards, the own
 *     account, "How it looks" beside one large live preview (the real email
 *     and the SMS on a phone), and "Check it works";
 *   - Log: every email and SMS sent (messages.js).
 * The form is still the registry form (fields.js): every input keeps its
 * data-key, so saving, Reset and the save bar work as before. The form is
 * drawn again after a save; the open tab stays.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;

  const MODES = {
    diocese: { title: "Through the diocese", text: "The diocese's email and SMS. Nothing to set up.", icon: "ri-building-4-line", tag: "Recommended" },
    own: { title: "Our own account", text: "Your own email server and SMS account, under your own name.", icon: "ri-key-2-line", tag: "" },
  };
  const TABS = [
    ["overview", "Overview", "ri-dashboard-3-line", "primary"],
    ["templates", "Templates", "ri-file-list-3-line", "purple"],
    ["campaigns", "Campaigns", "ri-broadcast-line", "success"],
    ["sending", "Sending", "ri-route-line", "warning"],
    ["log", "Log", "ri-history-line", "pink"],
  ];
  const SAMPLE_SUBJECT = "Youth convention this Saturday";
  const SAMPLE_BODY = "Dear {name},\n\nThe youth convention starts this Saturday at 9am. Come with a friend - there is lunch for everyone.\n\nSee you there,\n{sender}";

  // The open tab survives the form being drawn again (after a save or Discard).
  let active = new URLSearchParams(window.location.search).get("tab") || "overview";
  if (!TABS.some(([k]) => k === active)) active = "overview";

  F.extras.communication = function (root, payload) {
    const c = payload.extra || {};
    const isDiocese = SettingsRail.data?.level === "diocese";
    const $ = (id) => root.querySelector(`#${id}`);
    let lookPv = null;
    const opened = {};
    const figures = {};

    // ------------------------------------------------ How we send: choice cards over the select
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

    // ------------------------------------------------ How it looks: the fields beside one large preview
    const lookCard = root.querySelector('[data-field="comms.display_name"]')?.closest(".card");
    if (lookCard) {
      const body = lookCard.querySelector(".card-body");
      const fieldsRow = body.querySelector(".row");
      fieldsRow.querySelectorAll("[data-field]").forEach((col) => (col.className = "col-12"));
      body.innerHTML = `<div class="row g-4"><div class="col-xl-5" id="cmLookFields"></div><div class="col-xl-7"><div class="cm-look-stage" id="cmLookPreview"></div></div></div>`;
      $("cmLookFields").appendChild(fieldsRow);
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
      // The preview follows what's typed, not only what's saved.
      fieldsRow.querySelectorAll("[data-key]").forEach((el) => el.addEventListener("input", () => update(readNow())));
    }

    const readNow = () => {
      const v = {};
      root.querySelectorAll("[data-key]").forEach((el) => (v[el.dataset.key] = el.type === "checkbox" ? el.checked : el.value));
      return v;
    };
    const typedOr = (values, key, saved) => (key in values ? String(values[key] ?? "").trim() : saved || "");
    let typed = {};
    const nameNow = () => typedOr(typed, "comms.display_name", "") || SettingsRail.data?.place?.name || c.display_name;
    const signatureNow = () => typedOr(typed, "comms.sms_signature", c.sms_signature);
    const replyNow = () => typedOr(typed, "comms.reply_to", c.reply_to);
    const fromNow = () => `${nameNow()} <${c.email?.from_address || "not set"}>`;
    const senderNow = () => c.sms?.sender_id || "The provider's default sender";

    const via = (v) => (v === "own" ? '<span class="badge bg-success list-pill">Your own account</span>' : `<span class="soft-chip soft-primary">${isDiocese ? "The diocese's account" : "Through the diocese"}</span>`);
    const state = (sends) =>
      sends ? '<span class="badge bg-success list-pill"><i class="ri-check-line me-1"></i>Sends for real</span>' : '<span class="soft-chip soft-warning"><i class="ri-flask-line"></i>Test only - written to the log, nothing reaches phones or inboxes yet</span>';

    function update(values = {}) {
      typed = values;
      const mode = values["comms.mode"] || c.mode;
      if ($("commsFrom")) {
        $("commsEmailVia").innerHTML = via(c.email?.via);
        $("commsSmsVia").innerHTML = via(c.sms?.via);
        $("commsFrom").textContent = fromNow();
        $("commsReply").textContent = replyNow() || "Not set - replies go to the From address";
        $("commsSender").textContent = senderNow();
        $("commsSig").textContent = signatureNow() ? `- ${signatureNow()}` : "None";
        $("commsEmailState").innerHTML = state(c.email?.sends);
        $("commsSmsState").innerHTML = state(c.sms?.sends);
      }
      if ($("cmSigCount")) {
        const n = signatureNow().length;
        $("cmSigCount").textContent = `${n} / 30`;
        $("cmSigCount").classList.toggle("is-full", n >= 30);
      }
      lookPv?.redraw();
      root.querySelectorAll('input[name="cm_mode_ui"]').forEach((r) => (r.checked = r.value === mode));
      setFigure("sending", mode === "own" ? "Our own account" : isDiocese ? "Diocese account" : "Through the diocese");

      const notes = [];
      if (mode !== c.mode) notes.push(`<div class="cm-note is-warning"><i class="ri-save-line"></i><span>Save to switch to ${mode === "own" ? "your own account" : "the diocese's email and SMS"}.</span></div>`);
      else if (c.own_incomplete?.length)
        notes.push(`<div class="cm-note is-warning"><i class="ri-information-line"></i><span>Until your own ${c.own_incomplete.map((x) => (x === "sms" ? "SMS account" : "email server")).join(" and ")} ${c.own_incomplete.length > 1 ? "are" : "is"} filled in, ${c.own_incomplete.length > 1 ? "they" : "it"} still go${c.own_incomplete.length > 1 ? "" : "es"} through the diocese's.</span></div>`);
      if ($("commsNotes")) $("commsNotes").innerHTML = notes.join("");
    }

    // ------------------------------------------------ the workspace: tabs and panes
    function setFigure(key, text) {
      figures[key] = text;
      const el = root.querySelector(`[data-tab-figure="${key}"]`);
      if (el) el.textContent = text;
    }

    function open(key, { scroll = false } = {}) {
      active = key;
      root.querySelectorAll("#cmTabs .section-tab").forEach((b) => {
        const on = b.dataset.tab === key;
        b.classList.toggle("active", on);
        b.setAttribute("aria-selected", on ? "true" : "false");
      });
      root.querySelectorAll("[data-cm-pane]").forEach((p) => (p.hidden = p.dataset.cmPane !== key));
      const url = new URL(window.location.href);
      if (key === "overview") url.searchParams.delete("tab");
      else url.searchParams.set("tab", key);
      history.replaceState(history.state, "", url);
      if (!opened[key]) {
        opened[key] = true;
        if (key === "campaigns") window.CommsCampaigns.mount($("cmPaneCampaigns"), { canSend: templatesOk, openTemplates: () => open("templates") }).then((n) => n !== null && n !== undefined && setFigure("campaigns", `${n} this month`));
        if (key === "log") window.SettingsMessages?.mount($("commsMessages"));
      }
      if (key === "sending") lookPv?.redraw();
      if (scroll) root.querySelector("#cmTabs").scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function overview() {
      const q = (k) => `style="--q: var(--${k}-rgb)"`;
      return `
        <div id="commsNotes"></div>
        <div class="row g-3 mb-4">
          <div class="col-lg-6">
            <div class="cm-what h-100" ${q("purple")}>
              <div class="cm-what-head"><span class="ev-tile" ${q("purple")}><i class="ri-mail-line"></i></span><div class="flex-fill"><strong>Email</strong><small>What people get in their inbox</small></div><span id="commsEmailVia"></span></div>
              <div class="comms-line"><span>From</span><b id="commsFrom"></b></div>
              <div class="comms-line"><span>Replies to</span><b id="commsReply"></b></div>
              <div class="mt-3" id="commsEmailState"></div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="cm-what h-100" ${q("success")}>
              <div class="cm-what-head"><span class="ev-tile is-soft" ${q("success")}><i class="ri-message-2-line"></i></span><div class="flex-fill"><strong>SMS</strong><small>What lands on their phone</small></div><span id="commsSmsVia"></span></div>
              <div class="comms-line"><span>Sender</span><b id="commsSender"></b></div>
              <div class="comms-line"><span>Signature</span><b id="commsSig"></b></div>
              <div class="mt-3" id="commsSmsState"></div>
            </div>
          </div>
        </div>
        <div class="row g-3 mb-4" id="cmMonth">${UI.skeletonCards(4, "col-6 col-xl-3")}</div>
        <div class="cm-quick">
          ${[
            ["campaigns", "ri-broadcast-line", "success", "Send a campaign", "To your people or the places below, by SMS, email or in the app", false],
            ["templates", "ri-file-list-3-line", "purple", "Our templates", isDiocese ? "Write the ones every church and region copies" : "The diocese's ready-made messages, and ours", true],
            ["sending", "ri-route-line", "warning", "How we send", "Through the diocese or your own account, your name and signature", false],
            ["log", "ri-history-line", "pink", "Every message sent", "Open one to see exactly what went out", true],
          ]
            .map(([k, icon, col, title, text, soft]) => `<button type="button" class="cm-quick-item" data-open="${k}"><span class="ev-tile${soft ? " is-soft" : ""}" ${q(col)}><i class="${icon}"></i></span><span class="flex-fill"><strong>${title}</strong><small>${text}</small></span><i class="ri-arrow-right-s-line"></i></button>`)
            .join("")}
        </div>`;
    }

    async function month() {
      const res = await SettingsAPI.messages();
      const host = $("cmMonth");
      if (!host) return;
      const rows = res.ok ? res.data.rows : [];
      const start = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
      const m = rows.filter((r) => new Date(r.at) >= start);
      const failed = m.filter((r) => r.status === "failed").length;
      const emails = m.filter((r) => r.channel === "email").length;
      const fig = (icon, col, value, label, soft) => `<div class="col-6 col-xl-3"><div class="cm-figure"><span class="ev-tile${soft ? " is-soft" : ""}" style="--q: var(--${col}-rgb)"><i class="${icon}"></i></span><div><b>${value}</b><span>${label}</span></div></div></div>`;
      host.innerHTML = [
        fig("ri-send-plane-line", "primary", m.length - failed, "Sent this month"),
        fig("ri-mail-line", "purple", emails, "Emails", true),
        fig("ri-message-2-line", "success", m.length - emails, "SMS"),
        fig(failed ? "ri-error-warning-line" : "ri-shield-check-line", failed ? "danger" : "warning", failed, failed ? "Failed - open the Log" : "Failed", !failed),
      ].join("");
      if (res.ok) setFigure("log", `${m.length} this month`);
    }

    let templatesOk = true;
    function after() {
      const sendingCards = [...root.children];
      root.insertAdjacentHTML(
        "afterbegin",
        `<div class="nav section-tabs is-row cm-tabs" id="cmTabs" role="tablist" aria-label="Communication">
          ${TABS.map(([k, label, icon, col]) => `<button class="nav-link section-tab" data-tab="${k}" type="button" role="tab" aria-selected="false"><span class="section-tab-icon bg-${col}${col === "warning" ? " text-dark" : ""}"><i class="${icon}"></i></span><span class="section-tab-text"><strong>${label}</strong><small data-tab-figure="${k}">${esc(figures[k] || " ")}</small></span></button>`).join("")}
        </div>
        <div class="cm-panes">
          <section data-cm-pane="overview" hidden>${overview()}</section>
          <section data-cm-pane="templates" id="cmPaneTemplates" hidden><div class="row g-3">${UI.skeletonCards(3, "col-md-4")}</div></section>
          <section data-cm-pane="campaigns" id="cmPaneCampaigns" hidden></section>
          <section data-cm-pane="sending" id="cmPaneSending" hidden></section>
          <section data-cm-pane="log" hidden><div id="commsMessages"></div></section>
        </div>`,
      );
      sendingCards.forEach((el) => $("cmPaneSending").appendChild(el));
      root.querySelectorAll("#cmTabs .section-tab").forEach((b) => b.addEventListener("click", () => open(b.dataset.tab)));
      root.querySelector(".cm-quick").addEventListener("click", (e) => {
        const b = e.target.closest("[data-open]");
        if (b) open(b.dataset.open, { scroll: true });
      });

      // The big preview, now it's in the page.
      if ($("cmLookPreview")) {
        lookPv = window.CommsPreview($("cmLookPreview"), {
          view: "email",
          from: () => fromNow(),
          sender: () => senderNow(),
          smsText: (server, text) => (signatureNow() ? `${text}\n- ${signatureNow()}` : text),
        });
        lookPv.set({ subject: SAMPLE_SUBJECT, body: SAMPLE_BODY });
      }

      update(readNow());
      setFigure("overview", c.email?.sends && c.sms?.sends ? "Sending for real" : "Test setup");
      setFigure("campaigns", figures.campaigns || "Send one");
      month();
      window.CommsTemplates.load().then((list) => {
        templatesOk = list !== null;
        setFigure("templates", list ? `${list.length} ready` : "For senders");
        window.CommsTemplates.mount($("cmPaneTemplates"), { onCount: (l) => setFigure("templates", `${l.length} ready`) });
      });
      open(active);
    }

    return {
      update,
      after,
      // The rail lists the tabs, and opens one.
      onlyLinks: true,
      links: TABS.map(([k, label]) => ({ id: "cmTabs", label, open: () => open(k, { scroll: true }) })),
    };
  };
})();
