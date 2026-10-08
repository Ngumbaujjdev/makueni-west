/**
 * ============================================================================
 * EVENTS AND INITIATIVES - one event or initiative (church, region, diocese)
 * ============================================================================
 * The organiser sees: details, who's coming (grouped by region, with fees),
 * money (recorded through the Budgets "Record money" window, tagged with the
 * event), how it went and the history - plus Publish / Mark as done / Cancel.
 * An invited place sees the details and registers its numbers, then says how
 * many came. The places above see it read-only. An initiative adds its
 * sessions (attendance recorded in place), progress across sessions, and
 * how many finished from each place.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const E = EventsUI;
  const CTX = window.EVENTS_CTX;
  const INIT = E.IS_INITIATIVE;
  const N = E.NOUN;
  const $ = (id) => document.getElementById(id);
  const id = Number(new URLSearchParams(window.location.search).get("id"));
  const GROUPS = Object.keys(E.GROUPS);
  const state = { ev: null, regs: null, money: null, history: null, sessions: null, tab: new URLSearchParams(window.location.search).get("tab") || "details" };
  let donut = null;
  let progressChart = null;

  // ================================================================ header
  function renderHero() {
    const ev = state.ev;
    const color = E.typeColor(ev.type);
    const can = ev.can;
    const more = [
      can.complete ? `<li><button class="dropdown-item" data-act="complete"><i class="ri-checkbox-circle-line me-2 text-success"></i>Mark as done</button></li>` : "",
      can.cancel ? `<li><button class="dropdown-item text-danger" data-act="cancel"><i class="ri-close-circle-line me-2"></i>Cancel the ${N.one}</button></li>` : "",
    ].join("");
    const actions = [
      can.publish ? `<button class="btn btn-success" data-act="publish"><i class="ri-send-plane-line me-1"></i>Publish</button>` : "",
      can.edit ? `<a class="btn btn-outline-primary" href="${CTX.baseUrl}/new?id=${ev.id}"><i class="ri-edit-line me-1"></i>Edit</a>` : "",
      can.record_money
        ? `<div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-hand-coin-line me-1"></i>Record money</button>
            <ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" data-act="money-in"><i class="ri-arrow-down-circle-line me-2 text-success"></i>Income (offerings, fees)</button></li><li><button class="dropdown-item" data-act="money-out"><i class="ri-arrow-up-circle-line me-2 text-danger"></i>Expenses (spending)</button></li></ul></div>`
        : "",
      can.record_attendance ? `<button class="btn btn-outline-primary" data-act="attendance"><i class="ri-user-follow-line me-1"></i>Record attendance</button>` : "",
      inviteLink(),
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
              ${INIT ? `<span><i class="ri-repeat-line"></i>${E.esc(E.meets(ev))}</span><span><i class="ri-calendar-line"></i>${E.shortDate(new Date(ev.starts_at))} - ${E.longDate(new Date(ev.ends_at))}</span>` : `<span><i class="ri-time-line"></i>${E.when(ev.starts_at, ev.ends_at)} · ${E.relative(ev.starts_at)}</span>`}
              ${ev.venue ? `<span><i class="ri-map-pin-line"></i>${E.esc(ev.venue)}</span>` : ""}
              ${by}
            </div>
          </div>
          <div class="ev-hero-actions">${actions}</div>
        </div>
        ${heroNote()}
      </div>`;
  }

  /** "Invite by message": the composer, filled in with the places it's open to (never sideways, so not for a church). */
  function inviteLink() {
    const ev = state.ev;
    if (!CTX.can.message || ev.relation !== "own" || CTX.level === "church" || ev.status !== "published" || ev.open_to === "own") return "";
    const when = new Date(ev.starts_at).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
    const params = new URLSearchParams({
      roles: "Senior Pastor" + (CTX.level === "diocese" ? ",Regional Overseer" : ""),
      levels: CTX.level === "diocese" ? "region,church" : "church",
      channel: "sms",
      subject: ev.title,
      body: `Dear {name}, you're invited to ${ev.title} on ${when}${ev.venue ? ` at ${ev.venue}` : ""}.${ev.registration_open ? ` Please register ${INIT ? "to join" : "your numbers"} in ${N.Many}.` : ""} - {sender}`,
    });
    if (ev.open_to === "selected" && ev.invitees?.length) params.set("places", ev.invitees.map((p) => p.id).join(","));
    else params.set("scope", "all");
    return `<a class="btn btn-outline-primary" href="${CTX.siteUrl}/${CTX.level}/messages/new?${params}"><i class="ri-chat-3-line me-1"></i>Invite by message</a>`;
  }

  function heroNote() {
    const ev = state.ev;
    if (ev.status === "cancelled") return `<div class="alert alert-danger d-flex gap-2 mt-3 mb-0"><i class="ri-close-circle-line fs-16"></i><span>This ${N.one} was cancelled.</span></div>`;
    if (ev.status === "draft") return `<div class="alert alert-primary d-flex gap-2 mt-3 mb-0"><i class="ri-draft-line fs-16"></i><span>This is a draft - only your place sees it. ${ev.open_to === "own" ? `Publish it to put it on your ${N.many}.` : "Publish it to tell the places it's open to."}</span></div>`;
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
    const s = ev.sessions || {};
    const cards = INIT ? [
      { icon: "ri-community-line", label: "Places taking part", value: E.num(t.places), color: "primary", sub: ev.registration ? (ev.registration_open ? `Open to join${ev.register_by ? ` until ${E.shortDate(new Date(ev.register_by))}` : ""}` : "Joining closed") : "Our own place" },
      { icon: "ri-calendar-check-line", label: "Sessions held", value: `${E.num(s.held || 0)} of ${E.num(s.total || 0)}`, color: "purple", sub: s.next ? `Next on ${E.longDate(new Date(`${s.next}T12:00:00`))}` : s.total && s.held >= s.total ? "All sessions held" : "No session coming up" },
      { icon: "ri-user-follow-line", label: "Average attendance", value: s.average == null ? "-" : E.num(s.average), color: "success", sub: s.average == null ? "Record attendance at each session" : `${E.num(s.attendance)} attendances in all` },
      ev.fee_per_person
        ? { icon: "ri-money-dollar-circle-line", label: "Fees paid", value: E.money(t.fee_paid), color: "secondary", sub: t.fee_due ? `of ${E.money(t.fee_due)} due` : "" }
        : { icon: "ri-group-line", label: "People taking part", value: E.num(t.expected), color: "secondary", sub: ev.capacity ? `Places for ${E.num(ev.capacity)}` : "From the places that joined" },
    ] : [
      { icon: "ri-community-line", label: "Places registered", value: E.num(t.places), color: "primary", sub: ev.registration ? (ev.registration_open ? `Open${ev.register_by ? ` until ${E.shortDate(new Date(ev.register_by))}` : ""}` : "Registration closed") : "No registration" },
      { icon: "ri-group-line", label: "People expected", value: E.num(t.expected), color: "purple", sub: ev.capacity ? `Room for ${E.num(ev.capacity)}` : GROUPS.map((g) => `${E.num(t.by_group[g])} ${E.GROUPS[g].toLowerCase()}`).slice(0, 2).join(" · ") },
      { icon: "ri-user-follow-line", label: "People who came", value: t.came == null ? "-" : E.num(t.came), color: "success", sub: t.came == null ? "Places say once it has happened" : t.expected ? `${Math.round((t.came / t.expected) * 100)}% of those expected` : "" },
      { icon: "ri-money-dollar-circle-line", label: "Fees paid", value: E.money(t.fee_paid), color: "secondary", sub: t.fee_due ? `of ${E.money(t.fee_due)} due` : ev.fee_per_person ? "" : "Free to attend" },
    ];
    row.innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
  }

  /** An initiative shows its sessions to whoever can see it - even an invited place. */
  function renderInvitedStats() {
    const s = state.ev.sessions;
    if (!INIT || !s || state.ev.totals) return;
    $("statCardsRow").innerHTML = [
      { icon: "ri-calendar-check-line", label: "Sessions", value: `${E.num(s.held)} of ${E.num(s.total)}`, color: "primary", sub: "Held so far" },
      { icon: "ri-calendar-event-line", label: "Next session", value: s.next ? E.shortDate(new Date(`${s.next}T12:00:00`)) : "-", color: "purple", sub: E.meets(state.ev) },
    ].map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
  }

  // ================================================================ tabs
  function tabsFor() {
    const ev = state.ev;
    const sessionsTab = { key: "sessions", label: "Sessions", icon: "ri-calendar-check-line", color: "success", figure: `${E.num(ev.sessions?.held || 0)} of ${E.num(ev.sessions?.total || 0)} held` };
    if (ev.relation === "invited") return INIT ? [{ key: "details", label: "Details", icon: "ri-file-list-3-line", color: "primary", figure: E.STATUS[ev.status]?.label || "" }, sessionsTab] : [];
    const tabs = [{ key: "details", label: "Details", icon: "ri-file-list-3-line", color: "primary", figure: E.STATUS[ev.status]?.label || "" }];
    if (INIT) tabs.push(sessionsTab);
    if (ev.open_to !== "own" || ev.totals?.places) tabs.push({ key: "coming", label: INIT ? "Taking part" : "Who's coming", icon: "ri-group-line", color: "purple", figure: `${E.num(ev.totals?.expected || 0)} ${INIT ? "people" : "expected"}` });
    if (INIT) tabs.push({ key: "progress", label: "Progress", icon: "ri-line-chart-line", color: "pink", figure: ev.sessions?.average != null ? `${E.num(ev.sessions.average)} a session` : "Attendance" });
    if (ev.relation === "own") {
      tabs.push({ key: "money", label: "Money", icon: "ri-hand-coin-line", color: "secondary", figure: "Income and expenses" });
      tabs.push({ key: "went", label: "How it went", icon: "ri-chat-smile-2-line", color: INIT ? "primary" : "success", figure: ev.status === "completed" ? "Done" : `After the ${N.one}` });
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
    return ({ details: renderDetails, sessions: loadSessions, coming: loadComing, progress: loadProgress, money: loadMoney, went: renderWent, history: loadHistory })[key]?.(first);
  }

  const pane = (key) => document.querySelector(`#evPanes [data-pane="${key}"]`);
  const loading = () => `<div class="card custom-card"><div class="card-body"><span class="skel skel-line" style="width:40%"></span><span class="skel mt-3" style="display:block;height:10rem"></span></div></div>`;

  // ================================================================ details
  function renderDetails() {
    const ev = state.ev;
    const agenda = (ev.agenda || "").split(/\n+/).map((l) => l.trim()).filter(Boolean);
    pane("details").innerHTML = `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">About the ${N.one}</div></div>
        <div class="card-body">
          ${ev.description ? `<p class="ev-text">${E.esc(ev.description)}</p>` : `<p class="mb-0 fw-semibold">No description yet.</p>`}
          ${agenda.length ? `<h6 class="ev-sub mt-4">${INIT ? "What it covers" : "Programme"}</h6><ol class="ev-agenda">${agenda.map((l) => `<li>${E.esc(l)}</li>`).join("")}</ol>` : ""}
          ${ev.speakers || ev.coordinator ? `<div class="row g-3 mt-2">
            ${ev.speakers ? `<div class="col-sm-6"><div class="ev-person"><span class="avatar avatar-md avatar-rounded bg-purple text-white"><i class="ri-mic-line"></i></span><div><small>Speakers</small><strong>${E.esc(ev.speakers)}</strong></div></div></div>` : ""}
            ${ev.coordinator ? `<div class="col-sm-6"><div class="ev-person"><span class="avatar avatar-md avatar-rounded bg-success text-white"><i class="ri-user-star-line"></i></span><div><small>${INIT ? "Facilitator" : "Coordinator"}</small><strong>${E.esc(ev.coordinator)}</strong></div></div></div>` : ""}
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
            ${INIT
              ? `<li><i class="ri-repeat-line"></i><span>${E.esc(E.meets(ev))}</span></li>
                 <li><i class="ri-calendar-line"></i><span>${E.longDate(new Date(ev.starts_at))} - ${E.longDate(new Date(ev.ends_at))} · ${E.num(ev.sessions?.total || 0)} sessions</span></li>
                 ${ev.certificate ? `<li><i class="ri-award-line"></i><span>A certificate for those who finish</span></li>` : ""}`
              : `<li><i class="ri-time-line"></i><span>${E.when(ev.starts_at, ev.ends_at)}</span></li>`}
            <li><i class="ri-map-pin-line"></i><span>${E.esc(ev.venue) || "Venue not set yet"}</span></li>
            <li><i class="ri-user-heart-line"></i><span>For ${E.esc((ev.audience || "everyone").replace(/^\w/, (c) => c.toUpperCase()))}${ev.capacity ? ` · ${INIT ? "places" : "room"} for ${E.num(ev.capacity)}` : ""}</span></li>
            <li><i class="ri-community-line"></i><span>${E.esc(ev.open_to_label)}</span></li>
            <li><i class="ri-user-add-line"></i><span>${ev.registration ? `${INIT ? "Join" : "Register"}${ev.register_by ? ` by ${E.longDate(new Date(ev.register_by))}` : INIT ? " until the last day" : ""} · ${ev.fee_per_person ? `${E.money(ev.fee_per_person)} a person` : "free"}` : INIT ? "Nothing to join - it's for our own place" : "No registration needed"}</span></li>
            ${ev.relation === "own" && (ev.planned_income || ev.planned_spend) ? `<li><i class="ri-hand-coin-line"></i><span>Plan: income ${E.money(ev.planned_income)}, expenses ${E.money(ev.planned_spend)}</span></li>` : ""}
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
      return `<div class="card custom-card"><div class="card-body ev-callout"><span class="avatar avatar-md avatar-rounded bg-primary text-white"><i class="ri-calendar-check-line"></i></span><div><strong>${INIT ? "Nothing to join" : "No registration needed"}</strong><span>Just come - ${E.esc(ev.owner.name)} hasn't asked for numbers.</span></div></div></div>`;
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
        ? `<div class="card custom-card"><div class="card-body ev-callout"><span class="avatar avatar-md avatar-rounded bg-success text-white"><i class="ri-checkbox-circle-line"></i></span><div><strong>You ${INIT ? "joined with" : "registered"} ${E.num(mine.expected)}</strong><span>${GROUPS.map((g) => `${E.num(mine[g])} ${E.GROUPS[g].toLowerCase()}`).join(" · ")}${mine.fee_due ? ` · fee ${E.money(mine.fee_due)}` : ""}</span></div></div></div>`
        : `<div class="card custom-card"><div class="card-body ev-callout"><span class="avatar avatar-md avatar-rounded bg-danger text-white"><i class="ri-lock-line"></i></span><div><strong>${INIT ? "Joining is closed" : "Registration is closed"}</strong><span>Talk to ${E.esc(ev.owner.name)} if you still want to take part.</span></div></div></div>`;
    }
    return `
      <div class="card custom-card ev-register" id="regCard">
        <div class="card-header justify-content-between">
          <div class="card-title">${mine ? "Our numbers" : INIT ? "Join" : "Register"}</div>
          ${mine ? UI.pill(INIT ? "Joined" : "Registered", "success", "ri-checkbox-circle-line") : ev.register_by ? `<span class="soft-chip soft-danger"><i class="ri-alarm-line"></i>By ${E.shortDate(new Date(ev.register_by))}</span>` : ""}
        </div>
        <div class="card-body">
          <p class="mb-3">${INIT ? "How many from us are taking part?" : "How many are coming from us?"} Numbers only - no names needed.</p>
          <div class="row g-3">${GROUPS.map((g) => stepper("reg", g, mine?.[g])).join("")}</div>
          <label class="form-label mt-3" for="regNames">Names (optional)</label>
          <textarea class="form-control" id="regNames" rows="2" maxlength="2000" placeholder="e.g. who is leading the group">${E.esc(mine?.names || "")}</textarea>
          <div class="ev-total mt-3"><span>${INIT ? "Taking part" : "Coming"}</span><strong id="regTotal">0</strong>${ev.fee_per_person ? `<span>Fee</span><strong id="regFee">KES 0</strong>` : ""}</div>
          <button class="btn btn-primary w-100 mt-3" id="regSave"><i class="ri-user-add-line me-1"></i>${mine ? "Update our numbers" : INIT ? "Join" : "Register"}</button>
          ${mine ? `<button class="btn btn-link text-danger w-100 mt-1" id="regWithdraw">${INIT ? "We're no longer taking part" : "We're no longer coming"}</button>` : ""}
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
        if (!total()) return Toast.warning(INIT ? "Say how many are taking part." : "Say how many are coming.");
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
        const yes = await E.ask({ title: INIT ? "No longer taking part?" : "No longer coming?", text: `${E.esc(ev.owner.name)} will see that your place withdrew. You can ${INIT ? "join" : "register"} again while it is open.`, icon: "ri-user-unfollow-line", color: "danger", action: "Withdraw", actionColor: "danger" });
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
      p.innerHTML = `<div class="card custom-card"><div class="card-body"><div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-purple text-white mb-2"><i class="ri-group-line fs-20"></i></span><h6 class="mb-1">${INIT ? "No place has joined yet" : "Nobody has registered yet"}</h6><p class="mb-0">${state.ev.status === "draft" ? `Publish the ${N.one} so the places it's open to can ${INIT ? "join" : "register"}.` : `Places show here as they ${INIT ? "join" : "register"}, grouped by region.`}</p></div></div></div>`;
      return;
    }
    const byRegion = new Map();
    active.forEach((r) => {
      const k = r.region || "Diocese";
      if (!byRegion.has(k)) byRegion.set(k, []);
      byRegion.get(k).push(r);
    });
    const fees = !!state.ev.fee_per_person || active.some((r) => r.fee_due || r.fee_paid);
    const cols = 6 + (fees ? 1 : 0) + (INIT ? 1 : 0);
    const row = (r) => `
      <tr data-row-id="${r.id}">
        <td><div class="fw-semibold">${E.esc(r.place.name)}</div>${r.subregion ? `<small>${E.esc(r.subregion)}</small>` : ""}${r.names ? `<div class="fs-12 text-break">${E.esc(r.names)}</div>` : ""}</td>
        ${GROUPS.map((g) => `<td class="text-end">${E.num(r[g])}</td>`).join("")}
        <td class="text-end fw-bold">${E.num(r.expected)}${!INIT && r.came != null ? `<div class="fs-12 text-success">${E.num(r.came)} came</div>` : ""}</td>
        ${INIT ? `<td class="text-end"><div class="d-flex align-items-center justify-content-end gap-2"><span class="fw-semibold">${r.completed == null ? "-" : E.num(r.completed)}</span>${r.can_record_fee ? `<button class="btn btn-sm btn-success-light btn-icon" data-finished="${r.id}" title="Record how many from ${E.esc(r.place.name)} finished" aria-label="Record how many finished"><i class="ri-award-line"></i></button>` : ""}</div></td>` : ""}
        ${fees ? `<td class="text-end">${feeCell(r)}</td>` : ""}
      </tr>`;
    p.innerHTML = `
      <div class="card custom-card">
        <div class="card-header justify-content-between"><div class="card-title">${INIT ? "Who is taking part" : "Who is coming"}</div><span class="soft-chip soft-purple"><i class="ri-group-line"></i>${E.num(totals.expected)} from ${E.num(totals.places)} ${totals.places === 1 ? "place" : "places"}</span></div>
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
            <div class="card-header justify-content-between"><div class="card-title">${INIT ? "Places that joined" : "Places that registered"}</div><span class="soft-chip soft-primary">${E.num(active.length)} ${active.length === 1 ? "place" : "places"}</span></div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table text-nowrap mb-0 ev-reg-table">
                  <thead><tr><th>Place</th>${GROUPS.map((g) => `<th class="text-end">${E.GROUPS[g]}</th>`).join("")}<th class="text-end">Total</th>${INIT ? `<th class="text-end">Finished</th>` : ""}${fees ? `<th class="text-end">Fee</th>` : ""}</tr></thead>
                  <tbody>
                    ${[...byRegion.entries()].map(([name, list]) => `<tr class="ev-group-row"><td colspan="${cols}"><span class="soft-chip soft-${UI.colorFor(name)}"><i class="ri-map-2-line"></i>${E.esc(name)}</span></td></tr>${list.map(row).join("")}`).join("")}
                    ${withdrawn.length ? `<tr class="ev-group-row"><td colspan="${cols}"><span class="soft-chip soft-danger"><i class="ri-user-unfollow-line"></i>Withdrew</span></td></tr>${withdrawn.map((r) => `<tr><td colspan="${cols}">${E.esc(r.place.name)}${r.region ? ` · ${E.esc(r.region)}` : ""}</td></tr>`).join("")}` : ""}
                  </tbody>
                  <tfoot><tr><th>Total</th>${GROUPS.map((g) => `<th class="text-end">${E.num(totals.by_group[g])}</th>`).join("")}<th class="text-end">${E.num(totals.expected)}</th>${INIT ? `<th class="text-end">${E.num(active.reduce((a, r) => a + (r.completed || 0), 0))}</th>` : ""}${fees ? `<th class="text-end">${E.money(totals.fee_paid)} <small>of ${E.money(totals.fee_due)}</small></th>` : ""}</tr></tfoot>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>`;
    donut?.destroy?.();
    donut = UI.renderRingDonut("comingDonut", { labels: GROUPS.map((g) => E.GROUPS[g]), series: GROUPS.map((g) => totals.by_group[g]), colors: GROUPS.map((g) => E.GROUP_COLORS[g]), centerLabel: INIT ? "Taking part" : "Expected" });
    p.querySelectorAll("[data-fee]").forEach((b) => b.addEventListener("click", () => recordFee(active.find((r) => r.id === Number(b.dataset.fee)))));
    p.querySelectorAll("[data-finished]").forEach((b) => b.addEventListener("click", () => recordFinished(active.find((r) => r.id === Number(b.dataset.finished)))));
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

  async function recordFinished(r) {
    const value = await E.ask({ title: "How many finished?", text: `How many from <b>${E.esc(r.place.name)}</b> finished the ${N.one}? ${E.num(r.expected)} took part.`, icon: "ri-award-line", color: "success", action: "Save", input: { label: "Finished", value: r.completed ?? "" } });
    if (value === null || value === "") return;
    const res = await EventsAPI.updateRegistration(r.id, { completed: Number(value) });
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
          ["Income", m.in, "success", "ri-arrow-down-circle-line"],
          ["Expenses", m.out, "danger", "ri-arrow-up-circle-line"],
          ["Left over", m.net, m.net < 0 ? "danger" : "purple", "ri-scales-3-line"],
        ]
          .map(([label, v, c, icon]) => `<div class="col-md-4">${UI.renderSparkCard({ icon, label, value: E.money(v), color: c })}</div>`)
          .join("")}
      </div>
      ${m.planned_income || m.planned_spend ? `<div class="card custom-card"><div class="card-header"><div class="card-title">Against the plan</div></div><div class="card-body">${bar("Income", m.in, m.planned_income, "success")}${bar("Expenses", m.out, m.planned_spend, "danger")}</div></div>` : ""}
      <div class="card custom-card">
        <div class="card-header justify-content-between">
          <div class="card-title">Recorded for this ${N.one}</div>
          ${ev.can.record_money && m.budget_in_use ? `<div class="d-flex gap-2"><button class="btn btn-sm btn-success" data-act="money-in"><i class="ri-add-line me-1"></i>Income</button><button class="btn btn-sm btn-danger" data-act="money-out"><i class="ri-add-line me-1"></i>Expense</button></div>` : ""}
        </div>
        <div class="card-body p-0">
          ${m.entries.length
            ? `<div class="table-responsive"><table class="table text-nowrap mb-0"><thead><tr><th>Date</th><th>What</th><th class="text-end">Amount</th></tr></thead><tbody>
              ${m.entries.map((e) => `<tr><td>${E.shortDate(new Date(e.date))}</td><td class="text-wrap">${E.esc(e.description || (e.direction === "in" ? "Income" : "Expense"))}</td><td class="text-end fw-semibold text-${e.direction === "in" ? "success" : "danger"}">${e.direction === "in" ? "+" : "-"}${E.money(e.amount)}</td></tr>`).join("")}
            </tbody></table></div>`
            : `<div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-secondary text-dark mb-2"><i class="ri-hand-coin-line fs-20"></i></span><h6 class="mb-1">Nothing recorded yet</h6><p class="mb-0">Offerings, fees collected and spending for this ${N.one} show here. They go into your budget${m.budget_in_use ? ` (${E.esc(m.budget_in_use.label)})` : ""}, tagged with the ${N.one}.</p></div>`}
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
          ${ev.report_back ? `<p class="ev-text mb-0">${E.esc(ev.report_back)}</p>` : `<p class="mb-0 fw-semibold">${ev.status === "completed" ? "No report was written." : `When the ${N.one} is over, mark it as done and write a few lines on how it went.`}</p>`}
        </div>
      </div>
      ${INIT ? wentInitiative(items) : ""}`;
    if (INIT) return;
    p.innerHTML += `
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

  function wentInitiative(items) {
    const s = state.ev.sessions || {};
    const finished = items.reduce((a, r) => a + (r.completed || 0), 0);
    const recorded = items.filter((r) => r.completed != null).length;
    const joined = items.reduce((a, r) => a + r.expected, 0);
    return `
      <div class="row g-4">
        <div class="col-md-6">
          <div class="card custom-card h-100">
            <div class="card-header"><div class="card-title">Sessions</div></div>
            <div class="card-body">
              <div class="ev-big">${E.num(s.held || 0)} <small>of ${E.num(s.total || 0)} held</small></div>
              <div class="progress progress-sm mt-2"><div class="progress-bar bg-success" style="width:${s.total ? Math.round(((s.held || 0) / s.total) * 100) : 0}%"></div></div>
              <p class="mt-2 mb-0">${s.average != null ? `${E.num(s.average)} people a session on average.` : "No attendance recorded yet."}</p>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card custom-card h-100">
            <div class="card-header"><div class="card-title">Finished${state.ev.certificate ? " (certificate)" : ""}</div></div>
            <div class="card-body">
              ${items.length
                ? `<div class="ev-big">${E.num(finished)} <small>of ${E.num(joined)} who took part</small></div><div class="progress progress-sm mt-2"><div class="progress-bar bg-purple" style="width:${joined ? Math.min(100, Math.round((finished / joined) * 100)) : 0}%"></div></div><p class="mt-2 mb-0">${recorded} of ${items.length} places recorded. Record it under Taking part.</p>`
                : `<p class="mb-0 fw-semibold">No place joined - attendance at each session tells the story.</p>`}
            </div>
          </div>
        </div>
      </div>`;
  }

  // ================================================================ sessions (initiatives)
  const SESSION_STATUS = { held: ["Held", "success"], planned: ["Planned", "primary"], cancelled: ["Cancelled", "danger"] };
  const todayIso = () => {
    const t = new Date();
    return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, "0")}-${String(t.getDate()).padStart(2, "0")}`;
  };
  const dayOf = (iso) => new Date(`${iso}T12:00:00`);

  async function fetchSessions() {
    if (state.sessions) return state.sessions;
    const res = await EventsAPI.sessions(id);
    if (!res.ok) {
      Toast.error(res.message);
      return null;
    }
    state.sessions = res.data;
    return state.sessions;
  }

  async function loadSessions() {
    const p = pane("sessions");
    if (!state.sessions) p.innerHTML = loading();
    const data = await fetchSessions();
    if (!data) return;
    const today = todayIso();
    const row = (x) => {
      const missed = x.status === "planned" && x.held_on < today;
      const [label, color] = missed ? ["Not recorded", "secondary"] : SESSION_STATUS[x.status];
      const d = dayOf(x.held_on);
      return `
        <li class="ev-session${x.status === "cancelled" ? " is-cancelled" : ""}" data-session="${x.id}">
          <span class="ev-session-no bg-${color} ${E.textOn(color)}">${x.number}</span>
          <div class="ev-session-day"><strong>${d.getDate()} ${d.toLocaleDateString(undefined, { month: "short" })}</strong><small>${d.toLocaleDateString(undefined, { weekday: "short" })}</small></div>
          <div class="flex-fill" style="min-width:0">
            <div class="fw-semibold text-break">${x.topic ? E.esc(x.topic) : `<span class="ev-session-none">No topic yet</span>`}</div>
            <div class="ev-card-meta mt-1">${UI.pill(label, color)}${x.attendance != null ? `<span><i class="ri-user-follow-line"></i>${E.num(x.attendance)} came</span>` : ""}${x.notes ? `<span class="text-break"><i class="ri-sticky-note-line"></i>${E.esc(x.notes).slice(0, 80)}</span>` : ""}</div>
          </div>
          ${data.can_manage
            ? `<div class="flex-shrink-0">${x.status !== "cancelled" && x.held_on <= today ? `<button class="btn btn-sm btn-success" data-record="${x.id}"><i class="ri-user-follow-line me-1"></i>${x.attendance != null ? "Edit" : "Record"}</button>` : `<button class="btn btn-sm btn-outline-primary" data-record="${x.id}"><i class="ri-edit-line me-1"></i>Edit</button>`}</div>`
            : ""}
        </li>`;
    };
    const s = data.summary;
    p.innerHTML = `
      <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
          <div><div class="card-title">Sessions</div><span class="card-subtitle-text">${E.esc(E.meets(state.ev))}</span></div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="soft-chip soft-success"><i class="ri-checkbox-circle-line"></i>${E.num(s.held)} of ${E.num(s.total)} held</span>
            ${s.average != null ? `<span class="soft-chip soft-purple"><i class="ri-user-follow-line"></i>${E.num(s.average)} a session</span>` : ""}
            ${data.can_manage ? `<button class="btn btn-sm btn-primary" id="addSessionBtn"><i class="ri-add-line me-1"></i>Add a session</button>` : ""}
          </div>
        </div>
        <div class="card-body">
          ${data.items.length ? `<ul class="ev-sessions">${data.items.map(row).join("")}</ul>` : `<div class="ev-empty"><h6 class="mb-1">No sessions</h6><p class="mb-0">Add one, or change how often it meets.</p></div>`}
        </div>
      </div>`;
    p.querySelectorAll("[data-record]").forEach((b) => b.addEventListener("click", () => openSession(data.items.find((x) => x.id === Number(b.dataset.record)))));
    $("addSessionBtn")?.addEventListener("click", () => openSession(null));
  }

  /** One session's window: day and topic, attendance (once the day has come), notes; it didn't happen / remove. */
  function openSession(x) {
    document.getElementById("evSessionModal")?.remove();
    const canHold = x ? x.held_on <= todayIso() : false;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="evSessionModal" tabindex="-1" aria-labelledby="evSessionTitle">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon bg-success text-white"><i class="ri-calendar-check-line"></i></span>
              <div class="flex-fill"><h5 class="modal-title" id="evSessionTitle">${x ? `Session ${x.number}` : "Add a session"}</h5><div class="app-modal-subtitle">${E.esc(state.ev.title)}</div></div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-sm-5"><label class="form-label" for="sDate">Day</label><input type="date" class="form-control" id="sDate" value="${x?.held_on || todayIso()}"></div>
                <div class="col-sm-7"><label class="form-label" for="sTopic">Topic</label><input type="text" class="form-control" id="sTopic" maxlength="160" value="${E.esc(x?.topic || "")}" placeholder="e.g. Prayer and fasting"></div>
              </div>
              ${x
                ? `<div class="ev-sub mt-4 mb-2">How many came</div>
                   ${canHold
                     ? `<div class="row g-3" id="sCounts">${GROUPS.map((g) => `<div class="col-6">${UI.numberStepperHtml(`s_${g}`, { label: E.GROUPS[g], min: 0, max: 100000, value: x[g] ?? "" })}</div>`).join("")}</div>
                        <div class="ev-total mt-3"><span>Came</span><strong id="sTotal">0</strong></div>`
                     : `<div class="alert alert-primary d-flex gap-2 mb-0"><i class="ri-information-line fs-16"></i><span>Attendance can be recorded on the day or after.</span></div>`}
                   <label class="form-label mt-3" for="sNotes">Notes (optional)</label>
                   <textarea class="form-control" id="sNotes" rows="2" maxlength="2000">${E.esc(x.notes || "")}</textarea>`
                : ""}
            </div>
            <div class="modal-footer">
              ${x && x.status !== "cancelled" ? `<button type="button" class="btn btn-link text-danger me-auto" id="sCancel">${x.attendance == null && x.status === "planned" ? "Remove this session" : "It didn't happen"}</button>` : ""}
              ${x && x.status === "cancelled" ? `<button type="button" class="btn btn-link me-auto" id="sRestore">Put it back</button>` : ""}
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
              <button type="button" class="btn btn-success" id="sSave"><i class="ri-check-line me-1"></i>Save</button>
            </div>
          </div>
        </div>
      </div>`,
    );
    const el = $("evSessionModal");
    const modal = new bootstrap.Modal(el);
    const counts = $("sCounts");
    if (counts) {
      const sync = () => ($("sTotal").textContent = E.num(GROUPS.reduce((a, g) => a + (parseInt($(`s_${g}`).value, 10) || 0), 0)));
      UI.initSteppers(counts, sync);
      counts.addEventListener("input", sync);
      sync();
    }
    const done = async (res) => {
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      modal.hide();
      state.sessions = null;
      await reload(false);
    };
    $("sSave").addEventListener("click", async () => {
      const btn = $("sSave");
      UI.setButtonLoading(btn, "Saving...");
      let res;
      if (!x) {
        res = await EventsAPI.addSession(id, { held_on: $("sDate").value, topic: $("sTopic").value.trim() || null });
      } else {
        const body = { held_on: $("sDate").value, topic: $("sTopic").value.trim() || null, notes: $("sNotes").value.trim() || null };
        if (counts) GROUPS.forEach((g) => (body[g] = $(`s_${g}`).value === "" ? null : parseInt($(`s_${g}`).value, 10)));
        res = await EventsAPI.updateSession(x.id, body);
      }
      UI.restoreButton(btn);
      done(res);
    });
    $("sCancel")?.addEventListener("click", async () => {
      const remove = x.attendance == null && x.status === "planned";
      done(remove ? await EventsAPI.removeSession(x.id) : await EventsAPI.updateSession(x.id, { status: "cancelled" }));
    });
    $("sRestore")?.addEventListener("click", async () => done(await EventsAPI.updateSession(x.id, { status: x.attendance != null ? "held" : "planned" })));
    el.addEventListener("hidden.bs.modal", () => el.remove());
    modal.show();
  }

  // ================================================================ progress (initiatives)
  async function loadProgress() {
    const p = pane("progress");
    if (!state.sessions) p.innerHTML = loading();
    const data = await fetchSessions();
    if (!data) return;
    const held = data.items.filter((x) => x.status === "held" && x.attendance != null);
    if (!held.length) {
      p.innerHTML = `<div class="card custom-card"><div class="card-body"><div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-pink text-white mb-2"><i class="ri-line-chart-line fs-20"></i></span><h6 class="mb-1">No attendance yet</h6><p class="mb-0">Once attendance is recorded at the sessions, this shows how it goes from one session to the next.</p></div></div></div>`;
      return;
    }
    const best = held.reduce((a, x) => (x.attendance > a.attendance ? x : a), held[0]);
    const first = held[0].attendance;
    const last = held[held.length - 1].attendance;
    const expected = state.ev.capacity || state.ev.totals?.expected || null;
    const delta = held.length > 1 ? UI.periodDelta(last, first, { prevLabel: "the first session" }) : null;
    const rate = expected ? Math.round((held.reduce((a, x) => a + x.attendance, 0) / (held.length * expected)) * 100) : null;
    p.innerHTML = `
      <div class="row g-3 mb-1">
        <div class="col-md-4">${UI.renderSparkCard({ icon: "ri-user-follow-line", label: "Last session", value: E.num(last), color: "success", delta, sub: `Session ${held[held.length - 1].number}` })}</div>
        <div class="col-md-4">${UI.renderSparkCard({ icon: "ri-trophy-line", label: "Best session", value: E.num(best.attendance), color: "purple", sub: `Session ${best.number} · ${E.shortDate(dayOf(best.held_on))}` })}</div>
        <div class="col-md-4">${UI.renderSparkCard({ icon: "ri-percent-line", label: "Attendance rate", value: rate == null ? "-" : `${rate}%`, color: "secondary", sub: expected ? `Against ${E.num(expected)} expected each time` : "Set how many it is for, or let places join" })}</div>
      </div>
      <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Attendance per session</div><span class="card-subtitle-text">Youth, adults, children and leaders</span></div></div>
        <div class="card-body"><div id="progressChart"></div></div>
      </div>`;
    progressChart?.destroy?.();
    progressChart = UI.renderTrendChart("progressChart", {
      categories: held.map((x) => `${x.number} · ${E.shortDate(dayOf(x.held_on))}`),
      series: GROUPS.map((g) => ({ name: E.GROUPS[g], type: "column", data: held.map((x) => x[g] || 0) })),
      type: "mixed",
      stacked: true,
      colors: GROUPS.map((g) => UI.cssColor(E.GROUP_COLORS[g])),
    });
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
      const ok = await E.ask({ title: `Publish this ${N.one}?`, text: ev.open_to === "own" ? `It goes on your ${N.many}. Nobody else is told.` : `The leaders of the places it's open to (${E.esc(ev.open_to_label.toLowerCase())}) get a notification and can ${INIT ? "join" : "register"}.`, icon: "ri-send-plane-line", color: "success", action: "Publish", actionColor: "success" });
      if (!ok) return;
      UI.setButtonLoading(btn, "Publishing...");
      res = await EventsAPI.publish(id);
    } else if (name === "complete") {
      const text = await E.ask({ title: "Mark as done", text: "Write a few lines on how it went - the places above you can read it.", icon: "ri-checkbox-circle-line", color: "success", action: "Mark as done", actionColor: "success", textarea: { label: "How it went (optional)", placeholder: "e.g. 640 young people came; 32 gave their lives to Christ..." } });
      if (text === null) return;
      res = await EventsAPI.complete(id, text || null);
    } else if (name === "cancel") {
      const ok = await E.ask({ title: `Cancel this ${N.one}?`, text: ev.status === "published" ? `The places that ${INIT ? "joined" : "registered"} get a notification that it's cancelled. This can't be undone.` : "The draft is kept, marked as cancelled. This can't be undone.", icon: "ri-close-circle-line", color: "danger", action: `Cancel the ${N.one}`, actionColor: "danger" });
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
    const failedPage = (title, message, retry) => {
      $("eventPage").innerHTML = `<div class="card custom-card"><div class="card-body"><div class="ev-empty"><span class="avatar avatar-lg avatar-rounded bg-danger text-white mb-2"><i class="ri-close-circle-line fs-20"></i></span><h6 class="mb-1">${title}</h6><p class="mb-3">${E.esc(message)}</p><div class="d-flex flex-wrap justify-content-center gap-2">${retry ? '<button type="button" class="btn btn-primary" id="pageRetry"><i class="ri-refresh-line me-1"></i>Try again</button>' : ""}<a class="btn ${retry ? "btn-light" : "btn-primary"}" href="${CTX.baseUrl}/">Back to ${N.many}</a></div></div></div></div>`;
      $("pageRetry")?.addEventListener("click", () => window.location.reload());
    };
    if (!res.ok) {
      // Not found or not allowed: no point trying again. Anything else (slow, offline): Try again.
      return failedPage(res.status === 404 ? `This ${N.one} isn't one you can see` : `Couldn't open the ${N.one}`, res.message, ![403, 404].includes(res.status));
    }
    state.ev = res.data;
    if (resetCaches) {
      state.regs = state.money = state.history = state.sessions = null;
    }
    document.title = `${state.ev.title} - Makueni West Diocese`;
    try {
      renderHero();
      renderStats();
      renderInvitedStats();
      renderSide();
      await renderTabs();
    } catch (e) {
      console.error(e);
      failedPage(`Couldn't show the ${N.one}`, "Something on this page went wrong.", true);
    }
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
