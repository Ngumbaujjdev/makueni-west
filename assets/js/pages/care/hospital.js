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
    const ago = r.days_since_visit === 0 ? "Visited today" : `Last visited ${r.days_since_visit} ${r.days_since_visit === 1 ? "day" : "days"} ago`;
    return `<div class="col-xl-4 col-md-6"><div class="card custom-card cr-hosp${r.overdue ? " is-late" : ""}"><div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-3">
        ${C.typeTile("hospital", "md")}
        <div class="min-w-0 flex-fill"><a class="fw-bold mb-link fs-15" href="${CTX.baseUrl}/case?id=${r.id}">${C.esc(r.who)}</a><div class="mb-sub">${C.esc(r.hospital || "Hospital not given")}</div></div>
        ${r.priority === "high" ? '<span class="badge bg-danger">Urgent</span>' : ""}
      </div>
      <div class="cr-hosp-facts">
        <div><span>In since</span><strong>${C.day(r.on)}</strong><small>${r.in_days} ${r.in_days === 1 ? "day" : "days"}</small></div>
        <div class="${r.overdue ? "text-danger" : ""}"><span>Visit</span><strong>${ago}</strong><small>${r.overdue ? `More than ${days} days` : "On track"}</small></div>
      </div>
      ${CTX.can.manage && r.note !== undefined ? `<div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-primary btn-sm flex-fill" data-visit="${r.id}"><i class="ri-home-heart-line me-1"></i>Visit now</button><button type="button" class="btn btn-outline-primary btn-sm flex-fill" data-home="${r.id}"><i class="ri-check-line me-1"></i>Home</button></div>` : ""}
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
