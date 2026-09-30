/**
 * ============================================================================
 * PAGE - ATTENDANCE RECORD (church/attendance/record.php?id=)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * One recorded Sunday or meeting: who came (the four groups, the total and
 * the split), how it compares - with last time, the usual, the best and,
 * for a Sunday, the members on the roll - the last few meetings as a small
 * chart, its notes and its history (who recorded it, every change since).
 * Previous / Next step through meetings of the same gathering; for a
 * Sunday they're the Sundays either side, and a missed one offers Record.
 * Edit opens the shared entry form and the page refreshes in place.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, AttendanceFormShared,
 * Toast, ApexCharts, jQuery + Select2
 * ============================================================================
 */

const AttendanceRecord = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AttendanceFormShared;
  const LIST_FOR = {
    sunday_service: { href: "services", label: "Sunday Services" },
    ministry_gathering: { href: "ministries", label: "Ministry Gatherings" },
    special_event: { href: "events", label: "Special Events" },
  };

  let id = null;
  let d = null;
  let chart = null;
  const base = () => `${AppConfig.FRONTEND_BASE_URL}/church/attendance`;
  const fmt = (iso, opts = { weekday: "long", day: "numeric", month: "short", year: "numeric" }) => A.formatDate(iso, opts);

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    id = Number(new URLSearchParams(window.location.search).get("id"));
    if (!id) {
      showMissing("No record was chosen.");
      return;
    }
    await load();
  }

  async function load() {
    const res = await DemographicsAPIHandler.getAttendanceRecord(id);
    if (!res.success) {
      showMissing(res.status === 403 ? "This record belongs to another church." : "This record couldn't be found - it may have been removed.");
      return;
    }
    d = res.data;
    render();
    loadHistory();
  }

  /** After a delete: say so, with Undo and the way back - the record is gone from every list. */
  function showDeleted() {
    const list = LIST_FOR[d.record.gathering_category?.slug || "sunday_service"];
    document.getElementById("recordActions").innerHTML = "";
    document.getElementById("recordBody").innerHTML = `
      <div class="card custom-card"><div class="card-body">
        <div class="list-empty">
          <span class="list-empty-icon bg-danger text-white"><i class="ri-delete-bin-line"></i></span>
          <div class="fw-semibold mt-2">This record was deleted</div>
          <div class="fs-12">It no longer counts in any total, chart or report.</div>
          <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
            <button type="button" class="btn btn-light btn-sm" id="recordUndo"><i class="ri-arrow-go-back-line me-1"></i>Undo</button>
            <a href="${base()}/${list.href}" class="btn btn-primary btn-sm">${list.label}</a>
          </div>
        </div>
      </div></div>`;
    document.getElementById("recordUndo").addEventListener("click", async () => {
      const back = await DemographicsAPIHandler.restoreAttendance(id);
      if (!back.success) {
        Toast.error(back.message || "Couldn't put the record back");
        return;
      }
      window.location.reload();
    });
  }

  function showMissing(text) {
    document.getElementById("recordBody").innerHTML = `
      <div class="card custom-card"><div class="card-body">
        <div class="list-empty">
          <span class="list-empty-icon bg-danger text-white"><i class="ri-file-search-line"></i></span>
          <div class="fw-semibold mt-2">${A.escapeHtml(text)}</div>
          <a href="${base()}" class="btn btn-primary btn-sm mt-3">Back to Attendance</a>
        </div>
      </div></div>`;
    document.getElementById("recordActions").innerHTML = "";
  }

  // ==========================================================================
  // RENDER
  // ==========================================================================

  function render() {
    const r = d.record;
    const slug = r.gathering_category?.slug || "sunday_service";
    const list = LIST_FOR[slug];
    const iso = A.recordIso(r);
    const icon = d.is_sunday ? "ri-sun-line" : r.gathering_type?.icon || r.gathering_category?.icon || "ri-group-line";
    const canWrite = !!(CAN_WRITE_ATTENDANCE || {})[slug];
    document.title = `${d.name}, ${fmt(iso, { day: "numeric", month: "short", year: "numeric" })} - Makueni West Diocese`;

    // Header
    document.getElementById("recordTitleIcon").innerHTML = UI.avatarTile(A.escapeHtml(icon), d.is_sunday ? "primary" : UI.colorFor(d.name));
    const gatheringLink = !d.is_sunday
      ? `<a href="${base()}/gathering?${r.gathering_type_id ? `type=${r.gathering_type_id}` : `name=${encodeURIComponent(d.name)}`}">${A.escapeHtml(d.name)}</a>`
      : A.escapeHtml(d.name);
    document.getElementById("recordTitle").innerHTML = `${gatheringLink} <span>${fmt(iso)}</span>`;
    const who = [d.recorded_by ? `Recorded by ${A.escapeHtml(d.recorded_by)}` : "", d.updated_by ? `last changed by ${A.escapeHtml(d.updated_by)}` : ""].filter(Boolean).join(", ");
    document.getElementById("recordSub").innerHTML = `${UI.pill("Recorded", "success", "ri-checkbox-circle-fill")} <span>${who}</span>`;
    document.getElementById("recordBack").href = `${base()}/${list.href}`;
    document.getElementById("recordBack").innerHTML = `<i class="ri-arrow-left-line me-1"></i>${list.label}`;

    renderNav(canWrite);
    const editBtn = document.getElementById("recordEdit");
    editBtn.hidden = !canWrite;
    editBtn.onclick = () => openEdit(slug);
    const deleteBtn = document.getElementById("recordDelete");
    deleteBtn.hidden = !(CAN_DELETE_ATTENDANCE || {})[slug];
    deleteBtn.onclick = () => A.deleteRecord(d.record, { onDeleted: showDeleted, onRestored: () => window.location.reload() });

    renderCounts();
    renderCompare();
    renderRecent();
    document.getElementById("recordNotes").innerHTML = r.notes
      ? `<p class="record-notes">${A.escapeHtml(r.notes)}</p>`
      : '<div class="att-empty py-3">No notes for this one.</div>';
  }

  /** Previous / Next: the Sundays either side (a missed one offers Record), or this gathering's meetings. */
  function renderNav(canWrite) {
    const side = (dir) => {
      const label = dir === "previous" ? "Previous" : "Next";
      const arrow = dir === "previous" ? "ri-arrow-left-s-line" : "ri-arrow-right-s-line";
      const target = d.is_sunday ? d.sundays?.[dir] : d[dir];
      if (!target) return `<span class="record-nav-btn is-empty">${dir === "previous" ? `<i class="${arrow}"></i>` : ""}<span><small>${label}</small>None${d.is_sunday ? " yet" : ""}</span>${dir === "next" ? `<i class="${arrow}"></i>` : ""}</span>`;
      const date = A.formatDate(target.date, { weekday: "short", day: "numeric", month: "short" });
      if (!target.id) {
        return canWrite
          ? `<button type="button" class="record-nav-btn is-missing" data-record-date="${target.date}">${dir === "previous" ? `<i class="${arrow}"></i>` : ""}<span><small>${label} · not recorded</small>Record ${date}</span>${dir === "next" ? `<i class="${arrow}"></i>` : ""}</button>`
          : `<span class="record-nav-btn is-missing">${dir === "previous" ? `<i class="${arrow}"></i>` : ""}<span><small>${label}</small>${date} · not recorded</span>${dir === "next" ? `<i class="${arrow}"></i>` : ""}</span>`;
      }
      return `<a class="record-nav-btn" href="${base()}/record?id=${target.id}">${dir === "previous" ? `<i class="${arrow}"></i>` : ""}<span><small>${label}</small>${date} · ${target.total.toLocaleString()}</span>${dir === "next" ? `<i class="${arrow}"></i>` : ""}</a>`;
    };
    const nav = document.getElementById("recordNav");
    nav.innerHTML = side("previous") + side("next");
    nav.querySelectorAll("[data-record-date]").forEach((b) => b.addEventListener("click", () => openNew(b.dataset.recordDate)));
  }

  function renderCounts() {
    const r = d.record;
    const total = d.total;
    document.getElementById("recordCounts").innerHTML = `
      <div class="record-total">
        <span class="record-total-value">${total.toLocaleString()}</span>
        <span class="record-total-label">people</span>
      </div>
      <div class="count-bar record-bar" aria-hidden="true">
        ${A.GROUPS.map((g) => `<span class="bg-${g.color}" style="width: ${total ? ((Number(r[g.key]) || 0) / total) * 100 : 0}%"></span>`).join("")}
      </div>
      <div class="row g-2">
        ${A.GROUPS.map((g) => {
          const v = Number(r[g.key]) || 0;
          return `
            <div class="col-6 col-md-3">
              <div class="record-count">
                <span class="record-count-icon bg-${g.color} text-white"><i class="${g.icon}"></i></span>
                <span class="record-count-label">${g.label}</span>
                <span class="record-count-value">${v.toLocaleString()}</span>
                <span class="record-count-share">${total ? Math.round((v / total) * 100) : 0}% of everyone</span>
              </div>
            </div>`;
        }).join("")}
      </div>`;
  }

  function renderCompare() {
    const total = d.total;
    const rows = [];
    const delta = (before, caption) => UI.periodDelta(total, before, { prevLabel: caption });
    const deltaPill = (dl) => (dl ? `<span class="stat-delta is-${dl.dir}">${dl.dir === "up" ? '<i class="ri-arrow-up-s-fill"></i>' : dl.dir === "down" ? '<i class="ri-arrow-down-s-fill"></i>' : ""}${dl.text}</span>` : "");
    if (d.previous) {
      rows.push({ icon: "ri-history-line", color: "primary", label: "Last time", sub: A.formatDate(d.previous.date, { weekday: "short", day: "numeric", month: "short" }), value: d.previous.total, pill: deltaPill(delta(d.previous.total, "last time")) });
    }
    if (d.usual != null) {
      rows.push({ icon: "ri-bar-chart-line", color: "purple", label: "The usual", sub: `Average of the ${d.usual_of} before`, value: d.usual, pill: deltaPill(delta(d.usual, "the usual")) });
    }
    if (d.best && d.of > 1) {
      rows.push({
        icon: "ri-trophy-line",
        color: "secondary",
        label: d.is_best ? "The best yet" : "The best",
        sub: d.is_best ? `The highest of ${d.of}` : `${A.formatDate(d.best.date, { day: "numeric", month: "short", year: "numeric" })} · this one is ${ordinal(d.rank)} of ${d.of}`,
        value: d.best.total,
        pill: d.is_best ? UI.pill("Best", "success", "ri-star-fill") : "",
      });
    }
    if (d.members?.rate != null) {
      rows.push({ icon: "ri-team-line", color: "success", label: "Members who came", sub: `Of ${d.members.total_members.toLocaleString()} members${d.members.as_of ? ` (${A.escapeHtml(d.members.as_of)})` : ""}`, value: `${d.members.rate}%`, pill: "" });
    }
    document.getElementById("recordCompare").innerHTML = rows.length
      ? `<ul class="record-compare">${rows
          .map(
            (x) => `
          <li>
            <span class="avatar avatar-sm bg-${x.color} ${x.color === "secondary" ? "text-dark" : "text-white"}"><i class="${x.icon}"></i></span>
            <span class="record-compare-text"><strong>${x.label}</strong><small>${x.sub}</small></span>
            <span class="record-compare-value">${typeof x.value === "number" ? x.value.toLocaleString() : x.value}${x.pill}</span>
          </li>`,
          )
          .join("")}</ul>`
      : '<div class="att-empty py-3">The first one recorded - nothing to compare with yet.</div>';
  }

  function ordinal(n) {
    const s = ["th", "st", "nd", "rd"];
    const v = n % 100;
    return `${n}${s[(v - 20) % 10] || s[v] || s[0]}`;
  }

  /** The last meetings up to this one, this one in solid colour. */
  function renderRecent() {
    const el = document.getElementById("recordRecent");
    if (chart) chart.destroy();
    chart = null;
    if (d.recent.length < 2) {
      el.innerHTML = '<div class="att-empty">The trend shows once there are a few meetings.</div>';
      return;
    }
    el.innerHTML = "";
    const primary = UI.cssColor("primary");
    chart = new ApexCharts(el, {
      chart: { type: "bar", height: 250, toolbar: { show: false }, foreColor: UI.chartTextColor(), fontFamily: "inherit", events: { dataPointSelection: (e, ctx, cfg) => goTo(d.recent[cfg.dataPointIndex].id) } },
      series: [{ name: "Attended", data: d.recent.map((x) => x.total) }],
      xaxis: { categories: d.recent.map((x) => A.formatDate(x.date, { day: "numeric", month: "short" })), labels: { style: { fontSize: "11px" } } },
      yaxis: { labels: { formatter: (v) => Math.round(v) } },
      colors: d.recent.map((x) => (x.id === d.record.id ? primary : UI.withAlpha(primary, 0.3))),
      plotOptions: { bar: { distributed: true, columnWidth: "55%", borderRadius: 5 } },
      dataLabels: { enabled: true, style: { fontSize: "11px", colors: [UI.chartTextColor()] }, offsetY: -18 },
      legend: { show: false },
      grid: { borderColor: UI.withAlpha(primary, 0.08) },
      tooltip: { y: { formatter: (v) => `${v} attended` } },
    });
    chart.render();
  }

  function goTo(recordId) {
    if (recordId && recordId !== d.record.id) window.location.href = `${base()}/record?id=${recordId}`;
  }

  // ==========================================================================
  // HISTORY
  // ==========================================================================

  async function loadHistory() {
    const el = document.getElementById("recordHistory");
    const res = await DemographicsAPIHandler.getAttendanceAudits(id);
    const audits = res.success ? res.data || [] : [];
    const created = { event: "created", user: d.recorded_by, created_at: d.record.created_at, changes: [] };
    const items = audits.some((a) => a.event === "created") ? audits : [...audits, created];
    el.innerHTML = `<ul class="record-history">${items
      .map((a) => {
        const when = a.created_at ? new Date(a.created_at).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "";
        const changes = a.event === "updated" && a.changes?.length
          ? `<ul class="record-history-changes">${a.changes
              .map((c) => `<li><b>${A.escapeHtml(c.field)}</b> ${c.old == null || c.old === "" ? "-" : A.escapeHtml(c.old)} <i class="ri-arrow-right-line"></i> ${c.new == null || c.new === "" ? "-" : A.escapeHtml(c.new)}</li>`)
              .join("")}</ul>`
          : "";
        const verb = a.event === "created" ? "Recorded" : a.event === "updated" ? "Changed" : a.event;
        return `
          <li class="is-${a.event}">
            <span class="record-history-dot"><i class="${a.event === "created" ? "ri-add-line" : "ri-edit-line"}"></i></span>
            <div>
              <strong>${verb}${a.user ? ` by ${A.escapeHtml(a.user)}` : ""}</strong>
              <small>${when}</small>
              ${changes}
            </div>
          </li>`;
      })
      .join("")}</ul>`;
  }

  // ==========================================================================
  // EDIT / RECORD
  // ==========================================================================

  async function formConfig(slug) {
    const r = d.record;
    const [recs, typesRes] = await Promise.all([
      DemographicsAPIHandler.getAttendance(USER_TERRITORY.id, { gathering_category_id: r.gathering_category_id }),
      d.is_sunday ? Promise.resolve({ success: true, data: [] }) : DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, { gathering_category_id: r.gathering_category_id }),
    ]);
    return {
      gatheringCategoryId: r.gathering_category_id,
      isWeekly: d.is_sunday,
      territoryId: USER_TERRITORY.id,
      records: recs.success ? recs.data || [] : [],
      types: d.is_sunday ? undefined : typesRes.success ? typesRes.data || [] : [],
      categoryLabel: slug === "special_event" ? "Special event" : "Ministry gathering",
    };
  }

  async function openEdit(slug) {
    const config = await formConfig(slug);
    A.openEntryModal({
      ...config,
      record: d.record,
      canDelete: !!(CAN_DELETE_ATTENDANCE || {})[slug],
      onSaved: async () => {
        await load();
        Toast.success("Updated - the page shows the new numbers");
      },
      onDeleted: showDeleted,
      onRestored: () => window.location.reload(),
    });
  }

  async function openNew(date) {
    const slug = d.record.gathering_category?.slug;
    const config = await formConfig(slug);
    A.openEntryModal({
      ...config,
      record: null,
      defaultDate: date,
      defaultTypeId: d.record.gathering_type_id,
      defaultName: d.record.gathering_type_id ? null : d.name,
      onSaved: (saved) => {
        if (saved?.id) window.location.href = `${base()}/record?id=${saved.id}`;
      },
    });
  }

  return { init };
})();

window.AttendanceRecord = AttendanceRecord;
