/**
 * ============================================================================
 * MONTHLY REPORTS - shared look (the list and the report page)
 * ============================================================================
 * A month's state in words and colour (Sent, Seen, Started, Not started,
 * Late), money and dates, and a small text/confirm window.
 * ============================================================================
 */
const ReportsUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");
  const num = (v) => (v == null ? "-" : Number(v).toLocaleString());
  const money = (v) => (v == null ? "-" : `KES ${Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })}`);
  const day = (iso) => new Date(`${String(iso).slice(0, 10)}T12:00:00`);
  const shortDate = (iso) => (iso ? day(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "-");
  const longDate = (iso) => (iso ? day(iso).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", year: "numeric" }) : "-");

  /** A month's state: { key, label, color, icon } - late wins over draft and not started. */
  function stateOf(m) {
    if (m.status === "seen") return { key: "seen", label: "Seen", color: "purple", icon: "ri-eye-line" };
    if (m.status === "sent") return { key: "sent", label: m.on_time === false ? "Sent late" : "Sent", color: "success", icon: "ri-send-plane-line" };
    if (m.status === "not_tracked") return { key: "future", label: "Before reports", color: "light", icon: "ri-subtract-line" };
    if (m.late) return { key: "late", label: m.status === "draft" ? "Late · started" : "Late", color: "danger", icon: "ri-alarm-warning-line" };
    if (!m.open) return { key: "future", label: "Not yet", color: "light", icon: "ri-time-line" };
    if (m.status === "draft") return { key: "draft", label: "Started", color: "secondary", icon: "ri-draft-line" };
    return { key: "not_started", label: "Not started", color: "primary", icon: "ri-file-line" };
  }

  function statePill(m) {
    const s = stateOf(m);
    return s.key === "future" ? `<span class="soft-chip soft-primary">${s.label}</span>` : UI.pill(s.label, s.color, s.icon);
  }

  /** Table order: waiting to be read first, then late, started, not started, seen. */
  function rank(m) {
    const s = stateOf(m).key;
    return { sent: 0, late: 1, draft: 2, not_started: 3, seen: 4, future: 5 }[s] ?? 6;
  }

  /** Where the report page is for our own month, or for one below. */
  const ownUrl = (base, y, m) => `${base}/report?year=${y}&month=${m}`;
  const idUrl = (base, id) => `${base}/report?id=${id}`;

  function ask({ title, text, icon = "ri-question-line", color = "primary", action = "OK", actionColor = "primary" }) {
    document.getElementById("mrAskModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="mrAskModal" tabindex="-1" aria-labelledby="mrAskTitle">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <div class="modal-header">
            <span class="app-modal-icon bg-${color} ${textOn(color)}"><i class="${icon}"></i></span>
            <div class="flex-fill"><h5 class="modal-title" id="mrAskTitle">${esc(title)}</h5></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body"><p class="mb-0">${text}</p></div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Not now</button><button type="button" class="btn btn-${actionColor}" id="mrAskGo">${action}</button></div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById("mrAskModal");
    const modal = new bootstrap.Modal(el);
    return new Promise((resolve) => {
      let answer = false;
      el.querySelector("#mrAskGo").addEventListener("click", () => {
        answer = true;
        modal.hide();
      });
      el.addEventListener("hidden.bs.modal", () => {
        el.remove();
        resolve(answer);
      });
      modal.show();
    });
  }

  function empty(icon, title, text, color = "primary") {
    return `<div class="mr-empty"><span class="avatar avatar-lg avatar-rounded bg-${color} ${textOn(color)} mb-2"><i class="${icon} fs-20"></i></span><h6 class="mb-1">${title}</h6><p class="mb-0">${text}</p></div>`;
  }

  return { esc, textOn, num, money, day, shortDate, longDate, stateOf, statePill, rank, ownUrl, idUrl, ask, empty };
})();
