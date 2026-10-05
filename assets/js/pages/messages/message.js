/**
 * ============================================================================
 * MESSAGES - one sent message (church, region, diocese)
 * ============================================================================
 * What was sent and to whom, how each person's SMS and email went, who read
 * it in the app, the replies - with Send again (to the ones that failed) and
 * Cancel (while scheduled).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const CTX = window.MESSAGES_CTX;
  const $ = (id) => document.getElementById(id);
  const id = Number(new URLSearchParams(window.location.search).get("id"));
  let b = null;
  let poll = null;

  function render() {
    const canAct = CTX.can.send;
    const actions = [
      b.status === "scheduled" && canAct ? `<button class="btn btn-outline-danger" data-act="cancel"><i class="ri-close-circle-line me-1"></i>Don't send it</button>` : "",
      b.status === "sent" && b.failed_count && canAct ? `<button class="btn btn-primary" data-act="retry"><i class="ri-restart-line me-1"></i>Send again to ${b.failed_count} that didn't go</button>` : "",
      canAct ? `<a class="btn btn-outline-primary" href="${CTX.baseUrl}/new?${new URLSearchParams({ channel: b.channel, subject: b.subject || "", body: b.body })}"><i class="ri-file-copy-line me-1"></i>Send a copy</a>` : "",
    ].join("");
    const chan = M.CHANNELS[b.channel] || M.CHANNELS.app;
    $("msgHero").innerHTML = `
      <div class="card-body">
        <div class="ev-hero-row">
          <span class="avatar avatar-lg avatar-rounded bg-${chan.color} text-white flex-shrink-0"><i class="${chan.icon} fs-20"></i></span>
          <div class="flex-fill" style="min-width:0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${M.esc(b.subject || b.preview.slice(0, 60))}</h2>${M.statusPill(b.status)}</div>
            <div class="ev-card-meta">
              ${M.channelChip(b.channel)}
              <span><i class="ri-group-line"></i>${M.esc(b.summary || "")}</span>
              <span><i class="ri-time-line"></i>${b.status === "scheduled" ? `For ${M.when(b.scheduled_at)}` : M.when(b.sent_at || b.created_at)}${b.by ? ` · by ${M.esc(b.by)}` : ""}</span>
            </div>
          </div>
          <div class="ev-hero-actions">${actions}</div>
        </div>
      </div>`;

    const sms = b.channel === "sms" || b.channel === "both";
    const cards = [
      { icon: "ri-group-line", label: "People", value: M.num(b.recipient_count), color: "primary", sub: `${M.num(b.with_login)} with a login` },
      { icon: "ri-checkbox-circle-line", label: "Reached", value: M.num(b.sent_count), color: "success", sub: b.status === "sending" ? "Still sending..." : b.status === "scheduled" ? "Not sent yet" : "By at least one way" },
      { icon: "ri-error-warning-line", label: "Didn't go", value: M.num(b.failed_count), color: "danger", sub: b.failed_count ? "See who below" : "None" },
      { icon: "ri-eye-line", label: "Read in the app", value: M.num(b.read), color: "purple", sub: `${M.num(b.replies)} ${b.replies === 1 ? "reply" : "replies"}` },
    ];
    $("statCardsRow").innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");

    const tbody = $("recipientTable").tBodies[0];
    tbody.innerHTML = b.recipients
      .map(
        (r) => `<tr>
          <td><div class="fw-semibold">${M.esc(r.name || r.phone || r.email)}</div><small>${M.esc(r.role || (r.has_login ? "" : "Typed in"))}</small></td>
          <td>${M.esc(r.place || "-")}</td>
          <td data-order="${r.sms_status || ""}">${sms ? `${M.delivery(r.sms_status)}${r.phone ? `<div class="fs-12">${M.esc(r.phone)}</div>` : ""}` : "-"}</td>
          <td data-order="${r.email_status || ""}">${b.channel === "email" || b.channel === "both" ? `${M.delivery(r.email_status)}${r.email ? `<div class="fs-12 text-break">${M.esc(r.email)}</div>` : ""}` : "-"}</td>
          <td>${r.has_login ? (r.read_at ? UI.pill(`Read ${M.when(r.read_at)}`, "purple", "ri-eye-line") : UI.pill("Not read yet", "primary")) : "-"}${r.error ? `<div class="fs-12 text-danger">${M.esc(r.error)}</div>` : ""}</td>
        </tr>`,
      )
      .join("");
    const filters = [{ id: "fDelivery", label: "Any result", columnIndex: 2, options: [{ value: "Failed", label: "Didn't go (SMS)" }, { value: "Sent", label: "Sent (SMS)" }, { value: "No contact", label: "No phone" }] }];
    UI.renderFilterToolbar("recipientFilters", { searchPlaceholder: "Search people or places...", filters });
    const table = UI.initListDataTable("recipientTable", { hideDefaultSearch: true, noun: "people", pageLength: 25 });
    UI.wireFilterToolbar("recipientFilters", table, filters, { noun: "people", urlSync: false });

    const p = M.smsParts(b.body);
    $("msgSide").innerHTML = `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">The message</div>${sms ? `<span class="soft-chip soft-primary ms-auto">${p.parts} ${p.parts === 1 ? "SMS" : "SMS parts"}</span>` : ""}</div>
        <div class="card-body"><div class="msg-bubble is-them"><p class="mb-0">${M.esc(b.body)}</p></div></div>
      </div>
      <div class="card custom-card">
        <div class="card-header justify-content-between"><div class="card-title">Replies</div><span class="soft-chip soft-purple">${b.replies_list.length}</span></div>
        <div class="card-body">
          ${b.replies_list.length
            ? `<ul class="mr-thread">${b.replies_list.map((r) => `<li><span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(r.who)} text-white">${M.esc((r.who || "?").charAt(0))}</span><div><strong>${M.esc(r.who)}</strong><small>${M.esc(r.place || "")} · ${M.when(r.at)}</small><p class="mb-0">${M.esc(r.body)}</p></div></li>`).join("")}</ul>`
            : `<p class="fw-semibold mb-0">No replies yet. People with a login can reply from their Inbox.</p>`}
        </div>
      </div>`;

    clearTimeout(poll);
    if (b.status === "sending") poll = setTimeout(load, 3000);
  }

  async function act(name) {
    if (name === "cancel") {
      const ok = await M.ask({ title: "Don't send it?", text: "It won't be sent. You can send a copy later.", icon: "ri-close-circle-line", color: "danger", action: "Don't send it", actionColor: "danger" });
      if (!ok) return;
      const res = await MessagesAPI.cancel(id);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      b = res.data;
      return render();
    }
    if (name === "retry") {
      const res = await MessagesAPI.retry(id);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      b = res.data;
      render();
    }
  }

  async function load() {
    const res = await MessagesAPI.get(id);
    if (!res.ok) {
      $("messagePage").innerHTML = `<div class="card custom-card"><div class="card-body">${M.empty("ri-chat-off-line", "This message isn't one you can see", M.esc(res.message), "danger")}<div class="text-center mt-3"><a class="btn btn-primary" href="${CTX.baseUrl}/?tab=sent">Back to Sent</a></div></div></div>`;
      return;
    }
    b = res.data;
    document.title = `${b.subject || "Message"} - Makueni West Diocese`;
    render();
  }

  document.addEventListener("DOMContentLoaded", () => {
    if (!id) {
      window.location.href = `${CTX.baseUrl}/?tab=sent`;
      return;
    }
    const flash = sessionStorage.getItem("mwd-messages-flash");
    if (flash) {
      sessionStorage.removeItem("mwd-messages-flash");
      Toast.success(flash);
    }
    document.addEventListener("click", (e) => {
      const a = e.target.closest("[data-act]");
      if (a) act(a.dataset.act);
    });
    load();
  });
})();
