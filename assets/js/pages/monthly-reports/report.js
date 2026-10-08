/**
 * ============================================================================
 * MONTHLY REPORTS - one report (church, region; read from above)
 * ============================================================================
 * Our own draft: four steps (the figures, what happened, in the pastor's
 * words, files then send) with a "what goes up" preview; it saves as you
 * type. Sent, seen, or a report from below: one tidy document with the
 * files, the comments and - from above - Mark as seen.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const R = ReportsUI;
  const CTX = window.REPORTS_CTX;
  const $ = (id) => document.getElementById(id);
  const q = new URLSearchParams(window.location.search);
  const byId = Number(q.get("id")) || null;
  let year = Number(q.get("year")) || null;
  let month = Number(q.get("month")) || null;
  const WORDS = {
    achievements: ["What went well", "Growth, answered prayer, things to give thanks for", "ri-trophy-line", "success"],
    challenges: ["Challenges", "What was hard this month", "ri-error-warning-line", "danger"],
    prayer_requests: ["Prayer requests", "What we ask you to pray for", "ri-hand-heart-line", "purple"],
    support_needed: ["Support we need", "From the region or the diocese", "ri-service-line", "primary"],
    testimonies: ["Testimonies", "Stories worth telling", "ri-chat-smile-2-line", "pink"],
    next_month: ["Plans for next month", "What's coming up", "ri-calendar-todo-line", "secondary"],
  };
  let r = null; // the report as the API gives it
  let step = 1;
  let saveTimer = null;
  let saving = null;

  const recordLink = (what) => {
    const lvl = CTX.level;
    const base = CTX.siteUrl;
    return (
      {
        people: lvl === "church" ? `${base}/church/demographics-growth/demographics-tracking.php` : null,
        attendance: lvl === "church" ? `${base}/church/attendance/` : null,
        money: lvl === "church" ? `${base}/church/budget/overview.php` : `${base}/${lvl}/budgets/overview.php`,
      }[what] || null
    );
  };

  // ================================================================ header
  function renderHero() {
    const s = R.stateOf(r);
    const own = r.relation === "own";
    const actions = [
      r.can.seen ? `<button class="btn btn-primary" data-act="seen"><i class="ri-eye-line me-1"></i>Mark as seen</button>` : "",
      r.can.reopen ? `<button class="btn btn-outline-primary" data-act="reopen"><i class="ri-arrow-go-back-line me-1"></i>Take it back to change</button>` : "",
      r.id && r.status !== "draft" ? `<button class="btn btn-outline-primary" data-report-key="monthly.report" data-module="monthly-reports" data-report-id="${r.id}" data-territory-id="${own ? r.place.id : CTX.place.id}"><i class="ri-download-2-line me-1"></i>Export</button>` : "",
    ].join("");
    const facts = [
      `<span><i class="ri-building-4-line"></i>${R.esc(r.place.name)}${r.reports_to ? ` → ${R.esc(r.reports_to.name)}` : ""}</span>`,
      r.sent_at ? `<span><i class="ri-send-plane-line"></i>Sent ${R.longDate(r.sent_at)}${r.sent_by ? ` by ${R.esc(r.sent_by)}` : ""}</span>` : `<span><i class="ri-calendar-event-line"></i>Due ${R.longDate(r.due_on)}</span>`,
      r.seen_at ? `<span><i class="ri-eye-line"></i>Seen ${R.longDate(r.seen_at)}${r.seen_by ? ` by ${R.esc(r.seen_by)}` : ""}</span>` : "",
    ].join("");
    $("mrHero").innerHTML = `
      <div class="card-body">
        <div class="ev-hero-row">
          <div class="mr-hero-icon bg-${s.color === "light" ? "primary" : s.color} ${R.textOn(s.color)}"><strong>${r.label.split(" ")[0].slice(0, 3)}</strong><span>${r.year}</span></div>
          <div class="flex-fill" style="min-width:0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${R.esc(r.label)} report</h2>${R.statePill(r)}</div>
            <div class="ev-card-meta">${facts}</div>
          </div>
          <div class="ev-hero-actions">${actions}</div>
        </div>
        ${stepper()}
        ${!r.open ? `<div class="alert alert-primary d-flex gap-2 mt-3 mb-0"><i class="ri-time-line fs-16"></i><span>${R.esc(r.label.split(" ")[0])} hasn't started yet - you can write ahead, and send once it has.</span></div>` : ""}
      </div>`;
  }

  /** Where the report is: Started -> Sent -> Seen, with the dates (replaces the "Where it is" card). */
  function stepper() {
    if (!r.id && r.status !== "draft") return "";
    const steps = [
      { label: "Started", done: true, when: r.created_at ? R.longDate(r.created_at) : "" },
      { label: r.reports_to ? `Sent to ${r.reports_to.name}` : "Sent", done: !!r.sent_at, when: r.sent_at ? `${R.longDate(r.sent_at)}${r.on_time === false ? " · late" : ""}` : `Due ${R.longDate(r.due_on)}` },
      { label: "Seen", done: !!r.seen_at, when: r.seen_at ? `${R.longDate(r.seen_at)}${r.seen_by ? ` · ${r.seen_by}` : ""}` : r.sent_at ? "Waiting to be read" : "" },
    ];
    const current = steps.findIndex((x) => !x.done);
    return `<ol class="mr-stepper">${steps
      .map((x, i) => `<li class="${x.done ? "is-done" : i === current ? "is-now" : ""}"><span class="mr-stepper-dot">${x.done ? '<i class="ri-check-line"></i>' : i + 1}</span><div><strong>${R.esc(x.label)}</strong>${x.when ? `<small>${R.esc(x.when)}</small>` : ""}</div></li>`)
      .join("")}</ol>`;
  }

  // ================================================================ the figures
  function figureTiles(f, { compact = false } = {}) {
    const tile = (icon, color, label, value, sub, link) => `
      <div class="mr-fig">
        <span class="avatar avatar-md avatar-rounded bg-${color} ${R.textOn(color)}"><i class="${icon}"></i></span>
        <div class="flex-fill" style="min-width:0"><small>${label}</small><strong>${value}</strong>${sub ? `<span>${sub}</span>` : ""}</div>
        ${link && !compact ? `<a class="mr-fig-link" href="${link}" title="Open where it's recorded"><i class="ri-arrow-right-up-line"></i></a>` : ""}
      </div>`;
    const note = (text, link, label) => `<div class="alert alert-primary d-flex gap-2 mb-0 mt-2"><i class="ri-information-line fs-16"></i><span>${R.esc(text)}${link && !compact ? ` <a href="${link}">${label}</a>` : ""}</span></div>`;
    const blocks = [];
    const p = f.people;
    if (p) {
      blocks.push(`<div class="mr-block"><h6 class="ev-sub">People <span class="soft-chip soft-primary">From Demographics${p.from ? ` · ${R.esc(p.from)}` : ""}</span></h6>${
        p.recorded
          ? `<div class="mr-figs">${tile("ri-group-line", "primary", "Members", R.num(p.members), p.members_change != null ? `${p.members_change >= 0 ? "+" : ""}${p.members_change} since the last` : "", recordLink("people"))}${
              p.this_month
                ? tile("ri-user-add-line", "success", "New members", R.num(p.new_members), "", null) + tile("ri-drop-line", "purple", "Baptisms", R.num(p.baptisms), "", null) + tile("ri-heart-line", "pink", "Conversions", R.num(p.conversions), "", null) + tile("ri-cup-line", "secondary", "Communion", R.num(p.communion), "", null)
                : ""
            }</div>${p.note ? note(p.note, recordLink("people"), "Record this month") : ""}`
          : note(p.note, recordLink("people"), "Record Demographics")
      }</div>`);
    }
    const a = f.attendance;
    if (a) {
      blocks.push(`<div class="mr-block"><h6 class="ev-sub">Attendance <span class="soft-chip soft-primary">From Attendance</span></h6>${
        a.recorded
          ? `<div class="mr-figs">${tile("ri-sun-line", "primary", "Average Sunday", R.num(a.average_sunday), `${a.sundays} ${a.sundays === 1 ? "Sunday" : "Sundays"} recorded`, recordLink("attendance"))}${tile("ri-arrow-up-line", "success", "Highest Sunday", R.num(a.highest_sunday), "", null)}${tile("ri-group-2-line", "purple", "Other gatherings", R.num(a.gatherings), `${R.num(a.gathering_attendance)} attended`, null)}</div>${a.note ? note(a.note, recordLink("attendance"), "Record attendance") : ""}`
          : note(a.note, recordLink("attendance"), "Record attendance")
      }</div>`);
    }
    const m = f.money;
    if (m) {
      blocks.push(`<div class="mr-block"><h6 class="ev-sub">Income and expenses <span class="soft-chip soft-primary">From Budgets${m.budget?.label ? ` · ${R.esc(m.budget.label)}` : ""}</span></h6>${
        m.recorded
          ? `<div class="mr-figs">${tile("ri-arrow-down-circle-line", "success", "Income", R.money(m.income), m.income_planned ? `of ${R.money(m.income_planned)} planned` : "", recordLink("money"))}${tile("ri-arrow-up-circle-line", "danger", "Expenses", R.money(m.expenses), m.expenses_planned ? `of ${R.money(m.expenses_planned)} planned` : "", null)}${tile("ri-scales-3-line", m.left < 0 ? "danger" : "primary", "Left", R.money(m.left), "", null)}${
              m.share ? tile("ri-hand-coin-line", m.share.status === "late" ? "danger" : "secondary", R.esc(m.share.name), R.money(m.share.sent), m.share.still_to_send > 0 ? `${R.money(m.share.still_to_send)} still to send` : `sent of ${R.money(m.share.due)}`, null) : ""
            }</div>`
          : note(m.note || "No budget or money recorded for this month.", recordLink("money"), "Open Budgets")
      }</div>`);
    }
    const c = f.churches;
    if (c) {
      blocks.push(`<div class="mr-block"><h6 class="ev-sub">Our churches <span class="soft-chip soft-primary">From their reports and Budgets</span></h6><div class="mr-figs">${tile("ri-file-chart-line", "primary", "Reports sent", `${c.sent} of ${c.churches}`, `${c.seen} read`, null)}${tile("ri-sun-line", "purple", "Sunday attendance", R.num(c.average_sunday), "All churches, average Sunday", null)}${tile("ri-arrow-down-circle-line", "success", "Income", R.money(c.income), "", null)}${tile("ri-arrow-up-circle-line", "danger", "Expenses", R.money(c.expenses), "", null)}</div></div>`);
    }

    return blocks.join("");
  }

  /** What happened, for reading: a timeline with date pills and a count chip. */
  function happenedTimeline(f) {
    const ev = f.events || { ours: [], took_part: [] };
    const items = [
      ...ev.ours.map((e) => ({ date: e.date, title: e.title, sub: `Our ${R.esc(e.type).toLowerCase()}`, n: e.came != null ? `${R.num(e.came)} came` : e.expected ? `${R.num(e.expected)} expected` : "" })),
      ...ev.took_part.map((e) => ({ date: e.date, title: e.title, sub: `With ${R.esc(e.organiser)}`, n: e.came != null ? `${R.num(e.came)} of ours came` : `${R.num(e.expected)} of ours` })),
    ].sort((a, b) => String(a.date).localeCompare(String(b.date)));
    const inits = (f.initiatives || []).map((i) => ({ date: null, title: i.title, sub: `${i.sessions} ${i.sessions === 1 ? "session" : "sessions"} held`, n: `${R.num(i.attendance)} attended` }));
    const all = [...items, ...inits];
    const extra = r.pastoral_visits != null ? `<div class="profile-fact profile-tint-primary mt-3"><span class="avatar avatar-sm avatar-rounded bg-primary text-white"><i class="ri-home-smile-line"></i></span><div class="flex-fill"><div class="profile-fact-label">Pastoral visits</div><div class="profile-fact-value">${R.num(r.pastoral_visits)}</div></div></div>` : "";
    return (
      (all.length
        ? `<ol class="ev-timeline">${all.map((x) => `<li><span class="ev-timeline-dot"></span><div class="flex-fill">${x.date ? `<span class="ev-timeline-when">${R.shortDate(x.date)}</span>` : `<span class="ev-timeline-when">Sessions</span>`}<span class="ev-timeline-what fw-semibold">${R.esc(x.title)}</span><div class="mr-tl-sub">${x.sub}${x.n ? ` <span class="soft-chip soft-primary ms-1">${x.n}</span>` : ""}</div></div></li>`).join("")}</ol>`
        : `<p class="fw-semibold mb-0">No events or initiative sessions this month.</p>`) +
      extra +
      (r.outreach ? `<div class="mr-quote mt-3" style="--q: var(--success-rgb)"><div class="mr-quote-head"><i class="ri-road-map-line"></i>Outreach</div><p>${R.esc(r.outreach)}</p></div>` : "")
    );
  }

  function happened(f, { editable = false } = {}) {
    const ev = f.events || { ours: [], took_part: [] };
    const rows = [
      ...ev.ours.map((e) => ({ icon: "ri-calendar-check-line", color: "success", title: e.title, sub: `${R.shortDate(e.date)} · our ${R.esc(e.type).toLowerCase()}`, n: e.came != null ? `${R.num(e.came)} came` : e.expected ? `${R.num(e.expected)} expected` : "" })),
      ...ev.took_part.map((e) => ({ icon: "ri-community-line", color: "purple", title: e.title, sub: `${R.shortDate(e.date)} · with ${R.esc(e.organiser)}`, n: e.came != null ? `${R.num(e.came)} of ours came` : `${R.num(e.expected)} of ours` })),
      ...(f.initiatives || []).map((i) => ({ icon: "ri-seedling-line", color: "primary", title: i.title, sub: `${i.sessions} ${i.sessions === 1 ? "session" : "sessions"} held`, n: `${R.num(i.attendance)} attended` })),
    ];
    const list = rows.length
      ? `<ul class="mr-list">${rows.map((x) => `<li><span class="avatar avatar-sm avatar-rounded bg-${x.color} text-white"><i class="${x.icon}"></i></span><div class="flex-fill" style="min-width:0"><strong>${R.esc(x.title)}</strong><small>${x.sub}</small></div><span class="fw-semibold">${x.n}</span></li>`).join("")}</ul>`
      : `<p class="fw-semibold mb-0">No events or initiative sessions this month.${editable ? " They show here as you add them in Events and Initiatives." : ""}</p>`;
    if (!editable) {
      const extra = [
        r.pastoral_visits != null ? `<li><span class="avatar avatar-sm avatar-rounded bg-pink text-white"><i class="ri-home-smile-line"></i></span><div class="flex-fill"><strong>Pastoral visits</strong></div><span class="fw-semibold">${R.num(r.pastoral_visits)}</span></li>` : "",
      ].join("");
      return list + (extra ? `<ul class="mr-list mt-2">${extra}</ul>` : "") + (r.outreach ? `<h6 class="ev-sub mt-3">Outreach</h6><p class="ev-text mb-0">${R.esc(r.outreach)}</p>` : "");
    }
    return `
      ${list}
      <div class="row g-3 mt-2">
        <div class="col-md-4">${UI.numberStepperHtml("f_pastoral_visits", { label: "Pastoral visits", min: 0, max: 10000, value: r.pastoral_visits ?? "" })}</div>
        <div class="col-12"><label class="form-label" for="f_outreach">Outreach</label><textarea class="form-control" id="f_outreach" rows="3" maxlength="5000" placeholder="Where you went, who you reached">${R.esc(r.outreach || "")}</textarea></div>
      </div>`;
  }

  // ================================================================ writing (our draft)
  function renderWrite() {
    $("writeView").hidden = false;
    $("readView").hidden = true;
    $("tipAbove").textContent = r.reports_to?.name || "the place above";
    $("stepBody1").innerHTML = (r.figures_live ? `<div class="alert alert-success d-flex gap-2"><i class="ri-refresh-line fs-16"></i><span>These are worked out from what is recorded right now. When you send, they are kept as they are.</span></div>` : "") + figureTiles(r.figures);
    $("stepBody2").innerHTML = happened(r.figures, { editable: true });
    $("stepBody3").innerHTML = `<div class="row g-3">${Object.entries(WORDS)
      .map(([k, [label, hint]]) => `<div class="col-md-6"><label class="form-label" for="f_${k}">${label}</label><textarea class="form-control" id="f_${k}" rows="4" maxlength="5000" placeholder="${hint}">${R.esc(r.words[k] || "")}</textarea></div>`)
      .join("")}</div>`;
    renderFiles();
    UI.initSteppers($("stepBody2"), () => queueSave());
    document.querySelectorAll("#writeView textarea, #writeView input").forEach((el) => el.addEventListener("input", queueSave));
    renderPreview();
    go(step);
  }

  function renderFiles() {
    const files = r.attachments || [];
    $("stepBody4").innerHTML = `
      <p class="mb-3">Add photos or PDFs (up to 5, 5 MB each) - optional.</p>
      <div class="mr-files" id="fileList">${files.map((f) => `<div class="mr-file"><span class="avatar avatar-sm avatar-rounded bg-${f.is_image ? "primary" : "danger"} text-white"><i class="${f.is_image ? "ri-image-line" : "ri-file-pdf-line"}"></i></span><span class="flex-fill text-truncate">${R.esc(f.name)}</span><button type="button" class="btn btn-sm btn-danger-light btn-icon" data-remove="${f.id}" aria-label="Remove ${R.esc(f.name)}"><i class="ri-delete-bin-line"></i></button></div>`).join("")}</div>
      ${files.length < 5 ? `<label class="mr-drop mt-2"><input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp,application/pdf" hidden><i class="ri-upload-cloud-2-line"></i><span><b>Choose a file</b> - a photo or a PDF</span></label>` : ""}
      <div class="alert alert-primary d-flex gap-2 mt-3 mb-0"><i class="ri-send-plane-line fs-16"></i><span>${r.open ? `When you send it, ${R.esc(r.reports_to?.name || "the place above")} is told and can read it. You can take it back until they mark it as seen.` : "You can send it once the month has started."}</span></div>`;
    $("fileInput")?.addEventListener("change", async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      await flushSave();
      if (!r.id) return Toast.error("Couldn't save the report. Please try again.");
      const res = await ReportsAPI.attach(r.id, file);
      if (!res.ok) return Toast.error(res.message);
      r.attachments = res.data.attachments;
      Toast.success(res.message);
      renderFiles();
      renderPreview();
    });
    $("stepBody4").querySelectorAll("[data-remove]").forEach((b) =>
      b.addEventListener("click", async () => {
        const res = await ReportsAPI.detach(r.id, b.dataset.remove);
        if (!res.ok) return Toast.error(res.message);
        r.attachments = res.data.attachments;
        renderFiles();
        renderPreview();
      }),
    );
  }

  function go(n) {
    step = n;
    document.querySelectorAll(".intake-step").forEach((s) => (s.hidden = Number(s.dataset.step) !== n));
    document.querySelectorAll(".intake-step-btn").forEach((b) => {
      const k = Number(b.dataset.go);
      b.classList.toggle("is-on", k === n);
      b.classList.toggle("is-done", k < n);
    });
    $("intakeStepsBar").style.width = `${(n / 4) * 100}%`;
    $("intakeStepsMobile").textContent = `Step ${n} of 4 · ${document.querySelector(`[data-go="${n}"] strong`).textContent}`;
    $("backBtn").hidden = n === 1;
    $("nextBtn").hidden = n === 4;
    $("sendBtn").hidden = n !== 4 || !r.can.send;
  }

  function body() {
    const out = {};
    Object.keys(WORDS).forEach((k) => (out[k] = $(`f_${k}`)?.value.trim() || null));
    out.outreach = $("f_outreach")?.value.trim() || null;
    const v = $("f_pastoral_visits")?.value;
    out.pastoral_visits = v === "" || v == null ? null : Number(v);
    return out;
  }

  function queueSave() {
    $("intakeSaved").textContent = "Saving...";
    $("intakeSaved").className = "intake-saved me-auto is-dirty";
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flushSave, 900);
    renderPreview();
  }

  async function flushSave() {
    clearTimeout(saveTimer);
    if (saving) await saving;
    saving = (async () => {
      const res = await ReportsAPI.save(r.year, r.month, body());
      if (!res.ok) {
        $("intakeSaved").textContent = "Not saved";
        Toast.error(res.message);
        return;
      }
      r.id = res.data.id;
      r.status = res.data.status;
      $("intakeSaved").textContent = "Saved";
      $("intakeSaved").className = "intake-saved me-auto is-saved";
    })();
    await saving;
    saving = null;
  }

  function renderPreview() {
    const f = r.figures;
    const b = $("writeView").hidden ? null : body();
    const filled = b ? Object.keys(WORDS).filter((k) => b[k]).length : 0;
    const line = (icon, text) => `<li><i class="${icon}"></i><span>${text}</span></li>`;
    $("previewStatus").innerHTML = R.statePill(r);
    $("previewBody").innerHTML = `
      <div class="fw-bold">${R.esc(r.place.name)} · ${R.esc(r.label)}</div>
      <ul class="ev-facts mt-2">
        ${f.attendance ? line("ri-sun-line", `Average Sunday: <b>${R.num(f.attendance.average_sunday)}</b>`) : ""}
        ${f.people?.recorded ? line("ri-group-line", `Members: <b>${R.num(f.people.members)}</b>`) : ""}
        ${f.money ? line("ri-arrow-down-circle-line", `Income: <b>${R.money(f.money.income)}</b> · Expenses: <b>${R.money(f.money.expenses)}</b>`) : ""}
        ${f.churches ? line("ri-file-chart-line", `Churches' reports: <b>${f.churches.sent} of ${f.churches.churches}</b>`) : ""}
        ${line("ri-calendar-check-line", `${(f.events?.ours.length || 0) + (f.events?.took_part.length || 0)} events · ${(f.initiatives || []).length} initiatives`)}
        ${line("ri-quill-pen-line", `${filled} of 6 boxes written`)}
        ${line("ri-attachment-2", `${(r.attachments || []).length} ${(r.attachments || []).length === 1 ? "file" : "files"}`)}
      </ul>
      <div class="mt-2"><span class="soft-chip soft-${r.late ? "danger" : "primary"}"><i class="ri-calendar-event-line"></i>Due ${R.longDate(r.due_on)}${r.late ? " · late" : ""}</span></div>`;
  }

  // ================================================================ reading
  async function renderRead() {
    $("writeView").hidden = true;
    $("readView").hidden = false;
    const words = Object.entries(WORDS).filter(([k]) => r.words[k]);
    $("readMain").innerHTML = `
      <div class="card custom-card"><div class="card-header"><div class="card-title">The figures</div>${r.figures_live ? `<span class="soft-chip soft-success ms-auto">Live - not sent yet</span>` : `<span class="soft-chip soft-primary ms-auto">As sent</span>`}</div><div class="card-body">${figureTiles(r.figures, { compact: r.relation !== "own" })}</div></div>
      <div class="card custom-card"><div class="card-header"><div class="card-title">What happened</div></div><div class="card-body">${happenedTimeline(r.figures)}</div></div>
      <div class="card custom-card"><div class="card-header"><div class="card-title">In the pastor's words</div></div><div class="card-body">${
        words.length
          ? `<div class="row g-3">${words.map(([k, [label, , icon, color]]) => `<div class="col-md-6"><div class="mr-quote h-100" style="--q: var(--${color}-rgb)"><div class="mr-quote-head"><i class="${icon}"></i>${label}</div><p>${R.esc(r.words[k])}</p></div></div>`).join("")}</div>`
          : `<p class="fw-semibold mb-0">Nothing was written.</p>`
      }</div></div>
      ${r.attachments.length ? `<div class="card custom-card"><div class="card-header"><div class="card-title">Photos and files</div></div><div class="card-body"><div class="mr-gallery" id="gallery">${r.attachments.map((a) => `<button type="button" class="mr-thumb" data-file="${a.id}" data-image="${a.is_image ? 1 : 0}" title="${R.esc(a.name)}">${a.is_image ? `<span class="skel" style="display:block;height:100%"></span>` : `<i class="ri-file-pdf-line"></i><small>${R.esc(a.name)}</small>`}</button>`).join("")}</div></div></div>` : ""}`;
    renderThread();
    // Images load through the API (they need the sign-in).
    for (const el of document.querySelectorAll("#gallery [data-image='1']")) {
      const src = await ReportsAPI.fileUrl(r.id, el.dataset.file);
      if (src) el.innerHTML = `<img src="${src}" alt="">`;
    }
    document.querySelectorAll("#gallery [data-file]").forEach((el) =>
      el.addEventListener("click", async () => {
        const src = await ReportsAPI.fileUrl(r.id, el.dataset.file);
        if (src) window.open(src, "_blank");
        else Toast.error("Couldn't open the file.");
      }),
    );
  }

  function renderThread() {
    const comments = r.thread || [];
    const initial = (n) => (n || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((x) => x[0].toUpperCase()).join("");
    $("readSide").innerHTML = `
      <div class="card custom-card mr-comments">
        <div class="card-header justify-content-between"><div class="card-title">Comments</div><span class="soft-chip soft-primary">${comments.length}</span></div>
        <div class="card-body">
          ${comments.length
            ? `<ul class="mi-thread">${comments.map((c) => `<li class="mi-thread-item${c.from_above ? "" : " is-mine"}"><span class="avatar avatar-sm avatar-rounded bg-primary text-white">${R.esc(initial(c.who))}</span><div class="mi-thread-body"><div class="mi-thread-meta"><strong>${R.esc(c.who)}</strong><span>${R.esc(c.place || "")}</span><span>${new Date(c.at).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" })}</span></div><div class="mi-thread-text"><p>${R.esc(c.body)}</p></div></div></li>`).join("")}</ul>`
            : `<p class="fw-semibold mb-0">${r.relation === "own" ? "No comments yet. Those above can comment once it's sent." : "No comments yet."}</p>`}
        </div>
        ${r.can.comment
          ? `<div class="mi-reply"><textarea class="form-control" id="commentBody" rows="3" maxlength="2000" aria-label="${r.relation === "own" ? "Reply" : "Comment"}" placeholder="${r.relation === "own" ? "Answer a question, add something..." : "Encourage, ask, follow up..."}"></textarea><div class="mi-reply-foot justify-content-end"><button class="btn btn-primary" id="commentBtn"><i class="ri-chat-3-line me-1"></i>Send comment</button></div></div>`
          : ""}
      </div>`;
    $("commentBtn")?.addEventListener("click", async () => {
      const text = $("commentBody").value.trim();
      if (!text) return Toast.warning("Write a comment first.");
      const btn = $("commentBtn");
      UI.setButtonLoading(btn, "Sending...");
      const res = await ReportsAPI.comment(r.id, text);
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      r = res.data;
      Toast.success(res.message);
      renderThread();
    });
  }

  // ================================================================ actions
  async function act(name) {
    if (name === "seen") {
      const res = await ReportsAPI.seen(r.id);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(`${res.message} ${R.esc(r.place.name)} is told.`);
      r = res.data;
      return render();
    }
    if (name === "reopen") {
      const ok = await R.ask({ title: "Take it back?", text: `It becomes a draft again, so you can change it, and the figures are worked out afresh. ${R.esc(r.reports_to?.name || "The place above")} will see it again when you send it.`, icon: "ri-arrow-go-back-line", action: "Take it back" });
      if (!ok) return;
      const res = await ReportsAPI.reopen(r.year, r.month);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      r = res.data;
      step = 1;
      return render();
    }
  }

  async function send() {
    await flushSave();
    const ok = await R.ask({ title: `Send ${r.label.split(" ")[0]}'s report?`, text: `It goes to <b>${R.esc(r.reports_to?.name || "the place above")}</b>, and the figures are kept as they are now. You can take it back until it has been seen.`, icon: "ri-send-plane-line", color: "success", action: "Send it", actionColor: "success" });
    if (!ok) return;
    const btn = $("sendBtn");
    UI.setButtonLoading(btn, "Sending...");
    const res = await ReportsAPI.send(r.year, r.month);
    UI.restoreButton(btn);
    if (!res.ok) return Toast.error(res.message);
    Toast.success(res.message);
    r = res.data;
    render();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // ================================================================ load
  function render() {
    renderHero();
    r.can.write ? renderWrite() : renderRead();
  }

  async function load() {
    const res = byId ? await ReportsAPI.get(byId) : await ReportsAPI.month(year, month);
    if (!res.ok) {
      $("reportPage").innerHTML = `<div class="card custom-card"><div class="card-body">${R.empty("ri-file-forbid-line", res.status === 404 ? "This report isn't one you can see" : "Couldn't open the report", R.esc(res.message), "danger")}<div class="text-center mt-3"><a class="btn btn-primary" href="${CTX.baseUrl}/">Back to monthly reports</a></div></div></div>`;
      return;
    }
    r = res.data;
    year = r.year;
    month = r.month;
    document.title = `${r.place.name} - ${r.label} report - Makueni West Diocese`;
    render();
  }

  // No month in the link (the menu's "Write this month's report"): the
  // month to write - the one due next, else the latest still open - as the
  // list's Write button picks it. Last year too, for December's in January.
  async function monthToWrite() {
    const now = new Date().getFullYear();
    for (const y of [now, now - 1]) {
      const res = await ReportsAPI.year(y);
      if (!res.ok) continue;
      const d = res.data;
      const target = d.figures?.next || d.months.filter((m) => m.open && ["draft", "not_started"].includes(m.status)).pop();
      if (target) return target;
    }
    return null;
  }

  document.addEventListener("DOMContentLoaded", async () => {
    if (!byId && (!year || !month)) {
      const target = await monthToWrite();
      if (!target) {
        window.location.href = `${CTX.baseUrl}/`;
        return;
      }
      year = target.year;
      month = target.month;
      history.replaceState(null, "", R.ownUrl(CTX.baseUrl, year, month));
    }
    $("nextBtn").addEventListener("click", () => go(Math.min(4, step + 1)));
    $("backBtn").addEventListener("click", () => go(Math.max(1, step - 1)));
    $("sendBtn").addEventListener("click", send);
    document.querySelectorAll(".intake-step-btn").forEach((b) => b.addEventListener("click", () => go(Number(b.dataset.go))));
    document.addEventListener("click", (e) => {
      const b = e.target.closest("[data-act]");
      if (b) act(b.dataset.act);
    });
    window.addEventListener("beforeunload", () => {
      if (saveTimer) flushSave();
    });
    load();
  });
})();
