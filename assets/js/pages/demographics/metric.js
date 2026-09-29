/**
 * ============================================================================
 * PAGE - METRIC TREND (church/demographics-growth/metric.php?key=...)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One metric's full history - where the Demographics pages' "View trend" /
 * "Full history" links land, so those pages can stay light. Metric switch
 * re-renders in place (URL updated with replaceState, no reload).
 *
 * Data: GET /demographics (approved submissions only).
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, ApexCharts,
 * jQuery DataTables
 * ============================================================================
 */

const DemographicsMetric = (function () {
  "use strict";

  const UI = DemographicsUI;
  const M = UI.DEMOGRAPHIC_METRICS;
  let approved = []; // oldest first
  let key = "total_members";
  let chart = null;

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }
    key = M[METRIC_KEY] ? METRIC_KEY : "total_members";

    document.getElementById("metricSwitchWrap").innerHTML = `
      <select class="form-select" id="metricSwitch" aria-label="Metric" style="min-width: 220px;">
        ${Object.entries(M).map(([k, m]) => `<option value="${k}"${k === key ? " selected" : ""}>${m.label}</option>`).join("")}
      </select>`;
    document.getElementById("metricSwitch").addEventListener("change", (e) => {
      key = e.target.value;
      history.replaceState(null, "", `${window.location.pathname}?key=${encodeURIComponent(key)}`);
      render();
    });

    const result = await DemographicsAPIHandler.getDemographics(USER_TERRITORY.id);
    if (!result.success) Toast.error(result.message || "Failed to load demographics");
    approved = UI.sortSubmissionsNewestFirst(result.success ? result.data || [] : [])
      .reverse()
      .filter((r) => r.status === "approved");
    render();
  }

  function render() {
    const m = M[key];
    const rows = approved.filter((r) => m.get(r) != null);
    const values = rows.map((r) => Number(m.get(r)));

    document.getElementById("metricTitle").textContent = m.label;
    const icon = document.getElementById("metricIcon");
    icon.className = `kpi-icon bg-${m.color} ${m.color === "secondary" ? "text-dark" : "text-white"}`;
    icon.innerHTML = `<i class="${m.icon}"></i>`;
    document.getElementById("metricSubtitle").textContent = rows.length
      ? `${rows.length} approved submission${rows.length === 1 ? "" : "s"} · ${UI.demographicPeriodLabel(rows[0])} to ${UI.demographicPeriodLabel(rows[rows.length - 1])}`
      : "No approved submissions yet";
    document.title = `${m.label} - Makueni West Diocese`;

    renderKpis(m, rows, values);
    renderChart(m, rows, values);
    renderTable(m, rows);
  }

  function renderKpis(m, rows, values) {
    if (!rows.length) {
      document.getElementById("statCardsRow").innerHTML = "";
      return;
    }
    const latest = values[values.length - 1];
    const prev = values.length > 1 ? values[values.length - 2] : null;
    const hi = values.indexOf(Math.max(...values));
    const lo = values.indexOf(Math.min(...values));
    const sum = values.reduce((a, b) => a + b, 0);

    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: m.icon,
        label: "Latest",
        value: latest.toLocaleString(),
        color: m.color,
        delta: prev != null ? UI.periodDelta(latest, prev, { prevLabel: UI.shortPeriodLabel(rows[rows.length - 2]) }) : null,
        sub: UI.demographicPeriodLabel(rows[rows.length - 1]),
      },
      { icon: "ri-arrow-up-circle-line", label: "Highest", value: values[hi].toLocaleString(), color: "success", sub: UI.demographicPeriodLabel(rows[hi]) },
      { icon: "ri-arrow-down-circle-line", label: "Lowest", value: values[lo].toLocaleString(), color: "danger", sub: UI.demographicPeriodLabel(rows[lo]) },
      m.flow
        ? { icon: "ri-add-circle-line", label: "Total recorded", value: sum.toLocaleString(), color: "purple", sub: "across all submissions" }
        : { icon: "ri-bar-chart-line", label: "Average", value: Math.round(sum / values.length).toLocaleString(), color: "purple", sub: "per submission" },
    ]);
  }

  function renderChart(m, rows, values) {
    const el = document.getElementById("metricTrendChart");
    if (chart) chart.destroy();
    chart = null;
    document.getElementById("trendSubtitle").textContent = m.flow ? "Recorded in each period" : "Headcount at each approved submission";

    if (rows.length === 0) {
      el.innerHTML = `
        <div class="list-empty">
          <span class="list-empty-icon bg-${m.color} text-white"><i class="${m.icon}"></i></span>
          <div class="fw-semibold mt-2">Nothing recorded yet</div>
        </div>`;
      return;
    }
    el.innerHTML = "";
    chart = UI.renderTrendChart("metricTrendChart", {
      categories: rows.map(UI.shortPeriodLabel),
      series: [{ name: m.label, data: values }],
      type: m.flow ? "column" : "area",
      colors: [UI.cssColor(m.color)],
    });
  }

  function renderTable(m, rows) {
    if (window.$ && $.fn.DataTable && $.fn.DataTable.isDataTable("#metricTable")) $("#metricTable").DataTable().destroy();
    const newestFirst = [...rows].reverse();
    document.getElementById("metricTableBody").innerHTML = newestFirst.length
      ? newestFirst
          .map((r, i) => {
            const v = Number(m.get(r));
            const older = newestFirst[i + 1];
            return `
              <tr>
                <td class="fw-semibold" data-order="${rows.length - i}">${UI.demographicPeriodLabel(r)}</td>
                <td>${r.fiscal_year?.year || "-"}</td>
                <td class="text-end fw-bold" data-order="${v}">${v.toLocaleString()}</td>
                <td class="text-end">${older ? UI.changePill(v, Number(m.get(older))) || '<span class="text-muted">No change</span>' : '<span class="text-muted">-</span>'}</td>
              </tr>`;
          })
          .join("")
      : UI.renderTableEmpty(4, "Nothing recorded yet", "ri-table-line");

    UI.renderFilterToolbar("metricFilterToolbar", { searchPlaceholder: "Search periods..." });
    const table = UI.initListDataTable("metricTable", { order: [[0, "desc"]], nonSortableColumns: [3], hideDefaultSearch: true, noun: "periods" });
    UI.wireFilterToolbar("metricFilterToolbar", table, [], { noun: "periods", urlSync: false });
  }

  return { init };
})();

window.DemographicsMetric = DemographicsMetric;
