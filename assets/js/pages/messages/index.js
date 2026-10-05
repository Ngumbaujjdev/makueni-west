/**
 * ============================================================================
 * MESSAGES - the Inbox, Sent and Saved messages (church, region, diocese)
 * ============================================================================
 * Inbox: the list with unread dots and a "from" filter, read in place with
 * your replies and a reply box. Sent: this month's figures and every message
 * with how it went. Saved messages: cards to use, edit or remove. The tab
 * (and the open message) stay in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const CTX = window.MESSAGES_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const state = { tab: ["sent", "saved"].includes(params.get("tab")) ? params.get("tab") : "inbox", from: "", open: Number(params.get("open")) || null };
  let inbox = null;
  let sent = null;
  let templates = null;

  function syncUrl() {
    const q = new URLSearchParams();
    if (state.tab !== "inbox") q.set("tab", state.tab);
    if (state.tab === "inbox" && state.open) q.set("open", state.open);
    const qs = q.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
  }

  // ================================================================ inbox
  async function loadInbox() {
    const res = await MessagesAPI.inbox(state.from);
    if (!res.ok) {
      $("inboxList").innerHTML = `<div class="p-3">${M.esc(res.message)}</div>`;
      return;
    }
    inbox = res.data;
    renderInbox();
  }

  function renderInbox() {
    const figure = document.querySelector('[data-tab-figure="inbox"]');
    if (figure) figure.textContent = inbox.unread ? `${inbox.unread} unread` : "All read";
    const filters = [
      ["", "All"],
      ["diocese", "Diocese"],
      ["region", "Region"],
      ["church", "Church"],
    ];
    $("inboxFilters").innerHTML = filters.map(([v, l]) => `<button type="button" class="cal-layer${state.from === v ? " active" : ""}" data-from="${v}" data-colour="primary">${l}</button>`).join("");
    $("inboxFilters").querySelectorAll("[data-from]").forEach((b) =>
      b.addEventListener("click", () => {
        state.from = b.dataset.from;
        loadInbox();
      }),
    );
    $("inboxList").innerHTML = inbox.items.length
      ? `<ul class="msg-list">${inbox.items
          .map((m) => {
            const f = M.FROM[m.from.type] || M.FROM.church;
            return `<li><button type="button" class="msg-item${m.read_at ? "" : " is-unread"}${state.open === m.id ? " is-on" : ""}" data-open="${m.id}">
              <span class="avatar avatar-md avatar-rounded bg-${f.color} text-white flex-shrink-0"><i class="${f.icon}"></i></span>
              <span class="flex-fill text-start" style="min-width:0">
                <span class="d-flex justify-content-between gap-2"><strong class="text-truncate">${M.esc(m.from.name)}</strong><small class="flex-shrink-0">${M.ago(m.at)}</small></span>
                <span class="msg-item-subject text-truncate">${M.esc(m.subject)}</span>
                <span class="msg-item-preview">${M.esc(m.body).slice(0, 90)}</span>
              </span>
              ${m.read_at ? "" : `<span class="msg-dot" aria-label="Unread"></span>`}
            </button></li>`;
          })
          .join("")}</ul>`
      : M.empty("ri-inbox-line", "Nothing here yet", state.from ? "No messages from there. Try All." : "Messages sent to you by your church, region or the diocese show here.");
    $("inboxList").querySelectorAll("[data-open]").forEach((b) => b.addEventListener("click", () => openMessage(Number(b.dataset.open))));
    const first = inbox.items.find((m) => m.id === state.open) || (window.innerWidth >= 992 ? inbox.items[0] : null);
    if (first) openMessage(first.id, false);
    else $("inboxRead").innerHTML = `<div class="card-body">${M.empty("ri-chat-3-line", "Pick a message", "Choose one on the left to read it and reply.")}</div>`;
  }

  async function openMessage(id, scroll = true) {
    const m = inbox.items.find((x) => x.id === id);
    if (!m) return;
    state.open = id;
    syncUrl();
    document.querySelectorAll("#inboxList [data-open]").forEach((b) => b.classList.toggle("is-on", Number(b.dataset.open) === id));
    const f = M.FROM[m.from.type] || M.FROM.church;
    $("inboxRead").innerHTML = `
      <div class="card-header justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2" style="min-width:0">
          <span class="avatar avatar-md avatar-rounded bg-${f.color} text-white"><i class="${f.icon}"></i></span>
          <div style="min-width:0"><div class="card-title msg-subject text-break">${M.esc(m.subject)}</div><small class="fw-semibold">${M.esc(m.from.name)}${m.by ? ` · ${M.esc(m.by)}` : ""} · ${M.when(m.at)}</small></div>
        </div>
        ${M.channelChip(m.channel)}
      </div>
      <div class="card-body">
        <div class="msg-thread">
          <div class="msg-bubble is-them"><p class="mb-0">${M.esc(m.body)}</p></div>
          ${m.my_replies.map((r) => `<div class="msg-bubble is-me"><p class="mb-0">${M.esc(r.body)}</p><small>You · ${M.when(r.at)}</small></div>`).join("")}
        </div>
        <div class="msg-reply mt-3">
          <label class="form-label" for="replyBody">Reply to ${M.esc(m.from.name)}</label>
          <textarea class="form-control" id="replyBody" rows="3" maxlength="2000" placeholder="Your reply goes to them in the app"></textarea>
          <div class="d-flex justify-content-end mt-2"><button type="button" class="btn btn-primary" id="replyBtn"><i class="ri-reply-line me-1"></i>Reply</button></div>
        </div>
      </div>`;
    $("replyBtn").addEventListener("click", async () => {
      const text = $("replyBody").value.trim();
      if (!text) return Toast.warning("Write a reply first.");
      UI.setButtonLoading($("replyBtn"), "Sending...");
      const res = await MessagesAPI.reply(id, text);
      UI.restoreButton($("replyBtn"));
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      Object.assign(m, res.data);
      openMessage(id, false);
    });
    if (scroll && window.innerWidth < 992) $("inboxRead").scrollIntoView({ behavior: "smooth" });
    if (!m.read_at) {
      const res = await MessagesAPI.read(id);
      if (res.ok) {
        m.read_at = res.data.read_at;
        inbox.unread = Math.max(0, inbox.unread - 1);
        document.querySelector(`#inboxList [data-open="${id}"]`)?.classList.remove("is-unread");
        document.querySelector(`#inboxList [data-open="${id}"] .msg-dot`)?.remove();
        const figure = document.querySelector('[data-tab-figure="inbox"]');
        if (figure) figure.textContent = inbox.unread ? `${inbox.unread} unread` : "All read";
      }
    }
  }

  // ================================================================ sent
  async function loadSent() {
    $("sentCardsRow").innerHTML = UI.skeletonCards(4);
    const res = await MessagesAPI.sent(new Date().getFullYear());
    if (!res.ok) {
      $("sentCardsRow").innerHTML = `<div class="col-12"><div class="alert alert-danger">${M.esc(res.message)}</div></div>`;
      return;
    }
    sent = res.data;
    renderSent();
  }

  function renderSent() {
    const f = sent.figures;
    const cards = [
      { icon: "ri-send-plane-line", label: "Sent this month", value: M.num(f.sent), color: "primary", sub: f.scheduled ? `${f.scheduled} more scheduled` : "Nothing scheduled" },
      { icon: "ri-group-line", label: "People reached", value: M.num(f.delivered), color: "success", sub: `of ${M.num(f.people)} this month` },
      { icon: "ri-error-warning-line", label: "Didn't go", value: M.num(f.failed), color: "danger", sub: f.failed ? "Open a message to send them again" : "Everything went" },
      { icon: "ri-reply-line", label: "Replies", value: M.num(f.replies), color: "purple", sub: "To this month's messages" },
    ];
    $("sentCardsRow").innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
    const figure = document.querySelector('[data-tab-figure="sent"]');
    if (figure) figure.textContent = `${f.sent} this month`;
    const tbody = $("sentTable").tBodies[0];
    tbody.innerHTML = sent.items.length
      ? sent.items
          .map(
            (b) => `<tr data-row-id="${b.id}">
              <td style="max-width:22rem;white-space:normal"><a class="fw-semibold" href="${CTX.baseUrl}/message?id=${b.id}">${M.esc(b.subject || b.preview.slice(0, 60))}</a><div class="fs-12">${M.esc(b.subject ? b.preview.slice(0, 80) : "")}</div></td>
              <td style="max-width:16rem;white-space:normal" class="fs-13">${M.esc(b.summary || "")}</td>
              <td>${M.channelChip(b.channel)}</td>
              <td data-order="${b.status}">${M.statusPill(b.status)}</td>
              <td data-order="${b.scheduled_at || b.sent_at || b.created_at}">${b.status === "scheduled" ? `For ${M.when(b.scheduled_at)}` : M.when(b.sent_at || b.created_at)}</td>
              <td class="text-end">${M.num(b.sent_count)} of ${M.num(b.recipient_count)}${b.failed_count ? ` <span class="soft-chip soft-danger">${b.failed_count} didn't go</span>` : ""}</td>
              <td class="text-end">${b.replies ? `<span class="soft-chip soft-purple"><i class="ri-reply-line"></i>${b.replies}</span>` : "-"}</td>
            </tr>`,
          )
          .join("")
      : `<tr><td colspan="7" class="text-center py-5">${M.empty("ri-send-plane-line", "Nothing sent yet", "Send a message to your leaders or the places below - it shows here with how it went.")}</td></tr>`;
    const filters = [{ id: "fChannel", label: "Any way", columnIndex: 2, options: Object.values(M.CHANNELS).map((c) => ({ value: c.label, label: c.label })) }];
    UI.renderFilterToolbar("sentFilters", { searchPlaceholder: "Search messages...", filters });
    const table = UI.initListDataTable("sentTable", { hideDefaultSearch: true, order: [[4, "desc"]], noun: "messages" });
    UI.wireFilterToolbar("sentFilters", table, filters, { noun: "messages", urlSync: false });
  }

  // ================================================================ saved messages
  async function loadSaved() {
    const res = await MessagesAPI.templates();
    if (!res.ok) {
      $("savedGrid").innerHTML = `<div class="col-12"><div class="alert alert-danger">${M.esc(res.message)}</div></div>`;
      return;
    }
    templates = res.data;
    renderSaved();
  }

  function renderSaved() {
    const figure = document.querySelector('[data-tab-figure="saved"]');
    if (figure) figure.textContent = `${templates.length} saved`;
    $("savedGrid").innerHTML = templates.length
      ? templates
          .map((t) => {
            const parts = M.smsParts(t.body);
            return `<div class="col-xxl-4 col-md-6"><div class="card custom-card h-100 msg-saved">
              <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2"><h6 class="mb-0 fw-bold text-break">${M.esc(t.name)}</h6>${M.channelChip(t.channel)}</div>
                ${t.subject ? `<div class="fw-semibold fs-13 mb-1">${M.esc(t.subject)}</div>` : ""}
                <p class="msg-saved-body flex-fill">${M.esc(t.body)}</p>
                <div class="d-flex align-items-center gap-2 mt-2">
                  <small class="me-auto fw-semibold">${parts.characters} characters · ${parts.parts} SMS</small>
                  <button type="button" class="btn btn-sm btn-light" data-edit="${t.id}"><i class="ri-edit-line me-1"></i>Edit</button>
                  <a class="btn btn-sm btn-primary" href="${CTX.baseUrl}/new?template=${t.id}"><i class="ri-send-plane-line me-1"></i>Use</a>
                </div>
              </div></div></div>`;
          })
          .join("")
      : `<div class="col-12"><div class="card custom-card"><div class="card-body">${M.empty("ri-bookmark-line", "No saved messages", "Save a message you send often - a reminder, a greeting - and use it again in one click.", "purple")}</div></div></div>`;
    $("savedGrid").querySelectorAll("[data-edit]").forEach((b) => b.addEventListener("click", () => editTemplate(templates.find((t) => t.id === Number(b.dataset.edit)))));
  }

  function editTemplate(t) {
    document.getElementById("tplModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="tplModal" tabindex="-1" aria-labelledby="tplTitle">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header">
            <span class="app-modal-icon bg-purple text-white"><i class="ri-bookmark-line"></i></span>
            <div class="flex-fill"><h5 class="modal-title" id="tplTitle">${t ? "Edit saved message" : "New saved message"}</h5></div>
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
    $("tSave").addEventListener("click", async () => {
      UI.setButtonLoading($("tSave"), "Saving...");
      const res = await MessagesAPI.saveTemplate(t?.id, { name: $("tName").value.trim(), channel: $("tChannel").value, subject: $("tSubject").value.trim() || null, body: $("tBody").value });
      UI.restoreButton($("tSave"));
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      modal.hide();
      loadSaved();
    });
    $("tDelete")?.addEventListener("click", async () => {
      const res = await MessagesAPI.deleteTemplate(t.id);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      modal.hide();
      loadSaved();
    });
    el.addEventListener("hidden.bs.modal", () => el.remove());
    modal.show();
  }

  // ================================================================ tabs
  function show(tab) {
    state.tab = tab;
    document.querySelectorAll("#msgTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    document.querySelectorAll(".msg-pane").forEach((p) => (p.hidden = p.dataset.pane !== tab));
    syncUrl();
    if (tab === "inbox" && !inbox) loadInbox();
    if (tab === "sent" && !sent) loadSent();
    if (tab === "saved" && !templates) loadSaved();
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("#msgTabs [data-tab]").forEach((b) => b.addEventListener("click", () => b.dataset.tab !== state.tab && show(b.dataset.tab)));
    $("newTemplateBtn")?.addEventListener("click", () => editTemplate(null));
    if (!document.querySelector(`#msgTabs [data-tab="${state.tab}"]`)) state.tab = "inbox";
    show(state.tab);
    // The other tabs' figures, quietly.
    if (state.tab !== "inbox") loadInbox();
    if (CTX.can.read && state.tab !== "sent") MessagesAPI.sent(new Date().getFullYear()).then((r) => r.ok && (document.querySelector('[data-tab-figure="sent"]').textContent = `${r.data.figures.sent} this month`));
  });
})();
