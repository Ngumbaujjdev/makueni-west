/**
 * ============================================================================
 * MESSAGES - Chat (docs/specs/messages-spec.md, L6), in chat.html's layout
 * ============================================================================
 * Left: Recent (one-to-one chats and groups - ACTIVE CHATS, ALL CHATS - and
 * the ANNOUNCEMENTS sent down to us), Groups (+ New group) and Contacts (the
 * template's Calls tab: every leader in the diocese). Middle: the chat -
 * online or typing in the header, day labels, ticks, Enter to send. Right:
 * the person, or the group and its members.
 * Live through Reverb (assets/js/utils/realtime.js); when that's down, the
 * open chat checks every 5 seconds and the list every 15.
 * ?chat=ID opens a chat, ?open=ID an announcement (the bell links there).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const API = MessagesAPI;
  const CTX = window.MESSAGES_CTX;
  const RT = window.Realtime || null;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);

  const state = {
    tab: "recent",
    q: "",
    open: params.get("chat") ? { kind: "chat", id: Number(params.get("chat")) } : params.get("open") ? { kind: "ann", id: Number(params.get("open")) } : null,
  };
  let chats = [];
  let inbox = { items: [], unread: 0, places: {} };
  let contacts = null;
  let templates = [];
  let current = null; // the open chat: {chat, details, messages, readUpto, more}
  const typing = new Map(); // chat id -> {name, until}
  let pollTimer = null;
  let listTimer = null;
  let lightbox = null;

  const LEVEL = { diocese: "Diocese", region: "Region", church: "Church" };
  const EMOJI = ["🙏", "🙌", "👍", "❤️", "😊", "😂", "🎉", "✝️", "📖", "⛪", "🕊️", "🔥", "👏", "💐", "📅", "✅"];
  const ME = (() => {
    try {
      return JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.USER_DATA) || "null");
    } catch (e) {
      return null;
    }
  })();
  const myId = ME?.id;
  const myName = () => ME?.full_name || [ME?.firstname, ME?.lastname].filter(Boolean).join(" ") || "You";
  const initials = (name) => (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join("");
  const isPhone = () => window.matchMedia("(max-width: 991.98px)").matches;
  const esc = M.esc;

  // ---------------------------------------------------------------- words and times
  const paragraphs = (s) =>
    String(s || "")
      .trim()
      .split(/\n\s*\n/)
      .map((p) => `<p>${esc(p).replace(/\n/g, "<br>")}</p>`)
      .join("") || "<p>-</p>";
  const dayKey = (iso) => new Date(iso).toDateString();
  function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const y = new Date(today);
    y.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return "Today";
    if (d.toDateString() === y.toDateString()) return "Yesterday";
    return d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", year: d.getFullYear() === today.getFullYear() ? undefined : "numeric" });
  }
  const clock = (iso) => (iso ? new Date(iso).toLocaleTimeString("en-GB", { hour: "numeric", minute: "2-digit" }) : "");
  const listTime = (iso) => (!iso ? "" : dayKey(iso) === new Date().toDateString() ? clock(iso) : new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" }));

  // ---------------------------------------------------------------- faces
  const online = (userId) => !!(RT && RT.isOnline(userId));
  const dot = (userId) => (userId ? (online(userId) ? " online" : " offline") : "");
  function face(person, size = "md", withDot = true) {
    const cls = `avatar avatar-${size}${withDot ? dot(person?.id) : ""} avatar-rounded`;
    if (person?.photo_url) return `<span class="${cls} mi-av"><img src="${esc(person.photo_url)}" alt=""></span>`;
    return `<span class="${cls} bg-${UI.colorFor(person?.name || "?")} text-white fw-semibold">${esc(initials(person?.name))}</span>`;
  }
  function chatFace(c, size = "md") {
    if (c.type === "group") return c.photo_url ? `<span class="avatar avatar-${size} avatar-rounded mi-av"><img src="${esc(c.photo_url)}" alt=""></span>` : `<span class="avatar avatar-${size} avatar-rounded bg-purple text-white"><i class="ri-group-2-line"></i></span>`;
    return face({ id: c.with?.id, name: c.name, photo_url: c.photo_url }, size);
  }
  function annFace(m, size = "md") {
    if (m.by_photo_url) return `<span class="avatar avatar-${size} avatar-rounded mi-av"><img src="${esc(m.by_photo_url)}" alt=""></span>`;
    const logo = inbox.places?.[m.from.id]?.logo_url;
    if (logo) return `<span class="avatar avatar-${size} avatar-rounded mi-av is-logo"><img src="${esc(logo)}" alt=""></span>`;
    const f = M.FROM[m.from.type] || M.FROM.church;
    return `<span class="avatar avatar-${size} avatar-rounded bg-${f.color} text-white"><i class="${f.icon}"></i></span>`;
  }
  const myFace = (size = "md") => face({ id: myId, name: myName(), photo_url: ME?.photo_url }, size, false);

  function syncUrl() {
    const q = new URLSearchParams(window.location.search);
    q.delete("chat");
    q.delete("open");
    if (state.open) q.set(state.open.kind === "chat" ? "chat" : "open", state.open.id);
    history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
  }

  // ================================================================ load
  async function load() {
    $("chat-msg-scroll").innerHTML = Array.from({ length: 6 }, () => `<li class="mi-chat-skel"><span class="skel skel-tile"></span><div class="flex-fill"><span class="skel skel-line" style="width:60%"></span><span class="skel skel-line skel-line-sm mt-2" style="width:85%"></span></div></li>`).join("");
    const [c, a, t] = await Promise.all([API.chats(), API.inbox(""), CTX.can.send ? API.templates() : null]);
    if (!c.ok && !a.ok) {
      $("chat-msg-scroll").innerHTML = `<li class="p-3">${M.empty("ri-error-warning-line", "Couldn't load your messages", esc(c.message || ""), "danger")}</li>`;
      $("miMain").innerHTML = "";
      return;
    }
    chats = c.ok ? c.data.items : [];
    if (a.ok) inbox = a.data;
    templates = t?.ok ? t.data : [];
    renderAll();
    if (state.open) return state.open.kind === "chat" ? openChat(state.open.id, { slide: true }) : openAnnouncement(state.open.id, { slide: true });
    const first = chats[0];
    if (first && !isPhone()) openChat(first.id, { slide: false });
    else if (!isPhone() && inbox.items[0]) openAnnouncement(inbox.items[0].id, { slide: false });
    else blank();
  }

  async function refreshChats() {
    const res = await API.chats();
    if (!res.ok) return;
    chats = res.data.items;
    renderAll();
    if (current) {
      const s = chats.find((x) => x.id === current.chat.id);
      if (s) {
        current.chat = s;
        if (s.read_upto > current.readUpto) {
          current.readUpto = s.read_upto;
          paintTicks();
        }
        renderHeader();
      }
    }
  }

  function renderAll() {
    const unread = chats.reduce((n, c) => n + c.unread, 0) + (inbox.unread || 0);
    $("miUnread").hidden = !unread;
    $("miUnread").textContent = unread;
    renderRecent();
    renderGroups();
    if (contacts) renderContacts();
  }

  // ================================================================ the list: Recent
  const match = (...parts) => !state.q || parts.join(" ").toLowerCase().includes(state.q.toLowerCase());
  const label = (text, n) => `<li class="pb-0"><p class="text-muted fs-11 fw-semibold mb-2 op-7">${text}${n !== undefined ? `<span class="mi-chat-count">${n}</span>` : ""}</p></li>`;

  function chatRow(c) {
    const t = typing.get(c.id);
    const isTyping = t && t.until > Date.now();
    const last = c.last_message;
    const text = isTyping ? `${c.type === "group" ? `${t.name.split(" ")[0]} is ` : ""}Typing...` : !last ? (c.type === "group" ? "Group created" : "Say hello") : last.kind === "system" ? last.body : `${c.type === "group" && !last.mine ? `${last.who}: ` : last.mine ? "You: " : ""}${last.body}`;
    const active = state.open?.kind === "chat" && state.open.id === c.id;
    const tick = last?.mine && last.kind === "text" ? `<span class="chat-read-icon float-end align-middle${c.read_upto >= last.id ? " is-read" : ""}"><i class="${c.read_upto >= last.id ? "ri-check-double-fill" : "ri-check-line"}"></i></span>` : "";
    return `<li class="checkforactive${c.unread ? " chat-msg-unread" : ""}${active ? " active" : ""}" data-chat="${c.id}">
      <a href="javascript:void(0);">
        <div class="d-flex align-items-top">
          <div class="me-1 lh-1">${chatFace(c)}</div>
          <div class="flex-fill min-w-0">
            <p class="mb-0 fw-semibold text-truncate"><span class="float-end text-muted fw-normal fs-11 ms-2">${listTime(c.last_message_at)}</span>${c.type === "group" ? '<i class="ri-group-2-line me-1 mi-chat-groupmark"></i>' : ""}${esc(c.name)}</p>
            <p class="fs-12 mb-0${isTyping ? " chat-msg-typing" : ""}">
              <span class="chat-msg text-truncate">${esc(text)}</span>
              ${c.unread ? `<span class="badge bg-primary rounded-pill float-end">${c.unread}</span>` : tick}
            </p>
          </div>
        </div>
      </a>
    </li>`;
  }

  function annRow(m) {
    const active = state.open?.kind === "ann" && state.open.id === m.id;
    return `<li class="checkforactive${m.read_at ? "" : " chat-msg-unread"}${active ? " active" : ""}" data-ann="${m.id}">
      <a href="javascript:void(0);">
        <div class="d-flex align-items-top">
          <div class="me-1 lh-1">${annFace(m)}</div>
          <div class="flex-fill min-w-0">
            <p class="mb-0 fw-semibold text-truncate"><span class="float-end text-muted fw-normal fs-11 ms-2">${listTime(m.at)}</span>${esc(m.from.name)}</p>
            <p class="fs-12 mb-0"><span class="chat-msg text-truncate">${esc(m.subject || m.body || "")}</span>${m.read_at ? "" : '<span class="badge bg-primary rounded-pill float-end">1</span>'}</p>
          </div>
        </div>
      </a>
    </li>`;
  }

  function contactRow(p, withChat = true) {
    return `<li data-person="${p.id}">
      <div class="d-flex align-items-center">
        <div class="me-1 lh-1">${face(p)}</div>
        <div class="flex-fill my-auto min-w-0">
          <p class="mb-0 fw-semibold text-truncate">${esc(p.name)}</p>
          <p class="fs-12 mb-0 text-truncate"><span class="text-muted">${esc([p.role, p.place].filter(Boolean).join(" · "))}</span></p>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          ${p.phone ? `<a class="btn btn-sm btn-icon btn-light" href="tel:${esc(p.phone.replace(/\s+/g, ""))}" title="Call ${esc(p.name)}" aria-label="Call ${esc(p.name)}"><i class="ri-phone-line"></i></a>` : ""}
          ${withChat ? `<button type="button" class="btn btn-sm btn-icon btn-primary-light" data-start="${p.id}" title="Chat with ${esc(p.name)}" aria-label="Chat with ${esc(p.name)}"><i class="ri-chat-3-line"></i></button>` : ""}
        </div>
      </div>
    </li>`;
  }

  function renderRecent() {
    const list = chats.filter((c) => match(c.name, c.last_message?.body));
    const active = list.filter((c) => c.unread);
    const rest = list.filter((c) => !c.unread);
    const anns = (inbox.items || []).filter((m) => match(m.subject, m.body, m.from.name, m.by));
    const people = state.q && contacts ? contacts.filter((p) => match(p.name, p.place, p.role) && !chats.some((c) => c.type === "direct" && c.with?.id === p.id)).slice(0, 8) : [];
    const html = [
      active.length ? label("ACTIVE CHATS", active.length) + active.map(chatRow).join("") : "",
      rest.length ? label(active.length ? "ALL CHATS" : "CHATS", rest.length) + rest.map(chatRow).join("") : "",
      anns.length ? label("ANNOUNCEMENTS", anns.length) + anns.map(annRow).join("") : "",
      people.length ? label("PEOPLE") + people.map((p) => contactRow(p)).join("") : "",
    ].join("");
    $("chat-msg-scroll").innerHTML =
      html ||
      `<li class="p-3">${state.q ? M.empty("ri-search-line", "Nothing matches", "Try another name, or look in Contacts.") : M.empty("ri-chat-3-line", "No chats yet", "Open Contacts and pick someone to talk to, or start a group.")}${state.q ? "" : '<button type="button" class="btn btn-primary mt-2" data-go-contacts><i class="ri-contacts-book-line me-1"></i>Open Contacts</button>'}</li>`;
  }

  // ================================================================ Groups
  function renderGroups() {
    const groups = chats.filter((c) => c.type === "group" && match(c.name));
    const row = (c) => {
      const faces = (c.member_faces || []).map((p) => (p.photo_url ? `<span class="avatar avatar-sm avatar-rounded"><img src="${esc(p.photo_url)}" alt=""></span>` : `<span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(p.name || "?")} text-white">${esc(initials(p.name))}</span>`)).join("");
      const on = (c.member_faces || []).filter((p) => p.id !== myId && online(p.id)).length;
      return `<li class="checkforactive${state.open?.kind === "chat" && state.open.id === c.id ? " active" : ""}" data-chat="${c.id}">
        <a href="javascript:void(0);">
          <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="min-w-0">
              <p class="mb-0 fw-semibold text-truncate">${esc(c.name)}${c.unread ? `<span class="badge bg-primary rounded-pill ms-2">${c.unread}</span>` : ""}</p>
              <p class="mb-0"><span class="badge bg-success">${on} online</span><span class="fs-11 ms-2 text-muted">${c.members} ${c.members === 1 ? "member" : "members"}${c.left ? " · You left" : ""}</span></p>
            </div>
            <div class="avatar-list-stacked my-auto flex-shrink-0">${faces}${c.members > 4 ? `<span class="avatar avatar-sm bg-primary text-fixed-white avatar-rounded">+${c.members - 4}</span>` : ""}</div>
          </div>
        </a>
      </li>`;
    };
    $("miGroups").innerHTML = `<li class="px-3 pb-2"><button type="button" class="btn btn-primary w-100" data-new-group><i class="ri-group-2-line me-1"></i>New group</button></li>${
      groups.length ? label("MY CHAT GROUPS", groups.length) + groups.map(row).join("") : `<li class="p-3">${M.empty("ri-group-2-line", "No groups yet", "Start one for your leaders, a committee or an event team.")}</li>`
    }`;
  }

  // ================================================================ Contacts
  async function loadContacts() {
    if (contacts) return contacts;
    const res = await API.contacts();
    contacts = res.ok ? res.data : [];
    return contacts;
  }

  async function renderContacts() {
    if (!contacts) {
      $("miContacts").innerHTML = `<li class="p-3"><span class="spinner-border spinner-border-sm text-primary"></span></li>`;
      await loadContacts();
    }
    const list = contacts.filter((p) => match(p.name, p.place, p.role));
    let letter = "";
    $("miContacts").innerHTML = list.length
      ? list
          .map((p) => {
            const first = (p.name || "?")[0].toUpperCase();
            const head = first !== letter ? label((letter = first)) : "";
            return head + contactRow(p);
          })
          .join("")
      : `<li class="p-3">${M.empty("ri-contacts-book-line", "Nobody matches", "Try a name, a church or a role.")}</li>`;
  }

  async function startWith(userId) {
    const res = await API.direct(userId);
    if (!res.ok) return Toast.error(res.message);
    if (!chats.some((c) => c.id === res.data.id)) chats.unshift(res.data);
    showTab("recent");
    renderAll();
    openChat(res.data.id);
  }

  function showTab(tab) {
    state.tab = tab;
    const btn = document.querySelector(`#myTab1 [data-tab="${tab}"]`);
    if (btn && !btn.classList.contains("active")) bootstrap.Tab.getOrCreateInstance(btn).show();
  }

  // ================================================================ the chat
  function blank() {
    leaveCurrent();
    state.open = null;
    syncUrl();
    $("miChat").classList.remove("responsive-chat-open");
    $("miMain").innerHTML = `<div class="mi-chat-empty">${M.empty("ri-chat-3-line", "Pick a chat", "Choose one on the left, or open Contacts to start one.")}</div>`;
    $("chat-user-details").innerHTML = "";
  }

  function leaveCurrent() {
    if (current && RT) RT.leaveChat(current.chat.id);
    current = null;
    clearInterval(pollTimer);
  }

  function markActive() {
    document.querySelectorAll("#miChat [data-chat], #miChat [data-ann]").forEach((li) => {
      const on = (state.open?.kind === "chat" && Number(li.dataset.chat) === state.open.id) || (state.open?.kind === "ann" && Number(li.dataset.ann) === state.open.id);
      li.classList.toggle("active", on);
    });
  }

  async function openChat(id, { slide = true } = {}) {
    leaveCurrent();
    state.open = { kind: "chat", id };
    syncUrl();
    markActive();
    if (slide && isPhone()) $("miChat").classList.add("responsive-chat-open");
    $("miMain").innerHTML = `<div class="mi-chat-empty"><span class="spinner-border text-primary" role="status"></span></div>`;
    const [d, m] = await Promise.all([API.chat(id), API.chatMessages(id)]);
    if (state.open?.id !== id) return;
    if (!d.ok || !m.ok) {
      $("miMain").innerHTML = `<div class="mi-chat-empty">${M.empty("ri-error-warning-line", "Couldn't open this chat", esc((d.ok ? m : d).message || ""), "danger")}</div>`;
      return;
    }
    current = { chat: chats.find((c) => c.id === id) || d.data, details: d.data, messages: m.data.items, readUpto: m.data.read_upto, more: m.data.more };
    renderChat();
    renderDetails();
    markRead();
    listen(id);
    pollTimer = setInterval(() => !RT?.live && poll(id), 5000);
  }

  function renderChat() {
    const c = current.chat;
    const left = !!current.details.left;
    $("miMain").innerHTML = `
      <div class="d-flex align-items-center p-2 border-bottom" id="miHead"></div>
      <div class="chat-content mi-chat-content" id="main-chat-content">
        ${current.more ? '<div class="text-center mb-3"><button type="button" class="btn btn-sm btn-light" data-older><i class="ri-arrow-up-line me-1"></i>Earlier messages</button></div>' : ""}
        <ul class="list-unstyled" id="miLines">${lines(current.messages)}</ul>
      </div>
      ${
        left
          ? `<div class="chat-footer mi-chat-footer justify-content-center"><span class="fs-13 fw-semibold">You're no longer in this group.</span></div>`
          : `<div class="chat-footer mi-chat-footer">
              <div class="mi-chat-emoji" id="miEmoji" hidden>${EMOJI.map((e) => `<button type="button" data-emoji="${e}">${e}</button>`).join("")}</div>
              <textarea class="form-control mi-chat-input" id="miInput" rows="1" maxlength="4000" placeholder="Type your message here..." aria-label="Your message"></textarea>
              <a aria-label="Emoji" class="btn btn-icon mx-2 btn-success-light" href="javascript:void(0);" data-emoji-toggle><i class="ri-emotion-line"></i></a>
              <a aria-label="Send" class="btn btn-primary btn-icon btn-send" href="javascript:void(0);" id="miSend"><i class="ri-send-plane-2-line"></i></a>
            </div>`
      }`;
    renderHeader();
    scrollDown();
    if (left) return;
    const input = $("miInput");
    const grow = () => {
      input.style.height = "auto";
      input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
    };
    let lastWhisper = 0;
    input.addEventListener("input", () => {
      grow();
      if (RT?.live && Date.now() - lastWhisper > 2500) {
        lastWhisper = Date.now();
        RT.whisperTyping(c.id, { id: myId, name: myName() });
      }
    });
    // Enter sends; Shift+Enter is a new line - as on WhatsApp.
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        send();
      }
    });
    $("miSend").addEventListener("click", send);
    if (!isPhone()) input.focus();
  }

  function renderHeader() {
    const head = $("miHead");
    if (!head || !current) return;
    const c = current.chat;
    const d = current.details;
    const t = typing.get(c.id);
    const isTyping = t && t.until > Date.now();
    const status = isTyping ? (c.type === "group" ? `${t.name.split(" ")[0]} is typing...` : "typing...") : c.type === "group" ? `${d.people?.length || c.members} members${(d.people || []).filter((p) => p.id !== myId && online(p.id)).length ? ` · ${(d.people || []).filter((p) => p.id !== myId && online(p.id)).length} online` : ""}` : online(c.with?.id) ? "online" : d.person?.role ? `${d.person.role}${d.person.place ? ` · ${d.person.place}` : ""}` : "offline";
    const phone = c.type === "direct" ? d.person?.phone : null;
    head.innerHTML = `
      <button type="button" class="btn btn-icon btn-light responsive-chat-close me-2" data-back aria-label="Back to the list"><i class="ri-arrow-left-line"></i></button>
      <div class="me-2 lh-1">${chatFace(c, "lg")}</div>
      <div class="flex-fill min-w-0">
        <p class="mb-0 fw-semibold fs-14 text-truncate"><a href="javascript:void(0);" class="chatnameperson" data-info>${esc(c.name)}</a></p>
        <p class="mb-0 chatpersonstatus text-truncate${isTyping ? " is-typing" : online(c.with?.id) ? " is-online" : ""}">${esc(status)}</p>
      </div>
      <div class="d-flex flex-nowrap rightIcons">
        ${phone ? `<a aria-label="Call" title="Call" class="btn btn-icon btn-outline-light my-1 ms-2" href="tel:${esc(phone.replace(/\s+/g, ""))}"><i class="ri-phone-line"></i></a>` : ""}
        <button aria-label="Details" title="Details" type="button" class="btn btn-icon btn-outline-light my-1 ms-2" data-info><i class="ri-user-3-line"></i></button>
        <div class="dropdown ms-2">
          <button aria-label="More" class="btn btn-icon btn-outline-light my-1 btn-wave waves-light" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-more-2-fill"></i></button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="javascript:void(0);" data-info><i class="ri-information-line me-2"></i>${c.type === "group" ? "Group details" : "Contact details"}</a></li>
            ${c.type === "group" && current.details.is_admin ? '<li><a class="dropdown-item" href="javascript:void(0);" data-add-people><i class="ri-user-add-line me-2"></i>Add people</a></li>' : ""}
            ${c.type === "group" && !current.details.left ? '<li><a class="dropdown-item text-danger" href="javascript:void(0);" data-leave><i class="ri-logout-box-r-line me-2"></i>Leave group</a></li>' : ""}
          </ul>
        </div>
      </div>`;
  }

  /** The lines, with day labels; system lines as small centred notes. */
  function lines(list) {
    let day = null;
    return list
      .map((m) => {
        let out = "";
        if (dayKey(m.at) !== day) {
          day = dayKey(m.at);
          out += `<li class="chat-day-label"><span>${dayLabel(m.at)}</span></li>`;
        }
        return out + line(m);
      })
      .join("");
  }

  function line(m) {
    if (m.kind === "system") return `<li class="mi-chat-system" data-line="${m.id}"><span>${esc(m.body)}</span></li>`;
    const mine = m.user?.id === myId;
    const group = current?.chat.type === "group";
    if (mine) {
      const read = m.id <= current.readUpto;
      return `<li class="chat-item-end" data-line="${m.id}">
        <div class="chat-list-inner">
          <div class="me-3">
            <span class="chatting-user-info"><span class="msg-sent-time"><span class="chat-read-mark${read ? " is-read" : ""}" data-tick><i class="${read ? "ri-check-double-fill" : "ri-check-line"}"></i></span>${clock(m.at)}</span>You</span>
            <div class="main-chat-msg"><div>${paragraphs(m.body)}</div></div>
          </div>
          <div class="chat-user-profile">${myFace("md")}</div>
        </div>
      </li>`;
    }
    return `<li class="chat-item-start" data-line="${m.id}">
      <div class="chat-list-inner">
        <div class="chat-user-profile">${face(m.user, "md", group)}</div>
        <div class="ms-3">
          <span class="chatting-user-info">${esc(m.user?.name || "Someone")}<span class="msg-sent-time">${clock(m.at)}</span></span>
          <div class="main-chat-msg"><div>${paragraphs(m.body)}</div></div>
        </div>
      </div>
    </li>`;
  }

  function scrollDown() {
    const box = $("main-chat-content");
    if (box) box.scrollTop = box.scrollHeight;
  }

  function paintTicks() {
    document.querySelectorAll("#miLines .chat-item-end").forEach((li) => {
      const read = Number(li.dataset.line) <= current.readUpto;
      const t = li.querySelector("[data-tick]");
      if (!t) return;
      t.classList.toggle("is-read", read);
      t.innerHTML = `<i class="${read ? "ri-check-double-fill" : "ri-check-line"}"></i>`;
    });
  }

  /** A new line for the open chat (live, from a check, or just sent). */
  function addLine(l) {
    if (!current || current.messages.some((m) => m.id === l.id)) return false;
    const last = current.messages[current.messages.length - 1];
    current.messages.push(l);
    const box = $("main-chat-content");
    const nearBottom = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 160;
    const list = $("miLines");
    if (list) list.insertAdjacentHTML("beforeend", (last && dayKey(last.at) === dayKey(l.at) ? "" : `<li class="chat-day-label"><span>${dayLabel(l.at)}</span></li>`) + line(l));
    if (nearBottom || l.user?.id === myId) scrollDown();
    return true;
  }

  /** Live events for the open chat (once the live connection is up). */
  function listen(id) {
    if (!RT || !RT.live || !current || current.chat.id !== id || current.listening) return;
    current.listening = true;
    RT.chat(id, {
      message: (l) => onLine(id, l),
      read: (e) => onRead(id, e),
      typing: (who) => onTyping(id, who),
    });
  }

  function onLine(chatId, l) {
    if (!current || current.chat.id !== chatId) return;
    typing.delete(chatId);
    if (addLine(l)) {
      renderHeader();
      if (l.kind === "system") refreshDetails();
      if (l.user?.id !== myId) markRead();
    }
  }

  function onRead(chatId, e) {
    if (!current || current.chat.id !== chatId || e.user_id === myId) return;
    // One-to-one: their read is my tick. A group: everyone must have read - ask the list.
    if (current.chat.type === "direct") {
      current.readUpto = Math.max(current.readUpto, e.message_id);
      paintTicks();
    } else refreshChats();
  }

  function onTyping(chatId, who) {
    typing.set(chatId, { name: who.name || "Someone", until: Date.now() + 3500 });
    renderHeader();
    renderRecent();
    setTimeout(() => {
      if ((typing.get(chatId)?.until || 0) <= Date.now()) {
        typing.delete(chatId);
        renderHeader();
        renderRecent();
      }
    }, 3600);
  }

  async function poll(id) {
    if (!current || current.chat.id !== id || document.hidden) return;
    const res = await API.chatMessages(id);
    if (!res.ok || !current || current.chat.id !== id) return;
    let got = false;
    res.data.items.forEach((l) => (got = addLine(l) || got));
    if (res.data.read_upto > current.readUpto) {
      current.readUpto = res.data.read_upto;
      paintTicks();
    }
    if (got && res.data.items.some((l) => l.user?.id !== myId)) markRead();
  }

  async function send() {
    const input = $("miInput");
    const body = input.value.trim();
    if (!body || !current) return;
    input.value = "";
    input.style.height = "auto";
    $("miEmoji").hidden = true;
    const res = await API.chatSend(current.chat.id, body);
    if (!res.ok) {
      input.value = body;
      return Toast.error(res.message);
    }
    addLine(res.data);
    const c = chats.find((x) => x.id === current.chat.id);
    if (c) {
      c.last_message = { id: res.data.id, body: res.data.body, kind: "text", mine: true, who: ME?.firstname, at: res.data.at };
      c.last_message_at = res.data.at;
      chats.sort((a, b) => String(b.last_message_at || "").localeCompare(String(a.last_message_at || "")));
      renderRecent();
    }
  }

  async function markRead() {
    if (!current || document.hidden) return;
    const last = [...current.messages].reverse().find((m) => m.user?.id !== myId);
    if (!last) return;
    const c = chats.find((x) => x.id === current.chat.id);
    if (c && !c.unread && c._readTo >= last.id) return;
    await API.chatRead(current.chat.id, last.id);
    if (c) {
      c.unread = 0;
      c._readTo = last.id;
    }
    renderAll();
  }

  async function olderLines() {
    const first = current.messages[0];
    const res = await API.chatMessages(current.chat.id, first?.id);
    if (!res.ok) return Toast.error(res.message);
    const box = $("main-chat-content");
    const from = box.scrollHeight;
    current.messages = [...res.data.items, ...current.messages];
    current.more = res.data.more;
    renderChat();
    box.scrollTop = $("main-chat-content").scrollHeight - from;
  }

  // ================================================================ details
  async function refreshDetails() {
    if (!current) return;
    const res = await API.chat(current.chat.id);
    if (res.ok && current) {
      current.details = res.data;
      renderHeader();
      renderDetails();
    }
  }

  function renderDetails() {
    if (!current) return;
    const c = current.chat;
    const d = current.details;
    const box = $("chat-user-details");
    if (c.type === "direct") {
      const p = d.person || { name: c.name };
      box.innerHTML = `
        <div class="d-flex mb-0"><div class="ms-auto"><button aria-label="Close" type="button" class="btn btn-icon btn-outline-light responsive-chat-close2 mi-chat-close" data-info-close><i class="ri-close-line"></i></button></div></div>
        <div class="text-center mb-5">
          <span class="d-inline-block mb-3 mi-chat-bigav">${face(p, "xxl")}</span>
          <p class="mb-1 fs-15 fw-semibold lh-1 chatnameperson">${esc(p.name)}</p>
          <p class="fs-12 mb-2">${esc(p.email || "")}</p>
          <p class="text-center mb-0">
            ${p.phone ? `<a href="tel:${esc(p.phone.replace(/\s+/g, ""))}" class="btn btn-icon rounded-pill btn-primary-light" aria-label="Call"><i class="ri-phone-line"></i></a>` : ""}
            <button type="button" class="btn btn-icon rounded-pill btn-primary-light ms-2" data-focus aria-label="Write"><i class="ri-chat-3-line"></i></button>
            ${p.email ? `<a href="mailto:${esc(p.email)}" class="btn btn-icon rounded-pill btn-primary-light ms-2" aria-label="Email"><i class="ri-mail-line"></i></a>` : ""}
          </p>
        </div>
        <div class="mb-5">
          <div class="fw-semibold mb-3">Their place</div>
          <ul class="shared-files list-unstyled mb-0">
            ${p.role ? `<li><div class="d-flex align-items-center"><div class="me-2"><span class="shared-file-icon"><i class="ri-user-star-line"></i></span></div><div class="flex-fill"><p class="fs-13 fw-semibold mb-0">${esc(p.role)}</p><p class="mb-0 text-muted fs-12">Their role</p></div></div></li>` : ""}
            ${p.place ? `<li><div class="d-flex align-items-center"><div class="me-2"><span class="shared-file-icon"><i class="ri-community-line"></i></span></div><div class="flex-fill"><p class="fs-13 fw-semibold mb-0">${esc(p.place)}</p><p class="mb-0 text-muted fs-12">${LEVEL[p.level] || ""}</p></div></div></li>` : ""}
            ${p.phone ? `<li><div class="d-flex align-items-center"><div class="me-2"><span class="shared-file-icon"><i class="ri-phone-line"></i></span></div><div class="flex-fill"><p class="fs-13 fw-semibold mb-0">${esc(p.phone)}</p><p class="mb-0 text-muted fs-12">Phone</p></div></div></li>` : ""}
          </ul>
        </div>
        <div class="mb-0" id="miPhotos"></div>`;
      if (p.place_id) loadPhotos(p.place_id, p.place);
    } else {
      const people = d.people || [];
      box.innerHTML = `
        <div class="d-flex mb-0"><div class="ms-auto"><button aria-label="Close" type="button" class="btn btn-icon btn-outline-light responsive-chat-close2 mi-chat-close" data-info-close><i class="ri-close-line"></i></button></div></div>
        <div class="text-center mb-4">
          <span class="d-inline-block mb-3 mi-chat-bigav position-relative">${chatFace(c, "xxl")}${d.is_admin ? '<button type="button" class="mi-chat-photo-btn" data-group-photo title="Change the photo" aria-label="Change the group photo"><i class="ri-camera-line"></i></button><input type="file" id="miGroupPhoto" accept="image/png,image/jpeg,image/webp" hidden>' : ""}</span>
          <p class="mb-1 fs-15 fw-semibold lh-1 chatnameperson">${esc(c.name)}${d.is_admin ? ' <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-rename aria-label="Rename"><i class="ri-edit-line"></i></button>' : ""}</p>
          <p class="fs-12 mb-2">${people.length} ${people.length === 1 ? "member" : "members"}</p>
          <p class="text-center mb-0">
            <button type="button" class="btn btn-icon rounded-pill btn-primary-light" data-focus aria-label="Write"><i class="ri-chat-3-line"></i></button>
            ${d.is_admin ? '<button type="button" class="btn btn-icon rounded-pill btn-primary-light ms-2" data-add-people aria-label="Add people"><i class="ri-user-add-line"></i></button>' : ""}
            ${d.left ? "" : '<button type="button" class="btn btn-icon rounded-pill btn-danger-light ms-2" data-leave aria-label="Leave the group"><i class="ri-logout-box-r-line"></i></button>'}
          </p>
        </div>
        <div class="mb-0">
          <div class="d-flex align-items-center justify-content-between mb-3"><div class="fw-semibold">Members<span class="mi-chat-count">${people.length}</span></div>${d.is_admin ? '<a href="javascript:void(0);" class="fs-12 text-primary fw-semibold" data-add-people>+ Add people</a>' : ""}</div>
          <ul class="shared-files list-unstyled mb-0 mi-chat-members">
            ${people
              .map(
                (p) => `<li><div class="d-flex align-items-center gap-2">
                  ${face(p, "sm")}
                  <div class="flex-fill min-w-0"><p class="fs-13 fw-semibold mb-0 text-truncate">${esc(p.is_me ? "You" : p.name)}${p.is_admin ? ' <span class="badge bg-primary ms-1">Admin</span>' : ""}</p><p class="mb-0 text-muted fs-12 text-truncate">${esc([p.role, p.place].filter(Boolean).join(" · "))}</p></div>
                  ${d.is_admin && !p.is_me ? `<button type="button" class="btn btn-sm btn-icon btn-light" data-remove="${p.id}" title="Remove ${esc(p.name)}" aria-label="Remove ${esc(p.name)}"><i class="ri-close-line"></i></button>` : !p.is_me ? `<button type="button" class="btn btn-sm btn-icon btn-primary-light" data-start="${p.id}" aria-label="Chat with ${esc(p.name)}"><i class="ri-chat-3-line"></i></button>` : ""}
                </div></li>`,
              )
              .join("")}
          </ul>
        </div>`;
    }
  }

  async function loadPhotos(placeId, placeName) {
    const box = $("miPhotos");
    if (!box) return;
    const res = await fetch(`${AppConfig.API_BASE_URL}/places/${placeId}/gallery`, { headers: { Accept: "application/json" } })
      .then((r) => r.json())
      .catch(() => null);
    const photos = (res?.data?.photos || []).slice(0, 6);
    if (!$("miPhotos")) return;
    box.innerHTML = `<div class="d-flex align-items-center justify-content-between mb-3"><div class="fw-semibold">Photos & Media<span class="mi-chat-count is-purple">${photos.length}</span></div></div>
      ${photos.length ? `<div class="row g-2">${photos.map((ph) => `<div class="col-4"><a class="chat-media mi-chat-photo" href="${esc(ph.url)}" ${ph.caption ? `data-title="${esc(ph.caption)}"` : ""}><img src="${esc(ph.thumb_url)}" alt="${esc(ph.caption || "")}" loading="lazy"></a></div>`).join("")}</div>` : `<p class="fs-12 mb-0">${esc(placeName || "Their church")} hasn't added photos yet.</p>`}`;
    if (window.GLightbox) {
      lightbox?.destroy();
      lightbox = photos.length ? GLightbox({ selector: "#chat-user-details .mi-chat-photo" }) : null;
    }
  }

  // ================================================================ announcements (one-way, with replies)
  async function openAnnouncement(id, { slide = true } = {}) {
    leaveCurrent();
    const m = (inbox.items || []).find((x) => x.id === id);
    if (!m) return blank();
    state.open = { kind: "ann", id };
    syncUrl();
    markActive();
    if (slide && isPhone()) $("miChat").classList.add("responsive-chat-open");
    const f = M.FROM[m.from.type] || M.FROM.church;
    const mineLines = m.my_replies.map((r) => ({ id: `r${r.id}`, body: r.body, at: r.at }));
    let day = dayKey(m.at);
    $("miMain").innerHTML = `
      <div class="d-flex align-items-center p-2 border-bottom">
        <button type="button" class="btn btn-icon btn-light responsive-chat-close me-2" data-back aria-label="Back to the list"><i class="ri-arrow-left-line"></i></button>
        <div class="me-2 lh-1">${annFace(m, "lg")}</div>
        <div class="flex-fill min-w-0">
          <p class="mb-0 fw-semibold fs-14 text-truncate"><span class="chatnameperson">${esc(m.by || m.from.name)}</span></p>
          <p class="mb-0 chatpersonstatus text-truncate">Announcement · ${esc(m.from.name)}</p>
        </div>
        <div class="d-flex flex-nowrap rightIcons">
          <span class="soft-chip soft-${f.color} my-1 ms-2"><i class="${f.icon}"></i>${LEVEL[m.from.type] || ""}</span>
          ${M.channelChip(m.channel).replace('class="', 'class="my-1 ms-2 ')}
        </div>
      </div>
      <div class="chat-content mi-chat-content" id="main-chat-content">
        <ul class="list-unstyled">
          <li class="chat-day-label"><span>${dayLabel(m.at)}</span></li>
          <li class="chat-item-start">
            <div class="chat-list-inner">
              <div class="chat-user-profile">${annFace(m, "md")}</div>
              <div class="ms-3">
                <span class="chatting-user-info">${esc(m.by || m.from.name)}<span class="msg-sent-time">${clock(m.at)}</span></span>
                <div class="main-chat-msg"><div class="mi-bubble"><p class="mi-bubble-subject">${esc(m.subject || "(No subject)")}</p>${paragraphs(m.body)}<p class="mi-bubble-sign">- ${esc(m.by || m.from.name)}${m.by ? `, ${esc(m.from.name)}` : ""}</p></div></div>
              </div>
            </div>
          </li>
          ${mineLines
            .map((r) => {
              const head = dayKey(r.at) !== day ? `<li class="chat-day-label"><span>${dayLabel(r.at)}</span></li>` : "";
              day = dayKey(r.at);
              return `${head}<li class="chat-item-end"><div class="chat-list-inner"><div class="me-3"><span class="chatting-user-info"><span class="msg-sent-time"><span class="chat-read-mark is-read"><i class="ri-check-double-fill"></i></span>${clock(r.at)}</span>You</span><div class="main-chat-msg"><div>${paragraphs(r.body)}</div></div></div><div class="chat-user-profile">${myFace("md")}</div></div></li>`;
            })
            .join("")}
        </ul>
      </div>
      <div class="chat-footer mi-chat-footer flex-wrap">
        ${templates.length ? `<div class="mi-chat-chips w-100">${templates.slice(0, 4).map((t) => `<button type="button" class="soft-chip soft-purple" data-saved="${t.id}"><i class="ri-bookmark-line"></i>${esc(t.name)}</button>`).join("")}</div>` : ""}
        <div class="d-flex align-items-end w-100">
          <textarea class="form-control mi-chat-input" id="miReplyBody" rows="1" maxlength="2000" placeholder="Reply to ${esc(m.by || m.from.name)}..." aria-label="Your reply"></textarea>
          <a aria-label="Send" class="btn btn-primary btn-icon btn-send ms-2 flex-shrink-0" href="javascript:void(0);" id="miReplyBtn"><i class="ri-send-plane-2-line"></i></a>
        </div>
      </div>`;
    scrollDown();
    $("chat-user-details").innerHTML = "";
    const body = $("miReplyBody");
    body.addEventListener("input", () => {
      body.style.height = "auto";
      body.style.height = `${Math.min(body.scrollHeight, 140)}px`;
    });
    $("miMain").querySelectorAll("[data-saved]").forEach((b) =>
      b.addEventListener("click", () => {
        const t = templates.find((x) => x.id === Number(b.dataset.saved));
        body.value = t.body.replace(/\{name\}/g, m.by || m.from.name).replace(/\{place\}/g, m.from.name).replace(/\{sender\}/g, myName());
        body.focus();
      }),
    );
    const reply = async () => {
      const text = body.value.trim();
      if (!text) return;
      const res = await API.reply(id, text);
      if (!res.ok) return Toast.error(res.message);
      Object.assign(m, res.data);
      openAnnouncement(id, { slide: false });
    };
    $("miReplyBtn").addEventListener("click", reply);
    body.addEventListener("keydown", (e) => e.key === "Enter" && !e.shiftKey && (e.preventDefault(), reply()));
    if (!m.read_at) {
      const res = await API.read(id);
      if (res.ok) {
        m.read_at = res.data.read_at;
        inbox.unread = Math.max(0, inbox.unread - 1);
        renderAll();
      }
    }
  }

  // ================================================================ New group / Add people
  let picker = { mode: "group", picked: new Map() };

  async function openPeople(mode) {
    picker = { mode, picked: new Map() };
    const modal = $("miPeopleModal");
    $("miPeopleTitle").textContent = mode === "group" ? "New group" : "Add people";
    $("miPeopleSub").textContent = mode === "group" ? "Name it, and pick who's in it" : `To ${current?.chat.name || "the group"}`;
    $("miGroupNamePart").hidden = mode !== "group";
    $("miGroupName").value = "";
    $("miPickSearch").value = "";
    $("miPeopleGo").innerHTML = `<i class="ri-check-line me-1"></i>${mode === "group" ? "Create group" : "Add them"}`;
    bootstrap.Modal.getOrCreateInstance(modal).show();
    $("miPickList").innerHTML = `<div class="p-3 text-center"><span class="spinner-border spinner-border-sm text-primary"></span></div>`;
    await loadContacts();
    paintPicker();
  }

  function paintPicker() {
    const q = $("miPickSearch").value.trim().toLowerCase();
    const already = new Set(picker.mode === "add" ? (current?.details.people || []).map((p) => p.id) : []);
    const list = (contacts || []).filter((p) => !already.has(p.id) && (!q || `${p.name} ${p.place} ${p.role}`.toLowerCase().includes(q)));
    $("miPickList").innerHTML = list.length
      ? list
          .map(
            (p) => `<label class="mi-pick-row${picker.picked.has(p.id) ? " is-picked" : ""}">
              <input type="checkbox" class="form-check-input" data-pick="${p.id}"${picker.picked.has(p.id) ? " checked" : ""}>
              ${face(p, "sm")}
              <span class="flex-fill min-w-0"><strong class="d-block text-truncate">${esc(p.name)}</strong><small class="d-block text-truncate">${esc([p.role, p.place].filter(Boolean).join(" · "))}</small></span>
            </label>`,
          )
          .join("")
      : `<div class="p-3 fs-13">Nobody matches.</div>`;
    $("miPicked").innerHTML = [...picker.picked.values()].map((p) => `<span class="pp-chip"><span>${esc(p.name)}</span><button type="button" data-unpick="${p.id}" aria-label="Remove ${esc(p.name)}">&times;</button></span>`).join("");
    $("miPickedCount").textContent = picker.picked.size ? `${picker.picked.size} picked` : "None picked yet";
  }

  async function submitPeople() {
    const ids = [...picker.picked.keys()];
    if (!ids.length) return Toast.warning("Pick at least one person.");
    const btn = $("miPeopleGo");
    UI.setButtonLoading(btn, picker.mode === "group" ? "Creating..." : "Adding...");
    let res;
    if (picker.mode === "group") {
      const name = $("miGroupName").value.trim();
      if (!name) {
        UI.restoreButton(btn);
        $("miGroupName").focus();
        return Toast.warning("Give the group a name.");
      }
      res = await API.createGroup(name, ids);
    } else {
      res = await API.addMembers(current.chat.id, ids);
    }
    UI.restoreButton(btn);
    if (!res.ok) return Toast.error(res.message);
    bootstrap.Modal.getInstance($("miPeopleModal"))?.hide();
    Toast.success(res.message);
    await refreshChats();
    if (picker.mode === "group") {
      showTab("recent");
      openChat(res.data.id);
    } else {
      current.details = res.data;
      renderHeader();
      renderDetails();
    }
  }

  async function leaveGroup() {
    if (!current || current.chat.type !== "group") return;
    if (!window.confirm(`Leave "${current.chat.name}"? You'll keep what was said up to now.`)) return;
    const res = await API.removeMember(current.chat.id, myId);
    if (!res.ok) return Toast.error(res.message);
    Toast.success(res.message);
    const id = current.chat.id;
    await refreshChats();
    openChat(id, { slide: false });
  }

  async function removePerson(userId) {
    const p = (current?.details.people || []).find((x) => x.id === userId);
    if (!p || !window.confirm(`Remove ${p.name} from "${current.chat.name}"?`)) return;
    const res = await API.removeMember(current.chat.id, userId);
    if (!res.ok) return Toast.error(res.message);
    current.details = res.data;
    renderHeader();
    renderDetails();
  }

  async function renameGroup() {
    const name = window.prompt("The group's name", current.chat.name);
    if (!name || name.trim() === current.chat.name) return;
    const res = await API.renameGroup(current.chat.id, name.trim());
    if (!res.ok) return Toast.error(res.message);
    current.details = res.data;
    current.chat.name = res.data.name;
    await refreshChats();
    renderDetails();
  }

  async function changeGroupPhoto(file) {
    const res = await API.groupPhoto(current.chat.id, file);
    if (!res.ok) return Toast.error(res.message);
    current.details = res.data;
    current.chat.photo_url = res.data.photo_url;
    await refreshChats();
    renderHeader();
    renderDetails();
    Toast.success("Group photo saved");
  }

  // ================================================================ live status
  function paintLive(on) {
    $("miLive").classList.toggle("is-live", on);
    $("miLiveText").textContent = on ? "Live" : "Checking every few seconds";
  }

  // ================================================================ start
  document.addEventListener("DOMContentLoaded", async () => {
    const root = $("miChat");
    root.addEventListener("click", (ev) => {
      const t = ev.target;
      const chat = t.closest("[data-chat]");
      if (chat && !t.closest("[data-start]")) {
        $("chat-user-details").classList.remove("open");
        return openChat(Number(chat.dataset.chat));
      }
      const ann = t.closest("[data-ann]");
      if (ann) return openAnnouncement(Number(ann.dataset.ann));
      const start = t.closest("[data-start]");
      if (start) return startWith(Number(start.dataset.start));
      const person = t.closest("[data-person]");
      if (person && !t.closest("a")) return startWith(Number(person.dataset.person));
      if (t.closest("[data-new-group]")) return openPeople("group");
      if (t.closest("[data-add-people]")) return openPeople("add");
      if (t.closest("[data-go-contacts]")) return showTab("contacts");
      if (t.closest("[data-back]")) {
        root.classList.remove("responsive-chat-open");
        leaveCurrent();
        state.open = null;
        return syncUrl();
      }
      if (t.closest("[data-info]")) return $("chat-user-details").classList.toggle("open");
      if (t.closest("[data-info-close]")) return $("chat-user-details").classList.remove("open");
      if (t.closest("[data-focus]")) {
        $("chat-user-details").classList.remove("open");
        return $("miInput")?.focus();
      }
      if (t.closest("[data-leave]")) return leaveGroup();
      const rm = t.closest("[data-remove]");
      if (rm) return removePerson(Number(rm.dataset.remove));
      if (t.closest("[data-rename]")) return renameGroup();
      if (t.closest("[data-group-photo]")) return $("miGroupPhoto")?.click();
      if (t.closest("[data-older]")) return olderLines();
      if (t.closest("[data-emoji-toggle]")) return ($("miEmoji").hidden = !$("miEmoji").hidden);
      const em = t.closest("[data-emoji]");
      if (em) {
        const input = $("miInput");
        const at = input.selectionStart ?? input.value.length;
        input.value = input.value.slice(0, at) + em.dataset.emoji + input.value.slice(at);
        input.focus();
        input.selectionStart = input.selectionEnd = at + em.dataset.emoji.length;
      }
    });
    root.addEventListener("change", (ev) => {
      if (ev.target.id === "miGroupPhoto" && ev.target.files[0]) changeGroupPhoto(ev.target.files[0]);
    });
    document.querySelectorAll("#myTab1 [data-tab]").forEach((b) =>
      b.addEventListener("shown.bs.tab", () => {
        state.tab = b.dataset.tab;
        if (state.tab === "contacts") renderContacts();
      }),
    );
    let st = null;
    $("miSearch").addEventListener("input", (e) => {
      clearTimeout(st);
      st = setTimeout(async () => {
        state.q = e.target.value.trim();
        if (state.q && !contacts) await loadContacts();
        renderAll();
        if (state.tab === "contacts") renderContacts();
      }, 200);
    });
    // The picker window.
    $("miPickSearch").addEventListener("input", paintPicker);
    $("miPickList").addEventListener("change", (ev) => {
      const id = Number(ev.target.dataset.pick);
      if (!id) return;
      const p = contacts.find((x) => x.id === id);
      ev.target.checked ? picker.picked.set(id, p) : picker.picked.delete(id);
      paintPicker();
    });
    $("miPicked").addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-unpick]");
      if (!b) return;
      picker.picked.delete(Number(b.dataset.unpick));
      paintPicker();
    });
    $("miPeopleGo").addEventListener("click", submitPeople);
    document.addEventListener("visibilitychange", () => !document.hidden && current && markRead());

    // The list loads at once; the live connection joins when it's up (or the page checks instead).
    load();
    listTimer = setInterval(() => !(RT && RT.live) && !document.hidden && refreshChats(), 15000);
    const live = RT ? await RT.start() : false;
    paintLive(live);
    if (RT) {
      RT.onStatus((on) => {
        paintLive(on);
        if (on && current) listen(current.chat.id);
      });
      RT.me(() => refreshChats());
      RT.online(() => {
        renderAll();
        renderHeader();
      });
      if (live && current) listen(current.chat.id);
    }
  });
})();
