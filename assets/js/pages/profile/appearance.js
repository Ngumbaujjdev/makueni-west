/**
 * ============================================================================
 * APPEARANCE SETTINGS - "Appearance" tab on profile.php
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Ports the pattern from the sibling project v1-events-backend: fetch the
 * effective settings, live-preview any change by toggling classes on
 * <html> immediately (before Save is pressed, mirroring the reference's
 * own "preview without saving" behavior), and only persist on Save/Reset.
 *
 * The class map (APPEARANCE_CLASSES) intentionally mirrors backend
 * App\Support\Appearance::OPTIONS - both sides need to agree on what
 * each value resolves to, and the backend is a set of pure functions
 * over plain arrays specifically so this file *can* mirror it directly
 * rather than needing a round-trip just to preview a change.
 *
 * Besides saving to the backend, every successful save/reset also
 * refreshes a "mwd_appearance_classes" cookie - includes/session-manager.php's
 * appearanceHtmlClasses() reads that cookie to print the resolved
 * classes on every page's <html> tag server-side, so there's no flash
 * of unstyled content on the *next* page load (this page's own live
 * preview already covers the current one).
 *
 * Dependencies: config/app.js, config/constants.js, utils/toast.js - all
 * already loaded on profile.php before this script tag.
 * ============================================================================
 */
