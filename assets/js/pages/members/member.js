/**
 * ============================================================================
 * MEMBERS - one member's page (member.php?id=)
 * ============================================================================
 * The hero (initials, status, Sunday school or main church, member since,
 * and what you can do), then Overview - their journey and the few details we
 * keep - and History. Windows: transfer out, and remove personal details.
 * The tab is kept in the URL (?tab=).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("id"));
  const state = { p: null, tab: params.get("tab") === "history" ? "history" : "overview", history: null, churches: null };

  const JOURNEY = {
    visited: { icon: "ri-user-heart-line", color: "info" },
    saved: { icon: "ri-heart-line", color: "pink" },
    baptised: { icon: "ri-drop-line", color: "purple" },
    joined: { icon: "ri-home-heart-line", color: "success" },
    transfer_in: { icon: "ri-login-box-line", color: "primary" },
    transfer_out: { icon: "ri-logout-box-r-line", color: "warning" },
  };

  /** A row: small icon tile in its colour, the label, then the value (already escaped). */
  // One calm colour for every detail - the facts are what you read (colour used sparingly, 2026-10-08).
  const row = (icon, color, label, html) =>
    `<li class="mr-row"><div class="mr-row-head"><span class="ev-tile is-sm is-soft" style="--q: var(--primary-rgb)"><i class="${icon}"></i></span>${label}</div><p class="mr-row-body">${html}</p></li>`;
  const notGiven = '<span class="mb-sub">Not given</span>';

  // -------------------------------------------------------------- the hero
  function renderHero() {
    const p = state.p;
    const can = p.can.manage && !p.anonymised;
    const c = M.colorFor(p.id);
    const chips = [
      M.groupChip(p.congregation),
      p.area ? `<span class="soft-chip soft-primary"><i class="ri-map-pin-line"></i>${M.esc(p.area)}</span>` : "",
      p.joined_on ? `<span class="soft-chip soft-success"><i class="ri-calendar-check-line"></i>Member since ${M.day(p.joined_on)}</span>` : "",
      p.how_joined ? `<span class="soft-chip soft-purple">${M.HOW_JOINED[p.how_joined]}</span>` : "",
    ].join("");
    const meta = [p.phone ? `<span><i class="ri-phone-line"></i>${M.esc(p.phone)}</span>` : "", p.gender ? `<span><i class="ri-user-3-line"></i>${p.gender === "male" ? "Male" : "Female"}</span>` : ""].join("");
    const more = can
      ? `<div class="dropdown">
          <button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-more-2-line me-1"></i>More</button>
          <ul class="dropdown-menu dropdown-menu-end">
            ${p.status !== "transferred_out" ? '<li><button class="dropdown-item" data-act="transfer"><i class="ri-logout-box-r-line me-2"></i>Transfer out</button></li>' : ""}
            <li><button class="dropdown-item" data-act="${p.archived ? "restore" : "archive"}"><i class="${p.archived ? "ri-inbox-unarchive-line" : "ri-archive-line"} me-2"></i>${p.archived ? "Bring back from the archive" : "Archive"}</button></li>
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item text-danger" data-act="anonymise"><i class="ri-user-unfollow-line me-2"></i>Remove personal details</button></li>
          </ul>
        </div>`
      : "";
    $("mbHero").innerHTML = `
      <div class="card-body">
        <div class="ev-hero-row">
          <span class="mb-hero-photo is-static"><span class="avatar avatar-xxl avatar-rounded bg-${c} ${M.textOn(c)}">${M.esc(p.initials)}</span></span>
          <div class="flex-fill" style="min-width:0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${M.esc(p.name)}</h2>${M.statusPill(p.status)}</div>
            <div class="d-flex flex-wrap gap-1 mb-1">${chips}</div>
            <div class="ev-card-meta">${meta || '<span class="mb-sub">No phone yet</span>'}</div>
          </div>
          <div class="ev-hero-actions">
            ${can ? `<a class="btn btn-primary" href="${CTX.baseUrl}/new?id=${p.id}"><i class="ri-edit-line me-1"></i>Edit</a>` : ""}
            ${p.came_as_visitor ? `<a class="btn btn-outline-primary" href="${CTX.siteUrl}/church/visitors/visitor?id=${p.id}"><i class="ri-user-heart-line me-1"></i>As a visitor</a>` : ""}
            ${p.phone && !p.anonymised ? `<a class="btn btn-outline-primary" href="${CTX.messagesUrl}?channel=sms&typed=${encodeURIComponent(p.phone)}"><i class="ri-chat-3-line me-1"></i>Send SMS</a>` : ""}
            ${more}
          </div>
        </div>
        ${p.anonymised ? '<div class="alert alert-secondary d-flex gap-2 mt-3 mb-0"><i class="ri-user-unfollow-line fs-16"></i><span>Their personal details were removed. They still count in the totals.</span></div>' : p.archived ? '<div class="alert alert-secondary d-flex gap-2 mt-3 mb-0"><i class="ri-archive-line fs-16"></i><span>Archived - hidden from the lists, but still counted. Use More to bring them back.</span></div>' : ""}
      </div>`;
    M.loadPhotos($("mbHero"));
  }

  // -------------------------------------------------------------- overview
  function renderOverview() {
    const p = state.p;
    const journey = p.journey.length
      ? `<ol class="ev-timeline">${p.journey
          .map((j) => {
            const k = JOURNEY[j.kind] || JOURNEY.joined;
            return `<li class="${k.color === "warning" ? "is-dark" : ""}" style="--q: var(--${k.color}-rgb)"><span class="ev-timeline-dot"></span><div class="flex-fill"><span class="ev-timeline-when">${M.day(j.on)}</span><span class="ev-timeline-what fw-semibold">${M.esc(j.label)}</span></div></li>`;
          })
          .join("")}</ol>`
      : `<p class="mb-0 fw-semibold">No dates yet. Add when they joined, were saved and baptised (Edit → Church life), and their journey shows here.</p>`;
    const details = [
      row("ri-phone-line", "primary", "Phone", p.phone ? M.esc(p.phone) : notGiven),
      row("ri-map-pin-line", "primary", "Area", p.area ? M.esc(p.area) : notGiven),
      row("ri-user-3-line", "primary", "Gender", p.gender ? (p.gender === "male" ? "Male" : "Female") : notGiven),
      row("ri-community-line", "primary", "Part of", p.congregation ? M.CONGREGATIONS[p.congregation].label : notGiven),
      p.previous_church ? row("ri-arrow-left-right-line", "primary", "Previous church", M.esc(p.previous_church)) : "",
    ].join("");
    $("mbMain").innerHTML = `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">Their journey</div></div>
        <div class="card-body">${journey}</div>
      </div>
      <div class="card custom-card">
        <div class="card-header justify-content-between"><div class="card-title">Details</div>${M.privateChip()}</div>
        <div class="card-body"><ul class="mr-rows">${details}</ul></div>
      </div>`;

    const transfers = p.journey.filter((j) => j.kind.startsWith("transfer_"));
    $("mbSide").innerHTML = `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">At a glance</div></div>
        <div class="card-body">
          <div class="mb-glance">
            <div><span>Member since</span><strong>${p.joined_on ? M.day(p.joined_on) : "-"}</strong></div>
            <div><span>Part of</span><strong>${p.congregation ? M.CONGREGATIONS[p.congregation].label : "-"}</strong></div>
            <div><span>Saved</span><strong>${p.saved_on ? M.day(p.saved_on) : "-"}</strong></div>
            <div><span>Baptised</span><strong>${p.baptised_on ? M.day(p.baptised_on) : "Not yet"}</strong></div>
          </div>
        </div>
      </div>
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">Transfers</div></div>
        <div class="card-body">${transfers.length ? `<ul class="mb-mini-list">${transfers.map((t) => `<li><i class="${JOURNEY[t.kind].icon}"></i><div><strong>${M.esc(t.label)}</strong><small>${M.day(t.on)}</small></div></li>`).join("")}</ul>` : '<p class="mb-0 fw-semibold">None.</p>'}</div>
      </div>`;
  }

  // -------------------------------------------------------------- history
  async function renderHistory() {
    $("mbSide").innerHTML = "";
    $("mbMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">History</div></div><div class="card-body" id="historyBody"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:60%"></span></div></div>`;
    if (!state.history) {
      const res = await MembersAPI.history(id);
      if (!res.ok) return ($("historyBody").innerHTML = M.errorBox(res.message));
      state.history = res.data;
    }
    $("historyBody").innerHTML = state.history.length
      ? `<ul class="ev-history">${state.history.map((h) => `<li><span class="ev-history-dot bg-${UI.colorFor(h.who)}"></span><div><strong>${M.esc(h.sentence)}</strong><small>${new Date(h.at).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" })}</small></div></li>`).join("")}</ul>`
      : '<p class="mb-0 fw-semibold">Nothing recorded yet.</p>';
  }

  function showTab() {
    document.querySelectorAll("#mbTabs [data-tab]").forEach((b) => b.classList.toggle("active", b.dataset.tab === state.tab));
    const q = new URLSearchParams(window.location.search);
    state.tab === "overview" ? q.delete("tab") : q.set("tab", state.tab);
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    state.tab === "history" ? renderHistory() : renderOverview();
  }

  function refresh(p, message) {
    state.p = p;
    state.history = null;
    renderHero();
    showTab();
    if (message) Toast.success(message);
  }

  // -------------------------------------------------------------- actions
  function modal(title, icon, color, body, foot) {
    document.getElementById("mbModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="mbModal" tabindex="-1" aria-labelledby="mbModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon bg-${color} ${M.textOn(color)}"><i class="${icon}"></i></span><div class="flex-fill"><h5 class="modal-title" id="mbModalTitle">${title}</h5><div class="app-modal-subtitle">${M.esc(state.p.name)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">${body}</div>
          <div class="modal-footer">${foot}</div>
        </div></div>
      </div>`,
    );
    const el = $("mbModal");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  async function transferOut() {
    if (!state.churches) {
      const res = await MembersAPI.transfers();
      state.churches = res.ok ? res.data.churches : [];
    }
    const el = modal(
      "Transfer out",
      "ri-logout-box-r-line",
      "warning",
      `<div class="row g-3">
        <div class="col-12"><label class="form-label" for="trChurch">Moving to</label>
          <select class="form-select" id="trChurch"><option value="">A church outside the diocese</option>${state.churches.map((c) => `<option value="${c.id}">${M.esc(c.name)}</option>`).join("")}</select></div>
        <div class="col-12" id="trNameWrap"><label class="form-label" for="trName">Name of that church</label><input class="form-control" id="trName" maxlength="160" placeholder="e.g. AIC Wote"></div>
        <div class="col-md-6"><label class="form-label" for="trOn">On</label><input type="date" class="form-control" id="trOn" value="${new Date().toISOString().slice(0, 10)}"></div>
        <div class="col-12"><label class="form-label" for="trReason">Why <span class="fw-normal">(optional)</span></label><input class="form-control" id="trReason" maxlength="1000" placeholder="e.g. Moved to Nairobi for work"></div>
        <div class="col-12" id="trNotifyWrap" hidden><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="trNotify" checked><label class="form-check-label" for="trNotify">Tell that church's leaders they're coming (with their name and phone)</label></div></div>
      </div>`,
      `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="trSave"><i class="ri-check-line me-1"></i>Transfer out</button>`,
    );
    const sel = $("trChurch");
    UI.enhanceSelect(sel, { dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    const sync = () => {
      $("trNameWrap").hidden = !!sel.value;
      $("trNotifyWrap").hidden = !sel.value;
    };
    (window.jQuery ? window.jQuery(sel) : null)?.on("change", sync);
    sel.addEventListener("change", sync);
    $("trSave").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Saving...");
      const res = await MembersAPI.transferOut(id, { other_church_id: sel.value || null, other_church_name: sel.value ? null : $("trName").value.trim() || null, on: $("trOn").value, reason: $("trReason").value.trim() || null, notify: !!sel.value && $("trNotify").checked });
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      refresh(res.data.person, res.message);
    });
  }

  function anonymise() {
    const el = modal(
      "Remove personal details",
      "ri-user-unfollow-line",
      "danger",
      `<p class="fw-semibold">This clears their name, phone and area. They still count in the totals, and their history stays. <strong>It can't be undone.</strong></p>
       <label class="form-label" for="anConfirm">Type <strong>REMOVE</strong> to confirm</label><input class="form-control" id="anConfirm" autocomplete="off">`,
      `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Keep them</button><button type="button" class="btn btn-danger" id="anGo" disabled><i class="ri-user-unfollow-line me-1"></i>Remove their details</button>`,
    );
    $("anConfirm").addEventListener("input", (e) => ($("anGo").disabled = e.target.value.trim() !== "REMOVE"));
    $("anGo").addEventListener("click", async (e) => {
      UI.setButtonLoading(e.currentTarget, "Removing...");
      const res = await MembersAPI.anonymise(id);
      UI.restoreButton(e.currentTarget);
      if (!res.ok) return Toast.error(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      refresh(res.data, res.message);
    });
  }

  async function act(name) {
    if (name === "transfer") return transferOut();
    if (name === "anonymise") return anonymise();
    const call = { archive: MembersAPI.archive, restore: MembersAPI.restore }[name];
    if (!call) return;
    const res = await call(id);
    if (!res.ok) return Toast.error(res.message);
    refresh(res.data, res.message);
  }

  async function init() {
    if (!id) {
      $("mbHero").innerHTML = `<div class="card-body">${M.errorBox("Open a member from the list.", `location.href='${CTX.baseUrl}/'`)}</div>`;
      return;
    }
    const res = await MembersAPI.get(id);
    if (!res.ok) {
      $("mbHero").innerHTML = `<div class="card-body">${M.errorBox(res.message, res.status === 404 ? `location.href='${CTX.baseUrl}/'` : "location.reload()")}</div>`;
      return;
    }
    state.p = res.data;
    document.title = `${res.data.name} - Makueni West Diocese`;
    $("mbTabs").hidden = false;
    renderHero();
    showTab();
    $("mbTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b || b.dataset.tab === state.tab) return;
      state.tab = b.dataset.tab;
      showTab();
    });
    $("mbHero").addEventListener("click", (e) => {
      const b = e.target.closest("[data-act]");
      if (b) act(b.dataset.act);
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
