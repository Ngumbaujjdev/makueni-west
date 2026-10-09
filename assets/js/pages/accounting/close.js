/**
 * ============================================================================
 * ACCOUNTING - Month-end close (close.php)
 * ============================================================================
 * Each month of the year with its checklist - every money account counted or
 * reconciled in it - what blocks closing and what to look at, and Close. A
 * region or the diocese, looking at a place below, gets Reopen (with a
 * reason): the one change it may make in a place below.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const API = AccountingAPI;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const thisYear = new Date().getFullYear();
  let year = Number(new URLSearchParams(window.location.search).get("year")) || thisYear;

  function render(d) {
    const months = d.months.filter((m) => !m.before_books).reverse();
    const closed = months.filter((m) => m.status === "closed").length;
    const ready = months.filter((m) => m.status === "open" && m.ended && !m.blockers.length).length;
    const blocked = months.filter((m) => m.status === "open" && m.ended && m.blockers.length).length;
    const last = d.months.filter((m) => m.status === "closed").pop();
    K.statRow($("statCardsRow"), [
      { icon: "ri-lock-line", label: "Closed", sub: last ? `Last: ${last.label}` : "None yet", value: A.num(closed), color: "success" },
      { icon: "ri-checkbox-circle-line", label: "Ready to close", sub: "Everything proven", value: A.num(ready), color: "primary" },
      { icon: "ri-error-warning-line", label: "Not ready", sub: "Something still to do", value: A.num(blocked), color: "danger" },
      { icon: "ri-calendar-line", label: "Months with books", sub: String(year), value: A.num(months.length), color: "purple" },
    ]);
    if (!months.length) {
      $("monthList").innerHTML = `<div class="card custom-card"><div class="card-body">${A.empty("ri-calendar-line", "Nothing to close", `No money was recorded in ${year}.`)}</div></div>`;
      return;
    }
    const own = !A.viewingBelow();
    $("monthList").innerHTML = months
      .map((m) => {
        const pill = m.status === "closed" ? '<span class="badge bg-success"><i class="ri-lock-line me-1"></i>Closed</span>' : !m.ended ? '<span class="badge bg-primary">This month</span>' : m.blockers.length ? '<span class="badge bg-danger">Not ready</span>' : '<span class="badge bg-warning text-dark">Ready to close</span>';
        const checks = m.checks.length
          ? `<div class="acc-checks">${m.checks.map((c) => `<span class="acc-check${c.ok ? " is-ok" : ""}">${A.tile(c.kind, "xs")}<span><strong>${esc(c.account)}</strong><small>${c.ok ? `${esc(c.what)} ${A.day(c.when, { day: "numeric", month: "short" })}` : esc(c.what === "Cash counted" ? "Not counted" : "Not reconciled")}</small></span><i class="${c.ok ? "ri-check-line" : "ri-close-line"}"></i></span>`).join("")}</div>`
          : '<p class="acc-muted-line mb-0">No money account moved or held money.</p>';
        const lists = m.status === "closed" ? "" : `${m.blockers.length ? `<ul class="acc-blockers">${m.blockers.map((b) => `<li><i class="ri-error-warning-line"></i>${esc(b)}</li>`).join("")}</ul>` : ""}${m.warnings.length ? `<ul class="acc-warnings">${m.warnings.map((b) => `<li><i class="ri-information-line"></i>${esc(b)}</li>`).join("")}</ul>` : ""}`;
        const canClose = own && d.can.close && m.status === "open" && m.ended && !m.blockers.length;
        const btn =
          m.status === "closed"
            ? d.can.reopen ? `<button type="button" class="btn btn-sm btn-outline-danger" data-reopen="${m.month}"><i class="ri-lock-unlock-line me-1"></i>Reopen</button>` : ""
            : own && d.can.close && m.ended ? `<button type="button" class="btn btn-sm btn-primary" data-close="${m.month}"${canClose ? "" : " disabled"}><i class="ri-lock-line me-1"></i>Close ${esc(m.label.split(" ")[0])}</button>` : "";
        const meta = m.status === "closed" ? `Closed ${m.closed_by ? `by ${esc(m.closed_by)} ` : ""}${A.day(m.closed_at)}` : m.reopened_at ? `Reopened ${A.day(m.reopened_at)}: ${esc(m.reopen_reason || "")}` : "";
        return `<div class="card custom-card acc-month"><div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2"><div class="d-flex align-items-center gap-2"><span class="fs-15 fw-semibold">${esc(m.label)}</span>${pill}</div><div class="d-flex align-items-center gap-2"><span class="acc-sub">${meta}</span>${btn}</div></div>
          ${checks}${lists}
        </div></div>`;
      })
      .join("");
  }

  async function load() {
    A.ownOnly();
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    const res = await API.periods(year);
    if (!res.ok) {
      $("monthList").innerHTML = A.errorBox(res.message);
      return;
    }
    A.placeLine($("accPlaceLine"), res.data.place);
    render(res.data);
  }

  function init() {
    $("yearPick").innerHTML = [0, 1, 2, 3].map((k) => `<option value="${thisYear - k}"${thisYear - k === year ? " selected" : ""} data-icon="ri-calendar-line" data-color="primary">${thisYear - k}</option>`).join("");
    UI.enhanceSelect($("yearPick"), { search: false });
    $("yearPick").addEventListener("change", () => {
      year = Number($("yearPick").value);
      const p = new URLSearchParams(window.location.search);
      year === thisYear ? p.delete("year") : p.set("year", year);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      load();
    });
    $("monthList").addEventListener("click", async (e) => {
      const c = e.target.closest("[data-close]");
      if (c) {
        if (!confirm("Close this month? Nothing more can be posted into it - only the level above can reopen it.")) return;
        UI.setButtonLoading(c, "Closing...");
        const res = await API.closeMonth(year, Number(c.dataset.close));
        UI.restoreButton(c);
        return res.ok ? (Toast.success(res.message), load()) : Toast.error(res.message);
      }
      const r = e.target.closest("[data-reopen]");
      if (r) W.reasonWindow({ title: "Reopen the month", subtitle: "Postings can be made into it again until it is closed", go: "Reopen", placeholder: "e.g. A receipt was missed", run: (reason) => API.reopenMonth(year, Number(r.dataset.reopen), reason), onDone: load });
    });
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
