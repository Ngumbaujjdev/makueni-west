/**
 * Communication > Messages > Message log (2026-10-08): every email and SMS
 * that went out - Settings > Communication's log (settings/sections/
 * messages.js) on its own page, through GET /messages/log (Messages rights).
 */
document.addEventListener("DOMContentLoaded", () => window.SettingsMessages.mount(document.getElementById("logHost")));
