/**
 * ============================================================================
 * MESSAGES - our inbox, in the template's chat layout (chat.html)
 * ============================================================================
 * Left: Messages, search, All / Diocese / Region / Church with counts, and
 * the list (Unread, then Earlier). Middle: the conversation - their message
 * as their bubble, your replies as yours, by day - and the reply box with
 * saved messages as one-tap chips. Right: who sent it, their place (logo,
 * contact, YouTube), its photos, and their other messages to you.
 * On a phone the list fills the screen and a message opens over it, with
 * Back; the details open from the info button. ?open= keeps the message.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const CTX = window.MESSAGES_CTX;
  const $ = (id) => document.getElementById(id);
  const state = { from: "", open: Number(new URLSearchParams(window.location.search).get("open")) || null, q: "" };
  let inbox = null;
  let templates = [];
  let lightbox = null;

  const LEVEL = { diocese: "Diocese", region: "Region", church: "Church" };
  const TABS = [
    ["", "All", "ri-inbox-line"],
    ["diocese", "Diocese", "ri-building-4-line"],
    ["region", "Region", "ri-map-2-line"],
    ["church", "Church", "ri-home-heart-line"],
  ];
  const ME = (() => {
    try {
      return JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.USER_DATA) || "null");
    } catch (e) {
      return null;
    }
  })();
  const myName = () => ME?.full_name || [ME?.firstname, ME?.lastname].filter(Boolean).join(" ") || "You";
  const initials = (name) => (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join("");
  const isPhone = () => window.matchMedia("(max-width: 991.98px)").matches;
  const place = (m) => inbox?.places?.[m.from.id] || {};
  const fromOf = (m) => M.FROM[m.from.type] || M.FROM.church;

  /** Paragraphs on blank lines, line breaks kept. */
  const paragraphs = (s) =>
    String(s || "")
      .trim()
      .split(/\n\s*\n/)
      .map((p) => `<p>${M.esc(p).replace(/\n/g, "<br>")}</p>`)
      .join("") || "<p>-</p>";
  const dayKey = (iso) => new Date(iso).toDateString();
  function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return "Today";
    if (d.toDateString() === yesterday.toDateString()) return "Yesterday";
    return d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", year: d.getFullYear() === today.getFullYear() ? undefined : "numeric" });
  }
  const clock = (iso) => (iso ? new Date(iso).toLocaleTimeString("en-GB", { hour: "numeric", minute: "2-digit" }) : "");
  const listTime = (iso) => (!iso ? "" : dayKey(iso) === new Date().toDateString() ? clock(iso) : new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" }));

  /** The sender's face: their photo, else their initials; with no person, the place's logo or its level tile. */
  function avatar(m, size = "md") {
    if (m.by_photo_url) return `<span class="avatar avatar-${size} avatar-rounded mi-av"><img src="${M.esc(m.by_photo_url)}" alt=""></span>`;
    if (m.by) return `<span class="avatar avatar-${size} avatar-rounded bg-${UI.colorFor(m.by)} text-white fw-semibold">${M.esc(initials(m.by))}</span>`;
    const logo = place(m).logo_url;
    if (logo) return `<span class="avatar avatar-${size} avatar-rounded mi-av is-logo"><img src="${M.esc(logo)}" alt=""></span>`;
    const f = fromOf(m);
    return `<span class="avatar avatar-${size} avatar-rounded bg-${f.color} text-white"><i class="${f.icon}"></i></span>`;
  }
  const myAvatar = (size = "md") =>
    ME?.photo_url ? `<span class="avatar avatar-${size} avatar-rounded mi-av"><img src="${M.esc(ME.photo_url)}" alt=""></span>` : `<span class="avatar avatar-${size} avatar-rounded bg-primary text-white fw-semibold">${M.esc(initials(myName()))}</span>`;

  function syncUrl() {
    const q = new URLSearchParams(window.location.search);
    state.open ? q.set("open", state.open) : q.delete("open");
    history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
  }

  // ================================================================ load
  async function load() {
    $("miList").innerHTML = Array.from({ length: 6 }, () => `<li class="mi-chat-skel"><span class="skel skel-tile"></span><div class="flex-fill"><span class="skel skel-line" style="width:60%"></span><span class="skel skel-line skel-line-sm mt-2" style="width:85%"></span></div></li>`).join("");
    const [res, tpl] = await Promise.all([MessagesAPI.inbox(""), CTX.can.send ? MessagesAPI.templates() : null]);
    if (!res?.ok) {
      $("miList").innerHTML = `<li class="p-3">${M.empty("ri-error-warning-line", "Couldn't load your messages", M.esc(res?.message || ""), "danger")}<button type="button" class="btn btn-primary mt-2" id="miRetry"><i class="ri-refresh-line me-1"></i>Try again</button></li>`;
      $("miRetry").addEventListener("click", load);
      $("miMain").innerHTML = "";
      return;
    }
    inbox = res.data;
    templates = tpl?.ok ? tpl.data : [];
    renderTabs();
    renderList();
    const list = filtered();
    const pick = inbox.items.find((m) => m.id === state.open) || (!isPhone() ? list[0] : null);
    if (pick) open(pick.id, { slide: !!state.open });
    else blank();
  }

  // ================================================================ the list
  function renderTabs() {
    const unread = inbox.unread;
    $("miUnread").hidden = !unread;
    $("miUnread").textContent = unread;
    const count = (v) => inbox.items.filter((m) => !v || m.from.type === v).length;
    $("miFrom").innerHTML = TABS.map(
      ([v, label, icon]) => `<li class="nav-item" role="presentation"><button class="nav-link${state.from === v ? " active" : ""}" type="button" role="tab" aria-selected="${state.from === v}" data-from="${v}"><i class="${icon} me-1 align-middle"></i>${label}<span class="mi-chat-count">${count(v)}</span></button></li>`,
    ).join("");
  }

  function filtered() {
    const q = state.q.toLowerCase();
    return (inbox?.items || []).filter((m) => (!state.from || m.from.type === state.from) && (!q || [m.subject, m.body, m.from.name, m.by].join(" ").toLowerCase().includes(q)));
  }

  function row(m) {
    const unread = !m.read_at;
    const replies = m.my_replies.length;
    return `<li class="checkforactive${unread ? " chat-msg-unread" : ""}${state.open === m.id ? " active" : ""}" data-open="${m.id}">
      <a href="javascript:void(0);" aria-label="${M.esc(m.subject || "Message")} from ${M.esc(m.from.name)}">
        <div class="d-flex align-items-top">
          <div class="me-2 lh-1 flex-shrink-0">${avatar(m, "md")}</div>
          <div class="flex-fill min-w-0">
            <p class="mb-0 fw-semibold d-flex align-items-center gap-2"><span class="text-truncate">${M.esc(m.from.name)}</span><span class="ms-auto mi-chat-time">${listTime(m.at)}</span></p>
            <p class="mb-0 mi-chat-subject text-truncate">${unread ? '<span class="mi-chat-dot" aria-label="Unread"></span>' : ""}${M.esc(m.subject || "(No subject)")}</p>
            <p class="fs-12 mb-0 d-flex align-items-center gap-2"><span class="chat-msg text-truncate">${M.esc(m.body || "")}</span>${replies ? `<span class="soft-chip soft-primary ms-auto mi-chat-replies"><i class="ri-reply-line"></i>${replies}</span>` : ""}</p>
          </div>
        </div>
      </a>
    </li>`;
  }

  function renderList() {
    const list = filtered();
    if (!list.length) {
      const narrowed = state.q || state.from;
      $("miList").innerHTML = `<li class="p-3">${M.empty("ri-inbox-line", narrowed ? "Nothing matches" : "Nothing here yet", narrowed ? "Try All, or another word." : "Messages from your church, region or the diocese show here.")}</li>`;
      return;
    }
    const unread = list.filter((m) => !m.read_at);
    const earlier = list.filter((m) => m.read_at);
    const head = (label, n) => `<li class="pb-0 mi-chat-group"><p class="fs-11 fw-semibold mb-2 op-7">${label}<span class="mi-chat-count">${n}</span></p></li>`;
    $("miList").innerHTML = (unread.length ? head("UNREAD", unread.length) + unread.map(row).join("") : "") + (earlier.length ? head(unread.length ? "EARLIER" : "ALL MESSAGES", earlier.length) + earlier.map(row).join("") : "");
  }

  // ================================================================ the conversation
  function blank() {
    state.open = null;
    syncUrl();
    $("miChat").classList.remove("responsive-chat-open");
    $("miMain").innerHTML = `<div class="mi-chat-empty">${M.empty("ri-chat-3-line", "Pick a message", "Choose one on the left to read it here.")}</div>`;
    $("chat-user-details").innerHTML = "";
  }

  function open(id, { slide = true } = {}) {
    const m = inbox.items.find((x) => x.id === id);
    if (!m) return blank();
    state.open = id;
    syncUrl();
    document.querySelectorAll("#miList [data-open]").forEach((li) => li.classList.toggle("active", Number(li.dataset.open) === id));
    if (slide && isPhone()) $("miChat").classList.add("responsive-chat-open");
    renderMain(m);
    renderDetails(m);
    markRead(m);
  }

  function bubbleTheirs(m) {
    return `<li class="chat-item-start">
      <div class="chat-list-inner">
        <div class="chat-user-profile">${avatar(m, "md")}</div>
        <div class="ms-3">
          <span class="chatting-user-info">${M.esc(m.by || m.from.name)}<span class="msg-sent-time">${clock(m.at)}</span></span>
          <div class="main-chat-msg">
            <div class="mi-bubble">
              <p class="mi-bubble-subject">${M.esc(m.subject || "(No subject)")}</p>
              ${paragraphs(m.body)}
              <p class="mi-bubble-sign">- ${M.esc(m.by || m.from.name)}${m.by ? `, ${M.esc(m.from.name)}` : ""}</p>
            </div>
          </div>
        </div>
      </div>
    </li>`;
  }

  function bubbleMine(r) {
    return `<li class="chat-item-end">
      <div class="chat-list-inner">
        <div class="me-3">
          <span class="chatting-user-info"><span class="msg-sent-time"><span class="chat-read-mark"><i class="ri-check-double-line"></i></span>${clock(r.at)}</span>You</span>
          <div class="main-chat-msg"><div>${paragraphs(r.body)}</div></div>
        </div>
        <div class="chat-user-profile">${myAvatar("md")}</div>
      </div>
    </li>`;
  }

  function thread(m) {
    const out = [];
    let day = null;
    const label = (iso) => {
      if (dayKey(iso) === day) return;
      day = dayKey(iso);
      out.push(`<li class="chat-day-label"><span>${dayLabel(iso)}</span></li>`);
    };
    label(m.at);
    out.push(bubbleTheirs(m));
    m.my_replies.forEach((r) => {
      label(r.at);
      out.push(bubbleMine(r));
    });
    return out.join("");
  }

  function renderMain(m) {
    const f = fromOf(m);
    $("miMain").innerHTML = `
      <div class="d-flex align-items-center gap-2 p-2 ps-3 border-bottom mi-chat-head">
        <button type="button" class="btn btn-icon btn-light responsive-chat-close mi-chat-back" data-back aria-label="Back to the list"><i class="ri-arrow-left-line"></i></button>
        <div class="lh-1 flex-shrink-0">${avatar(m, "lg")}</div>
        <div class="flex-fill min-w-0">
          <p class="mb-0 fw-semibold fs-14 text-truncate">${M.esc(m.by || m.from.name)}</p>
          <p class="mb-0 fs-12 d-flex align-items-center gap-1 flex-wrap mi-chat-headsub"><span class="soft-chip soft-${f.color === "primary" ? "primary" : f.color}"><i class="${f.icon}"></i>${M.esc(m.from.name)}</span>${M.channelChip(m.channel)}</p>
        </div>
        <div class="d-flex flex-nowrap gap-1">
          <button type="button" class="btn btn-icon btn-primary-light" data-copy title="Copy the message" aria-label="Copy the message"><i class="ri-file-copy-line"></i></button>
          ${CTX.can.send ? `<a class="btn btn-icon btn-primary-light" href="${CTX.baseUrl}/new" title="Send a new message" aria-label="Send a new message"><i class="ri-send-plane-line"></i></a>` : ""}
          <button type="button" class="btn btn-icon btn-purple-light responsive-userinfo-open" data-info title="Who sent it" aria-label="Who sent it"><i class="ri-information-line"></i></button>
        </div>
      </div>
      <div class="chat-content mi-chat-content" id="main-chat-content">
        <ul class="list-unstyled">${thread(m)}</ul>
      </div>
      <div class="mi-chat-footer">
        ${templates.length ? `<div class="mi-chat-chips">${templates.slice(0, 4).map((t) => `<button type="button" class="soft-chip soft-purple" data-saved="${t.id}"><i class="ri-bookmark-line"></i>${M.esc(t.name)}</button>`).join("")}</div>` : ""}
        <div class="d-flex align-items-end gap-2">
          <textarea class="form-control mi-chat-input" id="miReplyBody" rows="1" maxlength="2000" placeholder="Reply to ${M.esc(m.by || m.from.name)}..." aria-label="Your reply"></textarea>
          <button type="button" class="btn btn-primary btn-icon btn-send flex-shrink-0" id="miReplyBtn" title="Send (Ctrl+Enter)" aria-label="Send the reply"><i class="ri-send-plane-2-line"></i></button>
        </div>
      </div>`;
    const content = $("main-chat-content");
    content.scrollTop = content.scrollHeight;
    const body = $("miReplyBody");
    const grow = () => {
      body.style.height = "auto";
      body.style.height = `${Math.min(body.scrollHeight, 160)}px`;
    };
    body.addEventListener("input", grow);
    $("miMain").querySelector("[data-copy]").addEventListener("click", () => copy(m.body));
    $("miMain").querySelectorAll("[data-saved]").forEach((b) =>
      b.addEventListener("click", () => {
        const t = templates.find((x) => x.id === Number(b.dataset.saved));
        body.value = t.body.replace(/\{name\}/g, m.by || m.from.name).replace(/\{place\}/g, m.from.name).replace(/\{sender\}/g, myName());
        grow();
        body.focus();
      }),
    );
    const send = async () => {
      const text = body.value.trim();
      if (!text) return Toast.warning("Write a reply first.");
      UI.setButtonLoading($("miReplyBtn"), "");
      const res = await MessagesAPI.reply(m.id, text);
      UI.restoreButton($("miReplyBtn"));
      if (!res.ok) return Toast.error(res.message);
      Object.assign(m, res.data);
      Toast.success("Reply sent");
      renderList();
      renderMain(m);
      $("miReplyBody").focus();
    };
    $("miReplyBtn").addEventListener("click", send);
    body.addEventListener("keydown", (e) => e.key === "Enter" && (e.metaKey || e.ctrlKey) && (e.preventDefault(), send()));
  }

  // ================================================================ who sent it
  function renderDetails(m) {
    const f = fromOf(m);
    const p = place(m);
    const others = inbox.items.filter((x) => x.from.id === m.from.id && x.id !== m.id).slice(0, 5);
    const photos = p.photos || [];
    const contact = [
      p.phone ? `<a class="mi-chat-contact" href="tel:${M.esc(p.phone.replace(/\s+/g, ""))}"><i class="ri-phone-line"></i>${M.esc(p.phone)}</a>` : "",
      p.email ? `<a class="mi-chat-contact" href="mailto:${M.esc(p.email)}"><i class="ri-mail-line"></i>${M.esc(p.email)}</a>` : "",
      p.youtube_url ? `<a class="mi-chat-contact is-yt" href="${M.esc(p.youtube_url)}" target="_blank" rel="noopener"><i class="ri-youtube-fill"></i>Services on YouTube</a>` : "",
    ].join("");
    $("chat-user-details").innerHTML = `
      <button type="button" class="btn btn-icon btn-light mi-chat-close" data-info-close aria-label="Close"><i class="ri-close-line"></i></button>
      <div class="mi-chat-details">
        <div class="text-center mb-4">
          <div class="mb-2 mi-chat-bigav">${avatar(m, "xxl")}</div>
          <p class="mb-0 fw-semibold fs-16">${M.esc(m.by || m.from.name)}</p>
          <p class="mb-2 fs-12">${M.esc(m.by_position || (m.by ? `Sent for ${m.from.name}` : LEVEL[m.from.type] || ""))}</p>
          <div class="d-flex justify-content-center gap-2">
            <button type="button" class="btn btn-icon rounded-pill btn-primary" data-focus-reply title="Reply" aria-label="Reply"><i class="ri-reply-line"></i></button>
            <button type="button" class="btn btn-icon rounded-pill btn-primary-light" data-copy-2 title="Copy the message" aria-label="Copy the message"><i class="ri-file-copy-line"></i></button>
            ${CTX.can.send ? `<a class="btn btn-icon rounded-pill btn-purple-light" href="${CTX.baseUrl}/new" title="Send a new message" aria-label="Send a new message"><i class="ri-send-plane-line"></i></a>` : ""}
          </div>
        </div>

        <div class="mi-chat-placecard mb-4">
          <div class="d-flex align-items-center gap-2">
            ${p.logo_url ? `<span class="avatar avatar-md avatar-rounded mi-av is-logo"><img src="${M.esc(p.logo_url)}" alt=""></span>` : `<span class="avatar avatar-md avatar-rounded bg-${f.color} text-white"><i class="${f.icon}"></i></span>`}
            <div class="min-w-0"><p class="mb-0 fw-semibold text-truncate">${M.esc(m.from.name)}</p><span class="badge bg-${f.color} text-white">${LEVEL[m.from.type] || ""}</span></div>
          </div>
          ${contact ? `<div class="mt-3 d-flex flex-column gap-1">${contact}</div>` : ""}
        </div>

        <div class="mb-4">
          <div class="d-flex align-items-center justify-content-between mb-2"><p class="mb-0 fw-semibold">From them lately <span class="mi-chat-count">${others.length}</span></p></div>
          ${others.length
            ? `<ul class="list-unstyled mb-0 mi-chat-others">${others.map((x) => `<li><button type="button" data-open="${x.id}"><span class="mi-chat-others-icon"><i class="ri-mail-line"></i></span><span class="min-w-0 flex-fill text-start"><span class="d-block fw-semibold text-truncate">${M.esc(x.subject || "(No subject)")}</span><span class="d-block fs-11">${listTime(x.at)}${x.read_at ? "" : " · Unread"}</span></span></button></li>`).join("")}</ul>`
            : `<p class="fs-12 mb-0">Nothing else from ${M.esc(m.from.name)} yet.</p>`}
        </div>

        <div>
          <div class="d-flex align-items-center justify-content-between mb-2"><p class="mb-0 fw-semibold">Photos <span class="mi-chat-count is-purple">${photos.length}</span></p></div>
          ${photos.length
            ? `<div class="row g-2">${photos.map((ph) => `<div class="col-4"><a class="chat-media mi-chat-photo" href="${M.esc(ph.url)}" data-gallery="sender" ${ph.caption ? `data-title="${M.esc(ph.caption)}"` : ""}><img src="${M.esc(ph.thumb_url)}" alt="${M.esc(ph.caption || "")}" loading="lazy"></a></div>`).join("")}</div>`
            : `<p class="fs-12 mb-0">${M.esc(m.from.name)} hasn't added photos yet.</p>`}
        </div>
      </div>`;
    if (window.GLightbox) {
      lightbox?.destroy();
      lightbox = photos.length ? GLightbox({ selector: "#chat-user-details .mi-chat-photo" }) : null;
    }
  }

  async function markRead(m) {
    if (m.read_at) return;
    const res = await MessagesAPI.read(m.id);
    if (!res.ok) return;
    m.read_at = res.data.read_at;
    inbox.unread = Math.max(0, inbox.unread - 1);
    renderTabs();
    renderList();
  }

  async function copy(text) {
    try {
      await navigator.clipboard.writeText(text || "");
      Toast.success("Copied");
    } catch (e) {
      Toast.error("Couldn't copy - select the text instead.");
    }
  }

  // ================================================================ start
  document.addEventListener("DOMContentLoaded", () => {
    $("miChat").addEventListener("click", (ev) => {
      const tab = ev.target.closest("[data-from]");
      if (tab) {
        state.from = tab.dataset.from;
        renderTabs();
        return renderList();
      }
      const li = ev.target.closest("[data-open]");
      if (li) {
        $("chat-user-details").classList.remove("open");
        return open(Number(li.dataset.open));
      }
      if (ev.target.closest("[data-back]")) {
        $("miChat").classList.remove("responsive-chat-open");
        state.open = null;
        return syncUrl();
      }
      if (ev.target.closest("[data-info]")) return $("chat-user-details").classList.toggle("open");
      if (ev.target.closest("[data-info-close]")) return $("chat-user-details").classList.remove("open");
      if (ev.target.closest("[data-focus-reply]")) {
        $("chat-user-details").classList.remove("open");
        return $("miReplyBody")?.focus();
      }
      if (ev.target.closest("[data-copy-2]")) {
        const m = inbox?.items.find((x) => x.id === state.open);
        if (m) copy(m.body);
      }
    });
    let t = null;
    $("miSearch").addEventListener("input", (e) => {
      clearTimeout(t);
      t = setTimeout(() => {
        state.q = e.target.value.trim();
        renderList();
      }, 200);
    });
    load();
  });
})();
