/**
 * SETTINGS - Communication > Campaigns (2026-10-08): send a campaign from
 * here - the composer (/{level}/messages/new, v1-events' Send Campaign) -
 * and the latest ones as cards: who it reached, read, failed, and how it went.
 * A card opens the message's own page.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = window.SettingsFields.esc;

  const STATUS = {
    scheduled: (b) => `<span class="soft-chip soft-warning"><i class="ri-time-line"></i>Scheduled</span>`,
    sending: () => `<span class="soft-chip soft-primary"><i class="ri-loader-4-line"></i>Sending</span>`,
    cancelled: () => `<span class="soft-chip soft-secondary"><i class="ri-close-circle-line"></i>Cancelled</span>`,
    sent: (b) => (b.failed_count ? UI.pill(`${b.failed_count} failed`, "danger", "ri-error-warning-line") : UI.pill("Sent", "success", "ri-check-line")),
  };
  const day = (iso) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");
  const time = (iso) => (iso ? new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }) : "");
  const level = () => SettingsRail.data?.level || "church";
  const base = () => (typeof AppConfig !== "undefined" ? AppConfig.FRONTEND_BASE_URL : "") + `/${level()}/messages`;

  function figure(icon, c, value, label, soft) {
    return `<div class="col-6 col-xl-3"><div class="cm-figure"><span class="ev-tile${soft ? " is-soft" : ""}" style="--q: var(--${c}-rgb)"><i class="${icon}"></i></span><div><b>${value}</b><span>${label}</span></div></div></div>`;
  }

  function campaignCard(b) {
    const ch = window.CommsTemplates?.CH[b.channel] || { label: b.channel_label, icon: "ri-chat-3-line", c: "primary" };
    const reached = b.recipient_count ? Math.round(((b.recipient_count - (b.failed_count || 0)) / b.recipient_count) * 100) : 0;
    const when = b.status === "scheduled" ? b.scheduled_at : b.sent_at || b.created_at;
    return `
      <div class="col-xxl-4 col-md-6">
        <a class="cm-camp" href="${base()}/message?id=${b.id}">
          <div class="cm-camp-top">
            <span class="badge bg-${ch.c} list-pill"><i class="${ch.icon} me-1"></i>${esc(ch.label)}</span>
            ${(STATUS[b.status] || STATUS.sent)(b)}
          </div>
          <h3 class="cm-camp-title">${esc(b.subject || b.preview || "Message")}</h3>
          ${b.subject ? `<p class="cm-camp-text">${esc(b.preview || "")}</p>` : ""}
          <div class="cm-camp-meter" title="${reached}% reached"><span style="width:${reached}%"></span></div>
          <div class="cm-camp-facts">
            <span><i class="ri-group-line"></i>${b.recipient_count} ${b.recipient_count === 1 ? "person" : "people"}</span>
            <span><i class="ri-reply-line"></i>${b.replies || 0} ${b.replies === 1 ? "reply" : "replies"}</span>
            <span class="ms-auto"><i class="ri-calendar-line"></i>${day(when)} · ${time(when)}</span>
          </div>
          ${b.summary ? `<div class="cm-camp-who">To ${esc(b.summary)}${b.by ? ` · by ${esc(b.by)}` : ""}</div>` : ""}
        </a>
      </div>`;
  }

  window.CommsCampaigns = {
    /** Draws the Campaigns tab; resolves to this month's count (for the tab's figure). */
    async mount(host, { canSend, openTemplates }) {
      host.innerHTML = `<div class="row g-3">${UI.skeletonCards(3, "col-md-4")}</div>`;
      const res = await SettingsAPI.campaigns();
      if (!res.ok) {
        host.innerHTML = `<div class="cm-locked"><span class="ev-tile is-soft" style="--q: var(--danger-rgb)"><i class="ri-lock-line"></i></span><div><h3>Campaigns are for people who send messages</h3><p>${esc(res.message)}</p></div></div>`;
        return null;
      }
      const { figures: f, items } = res.data;
      const recent = items.slice(0, 6);
      host.innerHTML = `
        <div class="cm-launch">
          <div class="cm-launch-text">
            <span class="ev-tile" style="--q: var(--success-rgb)"><i class="ri-broadcast-line"></i></span>
            <div>
              <h3>Send a campaign</h3>
              <p>Pick who - your people, a group, or the places below - write it or start from a template, and send it by SMS, email or in the app. Send now or schedule it.</p>
            </div>
          </div>
          <div class="cm-launch-actions">
            ${canSend ? `<a class="btn btn-primary btn-lg" href="${base()}/new"><i class="ri-send-plane-line me-1"></i>Send a campaign</a>` : ""}
            <button type="button" class="btn btn-light border btn-lg" id="cmCampTpl"><i class="ri-file-list-3-line me-1"></i>Start from a template</button>
          </div>
        </div>

        <div class="row g-3 mb-4">
          ${figure("ri-send-plane-line", "primary", f.sent, "Sent this month")}
          ${figure("ri-group-line", "purple", f.people, "People reached", true)}
          ${figure("ri-reply-line", "success", f.replies, "Replies")}
          ${figure(f.failed ? "ri-error-warning-line" : "ri-time-line", f.failed ? "danger" : "warning", f.failed ? f.failed : f.scheduled, f.failed ? "Didn't arrive" : "Scheduled", !f.failed)}
        </div>

        <div class="cm-group-head">
          <span class="ev-tile is-soft" style="--q: var(--pink-rgb)"><i class="ri-history-line"></i></span>
          <div class="flex-fill"><h3>Recent campaigns</h3><p>${items.length ? `The latest ${recent.length} of ${items.length} this year` : "Nothing sent this year yet"}</p></div>
          <a class="btn btn-sm btn-light border" href="${base()}/">All messages<i class="ri-arrow-right-line ms-1"></i></a>
        </div>
        ${recent.length ? `<div class="row g-3">${recent.map(campaignCard).join("")}</div>` : `<div class="cm-group-empty"><i class="ri-broadcast-line"></i>Your campaigns show here once you send one.</div>`}`;
      host.querySelector("#cmCampTpl").addEventListener("click", openTemplates);
      return f.sent;
    },
  };
})();
