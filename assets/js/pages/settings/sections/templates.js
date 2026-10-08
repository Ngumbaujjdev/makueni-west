/**
 * SETTINGS - Communication > Templates (L5c, 2026-10-08) - v1-events'
 * template library and writer, for our places:
 *   - the library: "From the diocese" (shared with every place below, made by
 *     the diocese - or a region for its churches) and "Ours", each template a
 *     card with a small look at how it lands - a phone bubble for SMS, the
 *     branded email's first lines for email;
 *   - Preview: the real email (rendered by the server into a sandboxed frame,
 *     exactly what goes out) or the SMS on a phone, Desktop / Mobile, and
 *     "Send this to me";
 *   - Make our copy (our name goes in for {sender}), Edit, Remove, and Reset
 *     to the shared text;
 *   - the writer: Write -> Details -> Check, placeholder chips, the SMS
 *     counter, a warning for placeholders that won't be filled in, and the
 *     live preview beside it.
 * CommsPreview (the live preview) is shared with the Sending tab.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;

  // The same colours as the Messages pages (assets/js/pages/messages/ui.js).
  const CH = {
    sms: { label: "SMS", icon: "ri-message-2-line", c: "success" },
    email: { label: "Email", icon: "ri-mail-line", c: "purple" },
    both: { label: "SMS and email", icon: "ri-mail-send-line", c: "pink" },
    app: { label: "In the app", icon: "ri-notification-3-line", c: "primary" },
  };
  const TOKENS = [
    ["{name}", "Their name", "Stephen"],
    ["{place}", "Their church or region", "your place"],
    ["{sender}", "Who it's from", "your place"],
  ];
  const SAMPLE = "Stephen Mutua";
  const GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";

  const placeName = () => SettingsRail.data?.place?.name || "your place";
  const level = () => SettingsRail.data?.level || SettingsHub.ctx?.level || "church";
  const base = () => (window.AppConfig?.FRONTEND_BASE_URL || "") + `/${level()}/messages`;
  const composeUrl = (t) => `${base()}/new${t ? `?template=${t.id}` : ""}`;

  /** As the sample person reads it: {name}, {place} and {sender} filled in. */
  function fill(text) {
    const p = placeName();
    return String(text || "").replace(/\{name\}/g, "Stephen").replace(/\{place\}/g, p).replace(/\{sender\}/g, p);
  }
  const unknownTokens = (...texts) => [...new Set(texts.join(" ").match(/\{[a-z_]+\}/gi) || [])].filter((t) => !TOKENS.some(([k]) => k === t));

  /** How many texts an SMS takes: 160 (153 a part) in plain text, 70 (67) with other characters. */
  function smsParts(text) {
    const len = [...text].length;
    const gsm = [...text].every((c) => GSM.includes(c));
    const [one, part] = gsm ? [160, 153] : [70, 67];
    return { characters: len, parts: len === 0 ? 0 : len <= one ? 1 : Math.ceil(len / part), unicode: !gsm };
  }
  const segLine = (p) => `<b>${p.characters}</b> characters · <b>${p.parts}</b> ${p.parts === 1 ? "text" : "texts"}${p.unicode ? " · special characters make texts shorter" : ""}`;

  // ------------------------------------------------------------------ the live preview (shared)

  let seq = 0;
  /**
   * A live preview: On a phone / As an email, Desktop / Mobile, the real email
   * in a sandboxed frame, and (optionally) "Send this to me".
   *   CommsPreview(host, { view, test, smsText(data, text), from(data) })
   *   .set({ subject, body })   redraws (the email after a short pause)
   *   .view("phone" | "email")
   */
  function CommsPreview(host, opts = {}) {
    const id = `cmPv${++seq}`;
    let now = { subject: "", body: "" };
    let server = null;
    let timer = null;
    let asked = 0;
    host.innerHTML = `
      <div class="cm-pv">
        <div class="cm-pv-bar">
          <div class="pb-segment" role="radiogroup" aria-label="Preview as">
            <input type="radio" name="${id}View" id="${id}Phone" value="phone"><label for="${id}Phone"><i class="ri-smartphone-line me-1"></i>On a phone</label>
            <input type="radio" name="${id}View" id="${id}Email" value="email"><label for="${id}Email"><i class="ri-mail-line me-1"></i>As an email</label>
          </div>
          <div class="pb-segment cm-pv-width" role="radiogroup" aria-label="Email width">
            <input type="radio" name="${id}Width" id="${id}Desk" value="desktop" checked><label for="${id}Desk" title="Desktop"><i class="ri-computer-line"></i><span class="visually-hidden">Desktop</span></label>
            <input type="radio" name="${id}Width" id="${id}Mob" value="mobile"><label for="${id}Mob" title="Mobile"><i class="ri-smartphone-line"></i><span class="visually-hidden">Mobile</span></label>
          </div>
        </div>
        <div class="cm-pv-stage" data-stage="phone">
          <div class="cm-handset">
            <div class="cm-handset-top"><span></span></div>
            <div class="cm-handset-head"><span class="ev-tile is-sm is-soft" style="--q: var(--success-rgb)"><i class="ri-message-2-line"></i></span><b data-pv="sender">&nbsp;</b></div>
            <div class="cm-handset-screen"><div class="nw-bubble" data-pv="bubble"></div></div>
          </div>
          <div class="nw-seg" data-pv="seg"></div>
        </div>
        <div class="cm-pv-stage" data-stage="email" hidden>
          <div class="cm-mailbox">
            <div class="cm-mailbox-row"><span>From</span><b data-pv="from">&nbsp;</b></div>
            <div class="cm-mailbox-row"><span>To</span><b>${SAMPLE}</b></div>
            <div class="cm-mailbox-row"><span>Subject</span><b data-pv="subject">&nbsp;</b></div>
          </div>
          <div class="cm-frame-wrap" data-pv="wrap">
            <iframe class="cm-frame" sandbox="allow-same-origin" title="How the email looks" data-pv="frame"></iframe>
            <div class="cm-frame-loading" data-pv="loading"><span class="spinner-border spinner-border-sm text-primary"></span></div>
          </div>
        </div>
        <div class="cm-pv-foot">
          <span class="soft-chip soft-primary"><i class="ri-user-smile-line"></i>Written to ${SAMPLE}, a sample person</span>
          ${opts.test ? `<button type="button" class="btn btn-sm btn-success ms-auto" data-pv="test"><i class="ri-send-plane-line me-1"></i>Send this to me</button>` : ""}
        </div>
        <div class="cm-note is-warning mt-3 mb-0" data-pv="error" hidden></div>
      </div>`;
    const $ = (k) => host.querySelector(`[data-pv="${k}"]`);
    const frame = $("frame");

    function fit() {
      try {
        const doc = frame.contentDocument;
        if (doc?.body) frame.style.height = `${Math.max(320, doc.documentElement.scrollHeight)}px`;
      } catch (e) {
        /* a sandboxed frame we can't measure keeps its height */
      }
    }
    frame.addEventListener("load", fit);

    function drawPhone() {
      const text = opts.smsText ? opts.smsText(server, fill(now.body)) : server?.sms?.text ?? fill(now.body);
      $("sender").textContent = (opts.sender ? opts.sender(server) : server?.sender) || "Your SMS sender ID";
      $("bubble").textContent = text || "Your message shows here.";
      const p = smsParts(text || "");
      $("seg").className = `nw-seg${p.parts > 1 ? " is-over" : ""}`;
      $("seg").innerHTML = segLine(p);
    }
    function drawEmailHead() {
      $("from").textContent = (opts.from ? opts.from(server) : server?.from) || "…";
      $("subject").textContent = server?.subject || fill(now.subject) || "…";
    }

    async function ask() {
      const n = ++asked;
      $("loading").hidden = false;
      const res = await SettingsAPI.previewTemplate(now.subject || "", now.body || "");
      if (n !== asked) return;
      $("loading").hidden = true;
      if (!res.ok) {
        $("error").hidden = false;
        $("error").innerHTML = `<i class="ri-information-line"></i><span>${esc(res.message)}</span>`;
        drawPhone();
        return;
      }
      $("error").hidden = true;
      server = res.data;
      frame.srcdoc = server.html;
      drawPhone();
      drawEmailHead();
    }

    function view(v) {
      host.querySelector(`#${id}${v === "email" ? "Email" : "Phone"}`).checked = true;
      host.querySelectorAll("[data-stage]").forEach((s) => (s.hidden = s.dataset.stage !== v));
      host.querySelector(".cm-pv-width").hidden = v !== "email";
      if (v === "email") requestAnimationFrame(fit);
    }
    host.querySelectorAll(`input[name="${id}View"]`).forEach((r) => r.addEventListener("change", () => view(r.value)));
    host.querySelectorAll(`input[name="${id}Width"]`).forEach((r) =>
      r.addEventListener("change", () => {
        $("wrap").classList.toggle("is-mobile", r.value === "mobile");
        requestAnimationFrame(fit);
      }),
    );

    const testBtn = $("test");
    testBtn?.addEventListener("click", async () => {
      if (!now.body.trim()) return Toast.error("Write the message first.");
      const channel = host.querySelector(`#${id}Email`).checked ? "email" : "sms";
      UI.setButtonLoading(testBtn, "Sending…");
      const res = await SettingsAPI.testTemplate(channel, now.subject || "", now.body);
      UI.restoreButton(testBtn);
      res.ok ? Toast.success(res.message, { title: channel === "email" ? "Test email" : "Test SMS" }) : Toast.error(res.message, { title: "Not sent" });
    });

    view(opts.view || "phone");
    return {
      set(next) {
        now = { ...now, ...next };
        drawPhone();
        if (server) drawEmailHead();
        clearTimeout(timer);
        timer = setTimeout(ask, server ? 350 : 0);
      },
      redraw: () => {
        drawPhone();
        drawEmailHead();
      },
      view,
    };
  }

  // ------------------------------------------------------------------ the library

  let state = { host: null, list: [], filter: "all", q: "", blocked: null, onCount: null };

  const isDiocese = () => level() === "diocese";
  const canShare = () => level() !== "church";
  const ownerWord = (t) => (t.owner?.type === "region" ? "the region" : "the diocese");

  function groups() {
    const shown = state.list.filter((t) => {
      if (state.filter === "email" && !["email", "both"].includes(t.channel)) return false;
      if (state.filter === "sms" && !["sms", "both"].includes(t.channel)) return false;
      if (state.q && !`${t.name} ${t.subject || ""} ${t.body}`.toLowerCase().includes(state.q)) return false;
      return true;
    });
    if (canShare()) {
      return [
        { key: "shared", title: `Shared with every ${isDiocese() ? "church and region" : "church in the region"}`, sub: "Places below see these and copy them, with their own name in", icon: "ri-share-forward-line", c: "purple", items: shown.filter((t) => t.source === "ours" && t.shared_below), empty: "Nothing shared yet - write one and switch on Share." },
        ...(isDiocese() ? [] : [{ key: "above", title: "From the diocese", sub: "Copy one to make it your own", icon: "ri-building-4-line", c: "primary", items: shown.filter((t) => t.source === "shared"), empty: "The diocese hasn't shared any yet." }]),
        { key: "ours", title: isDiocese() ? "For the diocese only" : "Only ours", sub: "Not shared below", icon: "ri-bookmark-line", c: "success", items: shown.filter((t) => t.source === "ours" && !t.shared_below), empty: "None yet." },
      ];
    }
    return [
      { key: "above", title: "From the diocese", sub: "Ready-made - copy one and it carries your name", icon: "ri-building-4-line", c: "purple", items: shown.filter((t) => t.source === "shared"), empty: "The diocese hasn't shared any yet." },
      { key: "ours", title: "Ours", sub: "Our copies and our own", icon: "ri-bookmark-line", c: "success", items: shown.filter((t) => t.source === "ours"), empty: "None yet - copy one above or write your own." },
    ];
  }

  function look(t) {
    const ch = CH[t.channel] || CH.sms;
    if (t.channel === "email" || t.channel === "both") {
      const lines = fill(t.body).split(/\n\s*\n/).map((s) => s.trim()).filter(Boolean);
      return `
        <div class="cm-tpl-look is-email">
          <div class="cm-mini-mail">
            <div class="cm-mini-bar"></div>
            <div class="cm-mini-eyebrow">${esc(placeName())}</div>
            <div class="cm-mini-title">${esc(fill(t.subject) || lines[0] || "")}</div>
            ${lines.slice(0, 2).map((l) => `<p>${esc(l)}</p>`).join("")}
          </div>
        </div>`;
    }
    return `
      <div class="cm-tpl-look is-sms" style="--q: var(--${ch.c}-rgb)">
        <div class="cm-mini-from"><i class="${ch.icon}"></i>${t.channel === "app" ? "In the app" : esc(placeName())}</div>
        <div class="cm-mini-bubble">${esc(fill(t.body))}</div>
      </div>`;
  }

  function tplCard(t) {
    const ch = CH[t.channel] || CH.sms;
    const ours = t.source === "ours";
    const tags = [
      `<span class="badge bg-${ch.c} list-pill"><i class="${ch.icon} me-1"></i>${ch.label}</span>`,
      !ours ? `<span class="soft-chip soft-purple"><i class="ri-building-4-line"></i>${esc(t.owner?.name || "Diocese")}</span>` : "",
      ours && t.copied_from ? `<span class="soft-chip soft-primary"><i class="ri-file-copy-line"></i>Copied from ${esc(t.copied_from.owner || "the diocese")}</span>` : "",
      ours && t.shared_below ? `<span class="soft-chip soft-warning"><i class="ri-share-forward-line"></i>Shared below</span>` : "",
      !ours && t.our_copy_id ? `<span class="soft-chip soft-success"><i class="ri-check-line"></i>You have a copy</span>` : "",
    ].join("");
    const actions = [
      `<button type="button" class="btn btn-sm btn-primary" data-act="preview"><i class="ri-eye-line me-1"></i>Preview</button>`,
      ours ? `<a class="btn btn-sm btn-light border" href="${composeUrl(t)}"><i class="ri-send-plane-line me-1"></i>Use</a>` : "",
      !ours && !t.our_copy_id ? `<button type="button" class="btn btn-sm btn-success" data-act="copy"><i class="ri-file-copy-line me-1"></i>Make our copy</button>` : "",
      !ours && t.our_copy_id ? `<button type="button" class="btn btn-sm btn-light border" data-act="mine"><i class="ri-arrow-right-line me-1"></i>Our copy</button>` : "",
      ours ? `<button type="button" class="btn btn-sm btn-icon btn-light border" data-act="edit" title="Edit" aria-label="Edit ${esc(t.name)}"><i class="ri-pencil-line"></i></button>` : "",
      ours && t.copied_from ? `<button type="button" class="btn btn-sm btn-icon btn-light border" data-act="reset" title="Reset to ${esc(t.copied_from.owner || "the diocese")}'s text" aria-label="Reset"><i class="ri-arrow-go-back-line"></i></button>` : "",
      ours ? `<button type="button" class="btn btn-sm btn-icon btn-light border text-danger" data-act="remove" title="Remove" aria-label="Remove ${esc(t.name)}"><i class="ri-delete-bin-6-line"></i></button>` : "",
    ].join("");
    return `
      <div class="col-xxl-4 col-md-6">
        <article class="cm-tpl" data-tpl="${t.id}">
          <button type="button" class="cm-tpl-open" data-act="preview" aria-label="Preview ${esc(t.name)}">${look(t)}</button>
          <div class="cm-tpl-body">
            <h3 class="cm-tpl-name">${esc(t.name)}</h3>
            <div class="cm-tpl-tags">${tags}</div>
          </div>
          <div class="cm-tpl-actions">${actions}</div>
        </article>
      </div>`;
  }

  function drawList() {
    const box = state.host.querySelector("#cmTplGroups");
    if (!box) return;
    const gs = groups();
    box.innerHTML = gs
      .map(
        (g, i) => `
        <section class="cm-group">
          <header class="cm-group-head">
            <span class="ev-tile${i % 2 ? " is-soft" : ""}" style="--q: var(--${g.c}-rgb)"><i class="${g.icon}"></i></span>
            <div class="flex-fill"><h3>${g.title}</h3><p>${g.sub}</p></div>
            <span class="soft-chip soft-${g.c}">${g.items.length}</span>
          </header>
          ${g.items.length ? `<div class="row g-3">${g.items.map(tplCard).join("")}</div>` : `<div class="cm-group-empty"><i class="ri-inbox-line"></i>${state.q || state.filter !== "all" ? "Nothing matches." : g.empty}</div>`}
        </section>`,
      )
      .join("");
  }

  function drawShell() {
    if (state.blocked) {
      state.host.innerHTML = `<div class="cm-locked"><span class="ev-tile is-soft" style="--q: var(--danger-rgb)"><i class="ri-lock-line"></i></span><div><h3>Templates are for people who send messages</h3><p>${esc(state.blocked)}</p></div></div>`;
      return;
    }
    state.host.innerHTML = `
      <div class="cm-hero" style="--q: var(--purple-rgb)">
        <span class="ev-tile" style="--q: var(--purple-rgb)"><i class="ri-file-list-3-line"></i></span>
        <div class="flex-fill">
          <h3>Templates</h3>
          <p>${canShare() ? `Write a message once and share it - every ${isDiocese() ? "church and region" : "church"} below sees it and makes its own copy.` : "The diocese's ready-made messages, and your own. Copy one and your church's name goes in."}</p>
        </div>
        <button type="button" class="btn btn-primary" id="cmTplNew"><i class="ri-add-line me-1"></i>New template</button>
      </div>
      <div class="cm-toolbar">
        <div class="pb-segment cm-filter" role="radiogroup" aria-label="Show">
          ${[["all", "All"], ["email", "Email"], ["sms", "SMS"]].map(([v, l]) => `<input type="radio" name="cmTplFilter" id="cmTplF-${v}" value="${v}"${state.filter === v ? " checked" : ""}><label for="cmTplF-${v}">${l}</label>`).join("")}
        </div>
        <div class="input-group cm-search">
          <span class="input-group-text"><i class="ri-search-line"></i></span>
          <input type="search" class="form-control" id="cmTplSearch" placeholder="Search templates" aria-label="Search templates" value="${esc(state.q)}">
        </div>
      </div>
      <div id="cmTplGroups"></div>`;
    state.host.querySelector("#cmTplNew").addEventListener("click", () => writer(null));
    state.host.querySelectorAll('input[name="cmTplFilter"]').forEach((r) =>
      r.addEventListener("change", () => {
        state.filter = r.value;
        drawList();
      }),
    );
    state.host.querySelector("#cmTplSearch").addEventListener("input", (e) => {
      state.q = e.target.value.trim().toLowerCase();
      drawList();
    });
    state.host.querySelector("#cmTplGroups").addEventListener("click", (e) => {
      const b = e.target.closest("[data-act]");
      const t = b && state.list.find((x) => x.id === Number(b.closest("[data-tpl]")?.dataset.tpl));
      if (t) act(b.dataset.act, t, b);
    });
    drawList();
  }

  function upsert(row) {
    const i = state.list.findIndex((t) => t.id === row.id);
    if (i >= 0) state.list[i] = row;
    else state.list.push(row);
    state.list.sort((a, b) => a.name.localeCompare(b.name));
  }

  async function act(what, t, btn) {
    if (what === "preview") return previewWindow(t);
    if (what === "edit") return writer(t);
    if (what === "mine") return previewWindow(state.list.find((x) => x.id === t.our_copy_id) || t);
    if (what === "copy") return copy(t, btn);
    if (what === "reset")
      return Toast.confirm(`Put "${t.name}" back to ${t.copied_from?.owner || "the diocese"}'s text? Your changes to it are lost.`, async () => {
        const res = await SettingsAPI.resetTemplate(t.id);
        if (!res.ok) return Toast.error(res.message);
        upsert(res.data);
        drawList();
        Toast.success(res.message);
      }, null, { title: "Reset to the shared text", confirmText: "Reset it", cancelText: "Keep mine", type: "warning" });
    if (what === "remove")
      return Toast.confirm(`Remove "${t.name}"? This can't be undone.`, async () => {
        const res = await SettingsAPI.deleteTemplate(t.id);
        if (!res.ok) return Toast.error(res.message);
        state.list = state.list.filter((x) => x.id !== t.id).map((x) => (x.our_copy_id === t.id ? { ...x, our_copy_id: null } : x));
        drawList();
        count();
        Toast.success(res.message);
      }, null, { title: "Remove template", confirmText: "Remove", cancelText: "Keep it", type: "danger" });
  }

  async function copy(t, btn) {
    if (btn) UI.setButtonLoading(btn, "Copying…");
    const res = await SettingsAPI.copyTemplate(t.id);
    if (btn) UI.restoreButton(btn);
    if (!res.ok) return Toast.error(res.message);
    upsert(res.data);
    t.our_copy_id = res.data.id;
    drawList();
    count();
    Toast.success(res.message, { title: "Our copy" });
    return res.data;
  }

  const count = () => state.onCount?.(state.list);

  // ------------------------------------------------------------------ the preview window

  function modal(id, size) {
    let el = document.getElementById(id);
    if (el) return el;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal cm-modal" id="${id}" tabindex="-1" aria-labelledby="${id}Title">
        <div class="modal-dialog ${size} modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon" data-m="icon"></span>
              <div class="flex-fill" style="min-width:0"><h5 class="modal-title" id="${id}Title" data-m="title"></h5><div class="app-modal-subtitle" data-m="sub"></div></div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" data-m="body"></div>
            <div class="modal-footer" data-m="foot"></div>
          </div>
        </div>
      </div>`,
    );
    return document.getElementById(id);
  }
  const head = (el, { icon, c, title, sub }) => {
    const i = el.querySelector('[data-m="icon"]');
    i.className = `app-modal-icon bg-${c} text-white`;
    i.innerHTML = `<i class="${icon}"></i>`;
    el.querySelector('[data-m="title"]').textContent = title;
    el.querySelector('[data-m="sub"]').innerHTML = sub;
  };

  function previewWindow(t) {
    const el = modal("cmTplPreview", "modal-xl");
    const ch = CH[t.channel] || CH.sms;
    const ours = t.source === "ours";
    head(el, { icon: ch.icon, c: ch.c, title: t.name, sub: `${ch.label} · ${ours ? (t.copied_from ? `our copy of ${esc(t.copied_from.owner || "the diocese")}'s` : "ours") : `from ${esc(t.owner?.name || "the diocese")}`}` });
    const unknown = unknownTokens(t.subject || "", t.body);
    el.querySelector('[data-m="body"]').innerHTML = `
      <div class="row g-4">
        <div class="col-lg-5">
          <div class="cm-read">
            ${t.subject ? `<div class="cm-read-label">Subject</div><div class="cm-read-subject">${esc(t.subject)}</div>` : ""}
            <div class="cm-read-label">The message, as written</div>
            <div class="cm-read-body">${esc(t.body).replace(/\{[a-z_]+\}/gi, (m) => `<mark class="cm-token">${m}</mark>`)}</div>
          </div>
          <div class="cm-read-tokens">
            ${TOKENS.filter(([k]) => `${t.subject || ""} ${t.body}`.includes(k)).map(([k, l], i) => `<span class="soft-chip soft-${["primary", "purple", "pink"][i]}"><code>${k}</code> ${l}</span>`).join("") || '<span class="soft-chip soft-secondary">No placeholders - everyone gets the same text</span>'}
          </div>
          ${unknown.length ? `<div class="cm-note is-warning mt-3 mb-0"><i class="ri-error-warning-line"></i><span>${unknown.map(esc).join(", ")} won't be filled in - they go out exactly as written.</span></div>` : ""}
          ${!ours && !t.our_copy_id ? `<div class="cm-note is-primary mt-3 mb-0"><i class="ri-information-line"></i><span>Make your own copy to send it - ${esc(placeName())} goes in wherever it says <code>{sender}</code>, and you can change anything.</span></div>` : ""}
        </div>
        <div class="col-lg-7"><div id="cmTplPv"></div></div>
      </div>`;
    const pv = CommsPreview(el.querySelector("#cmTplPv"), { view: t.channel === "email" || t.channel === "both" ? "email" : "phone", test: true });
    pv.set({ subject: t.subject || "", body: t.body });

    const foot = el.querySelector('[data-m="foot"]');
    foot.innerHTML = `
      <button type="button" class="btn btn-light border me-auto" data-bs-dismiss="modal">Close</button>
      ${ours ? `<button type="button" class="btn btn-light border" data-f="edit"><i class="ri-pencil-line me-1"></i>Edit</button><a class="btn btn-primary" href="${composeUrl(t)}"><i class="ri-send-plane-line me-1"></i>Use in a campaign</a>` : ""}
      ${!ours && t.our_copy_id ? `<button type="button" class="btn btn-primary" data-f="mine"><i class="ri-arrow-right-line me-1"></i>Open our copy</button>` : ""}
      ${!ours && !t.our_copy_id ? `<button type="button" class="btn btn-success" data-f="copy"><i class="ri-file-copy-line me-1"></i>Make our copy</button>` : ""}`;
    const m = bootstrap.Modal.getOrCreateInstance(el);
    foot.querySelector('[data-f="edit"]')?.addEventListener("click", () => {
      m.hide();
      writer(t);
    });
    foot.querySelector('[data-f="mine"]')?.addEventListener("click", () => previewWindow(state.list.find((x) => x.id === t.our_copy_id)));
    foot.querySelector('[data-f="copy"]')?.addEventListener("click", async (e) => {
      const mine = await copy(t, e.currentTarget);
      if (mine) previewWindow(mine);
    });
    m.show();
  }

  // ------------------------------------------------------------------ the writer

  const STEPS = [
    ["write", "Write", "The subject and the message"],
    ["details", "Details", "Name, how it goes, sharing"],
    ["check", "Check", "Ready to save"],
  ];

  function writer(t) {
    const el = modal("cmTplWriter", "modal-xl");
    const editing = !!t;
    head(el, { icon: editing ? "ri-pencil-line" : "ri-add-line", c: "primary", title: editing ? `Edit "${t.name}"` : "New template", sub: editing && t.copied_from ? `Our copy of ${esc(t.copied_from.owner || "the diocese")}'s - Reset brings their text back` : "Write once, use it whenever you send" });
    const v = { name: t?.name || "", channel: t?.channel || "sms", subject: t?.subject || "", body: t?.body || "", shared_below: !!t?.shared_below };
    let step = "write";
    let last = "body";

    el.querySelector('[data-m="body"]').innerHTML = `
      <div class="cm-writer">
        <div class="cm-writer-form">
          <nav class="cm-steps" role="tablist" aria-label="Steps">
            ${STEPS.map(([k, l, s], i) => `<button type="button" class="cm-step" data-step="${k}" role="tab"><span class="cm-step-mark">${i + 1}</span><span><strong>${l}</strong><small data-step-sub="${k}">${s}</small></span></button>`).join("")}
          </nav>

          <section data-pane="write">
            <div class="mb-3" data-only="email">
              <label class="form-label" for="cmWSubject">Subject</label>
              <input class="form-control cm-input" id="cmWSubject" maxlength="120" placeholder="e.g. You are invited to our youth convention" value="${esc(v.subject)}">
            </div>
            <label class="form-label" for="cmWBody">Message</label>
            <textarea class="form-control cm-input cm-writer-text" id="cmWBody" rows="9" maxlength="10000" placeholder="Dear {name},&#10;&#10;Write your message here…">${esc(v.body)}</textarea>
            <div class="cm-writer-meta"><span data-only="sms" id="cmWSeg"></span><span class="ms-auto">Leave an empty line between paragraphs</span></div>
            <div class="cm-label-sm mt-3">Put in, for each person</div>
            <div class="cm-tokens">
              ${TOKENS.map(([k, l, s], i) => `<button type="button" class="cm-token-btn" data-token="${k}" style="--q: var(--${["primary", "purple", "pink"][i]}-rgb)"><code>${k}</code><span><strong>${l}</strong><small>e.g. ${k === "{name}" ? s : esc(placeName())}</small></span><i class="ri-add-line"></i></button>`).join("")}
            </div>
            <div class="cm-note is-warning mt-3 mb-0" id="cmWUnknown" hidden></div>
          </section>

          <section data-pane="details" hidden>
            <label class="form-label" for="cmWName">Name</label>
            <input class="form-control cm-input mb-3" id="cmWName" maxlength="80" placeholder="e.g. Youth convention invitation" value="${esc(v.name)}">
            <div class="form-label">How it goes</div>
            <div class="ec-choices is-varied cm-channels" role="radiogroup" aria-label="How it goes">
              ${Object.entries(CH).map(([k, c]) => `<label class="ec-choice" style="--q: var(--${c.c}-rgb)"><input type="radio" name="cmWChannel" value="${k}"${v.channel === k ? " checked" : ""}><span class="ec-choice-icon"><i class="${c.icon}"></i></span><strong>${c.label}</strong><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`).join("")}
            </div>
            ${canShare() ? `
            <label class="cm-share mt-3" for="cmWShare">
              <span class="ev-tile is-soft" style="--q: var(--warning-rgb)"><i class="ri-share-forward-line"></i></span>
              <span class="flex-fill"><strong>Share with every ${isDiocese() ? "church and region" : "church in the region"}</strong><small>They see it in their library and make their own copy, with their name for {sender}.</small></span>
              <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="cmWShare"${v.shared_below ? " checked" : ""}></span>
            </label>` : ""}
          </section>

          <section data-pane="check" hidden>
            <ul class="cm-checklist" id="cmWChecklist"></ul>
          </section>
        </div>
        <aside class="cm-writer-side">
          <div class="cm-label-sm mb-2">Live preview</div>
          <div id="cmWPv"></div>
        </aside>
      </div>`;
    el.querySelector('[data-m="foot"]').innerHTML = `
      <button type="button" class="btn btn-light border" id="cmWBack"><i class="ri-arrow-left-line me-1"></i>Back</button>
      <button type="button" class="btn btn-light border ms-auto" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-primary" id="cmWNext">Next</button>
      <button type="button" class="btn btn-success" id="cmWSave"><i class="ri-check-line me-1"></i>${editing ? "Save changes" : "Save template"}</button>`;

    const $ = (id) => el.querySelector(`#${id}`);
    const pv = CommsPreview($("cmWPv"), { view: ["email", "both"].includes(v.channel) ? "email" : "phone", test: true });

    function problems() {
      const out = [];
      if (!v.name.trim()) out.push(["details", "Give it a name"]);
      if (!v.body.trim()) out.push(["write", "Write the message"]);
      if (["email", "both"].includes(v.channel) && !v.subject.trim()) out.push(["write", "An email needs a subject"]);
      return out;
    }
    function marks() {
      const p = problems();
      STEPS.forEach(([k, , s]) => {
        const mine = p.filter((x) => x[0] === k).length;
        const btn = el.querySelector(`.cm-step[data-step="${k}"]`);
        const done = k !== "check" && !mine;
        btn.classList.toggle("is-done", done);
        btn.querySelector(".cm-step-mark").innerHTML = done ? '<i class="ri-check-line"></i>' : String(STEPS.findIndex((x) => x[0] === k) + 1);
        btn.classList.toggle("is-warn", !!mine && k !== step);
        el.querySelector(`[data-step-sub="${k}"]`).textContent = k === "check" ? (p.length ? `${p.length} to fix` : "Ready to save") : mine ? `${mine} to fill in` : s;
      });
      const unknown = unknownTokens(v.subject, v.body);
      const parts = smsParts(fill(v.body));
      $("cmWChecklist").innerHTML = [
        ...[["Name", !!v.name.trim(), v.name || "Not given yet", "details"], ["Message", !!v.body.trim(), v.body ? `${[...v.body].length} characters` : "Not written yet", "write"]],
        ...(["email", "both"].includes(v.channel) ? [["Subject", !!v.subject.trim(), v.subject || "Not written yet", "write"]] : []),
        ...(["sms", "both"].includes(v.channel) ? [["SMS length", parts.parts <= 1, `${parts.parts || 0} ${parts.parts === 1 ? "text" : "texts"} for each person${parts.parts > 1 ? " - each costs" : ""}`, "write", true]] : []),
        ["Placeholders", !unknown.length, unknown.length ? `${unknown.join(", ")} won't be filled in` : "All filled in for each person", "write", true],
        ...(canShare() ? [["Sharing", true, v.shared_below ? "Shared with the places below" : "Only for us", "details", true]] : []),
      ]
        .map(([label, ok, text, go, soft]) => `<li class="${ok ? "is-ok" : soft ? "is-soft" : "is-bad"}"><span class="ev-tile is-sm${ok ? "" : " is-soft"}" style="--q: var(--${ok ? "success" : soft ? "warning" : "danger"}-rgb)"><i class="${ok ? "ri-check-line" : "ri-error-warning-line"}"></i></span><span class="flex-fill"><strong>${label}</strong><small>${esc(text)}</small></span>${ok ? "" : `<button type="button" class="btn btn-sm btn-light border" data-go="${go}">Fix</button>`}</li>`)
        .join("");
      $("cmWUnknown").hidden = !unknown.length;
      $("cmWUnknown").innerHTML = `<i class="ri-error-warning-line"></i><span>${unknown.map(esc).join(", ")} won't be filled in - they go out exactly as written.</span>`;
      $("cmWSeg").innerHTML = segLine(parts);
      $("cmWSeg").className = parts.parts > 1 ? "is-over" : "";
      el.querySelectorAll("[data-token]").forEach((b) => b.classList.toggle("is-used", `${v.subject} ${v.body}`.includes(b.dataset.token)));
    }
    function channelFields() {
      const email = ["email", "both"].includes(v.channel);
      const sms = ["sms", "both", "app"].includes(v.channel);
      el.querySelectorAll('[data-only="email"]').forEach((x) => (x.hidden = !email));
      el.querySelectorAll('[data-only="sms"]').forEach((x) => (x.hidden = !sms));
    }
    function go(k) {
      step = k;
      el.querySelectorAll("[data-pane]").forEach((p) => (p.hidden = p.dataset.pane !== k));
      el.querySelectorAll(".cm-step").forEach((b) => {
        b.classList.toggle("active", b.dataset.step === k);
        b.setAttribute("aria-selected", b.dataset.step === k ? "true" : "false");
      });
      const i = STEPS.findIndex((s) => s[0] === k);
      $("cmWBack").hidden = i === 0;
      $("cmWNext").hidden = i === STEPS.length - 1;
      $("cmWNext").innerHTML = i < STEPS.length - 1 ? `Next: ${STEPS[i + 1][1]}<i class="ri-arrow-right-line ms-1"></i>` : "";
      marks();
    }
    const changed = () => {
      marks();
      pv.set({ subject: v.subject, body: v.body });
    };

    el.querySelectorAll(".cm-step").forEach((b) => b.addEventListener("click", () => go(b.dataset.step)));
    $("cmWBack").addEventListener("click", () => go(STEPS[Math.max(0, STEPS.findIndex((s) => s[0] === step) - 1)][0]));
    $("cmWNext").addEventListener("click", () => go(STEPS[Math.min(STEPS.length - 1, STEPS.findIndex((s) => s[0] === step) + 1)][0]));
    el.querySelector('[data-pane="check"]').addEventListener("click", (e) => e.target.closest("[data-go]") && go(e.target.closest("[data-go]").dataset.go));
    $("cmWSubject").addEventListener("focus", () => (last = "subject"));
    $("cmWBody").addEventListener("focus", () => (last = "body"));
    $("cmWSubject").addEventListener("input", (e) => ((v.subject = e.target.value), changed()));
    $("cmWBody").addEventListener("input", (e) => ((v.body = e.target.value), changed()));
    $("cmWName").addEventListener("input", (e) => ((v.name = e.target.value), marks()));
    $("cmWShare")?.addEventListener("change", (e) => ((v.shared_below = e.target.checked), marks()));
    el.querySelectorAll('input[name="cmWChannel"]').forEach((r) =>
      r.addEventListener("change", () => {
        v.channel = r.value;
        channelFields();
        pv.view(["email", "both"].includes(v.channel) ? "email" : "phone");
        marks();
      }),
    );
    el.querySelectorAll("[data-token]").forEach((b) =>
      b.addEventListener("click", () => {
        const field = last === "subject" && !$("cmWSubject").closest("[data-only]").hidden ? $("cmWSubject") : $("cmWBody");
        const [s, e] = [field.selectionStart ?? field.value.length, field.selectionEnd ?? field.value.length];
        field.value = field.value.slice(0, s) + b.dataset.token + field.value.slice(e);
        field.focus();
        field.selectionStart = field.selectionEnd = s + b.dataset.token.length;
        field.dispatchEvent(new Event("input"));
      }),
    );
    $("cmWSave").addEventListener("click", async () => {
      const p = problems();
      if (p.length) {
        go(p[0][0]);
        Toast.error(p.map((x) => x[1]).join(" · "), { title: "Not saved yet" });
        return;
      }
      const btn = $("cmWSave");
      UI.setButtonLoading(btn, "Saving…");
      const res = await SettingsAPI.saveTemplate(t?.id, { name: v.name.trim(), channel: v.channel, subject: ["email", "both"].includes(v.channel) ? v.subject.trim() || null : null, body: v.body, shared_below: v.shared_below });
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message, { title: "Not saved" });
      upsert(res.data);
      drawList();
      count();
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message, { title: v.name.trim() });
    });

    channelFields();
    go("write");
    pv.set({ subject: v.subject, body: v.body });
    bootstrap.Modal.getOrCreateInstance(el).show();
  }

  // ------------------------------------------------------------------ mount

  window.CommsPreview = CommsPreview;
  window.CommsTemplates = {
    CH,
    smsParts,
    segLine,
    /** Load the list (once per Communication draw); resolves to it, or null when the role can't send. */
    async load() {
      const res = await SettingsAPI.templates();
      state.blocked = res.ok ? null : res.status === 403 ? res.message : null;
      state.list = res.ok ? res.data : [];
      return res.ok ? state.list : null;
    },
    /** Draws the library into the Templates tab. onCount(list) keeps the tab's figure current. */
    mount(host, { onCount } = {}) {
      state.host = host;
      state.onCount = onCount;
      drawShell();
    },
    newTemplate: () => writer(null),
    composeUrl,
  };
})();
