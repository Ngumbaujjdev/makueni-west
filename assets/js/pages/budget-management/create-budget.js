/**
 * Create Budget Wizard
 * Clean implementation with Fiscal Periods API integration
 */
(function () {
  "use strict";

  // ============================================================================
  // STATE
  // ============================================================================

  let currentStep = 1;
  let budgetTypes = [];
  let budgetCategories = [];
  let budgetLineTemplates = [];
  let lineRowCounter = 0;
  let choicesInstances = {}; // Track Choices.js instances by row ID

  // Fiscal Period State
  let fiscalYears = [];
  let budgetPeriods = [];
  let selectedPeriodId = null;

  // Choices.js instances for Step 1 dropdowns
  let fiscalYearChoices = null;
  let budgetTypeChoices = null;
  let budgetPeriodChoices = null;

  // ============================================================================
  // DOM ELEMENTS
  // ============================================================================

  const elements = {
    form: null,
    // Tabs
    step1Tab: null,
    step2Tab: null,
    step3Tab: null,
    // Step 1 fields
    budgetName: null,
    fiscalYear: null,
    budgetType: null,
    budgetPeriod: null,
    startDate: null,
    endDate: null,
    description: null,
    // Step 2
    budgetLinesContainer: null,
    addLineBtn: null,
    totalIncome: null,
    totalExpense: null,
    netBudget: null,
    // Navigation buttons
    nextToStep2: null,
    backToStep1: null,
    nextToStep3: null,
    backToStep2: null,
    saveDraftBtn: null,
    submitBudgetBtn: null,
  };

  // ============================================================================
  // INITIALIZATION
  // ============================================================================

  function init() {
    console.log("🚀 Initializing Create Budget Wizard...");

    cacheDOM();
    bindEvents();
    loadInitialData();
    addBudgetLineRow(); // Add first empty row

    console.log("✅ Create Budget Wizard initialized");
  }

  function cacheDOM() {
    elements.form = document.getElementById("createBudgetForm");

    // Tabs
    elements.step1Tab = document.getElementById("step1Tab");
    elements.step2Tab = document.getElementById("step2Tab");
    elements.step3Tab = document.getElementById("step3Tab");

    // Step 1 fields
    elements.budgetName = document.getElementById("budgetName");
    elements.fiscalYear = document.getElementById("fiscalYear");
    elements.budgetType = document.getElementById("budgetType");
    elements.budgetPeriod = document.getElementById("budgetPeriod");
    elements.startDate = document.getElementById("startDate");
    elements.endDate = document.getElementById("endDate");
    elements.description = document.getElementById("description");

    // Step 2
    elements.budgetLinesContainer = document.getElementById(
      "budgetLinesContainer",
    );
    elements.addLineBtn = document.getElementById("addLineBtn");
    elements.totalIncome = document.getElementById("totalIncome");
    elements.totalExpense = document.getElementById("totalExpense");
    elements.netBudget = document.getElementById("netBudget");

    // Navigation buttons
    elements.nextToStep2 = document.getElementById("nextToStep2");
    elements.backToStep1 = document.getElementById("backToStep1");
    elements.nextToStep3 = document.getElementById("nextToStep3");
    elements.backToStep2 = document.getElementById("backToStep2");
    elements.saveDraftBtn = document.getElementById("saveDraftBtn");
    elements.submitBudgetBtn = document.getElementById("submitBudgetBtn");
  }

  function bindEvents() {
    // Navigation buttons
    elements.nextToStep2?.addEventListener("click", handleNextToStep2);
    elements.backToStep1?.addEventListener("click", () =>
      elements.step1Tab.click(),
    );
    elements.nextToStep3?.addEventListener("click", handleNextToStep3);
    elements.backToStep2?.addEventListener("click", () =>
      elements.step2Tab.click(),
    );

    // Add budget line button
    elements.addLineBtn?.addEventListener("click", addBudgetLineRow);

    // Form submission
    elements.form?.addEventListener("submit", handleSubmit);
    elements.saveDraftBtn?.addEventListener("click", handleSaveDraft);

    // Fiscal year change - reload periods when year changes
    elements.fiscalYear?.addEventListener("change", handleFiscalYearChange);

    // Budget type change - load periods from API
    elements.budgetType?.addEventListener("change", handleBudgetTypeChange);

    // Period change - set dates from API data
    elements.budgetPeriod?.addEventListener("change", handlePeriodChange);

    // Tab change tracking
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((tab) => {
      tab.addEventListener("shown.bs.tab", function (e) {
        const tabId = e.target.getAttribute("href");
        if (tabId === "#step1-basic") currentStep = 1;
        else if (tabId === "#step2-lines") currentStep = 2;
        else if (tabId === "#step3-review") {
          currentStep = 3;
          populateReviewStep();
        }
        console.log(`📍 Current step: ${currentStep}`);
      });
    });
  }

  // ============================================================================
  // DATA LOADING
  // ============================================================================

  async function loadInitialData() {
    try {
      console.log("📦 Loading initial data...");

      // Load fiscal years from API
      const yearsResult = await BudgetAPIHandler.getFiscalYears(true);
      if (yearsResult.success) {
        fiscalYears = yearsResult.data || [];
        populateFiscalYears();
        console.log(`✅ Loaded ${fiscalYears.length} fiscal years`);
      } else {
        console.error("❌ Failed to load fiscal years:", yearsResult.message);
        Toast.error("Failed to load fiscal years");
      }

      // Load budget types
      const typesResult = await BudgetAPIHandler.getBudgetTypes();
      if (typesResult.success) {
        budgetTypes = typesResult.data || [];
        populateBudgetTypes();
      }

      // Load budget categories
      const categoriesResult = await BudgetAPIHandler.getBudgetCategories();
      if (categoriesResult.success) {
        budgetCategories = categoriesResult.data || [];
      }

      // Load budget line templates
      const linesResult = await BudgetAPIHandler.getBudgetLines();
      if (linesResult.success) {
        budgetLineTemplates = linesResult.data || [];
        console.log(
          `✅ Loaded ${budgetLineTemplates.length} budget line templates`,
        );
      }
    } catch (error) {
      console.error("❌ Error loading initial data:", error);
      Toast.error("Failed to load form data. Please refresh.");
    }
  }

  /**
   * Populate fiscal years dropdown with Choices.js
   */
  function populateFiscalYears() {
    // Clear existing options
    elements.fiscalYear.innerHTML = "";

    // Add placeholder
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "Select Fiscal Year";
    placeholder.disabled = true;
    placeholder.selected = true;
    elements.fiscalYear.appendChild(placeholder);

    // Get current year to pre-select
    const currentYear = new Date().getFullYear();
    let currentYearId = null;

    // Add fiscal years from API
    fiscalYears.forEach((year) => {
      const option = document.createElement("option");
      option.value = year.id;
      option.textContent = year.year;
      option.dataset.year = year.year;

      if (year.year === currentYear) {
        currentYearId = year.id;
      }

      elements.fiscalYear.appendChild(option);
    });

    // Initialize Choices.js
    if (fiscalYearChoices) {
      fiscalYearChoices.destroy();
    }

    fiscalYearChoices = new Choices(elements.fiscalYear, {
      searchEnabled: true,
      searchPlaceholderValue: "Search year...",
      itemSelectText: "",
      allowHTML: false,
      placeholder: true,
      placeholderValue: "Select Fiscal Year",
    });

    // Pre-select current year if available
    if (currentYearId) {
      fiscalYearChoices.setChoiceByValue(String(currentYearId));
      console.log(`📅 Pre-selected fiscal year: ${currentYear}`);
    }
  }

  /**
   * Populate budget types dropdown with Choices.js
   */
  function populateBudgetTypes() {
    elements.budgetType.innerHTML = "";

    // Add placeholder
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "Select Budget Type";
    placeholder.disabled = true;
    placeholder.selected = true;
    elements.budgetType.appendChild(placeholder);

    budgetTypes.forEach((type) => {
      const option = document.createElement("option");
      option.value = type.id;
      option.textContent = type.name;
      option.dataset.slug = type.slug;
      elements.budgetType.appendChild(option);
    });

    // Initialize Choices.js
    if (budgetTypeChoices) {
      budgetTypeChoices.destroy();
    }

    budgetTypeChoices = new Choices(elements.budgetType, {
      searchEnabled: false,
      itemSelectText: "",
      allowHTML: false,
      placeholder: true,
      placeholderValue: "Select Budget Type",
    });
  }

  // ============================================================================
  // STEP 1: BASIC INFORMATION - FISCAL PERIODS INTEGRATION
  // ============================================================================

  /**
   * Handle fiscal year change - reload periods if type is selected
   */
  function handleFiscalYearChange() {
    const yearId = elements.fiscalYear.value;
    const typeId = elements.budgetType.value;

    console.log(`📅 Fiscal year changed to ID: ${yearId}`);

    // Reset period and dates
    resetPeriodSelection();

    // If both year and type are selected, load periods
    if (yearId && typeId) {
      loadBudgetPeriods(yearId, typeId);
    }
  }

  /**
   * Handle budget type change - load periods from API
   */
  async function handleBudgetTypeChange() {
    const yearId = elements.fiscalYear.value;
    const typeId = elements.budgetType.value;

    console.log(`📊 Budget type changed to ID: ${typeId}`);

    // Reset period and dates
    resetPeriodSelection();

    if (!yearId) {
      Toast.warning("Please select a fiscal year first");
      return;
    }

    if (!typeId) {
      return;
    }

    await loadBudgetPeriods(yearId, typeId);
  }

  /**
   * Load budget periods from API based on fiscal year and budget type
   */
  async function loadBudgetPeriods(fiscalYearId, budgetTypeId) {
    try {
      console.log(
        `📡 Loading periods for year: ${fiscalYearId}, type: ${budgetTypeId}`,
      );

      // Show loading state
      if (budgetPeriodChoices) {
        budgetPeriodChoices.destroy();
        budgetPeriodChoices = null;
      }

      elements.budgetPeriod.innerHTML =
        '<option value="">Loading periods...</option>';
      elements.budgetPeriod.disabled = true;

      const result = await BudgetAPIHandler.getBudgetPeriods(
        fiscalYearId,
        budgetTypeId,
      );

      if (result.success) {
        budgetPeriods = result.data || [];
        console.log(`✅ Loaded ${budgetPeriods.length} budget periods`);
        populateBudgetPeriods();
      } else {
        console.error("❌ Failed to load periods:", result.message);
        Toast.error("Failed to load budget periods");
        elements.budgetPeriod.innerHTML =
          '<option value="">No periods available</option>';
      }
    } catch (error) {
      console.error("❌ Error loading periods:", error);
      Toast.error("Error loading budget periods");
    }
  }

  /**
   * Populate budget periods dropdown with Choices.js
   */
  function populateBudgetPeriods() {
    elements.budgetPeriod.innerHTML = "";
    elements.budgetPeriod.disabled = false;

    // Add placeholder
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "Select Period";
    placeholder.disabled = true;
    placeholder.selected = true;
    elements.budgetPeriod.appendChild(placeholder);

    if (budgetPeriods.length === 0) {
      const noOption = document.createElement("option");
      noOption.value = "";
      noOption.textContent = "No periods available for this selection";
      noOption.disabled = true;
      elements.budgetPeriod.appendChild(noOption);
      return;
    }

    // Add periods from API
    budgetPeriods.forEach((period) => {
      const option = document.createElement("option");
      option.value = period.id;
      option.textContent = period.name;
      option.dataset.startDate = period.start_date;
      option.dataset.endDate = period.end_date;
      elements.budgetPeriod.appendChild(option);
    });

    // Initialize Choices.js
    if (budgetPeriodChoices) {
      budgetPeriodChoices.destroy();
    }

    budgetPeriodChoices = new Choices(elements.budgetPeriod, {
      searchEnabled: budgetPeriods.length > 6,
      searchPlaceholderValue: "Search period...",
      itemSelectText: "",
      allowHTML: false,
      placeholder: true,
      placeholderValue: "Select Period",
    });

    console.log(
      `✅ Budget periods dropdown populated with ${budgetPeriods.length} options`,
    );
  }

  /**
   * Handle period selection - set dates from API data
   */
  function handlePeriodChange() {
    const periodId = elements.budgetPeriod.value;

    if (!periodId) {
      elements.startDate.value = "";
      elements.endDate.value = "";
      selectedPeriodId = null;
      return;
    }

    // Find the selected period from our stored array
    const selectedPeriod = budgetPeriods.find(
      (p) => String(p.id) === String(periodId),
    );

    if (selectedPeriod) {
      elements.startDate.value = selectedPeriod.start_date;
      elements.endDate.value = selectedPeriod.end_date;
      selectedPeriodId = selectedPeriod.id;

      console.log(`📅 Period selected: ${selectedPeriod.name}`);
      console.log(
        `   Start: ${selectedPeriod.start_date}, End: ${selectedPeriod.end_date}`,
      );
    } else {
      console.warn(`⚠️ Period not found in stored data: ${periodId}`);
      // Fallback to data attributes
      const selectedOption = elements.budgetPeriod.selectedOptions[0];
      if (selectedOption) {
        elements.startDate.value = selectedOption.dataset.startDate || "";
        elements.endDate.value = selectedOption.dataset.endDate || "";
        selectedPeriodId = parseInt(periodId);
      }
    }
  }

  /**
   * Reset period selection and dates
   */
  function resetPeriodSelection() {
    budgetPeriods = [];
    selectedPeriodId = null;
    elements.startDate.value = "";
    elements.endDate.value = "";

    // Reset period dropdown
    if (budgetPeriodChoices) {
      budgetPeriodChoices.destroy();
      budgetPeriodChoices = null;
    }

    elements.budgetPeriod.innerHTML =
      '<option value="">Select type first</option>';
    elements.budgetPeriod.disabled = true;
  }

  function validateStep1() {
    const required = [
      elements.budgetName,
      elements.fiscalYear,
      elements.budgetType,
      elements.budgetPeriod,
    ];

    let isValid = true;

    required.forEach((field) => {
      if (!field.value.trim()) {
        field.classList.add("is-invalid");
        // Also highlight Choices.js container
        const choicesContainer = field.closest(".choices");
        if (choicesContainer) {
          choicesContainer.classList.add("is-invalid-choices");
        }
        isValid = false;
      } else {
        field.classList.remove("is-invalid");
        const choicesContainer = field.closest(".choices");
        if (choicesContainer) {
          choicesContainer.classList.remove("is-invalid-choices");
        }
      }
    });

    // Validate that a period is selected and dates are set
    if (!selectedPeriodId) {
      Toast.warning("Please select a budget period");
      return false;
    }

    return isValid;
  }

  function handleNextToStep2() {
    if (!validateStep1()) {
      Toast.warning("Please fill in all required fields");
      return;
    }
    elements.step2Tab.click();
  }

  // ============================================================================
  // STEP 2: BUDGET LINES
  // ============================================================================

  function addBudgetLineRow() {
    lineRowCounter++;
    const rowId = `line-row-${lineRowCounter}`;

    const row = document.createElement("div");
    row.className = "row g-3 mb-3 budget-line-row align-items-end";
    row.id = rowId;

    row.innerHTML = `
      <div class="col-md-2">
        <label class="form-label">Type</label>
        <select class="form-select line-type">
          <option value="">Select</option>
          <option value="income">Income</option>
          <option value="expense">Expense</option>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Budget Line</label>
        <select class="form-select line-budget-line" disabled>
          <option value="">Select type first</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Amount</label>
        <input type="number" class="form-control line-amount" placeholder="0.00" min="0" step="0.01">
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-danger btn-remove-line w-100">
          <i class="ri-delete-bin-line"></i>
        </button>
      </div>
    `;

    // Bind events for this row
    const typeSelect = row.querySelector(".line-type");
    const lineSelect = row.querySelector(".line-budget-line");
    const amountInput = row.querySelector(".line-amount");
    const removeBtn = row.querySelector(".btn-remove-line");

    typeSelect.addEventListener("change", () =>
      handleTypeChange(typeSelect, lineSelect, rowId),
    );
    amountInput.addEventListener("input", calculateTotals);
    removeBtn.addEventListener("click", () => removeBudgetLineRow(rowId));

    elements.budgetLinesContainer.appendChild(row);
    console.log(`✅ Added budget line row: ${rowId}`);
  }

  function removeBudgetLineRow(rowId) {
    const row = document.getElementById(rowId);
    if (row) {
      // Destroy Choices instance if exists
      if (choicesInstances[rowId]) {
        choicesInstances[rowId].destroy();
        delete choicesInstances[rowId];
      }
      row.remove();
      calculateTotals();
    }
  }

  function handleTypeChange(typeSelect, lineSelect, rowId) {
    const type = typeSelect.value;

    // Destroy existing Choices instance if any
    if (choicesInstances[rowId]) {
      choicesInstances[rowId].destroy();
      delete choicesInstances[rowId];
    }

    lineSelect.innerHTML = '<option value="">Select Budget Line</option>';
    lineSelect.disabled = !type;

    if (!type) return;

    // Filter lines by type (category slug) and territory
    const userTerritory = window.USER_TERRITORY?.type || "diocese";

    let filteredLines = budgetLineTemplates.filter((line) => {
      const categorySlug = line.budget_category?.slug || "";
      return categorySlug === type;
    });

    // Sort by territory: user's territory first, then "all", then others
    filteredLines.sort((a, b) => {
      const aScope = a.territory_scope || "";
      const bScope = b.territory_scope || "";

      if (aScope === userTerritory && bScope !== userTerritory) return -1;
      if (bScope === userTerritory && aScope !== userTerritory) return 1;
      if (aScope === "all" && bScope !== "all") return -1;
      if (bScope === "all" && aScope !== "all") return 1;
      return 0;
    });

    // Populate dropdown
    filteredLines.forEach((line) => {
      const option = document.createElement("option");
      option.value = line.id;
      const scopeLabel =
        line.territory_scope === "all"
          ? "All"
          : line.territory_scope.charAt(0).toUpperCase() +
            line.territory_scope.slice(1);
      option.textContent = `${line.name} (${scopeLabel})`;
      option.dataset.categoryId = line.budget_category_id;
      lineSelect.appendChild(option);
    });

    // Initialize Choices.js for searchable dropdown
    try {
      choicesInstances[rowId] = new Choices(lineSelect, {
        searchEnabled: true,
        searchPlaceholderValue: "Search budget lines...",
        itemSelectText: "",
        allowHTML: false,
        shouldSort: false, // Keep our custom sort order
        placeholder: true,
        placeholderValue: "Select Budget Line",
      });
      console.log(`✅ Choices.js initialized for row: ${rowId}`);
    } catch (error) {
      console.warn(
        `⚠️ Choices.js initialization failed for row ${rowId}:`,
        error,
      );
    }

    console.log(
      `🔍 Filtered ${filteredLines.length} ${type} lines for territory: ${userTerritory}`,
    );
  }

  function calculateTotals() {
    let totalIncome = 0;
    let totalExpense = 0;

    document.querySelectorAll(".budget-line-row").forEach((row) => {
      const type = row.querySelector(".line-type").value;
      const amount = parseFloat(row.querySelector(".line-amount").value) || 0;

      if (type === "income") totalIncome += amount;
      else if (type === "expense") totalExpense += amount;
    });

    const net = totalIncome - totalExpense;

    elements.totalIncome.textContent = `KES ${formatCurrency(totalIncome)}`;
    elements.totalExpense.textContent = `KES ${formatCurrency(totalExpense)}`;
    elements.netBudget.textContent = `KES ${formatCurrency(net)}`;
    elements.netBudget.className = `fs-5 fw-bold ${net >= 0 ? "text-success" : "text-danger"}`;
  }

  function formatCurrency(amount) {
    return amount.toLocaleString("en-KE", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function validateStep2() {
    const rows = document.querySelectorAll(".budget-line-row");
    let hasValidLine = false;

    rows.forEach((row) => {
      const type = row.querySelector(".line-type").value;
      const lineId = row.querySelector(".line-budget-line").value;
      const amount = parseFloat(row.querySelector(".line-amount").value) || 0;

      if (type && lineId && amount > 0) {
        hasValidLine = true;
      }
    });

    return hasValidLine;
  }

  function handleNextToStep3() {
    if (!validateStep2()) {
      Toast.warning("Please add at least one budget line with an amount");
      return;
    }
    elements.step3Tab.click();
  }

  // ============================================================================
  // STEP 3: REVIEW & SUBMIT
  // ============================================================================

  function populateReviewStep() {
    // Basic info
    document.getElementById("reviewBudgetName").textContent =
      elements.budgetName.value || "-";

    // Get fiscal year display text
    const selectedYearOption = elements.fiscalYear.selectedOptions[0];
    document.getElementById("reviewFiscalYear").textContent =
      selectedYearOption?.dataset?.year || selectedYearOption?.textContent || "-";

    document.getElementById("reviewBudgetType").textContent =
      elements.budgetType.selectedOptions[0]?.textContent || "-";
    document.getElementById("reviewPeriod").textContent =
      elements.budgetPeriod.selectedOptions[0]?.textContent || "-";
    document.getElementById("reviewStartDate").textContent =
      elements.startDate.value || "-";
    document.getElementById("reviewEndDate").textContent =
      elements.endDate.value || "-";
    document.getElementById("reviewDescription").textContent =
      elements.description.value || "No description";

    // Budget lines
    const tbody = document.getElementById("reviewLinesTable");
    tbody.innerHTML = "";

    let totalIncome = 0;
    let totalExpense = 0;
    let lineNum = 0;

    document.querySelectorAll(".budget-line-row").forEach((row) => {
      const type = row.querySelector(".line-type").value;
      const lineSelect = row.querySelector(".line-budget-line");
      const lineName = lineSelect.selectedOptions[0]?.textContent || "-";
      const amount = parseFloat(row.querySelector(".line-amount").value) || 0;

      if (type && lineSelect.value && amount > 0) {
        lineNum++;

        if (type === "income") totalIncome += amount;
        else totalExpense += amount;

        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${lineNum}</td>
          <td><span class="badge bg-${type === "income" ? "success" : "danger"}">${type.toUpperCase()}</span></td>
          <td>${lineName}</td>
          <td class="text-end fw-semibold">KES ${formatCurrency(amount)}</td>
        `;
        tbody.appendChild(tr);
      }
    });

    if (lineNum === 0) {
      tbody.innerHTML =
        '<tr><td colspan="4" class="text-center text-muted">No lines added</td></tr>';
    }

    // Totals
    const net = totalIncome - totalExpense;
    document.getElementById("reviewTotalIncome").textContent =
      `KES ${formatCurrency(totalIncome)}`;
    document.getElementById("reviewTotalExpense").textContent =
      `KES ${formatCurrency(totalExpense)}`;
    document.getElementById("reviewNetBudget").textContent =
      `KES ${formatCurrency(net)}`;
    document.getElementById("reviewNetBudget").className =
      `fs-5 fw-bold ${net >= 0 ? "text-success" : "text-danger"}`;
  }

  /**
   * Collect form data for API submission
   * Now includes budget_period_id from fiscal periods API
   */
  function collectFormData(status = "active") {
    const lines = [];

    document.querySelectorAll(".budget-line-row").forEach((row) => {
      const type = row.querySelector(".line-type").value;
      const lineSelect = row.querySelector(".line-budget-line");
      const amount = parseFloat(row.querySelector(".line-amount").value) || 0;

      if (type && lineSelect.value && amount > 0) {
        lines.push({
          budget_line_id: parseInt(lineSelect.value),
          line_type: type,
          budgeted_amount: amount,
          actual_amount: 0,
          notes: null,
        });
      }
    });

    // Get fiscal year value (the actual year number for display/reference)
    const selectedYearOption = elements.fiscalYear.selectedOptions[0];
    const fiscalYearValue = selectedYearOption?.dataset?.year
      ? parseInt(selectedYearOption.dataset.year)
      : parseInt(selectedYearOption?.textContent) || new Date().getFullYear();

    return {
      name: elements.budgetName.value,
      fiscal_year: fiscalYearValue,
      budget_type_id: parseInt(elements.budgetType.value),
      budget_period_id: selectedPeriodId, // NEW: From fiscal periods API
      territory_type: window.USER_TERRITORY?.type || "diocese",
      territory_id: window.USER_TERRITORY?.id || 1,
      start_date: elements.startDate.value,
      end_date: elements.endDate.value,
      description: elements.description.value || null,
      status: status,
      items: lines,
    };
  }

  async function handleSubmit(e) {
    e.preventDefault();

    const data = collectFormData("active");

    if (data.items.length === 0) {
      Toast.warning("Please add at least one budget line");
      return;
    }

    // Validate budget_period_id is set
    if (!data.budget_period_id) {
      Toast.warning("Please select a valid budget period");
      return;
    }

    try {
      elements.submitBudgetBtn.disabled = true;
      elements.submitBudgetBtn.innerHTML =
        '<i class="ri-loader-4-line ri-spin me-1"></i>Creating...';

      console.log("📤 Submitting budget data:", data);

      const result = await BudgetAPIHandler.createBudget(data);

      if (result.success) {
        Toast.success("Budget created successfully!");
        setTimeout(() => {
          window.location.href =
            "/makueni-west/diocese/budget-management/budget-overview/all-budgets";
        }, 1500);
      } else {
        throw new Error(result.message || "Failed to create budget");
      }
    } catch (error) {
      console.error("❌ Submit error:", error);
      Toast.error(error.message || "Failed to create budget");
      elements.submitBudgetBtn.disabled = false;
      elements.submitBudgetBtn.innerHTML =
        '<i class="ri-check-line me-1"></i>Create Budget';
    }
  }

  async function handleSaveDraft() {
    const data = collectFormData("draft");

    try {
      elements.saveDraftBtn.disabled = true;
      elements.saveDraftBtn.innerHTML =
        '<i class="ri-loader-4-line ri-spin me-1"></i>Saving...';

      console.log("📤 Saving draft:", data);

      const result = await BudgetAPIHandler.createBudget(data);

      if (result.success) {
        Toast.success("Draft saved successfully!");
        setTimeout(() => {
          window.location.href =
            "/makueni-west/diocese/budget-management/budget-overview/all-budgets";
        }, 1500);
      } else {
        throw new Error(result.message || "Failed to save draft");
      }
    } catch (error) {
      console.error("❌ Save draft error:", error);
      Toast.error(error.message || "Failed to save draft");
      elements.saveDraftBtn.disabled = false;
      elements.saveDraftBtn.innerHTML =
        '<i class="ri-save-line me-1"></i>Save as Draft';
    }
  }

  // ============================================================================
  // INIT ON DOM READY
  // ============================================================================

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
