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
    if (a >= 1e3) return `${Math.round(v / 1e3)}K`;
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

  return { CTX, MONTHS, money, amount, short, shortMoney, delta, STATUS, statusPill, periodLabel, esc, url, flash, showFlash };
})();

window.BudgetsUI = BudgetsUI;
