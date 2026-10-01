/**
 * ============================================================================
 * BUDGETS - small shared pieces (money, statuses, periods, flash messages)
 * ============================================================================
 */
const BudgetsUI = (function () {
  "use strict";

  const CTX = window.BUDGET_CTX || {};
  const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
  const kes = new Intl.NumberFormat("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  /** "KES 12,345.00" */
  const money = (n) => `KES ${kes.format(Number(n) || 0)}`;
  /** "12,345.00" */
  const amount = (n) => kes.format(Number(n) || 0);

  /** "250K", "1.2M" - for chart axes and chips. */
  function short(n) {
    const v = Number(n) || 0;
    const a = Math.abs(v);
    if (a >= 1e6) return `${(v / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace(/\.0$/, "")}M`;
    if (a >= 1e4) return `${Math.round(v / 1e3)}K`;
    if (a >= 1e3) return `${(v / 1e3).toFixed(1).replace(/\.0$/, "")}K`; // 1.5K, not 2K
    return String(Math.round(v));
  }
  const shortMoney = (n) => `KES ${short(n)}`;

  /** A change pill "▲ 8% vs 2025" (null when there's nothing to compare with). */
  const delta = (current, previous, label) => (previous == null ? null : DemographicsUI.periodDelta(Number(current) || 0, Number(previous) || 0, { prevLabel: label }));

  const STATUS = {
    draft: { label: "Draft", color: "warning", icon: "ri-draft-line" },
    active: { label: "In use", color: "success", icon: "ri-checkbox-circle-fill" },
    closed: { label: "Closed", color: "secondary", icon: "ri-lock-line" },
  };

  function statusPill(status) {
    const s = STATUS[status] || { label: status, color: "secondary", icon: "" };
    return DemographicsUI.pill(s.label, s.color, s.icon);
  }

  /**
   * A ring with a compact list beside it (dot, name, amount, %) - the lines
   * stay one row each instead of wrapping into tiles, so the card stays short.
   * Shows the biggest `limit` lines; the rest are added up as "Other"
   * (sort: false keeps rows that already come grouped, e.g. top 5 + Other).
   */
  function moneyDonut(containerId, { rows, colors, centerLabel, limit = 5, sort = true } = {}) {
    const el = document.getElementById(containerId);
    if (!el || typeof ApexCharts === "undefined") return null;
    const sorted = sort ? [...rows].sort((a, b) => b.value - a.value) : [...rows];
    const shown = sorted.slice(0, limit);
    const rest = sorted.slice(limit);
    if (rest.length) shown.push({ name: `Other (${rest.length} ${rest.length === 1 ? "line" : "lines"})`, value: rest.reduce((a, r) => a + r.value, 0) });
    const total = shown.reduce((a, r) => a + r.value, 0);
    // "Other" is a neutral grey, so it never looks like one of the lines.
    const colorOf = (r, i) => (/^Other \(/.test(r.name) ? "dark" : colors[i % colors.length]);
    const pct = (v) => (total ? (v / total < 0.01 && v > 0 ? "<1%" : `${Math.round((v / total) * 100)}%`) : "0%");
    el.innerHTML = `
      <div class="budget-donut">
        <div class="budget-donut-chart"></div>
        <ul class="composition-list budget-donut-list">
          ${shown
            .map(
              (r, i) => `
            <li>
              <span class="composition-name"><span class="count-dot bg-${colorOf(r, i)}"></span><span class="text-truncate">${esc(r.name)}</span></span>
              <span class="composition-value">${shortMoney(r.value)} <span>${pct(r.value)}</span></span>
            </li>`,
            )
            .join("")}
        </ul>
      </div>`;
    const text = DemographicsUI.chartTextColor();
    const chart = new ApexCharts(el.querySelector(".budget-donut-chart"), {
      chart: { type: "donut", height: 230, animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") } },
      series: shown.map((r) => r.value),
      labels: shown.map((r) => r.name),
      colors: shown.map((r, i) => DemographicsUI.cssColor(colorOf(r, i))),
      stroke: { width: 0 },
      legend: { show: false },
      dataLabels: { enabled: false },
      tooltip: { y: { formatter: (v) => money(v) } },
      plotOptions: {
        pie: {
          expandOnClick: false,
          donut: {
            size: "80%",
            labels: {
              show: true,
              name: { show: true, fontSize: "12px", color: text, offsetY: -4 },
              value: { show: true, fontSize: "20px", fontWeight: 700, color: text, offsetY: 6, formatter: (v) => shortMoney(v) },
              total: { show: true, showAlways: true, label: centerLabel, fontSize: "12px", color: text, formatter: () => shortMoney(total) },
            },
          },
        },
      },
    });
    chart.render();
    return chart;
  }

  // An icon that fits a line's name, so a list of lines is easy to scan.
  const LINE_ICONS = [
    [/tithe|offering/i, "ri-hand-heart-line"],
    [/donation|gift/i, "ri-gift-line"],
    [/fundrais|harambee|event/i, "ri-hand-coin-line"],
    [/diocese|diocesan|allocation|levy|share/i, "ri-government-line"],
    [/salar|wage|allowance|stipend/i, "ri-user-star-line"],
    [/housing|rent|house/i, "ri-home-4-line"],
    [/electric|power/i, "ri-flashlight-line"],
    [/water/i, "ri-drop-line"],
    [/internet|airtime|phone|wifi/i, "ri-wifi-line"],
    [/equipment|repair|maintenance/i, "ri-tools-line"],
    [/retreat|conference|seminar|training/i, "ri-community-line"],
    [/pastoral|welfare|care/i, "ri-user-heart-line"],
    [/transport|travel|fuel/i, "ri-bus-2-line"],
    [/print|stationery/i, "ri-printer-line"],
    [/music|choir|sound/i, "ri-music-2-line"],
    [/bank|charge/i, "ri-bank-line"],
    [/building|construction|church/i, "ri-building-line"],
    [/mission|evangel|outreach/i, "ri-seedling-line"],
  ];
  const lineIcon = (name, side) => (LINE_ICONS.find(([re]) => re.test(name || "")) || [, side === "in" ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line"])[1];
  /** Money in lines are green; money out lines take turns through the category colours. */
  const OUT_COLORS = ["danger", "warning", "purple", "pink", "primary", "secondary"];
  const lineColor = (side, index) => (side === "in" ? "success" : OUT_COLORS[index % OUT_COLORS.length]);

  const periodLabel = (year, month) => (month ? `${MONTHS[month - 1]} ${year}` : `Whole of ${year}`);

  function esc(value) {
    if (value === null || value === undefined) return "";
    return String(value).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }

  const url = (page, params) => `${CTX.baseUrl}/${page}${params ? `?${new URLSearchParams(params)}` : ""}`;

  /** A message to show on the next page (after a redirect). */
  function flash(message, type = "success") {
    try {
      sessionStorage.setItem("budgetsFlash", JSON.stringify({ message, type }));
    } catch (e) {
      /* storage blocked - the message is just lost */
    }
  }

  function showFlash() {
    try {
      const f = JSON.parse(sessionStorage.getItem("budgetsFlash") || "null");
      sessionStorage.removeItem("budgetsFlash");
      if (f?.message) Toast[f.type === "error" ? "error" : "success"](f.message);
    } catch (e) {
      /* nothing to show */
    }
  }

  /**
   * Year buttons (#yearSwitchWrap) + a month picker (#monthSelect: Whole year,
   * January..December), kept in the URL (?year=&month= / month=year).
   * Calls onChange({year, month}) when either changes; month null = whole year.
   */
  function periodControls({ defaultMonth = true, onChange }) {
    const p = new URLSearchParams(window.location.search);
    const now = new Date();
    const state = {
      year: Number(p.get("year")) || now.getFullYear(),
      month: p.has("month") ? (p.get("month") === "year" ? null : Number(p.get("month")) || null) : defaultMonth ? now.getMonth() + 1 : null,
    };
    const years = [...new Set([now.getFullYear() - 1, now.getFullYear(), now.getFullYear() + 1, state.year])].sort((a, b) => a - b);
    document.getElementById("yearSwitchWrap").innerHTML = DemographicsUI.renderSegmented("yearSwitch", years.map((y) => ({ value: y, label: String(y) })), state.year, { ariaLabel: "Year" });
    const select = document.getElementById("monthSelect");
    select.innerHTML = [
      `<option value="year" data-icon="ri-calendar-2-line" data-color="purple">Whole year</option>`,
      ...MONTHS.map((m, i) => `<option value="${i + 1}" data-icon="ri-calendar-line" data-color="primary">${m}</option>`),
    ].join("");
    select.value = state.month ? String(state.month) : "year";
    DemographicsUI.enhanceSelect(select, { search: false });

    const changed = () => {
      const q = new URLSearchParams(window.location.search);
      q.set("year", state.year);
      q.set("month", state.month ?? "year");
      history.replaceState(null, "", `${window.location.pathname}?${q}`);
      onChange({ ...state });
    };
    DemographicsUI.wireSegmented("yearSwitch", (value) => {
      state.year = Number(value);
      changed();
    });
    select.addEventListener("change", () => {
      state.month = select.value === "year" ? null : Number(select.value);
      changed();
    });
    return state;
  }

    return { CTX, MONTHS, money, amount, short, shortMoney, delta, STATUS, statusPill, moneyDonut, lineIcon, lineColor, periodLabel, periodControls, esc, url, flash, showFlash };
})();

window.BudgetsUI = BudgetsUI;
