/**
 * ============================================================================
 * MINISTRIES - links from other pages (P4, round 2)
 * ============================================================================
 * Demographics and Attendance show the church's ministries beside their own
 * figures: "12 in Youth ministry", a chip on a ministry gathering. Loaded
 * only for roles that can read ministries; any failure simply shows nothing.
 * Needs MinistriesAPI (ministries/api.js).
 * ============================================================================
 */
const MinistryLinks = (function () {
  "use strict";

  let pending = null;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  /** The church's running ministries (cached for the page). */
  function load() {
    if (!pending) pending = MinistriesAPI.overview().then((r) => (r.ok ? r.data.items : [])).catch(() => []);
    return pending;
  }
  const url = (m) => `${AppConfig.FRONTEND_BASE_URL}/church/ministries/ministry?id=${m.id}`;
  async function byType() {
    return new Map((await load()).filter((m) => m.gathering_type).map((m) => [String(m.gathering_type.id), m]));
  }
  async function byKind() {
    return new Map((await load()).filter((m) => m.standard).map((m) => [m.kind, m]));
  }
  /** "Ministry: Youth · 12 in our register" as a small link. */
  const chip = (m, text = null) =>
    `<a class="mn-link-chip" href="${url(m)}" title="Open ${esc(m.name)}"><span class="avatar avatar-rounded bg-${m.colour} ${m.colour === "warning" ? "text-dark" : "text-white"}"><i class="${m.icon}"></i></span>${esc(text || `Ministry: ${m.name} · ${m.members} in our register`)}<i class="ri-arrow-right-up-line"></i></a>`;

  return { load, byType, byKind, url, chip };
})();

window.MinistryLinks = MinistryLinks;