(function () {
  "use strict";

  const API_BASE = AppConfig.API_BASE_URL;
  const COOKIE_NAME = "mwd_appearance_classes";

  const APPEARANCE_CLASSES = {
    density: { compact: "app-density-compact", comfortable: null, spacious: "app-density-spacious" },
    text_size: { small: "app-text-sm", medium: null, large: "app-text-lg" },
    reduce_motion: "app-reduce-motion",
    high_contrast: "app-high-contrast",
    focus_outlines: "app-focus-outlines",
    underline_links: "app-underline-links",
    big_targets: "app-big-targets",
  };

  const DEFAULTS = {
    density: "comfortable",
    text_size: "medium",
    reduce_motion: false,
    high_contrast: false,
    focus_outlines: false,
    underline_links: false,
    big_targets: false,
  };

  let loaded = false;

  function getAuthToken() {
    return localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
  }

  function getHeaders() {
    return {
      "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
      Accept: Constants.HEADERS.ACCEPT_JSON,
      Authorization: `Bearer ${getAuthToken()}`,
    };
  }

  async function fetchSettings() {
    const response = await fetch(`${API_BASE}/appearance`, { method: "GET", headers: getHeaders() });
    const data = await response.json();
    return data.data || DEFAULTS;
  }

  async function saveSettings(payload) {
    const response = await fetch(`${API_BASE}/appearance`, {
      method: "PUT",
      headers: getHeaders(),
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    return { ok: response.ok, data: data.data, message: data.message };
  }

  async function resetSettings() {
    const response = await fetch(`${API_BASE}/appearance`, { method: "DELETE", headers: getHeaders() });
    const data = await response.json();
    return { ok: response.ok, data: data.data };
  }

  /** Mirrors backend Appearance::classesFor() - same option shape, same resolution. */
  function computeClasses(state) {
    const classes = [];
    if (APPEARANCE_CLASSES.density[state.density]) classes.push(APPEARANCE_CLASSES.density[state.density]);
    if (APPEARANCE_CLASSES.text_size[state.text_size]) classes.push(APPEARANCE_CLASSES.text_size[state.text_size]);
    ["reduce_motion", "high_contrast", "focus_outlines", "underline_links", "big_targets"].forEach((key) => {
      if (state[key]) classes.push(APPEARANCE_CLASSES[key]);
    });
    return classes;
  }

  function applyClassesToHtml(classes) {
    document.documentElement.className = classes.join(" ");
  }

  function persistCookie(classes) {
    document.cookie = `${COOKIE_NAME}=${encodeURIComponent(classes.join(" "))}; path=/; max-age=31536000; SameSite=Lax`;
  }

  function readFormState() {
    const density = document.querySelector('input[name="app-density"]:checked')?.value || DEFAULTS.density;
    const textSize = document.querySelector('input[name="app-text-size"]:checked')?.value || DEFAULTS.text_size;

    return {
      density,
      text_size: textSize,
      reduce_motion: document.getElementById("reduce_motion")?.checked || false,
      high_contrast: document.getElementById("high_contrast")?.checked || false,
      focus_outlines: document.getElementById("focus_outlines")?.checked || false,
      underline_links: document.getElementById("underline_links")?.checked || false,
      big_targets: document.getElementById("big_targets")?.checked || false,
    };
  }

  function populateForm(settings) {
    const densityInput = document.getElementById(`density-${settings.density}`);
    if (densityInput) densityInput.checked = true;

    const textSizeInput = document.getElementById(`text-size-${settings.text_size}`);
    if (textSizeInput) textSizeInput.checked = true;

    ["reduce_motion", "high_contrast", "focus_outlines", "underline_links", "big_targets"].forEach((key) => {
      const input = document.getElementById(key);
      if (input) input.checked = !!settings[key];
    });
  }

  function setBusy(btnId, busy, idleHtml) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = busy;
    if (busy) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Working...';
    else btn.innerHTML = idleHtml;
  }

  async function loadAndPopulate() {
    if (loaded) return;
    loaded = true;

    try {
      const settings = await fetchSettings();
      populateForm(settings);
      applyClassesToHtml(computeClasses(settings));
    } catch (error) {
      console.error("Failed to load appearance settings:", error);
      Toast.error("Failed to load appearance settings.");
    }
  }

  async function handleSave() {
    setBusy("appearanceSaveBtn", true, '<i class="ri-save-line me-1"></i>Save Changes');
    const state = readFormState();
    const result = await saveSettings(state);
    setBusy("appearanceSaveBtn", false, '<i class="ri-save-line me-1"></i>Save Changes');

    if (!result.ok) {
      Toast.error(result.message || "Failed to save appearance settings.");
      return;
    }

    const classes = computeClasses(result.data);
    applyClassesToHtml(classes);
    persistCookie(classes);
    Toast.success("Appearance settings saved.");
  }

  async function handleReset() {
    setBusy("appearanceResetBtn", true, '<i class="ri-refresh-line me-1"></i>Reset to Defaults');
    const result = await resetSettings();
    setBusy("appearanceResetBtn", false, '<i class="ri-refresh-line me-1"></i>Reset to Defaults');

    if (!result.ok) {
      Toast.error("Failed to reset appearance settings.");
      return;
    }

    populateForm(result.data);
    const classes = computeClasses(result.data);
    applyClassesToHtml(classes);
    persistCookie(classes);
    Toast.success("Appearance settings reset to defaults.");
  }

  function bindLivePreview() {
    const pane = document.getElementById("appearance-pane");
    if (!pane) return;

    pane.addEventListener("change", (e) => {
      if (!e.target.matches('input[name="app-density"], input[name="app-text-size"], .form-check-input')) return;
      applyClassesToHtml(computeClasses(readFormState()));
    });
  }

  function init() {
    const appearanceTabBtn = document.getElementById("appearance-tab");
    const saveBtn = document.getElementById("appearanceSaveBtn");
    const resetBtn = document.getElementById("appearanceResetBtn");
    if (!appearanceTabBtn || !saveBtn || !resetBtn) return;

    bindLivePreview();
    appearanceTabBtn.addEventListener("shown.bs.tab", loadAndPopulate);
    saveBtn.addEventListener("click", handleSave);
    resetBtn.addEventListener("click", handleReset);

    // The header/sidebar account menus link here as /profile#appearance -
    // open this tab directly (its shown.bs.tab handler above then loads
    // the saved settings as usual).
    // hashchange covers clicking that link while already on /profile,
    // where the browser only changes the hash and doesn't reload.
    const openFromHash = () => {
      if (window.location.hash === "#appearance") {
        bootstrap.Tab.getOrCreateInstance(appearanceTabBtn).show();
      }
    };
    openFromHash();
    window.addEventListener("hashchange", openFromHash);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
