/**
 * ============================================================================
 * SETTINGS - the hub page (church, region and diocese Settings)
 * ============================================================================
 * Switches sections without reloading the page (docs/specs/settings-spec.md):
 * ?section= stays in the URL (back/forward work), the section header shows
 * its icon, title and one sentence, and the sticky save bar appears as soon
 * as something changes. Leaving with unsaved changes asks first.
 *
 * A section is an object in window.SettingsSections[key] (or, for a generic
 * "form" section, SettingsFields.formSection(key)):
 *   render(body, ctx)  draws it (async)
 *   isDirty()          number of unsaved changes (optional)
 *   save()             saves, resolves true when done (optional)
 *   discard()          throws the changes away (optional)
 * and calls SettingsHub.changed() whenever its inputs change.
 * ============================================================================
 */
const SettingsHub = (function () {
  "use strict";

  const UI = DemographicsUI;
  let ctx = null;
  let current = null;
  let currentMod = null;

  const el = (id) => document.getElementById(id);
  const textOn = (colour) => (colour === "secondary" || colour === "warning" ? "text-dark" : "text-white");

  function dirtyCount() {
    try {
      return currentMod?.isDirty ? Number(currentMod.isDirty()) || 0 : 0;
    } catch (e) {
      return 0;
    }
  }

  /** Re-check the current section's changes and show / hide the save bar. */
  function changed() {
    const n = dirtyCount();
    const bar = el("settingsSaveBar");
    bar.hidden = n === 0;
    el("settingsSaveCount").innerHTML = `<i class="ri-edit-circle-line me-1"></i>${n} unsaved change${n === 1 ? "" : "s"}`;
  }

  function confirmLeave() {
    return new Promise((resolve) =>
      Toast.confirm("You have unsaved changes on this page. Leave without saving them?", () => resolve(true), () => resolve(false), {
        title: "Unsaved changes",
        confirmText: "Leave without saving",
        cancelText: "Stay here",
        type: "warning",
      }),
    );
  }

  function setHead(section) {
    const icon = el("settingsHead").querySelector(".settings-section-icon");
    icon.className = `settings-section-icon bg-${section.colour} ${textOn(section.colour)}`;
    icon.innerHTML = `<i class="${section.icon}"></i>`;
    el("settingsTitle").textContent = section.label;
    el("settingsSentence").textContent = section.sentence || "";
    document.title = `${section.label} · Settings - Makueni West Diocese`;
  }

  function skeleton() {
    return `<div class="row">${UI.skeletonCards(2, "col-xl-6")}</div><div class="row">${UI.skeletonCards(1, "col-12")}</div>`;
  }

  async function show(key, { push = true } = {}) {
    if (key === current) return true;
    if (dirtyCount() && !(await confirmLeave())) return false;

    const section = SettingsRail.section(key) || SettingsRail.section("overview");
    if (!section) return false;
    if (section.kind === "link") {
      window.location.href = SettingsRail.hrefFor(section);
      return true;
    }

    current = section.key;
    SettingsRail.setActive(current);
    setHead(section);
    if (push) history.pushState({ section: current }, "", SettingsRail.hrefFor(section));
    else history.replaceState({ section: current }, "", SettingsRail.hrefFor(section));

    const body = el("settingsBody");
    body.innerHTML = skeleton();
    el("settingsSaveBar").hidden = true;

    currentMod = (window.SettingsSections || {})[current] || (section.kind === "form" ? SettingsFields.formSection(current) : null);
    if (!currentMod) {
      body.innerHTML = `<div class="alert alert-primary">This part of Settings isn't available yet.</div>`;
      return true;
    }
    try {
      await currentMod.render(body, { ...ctx, section });
    } catch (e) {
      console.error(e);
      body.innerHTML = `<div class="alert alert-danger">Something went wrong loading ${section.label}. Please refresh the page.</div>`;
    }
    changed();
    return true;
  }

  async function save() {
    if (!currentMod?.save) return;
    const btn = el("settingsSaveBtn");
    UI.setButtonLoading(btn, "Saving…");
    try {
      await currentMod.save();
    } finally {
      UI.restoreButton(btn);
      changed();
    }
  }

  async function init() {
    ctx = window.SETTINGS_CTX || {};
    el("settingsSaveBtn").addEventListener("click", save);
    el("settingsDiscardBtn").addEventListener("click", () => {
      currentMod?.discard?.();
      changed();
    });
    window.addEventListener("beforeunload", (e) => {
      if (dirtyCount()) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
    window.addEventListener("popstate", async () => {
      const key = new URLSearchParams(window.location.search).get("section") || "overview";
      const ok = await show(key, { push: false });
      if (!ok) history.pushState({ section: current }, "", SettingsRail.hrefFor(SettingsRail.section(current)));
    });

    const data = await SettingsRail.load(ctx.active, { onSelect: (key) => show(key) });
    if (!data) {
      el("settingsBody").innerHTML = `<div class="alert alert-danger">Your role can't open Settings here.</div>`;
      return;
    }
    if (data.place?.name) el("placeLine").textContent = `Settings for ${data.place.name}`;
    await show(ctx.active, { push: false });
  }

  return {
    init,
    show,
    changed,
    /** Links to the open section's cards, shown under it in the rail. */
    subLinks: (links) => SettingsRail.setSubLinks(current, links),
    get ctx() {
      return ctx;
    },
    get current() {
      return current;
    },
  };
})();

window.SettingsHub = SettingsHub;
window.SettingsSections = window.SettingsSections || {};
