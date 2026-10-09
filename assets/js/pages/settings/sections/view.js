/**
 * SETTINGS - View profile: everything this place set up, in one read-only
 * page, as the diocese and visitors see it - the cover photo and logo, who we
 * are, when we meet, photos, services online, how to reach us and the map
 * pin, our leaders and (a church) a few totals. Each part links to where it
 * is changed, for those who can. GET /settings/view.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;
  const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const TYPE_LABEL = { church: "Church", region: "Region", diocese: "Diocese" };
  const pinIcon = () => L.divIcon({ className: "settings-map-pin", html: '<i class="ri-map-pin-2-fill"></i>', iconSize: [34, 34], iconAnchor: [17, 32] });
  const withScheme = (u) => (/^https?:\/\//i.test(u) ? u : `https://${u}`);
  const href = (key) => SettingsRail.hrefFor(SettingsRail.section(key) || { key, kind: "custom" });
  const go = (key, label) => `<a class="fw-semibold mb-link fs-13" href="${esc(href(key))}" data-go="${esc(key)}">${esc(label)}<i class="ri-arrow-right-line ms-1"></i></a>`;
  const ampm = (t) => {
    if (!t) return "";
    const [h, m] = t.split(":").map(Number);
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, "0")} ${h < 12 ? "am" : "pm"}`;
  };
  const initials = (name) => String(name || "?").split(/\s+/).filter((w) => /^[A-Za-z]/.test(w)).slice(0, 2).map((w) => w[0]).join("").toUpperCase() || "?";

  let map = null;
  let lightbox = null;

  function hero(d) {
    const p = d.profile;
    const cover = d.photos[0];
    return `<div class="card custom-card pv-hero" id="card-who">
      <div class="pv-cover${cover ? "" : " is-plain"}">${cover ? `<img src="${esc(cover.url)}" alt="${esc(cover.caption || p.name)}">` : ""}</div>
      <div class="card-body pv-hero-body">
        ${p.logo_url ? `<img class="pv-logo" src="${esc(p.logo_url)}" alt="">` : `<span class="pv-logo is-initials bg-purple text-white">${esc(initials(p.name))}</span>`}
        <div class="pv-hero-text">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div class="min-w-0">
              <h2 class="pv-name">${esc(p.name)}</h2>
              <div class="d-flex flex-wrap align-items-center gap-1">
                <span class="badge bg-primary">${TYPE_LABEL[p.type] || esc(p.type)}</span>
                ${p.parent ? `<span class="soft-chip soft-primary"><i class="ri-map-2-line"></i>${esc(p.parent.name)}</span>` : ""}
                ${p.established_date ? `<span class="soft-chip soft-success"><i class="ri-calendar-line"></i>Since ${new Date(p.established_date).getFullYear()}</span>` : ""}
              </div>
            </div>
            ${d.can.profile ? `<a class="btn btn-primary btn-sm" href="${esc(href("profile"))}" data-go="profile"><i class="ri-edit-line me-1"></i>Change profile</a>` : ""}
          </div>
          ${p.description ? `<p class="pv-about">${esc(p.description)}</p>` : d.can.profile ? `<p class="pv-about mb-0">No words about us yet. ${go("profile", "Add a few lines")}</p>` : ""}
        </div>
      </div>
    </div>`;
  }

  function times(d) {
    if (d.service_times === null) return "";
    const list = d.service_times;
    return F.card({
      id: "card-meet",
      title: "When we meet",
      icon: "ri-time-line",
      colour: "success",
      sub: list.length ? `${list.length} ${list.length === 1 ? "service" : "services"} a week` : "No services added yet",
      actions: d.can.servicetimes ? go("servicetimes", list.length ? "Change" : "Add service times") : "",
      body: list.length
        ? `<ul class="pv-times">${list
            .map(
              (t) => `<li>
                <span class="pv-day"><b>${DAYS[t.day].slice(0, 3)}</b></span>
                <div class="flex-fill min-w-0"><strong>${esc(t.name)}</strong><small>${esc([DAYS[t.day], t.gathering, t.language].filter(Boolean).join(" · "))}</small></div>
                <span class="pv-time">${ampm(t.start)}${t.end ? ` - ${ampm(t.end)}` : ""}</span>
              </li>`,
            )
            .join("")}</ul>`
        : '<p class="mb-0 fw-semibold">Add when you meet, so visitors know when to come.</p>',
    });
  }

  function photos(d) {
    const list = d.photos;
    return F.card({
      id: "card-photos",
      title: "Photos",
      icon: "ri-image-2-line",
      colour: "purple",
      sub: list.length ? `${list.length} ${list.length === 1 ? "photo" : "photos"} - tap one to see it full size` : "No photos yet",
      actions: d.can.profile && list.length < 3 ? go("profile", "Add photos") : d.can.profile ? go("profile", "Change") : "",
      body: list.length
        ? `<div class="gal-grid pv-gallery">${list.map((ph, i) => `<figure class="gal-item"><a class="gal-thumb" href="${esc(ph.url)}" data-gallery="pv" ${ph.caption ? `data-title="${esc(ph.caption)}"` : ""}><img src="${esc(ph.thumb_url)}" alt="${esc(ph.caption || `Photo ${i + 1}`)}" loading="lazy"></a>${ph.caption ? `<figcaption>${esc(ph.caption)}</figcaption>` : ""}</figure>`).join("")}</div>`
        : '<div class="gal-empty"><i class="ri-gallery-line"></i><span>No photos yet - your church, a Sunday service or an event.</span></div>',
    });
  }

  function online(d) {
    const p = d.profile;
    if (!p.youtube_url) return "";
    return F.card({
      id: "card-online",
      title: "Services online",
      icon: "ri-youtube-line",
      colour: "danger",
      sub: p.youtube_video ? "Our latest service" : "Our YouTube channel",
      body: p.youtube_video
        ? `<div class="ratio ratio-16x9 settings-yt-player"><iframe src="https://www.youtube-nocookie.com/embed/${esc(p.youtube_video)}" title="Our service on YouTube" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`
        : `<a class="settings-yt-channel" href="${esc(withScheme(p.youtube_url))}" target="_blank" rel="noopener"><span class="settings-yt-icon is-solid"><i class="ri-youtube-fill"></i></span><div class="min-w-0"><strong>Our YouTube channel</strong><small class="text-break">${esc(p.youtube_url)}</small></div><i class="ri-external-link-line ms-auto"></i></a>`,
    });
  }

  function findUs(d) {
    const p = d.profile;
    const where = [p.address, p.town, p.sub_county, p.county].filter(Boolean).join(", ");
    const line = (icon, text, link) => (text ? `<li><i class="${icon}"></i>${link ? `<a href="${esc(link)}"${link.startsWith("http") ? ' target="_blank" rel="noopener"' : ""}>${esc(text)}</a>` : `<span>${esc(text)}</span>`}</li>` : "");
    const pinned = p.latitude !== null && p.longitude !== null;
    return F.card({
      id: "card-find",
      title: "Find us",
      icon: "ri-map-pin-line",
      colour: "primary",
      actions: d.can.profile ? go("profile", "Change") : "",
      body: `<ul class="list-unstyled settings-facts mb-3">
          ${line("ri-phone-line", p.phone, p.phone ? `tel:${p.phone.replace(/\s+/g, "")}` : null)}
          ${line("ri-mail-line", p.email, p.email ? `mailto:${p.email}` : null)}
          ${line("ri-global-line", p.website, p.website ? withScheme(p.website) : null)}
          ${line("ri-map-pin-line", where)}
          ${line("ri-mail-send-line", p.postal_code ? `Postal code ${p.postal_code}` : "")}
        </ul>
        ${!p.phone && !p.email && !where ? '<p class="fw-semibold">No contact details yet.</p>' : ""}
        ${pinned ? '<div class="settings-map pv-map" id="pvMap"></div>' : `<div class="gal-empty"><i class="ri-map-pin-add-line"></i><span>No map pin yet${d.can.profile ? ` - ${go("profile", "add it")}` : ""}</span></div>`}
        ${pinned ? `<a class="fw-semibold mb-link fs-13 d-inline-block mt-2" href="https://www.google.com/maps/dir/?api=1&destination=${p.latitude},${p.longitude}" target="_blank" rel="noopener"><i class="ri-direction-line me-1"></i>Directions</a>` : ""}`,
    });
  }

  function leaders(d) {
    const list = d.leaders;
    return F.card({
      id: "card-leaders",
      title: "Our leaders",
      icon: "ri-team-line",
      colour: "pink",
      sub: list.length ? `${list.length} ${list.length === 1 ? "person" : "people"}` : "None yet",
      actions: d.can.team ? go("team", "Team") : "",
      body: list.length
        ? `<ul class="mb-mini-list">${list.map((l) => `<li><span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(l.name)} text-white flex-shrink-0">${esc(l.initials)}</span><div class="flex-fill min-w-0"><strong class="d-block">${esc(l.name)}</strong><small class="mb-sub">${esc(l.role || "")}</small></div></li>`).join("")}</ul>`
        : '<p class="mb-0 fw-semibold">No leaders added yet.</p>',
    });
  }

  function glance(d) {
    const g = d.glance;
    if (!g) return "";
    const fact = (icon, colour, n, label) => `<div class="pv-fact"><span class="avatar avatar-sm bg-${colour} ${colour === "warning" ? "text-dark" : "text-white"}"><i class="${icon}"></i></span><div><strong>${Number(n).toLocaleString()}</strong><small>${label}</small></div></div>`;
    return F.card({
      id: "card-glance",
      title: "At a glance",
      icon: "ri-bar-chart-box-line",
      colour: "warning",
      sub: "Totals only - never names",
      body: `<div class="pv-facts">
          ${fact("ri-contacts-book-2-line", "primary", g.members, g.members === 1 ? "Member" : "Members")}
          ${fact("ri-team-line", "pink", g.ministries, g.ministries === 1 ? "Ministry" : "Ministries")}
          ${fact("ri-door-open-line", "success", g.rooms, g.rooms === 1 ? "Room" : "Rooms")}
          ${fact("ri-time-line", "warning", g.services, g.services === 1 ? "Service a week" : "Services a week")}
        </div>`,
    });
  }

  function drawMap(p) {
    const box = document.getElementById("pvMap");
    if (!box) return;
    if (!window.L) return (box.innerHTML = '<div class="p-3 fs-13">The map couldn\'t load.</div>');
    map?.remove();
    map = L.map(box, { scrollWheelZoom: false }).setView([p.latitude, p.longitude], 15);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "&copy; OpenStreetMap contributors" }).addTo(map);
    L.marker([p.latitude, p.longitude], { icon: pinIcon(), keyboard: false, title: p.name }).addTo(map);
    setTimeout(() => map && map.invalidateSize(), 150);
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.view = {
    async render(body) {
      const res = await SettingsAPI.profileView();
      if (!res.ok) {
        body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
        return;
      }
      const d = res.data;
      body.innerHTML = `
        ${hero(d)}
        <div class="row align-items-start">
          <div class="col-xl-8">${times(d)}${photos(d)}${online(d)}</div>
          <div class="col-xl-4">${findUs(d)}${leaders(d)}${glance(d)}</div>
        </div>`;
      body.querySelectorAll("[data-go]").forEach((a) =>
        a.addEventListener("click", (e) => {
          e.preventDefault();
          SettingsHub.show(a.dataset.go);
        }),
      );
      drawMap(d.profile);
      if (window.GLightbox) {
        lightbox?.destroy();
        lightbox = d.photos.length ? GLightbox({ selector: ".pv-gallery .gal-thumb" }) : null;
      }
      SettingsHub.subLinks(
        [
          { id: "card-who", label: "Who we are" },
          d.service_times !== null ? { id: "card-meet", label: "When we meet" } : null,
          { id: "card-photos", label: "Photos" },
          d.profile.youtube_url ? { id: "card-online", label: "Services online" } : null,
          { id: "card-find", label: "Find us" },
          { id: "card-leaders", label: "Our leaders" },
          d.glance ? { id: "card-glance", label: "At a glance" } : null,
        ].filter(Boolean),
      );
    },
  };
})();
