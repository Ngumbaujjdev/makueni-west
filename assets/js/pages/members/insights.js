/**
 * ============================================================================
 * MEMBERS - insights (insights.php)
 * ============================================================================
 * The age-and-gender pyramid, joining and leaving over the year, the gender
 * donut, this month's birthdays (with a birthday SMS through Messages), and
 * the register beside the last Demographics count.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);

  function pyramid(i) {
    const el = $("pyramidChart");
    el.classList.remove("skel-chart");
    const total = i.pyramid.reduce((a, b) => a + b.male + b.female, 0);
    $("pyramidChips").innerHTML = `${i.no_birth_date ? `<span class="soft-chip soft-warning"><i class="ri-question-line"></i>${M.num(i.no_birth_date)} without a date of birth</span>` : ""}`;
    if (!total) {
      el.innerHTML = M.empty("ri-bar-chart-horizontal-line", "No ages yet", "Add members' dates of birth, and the age bands show here.");
      return;
    }
    const labels = i.pyramid.map((b) => b.label);
    new ApexCharts(el, {
      chart: { type: "bar", height: 280, stacked: true, toolbar: { show: false }, fontFamily: "inherit" },
      plotOptions: { bar: { horizontal: true, barHeight: "62%", borderRadius: 4 } },
      colors: [UI.cssColor("primary"), UI.cssColor("pink")],
      series: [
        { name: "Men", data: i.pyramid.map((b) => -b.male) },
        { name: "Women", data: i.pyramid.map((b) => b.female) },
      ],
      xaxis: { categories: labels, labels: { formatter: (v) => (Number.isInteger(+v) ? Math.abs(v) : "") } },
      tooltip: { y: { formatter: (v) => `${Math.abs(v)} ${Math.abs(v) === 1 ? "person" : "people"}` } },
      dataLabels: { enabled: true, formatter: (v) => (v ? Math.abs(v) : "") },
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

  function birthdays(i) {
    $("birthdayCount").innerHTML = i.birthdays.length ? `<span class="soft-chip soft-pink"><i class="ri-cake-2-line"></i>${i.birthdays.length}</span>` : "";
    if (!i.birthdays.length) {
      $("birthdays").innerHTML = '<p class="mb-0 fw-semibold">No birthdays this month - or no dates of birth yet.</p>';
      return;
    }
    const today = new Date().getDate();
    $("birthdays").innerHTML = `<ul class="mb-mini-list">${i.birthdays
      .map((b) => {
        const text = (i.birthday_template || "").replace(/\{first_name\}/g, b.name.split(" ")[0]).replace(/\{church\}/g, i.church_name || "");
        const sms = b.phone ? `<a class="btn btn-sm btn-outline-primary ms-auto" href="${CTX.messagesUrl}?channel=sms&typed=${encodeURIComponent(b.phone)}&body=${encodeURIComponent(text)}" title="Send a birthday SMS"><i class="ri-chat-heart-line"></i></a>` : "";
        return `<li><span class="avatar avatar-sm avatar-rounded bg-${M.colorFor(b.id)} ${M.textOn(M.colorFor(b.id))}">${M.esc(b.initials)}</span><div><a class="fw-semibold mb-link" href="${CTX.baseUrl}/member?id=${b.id}">${M.esc(b.name)}</a><small>${b.day === today ? "Today" : `On the ${b.day}${["th", "st", "nd", "rd"][b.day % 10 > 3 || Math.floor(b.day / 10) === 1 ? 0 : b.day % 10]}`} · turns ${b.turns}</small></div>${sms}</li>`;
      })
      .join("")}</ul>`;
  }

  function registerVsDemo(i) {
    const r = i.register;
    const d = i.demographics;
    const rows = [
      ["Members", r.total, d?.total],
      ["Men", r.male, d?.male],
      ["Women", r.female, d?.female],
      ["Youth (13-35)", r.youth, d?.youth],
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
    pyramid(ins.data);
    flow(ov.data);
    gender(ins.data);
    birthdays(ins.data);
    registerVsDemo(ins.data);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
