/**
 * ============================================================================
 * FACILITIES - What we own (assets.php)
 * ============================================================================
 * The church's things as assets: four cards (what they cost, how much is
 * recorded in Budgets, how many have their receipt, what repairs cost), what
 * it cost by kind (a donut) and by the year it was bought, by room, the
 * things that cost the most, and those still missing a price, a receipt or a
 * photo. Export gives the asset register (PDF or Excel).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const K = PeopleKit;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  const short = (v) => (v >= 1000000 ? `KES ${(v / 1000000).toFixed(1)}m` : v >= 1000 ? `KES ${F.num(Math.round(v / 1000))}k` : F.money(v));
  const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
  const MISSING = { price: ["No price", "warning", "ri-money-dollar-circle-line"], receipt: ["No receipt", "pink", "ri-bill-line"], photo: ["No photo", "purple", "ri-image-line"] };

  function cards(a) {
    K.statRow($("statCardsRow"), [
      { icon: "ri-safe-2-line", label: "What our things cost", sub: `${F.num(a.items)} items · ${F.num(a.kinds)} kinds`, value: short(a.worth), color: "primary", bar: { pct: pct(a.priced, a.kinds), text: `${F.num(a.priced)} of ${F.num(a.kinds)} have a price` } },
      { icon: "ri-wallet-3-line", label: "Recorded in Budgets", sub: a.in_budgets_worth ? `${short(a.in_budgets_worth)} of it` : "Link each purchase on its page", value: `${F.num(a.in_budgets)}<small class="pp-stat-of"> of ${F.num(a.kinds)}</small>`, color: "success", bar: { pct: pct(a.in_budgets, a.kinds), text: `${pct(a.in_budgets, a.kinds)}% recorded` } },
      { icon: "ri-bill-line", label: "Receipt kept", sub: `${F.num(a.with_photo)} have a photo`, value: `${F.num(a.with_receipt)}<small class="pp-stat-of"> of ${F.num(a.kinds)}</small>`, color: "purple", bar: { pct: pct(a.with_receipt, a.kinds), text: `${pct(a.with_receipt, a.kinds)}% with a receipt` } },
      { icon: "ri-tools-line", label: "Spent on repairs", sub: a.worth ? `${pct(a.repairs_spent, a.worth)}% of what our things cost` : "Costs recorded on repairs", value: short(a.repairs_spent), color: "warning" },
    ]);
  }

  function byKind(a) {
    const list = a.by_kind.filter((k) => k.worth > 0);
    if (!list.length) return ($("byKind").innerHTML = '<p class="mb-0 fw-semibold">No prices recorded yet.</p>');
    // A colour each, in order - some kinds share a colour elsewhere, and the donut needs them apart.
    const PALETTE = ["primary", "warning", "purple", "success", "pink", "info", "danger", "secondary"];
    const colour = (k) => PALETTE[list.indexOf(k) % PALETTE.length];
    $("byKind").innerHTML = `<div class="fx-donut-row"><div id="kindChart"></div><ul class="fx-legend">${list
      .map((k) => `<li><i class="bg-${colour(k)}"></i><span class="flex-fill">${F.esc(k.label)}<small>${F.num(k.items)} ${k.items === 1 ? "item" : "items"}</small></span><strong>${short(k.worth)}</strong></li>`)
      .join("")}</ul></div>`;
    new ApexCharts($("kindChart"), {
      chart: { type: "donut", height: 240, fontFamily: "Inter, sans-serif" },
      series: list.map((k) => k.worth),
      labels: list.map((k) => k.label),
      colors: list.map((k) => UI.cssColor(colour(k))),
      legend: { show: false },
      dataLabels: { enabled: false },
      stroke: { width: 2 },
      plotOptions: { pie: { donut: { size: "70%", labels: { show: true, total: { show: true, label: "In all", formatter: () => short(a.worth) }, value: { formatter: (v) => short(Number(v)) } } } } },
      tooltip: { y: { formatter: (v) => F.money(v) } },
    }).render();
  }

  function byYear(a) {
    if (!a.by_year.length) return ($("byYear").innerHTML = '<p class="mb-0 fw-semibold">Add when things were bought, and their price, to see this.</p>');
    $("byYear").innerHTML = '<div id="yearChart"></div>';
    new ApexCharts($("yearChart"), {
      chart: { type: "bar", height: 330, toolbar: { show: false }, fontFamily: "Inter, sans-serif" },
      series: [{ name: "Spent", data: a.by_year.map((y) => y.spent) }],
      xaxis: { categories: a.by_year.map((y) => String(y.year)) },
      yaxis: { labels: { formatter: (v) => short(v) } },
      colors: [UI.cssColor("success")],
      plotOptions: { bar: { borderRadius: 6, columnWidth: "45%", dataLabels: { position: "top" } } },
      dataLabels: { enabled: true, formatter: (v) => short(v), offsetY: -20, style: { fontSize: "11px", fontWeight: 600, colors: ["#1d1d1f"] } },
      grid: { borderColor: "rgba(0,0,0,.06)" },
      tooltip: { y: { formatter: (v, o) => `${F.money(v)} · ${a.by_year[o.dataPointIndex].kinds} kinds of thing` } },
    }).render();
  }

  function byRoom(a) {
    const max = Math.max(1, ...a.by_room.map((r) => r.worth));
    $("byRoom").innerHTML = a.by_room.length
      ? `<ul class="fx-bars">${a.by_room
          .map((r) => `<li><div class="d-flex justify-content-between gap-2"><span class="fw-semibold text-truncate">${F.esc(r.label)}</span><strong>${short(r.worth)}</strong></div><div class="progress progress-sm"><div class="progress-bar bg-primary" style="width:${Math.max(2, Math.round((r.worth / max) * 100))}%"></div></div><small class="mb-sub">${F.num(r.items)} ${r.items === 1 ? "item" : "items"} · ${F.num(r.kinds)} ${r.kinds === 1 ? "kind" : "kinds"}</small></li>`)
          .join("")}</ul>`
      : '<p class="mb-0 fw-semibold">Nothing recorded yet.</p>';
  }

  function top(a) {
    $("top").innerHTML = a.top.length
      ? `<ul class="fx-top">${a.top
          .map(
            (i) => `<li><a href="${CTX.baseUrl}/item?id=${i.id}">${i.photo ? `<img class="fx-thumb" src="${F.esc(i.photo.thumb_url)}" alt="" loading="lazy">` : F.tile(i.icon, i.colour, "md")}
              <span class="flex-fill min-w-0"><strong>${F.esc(i.name)}</strong><small>${F.esc(i.asset_no || "")} · ${i.room ? F.esc(i.room.name) : "No room"}${i.quantity > 1 ? ` · ${F.num(i.quantity)} × ${F.money(i.value)}` : ""}</small></span>
              ${i.in_budgets ? '<span class="soft-chip soft-success d-none d-sm-inline-flex"><i class="ri-wallet-3-line"></i>In Budgets</span>' : ""}
              <strong class="text-nowrap">${F.money(i.total)}</strong></a></li>`,
          )
          .join("")}</ul>`
      : '<p class="mb-0 fw-semibold">No prices recorded yet.</p>';
  }

  function needs(a) {
    $("needsHead").innerHTML = a.needs.length ? `<span class="badge bg-warning text-dark">${a.needs.length} to finish</span>` : '<span class="badge bg-success">All done</span>';
    if (!a.needs.length) return ($("needs").innerHTML = '<p class="mb-0 fw-semibold"><i class="ri-checkbox-circle-line text-success me-1"></i>Every item has its price, receipt and photo.</p>');
    $("needs").innerHTML = `<div class="fx-needs">${a.needs
      .map((n) => {
        const c = a.categories.find((x) => x.key === n.category) || a.categories[a.categories.length - 1];
        return `<a class="fx-need" href="${CTX.baseUrl}/item?id=${n.id}">${F.tile(c.icon, c.color, "sm")}<span class="flex-fill min-w-0"><strong>${F.esc(n.name)}</strong><span class="d-flex flex-wrap gap-1 mt-1">${n.missing.map((m) => `<span class="soft-chip soft-${MISSING[m][1]}"><i class="${MISSING[m][2]}"></i>${MISSING[m][0]}</span>`).join("")}</span></span><i class="ri-arrow-right-s-line"></i></a>`;
      })
      .join("")}</div>`;
  }

  async function load() {
    const res = await FacilitiesAPI.assets();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      return;
    }
    const a = res.data;
    ["byKind", "byYear"].forEach((x) => ($(x).innerHTML = ""));
    cards(a);
    byKind(a);
    byYear(a);
    byRoom(a);
    top(a);
    needs(a);
  }

  function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("addBtn")?.addEventListener("click", () => F.itemWindow({ onDone: () => load() }));
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
