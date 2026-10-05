/**
 * ============================================================================
 * MESSAGES - shared look (Inbox, Send, Sent, a message)
 * ============================================================================
 * Channel and status words and colours, who a message is from, the SMS part
 * counter (the server's rule), and a small confirm window.
 * ============================================================================
 */
const MessagesUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");
  const num = (v) => (v == null ? "-" : Number(v).toLocaleString());

  const CHANNELS = {
    app: { label: "In the app", icon: "ri-notification-3-line", color: "primary" },
    sms: { label: "SMS", icon: "ri-message-2-line", color: "success" },
    email: { label: "Email", icon: "ri-mail-line", color: "purple" },
    both: { label: "SMS and email", icon: "ri-mail-send-line", color: "pink" },
  };
  const STATUS = {
    scheduled: { label: "Scheduled", color: "secondary", icon: "ri-time-line" },
    sending: { label: "Sending", color: "primary", icon: "ri-loader-4-line" },
    sent: { label: "Sent", color: "success", icon: "ri-checkbox-circle-line" },
    cancelled: { label: "Cancelled", color: "danger", icon: "ri-close-circle-line" },
  };
  const FROM = { diocese: { color: "primary", icon: "ri-building-4-line" }, region: { color: "purple", icon: "ri-map-2-line" }, church: { color: "success", icon: "ri-home-heart-line" } };
  const DELIVERY = {
    sent: ["Sent", "success"],
    logged: ["Logged", "primary"],
    failed: ["Failed", "danger"],
    skipped: ["No contact", "secondary"],
  };

  const channelChip = (c) => {
    const m = CHANNELS[c] || CHANNELS.app;
    return `<span class="soft-chip soft-${m.color}"><i class="${m.icon}"></i>${m.label}</span>`;
  };
  const statusPill = (s) => {
    const m = STATUS[s] || STATUS.sent;
    return UI.pill(m.label, m.color, m.icon);
  };
  const delivery = (s) => {
    if (!s) return "-";
    const [label, color] = DELIVERY[s] || [s, "secondary"];
    return UI.pill(label, color);
  };
  const when = (iso, withTime = true) => {
    if (!iso) return "-";
    const d = new Date(iso);
    return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: d.getFullYear() === new Date().getFullYear() ? undefined : "numeric" }) + (withTime ? `, ${d.toLocaleTimeString("en-GB", { hour: "numeric", minute: "2-digit" })}` : "");
  };
  const ago = (iso) => {
    const s = (Date.now() - new Date(iso)) / 1000;
    if (s < 60) return "just now";
    if (s < 3600) return `${Math.round(s / 60)} min ago`;
    if (s < 86400) return `${Math.round(s / 3600)} h ago`;
    return when(iso, false);
  };

  const GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";
  /** The same rule as the server: 160 (153 a part) in plain GSM text, 70 (67) otherwise. */
  function smsParts(text) {
    const chars = [...(text || "")];
    const gsm = chars.every((c) => GSM.includes(c));
    const [single, part] = gsm ? [160, 153] : [70, 67];
    const n = chars.length;
    return { characters: n, parts: n === 0 ? 0 : n <= single ? 1 : Math.ceil(n / part), unicode: !gsm, left: n <= single ? single - n : part - (n % part || part) };
  }

  function ask({ title, text, icon = "ri-question-line", color = "primary", action = "OK", actionColor = "primary" }) {
    document.getElementById("msgAskModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="msgAskModal" tabindex="-1" aria-labelledby="msgAskTitle">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <div class="modal-header">
            <span class="app-modal-icon bg-${color} ${textOn(color)}"><i class="${icon}"></i></span>
            <div class="flex-fill"><h5 class="modal-title" id="msgAskTitle">${esc(title)}</h5></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body"><p class="mb-0">${text}</p></div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Not now</button><button type="button" class="btn btn-${actionColor}" id="msgAskGo">${action}</button></div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById("msgAskModal");
    const modal = new bootstrap.Modal(el);
    return new Promise((resolve) => {
      let answer = false;
      el.querySelector("#msgAskGo").addEventListener("click", () => {
        answer = true;
        modal.hide();
      });
      el.addEventListener("hidden.bs.modal", () => {
        el.remove();
        resolve(answer);
      });
      modal.show();
    });
  }

  function empty(icon, title, text, color = "primary") {
    return `<div class="msg-empty"><span class="avatar avatar-lg avatar-rounded bg-${color} ${textOn(color)} mb-2"><i class="${icon} fs-20"></i></span><h6 class="mb-1">${title}</h6><p class="mb-0">${text}</p></div>`;
  }

  return { esc, textOn, num, CHANNELS, STATUS, FROM, channelChip, statusPill, delivery, when, ago, smsParts, ask, empty };
})();
