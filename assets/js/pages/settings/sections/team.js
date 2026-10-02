/**
 * SETTINGS - Leadership & team: the people with a role at this place
 * (GET / POST /settings/team, PUT / DELETE /settings/team/{id},
 * POST /settings/team/{id}/reset-access). A manager adds people with roles
 * below their own; a new person's employee code and temporary password are
 * shown once, with copy buttons.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;
  const AVATAR_COLOURS = ["primary", "purple", "success", "pink", "danger", "secondary"];

  let root = null;
  let data = null;

  const colourFor = (id) => AVATAR_COLOURS[Number(id) % AVATAR_COLOURS.length];
  const textOn = (c) => (c === "secondary" ? "text-dark" : "text-white");

  function ago(iso) {
    if (!iso) return '<span class="soft-chip soft-warning">Not signed in yet</span>';
    const d = Math.round((Date.now() - new Date(iso).getTime()) / 86400000);
    return d <= 0 ? "Today" : d === 1 ? "Yesterday" : d < 30 ? `${d} days ago` : new Date(iso).toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
  }

  function kpis() {
    const c = data.counts;
    const roles = Object.keys(c.roles || {}).length;
    const cards = [
      UI.renderSparkCard({ icon: "ri-team-line", label: "People with a role here", value: String(c.people), color: "pink", sub: "Leaders and office holders" }),
      UI.renderSparkCard({ icon: "ri-shield-user-line", label: "Can manage the team", value: String(c.managers), color: "primary", sub: c.managers === 1 ? "Only one - consider a second" : "Add, change and remove people" }),
      UI.renderSparkCard({ icon: "ri-user-star-line", label: "Different roles", value: String(roles), color: "success", sub: Object.entries(c.roles || {}).slice(0, 2).map(([r, n]) => `${n} ${r}`).join(" · ") || "No roles yet" }),
    ];
    return `<div class="row">${cards.map((k) => `<div class="col-xl-4 col-md-6">${k}</div>`).join("")}</div>`;
  }

  function row(p) {
    const colour = colourFor(p.user.id);
    const contact = [p.user.phone, p.user.email].filter(Boolean).map(esc).join(" · ");
    const actions = [];
    if (p.can.change) actions.push(`<button type="button" class="btn btn-icon btn-sm btn-primary" data-act="role" data-id="${p.assignment_id}" title="Change role" aria-label="Change ${esc(p.user.name)}'s role"><i class="ri-user-settings-line"></i></button>`);
    if (p.can.reset) actions.push(`<button type="button" class="btn btn-icon btn-sm btn-secondary text-dark" data-act="reset" data-id="${p.assignment_id}" title="New sign-in details" aria-label="New sign-in details for ${esc(p.user.name)}"><i class="ri-key-2-line"></i></button>`);
    if (p.can.remove) actions.push(`<button type="button" class="btn btn-icon btn-sm btn-danger" data-act="remove" data-id="${p.assignment_id}" title="Remove from the team" aria-label="Remove ${esc(p.user.name)}"><i class="ri-user-unfollow-line"></i></button>`);
    return `
      <tr data-row-id="${p.assignment_id}">
        <td>
          <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm avatar-rounded bg-${colour} ${textOn(colour)} flex-shrink-0">${esc(p.user.initials)}</span>
            <div style="min-width:0">
              <div class="fw-semibold">${esc(p.user.name)}${p.is_me ? ' <span class="soft-chip soft-primary ms-1">You</span>' : ""}</div>
              <div class="fs-12 text-break">${contact || "No contact details"}</div>
            </div>
          </div>
        </td>
        <td data-order="${esc(p.role.name)}">
          <span class="badge bg-${p.role.manager ? "primary" : "purple"}">${esc(p.role.name || "No role")}</span>
          ${p.role.manager ? '<div class="mt-1"><span class="soft-chip soft-success">Manages the team</span></div>' : ""}
        </td>
        <td data-order="${esc(p.user.last_login_at || "")}">${ago(p.user.last_login_at)}</td>
        <td class="text-end text-nowrap"><div class="d-inline-flex gap-1">${actions.join("") || (p.is_me ? '<span class="fs-12">That\'s you</span>' : "")}</div></td>
      </tr>`;
  }

  function draw() {
    const can = !!data.can?.manage;
    root.innerHTML = `
      ${kpis()}
      ${F.card({
        id: "card-people",
        title: "People",
        icon: "ri-team-line",
        colour: "pink",
        sub: can ? "Add people with a role below yours. They sign in with their employee code." : "Who serves here. Ask a manager to add or change people.",
        actions: can && data.grantable.length ? '<button type="button" class="btn btn-primary btn-sm" id="addPerson"><i class="ri-user-add-line me-1"></i>Add someone</button>' : "",
        body: `
          <div class="table-responsive">
            <table class="table align-middle mb-0 team-table" id="teamTable">
              <thead><tr><th class="all">Person</th><th data-priority="1">Role</th><th data-priority="2">Last signed in</th><th class="text-end all">Actions</th></tr></thead>
              <tbody>${data.people.length ? data.people.map(row).join("") : '<tr><td colspan="4" class="text-center py-4">Nobody has a role here yet.</td></tr>'}</tbody>
            </table>
          </div>`,
      })}`;
    UI.initListDataTable("teamTable", { searchPlaceholder: "Search people…", noun: "people", order: [], nonSortableColumns: [3], pageLength: 25 });
    root.querySelector("#addPerson")?.addEventListener("click", openAdd);
    root.querySelector("#teamTable").addEventListener("click", (e) => {
      const btn = e.target.closest("[data-act]");
      if (!btn) return;
      const person = data.people.find((p) => String(p.assignment_id) === btn.dataset.id);
      if (!person) return;
      if (btn.dataset.act === "role") openRole(person);
      if (btn.dataset.act === "reset") confirmReset(person);
      if (btn.dataset.act === "remove") confirmRemove(person);
    });
    SettingsHub.subLinks([{ id: "card-people", label: "People" }]);
  }

  /* ---------- the window (one, reused) ---------- */

  function modal() {
    let el = document.getElementById("teamModal");
    if (el) return el;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="teamModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="teamModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon bg-pink" id="teamModalIcon"><i class="ri-user-add-line"></i></span>
              <div class="flex-fill" style="min-width:0">
                <h5 class="modal-title" id="teamModalTitle">Add someone</h5>
                <div class="app-modal-subtitle" id="teamModalSub">&nbsp;</div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="teamModalBody"></div>
            <div class="modal-footer" id="teamModalFoot"></div>
          </div>
        </div>
      </div>`,
    );
    return document.getElementById("teamModal");
  }

  function open({ icon, colour, title, sub, body, foot }) {
    const el = modal();
    const ic = el.querySelector("#teamModalIcon");
    ic.className = `app-modal-icon bg-${colour}`;
    ic.innerHTML = `<i class="${icon}"></i>`;
    el.querySelector("#teamModalTitle").textContent = title;
    el.querySelector("#teamModalSub").textContent = sub;
    el.querySelector("#teamModalBody").innerHTML = body;
    el.querySelector("#teamModalFoot").innerHTML = foot;
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  const roleOptions = (selected) => data.grantable.map((r) => `<option value="${r.id}"${String(r.id) === String(selected) ? " selected" : ""}>${esc(r.name)}</option>`).join("");

  function showErrors(el, res) {
    el.querySelectorAll("[data-error-for]").forEach((e) => (e.textContent = ""));
    el.querySelectorAll(".is-invalid").forEach((e) => e.classList.remove("is-invalid"));
    Object.entries(res.errors || {}).forEach(([k, msgs]) => {
      el.querySelector(`[name="${k}"]`)?.classList.add("is-invalid");
      const e = el.querySelector(`[data-error-for="${k}"]`);
      if (e) e.textContent = [].concat(msgs)[0];
    });
    if (!res.errors) Toast.error(res.message);
  }

  function credentialsHtml(name, creds) {
    const copy = (label, value) => `
      <div class="team-credential">
        <span class="team-credential-label">${label}</span>
        <code class="team-credential-value">${esc(value)}</code>
        <button type="button" class="btn btn-sm btn-primary" data-copy="${esc(value)}"><i class="ri-file-copy-line me-1"></i>Copy</button>
      </div>`;
    return `
      <div class="app-modal-state is-done mb-3">
        <span class="avatar avatar-md bg-success text-white"><i class="ri-check-line"></i></span>
        <div><b>Sign-in details for ${esc(name)}</b><br>Share them privately. The password is shown only this once, and they'll choose their own at first sign-in.</div>
      </div>
      <div class="soft-primary rounded p-3">
        ${copy("Employee code", creds.employee_code)}
        ${copy("Temporary password", creds.temporary_password)}
      </div>
      <div class="fs-12 mt-2">They sign in on the login page's password tab with the employee code (or their email) and this password. Keep the employee code private - treat it like a password.</div>`;
  }

  function wireCopy(el) {
    el.querySelectorAll("[data-copy]").forEach((b) =>
      b.addEventListener("click", async () => {
        try {
          await navigator.clipboard.writeText(b.dataset.copy);
          b.innerHTML = '<i class="ri-check-line me-1"></i>Copied';
        } catch (e) {
          Toast.warning("Couldn't copy - select the text and copy it instead.");
        }
      }),
    );
  }

  function openAdd() {
    const el = open({
      icon: "ri-user-add-line",
      colour: "pink",
      title: "Add someone to the team",
      sub: `With a role below yours at ${SettingsRail.data?.place?.name || "this place"}`,
      body: `
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label" for="tmFirst">First name</label><input class="form-control" id="tmFirst" name="firstname" maxlength="255"><div class="invalid-feedback" data-error-for="firstname"></div></div>
          <div class="col-sm-6"><label class="form-label" for="tmLast">Last name</label><input class="form-control" id="tmLast" name="lastname" maxlength="255"><div class="invalid-feedback" data-error-for="lastname"></div></div>
          <div class="col-sm-6"><label class="form-label" for="tmPhone">Phone</label><input class="form-control" id="tmPhone" name="phone" type="tel" maxlength="30" placeholder="0712 345 678"><div class="invalid-feedback" data-error-for="phone"></div></div>
          <div class="col-sm-6"><label class="form-label" for="tmEmail">Email <span class="fw-normal">(optional)</span></label><input class="form-control" id="tmEmail" name="email" type="email" maxlength="255"><div class="invalid-feedback" data-error-for="email"></div></div>
          <div class="col-12"><label class="form-label" for="tmRole">Role</label><select class="form-select" id="tmRole" name="role_id">${roleOptions(data.grantable[data.grantable.length > 2 ? 2 : 0]?.id)}</select><div class="invalid-feedback d-block" data-error-for="role_id"></div></div>
        </div>
        <div class="soft-primary rounded p-2 mt-3 fs-13"><i class="ri-information-line me-1"></i>If they already have an account (same phone or email), they're just given the role here.</div>`,
      foot: '<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="tmSave"><i class="ri-user-add-line me-1"></i>Add to the team</button>',
    });
    UI.enhanceSelect(el.querySelector("#tmRole"), { dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    setTimeout(() => el.querySelector("#tmFirst")?.focus(), 300);
    el.querySelector("#tmSave").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Adding…");
      const val = (n) => el.querySelector(`[name="${n}"]`).value.trim() || null;
      const res = await SettingsAPI.addPerson({ firstname: val("firstname"), lastname: val("lastname"), phone: val("phone"), email: val("email"), role_id: Number(el.querySelector("#tmRole").value) });
      UI.restoreButton(btn);
      if (!res.ok) return showErrors(el, res);
      data = res.data.team;
      draw();
      UI.flashRow(res.data.person.assignment_id);
      SettingsRail.setAttention("team", false);
      if (res.data.credentials) {
        el.querySelector("#teamModalTitle").textContent = `${res.data.person.user.name} is on the team`;
        el.querySelector("#teamModalSub").textContent = res.data.person.role.name;
        el.querySelector("#teamModalBody").innerHTML = credentialsHtml(res.data.person.user.name, res.data.credentials);
        el.querySelector("#teamModalFoot").innerHTML = '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>';
        wireCopy(el);
      } else {
        bootstrap.Modal.getInstance(el)?.hide();
        Toast.success(res.message);
      }
    });
  }

  function openRole(person) {
    const el = open({
      icon: "ri-user-settings-line",
      colour: "primary",
      title: `Change ${person.user.name}'s role`,
      sub: `Now: ${person.role.name}`,
      body: `<label class="form-label" for="tmNewRole">New role</label><select class="form-select" id="tmNewRole" name="role_id">${roleOptions(person.role.id)}</select><div class="invalid-feedback d-block" data-error-for="role_id"></div>`,
      foot: '<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="tmRoleSave"><i class="ri-check-line me-1"></i>Change role</button>',
    });
    UI.enhanceSelect(el.querySelector("#tmNewRole"), { dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    el.querySelector("#tmRoleSave").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Saving…");
      const res = await SettingsAPI.changeRole(person.assignment_id, Number(el.querySelector("#tmNewRole").value));
      UI.restoreButton(btn);
      if (!res.ok) return showErrors(el, res);
      data = res.data;
      draw();
      UI.flashRow(person.assignment_id);
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message);
    });
  }

  function confirmReset(person) {
    Toast.confirm(
      `Give ${esc(person.user.name)} a new temporary password? Their old password stops working and they're signed out everywhere.`,
      async () => {
        const res = await SettingsAPI.resetAccess(person.assignment_id);
        if (!res.ok) return Toast.error(res.message);
        const el = open({ icon: "ri-key-2-line", colour: "secondary", title: `New sign-in details`, sub: person.user.name, body: credentialsHtml(person.user.name, res.data.credentials), foot: '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>' });
        wireCopy(el);
      },
      null,
      { title: "New sign-in details", confirmText: "Yes, reset", cancelText: "Cancel", type: "warning" },
    );
  }

  function confirmRemove(person) {
    Toast.confirm(
      `Remove ${esc(person.user.name)} (${esc(person.role.name)}) from the team here? Their account stays; they just lose this role.`,
      async () => {
        const res = await SettingsAPI.removePerson(person.assignment_id);
        if (!res.ok) return Toast.error(res.message);
        data = res.data;
        draw();
        Toast.success(res.message);
      },
      null,
      { title: "Remove from the team", confirmText: "Remove", cancelText: "Keep", type: "error" },
    );
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.team = {
    async render(body) {
      root = body;
      const res = await SettingsAPI.team();
      if (!res.ok) {
        body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
        return;
      }
      data = res.data;
      draw();
    },
  };
})();
