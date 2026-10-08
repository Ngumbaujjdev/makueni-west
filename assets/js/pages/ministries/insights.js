/**
 * ============================================================================
 * MINISTRIES - Insights (insights.php)
 * ============================================================================
 * Who serves where: the cards (serving, in two or more, not in one yet),
 * members per ministry by men and women, each ministry's people and its
 * gatherings over six months, and the members not in a ministry yet - to
 * invite in (names stay in the church).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const N = MinistriesUI;
  const M = MembersUI;
  const CTX = window.MIN_CTX;
  const $ = (id) => document.getElementById(id);

  function render(d) {
    const pct = (a) => (d.members_total ? Math.round((a / d.members_total) * 100) : 0);
    PeopleKit.statRow($("statCardsRow"), [
      { icon: "ri-group-line", label: "Serving", sub: `Of ${N.num(d.members_total)} members`, value: N.num(d.serving), color: "success", bar: { pct: pct(d.serving), text: `${pct(d.serving)}% serve` } },
      { icon: "ri-git-merge-line", label: "In two or more", sub: "Serving in more than one", value: N.num(d.in_two_or_more), color: "purple" },
      { icon: "ri-user-add-line", label: "Not in one yet", sub: "Members to invite in", value: N.num(d.not_serving), color: "warning" },
      { icon: "ri-team-line", label: "Ministries", sub: `${N.num(d.ministries.filter((m) => m.linked).length)} linked to a gathering`, value: N.num(d.ministries.length), color: "primary" },
    ]);

    const el = $("perMinistry");
    el.classList.remove("skel-chart");
    el.innerHTML = "";
    if (!d.ministries.some((m) => m.members)) {
      el.innerHTML = M.empty("ri-bar-chart-horizontal-line", "Nobody in a ministry yet", "Add members to a ministry and this fills in.");
    } else {
      new ApexCharts(el, {
        chart: { type: "bar", height: Math.max(260, d.ministries.length * 44), stacked: true, toolbar: { show: false }, fontFamily: "inherit" },
        plotOptions: { bar: { horizontal: true, barHeight: "55%", borderRadius: 3 } },
        series: [
          { name: "Women", data: d.ministries.map((m) => m.female) },
          { name: "Men", data: d.ministries.map((m) => m.male) },
          { name: "Not given", data: d.ministries.map((m) => Math.max(0, m.members - m.male - m.female)) },
        ],
        colors: [UI.cssColor("pink"), UI.cssColor("info"), UI.cssColor("secondary")],
        xaxis: { categories: d.ministries.map((m) => m.name), labels: { formatter: (v) => Math.round(v) } },
        dataLabels: { enabled: false },
        legend: { position: "bottom" },
        grid: { borderColor: "rgba(var(--dark-rgb), .06)" },
      }).render();
    }

    $("mnRows").innerHTML = d.ministries.length
      ? d.ministries
          .map(
            (m) => `<tr>
          <td><a class="d-flex align-items-center gap-2 mb-link fw-semibold" href="${CTX.baseUrl}/ministry?id=${m.id}">${N.tile(m, "sm")}${N.esc(m.name)}</a></td>
          <td class="fw-semibold">${N.num(m.members)}</td>
          <td>${N.num(m.male)} · ${N.num(m.female)}</td>
          <td>${N.num(m.sunday_school)}</td>
          <td>${m.linked ? N.num(m.gatherings) : '<span class="mb-sub">Not linked</span>'}</td>
          <td>${m.average === null ? '<span class="mb-sub">-</span>' : N.num(m.average)}</td>
        </tr>`,
          )
          .join("")
      : `<tr><td colspan="6"><p class="mb-0 p-2 fw-semibold">No ministries running.</p></td></tr>`;

    $("noneHead").innerHTML = d.not_serving ? `<span class="soft-chip soft-warning"><i class="ri-user-add-line"></i>${N.num(d.not_serving)}</span>` : "";
    $("noneBody").innerHTML = d.not_serving_people.length
      ? `<ul class="mb-mini-list">${d.not_serving_people
          .map((p) => `<li>${M.avatar(p, "sm")}<div class="flex-fill min-w-0">${CTX.can.members ? `<a class="fw-semibold mb-link" href="${CTX.membersUrl}/member?id=${p.id}">${N.esc(p.name)}</a>` : `<strong>${N.esc(p.name)}</strong>`}<small>${N.esc([p.congregation === "sunday_school" ? "Sunday school" : "Main church", p.area].filter(Boolean).join(" · "))}</small></div></li>`)
          .join("")}</ul>${d.not_serving > d.not_serving_people.length ? `<p class="mb-0 mt-2 mb-sub">And ${N.num(d.not_serving - d.not_serving_people.length)} more - filter the members list by "Not in a ministry".</p>` : ""}`
      : '<p class="mb-0 fw-semibold">Every member serves somewhere.</p>';
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    const res = await MinistriesAPI.insights();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      return;
    }
    render(res.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
