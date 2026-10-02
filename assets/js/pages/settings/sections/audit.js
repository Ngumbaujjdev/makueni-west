/**
 * SETTINGS - Audit log (diocese, global admins): every settings change at
 * every church, region and the diocese - who, when, where and what
 * (GET /settings/audit, newest first). Filtering and paging happen in
 * place with the shared list helpers; nothing reloads the page.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;

  const EVENTS = { team: "People", maintenance: "Tool run", updated: "Changed" };
  const PLACE_COLOURS = { church: "primary", region: "purple", diocese: "pink", system: "secondary" };

  let root = null;

  const when = (iso) => {
    const d = new Date(iso);
    return {
      day: d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }),
      time: d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }),
      iso: iso.slice(0, 10),
    };
  };

  const initials = (name) =>
    String(name || "?")
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((w) => w[0].toUpperCase())
      .join("");

  function value(v, kind) {
    return v === null || v === undefined
      ? `<span class="soft-chip soft-secondary fst-italic">empty</span>`
      : `<span class="soft-chip soft-${kind} audit-value">${esc(v)}</span>`;
  }

  function changes(list) {
    return `<ul class="list-unstyled mb-0 audit-changes">${list
      .map(
        (c) => `<li>
          <span class="fw-semibold">${esc(c.label)}</span>
          ${c.old === null && c.new !== null ? "" : `${value(c.old, "danger")}<i class="ri-arrow-right-line mx-1"></i>`}${value(c.new, "success")}
        </li>`,
      )
      .join("")}</ul>`;
  }

  function row(r) {
    const t = when(r.at);
    const placeColour = PLACE_COLOURS[r.place.type] || "primary";
    const by = r.by?.name || "The system";
    return `
      <tr data-row-id="${r.id}" data-date="${t.iso}">
        <td data-order="${esc(r.at)}" data-search="${esc(t.day)}">
          <div class="fw-semibold">${esc(t.day)}</div>
          <div class="fs-12">${esc(t.time)}</div>
        </td>
        <td data-search="${esc(by)}">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm avatar-rounded bg-primary text-white flex-shrink-0">${esc(initials(by))}</span>
            <span class="fw-semibold">${esc(by)}</span>
          </div>
        </td>
        <td data-search="${esc(r.place.name)}">
          <span class="soft-chip soft-${placeColour}"><i class="${r.place.type === "system" ? "ri-server-line" : "ri-map-pin-2-line"} me-1"></i>${esc(r.place.name)}</span>
        </td>
        <td data-search="${esc(r.section.label)}">
          ${UI.pill(esc(r.section.label), r.section.colour, r.section.icon)}
          <div class="fs-12 mt-1 fw-semibold">${esc(EVENTS[r.event] || "Changed")}</div>
        </td>
        <td>${changes(r.changes)}</td>
      </tr>`;
  }

  function kpis(rows) {
    const series = UI.monthlySeries(rows, { dateField: "at", months: 6 });
    const thisMonth = series.data[series.data.length - 1];
    const lastMonth = series.data[series.data.length - 2];
    const monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    const recent = rows.filter((r) => new Date(r.at) >= monthStart);
    const people = new Set(recent.map((r) => r.by?.id).filter(Boolean)).size;
    const places = new Set(recent.map((r) => r.place.id ?? "system")).size;
    const latest = rows[0];
    return [
      UI.renderSparkCard({ icon: "ri-history-line", label: "Changes this month", value: String(thisMonth), color: "pink", delta: UI.periodDelta(thisMonth, lastMonth), series }),
      UI.renderSparkCard({ icon: "ri-user-star-line", label: "People who changed things", value: String(people), color: "primary", sub: "This month" }),
      UI.renderSparkCard({ icon: "ri-map-pin-2-line", label: "Places changed", value: String(places), color: "purple", sub: "This month, incl. the system" }),
      UI.renderSparkCard({
        icon: "ri-time-line",
        label: "Last change",
        value: latest ? when(latest.at).day : "None yet",
        color: "success",
        sub: latest ? `${latest.by?.name || "The system"} · ${latest.section.label}` : "Nothing has been changed",
      }),
    ];
  }

  function draw(data) {
    const rows = data.rows;
    const opts = (list, colour) => list.map((o) => ({ value: o.label, label: o.label, color: colour }));
    root.innerHTML = `
      <div class="row">${kpis(rows)
        .map((k) => `<div class="col-xxl-3 col-md-6">${k}</div>`)
        .join("")}</div>
      ${F.card({
        id: "card-changes",
        title: "Changes",
        icon: "ri-history-line",
        colour: "pink",
        sub: rows.length >= data.limit ? `The latest ${data.limit} changes.` : "Every settings change, newest first.",
        body: `
          <div id="auditFilterToolbar"></div>
          <div class="table-responsive">
            <table class="table text-nowrap align-middle mb-0 audit-table" id="auditTable">
              <thead><tr><th>When</th><th>Who</th><th>Where</th><th>Section</th><th class="all">What changed</th></tr></thead>
              <tbody>${rows.length ? rows.map(row).join("") : UI.renderTableEmpty(5, "No settings have been changed yet", "ri-history-line")}</tbody>
            </table>
          </div>`,
      })}`;
    UI.mountSparklines(root);

    UI.renderFilterToolbar("auditFilterToolbar", {
      searchPlaceholder: "Search changes…",
      filters: [
        { id: "auditSectionFilter", label: "All sections", options: opts(data.filters.sections, "pink") },
        { id: "auditPlaceFilter", label: "All places", options: [{ value: "System", label: "System", color: "secondary" }, ...opts(data.filters.places, "primary")] },
        { id: "auditPersonFilter", label: "Everyone", options: opts(data.filters.people, "purple") },
      ],
      dateRange: true,
    });
    const table = rows.length ? UI.initListDataTable("auditTable", { order: [[0, "desc"]], nonSortableColumns: [4], hideDefaultSearch: true, noun: "changes", pageLength: 25 }) : null;
    UI.wireFilterToolbar(
      "auditFilterToolbar",
      table,
      [
        { id: "auditSectionFilter", columnIndex: 3, exact: true },
        { id: "auditPlaceFilter", columnIndex: 2, exact: true },
        { id: "auditPersonFilter", columnIndex: 1, exact: true },
      ],
      { noun: "changes" },
    );
    SettingsHub.subLinks([{ id: "card-changes", label: "Changes" }]);
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.audit = {
    async render(body) {
      root = body;
      const res = await SettingsAPI.audit();
      if (!res.ok) {
        body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
        return;
      }
      draw(res.data);
    },
  };
})();
