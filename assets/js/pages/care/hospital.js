/**
 * ============================================================================
 * PASTORAL CARE - in hospital (hospital.php)
 * ============================================================================
 * A card for each person in hospital now: the hospital, how long they've
 * been in, and when they were last visited - red once it's longer than the
 * church's setting. Visit now adds a visit; Home closes the stay.
 * ============================================================================
 */
(function () {
  "use strict";

  const C = CareUI;
  const CTX = window.CARE_CTX;
  const $ = (id) => document.getElementById(id);
  let days = 7;

  function card(r) {
    const since = r.days_since_visit;
    const ago = since === 0 ? "Visited today" : `${since} ${since === 1 ? "day" : "days"} ago`;
    // How far through the visit window they are - full and red once it's past.
    const pct = Math.min(100, Math.round((since / days) * 100));
    const left = days - since;
    return `<div class="col-xl-4 col-md-6"><div class="card custom-card cr-hosp${r.overdue ? " is-late" : ""}"><div class="card-body">
      <div class="d-flex align-items-start gap-3">
        <span class="avatar avatar-md avatar-rounded bg-danger text-white flex-shrink-0"><i class="ri-hospital-line"></i></span>
        <div class="min-w-0 flex-fill">
          <a class="fw-bold mb-link fs-15 d-block text-truncate" href="${CTX.baseUrl}/case?id=${r.id}">${C.esc(r.who)}</a>
          <span class="soft-chip soft-danger mt-1"><i class="ri-hospital-line"></i>${C.esc(r.hospital || "Hospital not given")}</span>
        </div>
        ${r.priority === "high" ? '<span class="badge bg-danger flex-shrink-0">Urgent</span>' : ""}
      </div>
      <div class="cr-hosp-boxes">
        <div class="cr-hosp-box" style="--q: var(--primary-rgb)"><span class="cr-hosp-box-icon"><i class="ri-calendar-2-line"></i></span><div class="min-w-0"><span>In since</span><strong>${C.day(r.on)}</strong><small>${r.in_days} ${r.in_days === 1 ? "day" : "days"} in</small></div></div>
        <div class="cr-hosp-box is-solid" style="--q: var(--${r.overdue ? "danger" : "success"}-rgb)"><span class="cr-hosp-box-icon"><i class="${r.overdue ? "ri-alarm-warning-line" : "ri-shield-check-line"}"></i></span><div class="min-w-0"><span>Last visit</span><strong>${ago}</strong><small>${r.overdue ? `Over ${days} days - go soon` : left === 0 ? "Due today" : `Due in ${left} ${left === 1 ? "day" : "days"}`}</small></div></div>
      </div>
      <div class="cr-hosp-target${r.overdue ? " is-late" : ""}" title="Visit every ${days} days"><span class="cr-hosp-target-bar"><i style="width:${Math.max(4, pct)}%"></i></span><small>Visit every ${days} days</small></div>
      ${CTX.can.manage && r.note !== undefined ? `<div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-primary flex-fill" data-visit="${r.id}"><i class="ri-home-heart-line me-1"></i>Visit now</button><button type="button" class="btn btn-outline-primary flex-fill" data-home="${r.id}"><i class="ri-home-4-line me-1"></i>Back home</button></div>` : ""}
    </div></div></div>`;
  }

  let rows = [];
  async function load() {
    const [res, o] = await Promise.all([CareAPI.hospital(), C.options()]);
    days = o.hospital_days || 7;
    if (!res.ok) return ($("hospitalBody").innerHTML = MembersUI.errorBox(res.message));
    rows = res.data;
    $("hospitalBody").innerHTML = rows.length
      ? `<div class="row g-3">${rows.map(card).join("")}</div>`
      : `<div class="card custom-card"><div class="card-body">${MembersUI.empty("ri-hospital-line", "Nobody in hospital", "When someone is admitted, record it and they show here until they're home.")}</div></div>`;
  }

  function init() {
    $("recordBtn")?.addEventListener("click", () => C.recordWindow({ type: "hospital", userId: CTX.userId, onDone: load }));
    $("hospitalBody").addEventListener("click", (e) => {
      const v = e.target.closest("[data-visit]");
      const h = e.target.closest("[data-home]");
      const r = rows.find((x) => x.id === Number((v || h)?.dataset.visit || (v || h)?.dataset.home));
      if (v && r) C.contactWindow(r, { type: "visit", onDone: load });
      if (h && r) C.dischargeWindow(r, { onDone: load });
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
