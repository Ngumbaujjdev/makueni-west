/**
 * SETTINGS - Access control (diocese): the user, role, permission and menu
 * pages, as cards with how many of each there are (GET /settings/access).
 * Each role sees only the pages it can open; the pages themselves are
 * unchanged.
 */
(function () {
  "use strict";

  const F = window.SettingsFields;
  const esc = F.esc;

  const NOUNS = { users: ["person", "people"], roles: ["role", "roles"], permissions: ["permission", "permissions"], modules: ["module switched on", "modules switched on"], groups: ["group", "groups"] };

  function card(link) {
    const href = SettingsRail.hrefFor({ kind: "link", url: `/${link.url}` });
    const noun = NOUNS[link.key] || ["", ""];
    const count = link.count === null || link.count === undefined ? "" : `${link.count.toLocaleString()} ${link.count === 1 ? noun[0] : noun[1]}`;
    return `
      <div class="col-xl-4 col-md-6">
        <a class="card custom-card settings-link-card h-100" href="${esc(href)}">
          <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex align-items-center gap-3">
              <span class="avatar avatar-md bg-${esc(link.colour)} ${F.textOn(link.colour)}"><i class="${esc(link.icon)}"></i></span>
              <div class="flex-fill">
                <div class="fw-semibold fs-15">${esc(link.label)}</div>
                ${count ? `<span class="soft-chip soft-${esc(link.colour)}">${esc(count)}</span>` : ""}
              </div>
              <i class="ri-arrow-right-up-line fs-18 text-primary"></i>
            </div>
            <p class="mb-0 fs-13">${esc(link.sentence)}</p>
          </div>
        </a>
      </div>`;
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.access = {
    async render(body) {
      const res = await SettingsAPI.access();
      if (!res.ok) {
        body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
        return;
      }
      const links = res.data.links;
      body.innerHTML = `
        <div class="row" id="card-pages">${links.map(card).join("")}</div>
        <div class="alert alert-primary d-flex align-items-start gap-2 mb-0">
          <i class="ri-information-line fs-18"></i>
          <span>Each church, region and the diocese can also add their own people from <b>Leadership &amp; team</b>, with roles below their own.</span>
        </div>`;
      SettingsHub.subLinks([{ id: "card-pages", label: "Pages" }]);
    },
  };
})();
