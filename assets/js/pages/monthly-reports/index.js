/**
 * ============================================================================
 * MONTHLY REPORTS - the list (church, region, diocese)
 * ============================================================================
 * Our reports (church and region): the year at a glance - twelve month tiles
 * coloured by state - with KPI cards and "Write {month}'s report". The
 * reports below (region and diocese): a month picker, KPI cards, "What we
 * noticed", tiles per subregion or region that filter the table. Tab, year
 * and month stay in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const R = ReportsUI;
  const CTX = window.REPORTS_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const now = new Date();
  const lastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  const state = {
    tab: CTX.reports ? (params.get("tab") === "below" && CTX.can.below ? "below" : "ours") : "below",
    year: Number(params.get("year")) || now.getFullYear(),
    period: /^\d{4}-\d{2}$/.test(params.get("period") || "") ? params.get("period") : `${lastMonth.getFullYear()}-${String(lastMonth.getMonth() + 1).padStart(2, "0")}`,
    group: params.get("group") || "",
  };
  let ours = null;
  let below = null;
  let table = null;

  function syncUrl() {
    const q = new URLSearchParams(window.location.search);
    const set = (k, v, dflt) => (v && String(v) !== String(dflt) ? q.set(k, v) : q.delete(k));
    set("tab", state.tab, CTX.reports ? "ours" : "below");
    set("year", state.tab === "ours" ? state.year : "", now.getFullYear());
    set("period", state.tab === "below" ? state.period : "", "");
    set("group", state.tab === "below" ? state.group : "", "");
    const qs = q.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
  }

  // ================================================================ our reports
  function renderYearSwitch() {
    const years = [now.getFullYear() - 1, now.getFullYear()];
    if (!years.includes(state.year)) years.unshift(state.year);
    $("periodSwitchWrap").innerHTML = UI.renderSegmented("yearSwitch", years.map((y) => ({ value: y, label: y })), state.year, { ariaLabel: "Year" });
    UI.wireSegmented("yearSwitch", (y) => {
      state.year = Number(y);
      syncUrl();
      loadOurs();
    });
  }

  async function loadOurs() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4);
    const res = await ReportsAPI.year(state.year);
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12"><div class="alert alert-danger">${R.esc(res.message)}</div></div>`;
      return;
    }
    ours = res.data;
    renderOurs();
  }

  function renderOurs() {
    const d = ours;
    const f = d.figures;
    const next = f.next;
    const cards = [
      { icon: "ri-checkbox-circle-line", label: "Sent on time", value: R.num(f.on_time), color: "success", sub: `${R.num(f.sent)} sent in ${d.year}` },
      { icon: "ri-alarm-warning-line", label: "Late", value: R.num(f.late), color: "danger", sub: f.late ? "Sent after the due day, or not yet sent" : "None - well done" },
      next
        ? { icon: "ri-calendar-event-line", label: `${next.label.split(" ")[0]}'s report`, value: next.due_in_days === 0 ? "Due today" : `Due in ${next.due_in_days} days`, color: "primary", sub: `Due on ${R.longDate(next.due_on)}${next.status === "draft" ? " · started" : ""}` }
        : { icon: "ri-calendar-check-line", label: "Next report", value: "Nothing due", color: "primary", sub: "Every month so far is sent" },
      { icon: "ri-chat-3-line", label: "Comments", value: R.num(f.comments), color: "purple", sub: `${R.num(f.seen)} ${f.seen === 1 ? "report" : "reports"} read above` },
    ];
    $("statCardsRow").innerHTML = cards.map((c) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
    const figure = document.querySelector('[data-tab-figure="ours"]');
    if (figure) figure.textContent = `${f.sent} sent in ${d.year}`;

    $("yearSub").textContent = `Due on day ${d.due_day} of the next month${d.reports_to ? "" : ""}`;
    const legend = [
      ["success", "Sent"],
      ["purple", "Seen"],
      ["secondary", "Started"],
      ["danger", "Late"],
    ];
    $("yearLegend").innerHTML = legend.map(([c, l]) => `<span class="soft-chip soft-${c}"><span class="mr-dot bg-${c}"></span>${l}</span>`).join("");
    $("yearTiles").innerHTML = d.months
      .map((m) => {
        const s = R.stateOf(m);
        const sub =
          m.status === "sent" || m.status === "seen"
            ? `Sent ${R.shortDate(m.sent_at)}`
            : !m.open
              ? `Due ${R.shortDate(m.due_on)}`
              : m.late
                ? `Was due ${R.shortDate(m.due_on)}`
                : `Due ${R.shortDate(m.due_on)}`;
        const inner = `
          <span class="mr-tile-month">${m.short}</span>
          <span class="mr-tile-state"><i class="${s.icon}"></i>${s.label}</span>
          <span class="mr-tile-sub">${sub}${m.comments ? ` · <i class="ri-chat-3-line"></i>${m.comments}` : ""}</span>`;
        return m.open && m.status !== "not_tracked" ? `<a class="mr-tile is-${s.key}" href="${R.ownUrl(CTX.baseUrl, m.year, m.month)}">${inner}</a>` : `<div class="mr-tile is-${s.key}">${inner}</div>`;
      })
      .join("");

    const write = $("writeBtn");
    const target = next || d.months.filter((m) => m.open && ["draft", "not_started"].includes(m.status)).pop();
    if (CTX.can.write && target) {
      write.href = R.ownUrl(CTX.baseUrl, target.year, target.month);
      write.innerHTML = `<i class="ri-edit-2-line me-1"></i>${target.status === "draft" ? "Continue" : "Write"} ${target.label.split(" ")[0]}'s report`;
      write.classList.remove("d-none");
    } else {
      write.classList.add("d-none");
    }
  }

  // ================================================================ the places below
  function renderMonthPicker() {
    const options = [];
    for (let i = 1; i <= 15; i++) {
      const d = new Date(now.getFullYear(), now.getMonth() - i + 1, 1);
      const v = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
      options.push(`<option value="${v}"${v === state.period ? " selected" : ""}>${d.toLocaleDateString("en-GB", { month: "long", year: "numeric" })}</option>`);
    }
    $("periodSwitchWrap").innerHTML = `<select class="form-select" id="monthPick" aria-label="Month" style="min-width: 11rem">${options.join("")}</select>`;
    UI.enhanceSelect("monthPick", { search: false });
    $("monthPick").addEventListener("change", () => {
      state.period = $("monthPick").value;
      state.group = "";
      syncUrl();
      loadBelow();
    });
  }

  async function loadBelow() {
    $("belowCardsRow").innerHTML = UI.skeletonCards(4);
    const [y, m] = state.period.split("-").map(Number);
    const exp = $("exportStatusBtn");
    exp.dataset.year = y;
    exp.dataset.month = m;
    const res = await ReportsAPI.below(y, m);
    if (!res.ok) {
      $("belowCardsRow").innerHTML = `<div class="col-12"><div class="alert alert-danger">${R.esc(res.message)}</div></div>`;
      return;
    }
    below = res.data;
    renderBelow();
  }

  function renderBelow() {
    const d = below;
    const c = d.counts;
    const words = CTX.level === "diocese" ? "places" : "churches";
    const cards = [
      { icon: "ri-send-plane-line", label: "Sent", value: `${c.sent} of ${c.places}`, color: "success", sub: `${d.label} reports` },
      { icon: "ri-mail-unread-line", label: "Waiting to be read", value: R.num(c.waiting), color: "primary", sub: CTX.can.review ? "Open one to read it and mark it as seen" : "Sent, not yet seen" },
      { icon: "ri-alarm-warning-line", label: "Late", value: R.num(c.late), color: "danger", sub: c.late ? "Past the due day, not sent" : "None late" },
      { icon: "ri-draft-line", label: "Not started", value: R.num(c.not_started), color: "secondary", sub: c.drafts ? `${c.drafts} more started, not sent` : "No drafts waiting" },
    ];
    $("belowCardsRow").innerHTML = cards.map((x) => `<div class="col-xl-3 col-lg-6 col-md-6">${UI.renderSparkCard(x)}</div>`).join("");
    const figure = document.querySelector('[data-tab-figure="below"]');
    if (figure) figure.textContent = `${c.sent} of ${c.places} sent for ${d.label.split(" ")[0]}`;
    const late = d.rows.filter((r) => r.late);
    const month = d.label.split(" ")[0];
    const remind = late.length && CTX.can.message
      ? `<a class="btn btn-sm btn-danger ms-auto flex-shrink-0" href="${CTX.siteUrl}/${CTX.level}/messages/new?${new URLSearchParams({
          places: late.map((r) => r.place.id).join(","),
          roles: "Senior Pastor,Regional Overseer",
          levels: "region,church",
          channel: "sms",
          subject: `${month}'s monthly report`,
          body: `Dear {name}, ${month}'s monthly report for {place} hasn't been sent yet. It only takes a few minutes - the figures are filled in. Thank you. - {sender}`,
        })}"><i class="ri-chat-3-line me-1"></i>Remind them</a>`
      : "";
    $("noticed").innerHTML = d.noticed.length
      ? `<div class="mr-noticed">${d.noticed.map((n) => `<div class="alert alert-${n.tone === "danger" ? "danger" : n.tone === "success" ? "success" : "primary"} d-flex align-items-center gap-2 mb-2"><i class="${n.tone === "danger" ? "ri-alarm-warning-line" : n.tone === "success" ? "ri-checkbox-circle-line" : "ri-mail-unread-line"} fs-16"></i><span>${R.esc(n.text)}</span>${n.tone === "danger" ? remind : ""}</div>`).join("")}</div>`
      : "";

    const named = d.groups.filter((g) => g.name);
    $("groupTiles").innerHTML = named.length
      ? named
          .map(
            (g) => `
        <button type="button" class="mr-group${state.group === g.name ? " is-on" : ""}" data-group="${R.esc(g.name)}">
          <span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(g.name)} text-white"><i class="ri-map-2-line"></i></span>
          <span class="flex-fill text-start"><strong>${R.esc(g.name)}</strong><small>${g.sent} of ${g.places} sent${g.late ? ` · ${g.late} late` : ""}</small></span>
          <span class="mr-group-bar"><i class="bg-success" style="width:${g.places ? Math.round((g.sent / g.places) * 100) : 0}%"></i></span>
        </button>`,
          )
          .join("")
      : "";
    $("groupTiles").querySelectorAll("[data-group]").forEach((b) =>
      b.addEventListener("click", () => {
        state.group = state.group === b.dataset.group ? "" : b.dataset.group;
        const sel = $("fGroup");
        if (sel) {
          sel.value = state.group;
          UI.syncSelect(sel);
          sel.dispatchEvent(new Event("change"));
        }
        $("groupTiles").querySelectorAll("[data-group]").forEach((x) => x.classList.toggle("is-on", x.dataset.group === state.group));
        syncUrl();
      }),
    );

    $("belowTitle").textContent = `${d.label} - ${CTX.level === "diocese" ? "regions and churches" : "our churches"}`;
    $("groupHead").textContent = CTX.level === "diocese" ? "Region" : "Subregion";
    const tbody = $("belowTable").tBodies[0];
    tbody.innerHTML = d.rows.length
      ? d.rows
          .map((r) => {
            const s = R.stateOf(r);
            const open = r.id && ["sent", "seen"].includes(r.status);
            return `<tr data-row-id="${r.place.id}">
              <td><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${r.place.type === "region" ? "purple" : "success"} text-white"><i class="${r.place.type === "region" ? "ri-map-2-line" : "ri-home-heart-line"}"></i></span><span class="fw-semibold">${R.esc(r.place.name)}</span></div></td>
              <td>${R.esc(r.group || (r.place.type === "region" ? "Regions" : "-"))}</td>
              <td data-order="${R.rank(r)}">${R.statePill(r)}</td>
              <td data-order="${r.sent_at || ""}">${r.sent_at ? R.shortDate(r.sent_at) : "-"}</td>
              <td class="text-end">${R.num(r.key?.average_sunday)}</td>
              <td class="text-end">${R.money(r.key?.income)}</td>
              <td class="text-end">${r.comments ? `<span class="soft-chip soft-purple"><i class="ri-chat-3-line"></i>${r.comments}</span>` : "-"}</td>
              <td class="text-end">${open ? `<a class="btn btn-sm ${r.status === "sent" && CTX.can.review ? "btn-primary" : "btn-outline-primary"}" href="${R.idUrl(CTX.baseUrl, r.id)}">${r.status === "sent" && CTX.can.review ? "Read" : "Open"}</a>` : ""}</td>
            </tr>`;
          })
          .join("")
      : `<tr><td colspan="8" class="text-center py-5 fw-semibold">No ${words} below.</td></tr>`;

    const groups = [...new Set(d.rows.map((r) => r.group || (r.place.type === "region" ? "Regions" : "-")))].filter((g) => g !== "-");
    const statuses = ["Sent", "Sent late", "Seen", "Started", "Late", "Late · started", "Not started", "Before reports"].filter((l) => d.rows.some((r) => R.stateOf(r).label === l));
    const filters = [
      { id: "fGroup", label: CTX.level === "diocese" ? "Any region" : "Any subregion", columnIndex: 1, exact: true, options: groups.map((g) => ({ value: g, label: g })) },
      { id: "fStatus", label: "Any status", columnIndex: 2, exact: true, options: statuses.map((s) => ({ value: s, label: s })) },
    ];
    UI.renderFilterToolbar("belowFilters", { searchPlaceholder: `Search ${words}...`, filters });
    table = UI.initListDataTable("belowTable", { hideDefaultSearch: true, order: [[2, "asc"]], nonSortableColumns: [7], noun: words, pageLength: 25 });
    UI.wireFilterToolbar("belowFilters", table, filters, { noun: words, urlSync: false });
    if (state.group && $("fGroup")) {
      $("fGroup").value = state.group;
      UI.syncSelect($("fGroup"));
      $("fGroup").dispatchEvent(new Event("change"));
    }
    $("fGroup")?.addEventListener("change", () => {
      state.group = $("fGroup").value;
      $("groupTiles").querySelectorAll("[data-group]").forEach((x) => x.classList.toggle("is-on", x.dataset.group === state.group));
      syncUrl();
    });
  }

  // ================================================================ tabs
  function show(tab) {
    state.tab = tab;
    document.querySelectorAll("#reportTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    document.querySelectorAll(".rp-pane").forEach((p) => (p.hidden = p.dataset.pane !== tab));
    $("exportStatusBtn").classList.toggle("d-none", tab !== "below");
    $("writeBtn").classList.toggle("d-none", tab !== "ours" || !ours || !CTX.can.write);
    syncUrl();
    if (tab === "ours") {
      renderYearSwitch();
      if (ours) renderOurs();
      else loadOurs();
    } else {
      renderMonthPicker();
      if (below) renderBelow();
      else loadBelow();
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("#reportTabs [data-tab]").forEach((b) => b.addEventListener("click", () => b.dataset.tab !== state.tab && show(b.dataset.tab)));
    if (!CTX.reports && !CTX.can.below) {
      document.querySelector(".container-fluid").insertAdjacentHTML("beforeend", R.empty("ri-lock-line", "Nothing to show here", "Your role can't see monthly reports at this level."));
      return;
    }
    show(state.tab);
  });
})();
