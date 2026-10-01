/**
 * ============================================================================
 * PAGE - ONE AMOUNT OF MONEY IN OR OUT (includes/budget/entry.php, every level)
 * ============================================================================
 * The amount and what it was for, every detail recorded about it, what it
 * did to its line (before / this / after / left), its own History, and the
 * other money on the same line. Change and Delete (with Undo) for the place
 * itself; a level above sees it read-only.
 * ============================================================================
 */
const BudgetsEntry = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const id = Number(new URLSearchParams(window.location.search).get("id"));
  let d = null;

  async function init() {
    B.showFlash();
    if (!id) {
      showError("No entry was chosen.");
      return;
    }
    document.getElementById("changeBtn").addEventListener("click", () =>
      BudgetsEntryModal.open({ budgetId: d.budget.id, entry: d.entry, onSaved: load }),
    );
    document.getElementById("deleteBtn").addEventListener("click", remove);
    document.getElementById("restoreBtn").addEventListener("click", restore);
    await load();
  }

  async function load() {
    const res = await BudgetsAPI.entry(id);
    if (!res.ok) {
      showError(res.message);
      return;
    }
    d = res.data;
    render();
  }

  const day = (iso, opts = { weekday: "long", day: "numeric", month: "long", year: "numeric" }) => (iso ? new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString("en-GB", opts) : "-");
  const stamp = (iso) => (iso ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit", hour12: true }) : "-");

  function render() {
    const e = d.entry;
    const isIn = e.direction === "in";
    const color = isIn ? "success" : "danger";
    const place = d.budget.place?.name || "";
    document.title = `${e.description} - Makueni West Diocese`;
    document.getElementById("placeLine").textContent = `${place} · ${d.budget.period_label} budget`;
    document.getElementById("backBtn").href = B.url("budget.php", { id: d.budget.id, tab: "spending" });
    document.getElementById("backBtn").innerHTML = '<i class="ri-arrow-left-line me-1"></i>Back to the budget';
    document.getElementById("changeBtn").hidden = !d.can.change;
    document.getElementById("deleteBtn").hidden = !d.can.change;

    const banner = document.getElementById("viewOnlyBanner");
    banner.classList.toggle("d-none", !d.view_only);
    banner.classList.toggle("d-flex", !!d.view_only);
    if (d.view_only) document.getElementById("viewOnlyText").textContent = `This is ${place}'s money. Only ${place} can change it.`;
    const removed = document.getElementById("removedBanner");
    removed.classList.toggle("d-none", !e.deleted);
    removed.classList.toggle("d-flex", !!e.deleted);
    document.getElementById("restoreBtn").hidden = !(e.deleted && !d.view_only && d.budget.status === "active");

    // The amount, what for, when
    const lineColor = isIn ? "success" : "danger";
    document.querySelector("#entryHero .card-body").innerHTML = `
      <div class="budget-entry-hero-main">
        <span class="avatar avatar-lg bg-${lineColor} text-white flex-shrink-0"><i class="${B.lineIcon(d.line.name, d.line.side)}"></i></span>
        <div style="min-width: 0;">
          <div class="budget-entry-hero-kicker">${isIn ? "Money in" : "Money out"} · ${B.esc(d.line.name || "")}</div>
          <h3 class="budget-entry-hero-title">${B.esc(e.description)}</h3>
          <div class="d-flex flex-wrap gap-1 mt-2">
            <span class="soft-chip soft-primary"><i class="ri-calendar-line me-1"></i>${day(e.entry_date)}</span>
            ${B.methodChip(e.method)}
            ${d.line.is_unplanned ? '<span class="soft-chip soft-warning">Unplanned line</span>' : ""}
            ${e.deleted ? '<span class="badge bg-danger">Removed</span>' : B.statusPill(d.budget.status)}
          </div>
        </div>
      </div>
      <div class="budget-entry-hero-amount text-${color}${e.deleted ? " is-removed" : ""}">
        <small>KES</small>${isIn ? "+" : "−"}${B.amount(e.amount)}
      </div>`;

    renderDetails(e, isIn);
    renderEffect(e, isIn);
    renderHistory(e);
    renderOthers();
  }

  function renderDetails(e, isIn) {
    const rows = [
      ["What for", B.esc(e.description)],
      ["Line", `<a href="${B.url("line.php", { budget: d.budget.id, line: d.line.line_id })}" class="fw-semibold">${B.lineDot(d.line.name)}</a>`],
      ["Budget", `<a href="${B.url("budget.php", { id: d.budget.id })}">${B.esc(d.budget.period_label)} budget</a> ${B.statusPill(d.budget.status)}`],
      ["Date", day(e.entry_date)],
      [isIn ? "Received from" : "Paid to", B.esc(e.counterparty || "-")],
      ["How it was paid", B.methodChip(e.method)],
      ["Reference", e.reference ? `<span class="font-monospace">${B.esc(e.reference)}</span>` : "-"],
      ["Recorded by", `${B.esc(e.recorded_by || "-")} · ${stamp(e.recorded_at)}`],
      e.changed_at ? ["Last changed", `${B.esc(e.changed_by || e.recorded_by || "-")} · ${stamp(e.changed_at)}`] : null,
    ].filter(Boolean);
    document.getElementById("entryDetails").innerHTML = `<ul class="list-unstyled mb-0 budget-facts">${rows.map(([k, v]) => `<li><span>${k}</span><span class="fw-semibold text-end">${v}</span></li>`).join("")}</ul>`;
  }

  /** The line before this amount, the amount itself, and after - as figures and as one bar. */
  function renderEffect(e, isIn) {
    const l = d.line;
    const amount = e.deleted ? 0 : e.amount;
    const after = l.before + amount;
    const planned = l.planned;
    const scale = Math.max(planned, after, 1);
    const color = isIn ? "success" : after > planned && planned > 0 ? "danger" : "primary";
    const left = planned - after;
    document.getElementById("lineLink").href = B.url("line.php", { budget: d.budget.id, line: l.line_id });
    document.getElementById("effectSub").textContent = `${l.name} · ${isIn ? "received" : "spent"} before and after this amount`;
    const fig = (label, value, cls = "") => `<div><small>${label}</small><b class="${cls}">${value}</b></div>`;
    document.getElementById("entryEffect").innerHTML = `
      <div class="budget-items-strip soft-${isIn ? "success" : "danger"} mx-n3 mt-n3 mb-3 budget-effect-strip">
        ${fig("Planned", B.money(planned))}
        ${fig(isIn ? "Received before" : "Spent before", B.money(l.before))}
        ${fig("This amount", `${isIn ? "+" : "−"}${B.money(amount)}`, `text-${isIn ? "success" : "danger"}`)}
        ${fig(isIn ? "Still to come" : left < 0 ? "Over by" : "Left", B.money(Math.abs(isIn ? Math.max(left, 0) : left)), !isIn && left < 0 ? "text-danger" : "")}
      </div>
      <div class="budget-effect-bar" aria-hidden="true">
        <span class="is-before" style="width: ${(Math.min(l.before, scale) / scale) * 100}%"></span>
        <span class="bg-${color}" style="width: ${(Math.min(amount, scale - Math.min(l.before, scale)) / scale) * 100}%"></span>
      </div>
      <div class="d-flex flex-wrap justify-content-between gap-2 fs-12 mt-2">
        <span><span class="budget-effect-key is-before"></span>Before this</span>
        <span><span class="budget-effect-key bg-${color}"></span>This amount</span>
        <span>${planned > 0 ? `${Math.round((after / planned) * 100)}% of the plan after this` : "Nothing was planned on this line"}</span>
      </div>
      ${!isIn && planned > 0 && after > planned ? `<div class="budget-items-callout mx-0 mt-3"><i class="ri-alarm-warning-line text-danger"></i><span>After this the line is <b class="text-danger">${B.money(after - planned)} over plan</b>.</span></div>` : ""}`;
  }

  function renderHistory(e) {
    const el = document.getElementById("entryHistory");
    // Older entries were recorded before History named its entry: show what the entry itself knows.
    const items = d.history.length
      ? d.history.map((h) => ({ ...h, entry_id: null }))
      : [
          ...(e.changed_at ? [{ action: "entry_changed", description: "Changed this entry", who: e.changed_by || e.recorded_by, when: e.changed_at }] : []),
          { action: "entry_recorded", description: `Recorded ${B.money(e.amount)} ${e.direction === "in" ? "received" : "spent"} on ${d.line.name}`, who: e.recorded_by, when: e.recorded_at },
        ];
    el.innerHTML = B.timeline(items);
  }

  function renderOthers() {
    const el = document.getElementById("entryOthers");
    const lineUrl = B.url("line.php", { budget: d.budget.id, line: d.line.line_id });
    document.getElementById("othersSub").textContent = d.others_count ? `${d.others_count} more on ${d.line.name}` : `Nothing else on ${d.line.name} yet`;
    if (!d.others.length) {
      el.innerHTML = `<div class="list-empty py-3"><span class="list-empty-icon bg-primary text-white"><i class="ri-exchange-dollar-line"></i></span><div class="fw-semibold mt-2">This is the only amount on this line</div></div>`;
      return;
    }
    el.innerHTML = `
      <ul class="budget-recent">${d.others
        .map((o) => {
          const date = new Date(`${o.entry_date}T00:00:00`);
          const isIn = o.direction === "in";
          return `
            <li class="is-clickable" data-href="${B.url("entry.php", { id: o.id })}">
              <span class="budget-recent-date is-${isIn ? "in" : "out"}"><b>${date.getDate()}</b><small>${date.toLocaleDateString("en-GB", { month: "short" })}</small></span>
              <span class="flex-fill" style="min-width: 0;">
                <span class="d-block fw-semibold text-truncate">${B.esc(o.description)}</span>
                <span class="d-block fs-12 text-truncate">${B.esc(o.recorded_by || "")}${o.counterparty ? ` · ${B.esc(o.counterparty)}` : ""}</span>
              </span>
              <span class="fw-bold ${isIn ? "text-success" : "text-danger"}">${isIn ? "+" : "−"}${B.amount(o.amount)}</span>
            </li>`;
        })
        .join("")}</ul>
      <a href="${lineUrl}" class="btn btn-sm btn-outline-primary w-100 mt-2">See everything on ${B.esc(d.line.name)}<i class="ri-arrow-right-line ms-1"></i></a>`;
    el.querySelectorAll("[data-href]").forEach((li) => li.addEventListener("click", () => (window.location.href = li.dataset.href)));
  }

  function remove() {
    const e = d.entry;
    Toast.confirm(
      `Delete ${B.money(e.amount)} - ${e.description}?`,
      async () => {
        const res = await BudgetsAPI.removeEntry(id);
        if (!res.ok) {
          Toast.error(res.message);
          return;
        }
        await load();
        Toast.success("Entry deleted", { action: { label: "Undo", onClick: restore } });
      },
      null,
      { title: "Delete entry", confirmText: "Delete", type: "error" },
    );
  }

  async function restore() {
    const res = await BudgetsAPI.restoreEntry(id);
    res.ok ? Toast.success("Entry brought back") : Toast.error(res.message);
    await load();
  }

  function showError(message) {
    document.getElementById("placeLine").textContent = "";
    document.querySelector("#entryHero .card-body").innerHTML = `<div class="list-empty py-4"><span class="list-empty-icon bg-danger text-white"><i class="ri-error-warning-line"></i></span><div class="fw-semibold mt-2">${B.esc(message)}</div></div>`;
    ["entryDetails", "entryEffect", "entryHistory", "entryOthers"].forEach((elId) => (document.getElementById(elId).innerHTML = ""));
  }

  return { init };
})();

window.BudgetsEntry = BudgetsEntry;
