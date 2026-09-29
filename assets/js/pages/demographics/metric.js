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
 * Metrics recorded by gender (DEMOGRAPHIC_METRICS[key].parts - Total members
 * as male/female, Sunday school as boys/girls) get a card per part, a
 * stacked column chart and a column per part in the table.
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
      <select class="form-select" id="metricSwitch" aria-label="Metric">
        ${Object.entries(M)
          .map(([k, m]) => `<option value="${k}" data-icon="${m.icon}" data-color="${m.color}"${k === key ? " selected" : ""}>${m.label}</option>`)
          .join("")}
      </select>`;
    UI.enhanceSelect("metricSwitch", { search: true });
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

  const partValue = (r, part) => (r[part.key] == null ? null : Number(r[part.key]));

  function renderKpis(m, rows, values) {
    if (!rows.length) {
      document.getElementById("statCardsRow").innerHTML = "";
      return;
    }
    const latestRow = rows[rows.length - 1];
    const prevRow = rows.length > 1 ? rows[rows.length - 2] : null;
    const latest = values[values.length - 1];
    const prev = prevRow ? values[values.length - 2] : null;
    const prevLabel = prevRow ? UI.shortPeriodLabel(prevRow) : "";
    const hi = values.indexOf(Math.max(...values));
    const lo = values.indexOf(Math.min(...values));
    const sum = values.reduce((a, b) => a + b, 0);
    const labels = rows.map(UI.shortPeriodLabel);

    const latestCard = {
      icon: m.icon,
      label: "Latest",
      value: latest.toLocaleString(),
      color: m.color,
      delta: prev != null ? UI.periodDelta(latest, prev, { prevLabel }) : null,
      sub: UI.demographicPeriodLabel(latestRow),
      series: rows.length > 1 ? { labels, data: values } : null,
    };

    if (m.parts) {
      const [a, b] = m.parts;
      const va = partValue(latestRow, a) || 0;
      const vb = partValue(latestRow, b) || 0;
      const share = va + vb ? Math.round((vb / (va + vb)) * 100) : null;
      const partCard = (part, v) => {
        const pv = prevRow ? partValue(prevRow, part) : null;
        return {
          icon: part.icon,
          label: part.label,
          value: v.toLocaleString(),
          color: part.color,
          delta: pv != null ? UI.periodDelta(v, pv, { prevLabel }) : null,
          sub: UI.demographicPeriodLabel(latestRow),
          series: rows.length > 1 ? { labels, data: rows.map((r) => partValue(r, part) || 0) } : null,
        };
      };
      UI.renderStatCardsRow("statCardsRow", [
        latestCard,
        partCard(a, va),
        partCard(b, vb),
        {
          icon: "ri-scales-3-line",
          label: `${a.label} / ${b.label}`,
          value: share == null ? "-" : `${100 - share}% / ${share}%`,
          color: "purple",
          sub: va + vb !== latest ? `${(va + vb).toLocaleString()} split of ${latest.toLocaleString()}` : "of the latest total",
        },
      ]);
      return;
    }

    UI.renderStatCardsRow("statCardsRow", [
      latestCard,
      { icon: "ri-arrow-up-circle-line", label: "Highest", value: values[hi].toLocaleString(), color: "success", sub: UI.demographicPeriodLabel(rows[hi]) },
      { icon: "ri-arrow-down-circle-line", label: "Lowest", value: values[lo].toLocaleString(), color: "danger", sub: UI.demographicPeriodLabel(rows[lo]) },
      m.flow
        ? { icon: "ri-add-circle-line", label: "Total recorded", value: sum.toLocaleString(), color: "purple", sub: "across all submissions" }
        : { icon: "ri-bar-chart-line", label: "Average", value: Math.round(sum / values.length).toLocaleString(), color: "purple", sub: "per submission" },
    ]);
  }

  function renderChart(m, rows, values) {
    const el = document.getElementById("metricTrendChart");
    const chipsEl = document.getElementById("metricChips");
    if (chart) chart.destroy();
    chart = null;
    chipsEl.innerHTML = "";
    document.getElementById("trendSubtitle").textContent = m.parts
      ? `${m.parts[0].label} and ${m.parts[1].label.toLowerCase()} at each approved submission`
      : m.flow
        ? "Recorded in each period"
        : "Headcount at each approved submission";

    if (rows.length === 0) {
      el.innerHTML = `
        <div class="list-empty">
          <span class="list-empty-icon bg-${m.color} text-white"><i class="${m.icon}"></i></span>
          <div class="fw-semibold mt-2">Nothing recorded yet</div>
        </div>`;
      return;
    }
    el.innerHTML = "";

    if (!m.parts) {
      chart = UI.renderTrendChart("metricTrendChart", {
        categories: rows.map(UI.shortPeriodLabel),
        series: [{ name: m.label, data: values }],
        type: m.flow ? "column" : "area",
        colors: [UI.cssColor(m.color)],
      });
      return;
    }

    const partSeries = m.parts.map((p) => ({ name: p.label, data: rows.map((r) => partValue(r, p) || 0) }));
    chart = new ApexCharts(el, {
      chart: { type: "bar", stacked: true, height: 320, toolbar: { show: false }, foreColor: UI.chartTextColor(), animations: { enabled: !document.documentElement.classList.contains("app-reduce-motion") } },
      series: partSeries,
      colors: m.parts.map((p) => UI.cssColor(p.color)),
      xaxis: { categories: rows.map(UI.shortPeriodLabel), labels: { rotate: 0, hideOverlappingLabels: true } },
      yaxis: { forceNiceScale: true, labels: { formatter: (v) => Math.round(v).toLocaleString() } },
      plotOptions: { bar: { columnWidth: rows.length > 6 ? "45%" : "32%", borderRadius: 4 } },
      dataLabels: { enabled: false },
      legend: { show: false },
      grid: { borderColor: "rgba(125,125,125,0.15)", strokeDashArray: 4 },
      tooltip: {
        shared: true,
        intersect: false,
        y: { formatter: (v) => (v == null ? "-" : v.toLocaleString()) },
      },
      responsive: [{ breakpoint: 576, options: { chart: { height: 260 } } }],
    });
    chart.render();

    // Toggle chips + the share line, where a lopsided split shows up.
    const latest = rows[rows.length - 1];
    const [a, b] = m.parts;
    const va = partValue(latest, a) || 0;
    const vb = partValue(latest, b) || 0;
    const shareB = va + vb ? Math.round((vb / (va + vb)) * 100) : null;
    const lopsided = shareB != null && (shareB < 40 || shareB > 60);
    chipsEl.innerHTML = `
      ${m.parts
        .map(
          (p) =>
            `<button type="button" class="soft-chip soft-${p.color === "info" ? "primary" : p.color} chip-toggle is-on" data-series="${p.label}" aria-pressed="true"><span class="count-dot bg-${p.color}"></span>${p.label}</button>`,
        )
        .join("")}
      ${shareB == null ? "" : `<span class="soft-chip soft-${lopsided ? "secondary" : "success"} ms-auto"><i class="ri-scales-3-line"></i>${b.label} ${shareB}% of ${UI.shortPeriodLabel(latest)}${lopsided ? " · uneven split" : ""}</span>`}`;
    chipsEl.querySelectorAll(".chip-toggle").forEach((btn) =>
      btn.addEventListener("click", () => {
        chart.toggleSeries(btn.dataset.series);
        const on = !btn.classList.contains("is-on");
        btn.classList.toggle("is-on", on);
        btn.setAttribute("aria-pressed", on);
      }),
    );
  }

  function renderTable(m, rows) {
    if (window.$ && $.fn.DataTable && $.fn.DataTable.isDataTable("#metricTable")) $("#metricTable").DataTable().destroy();
    const parts = m.parts || [];
    // Columns change with the metric, so the header is rebuilt too.
    document.getElementById("metricTableHead").innerHTML = `
      <tr>
        <th>Period</th>
        <th>Year</th>
        ${parts.map((p) => `<th class="text-end">${p.label}</th>`).join("")}
        <th class="text-end">${parts.length ? "Total" : "Value"}</th>
        <th class="text-end">Change</th>
      </tr>`;
    const colCount = 4 + parts.length;

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
                ${parts
                  .map((p) => {
                    const pv = partValue(r, p);
                    const po = older ? partValue(older, p) : null;
                    return pv == null
                      ? '<td class="text-end text-muted" data-order="-1">-</td>'
                      : `<td class="text-end" data-order="${pv}">${pv.toLocaleString()}${po != null ? UI.changePill(pv, po) : ""}</td>`;
                  })
                  .join("")}
                <td class="text-end fw-bold" data-order="${v}">${v.toLocaleString()}</td>
                <td class="text-end">${older ? UI.changePill(v, Number(m.get(older))) || '<span class="text-muted">No change</span>' : '<span class="text-muted">-</span>'}</td>
              </tr>`;
          })
          .join("")
      : UI.renderTableEmpty(colCount, "Nothing recorded yet", "ri-table-line");

    UI.renderFilterToolbar("metricFilterToolbar", { searchPlaceholder: "Search periods..." });
    const table = UI.initListDataTable("metricTable", { order: [[0, "desc"]], nonSortableColumns: [colCount - 1], hideDefaultSearch: true, noun: "periods" });
    UI.wireFilterToolbar("metricFilterToolbar", table, [], { noun: "periods", urlSync: false });
  }

  return { init };
})();

window.DemographicsMetric = DemographicsMetric;
