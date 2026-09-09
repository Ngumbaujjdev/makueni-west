/**
 * ============================================================================
 * PAGE - DEMOGRAPHICS TRACKING (the core entry form)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * 3-step wizard: Membership Counts -> Changes & Spiritual Activities ->
 * Review & Submit. One POST/PUT covers the whole ChurchDemographic row -
 * the steps are a UX grouping, not separate API calls.
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast
 * ============================================================================
 */

const DemographicsTracking = (function () {
  "use strict";

  const STEP1_FIELDS = [
    "total_members", "male_count", "female_count", "youth_count",
    "womens_fellowship_count", "mens_fellowship_count",
    "sunday_school_male_count", "sunday_school_female_count", "seniors_count",
    "sunday_school_teachers_count",
  ];
  const STEP2_FIELDS = [
    "new_members_count", "transferred_out_count",
    "baptisms_count", "communion_participants_count", "conversions_count",
  ];
  const ALL_FIELDS = STEP1_FIELDS.concat(STEP2_FIELDS);

  const FIELD_LABELS = {
    total_members: "Total Members", male_count: "Male", female_count: "Female",
    youth_count: "Youth (13-35)", womens_fellowship_count: "Women's Fellowship",
    mens_fellowship_count: "Men's Fellowship", sunday_school_male_count: "Sunday School (Male)",
    sunday_school_female_count: "Sunday School (Female)", seniors_count: "Seniors",
    sunday_school_teachers_count: "Sunday School Teachers",
    new_members_count: "New Members", transferred_out_count: "Transferred Out",
    baptisms_count: "Baptisms", communion_participants_count: "Communion Participants",
    conversions_count: "New Conversions",
  };

  const CADENCE_COPY = {
    monthly: { title: "Monthly Recording", description: "This church submits one demographics report every fiscal month." },
    half_yearly: { title: "Half-Yearly Recording", description: "This church submits one demographics report every half-year (H1/H2)." },
    yearly: { title: "Yearly Recording", description: "This church submits one demographics report per fiscal year." },
  };

  let currentRecordId = null;
  let currentStatus = "draft";
  /** monthly / half_yearly / yearly - this church's configured recording cadence, read once at load. */
  let demographicsMode = "monthly";
  /** Choices.js instance wrapping #fiscalYear - the only select on this form with a long enough list to need search. */
  let fiscalYearChoices = null;

  function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));
    wireStepNav();
    wireSteppers();
    wireCompleteness();
    wireActions();
    loadDemographicsMode().then(() => {
      loadFiscalYears().then(() => {
        if (EDIT_DEMOGRAPHIC_ID) {
          loadForEdit(EDIT_DEMOGRAPHIC_ID);
        }
      });
    });
    loadRecentSubmissions();
    loadClergySummary();
  }

  // ==========================================================================
  // CLERGY SUMMARY (read-only - derived live from staff records, never entered here)
  // ==========================================================================

  async function loadClergySummary() {
    const box = document.getElementById("clergySummaryBox");
    const result = await DemographicsAPIHandler.getClergySummary(USER_TERRITORY.id);

    if (!result.success) {
      box.innerHTML = '<span class="text-body">Could not load</span>';
      return;
    }

    const counts = result.data.counts || {};
    const roleNames = Object.keys(counts);

    if (roleNames.length === 0) {
      box.innerHTML = '<span class="text-body">No pastors on record</span>';
      return;
    }

    box.innerHTML = roleNames.map((role) => `<strong>${counts[role]}</strong> ${role}`).join(" &middot; ");
  }

  // ==========================================================================
  // RECORDING CADENCE (read-only here - set on the Growth Overview page)
  // ==========================================================================

  async function loadDemographicsMode() {
    const result = await DemographicsAPIHandler.getEntryMode(USER_TERRITORY.id);
    demographicsMode = result.success ? result.data.demographics_mode : "monthly";
    applyPeriodPickerMode();
  }

  /** Shows the Month select (monthly), the Half select (half_yearly), or neither (yearly) - and keeps `required` in sync so validateStep1() only checks the field that's actually visible. Also updates the cadence banner so the active mode is always visible at a glance, not just implied by which select is shown. */
  function applyPeriodPickerMode() {
    const monthWrapper = document.getElementById("fiscalMonthWrapper");
    const halfWrapper = document.getElementById("fiscalHalfWrapper");
    const monthSelect = document.getElementById("fiscalMonth");
    const halfSelect = document.getElementById("fiscalHalf");

    monthWrapper.style.display = demographicsMode === "monthly" ? "" : "none";
    halfWrapper.style.display = demographicsMode === "half_yearly" ? "" : "none";
    monthSelect.required = demographicsMode === "monthly";
    halfSelect.required = demographicsMode === "half_yearly";

    const copy = CADENCE_COPY[demographicsMode] || CADENCE_COPY.monthly;
    document.getElementById("cadenceBannerTitle").textContent = copy.title;
    document.getElementById("cadenceBannerDescription").textContent = copy.description;
  }

  // ==========================================================================
  // FISCAL PERIOD
  // ==========================================================================

  async function loadFiscalYears() {
    const select = document.getElementById("fiscalYear");
    const result = await DemographicsAPIHandler.getFiscalYears();

    if (!result.success || !result.data || result.data.length === 0) {
      select.innerHTML = '<option value="">No fiscal years configured</option>';
      return;
    }

    const years = result.data;
    select.innerHTML = years
      .sort((a, b) => b.year - a.year)
      .map((y) => `<option value="${y.id}">${y.year}</option>`)
      .join("");

    if (fiscalYearChoices) fiscalYearChoices.destroy();
    fiscalYearChoices = new Choices(select, {
      searchEnabled: true,
      searchPlaceholderValue: "Search year...",
      itemSelectText: "",
      allowHTML: false,
      placeholder: true,
      placeholderValue: "Select Fiscal Year",
    });

    // `is_active` just means "not disabled" - every year has it true here.
    // Default to the year matching today's real calendar year instead.
    const currentYear = new Date().getFullYear();
    const defaultYear = years.find((y) => y.year === currentYear) || years[0];
    fiscalYearChoices.setChoiceByValue(String(defaultYear.id));
    await loadPeriodOptionsFor(defaultYear.id);

    select.addEventListener("change", () => loadPeriodOptionsFor(select.value));
  }

  /** Loads whichever period select the church's mode actually uses - a no-op for yearly, since there's nothing below Fiscal Year to pick. */
  async function loadPeriodOptionsFor(fiscalYearId, selectPeriodId = null) {
    if (demographicsMode === "monthly") return loadFiscalMonths(fiscalYearId, selectPeriodId);
    if (demographicsMode === "half_yearly") return loadFiscalHalves(fiscalYearId, selectPeriodId);
  }

  async function loadFiscalMonths(fiscalYearId, selectMonthId = null) {
    const select = document.getElementById("fiscalMonth");
    select.disabled = true;
    select.innerHTML = '<option value="">Loading months...</option>';

    const result = await DemographicsAPIHandler.getFiscalMonthsForYear(fiscalYearId);

    if (!result.success || !result.data || result.data.length === 0) {
      select.innerHTML = '<option value="">No months available</option>';
      return;
    }

    select.innerHTML = result.data.map((m) => `<option value="${m.id}">${m.name}</option>`).join("");
    select.disabled = false;

    if (selectMonthId) {
      select.value = selectMonthId;
    } else {
      const currentMonthNumber = new Date().getMonth() + 1;
      const currentMonth = result.data.find((m) => m.number === currentMonthNumber);
      if (currentMonth) select.value = currentMonth.id;
    }
  }

  async function loadFiscalHalves(fiscalYearId, selectHalfId = null) {
    const select = document.getElementById("fiscalHalf");
    select.disabled = true;
    select.innerHTML = '<option value="">Loading...</option>';

    const result = await DemographicsAPIHandler.getFiscalYear(fiscalYearId);
    const halves = result.success ? result.data.semi_annuals || [] : [];

    if (halves.length === 0) {
      select.innerHTML = '<option value="">No half-years available</option>';
      return;
    }

    select.innerHTML = halves
      .sort((a, b) => a.number - b.number)
      .map((h) => `<option value="${h.id}">${h.name}</option>`)
      .join("");
    select.disabled = false;

    if (selectHalfId) {
      select.value = selectHalfId;
    } else {
      const currentHalfNumber = new Date().getMonth() + 1 <= 6 ? 1 : 2;
      const currentHalf = halves.find((h) => h.number === currentHalfNumber);
      if (currentHalf) select.value = currentHalf.id;
    }
  }

  // ==========================================================================
  // STEP NAVIGATION
  // ==========================================================================

  function validateStep1() {
    const fiscalYear = document.getElementById("fiscalYear");
    const fiscalMonth = document.getElementById("fiscalMonth");
    const fiscalHalf = document.getElementById("fiscalHalf");
    const totalMembers = document.getElementById("total_members");

    if (!fiscalYear.value) {
      Toast.warning("Please select a fiscal year");
      return false;
    }
    if (demographicsMode === "monthly" && !fiscalMonth.value) {
      Toast.warning("Please select a month");
      return false;
    }
    if (demographicsMode === "half_yearly" && !fiscalHalf.value) {
      Toast.warning("Please select a half-year");
      return false;
    }

    if (totalMembers.value === "") {
      Toast.warning("Total Members is required");
      totalMembers.classList.add("is-invalid");
      return false;
    }

    totalMembers.classList.remove("is-invalid");
    return true;
  }

  function wireStepNav() {
    document.getElementById("nextToStep2").addEventListener("click", () => {
      if (!validateStep1()) return;
      document.getElementById("step2Tab").click();
    });
    document.getElementById("backToStep1").addEventListener("click", () => document.getElementById("step1Tab").click());
    document.getElementById("nextToStep3").addEventListener("click", () => {
      renderReview();
      document.getElementById("step3Tab").click();
    });
    document.getElementById("backToStep2").addEventListener("click", () => document.getElementById("step2Tab").click());
  }

  // ==========================================================================
  // STEPPERS & COMPLETENESS
  // ==========================================================================

  function wireSteppers() {
    DemographicsUI.initSteppers(document.getElementById("demographicsForm"), updateCompleteness);
  }

  function wireCompleteness() {
    ALL_FIELDS.forEach((field) => {
      const input = document.getElementById(field);
      if (input) input.addEventListener("input", updateCompleteness);
    });
    updateCompleteness();
  }

  function updateCompleteness() {
    const filled = ALL_FIELDS.filter((f) => {
      const el = document.getElementById(f);
      return el && el.value !== "";
    }).length;
    DemographicsUI.updateCompletenessBar("formCompleteness", filled, ALL_FIELDS.length);
  }

  // ==========================================================================
  // FORM DATA <-> API
  // ==========================================================================

  function collectFormData() {
    const data = {
      territory_id: USER_TERRITORY.id,
      fiscal_year_id: parseInt(document.getElementById("fiscalYear").value, 10),
    };
    if (demographicsMode === "monthly") {
      data.fiscal_month_id = parseInt(document.getElementById("fiscalMonth").value, 10);
    } else if (demographicsMode === "half_yearly") {
      data.fiscal_semi_annual_id = parseInt(document.getElementById("fiscalHalf").value, 10);
    }
    ALL_FIELDS.forEach((field) => {
      const val = document.getElementById(field).value;
      data[field] = val === "" ? null : parseInt(val, 10);
    });
    return data;
  }

  function populateForm(record) {
    currentRecordId = record.id;
    currentStatus = record.status;

    if (fiscalYearChoices) {
      fiscalYearChoices.setChoiceByValue(String(record.fiscal_year_id));
    } else {
      document.getElementById("fiscalYear").value = record.fiscal_year_id;
    }

    // A record's own period field tells us how it was actually recorded -
    // that can differ from the church's *current* demographicsMode if the
    // mode was changed after this record was created, so this switches the
    // visible picker to match the record, not just whatever's configured now.
    if (record.fiscal_semi_annual_id) {
      demographicsMode = "half_yearly";
      applyPeriodPickerMode();
      loadFiscalHalves(record.fiscal_year_id, record.fiscal_semi_annual_id);
    } else if (record.fiscal_month_id) {
      demographicsMode = "monthly";
      applyPeriodPickerMode();
      loadFiscalMonths(record.fiscal_year_id, record.fiscal_month_id);
    } else {
      demographicsMode = "yearly";
      applyPeriodPickerMode();
    }

    ALL_FIELDS.forEach((field) => {
      const el = document.getElementById(field);
      if (el) el.value = record[field] ?? "";
    });

    updateCompleteness();
    updateDraftStatusLabel();
    applyEditLock();
  }

  function applyEditLock() {
    const locked = currentStatus !== "draft" && currentStatus !== "changes_requested";
    document
      .querySelectorAll("#demographicsForm input, #demographicsForm .stepper-btn")
      .forEach((el) => {
        el.disabled = locked;
      });
    document.getElementById("saveDraftBtn").style.display = locked ? "none" : "";
    document.getElementById("submitBtn").style.display = locked ? "none" : "";
  }

  function updateDraftStatusLabel() {
    const label = document.getElementById("draftStatusLabel");
    if (!currentRecordId) {
      label.textContent = "Not saved yet";
      return;
    }
    label.innerHTML = `Current status: ${DemographicsUI.renderStatusBadge(currentStatus)}`;
  }

  // ==========================================================================
  // REVIEW STEP
  // ==========================================================================

  function renderFieldGrid(fields) {
    return `<div class="row g-3">${fields
      .map(
        (f) => `
      <div class="col-md-4">
        <span class="d-block mb-1 text-body fw-semibold">${FIELD_LABELS[f]}</span>
        <strong class="fs-16">${document.getElementById(f).value || 0}</strong>
      </div>`,
      )
      .join("")}</div>`;
  }

  function renderReview() {
    document.getElementById("reviewMembership").innerHTML = renderFieldGrid(STEP1_FIELDS);
    document.getElementById("reviewActivities").innerHTML = renderFieldGrid(STEP2_FIELDS);
  }

  function renderWarnings(warnings) {
    const step1El = document.getElementById("step1Warnings");
    const reviewEl = document.getElementById("reviewWarnings");
    const html = warnings && warnings.length
      ? `<div class="alert alert-warning alert-dismissible fade show mb-0">
           <i class="ri-alert-line me-2"></i>
           <strong>Please double-check:</strong>
           <ul class="mb-0 mt-1">${warnings.map((w) => `<li>${w}</li>`).join("")}</ul>
           <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
         </div>`
      : "";
    step1El.innerHTML = html;
    reviewEl.innerHTML = html;
  }

  // ==========================================================================
  // SAVE / SUBMIT
  // ==========================================================================

  async function handleSaveDraft() {
    if (!validateStep1()) {
      document.getElementById("step1Tab").click();
      return;
    }

    const btn = document.getElementById("saveDraftBtn");
    DemographicsUI.setButtonLoading(btn, "Saving...");

    const payload = collectFormData();
    const result = currentRecordId
      ? await DemographicsAPIHandler.updateDemographic(currentRecordId, payload)
      : await DemographicsAPIHandler.createDemographic(payload);

    DemographicsUI.restoreButton(btn);

    if (!result.success) {
      if (result.status === 422 && result.data && result.data.id) {
        Toast.confirm(
          "A submission for this period already exists. Edit it instead?",
          () => loadForEdit(result.data.id),
          null,
          { type: "info", confirmText: "Edit existing draft" },
        );
        return;
      }
      Toast.error(result.message || "Failed to save draft");
      return;
    }

    currentRecordId = result.data.id;
    currentStatus = result.data.status;
    renderWarnings(result.warnings);
    updateDraftStatusLabel();
    Toast.success("Draft saved");
    loadRecentSubmissions();
  }

  async function handleSubmit() {
    if (!validateStep1()) {
      document.getElementById("step1Tab").click();
      return;
    }

    Toast.confirm(
      "Submit this month's demographics? You won't be able to edit it afterwards.",
      doSubmit,
      null,
      { type: "primary", confirmText: "Yes, Submit" },
    );
  }

  async function doSubmit() {
    const btn = document.getElementById("submitBtn");
    DemographicsUI.setButtonLoading(btn, "Submitting...");

    const payload = collectFormData();
    const saveResult = currentRecordId
      ? await DemographicsAPIHandler.updateDemographic(currentRecordId, payload)
      : await DemographicsAPIHandler.createDemographic(payload);

    if (!saveResult.success) {
      DemographicsUI.restoreButton(btn);
      Toast.error(saveResult.message || "Failed to save before submitting");
      return;
    }

    currentRecordId = saveResult.data.id;

    const submitResult = await DemographicsAPIHandler.submitDemographic(currentRecordId);
    DemographicsUI.restoreButton(btn);

    if (!submitResult.success) {
      Toast.error(submitResult.message || "Failed to submit");
      return;
    }

    currentStatus = submitResult.data.status;
    updateDraftStatusLabel();
    applyEditLock();
    Toast.success("Submitted successfully");
    loadRecentSubmissions();
  }

  function wireActions() {
    document.getElementById("saveDraftBtn").addEventListener("click", handleSaveDraft);
    document.getElementById("submitBtn").addEventListener("click", handleSubmit);
  }

  // ==========================================================================
  // RECENT SUBMISSIONS
  // ==========================================================================

  async function loadRecentSubmissions() {
    const tbody = document.getElementById("recentSubmissionsBody");
    tbody.innerHTML = DemographicsUI.renderTableLoading(4, "Loading recent submissions...");

    const result = await DemographicsAPIHandler.getDemographics(USER_TERRITORY.id);

    if (!result.success) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(4, "Could not load submissions");
      return;
    }

    const rows = (result.data || []).sort((a, b) => {
      const ay = a.fiscal_year?.year || 0;
      const by = b.fiscal_year?.year || 0;
      if (ay !== by) return by - ay;
      return (b.fiscal_month?.number || 0) - (a.fiscal_month?.number || 0);
    });

    // onView (not onEdit) for every row - loadForEdit()/applyEditLock() already
    // renders the full submission read-only when status isn't draft/changes_requested,
    // so one "View" button works whether or not the row is actually editable.
    tbody.innerHTML = DemographicsUI.renderSubmissionsRows(rows, { onView: "DemographicsTracking.loadForEdit" });

    DemographicsUI.renderFilterToolbar("submissionsFilterToolbar", {
      searchPlaceholder: "Search submissions...",
      filters: [
        {
          id: "submissionsStatusFilter",
          label: "All Statuses",
          options: [
            { value: "Draft", label: "Draft" },
            { value: "Approved", label: "Approved" },
            { value: "Flagged", label: "Flagged" },
            { value: "Changes Requested", label: "Changes Requested" },
          ],
        },
      ],
    });

    const table = DemographicsUI.initListDataTable("recentSubmissionsTable", {
      searchPlaceholder: "Search submissions...",
      order: [], // rows are already sorted newest-first - don't let DataTables re-sort column 0 alphabetically
      nonSortableColumns: [3],
      hideDefaultSearch: true,
    });

    DemographicsUI.wireFilterToolbar("submissionsFilterToolbar", table, [
      { id: "submissionsStatusFilter", columnIndex: 2, exact: true },
    ]);
  }

  async function loadForEdit(id) {
    const result = await DemographicsAPIHandler.getDemographic(id);

    if (!result.success) {
      Toast.error(result.message || "Could not load that submission");
      return;
    }

    populateForm(result.data);
    document.getElementById("step1Tab").click();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  return { init, loadForEdit };
})();

window.DemographicsTracking = DemographicsTracking;
