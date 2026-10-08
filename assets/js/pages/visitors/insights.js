/**
 * ============================================================================
 * VISITORS - insights (insights.php)
 * ============================================================================
 * For a year: the cards (first-timers, came back, became members, days to
 * the first follow-up), the road from first visit to member, each month,
 * how they heard of us, and the follow-up done. The year is kept in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const V = VisitorsUI;
  const $ = (id) => document.getElementById(id);
  const thisYear = new Date().getFullYear();
  let year = Number(new URLSearchParams(window.location.search).get("year")) || thisYear;
  let chart = null;
  const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);

  function cards(i) {
    const back = i.funnel.find((f) => f.key === "returning").count;
    const joined = i.funnel.find((f) => f.key === "member").count;
    const c = [
      { icon: "ri-star-smile-line", label: "First-timers", value: M.num(i.first_timers), color: "pink", series: { labels: i.months, data: i.first_series }, sub: year === thisYear ? "So far this year" : `In ${year}` },
      { icon: "ri-repeat-line", label: "Came back", value: i.first_timers ? `${pct(back, i.first_timers)}%` : "-", color: "primary", sub: `${M.num(back)} of them visited again` },
      { icon: "ri-home-heart-line", label: "Became members", value: M.num(joined), color: "purple", series: { labels: i.months, data: i.joined_series }, sub: i.first_timers ? `${pct(joined, i.first_timers)}% of first-timers` : "Of first-timers" },
      { icon: "ri-timer-line", label: "Days to the first follow-up", value: i.average_wait === null ? "-" : String(i.average_wait), color: "success", sub: i.never_followed ? `${M.num(i.never_followed)} still waiting for one` : "On average" },
    ];
    const row = $("statCardsRow");
    row.innerHTML = c.map((x) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(x)}</div>`).join("");
    UI.mountSparklines(row);
  }

  function funnel(i) {
    const top = i.funnel[0].count;
    if (!top) {
      $("funnel").innerHTML = M.empty("ri-filter-3-line", "No first-timers yet", `When visitors are recorded${year === thisYear ? " this year" : ` in ${year}`}, their road to membership shows here.`);
      return;
    }
    $("funnel").innerHTML = `<div class="vs-funnel">${i.funnel
      .map((f) => {
        const s = V.STAGES[f.key];
        const w = Math.max(pct(f.count, top), f.count ? 4 : 0);
        return `<div class="vs-funnel-row">
          <div class="vs-funnel-label"><span class="ev-tile is-sm is-soft" style="--q: var(--${s.color}-rgb)"><i class="${s.icon}"></i></span>${f.label}</div>
          <div class="vs-funnel-track"><span style="width:${w}%; --q: var(--${s.color}-rgb)"></span></div>
          <div class="vs-funnel-num"><strong>${M.num(f.count)}</strong><small>${pct(f.count, top)}%</small></div>
        </div>`;
      })
      .join("")}</div>`;
  }

  function months(i) {
    const el = $("monthChart");
    el.classList.remove("skel-chart");
    chart?.destroy?.();
    el.innerHTML = "";
    if (![...i.first_series, ...i.returning_series, ...i.joined_series].some(Boolean)) {
      el.innerHTML = M.empty("ri-bar-chart-2-line", "Nothing recorded", "Months with visitors show here.");
      return;
    }
    chart = UI.renderTrendChart("monthChart", {
      categories: i.months,
      type: "bar",
      colors: [UI.cssColor("pink"), UI.cssColor("primary"), UI.cssColor("purple")],
      series: [
        { name: "First-timers", data: i.first_series },
        { name: "Return visits", data: i.returning_series },
        { name: "Became members", data: i.joined_series },
      ],
    });
  }

  function heard(i) {
    const el = $("heardDonut");
    el.classList.remove("skel-chart");
    el.innerHTML = "";
    if (!i.how_heard.length) {
      el.innerHTML = M.empty("ri-broadcast-line", "Nobody asked yet", "Ask first-timers how they heard of us when you record them.");
      return;
    }
    const names = ["primary", "pink", "success", "purple", "info", "warning", "secondary"];
    UI.renderRingDonut("heardDonut", { labels: i.how_heard.map((h) => h.label), series: i.how_heard.map((h) => h.count), colors: i.how_heard.map((_, n) => UI.cssColor(names[n % names.length])), centerLabel: "First-timers" });
  }

  function followups(i) {
    const types = i.followup_types.filter((t) => t.count);
    const outcomes = i.followup_outcomes.filter((o) => o.count);
    if (!types.length) {
      $("followupMix").innerHTML = '<p class="mb-0 fw-semibold">No follow-up logged yet.</p>';
      return;
    }
    $("followupMix").innerHTML = `
      <ul class="mb-mini-list">${types.map((t) => `<li><i class="${V.TYPES[t.key].icon}"></i><div class="flex-fill"><strong>${t.label}</strong></div><strong>${M.num(t.count)}</strong></li>`).join("")}</ul>
      <div class="d-flex flex-wrap gap-1 mt-3">${outcomes.map((o) => `<span class="soft-chip soft-${V.OUTCOMES[o.key].color === "danger" ? "danger" : V.OUTCOMES[o.key].color === "success" ? "success" : "primary"}">${o.label} · ${M.num(o.count)}</span>`).join("")}</div>`;
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-lg-6 col-md-6");
    const res = await VisitorsAPI.insights(year);
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      $("vsInsights").hidden = true;
      return;
    }
    $("vsInsights").hidden = false;
    cards(res.data);
    funnel(res.data);
    months(res.data);
    heard(res.data);
    followups(res.data);
  }

  function init() {
    const years = [thisYear, thisYear - 1, thisYear - 2].map((y) => ({ value: y, label: String(y) }));
    $("yearWrap").innerHTML = UI.renderSegmented("yearSwitch", years, year, { ariaLabel: "Year" });
    UI.wireSegmented("yearSwitch", (v) => {
      year = Number(v);
      const q = new URLSearchParams(window.location.search);
      year === thisYear ? q.delete("year") : q.set("year", year);
      history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
      load();
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
