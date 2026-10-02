/**
 * The diocese's notice banner (Settings > Maintenance): when a global admin
 * sets a notice, every signed-in page shows it at the top of the content -
 * e.g. "The system will be down for updates on Saturday from 6 to 8 am."
 * Read from GET /settings/notice, cached for 5 minutes per tab. A person can
 * close it; it comes back when the message changes.
 *
 * Dependencies: AppConfig, Constants (both loaded before the header).
 */
(function () {
  "use strict";

  const CACHE_KEY = "mwd_system_notice";
  const CLOSED_KEY = "mwd_system_notice_closed";
  const MAX_AGE = 5 * 60 * 1000;

  const store = {
    get(key) {
      try {
        return sessionStorage.getItem(key);
      } catch (e) {
        return null;
      }
    },
    set(key, value) {
      try {
        sessionStorage.setItem(key, value);
      } catch (e) {
        /* private window - the banner just shows again */
      }
    },
  };

  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  async function notice() {
    try {
      const cached = JSON.parse(store.get(CACHE_KEY) || "null");
      if (cached && Date.now() - cached.at < MAX_AGE) return cached.data;
    } catch (e) {
      /* fetch it again */
    }
    if (typeof AppConfig === "undefined" || typeof Constants === "undefined") return null;
    const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
    if (!token) return null;
    try {
      const res = await fetch(`${AppConfig.API_BASE_URL}/settings/notice`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } });
      if (!res.ok) return null;
      const data = (await res.json()).data || null;
      store.set(CACHE_KEY, JSON.stringify({ at: Date.now(), data }));
      return data;
    } catch (e) {
      return null;
    }
  }

  async function show() {
    const data = await notice();
    if (!data || !data.message || store.get(CLOSED_KEY) === data.message) return;
    const host = document.querySelector(".main-content .container-fluid") || document.querySelector(".main-content");
    if (!host || document.getElementById("systemNotice")) return;
    const tone = ["primary", "warning", "danger"].includes(data.tone) ? data.tone : "warning";
    const icon = { primary: "ri-information-line", warning: "ri-alarm-warning-line", danger: "ri-error-warning-line" }[tone];
    host.insertAdjacentHTML(
      "afterbegin",
      `<div class="alert alert-${tone} alert-dismissible d-flex align-items-start gap-2 mt-3 mb-0 system-notice" id="systemNotice" role="status">
        <i class="${icon} fs-18 flex-shrink-0"></i>
        <div><b>Notice from the diocese:</b> ${esc(data.message)}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>`,
    );
    document.getElementById("systemNotice").addEventListener("closed.bs.alert", () => store.set(CLOSED_KEY, data.message));
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", show);
  else show();
})();
