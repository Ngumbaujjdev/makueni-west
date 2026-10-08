/**
 * SETTINGS - Profile: who the place is, how to reach it, where to find it,
 * its logo, its YouTube link and its photo gallery (GET / PUT
 * /settings/profile, POST / DELETE /settings/profile/logo, /settings/profile/photos).
 * The preview card on the right updates as you type. Gallery changes save at
 * once; the rest saves with the Save bar.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;
  const FIELDS = ["name", "established_date", "description", "phone", "email", "website", "youtube_url", "address", "town", "sub_county", "county", "postal_code", "latitude", "longitude"];
  const CHECKS = { phone: "Phone number", email: "Email address", address: "Physical address", county: "County", location: "Map pin", logo: "Logo or photo", photos: "At least 3 photos" };
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
  let gallery = { photos: [], max: 30 };
  let lightbox = null;

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
          ${F.card({
            id: "card-online", title: "Services online", icon: "ri-youtube-line", colour: "danger",
            sub: "Your YouTube channel, or the link to a service",
            body: `<div class="row g-3">
              ${field("youtube_url", "YouTube link", text("youtube_url", p.youtube_url, `maxlength="255" placeholder="youtube.com/@yourchurch" ${dis}`), "col-12", "Your channel (youtube.com/@yourchurch) or a video or live link - a video plays right here.")}
              <div class="col-12" id="ytPreview"></div>
            </div>`,
          })}
          ${F.card({
            id: "card-gallery", title: "Gallery", icon: "ri-gallery-line", colour: "purple",
            sub: "Photos of your church, services and events - for your page",
            body: `<div id="galleryArea"><span class="skel skel-line"></span></div>`,
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
    drawYouTube();
    drawGallery();
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
      { id: "card-online", label: "Services online" },
      { id: "card-gallery", label: "Gallery" },
    ]);
  }

  function onChange(e) {
    if (e?.target?.name === "youtube_url") drawYouTube();
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
      photos: gallery.photos.length >= 3,
    };
    const n = Object.values(done).filter(Boolean).length;
    const total = Object.keys(done).length;
    return { percent: Math.round((n / total) * 100), done: n, missing: Object.keys(done).filter((k) => !done[k]) };
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
        ${v.youtube_url && ytKind(v.youtube_url) ? `<a class="settings-yt-badge mt-3" href="${esc(withScheme(v.youtube_url))}" target="_blank" rel="noopener"><i class="ri-youtube-fill"></i>${ytKind(v.youtube_url) === "video" ? "Watch our service" : "Our services on YouTube"}</a>` : ""}
        ${gallery.photos.length ? `<div class="settings-preview-photos mt-3">${gallery.photos.slice(0, 4).map((ph, i) => `<img src="${esc(ph.thumb_url)}" alt="${esc(ph.caption || "")}" loading="lazy">${i === 3 && gallery.photos.length > 4 ? `<span>+${gallery.photos.length - 4}</span>` : ""}`).join("")}</div>` : ""}
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

  /* ---------- services online ---------- */

  const withScheme = (u) => (/^https?:\/\//i.test(u) ? u : `https://${u}`);
  /** The same links the server accepts (app/Support/YouTube.php). */
  const YT = /^(?:https?:\/\/)?(?:www\.|m\.)?(?:youtube\.com\/(?:@[\w.-]+|channel\/[\w-]+|c\/[\w.-]+|user\/[\w.-]+|watch\?(?:.*&)?v=[\w-]{11}|live\/[\w-]{11}|shorts\/[\w-]{11}|embed\/[\w-]{11}|playlist\?(?:.*&)?list=[\w-]+)|youtu\.be\/[\w-]{11})(?:[/?&#].*)?$/i;
  function videoId(u) {
    for (const re of [/youtu\.be\/([\w-]{11})/i, /[?&]v=([\w-]{11})/i, /\/(?:live|shorts|embed)\/([\w-]{11})/i]) {
      const m = String(u || "").match(re);
      if (m) return m[1];
    }
    return null;
  }
  const ytKind = (u) => (!u || !YT.test(u.trim()) ? null : videoId(u) ? "video" : "channel");

  function drawYouTube() {
    const u = val("youtube_url");
    const box = $("#ytPreview");
    const kind = ytKind(u);
    if (!u) {
      box.innerHTML = `<div class="settings-yt-empty"><span class="settings-yt-icon"><i class="ri-youtube-fill"></i></span><div><strong>No YouTube link yet</strong><small>When you stream or post your services, put the link here - it shows on your page.</small></div></div>`;
    } else if (!kind) {
      box.innerHTML = `<div class="settings-yt-empty is-wrong"><span class="settings-yt-icon"><i class="ri-error-warning-line"></i></span><div><strong>That isn't a YouTube link</strong><small>Use youtube.com/@yourchurch, or copy a video's link from YouTube.</small></div></div>`;
    } else if (kind === "video") {
      box.innerHTML = `<div class="ratio ratio-16x9 settings-yt-player"><iframe src="https://www.youtube-nocookie.com/embed/${videoId(u)}" title="Your service on YouTube" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
    } else {
      box.innerHTML = `<a class="settings-yt-channel" href="${esc(withScheme(u))}" target="_blank" rel="noopener"><span class="settings-yt-icon is-solid"><i class="ri-youtube-fill"></i></span><div class="min-w-0"><strong>Your YouTube channel</strong><small class="text-break">${esc(u)}</small></div><i class="ri-external-link-line ms-auto"></i></a>`;
    }
  }

  /* ---------- gallery ---------- */

  async function loadGallery() {
    const res = await SettingsAPI.photos();
    if (res.ok) gallery = res.data;
    drawGallery();
    drawPreview();
  }

  function drawGallery() {
    const box = $("#galleryArea");
    if (!box) return;
    const photos = gallery.photos;
    const left = gallery.max - photos.length;
    box.innerHTML = `
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <span class="soft-chip soft-purple"><i class="ri-image-2-line"></i>${photos.length} of ${gallery.max} photos</span>
        ${can && left > 0 ? `<input type="file" id="galFile" accept="image/png,image/jpeg,image/webp" multiple hidden><button type="button" class="btn btn-primary btn-sm" id="galPick"><i class="ri-image-add-line me-1"></i>Choose photos</button>` : ""}
      </div>
      ${can && left > 0 ? `<div class="gal-drop" id="galDrop" tabindex="0" role="button" aria-label="Add photos">
          <span class="gal-drop-icon"><i class="ri-upload-cloud-2-line"></i></span>
          <div><strong>Drop photos here, or choose them</strong><small>JPG, PNG or WebP, up to 10 MB each - up to ${left} more. We stand them upright, resize them and keep them as small WebP files.</small></div>
        </div>` : ""}
      <div class="gal-progress" id="galProgress" hidden><div class="d-flex justify-content-between mb-1"><span id="galProgressText"></span><span id="galProgressPct"></span></div><div class="progress progress-sm"><div class="progress-bar bg-purple" id="galProgressBar" style="width:0%"></div></div></div>
      <div class="invalid-feedback d-block" data-error-for="photos"></div>
      ${photos.length
        ? `<div class="gal-grid">${photos
            .map(
              (ph, i) => `<figure class="gal-item" data-id="${ph.id}">
                <a class="gal-thumb" href="${esc(ph.url)}" data-gallery="place" ${ph.caption ? `data-title="${esc(ph.caption)}"` : ""}><img src="${esc(ph.thumb_url)}" alt="${esc(ph.caption || `Photo ${i + 1}`)}" loading="lazy"></a>
                ${can ? `<div class="gal-tools">
                    <button type="button" class="gal-tool" data-move="-1" ${i === 0 ? "disabled" : ""} aria-label="Move earlier"><i class="ri-arrow-left-s-line"></i></button>
                    <button type="button" class="gal-tool" data-move="1" ${i === photos.length - 1 ? "disabled" : ""} aria-label="Move later"><i class="ri-arrow-right-s-line"></i></button>
                    <button type="button" class="gal-tool is-danger" data-remove aria-label="Remove this photo"><i class="ri-delete-bin-line"></i></button>
                  </div>
                  <input class="form-control form-control-sm gal-caption" value="${esc(ph.caption || "")}" maxlength="160" placeholder="Add a caption" aria-label="Caption">`
                  : ph.caption ? `<figcaption>${esc(ph.caption)}</figcaption>` : ""}
              </figure>`,
            )
            .join("")}</div>`
        : `<div class="gal-empty"><i class="ri-gallery-line"></i><span>No photos yet${can ? " - add your church, a Sunday service or an event." : "."}</span></div>`}`;

    if (window.GLightbox) {
      lightbox?.destroy();
      lightbox = photos.length ? GLightbox({ selector: "#galleryArea .gal-thumb" }) : null;
    }
    if (!can) return;
    const file = $("#galFile");
    $("#galPick")?.addEventListener("click", () => file.click());
    file?.addEventListener("change", (e) => addPhotos([...e.target.files]));
    const drop = $("#galDrop");
    if (drop) {
      drop.addEventListener("click", () => file.click());
      drop.addEventListener("keydown", (e) => (e.key === "Enter" || e.key === " ") && (e.preventDefault(), file.click()));
      ["dragenter", "dragover"].forEach((t) => drop.addEventListener(t, (e) => (e.preventDefault(), drop.classList.add("is-over"))));
      ["dragleave", "drop"].forEach((t) => drop.addEventListener(t, () => drop.classList.remove("is-over")));
      drop.addEventListener("drop", (e) => {
        e.preventDefault();
        addPhotos([...e.dataTransfer.files].filter((f) => /^image\//.test(f.type)));
      });
    }
    box.querySelectorAll(".gal-item").forEach((item) => {
      const id = Number(item.dataset.id);
      item.querySelectorAll("[data-move]").forEach((b) => b.addEventListener("click", () => move(id, Number(b.dataset.move))));
      const del = item.querySelector("[data-remove]");
      del.addEventListener("click", () => {
        // Two taps: the first asks, the second removes.
        if (!del.classList.contains("is-asking")) {
          del.classList.add("is-asking");
          del.innerHTML = '<span class="fs-11 fw-semibold px-1">Remove?</span>';
          setTimeout(() => del.isConnected && (del.classList.remove("is-asking"), (del.innerHTML = '<i class="ri-delete-bin-line"></i>')), 3000);
          return;
        }
        removePhoto(id, del);
      });
      const cap = item.querySelector(".gal-caption");
      const saveCap = async () => {
        const ph = gallery.photos.find((x) => x.id === id);
        const value = cap.value.trim();
        if (!ph || value === (ph.caption || "")) return;
        const res = await SettingsAPI.captionPhoto(id, value);
        if (!res.ok) return Toast.error(res.message);
        ph.caption = res.data.caption;
        Toast.success("Caption saved");
      };
      cap.addEventListener("blur", saveCap);
      cap.addEventListener("keydown", (e) => e.key === "Enter" && (e.preventDefault(), cap.blur()));
    });
  }

  async function addPhotos(files) {
    const err = $('[data-error-for="photos"]');
    err.textContent = "";
    const left = gallery.max - gallery.photos.length;
    const tooBig = files.filter((f) => f.size > 10 * 1024 * 1024);
    let queue = files.filter((f) => f.size <= 10 * 1024 * 1024);
    if (queue.length > left) {
      err.textContent = `Only ${left} more ${left === 1 ? "photo fits" : "photos fit"} - the first ${left} will be added.`;
      queue = queue.slice(0, left);
    }
    if (!queue.length) {
      if (tooBig.length) err.textContent = "Each photo must be 10 MB or smaller.";
      return;
    }
    const bar = $("#galProgress");
    bar.hidden = false;
    let added = 0;
    const failed = [];
    for (const [i, f] of queue.entries()) {
      $("#galProgressText").textContent = `Adding ${i + 1} of ${queue.length} - ${f.name}`;
      $("#galProgressPct").textContent = `${Math.round((i / queue.length) * 100)}%`;
      $("#galProgressBar").style.width = `${(i / queue.length) * 100}%`;
      const res = await SettingsAPI.uploadPhoto(f);
      if (res.ok) {
        gallery = res.data;
        added++;
      } else failed.push(`${f.name}: ${res.message}`);
    }
    drawGallery();
    drawPreview();
    SettingsRail.setAttention("profile", completeness(current()).percent < 100);
    const errEl = $('[data-error-for="photos"]');
    const notes = [...(tooBig.length ? [`${tooBig.length} over 10 MB ${tooBig.length === 1 ? "was" : "were"} left out`] : []), ...failed];
    if (notes.length) errEl.textContent = notes.join(" · ");
    if (added) Toast.success(added === 1 ? "Photo added" : `${added} photos added`);
  }

  async function move(id, by) {
    const ids = gallery.photos.map((p) => p.id);
    const i = ids.indexOf(id);
    const j = i + by;
    if (i < 0 || j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    const res = await SettingsAPI.orderPhotos(ids);
    if (!res.ok) return Toast.error(res.message);
    gallery = res.data;
    drawGallery();
    drawPreview();
  }

  async function removePhoto(id, btn) {
    UI.setButtonLoading(btn, "");
    const res = await SettingsAPI.removePhoto(id);
    if (!res.ok) {
      UI.restoreButton(btn);
      return Toast.error(res.message);
    }
    gallery = res.data;
    drawGallery();
    drawPreview();
    SettingsRail.setAttention("profile", completeness(current()).percent < 100);
    Toast.success("Photo removed");
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
      gallery = { photos: [], max: 30 };
      draw();
      loadGallery();
    },
    isDirty() {
      if (!root || !data || !$('[name="name"]')) return 0;
      const now = current();
      return FIELDS.filter((f) => String(now[f] ?? "") !== String(snapshot[f] ?? "")).length;
    },
    discard() {
      draw();
      loadGallery();
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
      drawYouTube();
      drawPreview();
      SettingsRail.setAttention("profile", data.completeness.percent < 100);
      Toast.success(res.message);
      return true;
    },
  };
})();
