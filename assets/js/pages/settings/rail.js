/**
 * ============================================================================
 * SETTINGS - the section rail (left column of every Settings page)
 * ============================================================================
 * Draws the grouped list of sections from GET /settings/sections
 * (docs/specs/settings-spec.md): a solid coloured icon tile per section,
 * the active one as a solid pill, a red dot where something needs
 * attention, and - under the active section - links to its cards.
 *
 * On the hub page, clicks on hub sections are handed to SettingsHub (no
 * page load); on an existing settings page wrapped in the shell, every item
 * is a normal link. The last response is kept in sessionStorage per acting
 * role, so moving between settings pages doesn't flash a skeleton.
 * ============================================================================
 */
const SettingsRail = (function () {
  "use strict";

  const SHELL = window.SETTINGS_SHELL || {};
  let data = null;
  let active = SHELL.active || "overview";
  let onSelect = null;

  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (colour) => (colour === "secondary" || colour === "warning" ? "text-dark" : "text-white");

  function cacheKey() {
    let assignment = "";
    try {
      assignment = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null")?.assignment_id || "";
    } catch (e) {
      /* no role */
    }
    return `mwd_settings_rail:${assignment}:${SettingsAPI.territoryId || ""}`;
  }

  function siteBase() {
    return String(SHELL.hubUrl || "").replace(/\/(church|region|diocese)\/settings\/(index\.php)?$/, "");
  }

  /** Where a section opens: the hub (with ?section=) or, for a linked page, that page. */
  function hrefFor(section) {
    if (section.kind === "link" && section.url) return siteBase() + section.url + (SettingsAPI.territoryId ? `?territory_id=${SettingsAPI.territoryId}` : "");
    const params = new URLSearchParams();
    if (section.key !== "overview") params.set("section", section.key);
    if (SettingsAPI.territoryId) params.set("territory_id", SettingsAPI.territoryId);
    const qs = params.toString();
    return `${SHELL.hubUrl}${qs ? `?${qs}` : ""}`;
  }

  function placeTile(place) {
    if (place.logo_url) return `<img class="settings-rail-logo" src="${esc(place.logo_url)}" alt="">`;
    const initials = String(place.name || "?").split(/\s+/).filter((w) => /^[A-Za-z]/.test(w)).slice(0, 2).map((w) => w[0]).join("").toUpperCase() || "?";
    return `<span class="settings-rail-logo is-initials">${esc(initials)}</span>`;
  }

  function render() {
    const rail = document.getElementById("settingsRail");
    if (!rail || !data) return;
    const level = data.level ? data.level.charAt(0).toUpperCase() + data.level.slice(1) : "";

    rail.innerHTML = `
      <div class="settings-rail-place">
        ${placeTile(data.place)}
        <div class="settings-rail-place-text">
          <strong title="${esc(data.place.name)}">${esc(data.place.name)}</strong>
          <small>${esc(level)} settings</small>
        </div>
      </div>
      ${data.groups
        .map(
          (group) => `
        <div class="settings-rail-group">
          ${group.label ? `<div class="settings-rail-label">${esc(group.label)}</div>` : ""}
          ${group.sections
            .map(
              (s) => `
            <a class="settings-rail-item${s.key === active ? " is-active" : ""}" href="${esc(hrefFor(s))}" data-section="${esc(s.key)}" data-kind="${esc(s.kind)}"${s.key === active ? ' aria-current="page"' : ""}>
              <span class="settings-rail-icon bg-${esc(s.colour)} ${textOn(s.colour)}"><i class="${esc(s.icon)}"></i></span>
              <span class="settings-rail-text">${esc(s.label)}</span>
              ${s.attention ? '<span class="settings-rail-dot" title="Needs attention"><span class="visually-hidden">Needs attention</span></span>' : ""}
            </a>
            <div class="settings-rail-sub" data-sub-for="${esc(s.key)}"></div>`,
            )
            .join("")}
        </div>`,
        )
        .join("")}`;

    rail.querySelectorAll(".settings-rail-item").forEach((a) => {
      a.addEventListener("click", (e) => {
        if (!onSelect || a.dataset.kind === "link" || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        onSelect(a.dataset.section);
      });
    });
    rail.querySelector(".settings-rail-item.is-active")?.scrollIntoView({ block: "nearest", inline: "center" });
  }

  /** Load (cached first, then fresh) and draw. Resolves to the sections data, or null when refused. */
  async function load(activeKey, options = {}) {
    active = activeKey || active;
    onSelect = options.onSelect || null;
    try {
      const cached = JSON.parse(sessionStorage.getItem(cacheKey()) || "null");
      if (cached) {
        data = cached;
        render();
      }
    } catch (e) {
      /* no cache */
    }

    const res = await SettingsAPI.sections();
    if (!res.ok) {
      if (!data) {
        const rail = document.getElementById("settingsRail");
        if (rail) rail.innerHTML = `<div class="p-3 fs-13">${esc(res.message)}</div>`;
      }
      return res.status === 403 ? null : data;
    }
    data = res.data;
    try {
      sessionStorage.setItem(cacheKey(), JSON.stringify(data));
    } catch (e) {
      /* storage full or blocked */
    }
    render();
    return data;
  }

  function setActive(key) {
    active = key;
    document.querySelectorAll("#settingsRail .settings-rail-item").forEach((a) => {
      const on = a.dataset.section === key;
      a.classList.toggle("is-active", on);
      if (on) a.setAttribute("aria-current", "page");
      else a.removeAttribute("aria-current");
    });
    document.querySelectorAll("#settingsRail .settings-rail-sub").forEach((sub) => {
      if (sub.dataset.subFor !== key) sub.innerHTML = "";
    });
  }

  /** Links to the open section's cards, under its rail item: [{id, label}]. */
  function setSubLinks(key, links) {
    const sub = document.querySelector(`#settingsRail .settings-rail-sub[data-sub-for="${CSS.escape(key)}"]`);
    if (!sub) return;
    sub.innerHTML = (links || []).map((l) => `<a href="#${esc(l.id)}" data-target="${esc(l.id)}">${esc(l.label)}</a>`).join("");
    sub.querySelectorAll("a").forEach((a) =>
      a.addEventListener("click", (e) => {
        e.preventDefault();
        document.getElementById(a.dataset.target)?.scrollIntoView({ behavior: "smooth", block: "start" });
      }),
    );
  }

  /** Update one section's attention dot (e.g. after the profile is completed). */
  function setAttention(key, on) {
    const item = document.querySelector(`#settingsRail .settings-rail-item[data-section="${CSS.escape(key)}"]`);
    if (!item) return;
    const dot = item.querySelector(".settings-rail-dot");
    if (on && !dot) item.insertAdjacentHTML("beforeend", '<span class="settings-rail-dot" title="Needs attention"><span class="visually-hidden">Needs attention</span></span>');
    if (!on && dot) dot.remove();
    if (data) data.groups.forEach((g) => g.sections.forEach((s) => s.key === key && (s.attention = on)));
    try {
      if (data) sessionStorage.setItem(cacheKey(), JSON.stringify(data));
    } catch (e) {
      /* ignore */
    }
  }

  function section(key) {
    return data?.groups.flatMap((g) => g.sections).find((s) => s.key === key) || null;
  }

  return { load, setActive, setSubLinks, setAttention, section, hrefFor, get data() { return data; } };
})();

window.SettingsRail = SettingsRail;

// On an existing settings page wrapped in the shell (no hub), draw the rail by itself.
document.addEventListener("DOMContentLoaded", () => {
  if (window.SETTINGS_SHELL && !window.SETTINGS_CTX) SettingsRail.load(window.SETTINGS_SHELL.active);
});
