/**
 * SETTINGS - Profile: who the place is, how to reach it, where to find it,
 * and its logo (GET / PUT /settings/profile, POST / DELETE
 * /settings/profile/logo). The preview card on the right updates as you type.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;
  const FIELDS = ["name", "established_date", "description", "phone", "email", "website", "address", "town", "sub_county", "county", "postal_code", "latitude", "longitude"];
  const CHECKS = { phone: "Phone number", email: "Email address", address: "Physical address", county: "County", location: "Map pin", logo: "Logo or photo" };
  const DEFAULT_CENTRE = [-1.95, 37.55]; // Makueni
  const TYPE_LABEL = { church: "Church", region: "Region", diocese: "Diocese" };
  // A brand-coloured pin from the icon font - the template's Leaflet copy has no marker shadow image.
  const pinIcon = () => L.divIcon({ className: "settings-map-pin", html: '<i class="ri-map-pin-2-fill"></i>', iconSize: [34, 34], iconAnchor: [17, 32] });

  let root = null;
  let data = null;
  let snapshot = {};
  let map = null;
  let marker = null;
  let can = false;
  let counties = [];

  const $ = (sel) => root.querySelector(sel);
  const val = (name) => {
    const el = $(`[name="${name}"]`);
    return el ? el.value.trim() : "";
  };

  function current() {
    const v = {};
    FIELDS.forEach((f) => (v[f] = val(f)));
    return v;
  }

  function initials(name) {
    return String(name || "?").split(/\s+/).filter((w) => /^[A-Za-z]/.test(w)).slice(0, 2).map((w) => w[0]).join("").toUpperCase() || "?";
  }

  function field(name, label, input, col = "col-md-6", help = "") {
    return `
      <div class="${col}">
        <label class="form-label" for="p-${name}">${label}</label>
        ${input}
        ${help ? `<div class="form-text">${help}</div>` : ""}
        <div class="invalid-feedback" data-error-for="${name}"></div>
      </div>`;
  }

  const text = (name, value, attrs = "") => `<input class="form-control" id="p-${name}" name="${name}" value="${esc(value ?? "")}" ${attrs}>`;

  function draw() {
    const p = data.profile;
    const dis = can ? "" : "disabled";
    const countyOptions = ['<option value="">Choose a county</option>']
      .concat(counties.map((c) => `<option value="${esc(c)}"${c === p.county ? " selected" : ""}>${esc(c)}</option>`))
      .concat(p.county && !p.county_listed ? [`<option value="${esc(p.county)}" selected>${esc(p.county)} (not in the list)</option>`] : [])
      .join("");

    root.innerHTML = `
      ${can ? "" : `<div class="alert alert-primary d-flex align-items-center gap-2"><span class="avatar avatar-sm bg-primary text-white"><i class="ri-eye-line"></i></span><div><b>View only.</b> Your role can see this profile, but not change it.</div></div>`}
      <div class="row">
        <div class="col-xxl-8 col-xl-7">
          ${F.card({
            id: "card-identity", title: "Who you are", icon: "ri-community-line", colour: "purple",
            sub: "Your name as it appears everywhere in the system",
            body: `<div class="row g-3">
              ${field("name", "Name", text("name", p.name, `maxlength="255" required ${dis}`), "col-md-8")}
              ${field("code", "Code", `<input class="form-control" id="p-code" value="${esc(p.code)}" disabled>`, "col-md-4", "Set by the diocese")}
              ${field("established_date", "Started on", text("established_date", p.established_date, `type="date" max="${new Date().toISOString().slice(0, 10)}" ${dis}`))}
              ${field("description", "About you", `<textarea class="form-control" id="p-description" name="description" rows="3" maxlength="2000" ${dis}>${esc(p.description ?? "")}</textarea>`, "col-12", "A few lines visitors and the diocese will read.")}
            </div>`,
          })}
          ${F.card({
            id: "card-contact", title: "How to reach you", icon: "ri-phone-line", colour: "primary",
            sub: "Shown on your pages and used for replies",
            body: `<div class="row g-3">
              ${field("phone", "Phone", text("phone", p.phone, `type="tel" maxlength="30" placeholder="+254 712 345 678" ${dis}`))}
              ${field("email", "Email", text("email", p.email, `type="email" maxlength="255" placeholder="office@yourchurch.or.ke" ${dis}`))}
              ${field("website", "Website", text("website", p.website, `maxlength="255" placeholder="yourchurch.or.ke" ${dis}`), "col-md-12", "Leave out https:// if you like - it's added for you.")}
            </div>`,
          })}
          ${F.card({
            id: "card-location", title: "Where to find you", icon: "ri-map-pin-line", colour: "success",
            sub: "Your address and a pin on the map",
            body: `<div class="row g-3">
              ${field("address", "Physical address", text("address", p.address, `maxlength="255" placeholder="e.g. Along Mombasa Road, next to the market" ${dis}`), "col-12")}
              ${field("town", "Town", text("town", p.town, `maxlength="100" ${dis}`))}
              ${field("sub_county", "Sub-county", text("sub_county", p.sub_county, `maxlength="100" ${dis}`))}
              ${field("county", "County", `<select class="form-select" id="p-county" name="county" ${dis}>${countyOptions}</select>`)}
              ${field("postal_code", "Postal code", text("postal_code", p.postal_code, `maxlength="20" ${dis}`))}
              <div class="col-12">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                  <label class="form-label mb-0">Map pin</label>
                  ${can ? `<div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="pinHere"><i class="ri-focus-3-line me-1"></i>Use where I am</button>
                    <button type="button" class="btn btn-sm btn-light border" id="pinClear"><i class="ri-close-line me-1"></i>Remove pin</button>
                  </div>` : ""}
                </div>
                <div class="settings-map" id="profileMap" role="application" aria-label="Map - click to drop your pin"></div>
                <div class="form-text" id="pinText"></div>
                <input type="hidden" name="latitude" value="${p.latitude ?? ""}">
                <input type="hidden" name="longitude" value="${p.longitude ?? ""}">
                <div class="invalid-feedback d-block" data-error-for="latitude"></div>
                <div class="invalid-feedback d-block" data-error-for="longitude"></div>
              </div>
            </div>`,
          })}
          ${F.card({
            id: "card-logo", title: "Logo or photo", icon: "ri-image-line", colour: "pink",
            sub: "Shown on your pages and reports",
            body: `<div class="d-flex align-items-center flex-wrap gap-3" id="logoArea"></div>`,
          })}
        </div>
        <div class="col-xxl-4 col-xl-5">
          <div class="settings-sticky">
            <div class="card custom-card settings-preview" id="card-preview"></div>
          </div>
        </div>
      </div>`;

    snapshot = current();
    drawLogo();
    drawPreview();
    initMap();
    UI.enhanceSelect($("#p-county"), { search: true });
    // Select2 changes the select through jQuery, which doesn't fire a native input event.
    if (window.jQuery) window.jQuery($("#p-county")).on("change", onChange);

    root.querySelectorAll("input[name], textarea[name], select[name]").forEach((el) => el.addEventListener("input", onChange));
    if (can) {
      $("#pinHere").addEventListener("click", locate);
      $("#pinClear").addEventListener("click", () => setPin(null, null));
    }
    SettingsHub.subLinks([
      { id: "card-identity", label: "Who you are" },
      { id: "card-contact", label: "How to reach you" },
      { id: "card-location", label: "Where to find you" },
      { id: "card-logo", label: "Logo or photo" },
    ]);
  }

  function onChange() {
    drawPreview();
    SettingsHub.changed();
  }

  /** The logo card's tile and buttons - redrawn alone after an upload, so typing elsewhere is kept. */
  function drawLogo() {
    const p = data.profile;
    $("#logoArea").innerHTML = `
      ${p.logo_url
        ? `<img class="settings-logo-preview" src="${esc(p.logo_url)}" alt="Current logo">`
        : `<span class="settings-logo-preview is-initials bg-pink text-white">${esc(initials(val("name") || p.name))}</span>`}
      <div>
        ${can ? `<input type="file" id="logoFile" accept="image/png,image/jpeg,image/webp" hidden>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-primary" id="logoPick"><i class="ri-upload-2-line me-1"></i>${p.logo_url ? "Change" : "Upload"}</button>
            ${p.logo_url ? '<button type="button" class="btn btn-light border" id="logoRemove"><i class="ri-delete-bin-line me-1"></i>Remove</button>' : ""}
          </div>` : ""}
        <div class="form-text">PNG, JPG or WebP, up to 2 MB. It's resized for you - a square image looks best.</div>
        <div class="invalid-feedback d-block" data-error-for="logo"></div>
      </div>`;
    if (can) {
      $("#logoPick").addEventListener("click", () => $("#logoFile").click());
      $("#logoFile").addEventListener("change", (e) => e.target.files[0] && upload(e.target.files[0]));
      $("#logoRemove")?.addEventListener("click", removeLogo);
    }
  }

  /** Completeness from what's on screen (the saved logo counts). */
  function completeness(v) {
    const done = {
      phone: !!v.phone,
      email: !!v.email,
      address: !!v.address,
      county: !!v.county,
      location: v.latitude !== "" && v.longitude !== "",
      logo: !!data.profile.logo_url,
    };
    const n = Object.values(done).filter(Boolean).length;
    return { percent: Math.round((n / 6) * 100), done: n, missing: Object.keys(done).filter((k) => !done[k]) };
  }

  function drawPreview() {
    const v = current();
    const p = data.profile;
    const c = completeness(v);
    const colour = c.percent === 100 ? "success" : c.percent >= 50 ? "secondary" : "danger";
    const fact = (icon, t) => (t ? `<li><i class="${icon}"></i><span>${esc(t)}</span></li>` : "");
    const where = [v.address, v.town, v.sub_county, v.county].filter(Boolean).join(", ");
    $("#card-preview").innerHTML = `
      <div class="card-header"><div class="card-title mb-0">How others see you</div></div>
      <div class="card-body">
        <div class="text-center">
          ${p.logo_url ? `<img class="settings-preview-logo" src="${esc(p.logo_url)}" alt="">` : `<span class="settings-preview-logo is-initials bg-purple text-white">${esc(initials(v.name || p.name))}</span>`}
          <div class="fw-semibold fs-17 mt-2">${esc(v.name || p.name)}</div>
          <div class="d-flex justify-content-center flex-wrap gap-1 mt-1">
            <span class="badge bg-primary">${TYPE_LABEL[p.type] || esc(p.type)}</span>
            ${p.parent ? `<span class="soft-chip soft-primary">${esc(p.parent.name)}</span>` : ""}
          </div>
          ${v.description ? `<p class="settings-preview-about mt-2 mb-0">${esc(v.description)}</p>` : ""}
        </div>
        <ul class="list-unstyled settings-facts mt-3 mb-0">
          ${fact("ri-phone-line", v.phone)}
          ${fact("ri-mail-line", v.email)}
          ${fact("ri-global-line", v.website)}
          ${fact("ri-map-pin-line", where)}
          ${fact("ri-calendar-line", v.established_date ? `Since ${new Date(v.established_date).getFullYear()}` : "")}
        </ul>
        ${!v.phone && !v.email && !where ? '<div class="text-center fs-13 mt-2">Add your contact details and they show up here.</div>' : ""}
      </div>
      <div class="card-footer">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="fw-semibold">Profile complete</span>
          <span class="badge bg-${colour} ${colour === "secondary" ? "text-dark" : "text-white"}">${c.percent}%</span>
        </div>
        <div class="progress progress-sm mb-2" aria-label="${c.percent}% complete"><div class="progress-bar bg-${colour}" style="width:${c.percent}%"></div></div>
        ${c.missing.length ? `<div class="d-flex flex-wrap gap-1">${c.missing.map((k) => `<span class="soft-chip soft-danger">${CHECKS[k]}</span>`).join("")}</div>` : '<div class="fs-13 text-success fw-semibold"><i class="ri-checkbox-circle-line me-1"></i>Everything is filled in</div>'}
      </div>`;
  }

  /* ---------- map pin ---------- */

  function setPin(lat, lng, pan = false) {
    $('[name="latitude"]').value = lat === null ? "" : Number(lat).toFixed(7);
    $('[name="longitude"]').value = lng === null ? "" : Number(lng).toFixed(7);
    if (map) {
      if (lat === null) {
        marker && map.removeLayer(marker);
        marker = null;
      } else if (marker) marker.setLatLng([lat, lng]);
      else {
        marker = L.marker([lat, lng], { icon: pinIcon(), draggable: can, keyboard: true, title: "Your location" }).addTo(map);
        marker.on("dragend", () => {
          const ll = marker.getLatLng();
          setPin(ll.lat, ll.lng);
        });
      }
      if (pan && lat !== null) map.setView([lat, lng], Math.max(map.getZoom(), 14));
    }
    $("#pinText").textContent =
      lat === null ? (can ? "No pin yet - click the map where you are." : "No pin yet.") : `Pinned at ${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}${can ? " - drag the pin to adjust." : ""}`;
    onChange();
  }

  function initMap() {
    const box = $("#profileMap");
    if (!window.L) {
      box.innerHTML = '<div class="p-3 fs-13">The map couldn\'t load. You can still save the rest of the profile.</div>';
      return;
    }
    const lat = data.profile.latitude;
    const lng = data.profile.longitude;
    map = L.map(box, { scrollWheelZoom: false }).setView(lat !== null ? [lat, lng] : DEFAULT_CENTRE, lat !== null ? 15 : 9);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "&copy; OpenStreetMap contributors" }).addTo(map);
    marker = null;
    if (lat !== null) {
      marker = L.marker([lat, lng], { icon: pinIcon(), draggable: can, keyboard: true, title: "Your location" }).addTo(map);
      marker.on("dragend", () => {
        const ll = marker.getLatLng();
        setPin(ll.lat, ll.lng);
      });
    }
    if (can) map.on("click", (e) => setPin(e.latlng.lat, e.latlng.lng));
    $("#pinText").textContent = lat === null ? (can ? "No pin yet - click the map where you are." : "No pin yet.") : `Pinned at ${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}${can ? " - drag the pin to adjust." : ""}`;
    setTimeout(() => map && map.invalidateSize(), 150);
  }

  function locate() {
    if (!navigator.geolocation) {
      Toast.warning("Your browser can't share its location. Click the map instead.");
      return;
    }
    const btn = $("#pinHere");
    UI.setButtonLoading(btn, "Finding you…");
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        UI.restoreButton(btn);
        setPin(pos.coords.latitude, pos.coords.longitude, true);
      },
      () => {
        UI.restoreButton(btn);
        Toast.warning("Couldn't get your location. Click the map where you are instead.");
      },
      { enableHighAccuracy: true, timeout: 10000 },
    );
  }

  /* ---------- logo ---------- */

  async function upload(file) {
    const err = $('[data-error-for="logo"]');
    err.textContent = "";
    if (file.size > 2 * 1024 * 1024) {
      err.textContent = "The logo must be 2 MB or smaller.";
      return;
    }
    const btn = $("#logoPick");
    UI.setButtonLoading(btn, "Uploading…");
    const res = await SettingsAPI.uploadLogo(file);
    UI.restoreButton(btn);
    if (!res.ok) {
      err.textContent = res.message;
      return;
    }
    afterLogo(res);
  }

  async function removeLogo() {
    const btn = $("#logoRemove");
    UI.setButtonLoading(btn, "Removing…");
    const res = await SettingsAPI.removeLogo();
    if (!res.ok) {
      UI.restoreButton(btn);
      Toast.error(res.message);
      return;
    }
    afterLogo(res);
  }

  function afterLogo(res) {
    data.profile.logo_url = res.data.profile.logo_url;
    data.completeness = res.data.completeness;
    drawLogo();
    drawPreview();
    SettingsRail.setAttention("profile", completeness(current()).percent < 100);
    Toast.success(res.message);
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.profile = {
    async render(body) {
      root = body;
      map = null;
      const [res, ref] = await Promise.all([SettingsAPI.profile(), SettingsAPI.reference()]);
      if (!res.ok) {
        body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
        return;
      }
      data = res.data;
      can = !!data.can?.update;
      counties = ref.ok ? ref.data.counties : [];
      draw();
    },
    isDirty() {
      if (!root || !data || !$('[name="name"]')) return 0;
      const now = current();
      return FIELDS.filter((f) => String(now[f] ?? "") !== String(snapshot[f] ?? "")).length;
    },
    discard() {
      draw();
    },
    async save() {
      root.querySelectorAll(".is-invalid").forEach((e) => e.classList.remove("is-invalid"));
      root.querySelectorAll("[data-error-for]").forEach((e) => (e.textContent = ""));
      const v = current();
      const body = {};
      FIELDS.forEach((f) => (body[f] = v[f] === "" ? null : v[f]));
      const res = await SettingsAPI.saveProfile(body);
      if (!res.ok) {
        Object.entries(res.errors || {}).forEach(([k, msgs]) => {
          $(`[name="${k}"]`)?.classList.add("is-invalid");
          const e = $(`[data-error-for="${k}"]`);
          if (e) {
            e.textContent = [].concat(msgs)[0];
            e.classList.add("d-block");
          }
        });
        const first = root.querySelector(".is-invalid, [data-error-for]:not(:empty)");
        first?.scrollIntoView({ behavior: "smooth", block: "center" });
        Toast.error(res.message, { title: "Profile not saved" });
        return false;
      }
      data = res.data;
      snapshot = current();
      // Show the saved (cleaned) values, e.g. https:// added to the website.
      FIELDS.forEach((f) => {
        const el = $(`[name="${f}"]`);
        const saved = data.profile[f];
        if (el && f !== "latitude" && f !== "longitude") el.value = saved ?? "";
      });
      snapshot = current();
      drawPreview();
      SettingsRail.setAttention("profile", data.completeness.percent < 100);
      Toast.success(res.message);
      return true;
    },
  };
})();
