/**
 * The Messages pages' setting for code shared with Settings > Communication
 * (2026-10-08): Templates (settings/sections/templates.js) and the Message log
 * (settings/sections/messages.js) were written for the Settings page, where
 * SettingsAPI, SettingsRail, SettingsHub and SettingsFields exist. Here the
 * same names give them what they need - the place, the level, a card - and
 * an API that goes to the Messages endpoints (Messages rights, not Settings).
 */
(function () {
  "use strict";

  const CTX = window.MESSAGES_CTX || {};
  const call = (method, path, body) => MessagesAPI.call(method, path, body);
  const esc = window.MessageFrames.esc;
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");

  window.SettingsRail = window.SettingsRail || { data: { level: CTX.level, place: CTX.place } };
  window.SettingsHub = window.SettingsHub || { ctx: { level: CTX.level } };
  window.SettingsFields = window.SettingsFields || {
    esc,
    textOn,
    extras: {},
    card: ({ id, title, icon = "ri-settings-3-line", colour = "primary", sub = "", actions = "", body = "", footer = "", cls = "" }) => `
      <div class="card custom-card settings-card ${cls}" id="${esc(id)}">
        <div class="card-header justify-content-between flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm bg-${colour} ${textOn(colour)}"><i class="${icon}"></i></span>
            <div><div class="card-title mb-0">${esc(title)}</div>${sub ? `<div class="settings-card-sub">${sub}</div>` : ""}</div>
          </div>
          ${actions ? `<div class="d-flex align-items-center gap-2">${actions}</div>` : ""}
        </div>
        <div class="card-body">${body}</div>
        ${footer ? `<div class="card-footer">${footer}</div>` : ""}
      </div>`,
  };
  window.SettingsAPI = window.SettingsAPI || {
    templates: () => call("GET", "/messages/templates"),
    saveTemplate: (id, body) => call(id ? "PUT" : "POST", id ? `/messages/templates/${id}` : "/messages/templates", body),
    deleteTemplate: (id) => call("DELETE", `/messages/templates/${id}`),
    copyTemplate: (id) => call("POST", `/messages/templates/${id}/copy`),
    resetTemplate: (id) => call("POST", `/messages/templates/${id}/reset`),
    previewTemplate: (subject, body) => call("POST", "/messages/templates/preview", { subject, body }),
    testTemplate: (channel, subject, body) => call("POST", "/messages/templates/test", { channel, subject, body }),
    campaigns: () => call("GET", "/messages/sent"),
    messages: () => call("GET", "/messages/log"),
    message: (id) => call("GET", `/messages/log/${id}`),
    resendMessage: (id) => call("POST", `/messages/log/${id}/resend`),
  };
})();
