/**
 * SETTINGS - Overview: how complete this place's details are, the setup
 * checklist, and (region / diocese) which churches below still have gaps.
 * GET /settings/overview (+ /settings/profile for the place card).
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;

  function ago(iso) {
    if (!iso) return "";
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return "Just now";
    if (s < 3600) return `${Math.round(s / 60)} min ago`;
    if (s < 86400) return `${Math.round(s / 3600)} h ago`;
    const d = Math.round(s / 86400);
    return d === 1 ? "Yesterday" : d < 30 ? `${d} days ago` : new Date(iso).toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
  }

  const SECTION_LABELS = { profile: "Profile", servicetimes: "Service times" };
  const href = (key) => SettingsRail.hrefFor(SettingsRail.section(key) || { key, kind: "custom" });
  const barColour = (p) => (p >= 100 ? "success" : p >= 50 ? "secondary" : "danger");

  function kpis(o) {
    const cards = [
      UI.renderSparkCard({
        icon: "ri-community-line",
        label: "Profile complete",
        value: `${o.profile.percent}%`,
        color: "purple",
        sub: `${o.profile.done} of ${o.profile.total} details`,
        link: o.profile.percent < 100 ? { href: href("profile"), text: "Finish the profile" } : null,
      }),
      UI.renderSparkCard({
        icon: "ri-team-line",
        label: "People with a role here",
        value: String(o.team.people),
        color: "primary",
        sub: "Leaders and office holders",
        link: SettingsRail.section("team") ? { href: href("team"), text: "See the team" } : null,
      }),
    ];
    if (o.service_times.shown) {
      cards.push(
        UI.renderSparkCard({
          icon: "ri-time-line",
          label: "Service times",
          value: String(o.service_times.count),
          color: "success",
          sub: o.service_times.count ? "A week's services" : "None added yet",
          link: o.service_times.count ? null : { href: href("servicetimes"), text: "Add your services" },
        }),
      );
    }
    cards.push(
      UI.renderSparkCard({
        icon: "ri-history-line",
        label: "Last change",
        value: o.last_change ? ago(o.last_change.at) : "None yet",
        color: "pink",
        sub: o.last_change ? [o.last_change.by, SECTION_LABELS[o.last_change.section] || o.last_change.section].filter(Boolean).join(" · ") : "Nothing has been changed here",
      }),
    );
    const col = cards.length === 4 ? "col-xxl-3 col-md-6" : "col-xl-4 col-md-6";
    return `<div class="row">${cards.map((c) => `<div class="${col}">${c}</div>`).join("")}</div>`;
  }

  function checklist(o) {
    const done = o.checklist.filter((c) => c.done).length;
    const total = o.checklist.length;
    const pct = total ? Math.round((done / total) * 100) : 100;
    return F.card({
      id: "card-checklist",
      title: "Setup checklist",
      icon: "ri-list-check-2",
      colour: "success",
      sub: "What others need to find and reach you",
      actions: `<span class="soft-chip soft-${pct === 100 ? "success" : "warning"}">${done} of ${total} done</span>${pct === 100 && SettingsRail.section("view") ? `<a class="btn btn-sm btn-primary" href="${esc(href("view"))}" data-go="view"><i class="ri-eye-line me-1"></i>See the profile</a>` : ""}`,
      body: `
        <div class="progress progress-sm mb-3" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100" aria-label="Setup progress">
          <div class="progress-bar bg-${barColour(pct)}" style="width: ${pct}%"></div>
        </div>
        <ul class="list-unstyled settings-checklist mb-0">
          ${o.checklist
            .map(
              (c) => `
            <li>
              <span class="avatar avatar-xs ${c.done ? "bg-success text-white" : "bg-secondary text-dark"}"><i class="${c.done ? "ri-check-line" : "ri-add-line"}"></i></span>
              <span class="flex-fill">${esc(c.label)}</span>
              ${c.done ? '<span class="soft-chip soft-success">Done</span>' : `<a class="btn btn-sm btn-outline-primary" href="${esc(href(c.section))}" data-go="${esc(c.section)}">Add it</a>`}
            </li>`,
            )
            .join("")}
        </ul>`,
    });
  }

  function placeCard(p) {
    if (!p) return "";
    const initials = String(p.name || "?").split(/\s+/).filter((w) => /^[A-Za-z]/.test(w)).slice(0, 2).map((w) => w[0]).join("").toUpperCase();
    const line = (icon, text, link) => (text ? `<li><i class="${icon}"></i>${link ? `<a href="${esc(link)}" target="_blank" rel="noopener">${esc(text)}</a>` : esc(text)}</li>` : "");
    return F.card({
      id: "card-place",
      title: "Your place",
      icon: "ri-map-pin-user-line",
      colour: "purple",
      sub: "As the diocese and visitors see it",
      body: `
        <div class="d-flex align-items-center gap-3 mb-3">
          ${p.logo_url ? `<img class="settings-place-logo" src="${esc(p.logo_url)}" alt="">` : `<span class="settings-place-logo is-initials bg-purple text-white">${esc(initials)}</span>`}
          <div>
            <div class="fw-semibold fs-16">${esc(p.name)}</div>
            <div class="d-flex flex-wrap gap-1 mt-1">
              <span class="badge bg-primary text-capitalize">${esc(p.type)}</span>
              ${p.parent ? `<span class="soft-chip soft-primary">${esc(p.parent.name)}</span>` : ""}
            </div>
          </div>
        </div>
        <ul class="list-unstyled settings-facts mb-0">
          ${line("ri-phone-line", p.phone)}
          ${line("ri-mail-line", p.email, p.email ? `mailto:${p.email}` : null)}
          ${line("ri-global-line", p.website, p.website)}
          ${line("ri-map-pin-line", [p.address, p.town, p.county].filter(Boolean).join(", "))}
        </ul>
        ${!p.phone && !p.email && !p.address ? `<div class="alert alert-primary mb-0 mt-2">No contact details yet. <a href="${esc(href("profile"))}" data-go="profile">Add them</a></div>` : ""}
        ${SettingsRail.section("view") ? `<a class="btn btn-primary w-100 mt-3" href="${esc(href("view"))}" data-go="view"><i class="ri-eye-line me-1"></i>See our full profile</a>` : ""}`,
    });
  }

  function belowCard(o, level) {
    const b = o.below;
    if (!b) return "";
    return F.card({
      id: "card-below",
      title: level === "region" ? "Our churches' details" : "Churches' details",
      icon: "ri-building-line",
      colour: "secondary",
      sub: "How complete each church's profile is",
      body: `
        <div class="settings-figure-strip soft-secondary mb-3">
          <div><strong>${b.total}</strong><span>Churches</span></div>
          <div><strong>${b.complete}</strong><span>Complete</span></div>
          <div><strong>${b.average}%</strong><span>Average</span></div>
        </div>
        ${b.missing.length
          ? `<ul class="list-unstyled settings-below-list mb-0">${b.missing
              .map(
                (c) => `
              <li>
                <span class="flex-fill text-truncate">${esc(c.name)}</span>
                <div class="progress progress-xs flex-shrink-0" style="width: 6rem" aria-label="${c.percent}% complete"><div class="progress-bar bg-${barColour(c.percent)}" style="width: ${c.percent}%"></div></div>
                <span class="badge bg-${barColour(c.percent)} ${c.percent >= 50 && c.percent < 100 ? "text-dark" : "text-white"}">${c.percent}%</span>
              </li>`,
              )
              .join("")}</ul>`
          : '<div class="alert alert-success mb-0">Every church has filled in its profile.</div>'}`,
    });
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.overview = {
    async render(body, ctx) {
      const [res, profile] = await Promise.all([SettingsAPI.overview(), SettingsAPI.profile()]);
      if (!res.ok) {
        body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
        return;
      }
      const o = res.data;
      body.innerHTML = `
        ${kpis(o)}
        <div class="row align-items-start">
          <div class="col-xl-7">${checklist(o)}</div>
          <div class="col-xl-5">${o.below ? belowCard(o, ctx.level) : placeCard(profile.ok ? profile.data.profile : null)}</div>
        </div>
        ${o.below && profile.ok ? `<div class="row"><div class="col-xl-7">${placeCard(profile.data.profile)}</div></div>` : ""}`;
      body.querySelectorAll("[data-go]").forEach((a) =>
        a.addEventListener("click", (e) => {
          e.preventDefault();
          SettingsHub.show(a.dataset.go);
        }),
      );
      SettingsHub.subLinks([
        { id: "card-checklist", label: "Setup checklist" },
        ...(o.below ? [{ id: "card-below", label: ctx.level === "region" ? "Our churches' details" : "Churches' details" }] : []),
        { id: "card-place", label: "Your place" },
      ]);
    },
  };
})();
