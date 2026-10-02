/**
 * ============================================================================
 * SETTINGS - fields and cards (shared by every section)
 * ============================================================================
 * The building blocks of the Settings hub (docs/specs/settings-spec.md):
 *   card()          a settings card with a solid icon tile in its header
 *   chips           "Changed", "From the diocese", "Locked by the diocese",
 *                   "Set" / "Not set" for secrets, and the (i) "used on" hint
 *   formSection(k)  a whole generic section drawn from the registry
 *                   (GET /settings/sections/{k}): inputs by type, a lock
 *                   switch at region/diocese, Reset per field, and saving
 *                   only what changed through the hub's save bar.
 * ============================================================================
 */
const SettingsFields = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const slug = (s) => String(s || "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
  const textOn = (colour) => (colour === "secondary" || colour === "warning" ? "text-dark" : "text-white");

  /** A settings card: {id, title, icon, colour, sub, actions, body, footer}. */
  function card({ id, title, icon = "ri-settings-3-line", colour = "primary", sub = "", actions = "", body = "", footer = "", cls = "" }) {
    return `
      <div class="card custom-card settings-card ${cls}" id="${esc(id)}">
        <div class="card-header justify-content-between flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm bg-${colour} ${textOn(colour)}"><i class="${icon}"></i></span>
            <div>
              <div class="card-title mb-0">${esc(title)}</div>
              ${sub ? `<div class="settings-card-sub">${sub}</div>` : ""}
            </div>
          </div>
          ${actions ? `<div class="d-flex align-items-center gap-2">${actions}</div>` : ""}
        </div>
        <div class="card-body">${body}</div>
        ${footer ? `<div class="card-footer">${footer}</div>` : ""}
      </div>`;
  }

  const placeWord = (from) => (from ? `the ${esc(from.type)}` : "above");

  function chips(field) {
    const out = [];
    if (field.locked_by) out.push(`<span class="badge bg-secondary text-dark"><i class="ri-lock-line me-1"></i>Locked by ${placeWord(field.locked_by)}</span>`);
    else if (field.source === "inherited" && field.from) out.push(`<span class="soft-chip soft-primary" title="Set by ${esc(field.from.name)}">From ${placeWord(field.from)}</span>`);
    if (field.secret) out.push(field.secret_set ? '<span class="soft-chip soft-success"><i class="ri-check-line me-1"></i>Set</span>' : '<span class="soft-chip soft-danger">Not set</span>');
    else if (field.changed && field.source === "own") out.push(`<span class="soft-chip soft-warning" title="Default: ${esc(field.default ?? "none")}">Changed</span>`);
    if (field.used_by) out.push(`<i class="ri-information-line settings-used-on" data-bs-toggle="tooltip" title="Used on: ${esc(field.used_by)}"></i>`);
    return out.join("");
  }

  function input(field, disabled) {
    const id = `f-${slug(field.key)}`;
    const dis = disabled ? " disabled" : "";
    const val = field.value ?? "";
    switch (field.type) {
      case "textarea":
        return `<textarea class="form-control" id="${id}" data-key="${esc(field.key)}" rows="3"${dis}>${esc(val)}</textarea>`;
      case "select":
        return `<select class="form-select" id="${id}" data-key="${esc(field.key)}"${dis}>${Object.entries(field.options || {})
          .map(([v, l]) => `<option value="${esc(v)}"${String(v) === String(val) ? " selected" : ""}>${esc(l)}</option>`)
          .join("")}</select>`;
      case "switch":
        return `<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="${id}" data-key="${esc(field.key)}"${val ? " checked" : ""}${dis}><label class="form-check-label" for="${id}">${val ? "On" : "Off"}</label></div>`;
      case "secret":
        return `<input type="password" class="form-control" id="${id}" data-key="${esc(field.key)}" autocomplete="new-password" placeholder="${field.secret_set ? "Leave blank to keep the saved one" : "Not set"}"${dis}>`;
      default: {
        const type = ["email", "tel", "url", "number", "time"].includes(field.type) ? field.type : "text";
        return `<input type="${type}" class="form-control" id="${id}" data-key="${esc(field.key)}" value="${esc(val)}"${dis}>`;
      }
    }
  }

  /** "Check it works": send a test email or SMS with the saved settings. */
  function testCard(channel) {
    const sms = channel === "sms";
    return card({
      id: "card-test",
      title: "Check it works",
      icon: "ri-send-plane-line",
      colour: "success",
      sub: `Send a test ${sms ? "SMS" : "email"} with the settings as saved (save your changes first).`,
      body: `
        <div class="row g-2 align-items-end">
          <div class="col-md-8">
            <label class="form-label" for="testTo">${sms ? "Phone number" : "Email address"}</label>
            <input class="form-control" id="testTo" type="${sms ? "tel" : "email"}" placeholder="${sms ? "0712 345 678" : "you@example.com"}">
          </div>
          <div class="col-md-4 d-grid">
            <button type="button" class="btn btn-success" id="testSend" data-channel="${channel}"><i class="ri-send-plane-line me-1"></i>Send a test</button>
          </div>
        </div>
        <div id="testResult" class="mt-3" aria-live="polite"></div>`,
    });
  }

  function wireTest(root) {
    const btn = root.querySelector("#testSend");
    if (!btn) return;
    btn.addEventListener("click", async () => {
      const to = root.querySelector("#testTo").value.trim();
      const out = root.querySelector("#testResult");
      if (!to) {
        out.innerHTML = '<div class="soft-chip soft-danger">Enter where to send it.</div>';
        return;
      }
      UI.setButtonLoading(btn, "Sending…");
      const res = await SettingsAPI.testSend(btn.dataset.channel, to);
      UI.restoreButton(btn);
      out.innerHTML = `<div class="${res.ok ? "soft-success" : "soft-danger"} rounded p-2 d-flex align-items-start gap-2">
        <span class="avatar avatar-xs ${res.ok ? "bg-success" : "bg-danger"} text-white flex-shrink-0"><i class="${res.ok ? "ri-check-line" : "ri-close-line"}"></i></span>
        <span>${esc(res.message)}</span></div>`;
    });
  }

  /** A whole generic section from the registry. */
  function formSection(key) {
    let payload = null;
    let original = {};
    let lockOriginal = {};
    let resets = new Set();
    let root = null;

    function readValue(el) {
      if (el.type === "checkbox") return el.checked;
      return el.value;
    }

    function values() {
      const v = {};
      root.querySelectorAll("[data-key]").forEach((el) => (v[el.dataset.key] = readValue(el)));
      return v;
    }

    function locks() {
      const l = {};
      root.querySelectorAll("[data-lock]").forEach((el) => (l[el.dataset.lock] = el.checked));
      return l;
    }

    function diff() {
      const now = values();
      const changedValues = {};
      Object.entries(now).forEach(([k, v]) => {
        const field = fieldByKey(k);
        if (field?.secret) {
          if (v !== "") changedValues[k] = v;
        } else if (String(v ?? "") !== String(original[k] ?? "")) changedValues[k] = v;
      });
      const nowLocks = locks();
      const changedLocks = {};
      Object.entries(nowLocks).forEach(([k, v]) => v !== lockOriginal[k] && (changedLocks[k] = v));
      return { values: changedValues, locks: changedLocks, reset: [...resets] };
    }

    function fieldByKey(k) {
      return payload?.cards.flatMap((c) => c.fields).find((f) => f.key === k);
    }

    function draw() {
      const can = !!payload.can?.update;
      original = {};
      lockOriginal = {};
      resets = new Set();
      root.innerHTML = payload.cards
        .map((c, i) =>
          card({
            id: `card-${slug(c.title)}`,
            title: c.title,
            icon: payload.section?.icon || "ri-settings-3-line",
            colour: ["primary", "purple", "success", "pink", "secondary", "danger"][i % 6],
            body: `<div class="row g-3">${c.fields
              .map((f) => {
                original[f.key] = f.type === "switch" ? !!f.value : (f.value ?? "");
                if (f.lockable) lockOriginal[f.key] = !!f.locked_here;
                const disabled = !can || !f.editable;
                return `
                  <div class="col-md-${f.span === 12 ? 12 : 6}" data-field="${esc(f.key)}">
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                      <label class="form-label mb-0" for="f-${slug(f.key)}">${esc(f.label)}</label>
                      ${chips(f)}
                    </div>
                    ${input(f, disabled)}
                    ${f.help ? `<div class="form-text">${esc(f.help)}</div>` : ""}
                    <div class="invalid-feedback d-block" data-error-for="${esc(f.key)}"></div>
                    <div class="d-flex align-items-center flex-wrap gap-3 mt-1">
                      ${f.lockable && can && f.editable ? `<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="l-${slug(f.key)}" data-lock="${esc(f.key)}"${f.locked_here ? " checked" : ""}><label class="form-check-label fs-12" for="l-${slug(f.key)}">Lock for the places below</label></div>` : ""}
                      ${can && f.editable && f.source === "own" ? `<button type="button" class="btn btn-link btn-sm p-0" data-reset="${esc(f.key)}"><i class="ri-arrow-go-back-line me-1"></i>Reset to ${f.secret ? "not set" : "inherited"}</button>` : ""}
                    </div>
                  </div>`;
              })
              .join("")}</div>`,
          }),
        )
        .join("");

      root.querySelectorAll("select[data-key]").forEach((s) => UI.enhanceSelect(s));
      root.querySelectorAll("[data-key], [data-lock]").forEach((el) => {
        el.addEventListener("input", () => SettingsHub.changed());
        el.addEventListener("change", () => {
          if (el.type === "checkbox" && el.dataset.key) el.nextElementSibling && (el.nextElementSibling.textContent = el.checked ? "On" : "Off");
          SettingsHub.changed();
        });
      });
      root.querySelectorAll("[data-reset]").forEach((b) =>
        b.addEventListener("click", () => {
          resets.add(b.dataset.reset);
          b.closest("[data-field]").classList.add("is-resetting");
          b.innerHTML = '<i class="ri-check-line me-1"></i>Will reset when you save';
          b.disabled = true;
          SettingsHub.changed();
        }),
      );
      if (payload.section?.test) root.insertAdjacentHTML("beforeend", testCard(payload.section.test));
      wireTest(root);
      root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((t) => window.bootstrap && new bootstrap.Tooltip(t));
      SettingsHub.subLinks([
        ...payload.cards.map((c) => ({ id: `card-${slug(c.title)}`, label: c.title })),
        ...(payload.section?.test ? [{ id: "card-test", label: "Check it works" }] : []),
      ]);
    }

    return {
      async render(body) {
        root = body;
        const res = await SettingsAPI.section(key);
        if (!res.ok) {
          body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
          return;
        }
        payload = res.data;
        draw();
      },
      isDirty() {
        if (!payload || !root) return 0;
        const d = diff();
        return Object.keys(d.values).length + Object.keys(d.locks).length + d.reset.length;
      },
      discard() {
        draw();
      },
      async save() {
        const d = diff();
        root.querySelectorAll("[data-error-for]").forEach((e) => (e.textContent = ""));
        const res = await SettingsAPI.saveSection(key, d);
        if (!res.ok) {
          Object.entries(res.errors || {}).forEach(([k, msgs]) => {
            const e = root.querySelector(`[data-error-for="${CSS.escape(k)}"]`);
            if (e) e.textContent = [].concat(msgs)[0];
          });
          Toast.error(res.message, { title: "Not saved" });
          return false;
        }
        payload = res.data;
        draw();
        Toast.success(res.message || "Saved.");
        return true;
      },
    };
  }

  return { esc, slug, card, chips, formSection, textOn };
})();

window.SettingsFields = SettingsFields;
