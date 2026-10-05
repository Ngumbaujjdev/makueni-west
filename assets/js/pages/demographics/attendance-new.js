/**
 * Record Attendance (church/attendance/new.php) - choose what you're
 * recording; the shared entry window (AttendanceFormShared.openEntryModal)
 * then asks when and how many. Below: the last few records.
 */
const AttendanceNew = (function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AttendanceFormShared;

  const KINDS = [
    { slug: "sunday_service", title: "Sunday service", icon: "ri-sun-line", color: "primary", text: "The main Sunday count - adults, youth and children.", go: "Record a Sunday" },
    { slug: "ministry_gathering", title: "Ministry gathering", icon: "ri-group-line", color: "success", text: "Youth, women's, men's or another ministry meeting.", label: "Ministry gathering", go: "Record a ministry gathering" },
    { slug: "special_event", title: "Special event", icon: "ri-star-line", color: "purple", text: "A crusade, kesha, wedding, fundraiser or other one-off.", label: "Special event", go: "Record a special event" },
  ];

  const categories = {};
  const typesBy = {};
  let rows = [];
  let entryMode = null;

  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const ofKind = (slug) => rows.filter((r) => r.gathering_category?.slug === slug);
  const nameOf = (r) => r.gathering_type?.name || r.event_name || "Sunday service";

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }
    const [cats, , mode] = await Promise.all([DemographicsAPIHandler.getGatheringCategories(), loadRows(), DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id)]);
    (cats.success ? cats.data || [] : []).forEach((c) => (categories[c.slug] = c));
    if (mode.success) entryMode = mode.data.attendance_mode;
    await Promise.all(
      ["ministry_gathering", "special_event"]
        .filter((slug) => categories[slug])
        .map(async (slug) => {
          const res = await DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, { gathering_category_id: categories[slug].id });
          typesBy[slug] = res.success ? res.data || [] : [];
        }),
    );
    render();
  }

  async function loadRows() {
    const result = await DemographicsAPIHandler.getAttendance(USER_TERRITORY.id);
    if (!result.success) Toast.error(result.message || "Couldn't load attendance");
    rows = (result.data || []).sort((a, b) => A.recordIso(b).localeCompare(A.recordIso(a)));
  }

  function nextSunday() {
    return A.nextMissingSunday(ofKind("sunday_service")) || A.isoDate(A.sundayOnOrBefore());
  }

  /** The one live fact on each card. */
  function factFor(kind) {
    if (kind.slug === "sunday_service") {
      if (entryMode === "monthly_only") return { text: "Weekly Sundays are off - this church records monthly only", tone: "warning" };
      const missing = A.nextMissingSunday(ofKind("sunday_service"));
      return missing ? { text: `${A.shortDate(missing)} not recorded yet`, tone: "warning" } : { text: "Every recent Sunday is recorded", tone: "success" };
    }
    const last = ofKind(kind.slug)[0];
    return last ? { text: `Last: ${nameOf(last)} · ${A.shortDate(A.recordIso(last))}`, tone: kind.color } : { text: "Nothing recorded yet", tone: "secondary" };
  }

  function render() {
    const allowed = KINDS.filter((k) => CAN_RECORD[k.slug] && categories[k.slug]);
    const box = document.getElementById("recordChoices");
    box.innerHTML = allowed.length
      ? allowed
          .map((k) => {
            const fact = factFor(k);
            const off = k.slug === "sunday_service" && entryMode === "monthly_only";
            return `
            <div class="col-lg-4">
              <button type="button" class="record-choice is-${k.color}" data-kind="${k.slug}" ${off ? "disabled" : ""}>
                <span class="avatar avatar-lg avatar-rounded bg-${k.color} text-white"><i class="${k.icon} fs-20"></i></span>
                <span class="record-choice-title">${k.title}</span>
                <span class="record-choice-text">${k.text}</span>
                <span class="soft-chip soft-${fact.tone}">${esc(fact.text)}</span>
                <span class="record-choice-go">${off ? "Turned off" : k.go}<i class="ri-arrow-right-line ms-1"></i></span>
              </button>
            </div>`;
          })
          .join("")
      : `<div class="col-12"><div class="list-empty"><span class="list-empty-icon bg-warning text-white"><i class="ri-lock-line"></i></span><div class="fw-semibold mt-2">You can see attendance but not record it</div><div class="fs-12">Ask your church's Senior Pastor or Secretary to record, or to give you permission.</div></div></div>`;
    box.onclick = (ev) => {
      const btn = ev.target.closest("[data-kind]");
      if (btn && !btn.disabled) open(btn.dataset.kind);
    };
    renderRecent();
  }

  function renderRecent() {
    const box = document.getElementById("recentList");
    const recent = rows.slice(0, 6);
    if (!recent.length) {
      box.innerHTML = `<div class="list-empty"><span class="list-empty-icon bg-primary text-white"><i class="ri-calendar-line"></i></span><div class="fw-semibold mt-2">Nothing recorded yet</div><div class="fs-12">Your first record will show here.</div></div>`;
      return;
    }
    box.innerHTML = recent
      .map((r) => {
        const kind = KINDS.find((k) => k.slug === r.gathering_category?.slug) || KINDS[0];
        const d = A.parseIso(A.recordIso(r));
        return `
        <a class="record-recent" href="${RECORD_URL}?id=${r.id}">
          <span class="budget-recent-date"><b>${d.getDate()}</b><small>${d.toLocaleDateString("en-GB", { month: "short" })}</small></span>
          <span class="avatar avatar-sm avatar-rounded bg-${kind.color} text-white flex-shrink-0"><i class="${kind.icon}"></i></span>
          <span class="flex-fill" style="min-width: 0;"><span class="d-block fw-semibold text-truncate">${esc(nameOf(r))}</span><span class="d-block fs-12">${kind.title} · ${A.formatDate(A.recordIso(r))}</span></span>
          <span class="fw-bold text-nowrap">${A.recordTotal(r).toLocaleString()} <span class="fs-12 fw-normal">came</span></span>
          <i class="ri-arrow-right-s-line"></i>
        </a>`;
      })
      .join("");
  }

  function open(slug) {
    const kind = KINDS.find((k) => k.slug === slug);
    const after = async () => {
      await loadRows();
      render();
    };
    const common = { gatheringCategoryId: categories[slug].id, territoryId: USER_TERRITORY.id, record: null, canDelete: CAN_DELETE_ATTENDANCE, onSaved: after, onDeleted: after };
    if (slug === "sunday_service") {
      A.openEntryModal({ ...common, isWeekly: true, defaultDate: nextSunday(), records: ofKind(slug) });
    } else {
      A.openEntryModal({ ...common, isWeekly: false, records: ofKind(slug), types: typesBy[slug] || [], icon: kind.icon, categoryLabel: kind.label });
    }
  }

  return { init };
})();

window.AttendanceNew = AttendanceNew;
