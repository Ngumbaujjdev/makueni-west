/**
 * Message frames (2026-10-08): a text message on a phone and an email in a
 * mail app, as people really see them. Shared by Settings > Communication
 * (Templates, Sending, the Log) and Send a message. No dependencies.
 *   MessageFrames.phoneHtml({ sender, text, time, day })
 *   MessageFrames.mailHtml({ subject, fromName, fromAddr, to, time }) - with a frame for the email's HTML
 *   MessageFrames.fitFrame(iframe) - grow a frame to its email
 * data-pv hooks let a live preview fill them in.
 */
const MessageFrames = (function () {
  "use strict";

  const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const logo = () => `${typeof AppConfig !== "undefined" ? AppConfig.FRONTEND_BASE_URL : ""}/assets/images/brand-logos/toggle-logo.png`;
  const statusBar = (time = "9:41") => `<div class="cm-phone-status"><b>${esc(time)}</b><span><i class="ri-signal-wifi-3-fill"></i><i class="ri-wifi-fill"></i><i class="ri-battery-2-fill"></i></span></div>`;
  const initials = (s) => (String(s || "").match(/[A-Za-z0-9]+/g) || []).slice(0, 2).map((w) => w[0].toUpperCase()).join("");

  function phoneHtml({ sender = "", text = "", time = "9:41", day = "Today" } = {}) {
    return `
      <div class="cm-phone">
        <div class="cm-phone-screen">
          ${statusBar(time)}
          <div class="cm-sms-head">
            <i class="ri-arrow-left-s-line"></i>
            <span class="cm-sms-avatar" data-pv="initials">${initials(sender) || '<i class="ri-user-3-fill"></i>'}</span>
            <div class="cm-sms-who"><b data-pv="sender">${esc(sender || "Your sender ID")}</b><small>Text message</small></div>
            <i class="ri-phone-line ms-auto"></i>
          </div>
          <div class="cm-sms-thread">
            <div class="cm-sms-day">${esc(day)} ${esc(time)}</div>
            <div class="cm-sms-bubble" data-pv="bubble">${esc(text)}</div>
            <div class="cm-sms-time">${esc(time)}</div>
          </div>
          <div class="cm-sms-input"><span>Text message</span><i class="ri-send-plane-2-fill"></i></div>
        </div>
      </div>`;
  }

  function mailHtml({ subject = "", fromName = "", fromAddr = "", to = "Stephen Mutua", time = "9:41 AM" } = {}) {
    return `
      <div class="cm-mailapp" data-pv="mailapp">
        ${statusBar("9:41")}
        <div class="cm-mailapp-bar">
          <span class="cm-mailapp-dots"><i></i><i></i><i></i></span>
          <span class="cm-mailapp-back"><i class="ri-arrow-left-s-line"></i>Inbox</span>
          <span class="cm-mailapp-tools"><i class="ri-archive-line"></i><i class="ri-delete-bin-6-line"></i><i class="ri-mail-unread-line"></i><i class="ri-more-2-fill"></i></span>
        </div>
        <h4 class="cm-mailapp-subject" data-pv="subject">${esc(subject) || "&nbsp;"}</h4>
        <div class="cm-mailapp-from">
          <img class="cm-mailapp-avatar" src="${logo()}" alt="">
          <div class="cm-mailapp-who"><div><b data-pv="fromName">${esc(fromName) || "&nbsp;"}</b> <span data-pv="fromAddr">${fromAddr ? esc(`<${fromAddr}>`) : ""}</span></div><small>to ${esc(to)}</small></div>
          <span class="cm-mailapp-time">${esc(time)}</span>
        </div>
        <div class="cm-mailapp-body">
          <iframe class="cm-frame" sandbox="allow-same-origin" title="How the email looks" data-pv="frame"></iframe>
          <div class="cm-frame-loading" data-pv="loading" hidden><span class="spinner-border spinner-border-sm text-primary"></span></div>
        </div>
      </div>`;
  }

  function fitFrame(frame) {
    try {
      const doc = frame.contentDocument;
      if (doc?.body) frame.style.height = `${Math.max(320, doc.documentElement.scrollHeight)}px`;
    } catch (e) {
      /* a frame we can't measure keeps its height */
    }
  }

  /** Fit once loaded - and again as a window settles. */
  function autoFit(frame) {
    frame.addEventListener("load", () => [0, 200, 600].forEach((ms) => setTimeout(() => fitFrame(frame), ms)));
  }

  return { phoneHtml, mailHtml, fitFrame, autoFit, initials, logo, esc };
})();

window.MessageFrames = MessageFrames;
