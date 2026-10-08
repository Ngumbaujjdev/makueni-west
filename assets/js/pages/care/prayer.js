/**
 * ============================================================================
 * PASTORAL CARE - prayer (prayer.php)
 * ============================================================================
 * Prayer requests as cards - open ones, and those answered this year with
 * their testimony (shown as offered to the monthly report when they agreed
 * it may be shared). Confidential requests show only as a lock to those
 * who may not read them.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const C = CareUI;
  const CTX = window.CARE_CTX;
  const $ = (id) => document.getElementById(id);
  let rows = [];
  let pill = new URLSearchParams(window.location.search).get("show") === "answered" ? "answered" : "open";

  function card(r) {
    const answered = r.status === "answered";
    return `<div class="col-xl-4 col-md-6"><div class="card custom-card cr-prayer${answered ? " is-answered" : ""}"><div class="card-body d-flex flex-column">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(r.who)} text-white">${C.esc(r.initials)}</span>
        <div class="min-w-0 flex-fill"><a class="fw-semibold mb-link" href="${CTX.baseUrl}/case?id=${r.id}">${C.esc(r.who)}</a><div class="mb-sub">${C.day(r.on)}${r.author ? ` · ${C.esc(r.author)}` : ""}</div></div>
        ${answered ? '<span class="badge bg-success">Answered</span>' : r.confidential ? '<i class="ri-lock-2-line text-muted" title="Confidential"></i>' : ""}
      </div>
      <div class="flex-fill">${r.note ? `<p class="cr-prayer-text">${C.esc(r.note)}</p>` : r.note_hidden ? '<p class="cr-note is-locked"><i class="ri-lock-2-line"></i>Confidential</p>' : '<p class="mb-sub">No words recorded.</p>'}</div>
      ${answered && r.testimony ? `<div class="cr-testimony"><i class="ri-chat-smile-2-line"></i><div><p class="mb-1">${C.esc(r.testimony)}</p>${r.share_testimony ? '<span class="soft-chip soft-success"><i class="ri-share-line"></i>Offered for the monthly report</span>' : ""}</div></div>` : ""}
      ${!answered && CTX.can.manage && !r.note_hidden ? `<div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-sm btn-success flex-fill" data-answered="${r.id}"><i class="ri-hand-heart-line me-1"></i>Answered</button><button type="button" class="btn btn-sm btn-outline-primary flex-fill" data-prayed="${r.id}"><i class="ri-add-line me-1"></i>We prayed</button></div>` : ""}
    </div></div></div>`;
  }

  function render() {
    const open = rows.filter((r) => r.status === "open");
    const answered = rows.filter((r) => r.status === "answered");
    $("prayerPills").innerHTML = `<div class="pp-pills">${[
      ["open", "Open", "ri-hand-heart-line", "pink", open.length],
      ["answered", "Answered this year", "ri-check-double-line", "success", answered.length],
    ]
      .map(([k, l, i, c, n]) => `<button type="button" class="pp-pill${pill === k ? " is-on" : ""}" style="--q: var(--${c}-rgb)" data-pill="${k}"><i class="${i}"></i>${l}<span class="pp-pill-count">${n}</span></button>`)
      .join("")}</div>`;
    const list = pill === "open" ? open : answered;
    $("prayerBody").innerHTML = list.length
      ? `<div class="row g-3">${list.map(card).join("")}</div>`
      : `<div class="card custom-card"><div class="card-body">${MembersUI.empty("ri-hand-heart-line", pill === "open" ? "No open prayer requests" : "None answered yet this year", pill === "open" ? "Record a prayer request, and pray for it together." : "When a prayer is answered, mark it - with the testimony.")}</div></div>`;
  }

  async function load() {
    const res = await CareAPI.prayer();
    if (!res.ok) return ($("prayerBody").innerHTML = MembersUI.errorBox(res.message));
    rows = res.data;
    render();
  }

  function init() {
    $("recordBtn")?.addEventListener("click", () => C.recordWindow({ type: "prayer", userId: CTX.userId, onDone: load }));
    $("prayerPills").addEventListener("click", (e) => {
      const b = e.target.closest("[data-pill]");
      if (!b) return;
      pill = b.dataset.pill;
      const q = new URLSearchParams(window.location.search);
      pill === "answered" ? q.set("show", "answered") : q.delete("show");
      history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
      render();
    });
    $("prayerBody").addEventListener("click", (e) => {
      const a = e.target.closest("[data-answered]");
      const p = e.target.closest("[data-prayed]");
      const r = rows.find((x) => x.id === Number(a?.dataset.answered || p?.dataset.prayed));
      if (a && r) C.closeWindow(r, { onDone: load });
      if (p && r) C.contactWindow(r, { type: "prayer", onDone: load });
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
