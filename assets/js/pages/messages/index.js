/**
 * ============================================================================
 * MESSAGES - the template's chat page (chat.html), filled with our messages
 * ============================================================================
 * The markup, classes and ids are chat.html's (includes/messages/body-index.php)
 * and so is the behaviour (assets/js/chat.js): SimpleBar on the five scroll
 * areas, .responsive-chat-close / .responsive-userinfo-open /
 * .responsive-chat-close2, and changeTheInfo()'s job - mark the row active,
 * fill .chatnameperson / .chatstatusperson / .chatpersonstatus, and slide to
 * the conversation on a phone (.responsive-chat-open).
 *
 * Tabs: Inbox (the template's Recent), Sent (Groups), Saved (Calls).
 * The tab and the open item stay in the URL (?tab=, ?open=).
 * The template's slots, filled with ours: the list header's settings menu
 * (show messages from...), its "ACTIVE / ALL CHATS" labels (Unread /
 * Earlier), the header's phone and video buttons (this message's two
 * actions), its dots menu, the footer's second button (a saved message
 * into the reply), and the details panel's three round buttons and
 * "Shared Files" block (About this message, View All).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const CTX = window.MESSAGES_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const state = { tab: ["sent", "saved"].includes(params.get("tab")) ? params.get("tab") : "inbox", from: "", open: Number(params.get("open")) || null, q: "" };
  const data = { inbox: { unread: 0, items: [] }, sent: null, saved: null };
  const LEVEL = { diocese: "Diocese", region: "Region", church: "Church" };
  const TAB_BUTTON = { inbox: "users-tab", sent: "groups-tab", saved: "calls-tab" };
  const ME = (() => {
    try {
      return JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.USER_DATA) || "null");
    } catch (e) {
      return null;
    }
  })();
  const myName = () => ME?.full_name || [ME?.firstname, ME?.lastname].filter(Boolean).join(" ") || "You";

  const initials = (name) => (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join("");
  const nl = (s) => M.esc(s).replace(/\n/g, "<br>");
  const timeOf = (iso) => new Date(iso).toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", hour12: true }).replace(" ", "");
  /** The list's time, as the template shows it: 1:32PM today, else the day. */
  const listTime = (iso) => {
    if (!iso) return "";
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString() ? timeOf(iso) : d.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
  };
  const dayLabel = (iso) => {
    const d = new Date(iso);
    const t = new Date();
    const y = new Date(t);
    y.setDate(t.getDate() - 1);
    return d.toDateString() === t.toDateString() ? "Today" : d.toDateString() === y.toDateString() ? "Yesterday" : d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
  };

  function syncUrl() {
    const q = new URLSearchParams();
    if (state.tab !== "inbox") q.set("tab", state.tab);
    if (state.open) q.set("open", state.open);
    const qs = q.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
  }

  // ======================================================== chat.js, as in the template

  const wrapper = () => document.querySelector(".main-chart-wrapper");
  /** Where SimpleBar puts an element's content (or the element itself). */
  const inner = (el) => (window.SimpleBar && SimpleBar.instances?.get(el)?.getContentElement()) || el;

  function templateBehaviour() {
    if (window.SimpleBar) {
      ["chat-msg-scroll", "groups-tab-pane", "calls-tab-pane", "main-chat-content", "chat-user-details"].forEach((id) => $(id) && new SimpleBar($(id), { autoHide: true }));
    }
    document.querySelector(".responsive-chat-close").addEventListener("click", () => wrapper().classList.remove("responsive-chat-open"));
    document.querySelectorAll(".responsive-userinfo-open").forEach((el) => el.addEventListener("click", () => $("chat-user-details").classList.add("open")));
    document.querySelector(".responsive-chat-close2").addEventListener("click", () => $("chat-user-details").classList.remove("open"));
    document.querySelector(".chat-info").addEventListener("click", () => $("chat-user-details").classList.remove("open"));
    document.querySelector(".chat-content").addEventListener("click", () => $("chat-user-details").classList.remove("open"));
  }

  /** changeTheInfo(): the open row, the name/avatar/status everywhere, and on a phone the slide. */
  function changeTheInfo({ name, sub, icon, color, slide }) {
    document.querySelectorAll(".checkforactive").forEach((li) => li.classList.toggle("active", Number(li.dataset.id) === state.open));
    document.querySelectorAll(".chatnameperson").forEach((el) => (el.textContent = name));
    document.querySelectorAll(".chatstatusperson").forEach((el) => {
      el.className = el.className.replace(/\bbg-\S+/g, "").trim() + ` bg-${color}`;
      el.innerHTML = `<i class="${icon}${el.id === "detailsAvatar" ? " fs-24" : " fs-20"}"></i>`;
    });
    document.querySelector(".chatpersonstatus").textContent = sub;
    if (slide) wrapper().classList.add("responsive-chat-open");
  }

  function scrollToEnd() {
    const sb = window.SimpleBar && SimpleBar.instances?.get($("main-chat-content"));
    const el = sb ? sb.getScrollElement() : $("main-chat-content");
    el.scrollTop = el.scrollHeight;
  }

  // ================================================================ load

  async function load() {
    const [inbox, sent, saved] = await Promise.all([
      MessagesAPI.inbox(""),
      CTX.can.read ? MessagesAPI.sent(new Date().getFullYear()) : null,
      CTX.can.send ? MessagesAPI.templates() : null,
    ]);
    if (inbox?.ok) data.inbox = inbox.data;
    else Toast.error(inbox?.message || "Your inbox couldn't be loaded");
    data.sent = sent?.ok ? sent.data : null;
    data.saved = saved?.ok ? saved.data : null;
    renderBadge();
    renderFromMenu();
    renderLists();
    const btn = $(TAB_BUTTON[state.tab]);
    if (btn && state.tab !== "inbox") bootstrap.Tab.getOrCreateInstance(btn).show();
    else openFirst();
  }

  function renderBadge() {
    const badge = $("unreadBadge");
    badge.hidden = !data.inbox.unread;
    badge.textContent = `${data.inbox.unread} unread`;
  }

  /** The list header's settings menu (the template's): whose messages to show. */
  const FROM_CHOICES = [
    ["", "Everyone", "ri-inbox-line"],
    ["diocese", "The diocese", M.FROM.diocese.icon],
    ["region", "The region", M.FROM.region.icon],
    ["church", "Our church", M.FROM.church.icon],
  ];
  function renderFromMenu() {
    $("fromMenu").innerHTML =
      `<li><h6 class="dropdown-header">Show messages from</h6></li>` +
      FROM_CHOICES.map(([v, l, icon]) => `<li><a class="dropdown-item d-flex align-items-center${state.from === v ? " active" : ""}" href="javascript:void(0);" data-from="${v}"><i class="${icon} me-2"></i>${l}</a></li>`).join("");
  }

  // ================================================================ lists (left)

  function listFor(tab) {
    const q = state.q.toLowerCase();
    const match = (...parts) => !q || parts.join(" ").toLowerCase().includes(q);
    if (tab === "sent") return (data.sent?.items || []).filter((b) => match(b.subject, b.preview, b.summary));
    if (tab === "saved") return (data.saved || []).filter((t) => match(t.name, t.subject, t.body));
    return data.inbox.items.filter((m) => (!state.from || m.from.type === state.from) && match(m.subject, m.body, m.from.name, m.by));
  }

  const label = (text) => `<li class="pb-0"><p class="text-muted fs-11 fw-semibold mb-2 op-7">${text}</p></li>`;

  /** The template's li.checkforactive row, with our icon avatar for its photo. */
  function row(tab, x) {
    let icon = "ri-send-plane-line";
    let color = "success";
    let name = "";
    let time = "";
    let line = "";
    let extra = "";
    if (tab === "inbox") {
      const f = M.FROM[x.from.type] || M.FROM.church;
      [icon, color, name, time, line] = [f.icon, f.color, x.from.name, listTime(x.at), x.subject];
      extra = x.read_at ? '<span class="chat-read-icon float-end align-middle"><i class="ri-check-double-fill"></i></span>' : '<span class="badge bg-success-transparent rounded-circle float-end">1</span>';
    } else if (tab === "sent") {
      [name, time, line] = [x.subject || x.preview.slice(0, 50), listTime(x.sent_at || x.scheduled_at || x.created_at), x.summary || ""];
      extra = x.replies ? `<span class="badge bg-success-transparent rounded-circle float-end" title="Replies">${x.replies}</span>` : '<span class="chat-read-icon float-end align-middle"><i class="ri-check-double-fill"></i></span>';
    } else {
      [icon, color, name, line] = ["ri-bookmark-line", "purple", x.name, x.body];
    }
    return `<li class="${tab === "inbox" && !x.read_at ? "chat-msg-unread " : ""}checkforactive${state.open === x.id && state.tab === tab ? " active" : ""}" data-id="${x.id}">
      <a href="javascript:void(0);" data-open="${x.id}" data-tab="${tab}">
        <div class="d-flex align-items-top">
          <div class="me-1 lh-1">
            <span class="avatar avatar-md me-2 avatar-rounded bg-${color} text-white"><i class="${icon}"></i></span>
          </div>
          <div class="flex-fill">
            <p class="mb-0 fw-semibold">${M.esc(name)}${time ? ` <span class="float-end text-muted fw-normal fs-11">${time}</span>` : ""}</p>
            <p class="fs-12 mb-0"><span class="chat-msg text-truncate">${M.esc(line)}</span> ${extra}</p>
          </div>
        </div>
      </a>
    </li>`;
  }

  function renderList(tab) {
    const target = tab === "inbox" ? inner($("chat-msg-scroll")) : $(tab === "sent" ? "sentList" : "savedList");
    if (!target) return;
    const list = listFor(tab);
    const none = {
      inbox: ["ri-inbox-line", state.q || state.from ? "Nothing matches" : "Nothing here yet", state.q || state.from ? "Try Everyone in the menu above, or another word." : "Messages sent to you by your church, region or the diocese show here."],
      sent: ["ri-send-plane-line", "Nothing sent yet", "Messages you send show here, with their replies."],
      saved: ["ri-bookmark-line", "No saved messages", "Save a message you send often and use it again in one click."],
    }[tab];
    let html = "";
    if (!list.length) html = `<li>${M.empty(...none)}</li>`;
    else if (tab === "inbox") {
      // The template's ACTIVE CHATS / ALL CHATS: unread first, then the rest.
      const from = FROM_CHOICES.find(([v]) => v === state.from);
      const suffix = state.from ? ` · ${from[1].toUpperCase()}` : "";
      const unread = list.filter((m) => !m.read_at);
      const earlier = list.filter((m) => m.read_at);
      if (unread.length) html += label(`UNREAD${suffix}`) + unread.map((x) => row(tab, x)).join("");
      if (earlier.length) html += label(`${unread.length ? "EARLIER" : "ALL MESSAGES"}${suffix}`) + earlier.map((x) => row(tab, x)).join("");
    } else {
      html = label(tab === "sent" ? "WHAT WE SENT" : "SAVED MESSAGES") + list.map((x) => row(tab, x)).join("");
    }
    target.innerHTML = html;
    if (tab === "saved" && CTX.can.send) target.insertAdjacentHTML("beforeend", `<li><button type="button" class="btn btn-outline-primary btn-sm w-100" id="tplNew"><i class="ri-add-line me-1"></i>New saved message</button></li>`);
  }

  function renderLists() {
    renderList("inbox");
    if (data.sent) renderList("sent");
    if (data.saved) renderList("saved");
  }

  function openFirst() {
    const list = listFor(state.tab);
    const pick = list.find((x) => x.id === state.open) || (window.innerWidth >= 992 ? list[0] : null);
    if (pick) open(pick.id, false);
    else blank();
  }

  // ================================================================ the conversation (middle)

  function blank() {
    state.open = null;
    syncUrl();
    changeTheInfo({ name: "Messages", sub: "Pick one on the left", icon: "ri-chat-3-line", color: "primary", slide: false });
    $("chatThread").innerHTML = `<li>${M.empty("ri-chat-3-line", "Pick a message", "Choose one on the left to read it.")}</li>`;
    actions({});
    $("chatFooter").innerHTML = `<input class="form-control" placeholder="Pick a message to reply" type="text" disabled><a aria-label="Send" class="btn btn-primary btn-icon btn-send ms-2 disabled" href="javascript:void(0)"><i class="ri-send-plane-2-line"></i></a>`;
    $("detailsSub").innerHTML = "&nbsp;";
    $("detailsBody").innerHTML = "";
  }

  /**
   * This message's actions in the template's places: the header's two
   * buttons (its phone and video), its dots menu, and the details panel's
   * three round buttons. Each action: { icon, label, href } or { icon, label, run }.
   */
  function actions({ top = [], menu = [], round = [] }) {
    const go = (a) => (a.href ? (window.location.href = a.href) : a.run());
    ["chatAct1", "chatAct2"].forEach((id, i) => {
      const b = $(id);
      const a = top[i];
      // From a small tablet up; on a phone the same actions are in the dots menu.
      b.classList.toggle("d-sm-inline-flex", !!a);
      b.onclick = a ? () => go(a) : null;
      if (!a) return;
      b.innerHTML = `<i class="${a.icon}"></i>`;
      b.title = a.label;
      b.setAttribute("aria-label", a.label);
    });
    $("chatMenu").innerHTML = menu.length
      ? menu.map((a, i) => `<li><a class="dropdown-item d-flex align-items-center" href="javascript:void(0);" data-menu="${i}"><i class="${a.icon} me-2"></i>${a.label}</a></li>`).join("")
      : `<li><span class="dropdown-item-text fs-12">Pick a message first</span></li>`;
    $("chatMenu").onclick = (e) => {
      const el = e.target.closest("[data-menu]");
      if (el) go(menu[el.dataset.menu]);
    };
    $("detailsActions").innerHTML = round
      .map((a, i) => `<button type="button" class="btn btn-icon rounded-pill btn-primary-light${i ? " ms-2" : ""}" data-round="${i}" title="${a.label}" aria-label="${a.label}"><i class="${a.icon}"></i></button>`)
      .join("");
    $("detailsActions").onclick = (e) => {
      const el = e.target.closest("[data-round]");
      if (el) go(round[el.dataset.round]);
    };
  }

  const copy = (text) => ({
    icon: "ri-file-copy-line",
    label: "Copy the message",
    run: async () => {
      try {
        await navigator.clipboard.writeText(text);
        Toast.success("Copied");
      } catch (e) {
        Toast.error("Couldn't copy - select the text instead");
      }
    },
  });
  const newMessage = () => (CTX.can.send ? [{ icon: "ri-send-plane-line", label: "Send a new message", href: `${CTX.baseUrl}/new` }] : []);

  /** The template's li.chat-item-start / li.chat-item-end. */
  function bubble(side, who, at, body, read = false) {
    const avatar = (name, color) => `<div class="chat-user-profile"><span class="avatar avatar-md avatar-rounded bg-${color} text-white">${M.esc(initials(name))}</span></div>`;
    if (side === "start") {
      return `<li class="chat-item-start"><div class="chat-list-inner">${avatar(who, UI.colorFor(who))}
        <div class="ms-3"><span class="chatting-user-info"><span>${M.esc(who)}</span> <span class="msg-sent-time">${at ? timeOf(at) : ""}</span></span>
          <div class="main-chat-msg"><div><p class="mb-0">${nl(body)}</p></div></div></div></div></li>`;
    }
    return `<li class="chat-item-end"><div class="chat-list-inner">
      <div class="me-3"><span class="chatting-user-info"><span class="msg-sent-time">${read ? '<span class="chat-read-mark align-middle d-inline-flex"><i class="ri-check-double-line"></i></span>' : ""}${at ? timeOf(at) : ""}</span> You</span>
        <div class="main-chat-msg"><div><p class="mb-0">${nl(body)}</p></div></div></div>
      ${avatar(myName(), "primary")}</div></li>`;
  }

  /** Bubbles in time order, with li.chat-day-label whenever the day changes. */
  function thread(entries) {
    let day = "";
    return entries
      .sort((a, b) => new Date(a.at || 0) - new Date(b.at || 0))
      .map((e) => {
        const d = e.at ? dayLabel(e.at) : "";
        const head = d && d !== day ? `<li class="chat-day-label"><span>${d}</span></li>` : "";
        day = d || day;
        return head + bubble(e.side, e.who, e.at, e.body, e.read);
      })
      .join("");
  }

  function open(id, slide = true) {
    state.open = id;
    syncUrl();
    if (state.tab === "sent") openSent(id, slide);
    else if (state.tab === "saved") openSaved(id, slide);
    else openInbox(id, slide);
  }

  /** The details panel's list, in the template's "Shared Files" rows. */
  function details(title, rows, extra = "", viewAll = "") {
    $("detailsBody").innerHTML = `
      <div class="fw-semibold mb-4">${title}${viewAll ? `<span class="float-end fs-11"><a href="${viewAll}" class="link-primary text-underline"><u>View All</u></a></span>` : ""}</div>
      <ul class="shared-files list-unstyled">${rows
        .map(([icon, k, v]) => `<li><div class="d-flex align-items-center"><div class="me-2"><span class="shared-file-icon"><i class="${icon}"></i></span></div><div class="flex-fill" style="min-width:0"><p class="fs-12 fw-semibold mb-0 text-break">${M.esc(v)}</p><p class="mb-0 text-muted fs-11">${k}</p></div></div></li>`)
        .join("")}</ul>${extra}`;
  }

  // ---- Inbox: their message on the left, my replies on the right, reply at the bottom
  async function openInbox(id, slide) {
    const m = data.inbox.items.find((x) => x.id === id);
    if (!m) return blank();
    const f = M.FROM[m.from.type] || M.FROM.church;
    changeTheInfo({ name: m.from.name, sub: `${m.subject}${m.by ? ` · ${m.by}` : ""}`, icon: f.icon, color: f.color, slide });
    $("chatThread").innerHTML = thread([{ side: "start", who: m.by || m.from.name, at: m.at, body: m.body }, ...m.my_replies.map((r) => ({ side: "end", at: r.at, body: r.body, read: true }))]);
    // The template's footer: the input, its second button (here: a saved message into the reply), send.
    const saved = data.saved || [];
    $("chatFooter").innerHTML = `<input class="form-control" id="replyBody" placeholder="Reply to ${M.esc(m.from.name)}..." type="text" maxlength="2000">
      <div class="dropup">
        <a aria-label="Put in a saved message" title="Put in a saved message" class="btn btn-icon mx-2 btn-success-light" href="javascript:void(0)" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false"><i class="ri-bookmark-line"></i></a>
        <ul class="dropdown-menu dropdown-menu-end" id="savedPick">${
          saved.length
            ? `<li><h6 class="dropdown-header">Put in a saved message</h6></li>` + saved.map((t) => `<li><a class="dropdown-item" href="javascript:void(0);" data-saved="${t.id}">${M.esc(t.name)}</a></li>`).join("")
            : `<li><span class="dropdown-item-text fs-12">No saved messages yet</span></li>`
        }</ul>
      </div>
      <a aria-label="Send the reply" class="btn btn-primary btn-icon btn-send" href="javascript:void(0)" id="replyBtn"><i class="ri-send-plane-2-line"></i></a>`;
    $("savedPick").addEventListener("click", (e) => {
      const el = e.target.closest("[data-saved]");
      const t = el && saved.find((x) => x.id === Number(el.dataset.saved));
      if (!t) return;
      $("replyBody").value = t.body.replace(/\{name\}/g, m.by || m.from.name).replace(/\{place\}/g, m.from.name).replace(/\{sender\}/g, myName());
      $("replyBody").focus();
    });
    const reply = { icon: "ri-reply-line", label: "Reply", run: () => $("replyBody").focus() };
    actions({ top: [reply, ...newMessage()], menu: [reply, copy(m.body), ...newMessage()], round: [reply, copy(m.body), ...newMessage()] });
    $("detailsSub").textContent = LEVEL[m.from.type] || "";
    details("About this message", [
      ["ri-user-line", "Sent by", m.by || "-"],
      [f.icon, "From", m.from.name],
      [M.CHANNELS[m.channel]?.icon || "ri-mail-line", "How it came", M.CHANNELS[m.channel]?.label || "In the app"],
      ["ri-time-line", "When", M.when(m.at)],
      ["ri-reply-line", "Your replies", String(m.my_replies.length)],
    ]);
    scrollToEnd();
    const send = async () => {
      const text = $("replyBody").value.trim();
      if (!text) return Toast.warning("Write a reply first.");
      const res = await MessagesAPI.reply(id, text);
      if (!res.ok) return Toast.error(res.message);
      Object.assign(m, res.data);
      Toast.success("Reply sent");
      openInbox(id, false);
    };
    $("replyBtn").addEventListener("click", send);
    $("replyBody").addEventListener("keydown", (e) => e.key === "Enter" && (e.preventDefault(), send()));
    if (!m.read_at) {
      const res = await MessagesAPI.read(id);
      if (res.ok) {
        m.read_at = res.data.read_at;
        data.inbox.unread = Math.max(0, data.inbox.unread - 1);
        renderBadge();
        renderList("inbox");
      }
    }
  }

  // ---- Sent: what we sent on the right, replies on the left
  async function openSent(id, slide) {
    const b0 = (data.sent?.items || []).find((x) => x.id === id);
    if (!b0) return blank();
    changeTheInfo({ name: b0.subject || b0.preview.slice(0, 60), sub: `To ${b0.summary || ""}`, icon: "ri-send-plane-line", color: "success", slide });
    $("chatThread").innerHTML = `<li class="text-center py-4"><span class="spinner-border spinner-border-sm"></span></li>`;
    const res = await MessagesAPI.get(id);
    if (state.open !== id || state.tab !== "sent") return;
    if (!res.ok) return Toast.error(res.message);
    const b = res.data;
    $("chatThread").innerHTML = thread([
      { side: "end", at: b.sent_at || b.scheduled_at || b.created_at, body: b.body, read: b.read > 0 },
      ...(b.replies_list || []).map((r) => ({ side: "start", who: `${r.who}${r.place ? ` · ${r.place}` : ""}`, at: r.at, body: r.body })),
    ]);
    $("chatFooter").innerHTML = `<span class="flex-fill fs-12 fw-semibold">${b.replies ? `${b.replies} ${b.replies === 1 ? "reply" : "replies"}` : "Replies from people with a login show here"}</span><a class="btn btn-outline-primary btn-sm ms-2" href="${CTX.baseUrl}/message?id=${b.id}"><i class="ri-list-check-2 me-1"></i>Everyone it went to</a>`;
    $("detailsSub").textContent = M.STATUS[b.status]?.label || "";
    const everyone = { icon: "ri-group-line", label: "Everyone it went to", href: `${CTX.baseUrl}/message?id=${b.id}` };
    actions({ top: [everyone, ...newMessage()], menu: [everyone, copy(b.body), ...newMessage()], round: [everyone, copy(b.body), ...newMessage()] });
    const people = (b.recipients || []).slice(0, 8);
    details(
      "What we sent",
      [
        ["ri-group-line", "To", b.summary || "-"],
        [M.CHANNELS[b.channel]?.icon || "ri-mail-line", "How", M.CHANNELS[b.channel]?.label || ""],
        ["ri-time-line", "Sent", b.status === "scheduled" ? `For ${M.when(b.scheduled_at)}` : M.when(b.sent_at || b.created_at)],
        ["ri-checkbox-circle-line", "Reached", `${M.num(b.sent_count)} of ${M.num(b.recipient_count)}`],
        ["ri-eye-line", "Read in the app", M.num(b.read)],
        ...(b.failed_count ? [["ri-error-warning-line", "Didn't go", M.num(b.failed_count)]] : []),
      ],
      people.length
        ? `<div class="fw-semibold mb-4 mt-5">Who it went to <span class="badge bg-primary-transparent rounded-circle ms-1">${b.recipients.length}</span><span class="float-end fs-11"><a href="${everyone.href}" class="link-primary text-underline"><u>View All</u></a></span></div>
           <ul class="shared-files list-unstyled">${people
             .map((r) => `<li><div class="d-flex align-items-center"><div class="me-2"><span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(r.name)} text-white">${M.esc(initials(r.name))}</span></div><div class="flex-fill" style="min-width:0"><p class="fs-12 fw-semibold mb-0 text-truncate">${M.esc(r.name || r.phone || r.email)}</p><p class="mb-0 text-muted fs-11 text-truncate">${M.esc(r.place || r.role || "")}</p></div><div class="fs-18">${r.read_at ? '<i class="ri-check-double-line text-success" title="Read"></i>' : '<i class="ri-check-line text-muted" title="Not read yet"></i>'}</div></div></li>`)
             .join("")}</ul>`
        : "",
    );
    scrollToEnd();
  }

  // ---- Saved: the message as it would go, with Edit / Use it
  function openSaved(id, slide) {
    const t = (data.saved || []).find((x) => x.id === id);
    if (!t) return blank();
    const p = M.smsParts(t.body);
    changeTheInfo({ name: t.name, sub: t.subject || "Saved message", icon: "ri-bookmark-line", color: "purple", slide });
    $("chatThread").innerHTML = bubble("end", "", null, t.body);
    $("chatFooter").innerHTML = `<button type="button" class="btn btn-light btn-sm" id="tplEdit"><i class="ri-edit-line me-1"></i>Edit</button><span class="flex-fill"></span><a class="btn btn-primary btn-sm" href="${CTX.baseUrl}/new?template=${t.id}"><i class="ri-send-plane-line me-1"></i>Use it</a>`;
    $("tplEdit").addEventListener("click", () => editTemplate(t));
    $("detailsSub").textContent = "Saved message";
    const use = { icon: "ri-send-plane-line", label: "Use it", href: `${CTX.baseUrl}/new?template=${t.id}` };
    const edit = { icon: "ri-edit-line", label: "Edit", run: () => editTemplate(t) };
    actions({ top: [use, edit], menu: [use, edit, copy(t.body)], round: [use, edit, copy(t.body)] });
    details(
      "About it",
      [
        [M.CHANNELS[t.channel]?.icon || "ri-mail-line", "Usually sent by", M.CHANNELS[t.channel]?.label || ""],
        ["ri-text", "Length", `${p.characters} characters`],
        ["ri-message-2-line", "As SMS", `${p.parts} ${p.parts === 1 ? "part" : "parts"}${p.unicode ? " (special characters)" : ""}`],
      ],
      `<p class="fs-12 mb-0">Use <b>{name}</b>, <b>{place}</b> and <b>{sender}</b> - each person gets their own.</p>`,
    );
  }

  // ================================================================ saved message window

  function editTemplate(t) {
    $("tplModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="tplModal" tabindex="-1" aria-labelledby="tplTitle">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header">
            <span class="app-modal-icon bg-purple text-white"><i class="ri-bookmark-line"></i></span>
            <div class="flex-fill"><h5 class="modal-title" id="tplTitle">${t ? "Edit saved message" : "New saved message"}</h5><div class="app-modal-subtitle">Use it again in one click when you send</div></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-7"><label class="form-label" for="tName">Name</label><input class="form-control" id="tName" maxlength="80" value="${M.esc(t?.name || "")}" placeholder="e.g. Report reminder"></div>
              <div class="col-md-5"><label class="form-label" for="tChannel">Usually sent by</label><select class="form-select" id="tChannel">${Object.entries(M.CHANNELS).map(([k, c]) => `<option value="${k}"${(t?.channel || "sms") === k ? " selected" : ""}>${c.label}</option>`).join("")}</select></div>
              <div class="col-12"><label class="form-label" for="tSubject">Subject (email and Inbox)</label><input class="form-control" id="tSubject" maxlength="120" value="${M.esc(t?.subject || "")}"></div>
              <div class="col-12"><label class="form-label" for="tBody">Message</label><textarea class="form-control" id="tBody" rows="6" maxlength="10000" placeholder="Dear {name}, ...">${M.esc(t?.body || "")}</textarea><div class="form-text" id="tCount"></div></div>
            </div>
          </div>
          <div class="modal-footer">
            ${t ? `<button type="button" class="btn btn-link text-danger me-auto" id="tDelete">Remove</button>` : ""}
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-primary" id="tSave"><i class="ri-check-line me-1"></i>Save</button>
          </div>
        </div></div>
      </div>`,
    );
    const el = $("tplModal");
    const modal = new bootstrap.Modal(el);
    UI.enhanceSelect("tChannel", { search: false, dropdownParent: window.jQuery(el) });
    const count = () => {
      const p = M.smsParts($("tBody").value);
      $("tCount").textContent = `${p.characters} characters · ${p.parts} SMS${p.unicode ? " (special characters make SMS shorter)" : ""}`;
    };
    $("tBody").addEventListener("input", count);
    count();
    const reload = async (keepId) => {
      const res = await MessagesAPI.templates();
      if (res.ok) data.saved = res.data;
      renderList("saved");
      if (keepId && data.saved.some((x) => x.id === keepId)) open(keepId, false);
      else blank();
    };
    $("tSave").addEventListener("click", async () => {
      UI.setButtonLoading($("tSave"), "Saving...");
      const res = await MessagesAPI.saveTemplate(t?.id, { name: $("tName").value.trim(), channel: $("tChannel").value, subject: $("tSubject").value.trim() || null, body: $("tBody").value });
      UI.restoreButton($("tSave"));
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      modal.hide();
      reload(t?.id || res.data?.id || null);
    });
    $("tDelete")?.addEventListener("click", async () => {
      const res = await MessagesAPI.deleteTemplate(t.id);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      modal.hide();
      reload(null);
    });
    el.addEventListener("hidden.bs.modal", () => el.remove());
    modal.show();
  }

  // ================================================================ start

  document.addEventListener("DOMContentLoaded", () => {
    templateBehaviour();
    renderFromMenu();
    if (!$(TAB_BUTTON[state.tab])) state.tab = "inbox";

    // Bootstrap switches the panes (as in the template); we follow it.
    document.querySelectorAll("#myTab1 [data-tab]").forEach((btn) =>
      btn.addEventListener("shown.bs.tab", () => {
        if (state.tab !== btn.dataset.tab) state.open = null;
        state.tab = btn.dataset.tab;
        openFirst();
      }),
    );
    // One click handler for every list: open a row, filter the Inbox (the header menu), add a saved message.
    document.querySelector(".chat-info").addEventListener("click", (ev) => {
      const a = ev.target.closest("[data-open]");
      if (a) return open(Number(a.dataset.open));
      const f = ev.target.closest("[data-from]");
      if (f) {
        state.from = f.dataset.from;
        renderFromMenu();
        if (state.tab !== "inbox") bootstrap.Tab.getOrCreateInstance($("users-tab")).show();
        return renderList("inbox");
      }
      if (ev.target.closest("#tplNew")) editTemplate(null);
    });
    let t = null;
    $("msgSearch").addEventListener("input", (e) => {
      clearTimeout(t);
      t = setTimeout(() => {
        state.q = e.target.value.trim();
        renderLists();
      }, 200);
    });
    load();
  });
})();
