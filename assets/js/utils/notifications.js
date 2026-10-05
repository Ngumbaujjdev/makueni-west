/**
 * ============================================================================
 * The header bell (docs/specs/events-initiatives-spec.md, foundation)
 * ============================================================================
 * Shows the signed-in person's unread count and latest notifications from
 * GET /notifications, refreshed every minute while the tab is visible.
 * Opening one marks it read and goes where it's about; "Mark all read"
 * clears the count. Also exposes window.MwdNotifications for other pages
 * (e.g. the Notifications page) to share the API calls and refresh the bell.
 * Dependencies: AppConfig, Constants (loaded before the header).
 * ============================================================================
 */
const MwdNotifications = (function () {
  "use strict";

  const POLL_MS = 60 * 1000;
  const base = () => window.mwdBaseUrl || "";
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  let timer = null;

  function headers() {
    const h = { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* the API uses the primary role */
    }
    return h;
  }

  async function call(method, path) {
    if (typeof AppConfig === "undefined" || typeof Constants === "undefined" || !localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)) return null;
    try {
      const res = await fetch(`${AppConfig.API_BASE_URL}${path}`, { method, headers: headers() });
      if (!res.ok) return null;
      return (await res.json()).data;
    } catch (e) {
      return null;
    }
  }

  /** "just now", "5 min ago", "3 h ago", "2 days ago", then the date */
  function ago(iso) {
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return "just now";
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    if (s < 7 * 86400) return `${Math.floor(s / 86400)} day${s < 2 * 86400 ? "" : "s"} ago`;
    return new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
  }

  const href = (n) => (n.url ? (n.url.startsWith("http") ? n.url : `${base()}${n.url}`) : `${base()}/notifications`);

  function itemHtml(n) {
    const tone = n.colour === "warning" || n.colour === "secondary" ? "text-dark" : "text-white";
    return `
      <li class="notif-item${n.read ? "" : " is-unread"}">
        <a href="${esc(href(n))}" data-notif="${esc(n.id)}" class="notif-link">
          <span class="avatar avatar-md avatar-rounded bg-${esc(n.colour)} ${tone} flex-shrink-0"><i class="${esc(n.icon)}"></i></span>
          <span class="notif-text">
            <span class="notif-title">${esc(n.title)}</span>
            <span class="notif-body">${esc(n.body)}</span>
            <span class="notif-time">${esc(ago(n.at))}${n.place ? ` · ${esc(n.place.name)}` : ""}</span>
          </span>
          ${n.read ? "" : '<span class="notif-dot" aria-label="Unread"></span>'}
        </a>
      </li>`;
  }

  function drawBell(d) {
    const badge = document.getElementById("notification-icon-badge");
    const label = document.getElementById("notifiation-data");
    const list = document.getElementById("notifList");
    const empty = document.getElementById("notifEmpty");
    if (!badge || !list) return;
    badge.textContent = d.unread > 99 ? "99+" : String(d.unread);
    badge.hidden = d.unread === 0;
    if (label) label.textContent = `${d.unread} Unread`;
    list.innerHTML = d.items.map(itemHtml).join("");
    if (empty) empty.hidden = d.items.length > 0;
  }

  async function refresh() {
    const d = await call("GET", "/notifications?limit=8");
    if (d) drawBell(d);
    return d;
  }

  async function markRead(id) {
    return call("POST", `/notifications/${encodeURIComponent(id)}/read`);
  }

  async function readAll() {
    const d = await call("POST", "/notifications/read-all");
    await refresh();
    return d;
  }

  function wire() {
    const list = document.getElementById("notifList");
    if (!list) return;
    // Mark read before following the link, so the count is right on the next page.
    list.addEventListener("click", async (e) => {
      const a = e.target.closest("[data-notif]");
      if (!a) return;
      e.preventDefault();
      await markRead(a.dataset.notif);
      window.location.href = a.href;
    });
    document.getElementById("notifReadAll")?.addEventListener("click", (e) => {
      e.stopPropagation();
      readAll();
    });
    refresh();
    timer = setInterval(() => document.visibilityState === "visible" && refresh(), POLL_MS);
    document.addEventListener("visibilitychange", () => document.visibilityState === "visible" && refresh());
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", wire);
  else wire();

  return { call, refresh, markRead, readAll, ago, href, esc };
})();

window.MwdNotifications = MwdNotifications;
