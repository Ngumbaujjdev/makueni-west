/**
 * ============================================================================
 * EVENTS - one event (church, region, diocese)
 * ============================================================================
 * The organiser sees: details, who's coming (grouped by region, with fees),
 * money (recorded through the Budgets "Record money" window, tagged with the
 * event), how it went and the history - plus Publish / Mark as done / Cancel.
 * An invited place sees the details and registers its numbers, then says how
 * many came. The places above see it read-only.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const E = EventsUI;
  const CTX = window.EVENTS_CTX;
  const $ = (id) => document.getElementById(id);
  const id = Number(new URLSearchParams(window.location.search).get("id"));
  const GROUPS = Object.keys(E.GROUPS);
  const state = { ev: null, regs: null, money: null, history: null, tab: new URLSearchParams(window.location.search).get("tab") || "details" };
  let donut = null;

  // ================================================================ header
  function renderHero() {
    const ev = state.ev;
    const color = E.typeColor(ev.type);
    const can = ev.can;
    const more = [
      can.complete ? `<li><button class="dropdown-item" data-act="complete"><i class="ri-checkbox-circle-line me-2 text-success"></i>Mark as done</button></li>` : "",
      can.cancel ? `<li><button class="dropdown-item text-danger" data-act="cancel"><i class="ri-close-circle-line me-2"></i>Cancel the event</button></li>` : "",
    ].join("");
    const actions = [
      can.publish ? `<button class="btn btn-success" data-act="publish"><i class="ri-send-plane-line me-1"></i>Publish</button>` : "",
      can.edit ? `<a class="btn btn-outline-primary" href="${CTX.baseUrl}/new?id=${ev.id}"><i class="ri-edit-line me-1"></i>Edit</a>` : "",
      can.record_money
        ? `<div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-hand-coin-line me-1"></i>Record money</button>
            <ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" data-act="money-in"><i class="ri-arrow-down-circle-line me-2 text-success"></i>Money in (offerings, fees)</button></li><li><button class="dropdown-item" data-act="money-out"><i class="ri-arrow-up-circle-line me-2 text-danger"></i>Money out (spending)</button></li></ul></div>`
        : "",
      can.record_attendance ? `<button class="btn btn-outline-primary" data-act="attendance"><i class="ri-user-follow-line me-1"></i>Record attendance</button>` : "",
      ev.relation !== "invited" ? `<button class="btn btn-outline-primary" data-report-key="activity.summary" data-module="events" data-activity-id="${ev.id}"><i class="ri-download-2-line me-1"></i>Export</button>` : "",
      more ? `<div class="dropdown"><button class="btn btn-light btn-icon" data-bs-toggle="dropdown" aria-label="More"><i class="ri-more-2-fill"></i></button><ul class="dropdown-menu dropdown-menu-end">${more}</ul></div>` : "",
    ].join("");
    const by = ev.relation === "own" ? "" : `<span><i class="ri-building-4-line"></i>Organised by <b>${E.esc(ev.owner.name)}</b></span>`;
    $("evHero").innerHTML = `
      <div class="card-body">
        <div class="ev-hero-row">
          ${E.dateBlock(ev.starts_at, color)}
          <div class="flex-fill" style="min-width:0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h2 class="ev-hero-title mb-0">${E.esc(ev.title)}</h2>
              ${E.statusPill(ev.status)}
            </div>
            <div class="ev-card-meta">
              <span class="soft-chip soft-${color}"><i class="${E.typeIcon(ev.type)}"></i>${E.esc(ev.type_label)}</span>
              <span><i class="ri-time-line"></i>${E.when(ev.starts_at, ev.ends_at)} · ${E.relative(ev.starts_at)}</span>
              ${ev.venue ? `<span><i class="ri-map-pin-line"></i>${E.esc(ev.venue)}</span>` : ""}
              ${by}
            </div>
          </div>
          <div class="ev-hero-actions">${actions}</div>
        </div>
        ${heroNote()}
      </div>`;
  }

  function heroNote() {
    const ev = state.ev;
    if (ev.status === "cancelled") return `<div class="alert alert-danger d-flex gap-2 mt-3 mb-0"><i class="ri-close-circle-line fs-16"></i><span>This event was cancelled.</span></div>`;
    if (ev.status === "draft") return `<div class="alert alert-primary d-flex gap-2 mt-3 mb-0"><i class="ri-draft-line fs-16"></i><span>This is a draft - only your place sees it. ${ev.open_to === "own" ? "Publish it to put it on your events." : "Publish it to tell the places it's open to."}</span></div>`;
    return "";
  }

  // ================================================================ figures
  function renderStats() {
    const ev = state.ev;
    const row = $("statCardsRow");
    if (!ev.totals) {
      row.innerHTML = "";
      return;
    }
    const t = ev.totals;
    const cards = [
      { icon: "ri-community-line", label: "Places registered", value: E.num(t.places), color: "primary", sub: ev.registration ? (ev.registration_open ? `Open${ev.register_by ? ` until ${E.shortDate(new Date(ev.register_by))}` : ""}` : "Registration closed") : "No registration" },
      { icon: "ri-group-line", label: "People expected", value: E.num(t.expected), color: "purple", sub: ev.capacity ? `Room for ${E.num(ev.capacity)}` : GROUPS.map((g) => `${E.num(t.by_group[g])} ${E.GROUPS[g].toLowerCase()}`).slice(0, 2).join(" · ") },
      { icon: "ri-user-follow-line", label: "People who came", value: t.came == null ? "-" : E.num(t.came), color: "success", sub: t.came == null ? "Places say once it has happened" : t.expected ? `${Math.round((t.came / t.expected) * 100)}% of those expected` : "" },
      { icon: "ri-money-dollar-circle-line", label: "Fees paid", value: E.money(t.fee_paid), color: "secondary", sub: t.fee_due ? `of ${E.money(t.fee_due)} due` : ev.fee_per_person ? "" : "Free to attend" },
    ];
    row.innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
  }

  // ================================================================ tabs
  function tabsFor() {
    const ev = state.ev;
    if (ev.relation === "invited") return [];
    const tabs = [{ key: "details", label: "Details", icon: "ri-file-list-3-line", color: "primary", figure: E.STATUS[ev.status]?.label || "" }];
    if (ev.open_to !== "own" || ev.totals?.places) tabs.push({ key: "coming", label: "Who's coming", icon: "ri-group-line", color: "purple", figure: `${E.num(ev.totals?.expected || 0)} expected` });
    if (ev.relation === "own") {
      tabs.push({ key: "money", label: "Money", icon: "ri-hand-coin-line", color: "secondary", figure: "In and out" });
      tabs.push({ key: "went", label: "How it went", icon: "ri-chat-smile-2-line", color: "success", figure: ev.status === "completed" ? "Done" : "After the event" });
      tabs.push({ key: "history", label: "History", icon: "ri-history-line", color: "danger", figure: "Who did what" });
    }
    return tabs;
  }

  function renderTabs() {
    const tabs = tabsFor();
    const bar = $("evTabs");
    if (!tabs.some((t) => t.key === state.tab)) state.tab = "details";
    bar.hidden = tabs.length < 2;
    bar.innerHTML = tabs
      .map(
        (t) => `
      <button class="nav-link section-tab${t.key === state.tab ? " active" : ""}" data-tab="${t.key}" type="button" role="tab" aria-selected="${t.key === state.tab}">
        <span class="section-tab-icon bg-${t.color}"><i class="${t.icon}"></i></span>
        <span class="section-tab-text"><strong>${t.label}</strong><small data-tab-figure="${t.key}">${t.figure}</small></span>
      </button>`,
      )
      .join("");
    $("evPanes").innerHTML = tabs.length ? tabs.map((t) => `<div class="ev-pane" data-pane="${t.key}" ${t.key === state.tab ? "" : "hidden"}></div>`).join("") : `<div class="ev-pane" data-pane="details"></div>`;
    bar.querySelectorAll("[data-tab]").forEach((b) => b.addEventListener("click", () => showTab(b.dataset.tab)));
    return showTab(state.tab, true);
  }

  function showTab(key, first = false) {
    state.tab = key;
    document.querySelectorAll("#evTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === key);
      b.setAttribute("aria-selected", b.dataset.tab === key);
    });
    document.querySelectorAll("#evPanes [data-pane]").forEach((p) => (p.hidden = p.dataset.pane !== key));
    const q = new URLSearchParams(window.location.search);
    key === "details" ? q.delete("tab") : q.set("tab", key);
    history.replaceState(null, "", `${window.location.pathname}?${q.toString()}`);
    return ({ details: renderDetails, coming: loadComing, money: loadMoney, went: renderWent, history: loadHistory })[key]?.(first);
  }

  const pane = (key) => document.querySelector(`#evPanes [data-pane="${key}"]`);
  const loading = () => `<div class="card custom-card"><div class="card-body"><span class="skel skel-line" style="width:40%"></span><span class="skel mt-3" style="display:block;height:10rem"></span></div></div>`;

  // ================================================================ details
  function renderDetails() {
    const ev = state.ev;
    const agenda = (ev.agenda || "").split(/\n+/).map((l) => l.trim()).filter(Boolean);
    pane("details").innerHTML = `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">About the event</div></div>
        <div class="card-body">
          ${ev.description ? `<p class="ev-text">${E.esc(ev.description)}</p>` : `<p class="mb-0 fw-semibold">No description yet.</p>`}
          ${agenda.length ? `<h6 class="ev-sub mt-4">Programme</h6><ol class="ev-agenda">${agenda.map((l) => `<li>${E.esc(l)}</li>`).join("")}</ol>` : ""}
          ${ev.speakers || ev.coordinator ? `<div class="row g-3 mt-2">
            ${ev.speakers ? `<div class="col-sm-6"><div class="ev-person"><span class="avatar avatar-md avatar-rounded bg-purple text-white"><i class="ri-mic-line"></i></span><div><small>Speakers</small><strong>${E.esc(ev.speakers)}</strong></div></div></div>` : ""}
            ${ev.coordinator ? `<div class="col-sm-6"><div class="ev-person"><span class="avatar avatar-md avatar-rounded bg-success text-white"><i class="ri-user-star-line"></i></span><div><small>Coordinator</small><strong>${E.esc(ev.coordinator)}</strong></div></div></div>` : ""}
          </div>` : ""}
        </div>
      </div>`;
  }

  function renderFacts() {
    const ev = state.ev;
    const invitees = ev.invitees || [];
    return `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">At a glance</div></div>
        <div class="card-body">
          <ul class="ev-facts">
            <li><i class="ri-time-line"></i><span>${E.when(ev.starts_at, ev.ends_at)}</span></li>
            <li><i class="ri-map-pin-line"></i><span>${E.esc(ev.venue) || "Venue not set yet"}</span></li>
            <li><i class="ri-user-heart-line"></i><span>For ${E.esc((ev.audience || "everyone").replace(/^\w/, (c) => c.toUpperCase()))}${ev.capacity ? ` · room for ${E.num(ev.capacity)}` : ""}</span></li>
            <li><i class="ri-community-line"></i><span>${E.esc(ev.open_to_label)}</span></li>
            <li><i class="ri-user-add-line"></i><span>${ev.registration ? `Register${ev.register_by ? ` by ${E.longDate(new Date(ev.register_by))}` : ""} · ${ev.fee_per_person ? `${E.money(ev.fee_per_person)} a person` : "free"}` : "No registration needed"}</span></li>
            ${ev.relation === "own" && (ev.planned_income || ev.planned_spend) ? `<li><i class="ri-hand-coin-line"></i><span>Plan: raise ${E.money(ev.planned_income)}, spend ${E.money(ev.planned_spend)}</span></li>` : ""}
          </ul>
          ${invitees.length ? `<h6 class="ev-sub mt-3">Open to</h6><div class="d-flex flex-wrap gap-1">${invitees.map((p) => `<span class="soft-chip soft-${p.type === "region" ? "purple" : "success"}"><i class="${p.type === "region" ? "ri-map-2-line" : "ri-home-heart-line"}"></i>${E.esc(p.name)}</span>`).join("")}</div>` : ""}
        </div>
      </div>`;
  }

  // ================================================================ register (an invited place)
  function stepper(prefix, g, value) {
    return `<div class="col-6">${UI.numberStepperHtml(`${prefix}_${g}`, { label: E.GROUPS[g], min: 0, max: 100000, value: value ?? 0 })}</div>`;
  }

  function renderRegister() {
    const ev = state.ev;
    if (ev.relation !== "invited") return "";
    const mine = ev.mine && ev.mine.status === "registered" ? ev.mine : null;
    if (ev.status === "cancelled") return "";
    if (!ev.registration) {
      return `<div class="card custom-card"><div class="card-body ev-callout"><span class="avatar avatar-md avatar-rounded bg-primary text-white"><i class="ri-calendar-check-line"></i></span><div><strong>No registration needed</strong><span>Just come - ${E.esc(ev.owner.name)} hasn't asked for numbers.</span></div></div></div>`;
    }
    if (ev.can.say_came) {
      return `
        <div class="card custom-card" id="cameCard">
          <div class="card-header"><div class="card-title">How many came from us?</div></div>
          <div class="card-body">
            <div class="row g-3">${GROUPS.map((g) => stepper("came", g, mine[`came_${g}`] ?? mine[g])).join("")}</div>
            <label class="form-label mt-3">How did it go?</label>
            <div class="ev-stars" id="cameRating" role="radiogroup" aria-label="Rating">${[1, 2, 3, 4, 5].map((n) => `<button type="button" class="${n <= (mine.rating || 0) ? "is-on" : ""}" data-star="${n}" aria-label="${n} of 5"><i class="ri-star-fill"></i></button>`).join("")}</div>
            <label class="form-label mt-3" for="cameComment">Anything to tell the organisers? (optional)</label>
            <textarea class="form-control" id="cameComment" rows="3" maxlength="2000">${E.esc(mine.comment || "")}</textarea>
            <button class="btn btn-success w-100 mt-3" id="cameSave"><i class="ri-check-line me-1"></i>Save</button>
          </div>
        </div>`;
    }
    if (!ev.can.register) {
      return mine
        ? `<div class="card custom-card"><div class="card-body ev-callout"><span class="avatar avatar-md avatar-rounded bg-success text-white"><i class="ri-checkbox-circle-line"></i></span><div><strong>You registered ${E.num(mine.expected)}</strong><span>${GROUPS.map((g) => `${E.num(mine[g])} ${E.GROUPS[g].toLowerCase()}`).join(" · ")}${mine.fee_due ? ` · fee ${E.money(mine.fee_due)}` : ""}</span></div></div></div>`
        : `<div class="card custom-card"><div class="card-body ev-callout"><span class="avatar avatar-md avatar-rounded bg-danger text-white"><i class="ri-lock-line"></i></span><div><strong>Registration is closed</strong><span>Talk to ${E.esc(ev.owner.name)} if you still want to come.</span></div></div></div>`;
    }
    return `
      <div class="card custom-card ev-register" id="regCard">
        <div class="card-header justify-content-between">
          <div class="card-title">${mine ? "Our numbers" : "Register"}</div>
          ${mine ? UI.pill("Registered", "success", "ri-checkbox-circle-line") : ev.register_by ? `<span class="soft-chip soft-danger"><i class="ri-alarm-line"></i>By ${E.shortDate(new Date(ev.register_by))}</span>` : ""}
        </div>
        <div class="card-body">
          <p class="mb-3">How many are coming from us? Numbers only - no names needed.</p>
          <div class="row g-3">${GROUPS.map((g) => stepper("reg", g, mine?.[g])).join("")}</div>
          <label class="form-label mt-3" for="regNames">Names (optional)</label>
          <textarea class="form-control" id="regNames" rows="2" maxlength="2000" placeholder="e.g. who is leading the group">${E.esc(mine?.names || "")}</textarea>
          <div class="ev-total mt-3"><span>Coming</span><strong id="regTotal">0</strong>${ev.fee_per_person ? `<span>Fee</span><strong id="regFee">KES 0</strong>` : ""}</div>
          <button class="btn btn-primary w-100 mt-3" id="regSave"><i class="ri-user-add-line me-1"></i>${mine ? "Update our numbers" : "Register"}</button>
          ${mine ? `<button class="btn btn-link text-danger w-100 mt-1" id="regWithdraw">We're no longer coming</button>` : ""}
        </div>
      </div>`;
  }

  function wireRegister() {
    const ev = state.ev;
    const card = $("regCard");
    if (card) {
      const total = () => GROUPS.reduce((a, g) => a + (parseInt($(`reg_${g}`).value, 10) || 0), 0);
      const sync = () => {
        $("regTotal").textContent = E.num(total());
        if ($("regFee")) $("regFee").textContent = E.money(total() * ev.fee_per_person);
      };
      UI.initSteppers(card, sync);
      card.addEventListener("input", sync);
      sync();
      $("regSave").addEventListener("click", async () => {
        if (!total()) return Toast.warning("Say how many are coming.");
        const body = { ...Object.fromEntries(GROUPS.map((g) => [g, parseInt($(`reg_${g}`).value, 10) || 0])), names: $("regNames").value.trim() || null };
        const btn = $("regSave");
        UI.setButtonLoading(btn, "Saving...");
        const res = ev.mine && ev.mine.status === "registered" ? await EventsAPI.updateRegistration(ev.mine.id, body) : await EventsAPI.register(ev.id, body);
        UI.restoreButton(btn);
        if (!res.ok) return Toast.error(res.message);
        Toast.success(res.message);
        reload();
      });
      $("regWithdraw")?.addEventListener("click", async () => {
        const yes = await E.ask({ title: "No longer coming?", text: `${E.esc(ev.owner.name)} will see that your place withdrew. You can register again while registration is open.`, icon: "ri-user-unfollow-line", color: "danger", action: "Withdraw", actionColor: "danger" });
        if (!yes) return;
        const res = await EventsAPI.withdraw(ev.mine.id);
        if (!res.ok) return Toast.error(res.message);
        Toast.success(res.message);
        reload();
      });
    }
    const came = $("cameCard");
    if (came) {
      UI.initSteppers(came);
      let rating = ev.mine.rating || null;
      $("cameRating").addEventListener("click", (e) => {
        const b = e.target.closest("[data-star]");
        if (!b) return;
        rating = Number(b.dataset.star);
        $("cameRating").querySelectorAll("[data-star]").forEach((s) => s.classList.toggle("is-on", Number(s.dataset.star) <= rating));
      });
      $("cameSave").addEventListener("click", async () => {
        const body = { ...Object.fromEntries(GROUPS.map((g) => [`came_${g}`, parseInt($(`came_${g}`).value, 10) || 0])), rating, comment: $("cameComment").value.trim() || null };
        const btn = $("cameSave");
        UI.setButtonLoading(btn, "Saving...");
        const res = await EventsAPI.updateRegistration(ev.mine.id, body);
        UI.restoreButton(btn);
        if (!res.ok) return Toast.error(res.message);
        Toast.success("Thank you - the organisers can see it.");
        reload();
      });
    }
  }

  // ================================================================ who's coming
  async function loadComing() {
    const p = pane("coming");
    if (!state.regs) {
      p.innerHTML = loading();
      const res = await EventsAPI.registrations(id);
      if (!res.ok) {
        p.innerHTML = `<div class="card custom-card"><div class="card-body">${E.esc(res.message)}</div></div>`;
        return;
      }
      state.regs = res.data;
    }
    const { totals, items } = state.regs;
    const active = items.filter((r) => r.status === "registered");
    const withdrawn = items.filter((r) => r.status !== "registered");
    if (!items.length) {
      p.innerHTML = `<div class="card custom-card"><div class="card-body"><div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-purple text-white mb-2"><i class="ri-group-line fs-20"></i></span><h6 class="mb-1">Nobody has registered yet</h6><p class="mb-0">${state.ev.status === "draft" ? "Publish the event so the places it's open to can register." : "Places show here as they register, grouped by region."}</p></div></div></div>`;
      return;
    }
    const byRegion = new Map();
    active.forEach((r) => {
      const k = r.region || "Diocese";
      if (!byRegion.has(k)) byRegion.set(k, []);
      byRegion.get(k).push(r);
    });
    const fees = !!state.ev.fee_per_person || active.some((r) => r.fee_due || r.fee_paid);
    const cols = 6 + (fees ? 1 : 0);
    const row = (r) => `
      <tr data-row-id="${r.id}">
        <td><div class="fw-semibold">${E.esc(r.place.name)}</div>${r.subregion ? `<small>${E.esc(r.subregion)}</small>` : ""}${r.names ? `<div class="fs-12 text-break">${E.esc(r.names)}</div>` : ""}</td>
        ${GROUPS.map((g) => `<td class="text-end">${E.num(r[g])}</td>`).join("")}
        <td class="text-end fw-bold">${E.num(r.expected)}${r.came != null ? `<div class="fs-12 text-success">${E.num(r.came)} came</div>` : ""}</td>
        ${fees ? `<td class="text-end">${feeCell(r)}</td>` : ""}
      </tr>`;
    p.innerHTML = `
      <div class="card custom-card">
        <div class="card-header justify-content-between"><div class="card-title">Who is coming</div><span class="soft-chip soft-purple"><i class="ri-group-line"></i>${E.num(totals.expected)} from ${E.num(totals.places)} ${totals.places === 1 ? "place" : "places"}</span></div>
        <div class="card-body">
          <div class="row g-4 align-items-center">
            <div class="col-md-5"><div id="comingDonut"></div></div>
            <div class="col-md-7">
              <h6 class="ev-sub mb-3">By region</h6>
              ${[...byRegion.entries()]
                .sort((a, b) => b[1].reduce((s, r) => s + r.expected, 0) - a[1].reduce((s, r) => s + r.expected, 0))
                .map(([name, list]) => {
                  const n = list.reduce((s, r) => s + r.expected, 0);
                  const pct = totals.expected ? Math.round((n / totals.expected) * 100) : 0;
                  return `<div class="ev-bar-row"><div class="d-flex justify-content-between gap-2"><strong>${E.esc(name)}</strong><span>${E.num(n)} · ${list.length} ${list.length === 1 ? "place" : "places"} · ${pct}%</span></div><div class="progress progress-sm mt-1"><div class="progress-bar bg-${UI.colorFor(name)}" style="width:${pct}%"></div></div></div>`;
                })
                .join("")}
            </div>
          </div>
        </div>
      </div>
      <div class="row g-4">
        <div class="col-12">
          <div class="card custom-card">
            <div class="card-header justify-content-between"><div class="card-title">Places that registered</div><span class="soft-chip soft-primary">${E.num(active.length)} ${active.length === 1 ? "place" : "places"}</span></div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table text-nowrap mb-0 ev-reg-table">
                  <thead><tr><th>Place</th>${GROUPS.map((g) => `<th class="text-end">${E.GROUPS[g]}</th>`).join("")}<th class="text-end">Total</th>${fees ? `<th class="text-end">Fee</th>` : ""}</tr></thead>
                  <tbody>
                    ${[...byRegion.entries()].map(([name, list]) => `<tr class="ev-group-row"><td colspan="${cols}"><span class="soft-chip soft-${UI.colorFor(name)}"><i class="ri-map-2-line"></i>${E.esc(name)}</span></td></tr>${list.map(row).join("")}`).join("")}
                    ${withdrawn.length ? `<tr class="ev-group-row"><td colspan="${cols}"><span class="soft-chip soft-danger"><i class="ri-user-unfollow-line"></i>Withdrew</span></td></tr>${withdrawn.map((r) => `<tr><td colspan="${cols}">${E.esc(r.place.name)}${r.region ? ` · ${E.esc(r.region)}` : ""}</td></tr>`).join("")}` : ""}
                  </tbody>
                  <tfoot><tr><th>Total</th>${GROUPS.map((g) => `<th class="text-end">${E.num(totals.by_group[g])}</th>`).join("")}<th class="text-end">${E.num(totals.expected)}</th>${fees ? `<th class="text-end">${E.money(totals.fee_paid)} <small>of ${E.money(totals.fee_due)}</small></th>` : ""}</tr></tfoot>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>`;
    donut?.destroy?.();
    donut = UI.renderRingDonut("comingDonut", { labels: GROUPS.map((g) => E.GROUPS[g]), series: GROUPS.map((g) => totals.by_group[g]), colors: GROUPS.map((g) => E.GROUP_COLORS[g]), centerLabel: "Expected" });
    p.querySelectorAll("[data-fee]").forEach((b) => b.addEventListener("click", () => recordFee(active.find((r) => r.id === Number(b.dataset.fee)))));
  }

  function feeCell(r) {
    const paid = r.fee_paid >= r.fee_due && r.fee_due > 0;
    const text = `${E.money(r.fee_paid)}<div class="fs-12">of ${E.money(r.fee_due)}</div>`;
    const badge = r.fee_due ? (paid ? UI.pill("Paid", "success") : r.fee_paid ? UI.pill("Part", "secondary") : UI.pill("Not paid", "danger")) : "";
    return `<div class="d-flex align-items-center justify-content-end gap-2"><div>${text}</div>${badge}${r.can_record_fee ? `<button class="btn btn-sm btn-primary-light btn-icon" data-fee="${r.id}" title="Record what ${E.esc(r.place.name)} paid" aria-label="Record fee"><i class="ri-edit-line"></i></button>` : ""}</div>`;
  }

  async function recordFee(r) {
    const value = await E.ask({ title: "Fee paid", text: `What has <b>${E.esc(r.place.name)}</b> paid so far? They owe ${E.money(r.fee_due)}.`, icon: "ri-money-dollar-circle-line", color: "secondary", action: "Save", input: { label: "Paid (KES)", value: r.fee_paid || "" } });
    if (value === null || value === "") return;
    const res = await EventsAPI.updateRegistration(r.id, { fee_paid: Number(value) });
    if (!res.ok) return Toast.error(res.message);
    Toast.success(res.message);
    state.regs = null;
    await reload(false);
    UI.flashRow(r.id);
  }

  // ================================================================ money
  async function loadMoney() {
    const p = pane("money");
    if (!state.money) {
      p.innerHTML = loading();
      const res = await EventsAPI.money(id);
      if (!res.ok) {
        p.innerHTML = `<div class="card custom-card"><div class="card-body">${E.esc(res.message)}</div></div>`;
        return;
      }
      state.money = res.data;
    }
    const m = state.money;
    const ev = state.ev;
    const bar = (label, actual, planned, color) =>
      planned
        ? `<div class="ev-bar-row"><div class="d-flex justify-content-between"><strong>${label}</strong><span>${E.money(actual)} of ${E.money(planned)}</span></div><div class="progress progress-sm mt-1"><div class="progress-bar bg-${color}" style="width:${Math.min(100, Math.round((actual / planned) * 100))}%"></div></div></div>`
        : "";
    const noBudget = ev.can.record_money && !m.budget_in_use;
    p.innerHTML = `
      ${noBudget ? `<div class="alert alert-primary d-flex gap-2"><i class="ri-information-line fs-16"></i><span>No budget is in use today, so money can't be recorded yet. <a href="${CTX.budget.baseUrl}/">Open Budgets</a> to start one.</span></div>` : ""}
      <div class="row g-3 mb-1">
        ${[
          ["Money in", m.in, "success", "ri-arrow-down-circle-line"],
          ["Money out", m.out, "danger", "ri-arrow-up-circle-line"],
          ["Left over", m.net, m.net < 0 ? "danger" : "primary", "ri-scales-3-line"],
        ]
          .map(([label, v, c, icon]) => `<div class="col-md-4">${UI.renderSparkCard({ icon, label, value: E.money(v), color: c })}</div>`)
          .join("")}
      </div>
      ${m.planned_income || m.planned_spend ? `<div class="card custom-card"><div class="card-header"><div class="card-title">Against the plan</div></div><div class="card-body">${bar("Raised", m.in, m.planned_income, "success")}${bar("Spent", m.out, m.planned_spend, "danger")}</div></div>` : ""}
      <div class="card custom-card">
        <div class="card-header justify-content-between">
          <div class="card-title">Recorded for this event</div>
          ${ev.can.record_money && m.budget_in_use ? `<div class="d-flex gap-2"><button class="btn btn-sm btn-success" data-act="money-in"><i class="ri-add-line me-1"></i>Money in</button><button class="btn btn-sm btn-danger" data-act="money-out"><i class="ri-add-line me-1"></i>Money out</button></div>` : ""}
        </div>
        <div class="card-body p-0">
          ${m.entries.length
            ? `<div class="table-responsive"><table class="table text-nowrap mb-0"><thead><tr><th>Date</th><th>What</th><th class="text-end">Amount</th></tr></thead><tbody>
              ${m.entries.map((e) => `<tr><td>${E.shortDate(new Date(e.date))}</td><td class="text-wrap">${E.esc(e.description || (e.direction === "in" ? "Money in" : "Money out"))}</td><td class="text-end fw-semibold text-${e.direction === "in" ? "success" : "danger"}">${e.direction === "in" ? "+" : "-"}${E.money(e.amount)}</td></tr>`).join("")}
            </tbody></table></div>`
            : `<div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-secondary text-dark mb-2"><i class="ri-hand-coin-line fs-20"></i></span><h6 class="mb-1">Nothing recorded yet</h6><p class="mb-0">Offerings, fees collected and spending for this event show here. They go into your budget${m.budget_in_use ? ` (${E.esc(m.budget_in_use.label)})` : ""}, tagged with the event.</p></div>`}
        </div>
      </div>`;
  }

  async function recordMoney(direction) {
    if (!state.money) {
      const res = await EventsAPI.money(id);
      if (!res.ok) return Toast.error(res.message);
      state.money = res.data;
    }
    if (!state.money.budget_in_use) return Toast.warning("No budget is in use today - start one in Budgets first.");
    BudgetsEntryModal.open({
      budgetId: state.money.budget_in_use.id,
      direction,
      activityId: id,
      onSaved: () => {
        state.money = null;
        showTab("money");
      },
    });
  }

  // ================================================================ how it went
  async function renderWent() {
    const ev = state.ev;
    const p = pane("went");
    if (!state.regs && (ev.totals?.places || 0) > 0) {
      p.innerHTML = loading();
      const res = await EventsAPI.registrations(id);
      if (res.ok) state.regs = res.data;
    }
    const items = (state.regs?.items || []).filter((r) => r.status === "registered");
    const rated = items.filter((r) => r.rating);
    const avg = rated.length ? rated.reduce((s, r) => s + r.rating, 0) / rated.length : null;
    const comments = items.filter((r) => r.comment);
    const t = ev.totals || {};
    p.innerHTML = `
      <div class="card custom-card">
        <div class="card-header justify-content-between"><div class="card-title">Report back</div>${ev.can.complete ? `<button class="btn btn-sm btn-success" data-act="complete"><i class="ri-checkbox-circle-line me-1"></i>Mark as done</button>` : ""}</div>
        <div class="card-body">
          ${ev.report_back ? `<p class="ev-text mb-0">${E.esc(ev.report_back)}</p>` : `<p class="mb-0 fw-semibold">${ev.status === "completed" ? "No report was written." : "When the event is over, mark it as done and write a few lines on how it went."}</p>`}
        </div>
      </div>
      <div class="row g-4">
        <div class="col-md-6">
          <div class="card custom-card h-100">
            <div class="card-header"><div class="card-title">Came against expected</div></div>
            <div class="card-body">
              ${t.came != null ? `<div class="ev-big">${E.num(t.came)} <small>of ${E.num(t.expected)}</small></div><div class="progress progress-sm mt-2"><div class="progress-bar bg-success" style="width:${t.expected ? Math.min(100, Math.round((t.came / t.expected) * 100)) : 0}%"></div></div><p class="mt-2 mb-0">${items.filter((r) => r.came != null).length} of ${items.length} places have said how many came.</p>` : `<p class="mb-0 fw-semibold">Places say how many came once it has started.</p>`}
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card custom-card h-100">
            <div class="card-header"><div class="card-title">What places thought</div></div>
            <div class="card-body">
              ${avg != null ? `<div class="ev-big">${avg.toFixed(1)} <small>out of 5 · ${rated.length} ${rated.length === 1 ? "place" : "places"}</small></div><div class="ev-stars is-static mt-1">${[1, 2, 3, 4, 5].map((n) => `<i class="ri-star-fill${n <= Math.round(avg) ? " is-on" : ""}"></i>`).join("")}</div>` : `<p class="mb-0 fw-semibold">No ratings yet.</p>`}
            </div>
          </div>
        </div>
      </div>
      ${comments.length ? `<div class="card custom-card"><div class="card-header"><div class="card-title">Comments</div></div><div class="card-body"><ul class="ev-comments">${comments.map((r) => `<li><span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(r.place.name)} text-white">${E.esc(r.place.name.charAt(0))}</span><div><strong>${E.esc(r.place.name)}</strong>${r.rating ? ` <span class="soft-chip soft-secondary"><i class="ri-star-fill"></i>${r.rating}</span>` : ""}<p class="mb-0">${E.esc(r.comment)}</p></div></li>`).join("")}</ul></div></div>` : ""}`;
  }

  // ================================================================ history
  async function loadHistory() {
    const p = pane("history");
    if (!state.history) {
      p.innerHTML = loading();
      const res = await EventsAPI.history(id);
      if (!res.ok) {
        p.innerHTML = `<div class="card custom-card"><div class="card-body">${E.esc(res.message)}</div></div>`;
        return;
      }
      state.history = res.data;
    }
    p.innerHTML = `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">History</div></div>
        <div class="card-body">
          ${state.history.length
            ? `<ul class="ev-history">${state.history.map((h) => `<li><span class="ev-history-dot bg-${UI.colorFor(h.who)}"></span><div><strong>${E.esc(h.sentence)}</strong><small>${new Date(h.at).toLocaleString(undefined, { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" })}</small></div></li>`).join("")}</ul>`
            : `<p class="mb-0 fw-semibold">Nothing recorded yet.</p>`}
        </div>
      </div>`;
  }

  // ================================================================ actions
  async function act(name, btn) {
    const ev = state.ev;
    if (name === "money-in" || name === "money-out") return recordMoney(name === "money-in" ? "in" : "out");
    if (name === "attendance") return recordAttendance();
    let res;
    if (name === "publish") {
      const ok = await E.ask({ title: "Publish this event?", text: ev.open_to === "own" ? "It goes on your events. Nobody else is told." : `The leaders of the places it's open to (${E.esc(ev.open_to_label.toLowerCase())}) get a notification and can register.`, icon: "ri-send-plane-line", color: "success", action: "Publish", actionColor: "success" });
      if (!ok) return;
      UI.setButtonLoading(btn, "Publishing...");
      res = await EventsAPI.publish(id);
    } else if (name === "complete") {
      const text = await E.ask({ title: "Mark as done", text: "Write a few lines on how it went - the places above you can read it.", icon: "ri-checkbox-circle-line", color: "success", action: "Mark as done", actionColor: "success", textarea: { label: "How it went (optional)", placeholder: "e.g. 640 young people came; 32 gave their lives to Christ..." } });
      if (text === null) return;
      res = await EventsAPI.complete(id, text || null);
    } else if (name === "cancel") {
      const ok = await E.ask({ title: "Cancel this event?", text: ev.status === "published" ? "The places that registered get a notification that it's cancelled. This can't be undone." : "The draft is kept, marked as cancelled. This can't be undone.", icon: "ri-close-circle-line", color: "danger", action: "Cancel the event", actionColor: "danger" });
      if (!ok) return;
      res = await EventsAPI.cancel(id);
    }
    if (btn) UI.restoreButton(btn);
    if (!res) return;
    if (!res.ok) return Toast.error(res.message);
    Toast.success(res.message);
    reload();
  }

  async function recordAttendance() {
    const ev = state.ev;
    const cats = await DemographicsAPIHandler.getGatheringCategories();
    const cat = (cats.data || []).find((c) => c.slug === "special_event");
    if (!cats.success || !cat) return Toast.error("Couldn't open the attendance form. Please try again.");
    const [recs, types] = await Promise.all([
      DemographicsAPIHandler.getAttendance(ev.owner.id, { gathering_category_id: cat.id }),
      DemographicsAPIHandler.getGatheringTypes(ev.owner.id, { gathering_category_id: cat.id }),
    ]);
    const start = new Date(ev.starts_at);
    AttendanceFormShared.openEntryModal({
      gatheringCategoryId: cat.id,
      isWeekly: false,
      territoryId: ev.owner.id,
      records: recs.success ? recs.data || [] : [],
      types: types.success ? types.data || [] : [],
      defaultDate: `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, "0")}-${String(start.getDate()).padStart(2, "0")}`,
      defaultName: ev.title,
      categoryLabel: "Special event",
      icon: "ri-star-line",
      activityId: id,
      onSaved: () => Toast.success("Attendance recorded - it shows in Attendance too."),
    });
  }

  // ================================================================ load
  function renderSide() {
    // An invited place's Register card comes first on a phone.
    const invited = state.ev.relation === "invited";
    $("evSide").classList.toggle("order-first", invited);
    $("evSide").classList.toggle("order-xl-last", invited);
    $("evSide").innerHTML = renderRegister() + renderFacts();
    wireRegister();
  }

  async function reload(resetCaches = true) {
    const res = await EventsAPI.get(id);
    if (!res.ok) {
      $("eventPage").innerHTML = `<div class="card custom-card"><div class="card-body"><div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-danger text-white mb-2"><i class="ri-close-circle-line fs-20"></i></span><h6 class="mb-1">${res.status === 404 ? "This event isn't one you can see" : "Couldn't open the event"}</h6><p class="mb-3">${E.esc(res.message)}</p><a class="btn btn-primary" href="${CTX.baseUrl}/">Back to events</a></div></div></div>`;
      return;
    }
    state.ev = res.data;
    if (resetCaches) {
      state.regs = state.money = state.history = null;
    }
    document.title = `${state.ev.title} - Makueni West Diocese`;
    renderHero();
    renderStats();
    renderSide();
    await renderTabs();
  }

  document.addEventListener("click", (e) => {
    const b = e.target.closest("[data-act]");
    if (b && $("eventPage").contains(b)) act(b.dataset.act, b.tagName === "BUTTON" && !b.classList.contains("dropdown-item") ? b : null);
  });

  document.addEventListener("DOMContentLoaded", () => {
    if (!id) {
      window.location.href = `${CTX.baseUrl}/`;
      return;
    }
    const flash = sessionStorage.getItem("mwd-events-flash");
    if (flash) {
      sessionStorage.removeItem("mwd-events-flash");
      Toast.success(flash);
    }
    reload();
  });
})();
