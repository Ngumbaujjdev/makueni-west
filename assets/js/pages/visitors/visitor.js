/**
 * ============================================================================
 * VISITORS - one visitor's page (visitor.php?id=)
 * ============================================================================
 * The hero (stage, first visit, visits, who follows them up, and what you
 * can do), then Follow-up (the timeline and the next step), Visits, Details
 * (name, phone, area - all we keep) and History. Windows: log a follow-up,
 * send an SMS (not if they asked not to be texted), record a visit, assign,
 * edit, became a member, and remove personal details. The tab is kept in
 * the URL (?tab=).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const V = VisitorsUI;
  const CTX = window.VISITORS_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("id"));
  const TABS = ["followup", "visits", "details", "history"];
  const state = { p: null, options: null, tab: TABS.includes(params.get("tab")) ? params.get("tab") : "followup", history: null };

  const notGiven = '<span class="mb-sub">Not given</span>';
  const row = (icon, label, html) => `<li class="mr-row"><div class="mr-row-head"><span class="ev-tile is-sm is-soft" style="--q: var(--primary-rgb)"><i class="${icon}"></i></span>${label}</div><p class="mr-row-body">${html}</p></li>`;
  const first = () => (state.p.first_name || state.p.name.split(" ")[0]).trim();
  const isVisitor = () => state.p.status === "visitor" && !state.p.anonymised;
  const canWork = () => state.p.can.manage && !state.p.anonymised;

  // -------------------------------------------------------------- the hero
  function renderHero() {
    const p = state.p;
    const chips = [
      p.first_visit_on ? `<span class="soft-chip soft-primary"><i class="ri-calendar-event-line"></i>First came ${M.day(p.first_visit_on)}</span>` : "",
      `<span class="soft-chip soft-success"><i class="ri-repeat-line"></i>${p.visits} ${p.visits === 1 ? "visit" : "visits"}</span>`,
      p.area ? `<span class="soft-chip soft-purple"><i class="ri-map-pin-line"></i>${M.esc(p.area)}</span>` : "",
    ].join("");
    const meta = [
      p.phone ? `<span><i class="ri-phone-line"></i>${M.esc(p.phone)}</span>` : "",
      p.phone ? `<span><i class="${p.consent ? "ri-chat-check-line" : "ri-chat-off-line"}"></i>${p.consent ? "Can be texted" : "Asked not to be texted"}</span>` : "",
      `<span><i class="ri-user-follow-line"></i>${p.assigned ? `${M.esc(p.assigned.name)} follows up` : "Nobody follows up yet"}</span>`,
    ].join("");
    const work = canWork();
    const stages = Object.entries(V.STAGES).filter(([k]) => k !== "member" && k !== p.stage);
    const more = work
      ? `<div class="dropdown">
          <button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-more-2-line me-1"></i>More</button>
          <ul class="dropdown-menu dropdown-menu-end">
            ${isVisitor() ? '<li><button class="dropdown-item" data-act="visit"><i class="ri-calendar-check-line me-2"></i>Record a visit</button></li>' : ""}
            ${isVisitor() ? '<li><button class="dropdown-item" data-act="assign"><i class="ri-user-follow-line me-2"></i>Who follows them up</button></li>' : ""}
            <li><button class="dropdown-item" data-act="edit"><i class="ri-edit-line me-2"></i>Edit details</button></li>
            ${isVisitor() ? `<li><hr class="dropdown-divider"></li><li><h6 class="dropdown-header">Move to</h6></li>${stages.map(([k, s]) => `<li><button class="dropdown-item" data-act="stage" data-stage="${k}"><i class="${s.icon} me-2"></i>${s.label}</button></li>`).join("")}` : ""}
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item" data-act="${p.archived ? "restore" : "archive"}"><i class="${p.archived ? "ri-inbox-unarchive-line" : "ri-archive-line"} me-2"></i>${p.archived ? "Bring back to the board" : "Archive"}</button></li>
            <li><button class="dropdown-item text-danger" data-act="anonymise"><i class="ri-user-unfollow-line me-2"></i>Remove personal details</button></li>
          </ul>
        </div>`
      : "";
    const sms = isVisitor() && work ? (p.can.sms ? '<button type="button" class="btn btn-outline-primary" data-act="sms"><i class="ri-chat-3-line me-1"></i>Send SMS</button>' : `<span class="d-inline-block" tabindex="0" title="${!p.phone ? "No phone number" : p.demo ? "A demo number - never texted" : `${M.esc(first())} asked not to be texted`}"><button type="button" class="btn btn-outline-primary" disabled><i class="ri-chat-off-line me-1"></i>Send SMS</button></span>`) : "";
    $("vsHero").innerHTML = `
      <div class="card-body">
        <div class="ev-hero-row">
          ${M.avatar({ id: p.id, initials: p.initials }, "xxl")}
          <div class="flex-fill" style="min-width:0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${M.esc(p.name)}</h2>${V.stagePill(p.stage)}${p.due_on ? V.dueChip(p.due_on) : ""}</div>
            <div class="d-flex flex-wrap gap-1 mb-1">${chips}</div>
            <div class="ev-card-meta">${meta}</div>
          </div>
          <div class="ev-hero-actions">
            ${isVisitor() && work ? '<button type="button" class="btn btn-primary" data-act="log"><i class="ri-add-line me-1"></i>Log follow-up</button>' : ""}
            ${sms}
            ${isVisitor() && p.can.make_member ? '<button type="button" class="btn btn-outline-primary" data-act="member"><i class="ri-home-heart-line me-1"></i>Became a member</button>' : ""}
            ${p.status === "member" && p.can.members ? `<a class="btn btn-outline-primary" href="${CTX.membersUrl}/member?id=${p.id}"><i class="ri-contacts-book-2-line me-1"></i>Member page</a>` : ""}
            ${more}
          </div>
        </div>
        ${
          p.anonymised
            ? '<div class="alert alert-secondary d-flex gap-2 mt-3 mb-0"><i class="ri-user-unfollow-line fs-16"></i><span>Their personal details were removed. They still count in the totals.</span></div>'
            : p.status === "member"
              ? `<div class="alert alert-success d-flex gap-2 mt-3 mb-0"><i class="ri-home-heart-line fs-16"></i><span>Became a member on ${M.day(p.became_member_on)}. Their visits and follow-ups stay here.</span></div>`
              : p.archived
                ? '<div class="alert alert-secondary d-flex gap-2 mt-3 mb-0"><i class="ri-archive-line fs-16"></i><span>Archived - off the board, but still counted. Use More to bring them back.</span></div>'
                : ""
        }
      </div>`;
    M.loadPhotos($("vsHero"));
    $("tabFollowups").textContent = p.followups.length ? `${p.followups.length} so far` : "Nothing logged yet";
    $("tabVisits").textContent = `${p.visits} ${p.visits === 1 ? "visit" : "visits"}`;
  }

  // -------------------------------------------------------------- side
  function renderSide() {
    const p = state.p;
    const order = Object.keys(V.STAGES);
    const at = order.indexOf(p.stage);
    $("vsSide").innerHTML = `
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">Where they are</div></div>
        <div class="card-body">
          <ol class="vs-steps">${order
            .map((k, i) => {
              const s = V.STAGES[k];
              const cls = i < at ? "is-done" : i === at ? "is-now" : "";
              return `<li class="${cls}" style="--q: var(--${s.color}-rgb)"><span class="vs-steps-dot">${i < at ? '<i class="ri-check-line"></i>' : `<i class="${s.icon}"></i>`}</span><div><strong>${s.label}</strong><small>${s.hint}</small></div></li>`;
            })
            .join("")}</ol>
        </div>
      </div>
      <div class="card custom-card">
        <div class="card-header"><div class="card-title">At a glance</div></div>
        <div class="card-body">
          <div class="mb-glance">
            <div><span>First visit</span><strong>${M.day(p.first_visit_on)}</strong></div>
            <div><span>Last visit</span><strong>${M.day(p.last_visit_on)}</strong></div>
            <div><span>Visits</span><strong>${M.num(p.visits)}</strong></div>
            <div><span>Follows up</span><strong>${p.assigned ? M.esc(p.assigned.name) : "Nobody yet"}</strong></div>
          </div>
        </div>
      </div>`;
  }

  // -------------------------------------------------------------- follow-up
  function renderFollowup() {
    const p = state.p;
    const d = V.due(p.due_on);
    const next = isVisitor()
      ? `<div class="vs-next${d.tone === "late" ? " is-late" : ""}">
          <span class="ev-tile${d.tone === "late" ? "" : " is-soft"}" style="--q: var(--${d.tone === "late" ? "danger" : d.tone === "none" ? "success" : "primary"}-rgb)"><i class="${d.tone === "none" ? "ri-check-line" : "ri-time-line"}"></i></span>
          <div class="flex-fill min-w-0"><strong>${d.tone === "none" ? "Nothing due" : `Next follow-up · ${d.text}`}</strong><small>${p.due_on ? M.day(p.due_on) : p.followups.length ? "Set a next step when you log the next follow-up." : "Log the first follow-up after their visit."}</small></div>
          ${canWork() ? '<button type="button" class="btn btn-primary btn-sm" data-act="log"><i class="ri-add-line me-1"></i>Log follow-up</button>' : ""}
        </div>`
      : "";
    const list = p.followups.length
      ? `<ol class="ev-timeline vs-timeline">${p.followups
          .map((f) => {
            const t = V.TYPES[f.type] || V.TYPES.call;
            const o = V.OUTCOMES[f.outcome] || V.OUTCOMES.other;
            return `<li style="--q: var(--${t.color}-rgb)"><span class="ev-timeline-dot"></span><div class="flex-fill min-w-0">
              <span class="ev-timeline-when">${M.day(f.done_on)}${f.by ? ` · ${M.esc(f.by)}` : ""}</span>
              <span class="ev-timeline-what fw-semibold"><i class="${t.icon} me-1"></i>${t.label} <span class="soft-chip soft-${o.color === "danger" ? "danger" : o.color === "success" ? "success" : "primary"} ms-1">${o.label}</span></span>
              ${f.note ? `<p class="vs-note">${M.esc(f.note)}</p>` : ""}
              ${f.next_on ? `<span class="mb-sub"><i class="ri-arrow-right-line me-1"></i>Next step ${M.day(f.next_on)}</span>` : ""}
            </div></li>`;
          })
          .join("")}</ol>`
      : `<p class="mb-0 fw-semibold">No follow-up yet.${isVisitor() ? ` A call or an SMS in the first ${state.options?.followup_days || 3} days makes a visitor far more likely to come back.` : ""}</p>`;
    $("vsMain").innerHTML = `
      ${next ? `<div class="card custom-card"><div class="card-body">${next}</div></div>` : ""}
      <div class="card custom-card">
        <div class="card-header justify-content-between"><div class="card-title">Follow-up so far</div>${V.privateChip()}</div>
        <div class="card-body">${list}</div>
      </div>`;
    renderSide();
  }

  // -------------------------------------------------------------- visits
  function renderVisits() {
    const p = state.p;
    $("vsMain").innerHTML = `
      <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2"><div class="card-title">Their visits</div>${canWork() && isVisitor() ? '<button type="button" class="btn btn-sm btn-outline-primary" data-act="visit"><i class="ri-add-line me-1"></i>Record a visit</button>' : ""}</div>
        <div class="card-body">${
          p.visit_list.length
            ? `<ul class="mr-rows">${p.visit_list
                .map(
                  (v) => `<li class="mr-row">
                    <div class="mr-row-head"><span class="ev-tile is-sm is-soft" style="--q: var(--${v.first_time ? "pink" : "success"}-rgb)"><i class="${v.first_time ? "ri-star-smile-line" : "ri-repeat-line"}"></i></span>${M.day(v.on)}</div>
                    <div class="mr-row-body">
                      <div class="d-flex flex-wrap gap-1">${v.first_time ? '<span class="badge bg-pink text-white">First time</span>' : ""}${v.gathering ? `<span class="soft-chip soft-primary"><i class="ri-community-line"></i>${M.esc(v.gathering)}</span>` : '<span class="mb-sub">At church</span>'}</div>
                    </div>
                  </li>`,
                )
                .join("")}</ul>`
            : '<p class="mb-0 fw-semibold">No visits recorded.</p>'
        }</div>
      </div>`;
    renderSide();
  }

  // -------------------------------------------------------------- details
  function renderDetails() {
    const p = state.p;
    $("vsMain").innerHTML = `
      <div class="card custom-card">
        <div class="card-header justify-content-between"><div class="card-title">Details</div><div class="d-flex gap-2 align-items-center">${V.privateChip()}${canWork() ? '<button type="button" class="btn btn-sm btn-outline-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Edit</button>' : ""}</div></div>
        <div class="card-body"><ul class="mr-rows">${[
          row("ri-user-3-line", "Name", M.esc(p.name)),
          row("ri-phone-line", "Phone", p.phone ? M.esc(p.phone) : notGiven),
          row("ri-map-pin-line", "Area", p.area ? M.esc(p.area) : notGiven),
          row("ri-chat-3-line", "Texting", !p.phone ? "No phone" : p.demo ? "A demo number - never texted" : p.consent ? "Can be texted" : "Asked not to be texted - the system won't text them"),
        ].join("")}</ul></div>
      </div>`;
    renderSide();
  }

  // -------------------------------------------------------------- history
  async function renderHistory() {
    $("vsSide").innerHTML = "";
    $("vsMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">History</div></div><div class="card-body" id="historyBody"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:60%"></span></div></div>`;
    if (!state.history) {
      const res = await VisitorsAPI.history(id);
      if (!res.ok) return ($("historyBody").innerHTML = M.errorBox(res.message));
      state.history = res.data;
    }
    $("historyBody").innerHTML = state.history.length
      ? `<ul class="ev-history">${state.history.map((h) => `<li><span class="ev-history-dot bg-${UI.colorFor(h.who)}"></span><div><strong>${M.esc(h.sentence)}</strong><small>${new Date(h.at).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" })}</small></div></li>`).join("")}</ul>`
      : '<p class="mb-0 fw-semibold">Nothing recorded yet.</p>';
  }

  function showTab() {
    document.querySelectorAll("#vsTabs [data-tab]").forEach((b) => b.classList.toggle("active", b.dataset.tab === state.tab));
    const q = new URLSearchParams(window.location.search);
    state.tab === "followup" ? q.delete("tab") : q.set("tab", state.tab);
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    ({ followup: renderFollowup, visits: renderVisits, details: renderDetails, history: renderHistory })[state.tab]();
  }

  function refresh(p, message) {
    state.p = p;
    state.history = null;
    renderHero();
    showTab();
    if (message) Toast.success(message);
  }

  // -------------------------------------------------------------- windows
  const save = async (btn, call, el) => {
    UI.setButtonLoading(btn, "Saving...");
    const res = await call();
    UI.restoreButton(btn);
    if (!res.ok) return Toast.error(res.message);
    bootstrap.Modal.getInstance(el)?.hide();
    refresh(res.data, res.message);
  };
  const addDays = (n) => {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  };
  const nextSunday = () => addDays(((7 - new Date().getDay()) % 7) || 7);

  function logFollowup() {
    const type = (k, t, checked) => `<label class="ec-choice"><input type="radio" name="fuType" value="${k}"${checked ? " checked" : ""}><span class="ec-choice-icon"><i class="${t.icon}"></i></span><span><strong class="d-block">${t.label}</strong></span><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`;
    const el = V.modal({
      title: "Log a follow-up",
      subtitle: state.p.name,
      icon: "ri-phone-line",
      color: "primary",
      size: "modal-lg",
      body: `<div class="row g-3">
        <div class="col-12"><label class="form-label">How did you follow up?</label><div class="ec-choices" role="radiogroup" aria-label="How">${Object.entries(V.TYPES).map(([k, t], i) => type(k, t, i === 0)).join("")}</div></div>
        <div class="col-md-6"><label class="form-label" for="fuOutcome">How did it go?</label><select class="form-select" id="fuOutcome">${Object.entries(V.OUTCOMES).filter(([k]) => k !== "sent").map(([k, o]) => `<option value="${k}">${o.label}</option>`).join("")}</select></div>
        <div class="col-md-6"><label class="form-label" for="fuOn">When</label><input type="date" class="form-control" id="fuOn" value="${V.todayIso()}" max="${V.todayIso()}"></div>
        <div class="col-12"><label class="form-label" for="fuNote">Note <span class="fw-normal">(optional - only your church's leaders see it)</span></label><textarea class="form-control" id="fuNote" rows="3" maxlength="2000" placeholder="e.g. Asked about the youth group; will come with her sister"></textarea></div>
        <div class="col-12"><label class="form-label" for="fuNext">Next step <span class="fw-normal">(optional)</span></label>
          <div class="d-flex flex-wrap gap-2 align-items-center"><input type="date" class="form-control vs-date" id="fuNext" min="${V.todayIso()}">
            <button type="button" class="btn btn-sm btn-light border" data-next="${addDays(3)}">In 3 days</button><button type="button" class="btn btn-sm btn-light border" data-next="${nextSunday()}">Next Sunday</button><button type="button" class="btn btn-sm btn-light border" data-next="${addDays(14)}">In 2 weeks</button></div>
          <div class="form-text">When someone should get in touch again. It shows in My follow-ups and on the calendar.</div></div>
      </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="fuSave"><i class="ri-check-line me-1"></i>Log it</button>`,
    });
    UI.enhanceSelect($("fuOutcome"), { search: false });
    el.querySelectorAll("[data-next]").forEach((b) => b.addEventListener("click", () => ($("fuNext").value = b.dataset.next)));
    $("fuSave").addEventListener("click", (e) =>
      save(e.currentTarget, () => VisitorsAPI.followup(id, { type: el.querySelector('input[name="fuType"]:checked').value, outcome: $("fuOutcome").value, done_on: $("fuOn").value, note: $("fuNote").value.trim() || null, next_on: $("fuNext").value || null }), el),
    );
  }

  function sendSms() {
    const el = V.modal({
      title: "Send an SMS",
      subtitle: `${state.p.name} · ${state.p.phone}`,
      icon: "ri-chat-3-line",
      color: "info",
      body: `<label class="form-label" for="smsText">Message</label><textarea class="form-control" id="smsText" rows="4" maxlength="640">Hi ${M.esc(first())}, </textarea>
        <div class="d-flex justify-content-between form-text"><span>Your church's SMS signature is added at the end.</span><span id="smsCount"></span></div>
        <p class="mb-sub mt-2 mb-0"><i class="ri-chat-check-line me-1"></i>The SMS is logged as a follow-up.</p>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="smsSend"><i class="ri-send-plane-line me-1"></i>Send</button>`,
    });
    const count = () => {
      const n = $("smsText").value.length;
      $("smsCount").textContent = `${n} characters · ${Math.max(1, Math.ceil(n / 160))} SMS`;
    };
    $("smsText").addEventListener("input", count);
    count();
    el.addEventListener("shown.bs.modal", () => {
      const t = $("smsText");
      t.focus();
      t.setSelectionRange(t.value.length, t.value.length);
    });
    $("smsSend").addEventListener("click", (e) => {
      if (!$("smsText").value.trim()) return Toast.error("Write the message.");
      save(e.currentTarget, () => VisitorsAPI.sms(id, $("smsText").value.trim()), el);
    });
  }

  function recordVisit() {
    const g = state.options?.gathering_types || [];
    const el = V.modal({
      title: "Record a visit",
      subtitle: state.p.name,
      icon: "ri-calendar-check-line",
      color: "success",
      body: `<div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="viOn">Date</label><input type="date" class="form-control" id="viOn" value="${V.todayIso()}" max="${V.todayIso()}"></div>
        <div class="col-md-6"><label class="form-label" for="viG">Gathering <span class="fw-normal">(optional)</span></label><select class="form-select" id="viG"><option value="">Not linked to one</option>${g.map((x) => `<option value="${x.id}">${M.esc(x.name)}</option>`).join("")}</select></div>
      </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="viSave"><i class="ri-check-line me-1"></i>Record visit</button>`,
    });
    UI.enhanceSelect($("viG"), { search: false });
    $("viSave").addEventListener("click", (e) => save(e.currentTarget, () => VisitorsAPI.visit(id, { on: $("viOn").value, gathering_type_id: $("viG").value || null }), el));
  }

  function assign() {
    const leaders = state.options?.leaders || [];
    const el = V.modal({
      title: "Who follows them up",
      subtitle: state.p.name,
      icon: "ri-user-follow-line",
      color: "purple",
      body: `<label class="form-label" for="asWho">Leader</label><select class="form-select" id="asWho"><option value="">Nobody yet</option>${leaders.map((l) => `<option value="${l.id}" data-color="${UI.colorFor(l.name)}"${state.p.assigned?.id === l.id ? " selected" : ""}>${M.esc(l.name)}${l.id === CTX.userId ? " (me)" : ""}</option>`).join("")}</select>
        <div class="form-text">They're told in the app. Only leaders who can follow visitors up are listed.</div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="asSave"><i class="ri-check-line me-1"></i>Save</button>`,
    });
    UI.enhanceSelect($("asWho"));
    $("asSave").addEventListener("click", (e) => save(e.currentTarget, () => VisitorsAPI.assign(id, $("asWho").value ? Number($("asWho").value) : null), el));
  }

  function edit() {
    const p = state.p;
    const el = V.modal({
      title: "Edit details",
      subtitle: p.name,
      icon: "ri-edit-line",
      color: "primary",
      body: `<div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="edFirst">First name</label><input class="form-control" id="edFirst" maxlength="80" value="${M.esc(p.first_name)}"></div>
        <div class="col-md-6"><label class="form-label" for="edLast">Last name</label><input class="form-control" id="edLast" maxlength="80" value="${M.esc(p.last_name)}"></div>
        <div class="col-md-6"><label class="form-label" for="edPhone">Phone</label><input class="form-control" id="edPhone" inputmode="tel" maxlength="30" value="${M.esc(p.phone || "")}"></div>
        <div class="col-md-6"><label class="form-label" for="edArea">Area</label><input class="form-control" id="edArea" maxlength="80" list="edAreas" value="${M.esc(p.area || "")}"><datalist id="edAreas">${(state.options?.areas || []).map((a) => `<option value="${M.esc(a)}">`).join("")}</datalist></div>
        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="edNoText"${p.consent ? "" : " checked"}><label class="form-check-label" for="edNoText">Don't text them - they asked not to be contacted</label></div></div>
      </div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="edSave"><i class="ri-check-line me-1"></i>Save</button>`,
    });
    $("edSave").addEventListener("click", (e) =>
      save(e.currentTarget, () => VisitorsAPI.update(id, { first_name: $("edFirst").value.trim(), last_name: $("edLast").value.trim(), phone: $("edPhone").value.trim() || null, area: $("edArea").value.trim() || null, consent_contact: !$("edNoText").checked }), el),
    );
  }

  function anonymise() {
    const el = V.modal({
      title: "Remove personal details",
      subtitle: state.p.name,
      icon: "ri-user-unfollow-line",
      color: "danger",
      body: `<p class="fw-semibold">This clears their name, phone, area and follow-up notes. Their visits still count in the totals. <strong>It can't be undone.</strong></p>
        <label class="form-label" for="anConfirm">Type <strong>REMOVE</strong> to confirm</label><input class="form-control" id="anConfirm" autocomplete="off">`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Keep them</button><button type="button" class="btn btn-danger" id="anGo" disabled><i class="ri-user-unfollow-line me-1"></i>Remove their details</button>`,
    });
    $("anConfirm").addEventListener("input", (e) => ($("anGo").disabled = e.target.value.trim() !== "REMOVE"));
    $("anGo").addEventListener("click", (e) => save(e.currentTarget, () => VisitorsAPI.anonymise(id), el));
  }

  async function act(name, btn) {
    const windows = { log: logFollowup, sms: sendSms, visit: recordVisit, assign, edit, anonymise };
    if (windows[name]) return windows[name]();
    if (name === "member") return V.becomeMember(state.p, CTX.membersUrl, { onDone: (p) => refresh(p) });
    const call = { archive: () => VisitorsAPI.archive(id), restore: () => VisitorsAPI.restore(id), stage: () => VisitorsAPI.stage(id, btn.dataset.stage) }[name];
    if (!call) return;
    const res = await call();
    if (!res.ok) return Toast.error(res.message);
    refresh(res.data, res.message);
  }

  async function init() {
    if (!id) {
      $("vsHero").innerHTML = `<div class="card-body">${M.errorBox("Open a visitor from the board.", `location.href='${CTX.baseUrl}/'`)}</div>`;
      return;
    }
    const [res, opts] = await Promise.all([VisitorsAPI.get(id), VisitorsAPI.options()]);
    if (!res.ok) {
      $("vsHero").innerHTML = `<div class="card-body">${M.errorBox(res.message, res.status === 404 ? `location.href='${CTX.baseUrl}/'` : "location.reload()")}</div>`;
      return;
    }
    state.p = res.data;
    state.options = opts.ok ? opts.data : null;
    document.title = `${res.data.name} - Makueni West Diocese`;
    $("vsTabs").hidden = false;
    renderHero();
    showTab();
    $("vsTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b || b.dataset.tab === state.tab) return;
      state.tab = b.dataset.tab;
      showTab();
    });
    document.addEventListener("click", (e) => {
      const b = e.target.closest("#vsHero [data-act], #vsPanes [data-act]");
      if (b) act(b.dataset.act, b);
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
