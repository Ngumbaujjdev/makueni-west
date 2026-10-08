/**
 * ============================================================================
 * MEMBERS - insights (insights.php)
 * ============================================================================
 * Sunday school and main church by gender, joining and leaving over the
 * year, the gender donut, where members live, and the register beside the
 * last Demographics count.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);

  function groups(i) {
    const el = $("groupChart");
    el.classList.remove("skel-chart");
    if (!i.groups.some((g) => g.male + g.female + g.unknown)) {
      el.innerHTML = M.empty("ri-bar-chart-horizontal-line", "No members yet", "Add members, and Sunday school and the main church show here.");
      return;
    }
    const series = [
      { name: "Men and boys", data: i.groups.map((g) => g.male) },
      { name: "Women and girls", data: i.groups.map((g) => g.female) },
    ];
    if (i.groups.some((g) => g.unknown)) series.push({ name: "Not given", data: i.groups.map((g) => g.unknown) });
    new ApexCharts(el, {
      chart: { type: "bar", height: 260, stacked: true, toolbar: { show: false }, fontFamily: "inherit" },
      plotOptions: { bar: { horizontal: true, barHeight: "55%", borderRadius: 4 } },
      colors: [UI.cssColor("primary"), UI.cssColor("pink"), UI.cssColor("secondary")],
      series,
      xaxis: { categories: i.groups.map((g) => g.label), labels: { formatter: (v) => (Number.isInteger(+v) ? v : "") } },
      dataLabels: { enabled: true, formatter: (v) => v || "" },
      legend: { position: "top" },
      grid: { borderColor: "rgba(var(--dark-rgb), .08)" },
    }).render();
  }

  function flow(o) {
    const el = $("flowChart");
    el.classList.remove("skel-chart");
    if (![...o.joins, ...o.leaves].some(Boolean)) {
      el.innerHTML = M.empty("ri-line-chart-line", "Nothing yet this year", "When members join or leave, the months show here.");
      return;
    }
    UI.renderTrendChart("flowChart", { categories: o.months, type: "bar", colors: [UI.cssColor("success"), UI.cssColor("secondary")], series: [{ name: "Joined", data: o.joins }, { name: "Left", data: o.leaves }] });
  }

  function gender(i) {
    const el = $("genderDonut");
    el.classList.remove("skel-chart");
    const g = i.gender;
    if (!g.male && !g.female && !g.unknown) {
      el.innerHTML = M.empty("ri-pie-chart-line", "No members yet", "Add members, and the split shows here.");
      return;
    }
    UI.renderRingDonut("genderDonut", { labels: ["Men", "Women", "Not given"], series: [g.male, g.female, g.unknown], colors: [UI.cssColor("primary"), UI.cssColor("pink"), UI.cssColor("secondary")], centerLabel: "Members" });
  }

  function areas(i) {
    if (!i.areas.length) {
      $("areaList").innerHTML = '<p class="mb-0 fw-semibold">No areas yet - add where members live on their page.</p>';
      return;
    }
    const top = Math.max(...i.areas.map((a) => a.count));
    $("areaList").innerHTML = `<div class="mb-areas">${i.areas
      .map((a) => `<div class="mb-area-row"><span class="mb-area-name">${M.esc(a.area)}</span><span class="mb-area-bar"><i style="width:${Math.max(4, Math.round((a.count / top) * 100))}%"></i></span><strong>${M.num(a.count)}</strong></div>`)
      .join("")}</div>`;
  }

  function registerVsDemo(i) {
    const r = i.register;
    const d = i.demographics;
    const rows = [
      ["Members", r.total, d?.total],
      ["Men", r.male, d?.male],
      ["Women", r.female, d?.female],
      ["Sunday school", r.sunday_school_male + r.sunday_school_female, d?.sunday_school],
    ];
    $("registerVsDemo").innerHTML = `
      <div class="mb-compare">
        <div class="mb-compare-head"><span></span><span>Register</span><span>Last count</span></div>
        ${rows.map(([k, a, b]) => `<div class="mb-compare-row"><span>${k}</span><strong>${M.num(a)}</strong><strong>${b == null ? "-" : M.num(b)}</strong></div>`).join("")}
      </div>
      <p class="mb-0 mt-2 mb-sub">${d ? `Demographics recorded ${M.day(d.recorded_at)}. The two don't have to match - Demographics counts everyone, the register only those you've added.` : "No Demographics count recorded yet."}</p>`;
  }

  async function init() {
    const [ins, ov] = await Promise.all([MembersAPI.insights(), MembersAPI.overview()]);
    if (!ins.ok || !ov.ok) {
      document.querySelector(".row.g-4").innerHTML = `<div class="col-12">${M.errorBox((ins.ok ? ov : ins).message)}</div>`;
      return;
    }
    groups(ins.data);
    flow(ov.data);
    gender(ins.data);
    areas(ins.data);
    registerVsDemo(ins.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
