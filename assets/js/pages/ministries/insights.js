/**
 * ============================================================================
 * MINISTRIES - Insights (insights.php)
 * ============================================================================
 * Who serves where: the cards (serving, in two or more, not in one yet,
 * ministries), members per ministry by women and men beside the members not
 * in a ministry yet (one height - the list scrolls), and a row per ministry -
 * its share of the members, the women and men in it, Sunday school, six
 * months of attendance and its average against the three months before.
 * Names stay in the church.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const N = MinistriesUI;
  const M = MembersUI;
  const CTX = window.MIN_CTX;
  const $ = (id) => document.getElementById(id);

  function cards(d) {
    const pct = (a) => (d.members_total ? Math.round((a / d.members_total) * 100) : 0);
    PeopleKit.statRow($("statCardsRow"), [
      { icon: "ri-group-line", label: "Serving", sub: `Of ${N.num(d.members_total)} members`, value: N.num(d.serving), color: "success", bar: { pct: pct(d.serving), text: `${pct(d.serving)}% serve` } },
      { icon: "ri-git-merge-line", label: "In two or more", sub: "Serving in more than one", value: N.num(d.in_two_or_more), color: "purple", bar: { pct: d.serving ? Math.round((d.in_two_or_more / d.serving) * 100) : 0, text: "of those serving" } },
      { icon: "ri-user-add-line", label: "Not in one yet", sub: "Members to invite in", value: N.num(d.not_serving), color: "warning", bar: { pct: pct(d.not_serving), text: `${pct(d.not_serving)}% of members` } },
      { icon: "ri-team-line", label: "Ministries", sub: `${N.num(d.ministries.filter((m) => m.linked).length)} linked to a gathering`, value: N.num(d.ministries.length), color: "primary", bar: { pct: d.ministries.length ? Math.round((d.ministries.filter((m) => m.linked).length / d.ministries.length) * 100) : 0, text: "linked" } },
    ]);
  }

  function chart(d) {
    const el = $("perMinistry");
    el.classList.remove("skel-chart");
    el.innerHTML = "";
    $("chartHead").innerHTML = `<span class="soft-chip soft-pink"><i class="ri-women-line"></i>${N.num(d.ministries.reduce((a, m) => a + m.female, 0))}</span> <span class="soft-chip soft-info"><i class="ri-men-line"></i>${N.num(d.ministries.reduce((a, m) => a + m.male, 0))}</span>`;
    if (!d.ministries.some((m) => m.members)) {
      el.innerHTML = M.empty("ri-bar-chart-horizontal-line", "Nobody in a ministry yet", "Add members to a ministry and this fills in.");
      return;
    }
    new ApexCharts(el, {
      chart: { type: "bar", height: Math.max(300, d.ministries.length * 48), stacked: true, toolbar: { show: false }, fontFamily: "inherit" },
      plotOptions: { bar: { horizontal: true, barHeight: "58%", borderRadius: 4, borderRadiusApplication: "end", borderRadiusWhenStacked: "last" } },
      series: [
        { name: "Women", data: d.ministries.map((m) => m.female) },
        { name: "Men", data: d.ministries.map((m) => m.male) },
        { name: "Not given", data: d.ministries.map((m) => Math.max(0, m.members - m.male - m.female)) },
      ].filter((s) => s.data.some(Boolean)),
      colors: [UI.cssColor("pink"), UI.cssColor("info"), UI.cssColor("warning")],
      xaxis: { categories: d.ministries.map((m) => m.name), labels: { formatter: (v) => Math.round(v) } },
      yaxis: { labels: { maxWidth: 220, style: { fontSize: "12px", fontWeight: 600 } } },
      dataLabels: { enabled: true, style: { fontSize: "11px", fontWeight: 700 }, formatter: (v) => (v ? v : "") },
      legend: { position: "top", horizontalAlign: "right" },
      grid: { borderColor: "rgba(var(--dark-rgb), .06)" },
    }).render();
  }

  function none(d) {
    // How many serve, as a ring - then who doesn't yet.
    const pct = d.members_total ? Math.round((d.serving / d.members_total) * 100) : 0;
    $("noneRing").innerHTML = "";
    new ApexCharts($("noneRing"), {
      chart: { type: "radialBar", height: 190, sparkline: { enabled: true }, fontFamily: "inherit" },
      series: [pct],
      colors: [UI.cssColor("success")],
      plotOptions: { radialBar: { hollow: { size: "62%" }, track: { background: "rgba(var(--success-rgb), .12)" }, dataLabels: { name: { show: true, offsetY: 20, fontSize: "12px", color: UI.cssColor("dark") }, value: { offsetY: -12, fontSize: "26px", fontWeight: 800, formatter: (v) => `${Math.round(v)}%` } } } },
      labels: [`${N.num(d.serving)} of ${N.num(d.members_total)} serve`],
    }).render();
    $("noneHead").innerHTML = d.not_serving ? `<span class="badge bg-warning text-dark">${N.num(d.not_serving)}</span>` : "";
    if (!d.not_serving_people.length) {
      $("noneBody").innerHTML = `<div class="mn-all-serve"><span class="avatar avatar-lg avatar-rounded bg-success text-white"><i class="ri-checkbox-circle-line fs-20"></i></span><strong>Every member serves somewhere</strong><small>New members show here until they join a ministry.</small></div>`;
      return;
    }
    $("noneBody").innerHTML = `<ul class="mb-mini-list mn-none-list">${d.not_serving_people
      .map(
        (p) => `<li>${M.avatar(p, "sm")}<div class="flex-fill min-w-0">${CTX.can.members ? `<a class="fw-semibold mb-link" href="${CTX.membersUrl}/member?id=${p.id}">${N.esc(p.name)}</a>` : `<strong>${N.esc(p.name)}</strong>`}<small>${N.esc([p.congregation === "sunday_school" ? "Sunday school" : "Main church", p.area].filter(Boolean).join(" · "))}</small></div>
        ${CTX.can.manage ? `<button type="button" class="btn btn-sm btn-outline-primary" data-invite="${p.id}" title="Add to a ministry"><i class="ri-user-add-line"></i></button>` : ""}</li>`,
      )
      .join("")}</ul>${d.not_serving > d.not_serving_people.length ? `<p class="mb-0 mt-2 mb-sub">And ${N.num(d.not_serving - d.not_serving_people.length)} more - filter the members list by "Not in a ministry".</p>` : ""}`;
  }

  function table(d) {
    if (!d.ministries.length) {
      $("mnRows").innerHTML = `<tr><td colspan="7"><p class="mb-0 p-2 fw-semibold">No ministries running.</p></td></tr>`;
      return;
    }
    const most = Math.max(1, ...d.ministries.map((m) => m.members));
    $("mnRows").innerHTML = d.ministries
      .map((m) => {
        const share = d.members_total ? Math.round((m.members / d.members_total) * 100) : 0;
        const known = m.male + m.female;
        const href = `${CTX.baseUrl}/ministry?id=${m.id}`;
        return `<tr>
          <td><a class="d-flex align-items-center gap-2 text-reset" href="${href}">${N.tile(m, "md")}<span class="min-w-0"><strong class="d-block">${N.esc(m.name)}</strong><small class="mb-sub"><i class="ri-user-star-line me-1"></i>${N.esc(m.leader || "No leader yet")}</small></span></a></td>
          <td data-order="${m.members}"><div class="mn-cell-bar"><strong>${N.num(m.members)}</strong><div class="progress progress-xs"><div class="progress-bar bg-${m.colour}" style="width:${Math.round((m.members / most) * 100)}%"></div></div><small>${share}% of members</small></div></td>
          <td><div class="mn-cell-bar"><div class="count-bar mn-split" aria-hidden="true">${known ? `<span class="bg-pink" style="width:${(m.female / known) * 100}%"></span><span class="bg-info" style="width:${(m.male / known) * 100}%"></span>` : ""}</div><small><span class="text-pink fw-semibold">${N.num(m.female)} women</span> · <span class="text-info fw-semibold">${N.num(m.male)} men</span></small></div></td>
          <td class="d-none d-lg-table-cell">${m.sunday_school ? `<span class="soft-chip soft-pink"><i class="ri-book-open-line"></i>${N.num(m.sunday_school)}</span>` : '<span class="mb-sub">-</span>'}</td>
          <td>${m.linked ? (m.series.some(Boolean) ? `<div class="mn-row-spark" data-spark='${JSON.stringify({ labels: d.months, data: m.series })}' data-spark-color="${m.colour}" data-spark-height="34"></div><small class="mb-sub">${N.num(m.gatherings)} gatherings</small>` : '<span class="mb-sub">None recorded yet</span>') : '<span class="soft-chip soft-warning"><i class="ri-link-unlink"></i>Not linked</span>'}</td>
          <td>${m.average === null ? '<span class="mb-sub">-</span>' : `<span class="fw-bold fs-15">${N.num(m.average)}</span>${m.recent_average !== null && m.earlier_average !== null ? UI.changePill(m.recent_average, m.earlier_average) : ""}<small class="d-block mb-sub">each time</small>`}</td>
          <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
        </tr>`;
      })
      .join("");
    UI.mountSparklines($("mnRows"));
  }

  async function init() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    const res = await MinistriesAPI.insights();
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      return;
    }
    cards(res.data);
    chart(res.data);
    none(res.data);
    table(res.data);
    $("noneBody").addEventListener("click", (e) => {
      const b = e.target.closest("[data-invite]");
      if (b) N.addToMinistryWindow([Number(b.dataset.invite)], { onDone: () => init() });
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
