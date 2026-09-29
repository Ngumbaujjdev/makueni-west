/**
 * ============================================================================
 * PAGE - DEMOGRAPHICS TRACKING (church/demographics-growth/demographics-tracking.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * The intake form, in the same shape as v1-events-backend's Create Event:
 * step bar -> one step at a time (Period, Membership, Groups, Changes &
 * sacraments, Review) with a live preview beside it -> a finish screen.
 *
 * - Period: fiscal year (Select2) + a grid of period chips showing each
 *   period's status. A draft chip opens that draft; a submitted/approved
 *   chip opens its report; an empty chip starts a new entry.
 * - Every number tile shows the previous *approved* period's value
 *   ("Last time"), and "Start from these numbers" copies the headcounts.
 * - Next checks only the current step; errors sit on the fields.
 * - One POST/PUT still covers the whole ChurchDemographic row - the steps are
 *   a UX grouping, not separate API calls. No backend changes.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast, jQuery + Select2
 * ============================================================================
 */

const DemographicsTracking = (function () {
  "use strict";

  const UI = DemographicsUI;

  const STEP_NAMES = ["Period", "Membership", "Groups", "Changes & sacraments", "Review"];
  const STEP_FIELDS = {
    2: ["total_members", "male_count", "female_count", "youth_count", "seniors_count"],
    3: ["womens_fellowship_count", "mens_fellowship_count", "sunday_school_male_count", "sunday_school_female_count", "sunday_school_teachers_count"],
    4: ["new_members_count", "transferred_out_count", "baptisms_count", "communion_participants_count", "conversions_count"],
  };
  // Headcounts are a snapshot, so "Start from these numbers" copies them;
  // step 4 is what happened this period and always starts empty.
  const SNAPSHOT_FIELDS = [...STEP_FIELDS[2], ...STEP_FIELDS[3]];
  const ALL_FIELDS = [...SNAPSHOT_FIELDS, ...STEP_FIELDS[4]];

  const FIELD_LABELS = {
    total_members: "Total members",
    male_count: "Male",
    female_count: "Female",
    youth_count: "Youth (13-35)",
    seniors_count: "Seniors (60+)",
    womens_fellowship_count: "Women's fellowship",
    mens_fellowship_count: "Men's fellowship",
    sunday_school_male_count: "Sunday school boys",
    sunday_school_female_count: "Sunday school girls",
    sunday_school_teachers_count: "Sunday school teachers",
    new_members_count: "New members",
    transferred_out_count: "Transferred out",
    baptisms_count: "Baptisms",
    communion_participants_count: "Communion",
    conversions_count: "Conversions",
  };

  const CADENCE = {
    monthly: { label: "Monthly reporting", unit: "month", pickLabel: "Month", hint: "Which month you are reporting" },
    half_yearly: { label: "Half-yearly reporting", unit: "half-year", pickLabel: "Half-year", hint: "Which half-year you are reporting" },
    yearly: { label: "Yearly reporting", unit: "year", pickLabel: "Year", hint: "Which year you are reporting" },
  };

  const EDITABLE = ["draft", "changes_requested"];
  const CHIP_STATES = {
    approved: { cls: "is-approved", icon: "ri-checkbox-circle-fill", text: "Approved" },
    submitted: { cls: "is-submitted", icon: "ri-send-plane-fill", text: "Submitted" },
    flagged: { cls: "is-flagged", icon: "ri-flag-fill", text: "Flagged" },
    draft: { cls: "is-draft", icon: "ri-draft-fill", text: "Draft" },
    changes_requested: { cls: "is-changes", icon: "ri-edit-fill", text: "Changes asked" },
    open: { cls: "is-open", icon: "ri-checkbox-blank-circle-line", text: "Not started" },
    upcoming: { cls: "is-upcoming", icon: "ri-time-line", text: "Upcoming" },
  };

  const state = {
    step: 1,
    reached: 1,
    mode: "monthly", // cadence of the entry on screen (an old record keeps its own)
    churchMode: "monthly", // the church's current setting
    years: [],
    yearId: null,
    periods: [], // [{id, name, number}] for the chosen year
    periodId: null,
    submissions: [],
    record: null, // the loaded submission (null = a new entry)
    previous: null, // the approved submission before the chosen period
    locked: false,
    dirty: false,
    savedAt: null,
    warnings: [],
    prefilled: false,
  };

  const $id = (id) => document.getElementById(id);

  // ==========================================================================
  // INIT
  // ==========================================================================

  async function init() {
    Object.assign(USER_TERRITORY, UI.resolveUserTerritory(USER_TERRITORY));
    wireStepBar();
    wireFooter();
    wireFields();
    wireBanners();
    renderPreview();

    const [modeRes, yearsRes, subsRes] = await Promise.all([
      DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id),
      DemographicsAPIHandler.getFiscalYears(),
      DemographicsAPIHandler.getDemographics(USER_TERRITORY.id),
    ]);
    state.churchMode = modeRes.success ? modeRes.data.demographics_mode || "monthly" : "monthly";
    state.mode = state.churchMode;
    state.submissions = subsRes.success ? subsRes.data || [] : [];
    const thisYear = new Date().getFullYear();
    state.years = (yearsRes.success ? yearsRes.data || [] : []).sort((a, b) => b.year - a.year);
    loadClergySummary();

    if (state.years.length === 0) {
      $id("fiscalYear").innerHTML = '<option value="">No fiscal years configured</option>';
      $id("periodGrid").innerHTML = '<p class="mb-0 text-body">Ask the diocese office to set up this fiscal year first.</p>';
      return;
    }

    const yearSelect = $id("fiscalYear");
    yearSelect.innerHTML = state.years.map((y) => `<option value="${y.id}">${y.year}</option>`).join("");
    UI.enhanceSelect(yearSelect, { search: state.years.length > 8 });
    yearSelect.addEventListener("change", () => {
      if (String(state.yearId) === yearSelect.value) return;
      if (!confirmLeave()) {
        yearSelect.value = String(state.yearId);
        UI.syncSelect(yearSelect);
        return;
      }
      if (state.record) startNewEntry();
      state.mode = state.churchMode;
      selectYear(yearSelect.value, { autoPick: true });
    });

    if (EDIT_DEMOGRAPHIC_ID) {
      await openRecord(EDIT_DEMOGRAPHIC_ID, { goTo: 2 });
    } else {
      const defaultYear = state.years.find((y) => y.year === thisYear) || state.years[0];
      await selectYear(defaultYear.id, { autoPick: true });
      offerResume();
    }
    updateFooter();
  }

  // ==========================================================================
  // PERIOD (step 1)
  // ==========================================================================

  function yearOf(id) {
    return state.years.find((y) => String(y.id) === String(id));
  }

  function renderCadence() {
    const c = CADENCE[state.mode] || CADENCE.monthly;
    $id("cadenceChip").innerHTML = `<i class="ri-calendar-2-line"></i>${c.label}`;
    $id("periodLabel").textContent = c.pickLabel;
    document.querySelector('.intake-step[data-step="1"] .intake-step-head p').textContent = c.hint;
    document.querySelector('#intakeSteps [data-go="1"] small').textContent = c.hint;
  }

  async function selectYear(yearId, { autoPick = false, periodId = null } = {}) {
    state.yearId = Number(yearId);
    const select = $id("fiscalYear");
    if (select.value !== String(yearId)) {
      select.value = String(yearId);
      UI.syncSelect(select);
    }
    renderCadence();
    $id("periodGrid").innerHTML = Array.from({ length: 6 }, () => '<span class="skel period-chip-skel"></span>').join("");

    const year = yearOf(yearId);
    if (state.mode === "monthly") {
      const res = await DemographicsAPIHandler.getFiscalMonthsForYear(yearId);
      state.periods = (res.success ? res.data || [] : []).map((m) => ({ id: m.id, name: m.name, number: m.number })).sort((a, b) => a.number - b.number);
    } else if (state.mode === "half_yearly") {
      const res = await DemographicsAPIHandler.getFiscalYear(yearId);
      state.periods = (res.success ? res.data.semi_annuals || [] : []).map((h) => ({ id: h.id, name: h.name, number: h.number })).sort((a, b) => a.number - b.number);
    } else {
      state.periods = [{ id: "year", name: `Whole of ${year ? year.year : ""}`.trim(), number: 0 }];
    }

    state.periodId = periodId ?? null;
    if (!state.periodId && autoPick) {
      // Pick this month (or the only period) when it's still open to fill in.
      const now = state.periods.find((p) => isCurrent(p)) || (state.periods.length === 1 ? state.periods[0] : null);
      if (now && chipState(now) === "open") state.periodId = now.id;
    }
    renderPeriodGrid();
    afterPeriodChange();
  }

  function isCurrent(p) {
    const year = yearOf(state.yearId);
    const now = new Date();
    if (!year || year.year !== now.getFullYear()) return false;
    if (state.mode === "monthly") return p.number === now.getMonth() + 1;
    if (state.mode === "half_yearly") return p.number === (now.getMonth() < 6 ? 1 : 2);
    return true;
  }

  function isUpcoming(p) {
    const year = yearOf(state.yearId);
    const now = new Date();
    if (!year) return false;
    if (year.year > now.getFullYear()) return true;
    if (year.year < now.getFullYear()) return false;
    if (state.mode === "monthly") return p.number > now.getMonth() + 1;
    if (state.mode === "half_yearly") return p.number > (now.getMonth() < 6 ? 1 : 2);
    return false;
  }

  function rowForPeriod(periodId) {
    return state.submissions.find((r) => {
      if (String(r.fiscal_year_id) !== String(state.yearId)) return false;
      if (state.mode === "monthly") return String(r.fiscal_month_id) === String(periodId);
      if (state.mode === "half_yearly") return String(r.fiscal_semi_annual_id) === String(periodId);
      return !r.fiscal_month_id && !r.fiscal_semi_annual_id;
    });
  }

  function chipState(p) {
    const row = rowForPeriod(p.id);
    if (row) return CHIP_STATES[row.status] ? row.status : "draft";
    return isUpcoming(p) ? "upcoming" : "open";
  }

  function renderPeriodGrid() {
    const grid = $id("periodGrid");
    grid.classList.toggle("is-single", state.periods.length === 1);
    if (state.periods.length === 0) {
      grid.innerHTML = '<p class="mb-0 text-body">No periods set up for this year yet.</p>';
      return;
    }
    grid.innerHTML = state.periods
      .map((p) => {
        const key = chipState(p);
        const s = CHIP_STATES[key];
        const selected = String(p.id) === String(state.periodId);
        const locked = ["approved", "submitted", "flagged"].includes(key);
        return `
          <button type="button" class="period-chip ${s.cls}${selected ? " is-selected" : ""}${isCurrent(p) ? " is-now" : ""}"
                  data-period="${p.id}" role="radio" aria-checked="${selected}" ${key === "upcoming" ? "disabled" : ""}
                  title="${locked ? "Open the report" : s.text}">
            <span class="period-chip-name">${p.name}</span>
            <span class="period-chip-status"><i class="${s.icon}"></i>${s.text}</span>
            ${locked ? '<i class="ri-arrow-right-up-line period-chip-go" aria-hidden="true"></i>' : ""}
            ${isCurrent(p) ? `<span class="period-chip-now">${state.mode === "monthly" ? "This month" : "Now"}</span>` : ""}
          </button>`;
      })
      .join("");
    grid.querySelectorAll(".period-chip").forEach((btn) => btn.addEventListener("click", () => choosePeriod(btn.dataset.period)));

    const note = $id("periodNote");
    const states = state.periods.map(chipState);
    const unit = (CADENCE[state.mode] || CADENCE.monthly).unit;
    if (!state.record && states.length && !states.some((k) => k === "open" || EDITABLE.includes(k))) {
      const year = yearOf(state.yearId);
      note.innerHTML = states.every((k) => k === "upcoming")
        ? `<i class="ri-time-line"></i>Nothing to report for ${year ? year.year : "this year"} yet.`
        : `<i class="ri-checkbox-circle-line"></i>Every ${unit} of ${year ? year.year : "this year"} that's due is already in. Pick another year, or open a report.`;
      note.hidden = false;
    } else {
      note.hidden = true;
    }
  }

  async function choosePeriod(periodId) {
    const period = state.periods.find((p) => String(p.id) === String(periodId));
    if (!period) return;
    const row = rowForPeriod(period.id);

    if (row && !EDITABLE.includes(row.status)) {
      window.location.href = `view-submission.php?id=${row.id}`;
      return;
    }
    if (row) {
      if (state.record && state.record.id === row.id) return;
      if (!confirmLeave()) return;
      await openRecord(row.id);
      return;
    }
    if (state.record && !confirmLeave()) return;
    if (state.record) startNewEntry();
    state.periodId = period.id;
    renderPeriodGrid();
    afterPeriodChange();
  }

  /** Previous approved submission, used for "Last time", change pills and the preview. */
  function afterPeriodChange() {
    state.previous = findPrevious();
    clearStepErrors(1);
    renderLastValues();
    renderPrefill();
    renderPreview();
    updateFooter();
  }

  /**
   * The month a period ends in (month = its number, H1 = 6, H2 = 12, a whole
   * year = 12), so submissions recorded under different cadences - a church
   * that switched from monthly to half-yearly - still order correctly.
   */
  function endMonthOf(r) {
    if (r.fiscal_month?.number) return r.fiscal_month.number;
    if (r.fiscal_semi_annual?.number) return r.fiscal_semi_annual.number * 6;
    return 12;
  }

  function selectedEndMonth() {
    const period = state.periods.find((p) => String(p.id) === String(state.periodId));
    if (!period) return null;
    if (state.mode === "monthly") return period.number;
    if (state.mode === "half_yearly") return period.number * 6;
    return 12;
  }

  function findPrevious() {
    const year = yearOf(state.yearId);
    const key = (r) => (r.fiscal_year?.year || 0) * 100 + endMonthOf(r);
    const approved = state.submissions
      .filter((r) => r.status === "approved" && (!state.record || r.id !== state.record.id))
      .sort((a, b) => key(b) - key(a));
    const end = selectedEndMonth();
    if (!year) return approved[0] || null;
    // No period picked yet: the latest approved one up to this year.
    const limit = end == null ? year.year * 100 + 13 : year.year * 100 + end;
    return approved.find((r) => key(r) < limit) || null;
  }

  function periodLabel() {
    const year = yearOf(state.yearId);
    const period = state.periods.find((p) => String(p.id) === String(state.periodId));
    if (!period) return null;
    if (state.mode === "monthly") return `${period.name} ${year ? year.year : ""}`.trim();
    return period.name;
  }

  // ==========================================================================
  // RECORD LOAD / NEW ENTRY
  // ==========================================================================

  async function openRecord(id, { goTo = null } = {}) {
    const res = await DemographicsAPIHandler.getDemographic(id);
    if (!res.success) {
      Toast.error(res.message || "Could not load that submission");
      if (!state.yearId && state.years.length) await selectYear(state.years[0].id, { autoPick: true });
      return;
    }
    const record = res.data;
    // A record keeps the cadence it was recorded with, even if the church's
    // setting changed since.
    state.mode = record.fiscal_semi_annual_id ? "half_yearly" : record.fiscal_month_id ? "monthly" : "yearly";
    const periodId = record.fiscal_month_id || record.fiscal_semi_annual_id || "year";

    state.record = record;
    await selectYear(record.fiscal_year_id, { periodId });

    ALL_FIELDS.forEach((f) => {
      const el = $id(f);
      if (el) el.value = record[f] ?? "";
    });
    state.locked = !EDITABLE.includes(record.status);
    state.dirty = false;
    state.savedAt = record.updated_at ? new Date(record.updated_at) : null;
    state.reached = 5;
    state.prefilled = true;
    applyLock();
    renderNotice();
    hideResume();
    setUrlId(record.id);
    afterPeriodChange();
    updateChecks();
    if (goTo) goToStep(goTo, { force: true });
    else updateStepBar();
  }

  function startNewEntry() {
    state.record = null;
    state.locked = false;
    state.dirty = false;
    state.savedAt = null;
    state.warnings = [];
    state.prefilled = false;
    state.reached = Math.min(state.reached, 1);
    ALL_FIELDS.forEach((f) => {
      const el = $id(f);
      if (el) el.value = "";
    });
    ALL_FIELDS.forEach(clearFieldError);
    applyLock();
    renderNotice();
    setUrlId(null);
    updateChecks();
    updateStepBar();
  }

  function setUrlId(id) {
    const params = new URLSearchParams(window.location.search);
    if (id) params.set("id", id);
    else params.delete("id");
    const qs = params.toString();
    history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
  }

  function confirmLeave() {
    return !state.dirty || window.confirm("You have unsaved changes. Leave them?");
  }

  function applyLock() {
    document.querySelectorAll("#demographicsForm .num-tile-input, #demographicsForm .stepper-btn").forEach((el) => {
      el.disabled = state.locked;
    });
    $id("prefillBtn").disabled = state.locked;
    $id("saveDraftBtn").hidden = state.locked;
  }

  // ==========================================================================
  // BANNERS
  // ==========================================================================

  function wireBanners() {
    $id("intakeResumeNo").addEventListener("click", hideResume);
  }

  function offerResume() {
    const draft = UI.sortSubmissionsNewestFirst(state.submissions.filter((r) => EDITABLE.includes(r.status)))[0];
    if (!draft) return;
    const label = UI.demographicPeriodLabel(draft);
    const asked = draft.status === "changes_requested";
    $id("intakeResumeTitle").textContent = asked ? `The diocese asked for changes to ${label}` : `Continue your draft for ${label}?`;
    $id("intakeResumeText").textContent = asked
      ? draft.review_notes || "Open it, make the changes and submit it again."
      : "You started it earlier. Pick up where you left off.";
    const yes = $id("intakeResumeYes");
    yes.textContent = asked ? "Open it" : "Continue";
    yes.onclick = () => openRecord(draft.id, { goTo: 2 });
    $id("intakeResume").hidden = false;
  }

  function hideResume() {
    $id("intakeResume").hidden = true;
  }

  function renderNotice() {
    const box = $id("intakeNotice");
    const r = state.record;
    if (!r || (!state.locked && r.status !== "changes_requested")) {
      box.hidden = true;
      return;
    }
    const icon = $id("intakeNoticeIcon");
    if (state.locked) {
      icon.className = "intake-banner-icon bg-primary text-white";
      icon.innerHTML = '<i class="ri-lock-line"></i>';
      $id("intakeNoticeTitle").textContent = `${UI.demographicPeriodLabel(r)} is ${r.status === "approved" ? "approved" : "with the diocese"} and can't be changed here`;
      $id("intakeNoticeText").textContent = "You can still look through every step.";
      $id("intakeNoticeActions").innerHTML = `<a href="view-submission.php?id=${r.id}" class="btn btn-sm btn-primary"><i class="ri-file-chart-2-line me-1"></i>Open the report</a>`;
    } else {
      icon.className = "intake-banner-icon bg-danger text-white";
      icon.innerHTML = '<i class="ri-chat-quote-line"></i>';
      $id("intakeNoticeTitle").textContent = "The diocese asked for changes";
      $id("intakeNoticeText").textContent = r.review_notes || "Update the numbers and submit again.";
      $id("intakeNoticeActions").innerHTML = "";
    }
    box.hidden = false;
  }

  // ==========================================================================
  // STEPS
  // ==========================================================================

  function wireStepBar() {
    document.querySelectorAll("#intakeSteps [data-go]").forEach((btn) => btn.addEventListener("click", () => goToStep(Number(btn.dataset.go))));
  }

  /** Moves to step n. Going forward past the current step checks each step on the way. */
  function goToStep(n, { force = false } = {}) {
    if (!force && n > state.step) {
      for (let s = state.step; s < n; s++) {
        if (!state.locked && !validateStep(s)) {
          if (s !== state.step) showStep(s);
          return;
        }
      }
    }
    if (!force && n > state.reached + 1) return;
    showStep(n);
  }

  function showStep(n) {
    state.step = n;
    state.reached = Math.max(state.reached, n);
    document.querySelectorAll("#demographicsForm .intake-step").forEach((sec) => {
      sec.hidden = Number(sec.dataset.step) !== n;
    });
    if (n === 5) renderReview();
    updateStepBar();
    updateFooter();
    const form = $id("demographicsForm");
    const top = form.getBoundingClientRect().top + window.scrollY - 90;
    if (window.scrollY > top) window.scrollTo({ top: Math.max(0, $id("intakeSteps").getBoundingClientRect().top + window.scrollY - 80), behavior: "smooth" });
  }

  function updateStepBar() {
    document.querySelectorAll("#intakeSteps [data-go]").forEach((btn) => {
      const n = Number(btn.dataset.go);
      btn.classList.toggle("is-on", n === state.step);
      btn.classList.toggle("is-done", n < state.step || (n <= state.reached && n !== state.step && n < 5));
      btn.disabled = n > state.reached + (n === state.step + 1 ? 1 : 0);
    });
    $id("intakeStepsMobile").textContent = `Step ${state.step} of 5 · ${STEP_NAMES[state.step - 1]}`;
    $id("intakeStepsBar").style.width = `${(state.step / 5) * 100}%`;
  }

  function wireFooter() {
    $id("nextBtn").addEventListener("click", () => goToStep(state.step + 1));
    $id("backBtn").addEventListener("click", () => showStep(Math.max(1, state.step - 1)));
    $id("toReviewBtn").addEventListener("click", () => goToStep(5));
    $id("saveDraftBtn").addEventListener("click", saveDraft);
    $id("submitBtn").addEventListener("click", submit);
    setInterval(updateSavedLabel, 30000);
    window.addEventListener("beforeunload", (e) => {
      if (state.dirty && !state.locked) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }

  function updateFooter() {
    $id("backBtn").hidden = state.step === 1;
    $id("nextBtn").hidden = state.step === 5;
    $id("submitBtn").hidden = state.step !== 5 || state.locked;
    // After an Edit link on Review, one tap goes straight back.
    $id("toReviewBtn").hidden = !(state.reached === 5 && state.step < 4);
    $id("nextBtn").innerHTML = state.step === 4 ? 'Review<i class="ri-arrow-right-line ms-1"></i>' : 'Next<i class="ri-arrow-right-line ms-1"></i>';
    updateSavedLabel();
  }

  function updateSavedLabel() {
    const el = $id("intakeSaved");
    if (state.locked && state.record) {
      el.innerHTML = `${UI.renderStatusBadge(state.record.status)}<span class="ms-2">Read-only</span>`;
      el.className = "intake-saved";
    } else if (state.dirty) {
      el.innerHTML = '<span class="intake-saved-dot"></span>Unsaved changes';
      el.className = "intake-saved is-dirty";
    } else if (state.record && state.savedAt) {
      el.innerHTML = `<i class="ri-check-line"></i>Draft saved · ${timeAgo(state.savedAt)}`;
      el.className = "intake-saved is-saved";
    } else {
      el.textContent = "Not saved yet";
      el.className = "intake-saved";
    }
  }

  function timeAgo(date) {
    const secs = Math.round((Date.now() - date.getTime()) / 1000);
    if (secs < 45) return "just now";
    const mins = Math.round(secs / 60);
    if (mins < 60) return `${mins} min ago`;
    const hours = Math.round(mins / 60);
    if (hours < 24) return `${hours} h ago`;
    return date.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
  }

  // ==========================================================================
  // FIELDS, CHECKS, VALIDATION
  // ==========================================================================

  function wireFields() {
    UI.initSteppers($id("demographicsForm"));
    ALL_FIELDS.forEach((f) =>
      $id(f).addEventListener("input", () => {
        clearFieldError(f);
        state.dirty = true;
        updateChecks();
        renderPreview();
        updateSavedLabel();
      }),
    );
    $id("prefillBtn").addEventListener("click", prefillFromPrevious);
  }

  function value(f) {
    const el = $id(f);
    return el && el.value !== "" ? parseInt(el.value, 10) : null;
  }

  function updateChecks() {
    const total = value("total_members");
    const male = value("male_count");
    const female = value("female_count");
    const check = $id("genderCheck");
    if (male == null && female == null) {
      check.innerHTML = '<span class="soft-chip soft-primary"><i class="ri-information-line"></i>Add male and female to check they add up to the total</span>';
    } else {
      const sum = (male || 0) + (female || 0);
      if (total == null) {
        check.innerHTML = `<span class="soft-chip soft-primary"><i class="ri-information-line"></i>Male + Female = <b>${sum.toLocaleString()}</b> · add Total members to compare</span>`;
      } else if (sum === total) {
        check.innerHTML = `<span class="soft-chip soft-success"><i class="ri-checkbox-circle-line"></i>Male + Female = <b>${sum.toLocaleString()}</b>, matches Total members</span>`;
      } else {
        const diff = Math.abs(total - sum);
        check.innerHTML = `<span class="soft-chip soft-danger"><i class="ri-error-warning-line"></i>Male + Female = <b>${sum.toLocaleString()}</b> · ${diff.toLocaleString()} ${sum < total ? "short of" : "more than"} ${total.toLocaleString()}</span>`;
      }
    }
    $id("fellowshipSubtotal").innerHTML = `Total · <b>${((value("womens_fellowship_count") || 0) + (value("mens_fellowship_count") || 0)).toLocaleString()}</b>`;
    $id("sundaySchoolSubtotal").innerHTML = `Children · <b>${((value("sunday_school_male_count") || 0) + (value("sunday_school_female_count") || 0)).toLocaleString()}</b>`;
    renderLastValues();
  }

  function setFieldError(f, message) {
    const tile = document.querySelector(`.num-tile[data-tile="${f}"]`);
    if (!tile) return;
    tile.classList.add("has-error");
    tile.querySelector(`[data-error-for="${f}"]`).textContent = message;
  }

  function clearFieldError(f) {
    const tile = document.querySelector(`.num-tile[data-tile="${f}"]`);
    if (!tile) return;
    tile.classList.remove("has-error");
    tile.querySelector(`[data-error-for="${f}"]`).textContent = "";
  }

  function clearStepErrors(step) {
    const box = document.querySelector(`[data-errors-for="${step}"]`);
    if (box) {
      box.hidden = true;
      box.innerHTML = "";
    }
    (STEP_FIELDS[step] || []).forEach(clearFieldError);
  }

  function showStepErrors(step, messages) {
    const box = document.querySelector(`[data-errors-for="${step}"]`);
    box.innerHTML = `<i class="ri-error-warning-line"></i><div><strong>${messages.length === 1 ? "One thing to fix" : `${messages.length} things to fix`}</strong><ul>${messages.map((m) => `<li>${m}</li>`).join("")}</ul></div>`;
    box.hidden = false;
    const target = document.querySelector(`.intake-step[data-step="${step}"] .num-tile.has-error input`) || box;
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    if (target.tagName === "INPUT") target.focus({ preventScroll: true });
  }

  /** Checks one step; shows errors on the fields and returns false if anything is wrong. */
  function validateStep(step) {
    clearStepErrors(step);
    const errors = [];
    const fieldError = (f, msg) => {
      setFieldError(f, msg);
      errors.push(msg);
    };

    if (step === 1) {
      if (!state.yearId) errors.push("Pick the fiscal year.");
      else if (!state.periodId) errors.push(`Pick the ${(CADENCE[state.mode] || CADENCE.monthly).unit} you're reporting for.`);
    }

    (STEP_FIELDS[step] || []).forEach((f) => {
      const v = value(f);
      if (v != null && (Number.isNaN(v) || v < 0)) fieldError(f, `${FIELD_LABELS[f]} can't be negative.`);
    });

    const total = value("total_members");
    if (step === 2) {
      if (total == null) fieldError("total_members", "Enter total members.");
      const male = value("male_count");
      const female = value("female_count");
      if (total != null && (male != null || female != null) && (male || 0) + (female || 0) !== total) {
        const msg = `Male + Female (${((male || 0) + (female || 0)).toLocaleString()}) should equal Total members (${total.toLocaleString()}).`;
        setFieldError("male_count", msg);
        setFieldError("female_count", " ");
        errors.push(msg);
      }
      ["youth_count", "seniors_count"].forEach((f) => {
        if (total != null && value(f) != null && value(f) > total) fieldError(f, `${FIELD_LABELS[f]} can't be more than Total members.`);
      });
    }
    if (step === 3 && total != null) {
      ["womens_fellowship_count", "mens_fellowship_count"].forEach((f) => {
        if (value(f) != null && value(f) > total) fieldError(f, `${FIELD_LABELS[f]} can't be more than Total members (${total.toLocaleString()}).`);
      });
    }

    if (errors.length) showStepErrors(step, errors);
    return errors.length === 0;
  }

  // ==========================================================================
  // LAST TIME + PREFILL
  // ==========================================================================

  function renderLastValues() {
    const prev = state.previous;
    const prevLabel = prev ? UI.shortPeriodLabel(prev) : "";
    document.querySelectorAll("[data-last-for]").forEach((el) => {
      const f = el.dataset.lastFor;
      if (!prev || prev[f] == null) {
        el.textContent = el.dataset.hint || "";
        return;
      }
      const last = Number(prev[f]);
      const now = value(f);
      el.innerHTML = `Last time <b>${last.toLocaleString()}</b> <span class="num-tile-when">· ${prevLabel}</span>${now != null ? UI.changePill(now, last) : ""}`;
    });
  }

  function renderPrefill() {
    const bar = $id("prefillBar");
    const prev = state.previous;
    if (!prev || state.record || state.locked) {
      bar.hidden = true;
      return;
    }
    $id("prefillText").innerHTML = `Last approved: <b>${UI.demographicPeriodLabel(prev)}</b> · ${Number(prev.total_members || 0).toLocaleString()} members`;
    const btn = $id("prefillBtn");
    btn.disabled = state.prefilled;
    btn.innerHTML = state.prefilled ? '<i class="ri-check-line me-1"></i>Filled in' : '<i class="ri-magic-line me-1"></i>Start from these numbers';
    bar.hidden = false;
  }

  function prefillFromPrevious() {
    const prev = state.previous;
    if (!prev) return;
    SNAPSHOT_FIELDS.forEach((f) => {
      if (prev[f] != null) $id(f).value = prev[f];
      clearFieldError(f);
    });
    state.prefilled = true;
    state.dirty = true;
    updateChecks();
    renderPrefill();
    renderPreview();
    updateSavedLabel();
    Toast.info(`Filled in from ${UI.demographicPeriodLabel(prev)}. Change whatever moved.`);
  }

  // ==========================================================================
  // LIVE PREVIEW
  // ==========================================================================

  function renderPreview() {
    const M = UI.DEMOGRAPHIC_METRICS;
    const label = periodLabel();
    $id("previewPeriod").textContent = label || "Pick a period";
    $id("previewStatus").innerHTML = state.record ? UI.renderStatusBadge(state.record.status) : '<span class="soft-chip soft-primary">New</span>';

    const filled = ALL_FIELDS.filter((f) => value(f) != null).length;
    $id("previewFilled").textContent = `${filled} of ${ALL_FIELDS.length} filled`;
    $id("previewFilledBar").style.width = `${Math.round((filled / ALL_FIELDS.length) * 100)}%`;

    const total = value("total_members");
    const prev = state.previous;
    const ss = value("sunday_school_male_count") == null && value("sunday_school_female_count") == null ? null : (value("sunday_school_male_count") || 0) + (value("sunday_school_female_count") || 0);
    UI.renderCompositionCard("previewComposition", {
      total,
      totalLabel: "total members",
      delta: total != null && prev && prev.total_members != null ? UI.periodDelta(total, Number(prev.total_members), { prevLabel: UI.shortPeriodLabel(prev) }) : null,
      items: [
        { label: "Youth", value: value("youth_count"), color: M.youth.color },
        { label: "Women's fellowship", value: value("womens_fellowship_count"), color: M.womens_fellowship.color },
        { label: "Men's fellowship", value: value("mens_fellowship_count"), color: M.mens_fellowship.color },
        { label: "Sunday school", value: ss, color: M.sunday_school.color },
        { label: "Seniors", value: value("seniors_count"), color: M.seniors.color },
      ],
    });

    const male = value("male_count");
    const female = value("female_count");
    const sum = (male || 0) + (female || 0);
    const pct = (v) => (sum ? Math.round(((v || 0) / sum) * 100) : 0);
    $id("previewGender").innerHTML =
      male == null && female == null
        ? '<div class="preview-empty">Male and female go in step 2</div>'
        : `
        <div class="preview-gender-bar"><span class="bg-info" style="width: ${pct(male)}%"></span><span class="bg-pink" style="width: ${pct(female)}%"></span></div>
        <div class="preview-gender-legend">
          <span><span class="count-dot bg-info"></span>Male <b>${(male || 0).toLocaleString()}</b> ${pct(male)}%</span>
          <span><span class="count-dot bg-pink"></span>Female <b>${(female || 0).toLocaleString()}</b> ${pct(female)}%</span>
        </div>`;

    const changes = [
      ["new_members_count", M.new_members],
      ["transferred_out_count", M.departures],
      ["baptisms_count", M.baptisms],
      ["communion_participants_count", M.communion],
      ["conversions_count", M.conversions],
    ];
    $id("previewChanges").innerHTML = changes
      .map(([f, m]) => {
        const v = value(f);
        return `
          <div class="preview-change${v == null ? " is-empty" : ""}">
            <span class="preview-change-icon bg-${m.color} ${m.color === "secondary" ? "text-dark" : "text-white"}"><i class="${m.icon}"></i></span>
            <span class="preview-change-value">${v == null ? "–" : v.toLocaleString()}</span>
            <span class="preview-change-label">${FIELD_LABELS[f]}</span>
          </div>`;
      })
      .join("");
  }

  // ==========================================================================
  // REVIEW (step 5)
  // ==========================================================================

  function renderReview() {
    const prev = state.previous;
    const groups = [
      { step: 1, title: "Period", icon: "ri-calendar-2-line", rows: [["Period", periodLabel() || "Not picked"], ["Reporting", (CADENCE[state.mode] || CADENCE.monthly).label]] },
      { step: 2, title: "Membership", icon: "ri-team-line", fields: STEP_FIELDS[2] },
      { step: 3, title: "Groups", icon: "ri-group-line", fields: STEP_FIELDS[3] },
      { step: 4, title: "Changes & sacraments", icon: "ri-hand-heart-line", fields: STEP_FIELDS[4] },
    ];
    $id("reviewGroups").innerHTML = `
      ${prev ? `<div class="mb-3"><span class="soft-chip soft-primary"><i class="ri-arrow-left-right-line"></i>Changes shown against ${UI.demographicPeriodLabel(prev)}</span></div>` : ""}
      ${groups
        .map((g) => {
          const rows = g.rows
            ? g.rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join("")
            : g.fields
                .map((f) => {
                  const v = value(f);
                  const last = prev && prev[f] != null ? Number(prev[f]) : null;
                  return `<dt>${FIELD_LABELS[f]}</dt><dd>${v == null ? '<span class="review-empty">Not entered</span>' : `<b>${v.toLocaleString()}</b>${last != null ? UI.changePill(v, last) : ""}`}</dd>`;
                })
                .join("");
          return `
            <div class="review-group">
              <div class="review-head">
                <span><i class="${g.icon}"></i>${g.title}</span>
                <button type="button" class="review-edit" data-review-go="${g.step}"><i class="ri-edit-line"></i>${state.locked ? "View" : "Edit"}</button>
              </div>
              <dl>${rows}</dl>
            </div>`;
        })
        .join("")}`;
    $id("reviewGroups").querySelectorAll("[data-review-go]").forEach((btn) => btn.addEventListener("click", () => showStep(Number(btn.dataset.reviewGo))));

    $id("reviewWarnings").innerHTML = state.warnings.length
      ? `<div class="intake-warnings"><i class="ri-alert-line"></i><div><strong>Please double-check</strong><ul>${state.warnings.map((w) => `<li>${w}</li>`).join("")}</ul></div></div>`
      : "";
  }

  // ==========================================================================
  // SAVE / SUBMIT
  // ==========================================================================

  function collectFormData() {
    const data = { territory_id: USER_TERRITORY.id, fiscal_year_id: Number(state.yearId) };
    if (state.mode === "monthly") data.fiscal_month_id = Number(state.periodId);
    else if (state.mode === "half_yearly") data.fiscal_semi_annual_id = Number(state.periodId);
    ALL_FIELDS.forEach((f) => {
      data[f] = value(f);
    });
    return data;
  }

  /** A draft only needs the period and total members. */
  function checkDraftBasics() {
    if (!validateStep(1)) {
      showStep(1);
      validateStep(1);
      return false;
    }
    if (value("total_members") == null) {
      showStep(2);
      clearStepErrors(2);
      setFieldError("total_members", "Enter total members.");
      showStepErrors(2, ["Enter total members before saving a draft."]);
      return false;
    }
    return true;
  }

  async function persist() {
    const payload = collectFormData();
    const res = state.record ? await DemographicsAPIHandler.updateDemographic(state.record.id, payload) : await DemographicsAPIHandler.createDemographic(payload);
    if (res.success) {
      state.record = { ...(state.record || {}), ...res.data };
      state.warnings = res.warnings || [];
      state.dirty = false;
      state.savedAt = new Date();
      const i = state.submissions.findIndex((r) => r.id === res.data.id);
      if (i >= 0) state.submissions[i] = { ...state.submissions[i], ...res.data };
      else state.submissions.push(res.data);
      setUrlId(res.data.id);
      renderPeriodGrid();
      renderPrefill();
      renderPreview();
    }
    return res;
  }

  function handleExisting(res) {
    if (res.status === 422 && res.data && res.data.id) {
      Toast.confirm("There's already a submission for this period. Open it instead?", () => openRecord(res.data.id, { goTo: 2 }), null, {
        type: "info",
        confirmText: "Open it",
      });
      return true;
    }
    return false;
  }

  async function saveDraft() {
    if (state.locked || !checkDraftBasics()) return;
    const btn = $id("saveDraftBtn");
    UI.setButtonLoading(btn, "Saving…");
    const res = await persist();
    UI.restoreButton(btn);
    if (!res.success) {
      if (!handleExisting(res)) Toast.error(res.message || "Could not save the draft");
      return;
    }
    updateFooter();
    if (state.step === 5) renderReview();
    Toast.success(state.warnings.length ? "Draft saved, with a few things to double-check" : "Draft saved");
  }

  function submit() {
    if (state.locked) return;
    for (let s = 1; s <= 4; s++) {
      if (!validateStep(s)) {
        showStep(s);
        validateStep(s);
        return;
      }
    }
    Toast.confirm(`Submit ${periodLabel()} for review? You won't be able to change it afterwards.`, doSubmit, null, {
      type: "primary",
      confirmText: "Yes, submit",
    });
  }

  async function doSubmit() {
    const btn = $id("submitBtn");
    UI.setButtonLoading(btn, "Submitting…");
    const saved = await persist();
    if (!saved.success) {
      UI.restoreButton(btn);
      if (!handleExisting(saved)) Toast.error(saved.message || "Could not save before submitting");
      return;
    }
    const res = await DemographicsAPIHandler.submitDemographic(state.record.id);
    UI.restoreButton(btn);
    if (!res.success) {
      Toast.error(res.message || "Could not submit");
      return;
    }
    state.record = { ...state.record, ...res.data };
    state.locked = true;
    showDone();
  }

  function showDone() {
    ["intakeSteps", "intakeMain", "intakeResume", "intakeNotice"].forEach((id) => {
      $id(id).hidden = true;
    });
    $id("intakeMain").classList.add("d-none");
    $id("intakeDoneTitle").textContent = `${periodLabel()} is submitted`;
    $id("intakeDoneText").textContent = "It's with the diocese for approval now. You'll see it under Growth Overview → History.";
    $id("doneView").href = `view-submission.php?id=${state.record.id}`;
    $id("intakeDone").hidden = false;
    window.scrollTo({ top: 0, behavior: "smooth" });
    Toast.success("Submitted for review");
  }

  // ==========================================================================
  // PASTORS (read-only, from staff records)
  // ==========================================================================

  async function loadClergySummary() {
    const box = $id("clergyChips");
    const res = await DemographicsAPIHandler.getClergySummary(USER_TERRITORY.id);
    if (!res.success) {
      box.innerHTML = '<span class="soft-chip soft-danger">Could not load pastors</span>';
      return;
    }
    const counts = res.data.counts || {};
    const roles = Object.keys(counts);
    box.innerHTML = roles.length
      ? roles.map((role) => `<span class="soft-chip soft-primary"><i class="ri-user-star-line"></i><b>${counts[role]}</b> ${role}</span>`).join("")
      : '<span class="soft-chip soft-secondary">No pastors on record</span>';
  }

  return { init };
})();

window.DemographicsTracking = DemographicsTracking;
