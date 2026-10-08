/**
 * ============================================================================
 * MESSAGES - our own inbox (church, region, diocese)
 * ============================================================================
 * Section tabs with live figures (Inbox / Sent / Saved), then one card: the
 * list on the left - search, "from" chips, Unread / Earlier - and the
 * reading pane on the right: the message as a letter, replies as a timeline,
 * and a reply box (Inbox), who it went to (Sent), or the phone preview
 * (Saved). On a phone the list fills the screen and a message opens over it.
 * The tab and the open message stay in the URL (?tab=, ?open=).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const CTX = window.MESSAGES_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const TABS = [...document.querySelectorAll("#msgTabs [data-tab]")].map((b) => b.dataset.tab);
  const state = { tab: TABS.includes(params.get("tab")) ? params.get("tab") : "inbox", from: "", open: Number(params.get("open")) || null, q: "" };
  const data = { inbox: null, sent: null, saved: null };
  const LEVEL = { diocese: "Diocese", region: "Region", church: "Church" };
  const FROM_CHOICES = [
    ["", "Everyone"],
    ["diocese", "Diocese"],
    ["region", "Region"],
    ["church", "Our church"],
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
  /** The message as a letter: paragraphs on blank lines, line breaks kept. */
  const letter = (s) =>
    String(s || "")
      .trim()
      .split(/\n\s*\n/)
      .map((p) => `<p>${M.esc(p).replace(/\n/g, "<br>")}</p>`)
      .join("") || "<p>-</p>";
  const listTime = (iso) => {
    if (!iso) return "";
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString() ? d.toLocaleTimeString("en-GB", { hour: "numeric", minute: "2-digit" }) : d.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
  };
  const isPhone = () => window.matchMedia("(max-width: 991.98px)").matches;

  function syncUrl() {
    const q = new URLSearchParams();
    if (state.tab !== "inbox") q.set("tab", state.tab);
    if (state.open) q.set("open", state.open);
    const qs = q.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
  }

  function failed(target, title, message, retry) {
    target.innerHTML = `<div class="mi-empty">${M.empty("ri-error-warning-line", title, M.esc(message), "danger")}<button type="button" class="btn btn-primary mt-2" data-retry><i class="ri-refresh-line me-1"></i>Try again</button></div>`;
    target.querySelector("[data-retry]").addEventListener("click", retry);
  }

  // ================================================================ load
  async function load() {
    $("miList").innerHTML = Array.from({ length: 6 }, () => `<div class="mi-row-skel"><span class="skel skel-tile"></span><div class="flex-fill"><span class="skel skel-line" style="width:60%"></span><span class="skel skel-line skel-line-sm mt-2" style="width:85%"></span></div></div>`).join("");
    $("miPane").innerHTML = `<div class="mi-pane-empty"><span class="spinner-border text-primary" role="status"></span></div>`;
    const [inbox, sent, saved] = await Promise.all([MessagesAPI.inbox(""), CTX.can.read ? MessagesAPI.sent(new Date().getFullYear()) : null, CTX.can.send ? MessagesAPI.templates() : null]);
    if (!inbox?.ok) {
      failed($("miList"), "Couldn't load your messages", inbox?.message || "", load);
      $("miPane").innerHTML = "";
      return;
    }
    data.inbox = inbox.data;
    data.sent = sent?.ok ? sent.data : null;
    data.saved = saved?.ok ? saved.data : null;
    renderFigures();
    selectTab(state.tab, true);
  }

  function renderFigures() {
    const fig = (k, t) => {
      const el = document.querySelector(`[data-tab-figure="${k}"]`);
      if (el) el.textContent = t;
    };
    fig("inbox", data.inbox.unread ? `${data.inbox.unread} unread` : `${data.inbox.items.length} ${data.inbox.items.length === 1 ? "message" : "messages"}`);
    if (data.sent) fig("sent", `${M.num(data.sent.figures?.sent ?? data.sent.items.length)} this month`);
    if (data.saved) fig("saved", `${data.saved.length} saved`);
  }

  // ================================================================ the list
  function selectTab(tab, keepOpen = false) {
    state.tab = tab;
    if (!keepOpen) state.open = null;
    document.querySelectorAll("#msgTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("miFrom").classList.toggle("d-none", tab !== "inbox");
    $("miSearch").placeholder = { inbox: "Search your messages", sent: "Search what we sent", saved: "Search saved messages" }[tab];
    renderFrom();
    renderList();
    const list = listFor(tab);
    const pick = list.find((x) => x.id === state.open) || (!isPhone() ? list[0] : null);
    if (pick) open(pick.id, { slide: !!state.open && isPhone() });
    else blank();
  }

  function renderFrom() {
    $("miFrom").innerHTML = FROM_CHOICES.map(([v, l]) => `<button type="button" class="soft-chip soft-primary${state.from === v ? " is-on" : ""}" data-from="${v}" aria-pressed="${state.from === v}">${l}</button>`).join("");
  }

  function listFor(tab) {
    const q = state.q.toLowerCase();
    const match = (...parts) => !q || parts.join(" ").toLowerCase().includes(q);
    if (tab === "sent") return (data.sent?.items || []).filter((b) => match(b.subject, b.preview, b.summary));
    if (tab === "saved") return (data.saved || []).filter((t) => match(t.name, t.subject, t.body));
    return (data.inbox?.items || []).filter((m) => (!state.from || m.from.type === state.from) && match(m.subject, m.body, m.from.name, m.by));
  }

  function rowFor(tab, x) {
    let icon, color, who, time, subject, snippet, extra;
    if (tab === "inbox") {
      const f = M.FROM[x.from.type] || M.FROM.church;
      [icon, color, who, time, subject, snippet] = [f.icon, f.color, x.from.name, listTime(x.at), x.subject || "(No subject)", x.body];
      extra = x.my_replies.length ? `<span class="soft-chip soft-primary"><i class="ri-reply-line"></i>${x.my_replies.length}</span>` : "";
    } else if (tab === "sent") {
      [icon, color, who, time, subject, snippet] = ["ri-send-plane-line", "success", x.summary || "Sent", listTime(x.sent_at || x.scheduled_at || x.created_at), x.subject || "(No subject)", x.preview];
      extra = [x.status !== "sent" ? M.statusPill(x.status) : "", x.replies ? `<span class="soft-chip soft-purple"><i class="ri-chat-3-line"></i>${x.replies}</span>` : ""].join("");
    } else {
      [icon, color, who, time, subject, snippet] = [M.CHANNELS[x.channel]?.icon || "ri-bookmark-line", "purple", x.name, M.CHANNELS[x.channel]?.label || "", x.subject || "(No subject)", x.body];
      extra = "";
    }
    const unread = tab === "inbox" && !x.read_at;
    return `<button type="button" class="mi-row${unread ? " is-unread" : ""}${state.open === x.id ? " is-active" : ""}" data-open="${x.id}">
      <span class="avatar avatar-md avatar-rounded bg-${color} text-white mi-row-icon"><i class="${icon}"></i></span>
      <span class="mi-row-main">
        <span class="mi-row-top"><span class="mi-row-who">${M.esc(who)}</span><span class="mi-row-time">${time}</span></span>
        <span class="mi-row-subject">${unread ? '<span class="mi-dot" aria-label="Unread"></span>' : ""}${M.esc(subject)}</span>
        <span class="mi-row-snippet">${M.esc(snippet || "")}</span>
        ${extra ? `<span class="mi-row-extra">${extra}</span>` : ""}
      </span>
    </button>`;
  }

  function renderList() {
    const tab = state.tab;
    const list = listFor(tab);
    const head = (label, n) => `<div class="mi-group">${label}<span class="soft-chip soft-primary">${n}</span></div>`;
    if (!list.length) {
      const filtered = state.q || (tab === "inbox" && state.from);
      const none = {
        inbox: ["ri-inbox-line", filtered ? "Nothing matches" : "Nothing here yet", filtered ? "Try Everyone, or another word." : "Messages from your church, region or the diocese show here."],
        sent: ["ri-send-plane-line", filtered ? "Nothing matches" : "Nothing sent yet", filtered ? "Try another word." : "Messages you send show here, with their replies."],
        saved: ["ri-bookmark-line", filtered ? "Nothing matches" : "No saved messages", filtered ? "Try another word." : "Save a message you send often and use it again in one click."],
      }[tab];
      $("miList").innerHTML = `<div class="mi-empty">${M.empty(...none)}</div>` + (tab === "saved" && CTX.can.send && !filtered ? `<div class="px-3 pb-3"><button type="button" class="btn btn-primary w-100" data-new-tpl><i class="ri-add-line me-1"></i>New saved message</button></div>` : "");
      return;
    }
    if (tab === "inbox") {
      const unread = list.filter((m) => !m.read_at);
      const earlier = list.filter((m) => m.read_at);
      $("miList").innerHTML = (unread.length ? head("Unread", unread.length) + unread.map((x) => rowFor(tab, x)).join("") : "") + (earlier.length ? head(unread.length ? "Earlier" : "All messages", earlier.length) + earlier.map((x) => rowFor(tab, x)).join("") : "");
    } else {
      $("miList").innerHTML =
        head(tab === "sent" ? "What we sent" : "Saved messages", list.length) +
        list.map((x) => rowFor(tab, x)).join("") +
        (tab === "saved" && CTX.can.send ? `<div class="p-3"><button type="button" class="btn btn-outline-primary w-100" data-new-tpl><i class="ri-add-line me-1"></i>New saved message</button></div>` : "");
    }
  }

  // ================================================================ the reading pane
  function blank() {
    state.open = null;
    syncUrl();
    $("miInbox").classList.remove("is-reading");
    $("miPane").innerHTML = `<div class="mi-pane-empty">${M.empty("ri-mail-open-line", "Pick a message", "Choose one on the left to read it here.")}</div>`;
  }

  function open(id, { slide = true } = {}) {
    state.open = id;
    syncUrl();
    document.querySelectorAll("#miList .mi-row").forEach((r) => r.classList.toggle("is-active", Number(r.dataset.open) === id));
    if (slide) $("miInbox").classList.add("is-reading");
    if (state.tab === "sent") openSent(id);
    else if (state.tab === "saved") openSaved(id);
    else openInbox(id);
  }

  /** The pane's header: back (phone), icon tile, subject, who, chips, actions. */
  function head({ icon, color, subject, who, chips, actions }) {
    return `<div class="mi-pane-head">
      <button type="button" class="btn btn-light btn-sm mi-back" data-back><i class="ri-arrow-left-line me-1"></i>Back</button>
      <div class="d-flex align-items-start gap-3">
        <span class="avatar avatar-lg avatar-rounded bg-${color} text-white flex-shrink-0"><i class="${icon} fs-20"></i></span>
        <div class="flex-fill min-w-0">
          <h5 class="mi-subject">${M.esc(subject)}</h5>
          <div class="mi-who">${who}</div>
          <div class="d-flex flex-wrap gap-1 mt-2">${chips.join("")}</div>
        </div>
        <div class="mi-actions">${actions}</div>
      </div>
    </div>`;
  }

  const copyBtn = `<button type="button" class="btn btn-light btn-icon" data-copy title="Copy the message" aria-label="Copy the message"><i class="ri-file-copy-line"></i></button>`;
  const newBtn = CTX.can.send ? `<a class="btn btn-light btn-icon" href="${CTX.baseUrl}/new" title="Send a new message" aria-label="Send a new message"><i class="ri-send-plane-line"></i></a>` : "";

  function threadItem(who, place, at, body, mine) {
    return `<li class="mi-thread-item${mine ? " is-mine" : ""}">
      <span class="avatar avatar-sm avatar-rounded ${mine ? "bg-primary" : `bg-${UI.colorFor(who)}`} text-white">${M.esc(initials(who))}</span>
      <div class="mi-thread-body">
        <div class="mi-thread-meta"><strong>${M.esc(mine ? "You" : who)}</strong>${place ? `<span>${M.esc(place)}</span>` : ""}<span>${at ? M.when(at) : ""}</span></div>
        <div class="mi-thread-text">${letter(body)}</div>
      </div>
    </li>`;
  }

  // ---- Inbox: the letter, my replies, and the reply box
  async function openInbox(id) {
    const m = data.inbox.items.find((x) => x.id === id);
    if (!m) return blank();
    const f = M.FROM[m.from.type] || M.FROM.church;
    const saved = data.saved || [];
    $("miPane").innerHTML = `
      ${head({
        icon: f.icon,
        color: f.color,
        subject: m.subject || "(No subject)",
        who: `From <strong>${M.esc(m.from.name)}</strong>${m.by ? ` · ${M.esc(m.by)}` : ""}`,
        chips: [`<span class="soft-chip soft-primary"><i class="ri-building-4-line"></i>${LEVEL[m.from.type] || ""}</span>`, M.channelChip(m.channel), `<span class="soft-chip soft-primary"><i class="ri-time-line"></i>${M.when(m.at)}</span>`],
        actions: `<button type="button" class="btn btn-primary btn-sm" data-reply><i class="ri-reply-line me-1"></i>Reply</button>${copyBtn}${newBtn}`,
      })}
      <div class="mi-pane-body">
        <article class="mi-letter">${letter(m.body)}<footer>- ${M.esc(m.by || m.from.name)}${m.by ? `, ${M.esc(m.from.name)}` : ""}</footer></article>
        ${m.my_replies.length ? `<div class="mi-thread-title">Your replies <span class="soft-chip soft-primary">${m.my_replies.length}</span></div><ul class="mi-thread">${m.my_replies.map((r) => threadItem(myName(), "", r.at, r.body, true)).join("")}</ul>` : ""}
      </div>
      <div class="mi-reply">
        <textarea class="form-control" id="miReplyBody" rows="2" maxlength="2000" placeholder="Reply to ${M.esc(m.by || m.from.name)}..."></textarea>
        <div class="mi-reply-foot">
          <div class="d-flex flex-wrap gap-1">${saved.slice(0, 4).map((t) => `<button type="button" class="soft-chip soft-primary" data-saved="${t.id}"><i class="ri-bookmark-line"></i>${M.esc(t.name)}</button>`).join("")}</div>
          <button type="button" class="btn btn-primary" id="miReplyBtn"><i class="ri-send-plane-2-line me-1"></i>Send reply</button>
        </div>
      </div>`;
    const body = $("miReplyBody");
    $("miPane").querySelector("[data-reply]").addEventListener("click", () => body.focus());
    $("miPane").querySelector("[data-copy]").addEventListener("click", () => copy(m.body));
    $("miPane").querySelectorAll("[data-saved]").forEach((b) =>
      b.addEventListener("click", () => {
        const t = saved.find((x) => x.id === Number(b.dataset.saved));
        body.value = t.body.replace(/\{name\}/g, m.by || m.from.name).replace(/\{place\}/g, m.from.name).replace(/\{sender\}/g, myName());
        body.focus();
      }),
    );
    const send = async () => {
      const text = body.value.trim();
      if (!text) return Toast.warning("Write a reply first.");
      UI.setButtonLoading($("miReplyBtn"), "Sending...");
      const res = await MessagesAPI.reply(id, text);
      UI.restoreButton($("miReplyBtn"));
      if (!res.ok) return Toast.error(res.message);
      Object.assign(m, res.data);
      Toast.success("Reply sent");
      renderList();
      openInbox(id);
    };
    $("miReplyBtn").addEventListener("click", send);
    body.addEventListener("keydown", (e) => e.key === "Enter" && (e.metaKey || e.ctrlKey) && (e.preventDefault(), send()));
    if (!m.read_at) {
      const res = await MessagesAPI.read(id);
      if (res.ok) {
        m.read_at = res.data.read_at;
        data.inbox.unread = Math.max(0, data.inbox.unread - 1);
        renderFigures();
        renderList();
      }
    }
  }

  // ---- Sent: what we sent, the replies, and who it went to
  async function openSent(id) {
    const b0 = (data.sent?.items || []).find((x) => x.id === id);
    if (!b0) return blank();
    $("miPane").innerHTML = `<div class="mi-pane-empty"><span class="spinner-border text-primary" role="status"></span></div>`;
    const res = await MessagesAPI.get(id);
    if (state.open !== id || state.tab !== "sent") return;
    if (!res.ok) return failed($("miPane"), "Couldn't open the message", res.message, () => openSent(id));
    const b = res.data;
    const everyone = `${CTX.baseUrl}/message?id=${b.id}`;
    const people = b.recipients || [];
    $("miPane").innerHTML = `
      ${head({
        icon: "ri-send-plane-line",
        color: "success",
        subject: b.subject || b.body.slice(0, 80),
        who: `To <strong>${M.esc(b.summary || "-")}</strong>`,
        chips: [M.statusPill(b.status), M.channelChip(b.channel), `<span class="soft-chip soft-primary"><i class="ri-time-line"></i>${b.status === "scheduled" ? `For ${M.when(b.scheduled_at)}` : M.when(b.sent_at || b.created_at)}</span>`, `<span class="soft-chip soft-success"><i class="ri-checkbox-circle-line"></i>Reached ${M.num(b.sent_count)} of ${M.num(b.recipient_count)}</span>`, b.read ? `<span class="soft-chip soft-primary"><i class="ri-eye-line"></i>Read by ${M.num(b.read)}</span>` : ""],
        actions: `<a class="btn btn-primary btn-sm" href="${everyone}"><i class="ri-group-line me-1"></i>Everyone it went to</a>${copyBtn}${newBtn}`,
      })}
      <div class="mi-pane-body">
        <article class="mi-letter">${letter(b.body)}<footer>- ${M.esc(b.by || myName())}</footer></article>
        <div class="mi-thread-title">Replies <span class="soft-chip soft-primary">${(b.replies_list || []).length}</span></div>
        ${(b.replies_list || []).length ? `<ul class="mi-thread">${b.replies_list.map((r) => threadItem(r.who, r.place, r.at, r.body, false)).join("")}</ul>` : `<p class="mi-note">No replies yet. People with a login can reply from their Inbox.</p>`}
        ${
          people.length
            ? `<div class="mi-thread-title">Who it went to <span class="soft-chip soft-primary">${people.length}</span><a class="ms-auto fs-12" href="${everyone}">View all</a></div>
          <div class="mi-people">${people
            .slice(0, 8)
            .map((r) => `<div class="mi-person"><span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(r.name || "?")} text-white">${M.esc(initials(r.name || r.phone || r.email))}</span><div class="min-w-0"><strong class="text-truncate d-block">${M.esc(r.name || r.phone || r.email)}</strong><small class="text-truncate d-block">${M.esc(r.place || r.role || "")}</small></div>${r.read_at ? '<i class="ri-check-double-line text-success ms-auto" title="Read"></i>' : '<i class="ri-check-line ms-auto" title="Not read yet"></i>'}</div>`)
            .join("")}</div>`
            : ""
        }
      </div>`;
    $("miPane").querySelector("[data-copy]").addEventListener("click", () => copy(b.body));
  }

  // ---- Saved: the message as it goes out, on a phone, with Use / Edit
  function openSaved(id) {
    const t = (data.saved || []).find((x) => x.id === id);
    if (!t) return blank();
    const sample = t.body.replaceAll("{name}", "Stephen").replaceAll("{place}", CTX.place?.name || "").replaceAll("{sender}", CTX.place?.name || "");
    const p = M.smsParts(sample);
    $("miPane").innerHTML = `
      ${head({
        icon: M.CHANNELS[t.channel]?.icon || "ri-bookmark-line",
        color: "purple",
        subject: t.name,
        who: t.subject ? `Subject: <strong>${M.esc(t.subject)}</strong>` : "Saved message",
        chips: [M.channelChip(t.channel), `<span class="soft-chip soft-primary"><i class="ri-text"></i>${p.characters} characters</span>`, `<span class="soft-chip soft-success"><i class="ri-message-2-line"></i>${p.parts} ${p.parts === 1 ? "text" : "texts"}</span>`],
        actions: `<a class="btn btn-primary btn-sm" href="${CTX.baseUrl}/new?template=${t.id}"><i class="ri-send-plane-line me-1"></i>Use it</a><button type="button" class="btn btn-light btn-icon" data-edit title="Edit" aria-label="Edit"><i class="ri-edit-line"></i></button>${copyBtn}`,
      })}
      <div class="mi-pane-body">
        <div class="row g-4 align-items-start">
          <div class="col-xl-7"><article class="mi-letter">${letter(t.body)}</article><p class="mi-note"><b>{name}</b>, <b>{place}</b> and <b>{sender}</b> are filled in for each person.</p></div>
          <div class="col-xl-5"><div class="pb-section-title text-center">On a phone</div><div class="nw-phone"><div class="nw-phone-from">${M.esc(CTX.place?.name || "")}</div><div class="nw-bubble">${M.esc(sample)}</div></div><div class="nw-seg${p.parts > 1 ? " is-over" : ""}"><b>${p.characters}</b> characters · <b>${p.parts}</b> ${p.parts === 1 ? "text" : "texts"}</div></div>
        </div>
      </div>`;
    $("miPane").querySelector("[data-edit]").addEventListener("click", () => editTemplate(t));
    $("miPane").querySelector("[data-copy]").addEventListener("click", () => copy(t.body));
  }

  async function copy(text) {
    try {
      await navigator.clipboard.writeText(text);
      Toast.success("Copied");
    } catch (e) {
      Toast.error("Couldn't copy - select the text instead");
    }
  }

  function editTemplate(t) {
    $("tplModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="tplModal" tabindex="-1" aria-labelledby="tplTitle">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header">
            <span class="app-modal-icon bg-purple text-white"><i class="ri-bookmark-line"></i></span>
            <div class="flex-fill"><h5 class="modal-title" id="tplTitle">${t ? "Edit saved message" : "New saved message"}</h5><div class="app-modal-subtitle">Use it again in one click when you send</div></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-4">
              <div class="col-lg-7"><div class="row g-3">
                <div class="col-md-7"><label class="form-label" for="tName">Name</label><input class="form-control" id="tName" maxlength="80" value="${M.esc(t?.name || "")}" placeholder="e.g. Report reminder"></div>
                <div class="col-md-5"><label class="form-label" for="tChannel">Usually sent by</label><select class="form-select" id="tChannel">${Object.entries(M.CHANNELS).map(([k, c]) => `<option value="${k}"${(t?.channel || "sms") === k ? " selected" : ""}>${c.label}</option>`).join("")}</select></div>
                <div class="col-12"><label class="form-label" for="tSubject">Subject (email and Inbox)</label><input class="form-control" id="tSubject" maxlength="120" value="${M.esc(t?.subject || "")}"></div>
                <div class="col-12"><label class="form-label" for="tBody">Message</label><textarea class="form-control" id="tBody" rows="7" maxlength="10000" placeholder="Dear {name}, ...">${M.esc(t?.body || "")}</textarea>
                  <div class="d-flex flex-wrap gap-1 mt-2">${[["{name}", "Their name"], ["{place}", "Their place"], ["{sender}", "Who it's from"]].map(([v, l]) => `<button type="button" class="btn btn-light border btn-sm py-0 px-2 fs-11" data-token="${v}" title="${v}">${l}</button>`).join("")}</div>
                </div>
              </div></div>
              <!-- v1-events' template writer: the message as it lands on a phone -->
              <div class="col-lg-5">
                <div class="pb-section-title">On a phone</div>
                <div class="nw-phone"><div class="nw-phone-from">${M.esc(CTX.place?.name || "")}</div><div class="nw-bubble" id="tBubble"></div></div>
                <div class="nw-seg" id="tCount"></div>
              </div>
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
      const text = ($("tBody").value || "Your message shows here.").replaceAll("{name}", "Stephen").replaceAll("{place}", CTX.place?.name || "").replaceAll("{sender}", CTX.place?.name || "");
      const p = M.smsParts(text);
      $("tBubble").textContent = text;
      $("tCount").className = `nw-seg${p.parts > 1 ? " is-over" : ""}`;
      $("tCount").innerHTML = `<b>${p.characters}</b> characters · <b>${p.parts}</b> ${p.parts === 1 ? "text" : "texts"}${p.unicode ? " (special characters make texts shorter)" : ""}`;
    };
    $("tBody").addEventListener("input", count);
    el.querySelectorAll("[data-token]").forEach((b) =>
      b.addEventListener("click", () => {
        const f = $("tBody");
        const [a, z] = [f.selectionStart ?? f.value.length, f.selectionEnd ?? f.value.length];
        f.value = f.value.slice(0, a) + b.dataset.token + f.value.slice(z);
        f.focus();
        f.selectionStart = f.selectionEnd = a + b.dataset.token.length;
        count();
      }),
    );
    count();
    const reload = async (keepId) => {
      const res = await MessagesAPI.templates();
      if (res.ok) data.saved = res.data;
      renderFigures();
      renderList();
      if (keepId && data.saved.some((x) => x.id === keepId)) open(keepId, { slide: false });
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
    document.querySelectorAll("#msgTabs [data-tab]").forEach((b) =>
      b.addEventListener("click", () => {
        if (b.dataset.tab !== state.tab && data.inbox) selectTab(b.dataset.tab);
      }),
    );
    // One click handler for the list: open a row, a "from" chip, a new saved message.
    $("miInbox").addEventListener("click", (ev) => {
      const row = ev.target.closest("[data-open]");
      if (row) return open(Number(row.dataset.open));
      const f = ev.target.closest("[data-from]");
      if (f) {
        state.from = f.dataset.from;
        renderFrom();
        return renderList();
      }
      if (ev.target.closest("[data-new-tpl]")) return editTemplate(null);
      if (ev.target.closest("[data-back]")) {
        $("miInbox").classList.remove("is-reading");
        state.open = null;
        syncUrl();
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
