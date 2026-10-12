/**
 * ============================================================================
 * STAFF - the dashboard (hr/dashboard.php, every level)
 * ============================================================================
 * docs/specs/hr-spec.md → Dashboard. The template's HRM dashboard (index-8):
 * four figures with their change and a sparkline, pay month by month against
 * last year, staff by type, what is coming up, pay by position, the latest
 * payroll runs and - for a region or the diocese - each place below. A year
 * and a place narrow it; both stay in the address.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const API = HrAPI;
  const CTX = window.HR_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  const TYPES = { full_time: "primary", part_time: "success", contract: "warning", casual: "purple" };
  const RUN = { draft: ["secondary", "ri-draft-line"], submitted: ["warning", "ri-time-line"], returned: ["danger", "ri-arrow-go-back-line"], posted: ["primary", "ri-checkbox-circle-line"], paid: ["success", "ri-check-double-line"] };
  const CYCLE = ["primary", "success", "purple", "warning", "pink", "info", "danger"];
  const q = new URLSearchParams(window.location.search);
  const state = { year: Number(q.get("year")) || new Date().getFullYear(), place: q.get("place_id") || "", range: 12 };
  let data = null;
  let payChart = null;
  let donut = null;
  const money = (v) => A.money(v, { cents: false });
  const shortMoney = (v) => (Math.abs(v) >= 1e6 ? `${(v / 1e6).toFixed(1)}M` : Math.abs(v) >= 1e3 ? `${Math.round(v / 1e3)}K` : String(Math.round(v)));
  const monthLabel = (m, opts = { month: "long", year: "numeric" }) => new Date(`${m}-01T12:00:00`).toLocaleDateString("en-GB", opts);
  const personUrl = (id) => `${CTX.baseUrl}/person.php?id=${id}`;

  // ------------------------------------------------------------ figures

  /**
   * One figure card, as the template's HRM cards: the coloured tile and label
   * with the change on the right, the figure with a sparkline beside it, and
   * a chip saying what it counts.
   */
  function kpi({ icon, color, label, value, chip, delta, spark }) {
    const d = delta ? `<span class="kpi-delta is-${delta.dir}"><i class="ri-arrow-${delta.dir === "down" ? "down-s-fill" : delta.dir === "up" ? "up-s-fill" : "right-line"}"></i>${esc(delta.text)}</span>` : "";
    const series = spark ? { labels: MONTHS.slice(0, spark.length), data: spark } : null;
    return `<div class="col-xxl-3 col-sm-6 d-flex"><div class="card custom-card flex-fill"><div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-3"><span class="avatar avatar-md bg-${color} ${A.textOn(color)} flex-shrink-0"><i class="${icon} fs-18"></i></span><span class="fw-semibold flex-fill">${esc(label)}</span>${d}</div>
      <div class="d-flex align-items-end justify-content-between gap-2"><h4 class="fw-semibold mb-0 text-nowrap">${value}</h4>${series ? `<div class="kpi-spark flex-shrink-0" style="width: 90px" data-spark='${JSON.stringify(series)}' data-spark-color="${color}" data-spark-height="40"></div>` : ""}</div>
      <div class="d-flex flex-wrap align-items-center gap-2 mt-2">${chip}${delta ? `<span class="kpi-caption">${esc(delta.caption)}</span>` : ""}</div>
    </div></div></div>`;
  }

  function figures() {
    const f = data.figures;
    const last = data.months.findLastIndex((m) => m.gross > 0);
    const upTo = last >= 0 ? last + 1 : 12;
    const series = data.months.slice(0, upTo);
    const types = data.by_type.filter((t) => t.count);
    let running = 0;
    $("dashKpis").innerHTML = [
      kpi({ icon: "ri-team-line", color: "primary", label: "Staff", value: A.num(f.staff), chip: `<span class="soft-chip soft-primary">${types.length ? types.map((t) => `${t.count} ${t.label.toLowerCase()}`).join(" · ") : "Nobody yet"}</span>`, delta: UI.periodDelta(f.staff, f.staff_year_ago, { percent: false, prevLabel: "a year ago" }), spark: series.map((m) => m.people) }),
      kpi({ icon: "ri-money-dollar-box-line", color: "success", label: "Pay a month", value: money(f.monthly_pay), chip: '<span class="soft-chip soft-success">Basic and allowances</span>', delta: f.last_bill !== null ? UI.periodDelta(f.monthly_pay, f.last_bill, { prevLabel: monthLabel(f.last_bill_month, { month: "long" }) }) : null, spark: series.map((m) => m.gross) }),
      kpi({ icon: "ri-hand-coin-line", color: "warning", label: `Paid in ${data.year}`, value: money(f.paid), chip: `<span class="soft-chip soft-warning">${f.months_counted} ${f.months_counted === 1 ? "month" : "months"}</span>`, delta: UI.periodDelta(f.paid, f.paid_before, { prevLabel: `same months ${data.year - 1}` }), spark: series.map((m) => (running += m.net)) }),
      kpi({ icon: "ri-user-star-line", color: "purple", label: "Average pay", value: money(f.average), chip: `<span class="soft-chip soft-purple">${f.contracts_ending ? `${f.contracts_ending} contract${f.contracts_ending === 1 ? "" : "s"} ending soon` : "A person a month"}</span>`, delta: UI.periodDelta(f.average, f.average_year_ago, { prevLabel: "a year ago" }), spark: series.map((m) => (m.people ? Math.round(m.gross / m.people) : 0)) }),
    ].join("");
    UI.mountSparklines($("dashKpis"));
  }

  // ------------------------------------------------------------ pay by month

  function payByMonth() {
    const last = data.months.findLastIndex((m) => m.gross > 0);
    const end = state.range === 6 ? Math.max(6, last + 1) : 12;
    const from = end - state.range;
    const now = data.months.slice(from, end);
    const before = data.months_before.slice(from, end);
    const options = {
      series: [
        { name: String(data.year), data: now.map((m) => m.gross) },
        { name: String(data.year - 1), data: before.map((m) => m.gross) },
      ],
      chart: { type: "bar", height: 310, toolbar: { show: false }, fontFamily: "Inter, sans-serif" },
      grid: { borderColor: "rgba(0, 0, 0, .08)", strokeDashArray: 3 },
      colors: [UI.cssColor ? UI.cssColor("primary") : "rgb(44,164,191)", UI.cssColor ? UI.cssColor("secondary") : "rgb(242,190,34)"],
      legend: { show: true, position: "top", horizontalAlign: "right" },
      plotOptions: { bar: { columnWidth: state.range === 6 ? "45%" : "55%", borderRadius: 3 } },
      dataLabels: { enabled: false },
      xaxis: { categories: MONTHS.slice(from, end) },
      yaxis: { labels: { formatter: (v) => shortMoney(v) } },
      tooltip: { y: { formatter: (v) => money(v) } },
      fill: { opacity: 1 },
    };
    if (payChart) payChart.destroy();
    $("payChart").innerHTML = "";
    payChart = new ApexCharts($("payChart"), options);
    payChart.render();

    const best = data.months.reduce((b, m) => (m.gross > (b?.gross || 0) ? m : b), null);
    const tile = (icon, color, label, value) => `<div class="col-md-4 col-sm-12"><div class="d-flex align-items-top"><div class="me-3"><span class="avatar avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon} fs-18"></i></span></div><div><span class="d-block mb-1 fw-medium">${esc(label)}</span><h6 class="fw-semibold mb-0">${value}</h6></div></div></div>`;
    $("paySummary").innerHTML = [
      tile("ri-wallet-3-line", "primary", `Paid out in ${data.year}`, money(data.figures.paid)),
      tile("ri-history-line", "warning", `Same months ${data.year - 1}`, money(data.figures.paid_before)),
      tile("ri-trophy-line", "success", "Biggest month", best ? `${money(best.gross)} <span class="kpi-caption">${monthLabel(best.month, { month: "short" })}</span>` : "None yet"),
    ].join("");
  }

  // ------------------------------------------------------------ staff by type

  function byType() {
    const rows = data.by_type;
    const total = rows.reduce((t, r) => t + r.count, 0);
    if (donut) donut.destroy();
    $("typeDonut").innerHTML = "";
    if (!total) {
      $("typeDonut").innerHTML = A.empty("ri-pie-chart-2-line", "Nobody yet", "Add the people this place employs.");
    } else {
      donut = new ApexCharts($("typeDonut"), {
        series: rows.map((r) => r.count),
        labels: rows.map((r) => r.label),
        chart: { type: "donut", height: 260, fontFamily: "Inter, sans-serif" },
        legend: { show: false },
        stroke: { width: 0 },
        dataLabels: { enabled: false },
        colors: rows.map((r) => UI.cssColor(TYPES[r.key] === "warning" ? "secondary" : TYPES[r.key])),
        plotOptions: { pie: { expandOnClick: false, donut: { size: "72%", labels: { show: true, value: { fontSize: "20px", fontWeight: 700 }, total: { show: true, showAlways: true, label: "Staff", fontSize: "14px", formatter: () => String(total) } } } } },
      });
      donut.render();
    }
    $("typeLegend").innerHTML = rows
      .map((r) => `<div class="col p-0"><div class="text-center"><span class="fs-12 mb-1 d-inline-flex align-items-center gap-1 fw-medium"><span class="d-inline-block rounded-circle bg-${TYPES[r.key]}" style="width: .5rem; height: .5rem"></span>${esc(r.label)}</span><div><span class="fs-16 fw-semibold">${r.count}</span></div></div></div>`)
      .join("");
  }

  // ------------------------------------------------------------ coming up

  const KIND = {
    starts: ["success", "ri-user-add-line", () => "Starts"],
    leaves: ["danger", "ri-user-unfollow-line", () => "Leaves"],
    contract: ["warning", "ri-file-warning-line", () => "Contract ends"],
    anniversary: ["purple", "ri-cake-2-line", (x) => `${x.years} ${x.years === 1 ? "year" : "years"} with us`],
  };

  function comingUp() {
    const items = data.coming_up;
    $("comingUp").innerHTML = items.length
      ? `<ul class="list-unstyled timeline-widget mb-0 my-3">${items
          .map((x) => {
            const d = new Date(`${x.date}T12:00:00`);
            const [c, i, label] = KIND[x.kind];
            return `<li class="timeline-widget-list"><div class="d-flex align-items-top">
              <div class="me-5 text-center"><span class="d-block fs-20 fw-semibold text-primary">${String(d.getDate()).padStart(2, "0")}</span><span class="d-block fs-12 fw-medium">${d.toLocaleDateString("en-GB", { month: "short" })}</span></div>
              <div class="d-flex flex-wrap flex-fill align-items-top justify-content-between gap-2">
                <div class="min-w-0"><a class="mb-1 fw-semibold d-block mb-link" href="${personUrl(x.employee_id)}">${esc(x.name)}</a><p class="mb-0 fs-12 lh-1"><span class="badge bg-${c} ${A.textOn(c)} me-2"><i class="${i} me-1"></i>${esc(label(x))}</span>${esc([x.position, CTX.can.below ? x.place : null].filter(Boolean).join(" · "))}</p></div>
                <a href="${personUrl(x.employee_id)}" class="btn btn-sm btn-icon btn-primary-light" aria-label="Open ${esc(x.name)}"><i class="ri-arrow-right-line"></i></a>
              </div></div></li>`;
          })
          .join("")}</ul>`
      : A.empty("ri-calendar-check-line", "Nothing in the next 60 days", "Contracts ending, people starting and years of service show here.");
  }

  // ------------------------------------------------------------ pay by position

  function byPosition() {
    const rows = data.by_position;
    const max = Math.max(1, ...rows.map((r) => r.monthly));
    $("byPosition").innerHTML = rows.length
      ? `<ul class="list-unstyled mb-0 d-grid gap-3">${rows
          .map((r, i) => {
            const c = CYCLE[i % CYCLE.length];
            return `<li class="d-flex align-items-center gap-3"><span class="avatar avatar-sm avatar-rounded bg-${c} ${A.textOn(c)} flex-shrink-0"><i class="ri-briefcase-4-line"></i></span>
              <div class="flex-fill min-w-0"><div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold text-truncate">${esc(r.name)}</span><span class="fw-semibold text-success text-nowrap">${money(r.monthly)}</span></div>
              <div class="d-flex align-items-center gap-2"><div class="progress progress-xs flex-fill" role="progressbar" aria-label="${esc(r.name)} share of pay" aria-valuenow="${Math.round((r.monthly / max) * 100)}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-${c}" style="width: ${(r.monthly / max) * 100}%"></div></div><span class="fs-12 fw-medium text-nowrap">${r.count} ${r.count === 1 ? "person" : "people"}</span></div></div></li>`;
          })
          .join("")}</ul>`
      : A.empty("ri-briefcase-4-line", "No positions yet", "Give people a position to see what each costs.");
  }

  // ------------------------------------------------------------ payroll runs

  function runs() {
    const rows = data.runs;
    $("runsBody").innerHTML = rows.length
      ? rows
          .map((r) => {
            const [c, i] = RUN[r.status] || ["secondary", "ri-question-line"];
            return `<tr><td><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-primary text-white flex-shrink-0 fs-11 fw-semibold">${monthLabel(r.month, { month: "short" })}</span><div><span class="d-block fw-semibold">${monthLabel(r.month)}</span><span class="d-block fs-12 fw-medium"><i class="ri-group-line me-1 text-primary"></i>${r.people} ${r.people === 1 ? "person" : "people"}</span>${CTX.can.below ? `<span class="d-block fs-12 fw-medium"><i class="ri-map-pin-line me-1 text-purple"></i>${esc(r.place)}</span>` : ""}</div></div></td>
              <td class="text-end"><span class="d-block fw-semibold text-success mb-1">${money(r.net)}</span><span class="badge bg-${c} ${A.textOn(c)}"><i class="${i} me-1"></i>${esc(r.status_label)}</span></td></tr>`;
          })
          .join("")
      : `<tr><td colspan="2">${A.empty("ri-money-dollar-box-line", "No payroll yet", "Each month's payroll shows here once it is started.")}</td></tr>`;
  }

  // ------------------------------------------------------------ places below

  function places() {
    const rows = data.places;
    $("placesCard").hidden = !rows.length;
    if (!rows.length) return;
    $("placesCount").textContent = `${rows.length} ${rows.length === 1 ? "place" : "places"}`;
    const max = Math.max(1, ...rows.map((r) => r.paid));
    HrWindows.fresh("placesTable");
    A.tableKit({
      tableId: "placesTable",
      prefix: "p_",
      items: rows,
      noun: "places",
      search: "Search a place...",
      sorts: [
        { key: "pay", label: "Highest pay", order: [[2, "desc"]] },
        { key: "name", label: "Name A-Z", order: [[0, "asc"]] },
        { key: "staff", label: "Most staff", order: [[1, "desc"]] },
      ],
      nonSortable: [4],
      rowHtml: (r, i) => {
        const c = CYCLE[rows.indexOf(r) % CYCLE.length];
        return `<tr data-place="${r.id}">
          <td data-order="${esc(r.name)}"><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${c} ${A.textOn(c)} flex-shrink-0"><i class="${r.level === "church" ? "ri-building-4-line" : "ri-map-pin-line"}"></i></span><div><span class="d-block fw-semibold">${esc(r.name)}</span><span class="d-block fs-12 fw-medium text-capitalize">${esc(r.level)}</span></div></div></td>
          <td class="text-end" data-order="${r.staff}"><span class="badge bg-primary text-white">${r.staff}</span></td>
          <td class="text-end fw-semibold text-success" data-order="${r.monthly_pay}">${money(r.monthly_pay)}</td>
          <td data-order="${r.paid}" style="min-width: 12rem"><div class="d-flex align-items-center gap-2"><div class="progress progress-xs flex-fill"><div class="progress-bar bg-${c}" style="width: ${(r.paid / max) * 100}%"></div></div><span class="fw-semibold text-nowrap">${money(r.paid)}</span></div></td>
          <td class="text-end"><button type="button" class="btn btn-sm btn-primary-light" data-only="${r.id}">Only this place<i class="ri-filter-3-line ms-1"></i></button></td>
        </tr>`;
      },
    });
  }

  // ------------------------------------------------------------ loading

  function skeleton() {
    $("dashKpis").innerHTML = UI.skeletonCards ? UI.skeletonCards(4, "col-xxl-3 col-sm-6") : "";
    ["comingUp", "byPosition"].forEach((id) => ($(id).innerHTML = '<span class="skel skel-line"></span><span class="skel skel-line mt-3" style="width: 70%"></span><span class="skel skel-line mt-3" style="width: 50%"></span>'));
    $("runsBody").innerHTML = UI.renderTableLoading(2, "Loading...", 4);
  }

  function years() {
    $("dashYears").innerHTML = data.years.map((y) => `<button type="button" class="seg-btn${y === data.year ? " active" : ""}" data-year="${y}" aria-pressed="${y === data.year}">${y}</button>`).join("");
  }

  function placeSelect() {
    const sel = $("dashPlace");
    if (!sel || sel.dataset.filled) return;
    const list = data.places;
    if (!list.length) return;
    sel.dataset.filled = "1";
    sel.innerHTML = `<option value="">Every place</option>${list.map((p) => `<option value="${p.id}" data-icon="${p.level === "church" ? "ri-building-4-line" : "ri-map-pin-line"}" data-color="primary">${esc(p.name)}</option>`).join("")}`;
    sel.value = state.place;
    UI.enhanceSelect(sel, { search: list.length > 8 });
    UI.syncSelect(sel);
  }

  function remember() {
    const p = new URLSearchParams(window.location.search);
    state.year !== new Date().getFullYear() ? p.set("year", state.year) : p.delete("year");
    state.place ? p.set("place_id", state.place) : p.delete("place_id");
    history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
  }

  async function load() {
    skeleton();
    remember();
    // The place list comes from the whole region or diocese - fetch it once, before narrowing.
    if ($("dashPlace") && !$("dashPlace").dataset.filled && state.place) {
      const all = await API.dashboard({ year: state.year });
      if (all.ok) {
        data = all.data;
        placeSelect();
      }
    }
    const res = await API.dashboard({ year: state.year, place_id: state.place });
    if (!res.ok) {
      $("dashKpis").innerHTML = `<div class="col-12">${A.errorBox(res.message)}</div>`;
      return;
    }
    data = res.data;
    $("payrollBtn").hidden = !data.can.payroll;
    $("runsLink").hidden = !data.can.payroll;
    years();
    placeSelect();
    figures();
    payByMonth();
    byType();
    comingUp();
    byPosition();
    runs();
    places();
  }

  document.addEventListener("DOMContentLoaded", async () => {
    $("dashYears").addEventListener("click", (e) => {
      const b = e.target.closest("[data-year]");
      if (!b || Number(b.dataset.year) === state.year) return;
      state.year = Number(b.dataset.year);
      load();
    });
    $("payRange").addEventListener("click", (e) => {
      const b = e.target.closest("[data-range]");
      if (!b) return;
      state.range = Number(b.dataset.range);
      $("payRange").querySelectorAll("[data-range]").forEach((x) => {
        x.classList.toggle("btn-primary", x === b);
        x.classList.toggle("btn-primary-light", x !== b);
      });
      payByMonth();
    });
    const pick = (id) => {
      state.place = id ? String(id) : "";
      if ($("dashPlace")) {
        $("dashPlace").value = state.place;
        UI.syncSelect($("dashPlace"));
      }
      load();
    };
    if ($("dashPlace")) window.jQuery ? window.jQuery($("dashPlace")).on("change", () => state.place !== $("dashPlace").value && pick($("dashPlace").value)) : $("dashPlace").addEventListener("change", () => pick($("dashPlace").value));
    $("placesCard").addEventListener("click", (e) => {
      const b = e.target.closest("[data-only]");
      if (b) {
        pick(b.dataset.only);
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    });
    if ($("addBtn")) {
      const opts = await API.options();
      if (opts.ok) HrWindows.setup(opts.data, () => load());
      $("addBtn").addEventListener("click", () => HrWindows.person());
    }
    load();
  });
})();
