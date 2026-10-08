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
  const base = () => (typeof AppConfig !== "undefined" ? AppConfig.FRONTEND_BASE_URL : "") + `/${level()}/messages`;
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
  // The phone and mail-app frames are shared (assets/js/utils/message-frames.js).
  const { phoneHtml, mailHtml, fitFrame, initials } = window.MessageFrames;

  /**
   * A live preview that looks like the real thing (v1-events' previews, made fuller):
   *   - On a phone: a handset with the status bar, the Messages header (sender
   *     ID), "Today", the incoming bubble and the input bar;
   *   - As an email: a mail app - subject, the sender with our logo, "to
   *     Stephen Mutua" - around the real email, rendered by the server into a
   *     sandboxed frame. Mobile puts the same mail app inside the handset.
   *   CommsPreview(host, { view, test, smsText(data, text), from(data), sender(data) })
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
            <input type="radio" name="${id}View" id="${id}Phone" value="phone"><label for="${id}Phone"><i class="ri-message-2-line me-1"></i>As an SMS</label>
            <input type="radio" name="${id}View" id="${id}Email" value="email"><label for="${id}Email"><i class="ri-mail-line me-1"></i>As an email</label>
          </div>
          <div class="pb-segment cm-pv-width" role="radiogroup" aria-label="Email on">
            <input type="radio" name="${id}Width" id="${id}Desk" value="desktop" checked><label for="${id}Desk"><i class="ri-computer-line me-1"></i>Desktop</label>
            <input type="radio" name="${id}Width" id="${id}Mob" value="mobile"><label for="${id}Mob"><i class="ri-smartphone-line me-1"></i>Mobile</label>
          </div>
        </div>

        <div class="cm-pv-stage" data-stage="phone">
          ${phoneHtml()}
          <div class="nw-seg" data-pv="seg"></div>
        </div>

        <div class="cm-pv-stage" data-stage="email" hidden>
          ${mailHtml()}
        </div>

        <div class="cm-pv-foot">
          <span class="cm-pv-note"><i class="ri-user-smile-line"></i>Written to ${SAMPLE}, a sample person</span>
          ${opts.test ? `<button type="button" class="btn btn-sm btn-primary ms-auto" data-pv="test"><i class="ri-send-plane-line me-1"></i>Send this to me</button>` : ""}
        </div>
        <div class="cm-note is-warning mt-3 mb-0" data-pv="error" hidden></div>
      </div>`;
    const $ = (k) => host.querySelector(`[data-pv="${k}"]`);
    const frame = $("frame");
    const stage = host.querySelector('[data-stage="email"]');

    const fit = () => fitFrame(frame);
    MessageFrames.autoFit(frame);

    function drawPhone() {
      const text = opts.smsText ? opts.smsText(server, fill(now.body)) : server?.sms?.text ?? fill(now.body);
      const sender = (opts.sender ? opts.sender(server) : server?.sender) || "";
      $("sender").textContent = sender || "Your sender ID";
      $("initials").innerHTML = initials(sender) || '<i class="ri-user-3-fill"></i>';
      $("bubble").textContent = text || "Your message shows here.";
      const p = smsParts(text || "");
      $("seg").className = `nw-seg${p.parts > 1 ? " is-over" : ""}`;
      $("seg").innerHTML = segLine(p);
    }
    function drawEmailHead() {
      const from = (opts.from ? opts.from(server) : server?.from) || "";
      const m = from.match(/^(.*?)\s*<([^>]*)>$/);
      $("fromName").textContent = (m ? m[1] : from) || "…";
      $("fromAddr").textContent = m ? `<${m[2]}>` : "";
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
        stage.classList.toggle("is-mobile", r.value === "mobile");
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

  let state = { host: null, list: [], blocked: null, onCount: null };

  const isDiocese = () => level() === "diocese";
  const canShare = () => level() !== "church";

  /** Where a template comes from, as the From column says it (and filters on it). */
  function fromOf(t) {
    if (t.source === "shared") return { label: t.owner?.type === "region" ? "From the region" : "From the diocese", c: "purple", icon: "ri-building-4-line" };
    if (t.copied_from) return { label: "Our copy", c: "primary", icon: "ri-file-copy-line" };
    if (t.shared_below) return { label: "Shared below", c: "warning", icon: "ri-share-forward-line" };
    return { label: isDiocese() ? "Diocese only" : "Ours", c: "success", icon: "ri-bookmark-line" };
  }
  const firstLine = (t) => fill(t.channel === "email" || t.channel === "both" ? t.subject || t.body : t.body).replace(/\s+/g, " ").trim();
  const day = (iso) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");

  /** One row - v1's template table: the template (icon, name, first line), channel, from, length, updated, actions. */
  function row(t) {
    const ch = CH[t.channel] || CH.sms;
    const ours = t.source === "ours";
    const from = fromOf(t);
    const p = smsParts(fill(t.body));
    const length = t.channel === "email" ? "Email" : t.channel === "app" ? "In the app" : `${p.parts} ${p.parts === 1 ? "text" : "texts"}`;
    const menu = [
      ours ? `<a class="dropdown-item" href="${composeUrl(t)}"><i class="ri-send-plane-line me-2"></i>Use in a campaign</a>` : "",
      !ours && !t.our_copy_id ? `<button type="button" class="dropdown-item" data-act="copy"><i class="ri-file-copy-line me-2"></i>Make our copy</button>` : "",
      !ours && t.our_copy_id ? `<button type="button" class="dropdown-item" data-act="mine"><i class="ri-arrow-right-line me-2"></i>Open our copy</button>` : "",
      ours ? `<button type="button" class="dropdown-item" data-act="edit"><i class="ri-pencil-line me-2"></i>Edit</button>` : "",
      ours && t.copied_from ? `<button type="button" class="dropdown-item" data-act="reset"><i class="ri-arrow-go-back-line me-2"></i>Reset to ${esc(t.copied_from.owner || "the diocese")}'s text</button>` : "",
      ours ? `<div class="dropdown-divider"></div><button type="button" class="dropdown-item text-danger" data-act="remove"><i class="ri-delete-bin-6-line me-2"></i>Remove</button>` : "",
    ].join("");
    return `
      <tr data-tpl="${t.id}">
        <td data-label="Template" data-search="${esc(t.name)} ${esc(t.subject || "")}">
          <button type="button" class="cm-tpl-cell" data-act="preview" title="Preview">
            <span class="cm-tpl-icon"><i class="${ch.icon}"></i></span>
            <span class="cm-tpl-text"><strong>${esc(t.name)}</strong><small>${esc(firstLine(t))}</small></span>
          </button>
        </td>
        <td data-label="Channel" data-search="${ch.label}">${UI.pill(ch.label, ch.c, ch.icon)}</td>
        <td data-label="From" data-search="${from.label}"><span class="soft-chip soft-${from.c}"><i class="${from.icon}"></i>${from.label}</span>${!ours && t.our_copy_id ? ' <span class="cm-has-copy" title="You have a copy"><i class="ri-check-line"></i></span>' : ""}</td>
        <td data-label="Length" data-order="${t.channel === "email" ? 99 : p.parts}">${length}</td>
        <td data-label="Updated" class="text-nowrap" data-order="${esc(t.updated_at || "")}">${day(t.updated_at)}</td>
        <td class="text-end text-nowrap cm-tpl-actions">
          <button type="button" class="btn btn-sm btn-primary" data-act="preview"><i class="ri-eye-line me-1"></i>Preview</button>
          ${menu ? `<div class="dropdown d-inline-block"><button type="button" class="btn btn-sm btn-icon btn-light border" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="More for ${esc(t.name)}"><i class="ri-more-2-fill"></i></button><div class="dropdown-menu dropdown-menu-end">${menu}</div></div>` : ""}
        </td>
      </tr>`;
  }

  const FILTERS = [
    { id: "cmTplChannel", columnIndex: 1, exact: true },
    { id: "cmTplFrom", columnIndex: 2, exact: true },
  ];

  /** The table and its filter bar - drawn again after a change, keeping what was typed and picked. */
  function drawList() {
    const box = state.host.querySelector("#cmTplList");
    if (!box) return;
    const keep = { q: box.querySelector("#cmTplToolbarSearch")?.value || "", ...Object.fromEntries(FILTERS.map((f) => [f.id, box.querySelector(`#${f.id}`)?.value || ""])) };
    const cols = ["Template", "Channel", "From", "Length", "Updated", ""];
    const froms = [...new Map(state.list.map((t) => [fromOf(t).label, fromOf(t)])).values()];
    box.innerHTML = `
      <div id="cmTplToolbar"></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0 cm-table" id="cmTplTable">
          <thead><tr>${cols.map((c) => `<th>${c}</th>`).join("")}</tr></thead>
          <tbody>${state.list.length ? state.list.map(row).join("") : UI.renderTableEmpty(cols.length, "No templates yet - write the first one", "ri-file-list-3-line")}</tbody>
        </table>
      </div>`;
    UI.renderFilterToolbar("cmTplToolbar", {
      searchPlaceholder: "Search templates…",
      filters: [
        { id: "cmTplChannel", label: "Every channel", options: Object.values(CH).map((c) => ({ value: c.label, label: c.label, color: c.c })) },
        { id: "cmTplFrom", label: "From anywhere", options: froms.map((f) => ({ value: f.label, label: f.label, color: f.c })) },
      ],
    });
    const table = state.list.length ? UI.initListDataTable("cmTplTable", { order: [[0, "asc"]], nonSortableColumns: [cols.length - 1], hideDefaultSearch: true, noun: "templates", pageLength: 25, responsive: false }) : null;
    UI.wireFilterToolbar("cmTplToolbar", table, FILTERS, { noun: "templates", urlSync: false });
    FILTERS.forEach((f) => {
      const sel = box.querySelector(`#${f.id}`);
      if (sel && keep[f.id] && [...sel.options].some((o) => o.value === keep[f.id])) {
        sel.value = keep[f.id];
        UI.syncSelect?.(sel);
        sel.dispatchEvent(new Event("change"));
      }
    });
    const search = box.querySelector("#cmTplToolbarSearch");
    if (search && keep.q) {
      search.value = keep.q;
      search.dispatchEvent(new Event("input"));
    }
  }

  function drawShell() {
    if (state.blocked) {
      state.host.innerHTML = `<div class="cm-locked"><span class="ev-tile is-soft" style="--q: var(--danger-rgb)"><i class="ri-lock-line"></i></span><div><h3>Templates are for people who send messages</h3><p>${esc(state.blocked)}</p></div></div>`;
      return;
    }
    state.host.innerHTML = `
      <div class="card custom-card cm-tpl-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm bg-purple text-white"><i class="ri-file-list-3-line"></i></span>
            <div>
              <div class="card-title mb-0">Templates</div>
              <div class="settings-card-sub">${canShare() ? `Shared ones reach every ${isDiocese() ? "church and region" : "church"} below - they copy them, with their own name in.` : "The diocese's ready-made messages, and ours. Copy one and your church's name goes in."}</div>
            </div>
          </div>
          <button type="button" class="btn btn-primary" id="cmTplNew"><i class="ri-add-line me-1"></i>New template</button>
        </div>
        <div class="card-body" id="cmTplList"></div>
      </div>`;
    state.host.querySelector("#cmTplNew").addEventListener("click", () => writer(null));
    state.host.querySelector("#cmTplList").addEventListener("click", (e) => {
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
    head(el, { icon: ch.icon, c: "primary", title: t.name, sub: `${ch.label} · ${ours ? (t.copied_from ? `our copy of ${esc(t.copied_from.owner || "the diocese")}'s` : "ours") : `from ${esc(t.owner?.name || "the diocese")}`}` });
    const unknown = unknownTokens(t.subject || "", t.body);
    // The details panel (2026-10-08): the summary, calm rows, the message as written, and the hint at its foot.
    const from = ours ? (t.copied_from ? `Our copy of ${t.copied_from.owner || "the diocese"}'s` : "Ours") : t.owner?.name || "The diocese";
    const used = TOKENS.filter(([k]) => `${t.subject || ""} ${t.body}`.includes(k)).map(([, l]) => l);
    const rows = [
      [ch.icon, "Channel", ch.label],
      ["ri-building-4-line", "From", from],
      ...(t.subject ? [["ri-text", "Subject", t.subject]] : []),
      ["ri-braces-line", "Placeholders", used.length ? used.join(", ") : "None - everyone gets the same text"],
    ];
    const body = el.querySelector('[data-m="body"]');
    body.classList.add("is-sheet");
    // Teal and purple, pale for facts, solid for the one signal; placeholders and [brackets] as pills.
    const tile = (icon, i) => `<span class="cm-detail-tile ${["is-teal is-solid", "is-purple", "is-teal", "is-purple is-solid"][i % 4]}"><i class="${icon}"></i></span>`; // solid and pale in turn, teal and purple
    const tokenPills = used.length ? TOKENS.filter(([k]) => `${t.subject || ""} ${t.body}`.includes(k)).map(([k, l]) => `<span class="cm-tok">${k}</span> ${esc(l)}`).join('<span class="cm-dot">·</span>') : "None - everyone gets the same text";
    const asWritten = esc(t.body)
      .replace(/\{[a-z_]+\}/gi, (m) => `<span class="cm-tok">${m}</span>`)
      .replace(/\[[a-z ]+\]/gi, (m) => `<span class="cm-brk">${m}</span>`);
    const hasBrackets = /\[[a-z ]+\]/i.test(t.body);
    body.innerHTML = `
      <div class="cm-sheet">
        <aside class="cm-sheet-side">
          <div class="cm-hero-card">
            <div class="cm-sheet-sum">
              <span class="cm-sheet-icon"><i class="${ch.icon}"></i></span>
              <div class="min-w-0 flex-fill"><strong>${esc(ch.label)} template</strong><small>${esc(from)}</small></div>
              <button type="button" class="btn btn-sm btn-light border cm-sheet-toggle" data-bs-toggle="collapse" data-bs-target="#tplSheetMore" aria-expanded="false">Details</button>
            </div>
            <div class="cm-hero-chips">
              <span class="badge bg-primary list-pill"><i class="${ch.icon} me-1"></i>${esc(ch.label)}</span>
              <span class="soft-chip soft-purple"><i class="${ours ? "ri-bookmark-line" : "ri-building-4-line"}"></i>${ours ? (t.copied_from ? "Our copy" : "Ours") : "From the diocese"}</span>
            </div>
          </div>
          <div class="collapse cm-sheet-more" id="tplSheetMore">
            <div class="cm-side-card">
              <div class="cm-side-head">${tile("ri-list-check-2", 0)}<strong>Details</strong></div>
              <dl class="cm-details">${rows
                .map(([i, k, v], n) => `<div class="cm-detail">${tile(i, n)}<div class="min-w-0"><dt>${esc(k)}</dt><dd>${k === "Placeholders" ? tokenPills : esc(v)}</dd></div></div>`)
                .join("")}</dl>
            </div>
            <div class="cm-side-card">
              <div class="cm-side-head">${tile("ri-quill-pen-line", 3)}<strong>As written</strong><button type="button" class="cm-side-link" id="tplCopyText"><i class="ri-file-copy-line"></i>Copy</button></div>
              <div class="cm-sheet-text">${asWritten}</div>
              <div class="cm-side-legend"><span><span class="cm-tok">{name}</span> fills in for each person</span>${hasBrackets ? '<span><span class="cm-brk">[event]</span> change before sending</span>' : ""}</div>
            </div>
          </div>
          <div class="cm-sheet-foot">
            ${unknown.length ? `<div class="cm-sheet-error"><i class="ri-error-warning-line"></i><span><b>Won't be filled in</b>${unknown.map(esc).join(", ")} - they go out exactly as written.</span></div>` : ""}
            ${!ours && !t.our_copy_id ? `<div class="cm-sheet-note"><i class="ri-lightbulb-line"></i><span>Make your own copy to send it - <b>${esc(placeName())}</b> goes in wherever it says {sender}, and you can change anything.</span></div>` : ""}
          </div>
        </aside>
        <section class="cm-sheet-main"><div id="cmTplPv"></div></section>
      </div>`;
    body.querySelector("#tplCopyText").addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(t.body);
        Toast.success("Copied.");
      } catch (e) {
        Toast.error("Couldn't copy - select the text instead.");
      }
    });
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
  Object.assign(CommsPreview, { phoneHtml, mailHtml, fitFrame }); // kept for the Log (messages.js)
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
