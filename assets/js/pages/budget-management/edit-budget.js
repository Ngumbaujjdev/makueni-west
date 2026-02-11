/**
 * ============================================================================
 * EDIT BUDGET PAGE JAVASCRIPT
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Handles editing of budgets in Draft or Rejected status:
 * - Load and display budget data
 * - Edit basic info (name, description, fiscal year, period)
 * - Expandable row editing for line items
 * - Add/Update/Delete line items
 * - Save changes to API
 *
 * Dependencies: api-handler.js, Constants.js, Choices.js, Toast.js
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // STATE VARIABLES
  // ========================================================================

  let budgetId = null;
  let budgetData = null;
  let originalBudgetData = null;
  let budgetLineItems = [];
  let budgetLineTemplates = [];
  let fiscalYears = [];
  let budgetTypes = [];
  let budgetPeriods = [];
  let selectedPeriodId = null;
  let hasUnsavedChanges = false;
  let expandedRowId = null;
  let lineToDelete = null;

  // Choices.js instances
  let fiscalYearChoices = null;
  let budgetTypeChoices = null;
  let budgetPeriodChoices = null;
  let newLineTypeChoices = null;
  let newBudgetLineChoices = null;

  // Editable statuses
  const EDITABLE_STATUSES = ["draft", "rejected"];

  // ========================================================================
  // DOM ELEMENTS
  // ========================================================================

  const elements = {
    // Loading and alerts
    loadingOverlay: document.getElementById("loadingOverlay"),
    nonEditableAlert: document.getElementById("nonEditableAlert"),
    nonEditableReason: document.getElementById("nonEditableReason"),
    editFormContainer: document.getElementById("editFormContainer"),

    // Header
    budgetNameHeader: document.getElementById("budgetNameHeader"),
    budgetStatusBadge: document.getElementById("budgetStatusBadge"),

    // Form fields
    form: document.getElementById("editBudgetForm"),
    budgetName: document.getElementById("budgetName"),
    fiscalYear: document.getElementById("fiscalYear"),
    budgetType: document.getElementById("budgetType"),
    budgetPeriod: document.getElementById("budgetPeriod"),
    startDate: document.getElementById("startDate"),
    endDate: document.getElementById("endDate"),
    description: document.getElementById("description"),
    territoryDisplay: document.getElementById("territoryDisplay"),

    // Lines table
    budgetLinesTable: document.getElementById("budgetLinesTable"),
    budgetLinesBody: document.getElementById("budgetLinesBody"),

    // Totals
    totalIncome: document.getElementById("totalIncome"),
    totalExpense: document.getElementById("totalExpense"),
    netBudget: document.getElementById("netBudget"),

    // Buttons
    addLineBtn: document.getElementById("addLineBtn"),
    saveBtn: document.getElementById("saveBtn"),
    saveCloseBtn: document.getElementById("saveCloseBtn"),
    cancelBtn: document.getElementById("cancelBtn"),

    // Add Line Modal
    addLineModal: document.getElementById("addLineModal"),
    addLineForm: document.getElementById("addLineForm"),
    newLineType: document.getElementById("newLineType"),
    newBudgetLine: document.getElementById("newBudgetLine"),
    newBudgetedAmount: document.getElementById("newBudgetedAmount"),
    newActualAmount: document.getElementById("newActualAmount"),
    newLineNotes: document.getElementById("newLineNotes"),
    confirmAddLineBtn: document.getElementById("confirmAddLineBtn"),

    // Delete Modal
    deleteLineModal: document.getElementById("deleteLineModal"),
    deleteLineName: document.getElementById("deleteLineName"),
    confirmDeleteLineBtn: document.getElementById("confirmDeleteLineBtn"),
  };

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  async function init() {
    console.log("📝 Edit Budget: Initializing...");

    // Get budget ID from global variable set in PHP
    budgetId = window.BUDGET_ID;

    if (!budgetId) {
      Toast.error("Invalid budget ID");
      window.location.href =
        "/makueni-west/diocese/budget-management/budget-overview/all-budgets";
      return;
    }

    // Initialize Choices.js for form fields
    initChoicesInstances();

    // Bind event listeners
    bindEventListeners();

    // Load all data
    await loadInitialData();
  }

  function initChoicesInstances() {
    // Fiscal Year dropdown
    fiscalYearChoices = new Choices(elements.fiscalYear, {
      searchEnabled: true,
      itemSelectText: "",
      placeholder: true,
      placeholderValue: "Select fiscal year",
      allowHTML: true,
    });

    // Budget Type dropdown
    budgetTypeChoices = new Choices(elements.budgetType, {
      searchEnabled: true,
      itemSelectText: "",
      placeholder: true,
      placeholderValue: "Select budget type",
      allowHTML: true,
    });

    // Budget Period dropdown
    budgetPeriodChoices = new Choices(elements.budgetPeriod, {
      searchEnabled: true,
      itemSelectText: "",
      placeholder: true,
      placeholderValue: "Select period",
      allowHTML: true,
    });

    // Add Line Modal - Line Type
    newLineTypeChoices = new Choices(elements.newLineType, {
      searchEnabled: false,
      itemSelectText: "",
      placeholder: true,
      placeholderValue: "Select type",
      allowHTML: true,
    });

    // Add Line Modal - Budget Line (disabled until type selected)
    newBudgetLineChoices = new Choices(elements.newBudgetLine, {
      searchEnabled: true,
      itemSelectText: "",
      placeholder: true,
      placeholderValue: "Select type first",
      allowHTML: true,
    });
    newBudgetLineChoices.disable();
  }

  function bindEventListeners() {
    // Form field changes
    elements.budgetName.addEventListener("input", markUnsavedChanges);
    elements.description.addEventListener("input", markUnsavedChanges);

    // Fiscal year change - reload periods
    elements.fiscalYear.addEventListener("change", handleFiscalYearChange);

    // Budget type change - reload periods
    elements.budgetType.addEventListener("change", handleBudgetTypeChange);

    // Budget period change - update dates
    elements.budgetPeriod.addEventListener("change", handleBudgetPeriodChange);

    // Add line button
    elements.addLineBtn.addEventListener("click", openAddLineModal);

    // Add line modal - type change
    elements.newLineType.addEventListener("change", handleNewLineTypeChange);

    // Confirm add line
    elements.confirmAddLineBtn.addEventListener("click", addNewLineItem);

    // Confirm delete line
    elements.confirmDeleteLineBtn.addEventListener("click", confirmDeleteLine);

    // Save buttons
    elements.saveBtn.addEventListener("click", saveChanges);
    elements.saveCloseBtn.addEventListener("click", saveAndClose);

    // Cancel with unsaved changes warning
    elements.cancelBtn.addEventListener("click", handleCancel);

    // Warn before leaving with unsaved changes
    window.addEventListener("beforeunload", handleBeforeUnload);
  }

  // ========================================================================
  // DATA LOADING
  // ========================================================================

  async function loadInitialData() {
    showLoading(true);

    try {
      // Load budget data first
      const budgetResult = await BudgetAPIHandler.getBudget(budgetId);

      if (!budgetResult.success) {
        Toast.error(budgetResult.message || "Failed to load budget");
        window.location.href =
          "/makueni-west/diocese/budget-management/budget-overview/all-budgets";
        return;
      }

      budgetData = budgetResult.data;
      originalBudgetData = JSON.parse(JSON.stringify(budgetData));

      console.log("📝 Budget Data loaded:", budgetData);

      // Check if budget is editable
      if (!isEditable(budgetData.status)) {
        showNonEditableMessage(budgetData.status);
        return;
      }

      // Load supporting data in parallel
      const [yearsResult, typesResult, linesResult] = await Promise.all([
        BudgetAPIHandler.getFiscalYears(true),
        BudgetAPIHandler.getBudgetTypes(),
        BudgetAPIHandler.getBudgetLines(),
      ]);

      if (yearsResult.success) {
        fiscalYears = yearsResult.data || [];
      }

      if (typesResult.success) {
        budgetTypes = typesResult.data || [];
      }

      if (linesResult.success) {
        budgetLineTemplates = linesResult.data || [];
      }

      // Populate form
      populateForm();

      // Load periods for current fiscal year and type
      if (budgetData.fiscal_year && budgetData.budget_type_id) {
        const yearObj = fiscalYears.find(
          (y) => y.year === budgetData.fiscal_year
        );
        if (yearObj) {
          await loadBudgetPeriods(yearObj.id, budgetData.budget_type_id, true);
        }
      }

      // Extract and render line items
      budgetLineItems = budgetData.line_items || [];
      renderBudgetLines();

      // Update totals
      updateTotals();
    } catch (error) {
      console.error("Error loading budget:", error);
      Toast.error("Failed to load budget data");
    } finally {
      showLoading(false);
    }
  }

  async function loadBudgetPeriods(
    fiscalYearId,
    budgetTypeId,
    isInitialLoad = false
  ) {
    try {
      budgetPeriodChoices.setChoices(
        [{ value: "", label: "Loading periods...", disabled: true }],
        "value",
        "label",
        true
      );

      const result = await BudgetAPIHandler.getBudgetPeriods(
        fiscalYearId,
        budgetTypeId
      );

      if (result.success && result.data) {
        budgetPeriods = result.data;

        const periodOptions = budgetPeriods.map((period) => ({
          value: period.id.toString(),
          label: period.name || `Period ${period.id}`,
          selected:
            isInitialLoad && budgetData.budget_period_id === period.id,
        }));

        if (periodOptions.length === 0) {
          periodOptions.push({
            value: "",
            label: "No periods available",
            disabled: true,
          });
        }

        budgetPeriodChoices.setChoices(periodOptions, "value", "label", true);

        // If initial load, set the selected period
        if (isInitialLoad && budgetData.budget_period_id) {
          budgetPeriodChoices.setChoiceByValue(
            budgetData.budget_period_id.toString()
          );
          selectedPeriodId = budgetData.budget_period_id;
        }
      } else {
        budgetPeriodChoices.setChoices(
          [{ value: "", label: "Failed to load periods", disabled: true }],
          "value",
          "label",
          true
        );
      }
    } catch (error) {
      console.error("Error loading periods:", error);
    }
  }

  // ========================================================================
  // FORM POPULATION
  // ========================================================================

  function populateForm() {
    // Update header
    elements.budgetNameHeader.textContent = budgetData.name || "Untitled Budget";
    updateStatusBadge(budgetData.status);

    // Basic info
    elements.budgetName.value = budgetData.name || "";
    elements.description.value = budgetData.description || "";

    // Fiscal Year
    const yearOptions = fiscalYears.map((year) => ({
      value: year.id.toString(),
      label: year.year.toString(),
      selected: year.year === budgetData.fiscal_year,
    }));
    fiscalYearChoices.setChoices(yearOptions, "value", "label", true);

    // Budget Type
    const typeOptions = budgetTypes.map((type) => ({
      value: type.id.toString(),
      label: type.name,
      selected: type.id === budgetData.budget_type_id,
    }));
    budgetTypeChoices.setChoices(typeOptions, "value", "label", true);

    // Dates
    elements.startDate.value = formatDate(budgetData.start_date);
    elements.endDate.value = formatDate(budgetData.end_date);

    // Territory
    if (budgetData.territory) {
      elements.territoryDisplay.textContent = budgetData.territory.name || budgetData.territory_type;
    }

    // Populate audit info
    populateAuditInfo();
  }

  function populateAuditInfo() {
    if (!budgetData) return;

    // Show the audit info card
    const auditInfoCard = document.getElementById("auditInfoCard");
    if (auditInfoCard) {
      auditInfoCard.style.display = "block";
    }

    // Creator Info
    const creatorNameEl = document.getElementById("budgetCreatorName");
    const createdDateEl = document.getElementById("budgetCreatedDate");

    if (creatorNameEl && budgetData.creator) {
      const creator = budgetData.creator;
      creatorNameEl.textContent =
        `${creator.firstname || ""} ${creator.lastname || ""}`.trim() || "System";
    }

    if (createdDateEl && budgetData.created_at) {
      const date = new Date(budgetData.created_at);
      createdDateEl.textContent = date.toLocaleString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    }

    // Updater Info
    const updaterNameEl = document.getElementById("budgetUpdaterName");
    const updatedDateEl = document.getElementById("budgetUpdatedDate");

    if (updaterNameEl) {
      if (budgetData.updater) {
        const updater = budgetData.updater;
        updaterNameEl.textContent =
          `${updater.firstname || ""} ${updater.lastname || ""}`.trim() || "System";
      } else {
        updaterNameEl.textContent = "Not updated yet";
      }
    }

    if (updatedDateEl) {
      if (budgetData.updated_at && budgetData.updated_by) {
        const date = new Date(budgetData.updated_at);
        updatedDateEl.textContent = date.toLocaleString("en-US", {
          year: "numeric",
          month: "short",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        });
      } else {
        updatedDateEl.textContent = "-";
      }
    }

    // Approver Info
    const approverNameEl = document.getElementById("budgetApproverName");
    const approvedDateEl = document.getElementById("budgetApprovedDate");

    if (approverNameEl) {
      if (budgetData.approver) {
        const approver = budgetData.approver;
        approverNameEl.textContent =
          `${approver.firstname || ""} ${approver.lastname || ""}`.trim() || "System";
      } else {
        approverNameEl.textContent = "Not approved yet";
      }
    }

    if (approvedDateEl) {
      if (budgetData.approved_at) {
        const date = new Date(budgetData.approved_at);
        approvedDateEl.textContent = date.toLocaleString("en-US", {
          year: "numeric",
          month: "short",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        });
      } else {
        approvedDateEl.textContent = "-";
      }
    }
  }

  function updateStatusBadge(status) {
    const statusConfig = {
      draft: { class: "bg-secondary", text: "Draft" },
      rejected: { class: "bg-danger", text: "Rejected" },
      submitted: { class: "bg-info", text: "Submitted" },
      under_review: { class: "bg-warning", text: "Under Review" },
      approved: { class: "bg-success", text: "Approved" },
      active: { class: "bg-primary", text: "Active" },
      closed: { class: "bg-dark", text: "Closed" },
    };

    const config = statusConfig[status] || {
      class: "bg-secondary",
      text: status,
    };
    elements.budgetStatusBadge.className = `badge status-badge ${config.class}`;
    elements.budgetStatusBadge.textContent = config.text;
  }

  // ========================================================================
  // BUDGET LINES RENDERING
  // ========================================================================

  function renderBudgetLines() {
    if (!budgetLineItems || budgetLineItems.length === 0) {
      elements.budgetLinesBody.innerHTML = `
        <tr>
          <td colspan="6" class="text-center text-muted py-4">
            <i class="ri-file-list-line fs-3 d-block mb-2"></i>
            No budget lines added yet. Click "Add Line" to add items.
          </td>
        </tr>
      `;
      return;
    }

    let html = "";
    budgetLineItems.forEach((item, index) => {
      const isExpanded = expandedRowId === item.id;
      const lineType = item.budget_line?.line_type || item.line_type || "expense";
      const lineName = item.budget_line?.name || item.name || "Unknown Line";
      const badgeClass =
        lineType === "income" ? "badge-income" : "badge-expense";

      // Main row
      html += `
        <tr class="line-item-row ${isExpanded ? "expanded" : ""}"
            data-line-id="${item.id}"
            onclick="EditBudget.toggleExpandRow(${item.id})">
          <td>${index + 1}</td>
          <td>
            <span class="badge ${badgeClass}">${capitalize(lineType)}</span>
          </td>
          <td>${escapeHtml(lineName)}</td>
          <td class="text-end">${formatCurrency(item.budgeted_amount)}</td>
          <td class="text-end">${formatCurrency(item.actual_amount || 0)}</td>
          <td class="text-center" onclick="event.stopPropagation();">
            <button type="button" class="btn btn-sm btn-icon btn-light me-1"
                    onclick="EditBudget.toggleExpandRow(${item.id})"
                    title="${isExpanded ? "Collapse" : "Expand"}">
              <i class="ri-arrow-${isExpanded ? "up" : "down"}-s-line"></i>
            </button>
            <button type="button" class="btn btn-sm btn-icon btn-danger-transparent"
                    onclick="EditBudget.showDeleteModal(${item.id}, '${escapeHtml(lineName)}')"
                    title="Delete">
              <i class="ri-delete-bin-line"></i>
            </button>
          </td>
        </tr>
      `;

      // Expanded edit row
      html += `
        <tr class="edit-row ${isExpanded ? "show" : ""}" id="edit-row-${item.id}">
          <td colspan="6" class="expanded-edit-form">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label small">Line Type</label>
                <input type="text" class="form-control form-control-sm" value="${capitalize(lineType)}" disabled>
              </div>
              <div class="col-md-3">
                <label class="form-label small">Line Item</label>
                <input type="text" class="form-control form-control-sm" value="${escapeHtml(lineName)}" disabled>
              </div>
              <div class="col-md-3">
                <label class="form-label small">Budgeted Amount <span class="text-danger">*</span></label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">KES</span>
                  <input type="number" class="form-control" id="edit-budgeted-${item.id}"
                         value="${item.budgeted_amount || 0}" min="0" step="0.01">
                </div>
              </div>
              <div class="col-md-3">
                <label class="form-label small">Actual Amount</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">KES</span>
                  <input type="number" class="form-control" id="edit-actual-${item.id}"
                         value="${item.actual_amount || 0}" min="0" step="0.01">
                </div>
              </div>
              <div class="col-md-9">
                <label class="form-label small">Notes</label>
                <input type="text" class="form-control form-control-sm" id="edit-notes-${item.id}"
                       value="${escapeHtml(item.notes || "")}" placeholder="Optional notes">
              </div>
              <div class="col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-secondary me-2" onclick="EditBudget.collapseRow(${item.id})">
                  Cancel
                </button>
                <button type="button" class="btn btn-sm btn-primary" onclick="EditBudget.saveLineItem(${item.id})">
                  <i class="ri-save-line me-1"></i>Save Line
                </button>
              </div>
            </div>
          </td>
        </tr>
      `;
    });

    elements.budgetLinesBody.innerHTML = html;
  }

  function toggleExpandRow(lineId) {
    if (expandedRowId === lineId) {
      // Collapse current row
      expandedRowId = null;
    } else {
      // Expand new row (collapse any other first)
      expandedRowId = lineId;
    }
    renderBudgetLines();
  }

  function collapseRow(lineId) {
    expandedRowId = null;
    renderBudgetLines();
  }

  // ========================================================================
  // LINE ITEM OPERATIONS
  // ========================================================================

  async function saveLineItem(lineId) {
    const budgetedInput = document.getElementById(`edit-budgeted-${lineId}`);
    const actualInput = document.getElementById(`edit-actual-${lineId}`);
    const notesInput = document.getElementById(`edit-notes-${lineId}`);

    if (!budgetedInput) {
      Toast.error("Cannot find input fields");
      return;
    }

    const budgetedAmount = parseFloat(budgetedInput.value) || 0;
    const actualAmount = parseFloat(actualInput?.value) || 0;
    const notes = notesInput?.value || "";

    if (budgetedAmount < 0) {
      Toast.warning("Budgeted amount cannot be negative");
      return;
    }

    try {
      Toast.info("Saving line item...");

      const result = await BudgetAPIHandler.updateLineItem(budgetId, lineId, {
        budgeted_amount: budgetedAmount,
        actual_amount: actualAmount,
        notes: notes,
      });

      if (result.success) {
        // Update local data
        const itemIndex = budgetLineItems.findIndex((item) => item.id === lineId);
        if (itemIndex !== -1) {
          budgetLineItems[itemIndex].budgeted_amount = budgetedAmount;
          budgetLineItems[itemIndex].actual_amount = actualAmount;
          budgetLineItems[itemIndex].notes = notes;
        }

        Toast.success("Line item updated");
        expandedRowId = null;
        renderBudgetLines();
        updateTotals();
      } else {
        Toast.error(result.message || "Failed to update line item");
      }
    } catch (error) {
      console.error("Error saving line item:", error);
      Toast.error("Failed to save line item");
    }
  }

  function openAddLineModal() {
    // Reset form
    elements.addLineForm.reset();
    newLineTypeChoices.setChoiceByValue("");
    newBudgetLineChoices.setChoices(
      [{ value: "", label: "Select type first", disabled: true }],
      "value",
      "label",
      true
    );
    newBudgetLineChoices.disable();

    // Show modal
    const modal = new bootstrap.Modal(elements.addLineModal);
    modal.show();
  }

  function handleNewLineTypeChange() {
    const selectedType = elements.newLineType.value;

    if (!selectedType) {
      newBudgetLineChoices.setChoices(
        [{ value: "", label: "Select type first", disabled: true }],
        "value",
        "label",
        true
      );
      newBudgetLineChoices.disable();
      return;
    }

    // Filter templates by type
    const filteredLines = budgetLineTemplates.filter(
      (line) => line.line_type === selectedType
    );

    // Exclude already used lines
    const usedLineIds = budgetLineItems.map((item) => item.budget_line_id);
    const availableLines = filteredLines.filter(
      (line) => !usedLineIds.includes(line.id)
    );

    if (availableLines.length === 0) {
      newBudgetLineChoices.setChoices(
        [{ value: "", label: "No available lines", disabled: true }],
        "value",
        "label",
        true
      );
      newBudgetLineChoices.disable();
      return;
    }

    const options = availableLines.map((line) => ({
      value: line.id.toString(),
      label: line.name,
    }));

    newBudgetLineChoices.enable();
    newBudgetLineChoices.setChoices(options, "value", "label", true);
  }

  async function addNewLineItem() {
    const lineType = elements.newLineType.value;
    const budgetLineId = elements.newBudgetLine.value;
    const budgetedAmount = parseFloat(elements.newBudgetedAmount.value) || 0;
    const actualAmount = parseFloat(elements.newActualAmount.value) || 0;
    const notes = elements.newLineNotes.value || "";

    if (!lineType || !budgetLineId) {
      Toast.warning("Please select line type and budget line");
      return;
    }

    if (budgetedAmount <= 0) {
      Toast.warning("Budgeted amount must be greater than 0");
      return;
    }

    try {
      Toast.info("Adding line item...");

      const result = await BudgetAPIHandler.addLineItem(budgetId, {
        budget_line_id: parseInt(budgetLineId),
        budgeted_amount: budgetedAmount,
        actual_amount: actualAmount,
        notes: notes,
      });

      if (result.success) {
        // Add to local array
        const newItem = result.data;

        // Find the template to get line details
        const template = budgetLineTemplates.find(
          (t) => t.id === parseInt(budgetLineId)
        );

        if (template) {
          newItem.budget_line = template;
          newItem.line_type = template.line_type;
          newItem.name = template.name;
        }

        budgetLineItems.push(newItem);

        Toast.success("Line item added");

        // Close modal
        bootstrap.Modal.getInstance(elements.addLineModal).hide();

        // Re-render
        renderBudgetLines();
        updateTotals();
      } else {
        Toast.error(result.message || "Failed to add line item");
      }
    } catch (error) {
      console.error("Error adding line item:", error);
      Toast.error("Failed to add line item");
    }
  }

  function showDeleteModal(lineId, lineName) {
    lineToDelete = lineId;
    elements.deleteLineName.textContent = lineName;

    const modal = new bootstrap.Modal(elements.deleteLineModal);
    modal.show();
  }

  async function confirmDeleteLine() {
    if (!lineToDelete) return;

    try {
      Toast.info("Deleting line item...");

      const result = await BudgetAPIHandler.deleteLineItem(budgetId, lineToDelete);

      if (result.success) {
        // Remove from local array
        budgetLineItems = budgetLineItems.filter((item) => item.id !== lineToDelete);

        Toast.success("Line item deleted");

        // Close modal
        bootstrap.Modal.getInstance(elements.deleteLineModal).hide();
        lineToDelete = null;

        // Re-render
        if (expandedRowId === lineToDelete) {
          expandedRowId = null;
        }
        renderBudgetLines();
        updateTotals();
      } else {
        Toast.error(result.message || "Failed to delete line item");
      }
    } catch (error) {
      console.error("Error deleting line item:", error);
      Toast.error("Failed to delete line item");
    }
  }

  // ========================================================================
  // EVENT HANDLERS
  // ========================================================================

  async function handleFiscalYearChange() {
    const selectedYearId = elements.fiscalYear.value;
    const selectedTypeId = elements.budgetType.value;

    if (!selectedYearId) return;

    markUnsavedChanges();

    if (selectedTypeId) {
      await loadBudgetPeriods(selectedYearId, selectedTypeId);
    }
  }

  async function handleBudgetTypeChange() {
    const selectedYearId = elements.fiscalYear.value;
    const selectedTypeId = elements.budgetType.value;

    if (!selectedTypeId) return;

    markUnsavedChanges();

    if (selectedYearId) {
      await loadBudgetPeriods(selectedYearId, selectedTypeId);
    }
  }

  function handleBudgetPeriodChange() {
    const selectedPeriod = budgetPeriods.find(
      (p) => p.id.toString() === elements.budgetPeriod.value
    );

    if (selectedPeriod) {
      selectedPeriodId = selectedPeriod.id;
      elements.startDate.value = formatDate(selectedPeriod.start_date);
      elements.endDate.value = formatDate(selectedPeriod.end_date);
      markUnsavedChanges();
    }
  }

  function handleCancel(e) {
    if (hasUnsavedChanges) {
      if (
        !confirm(
          "You have unsaved changes. Are you sure you want to leave this page?"
        )
      ) {
        e.preventDefault();
        return;
      }
    }
    hasUnsavedChanges = false;
    window.location.href =
      "/makueni-west/diocese/budget-management/budget-overview/all-budgets";
  }

  function handleBeforeUnload(e) {
    if (hasUnsavedChanges) {
      e.preventDefault();
      e.returnValue = "";
    }
  }

  // ========================================================================
  // SAVE OPERATIONS
  // ========================================================================

  async function saveChanges() {
    // Validate form
    if (!validateForm()) {
      return;
    }

    try {
      // Collect form data
      const yearObj = fiscalYears.find(
        (y) => y.id.toString() === elements.fiscalYear.value
      );

      const currentFormData = {
        name: elements.budgetName.value.trim(),
        description: elements.description.value.trim() || null,
        fiscal_year: yearObj ? yearObj.year : budgetData.fiscal_year,
        budget_type_id: parseInt(elements.budgetType.value),
        budget_period_id: parseInt(elements.budgetPeriod.value) || null,
      };

      // Detect changes - only send fields that have actually changed
      const updateData = {};
      const changedFields = [];

      if (currentFormData.name !== originalBudgetData.name) {
        updateData.name = currentFormData.name;
        changedFields.push("name");
      }

      if (currentFormData.description !== (originalBudgetData.description || null)) {
        updateData.description = currentFormData.description;
        changedFields.push("description");
      }

      if (currentFormData.fiscal_year !== originalBudgetData.fiscal_year) {
        updateData.fiscal_year = currentFormData.fiscal_year;
        changedFields.push("fiscal year");
      }

      if (currentFormData.budget_type_id !== originalBudgetData.budget_type_id) {
        updateData.budget_type_id = currentFormData.budget_type_id;
        changedFields.push("budget type");
      }

      if (currentFormData.budget_period_id !== (originalBudgetData.budget_period_id || null)) {
        updateData.budget_period_id = currentFormData.budget_period_id;
        changedFields.push("budget period");
      }

      // Check if there are any changes
      if (changedFields.length === 0) {
        Toast.info("No changes to save");
        return true;
      }

      Toast.info(`Saving changes (${changedFields.length} field${changedFields.length > 1 ? 's' : ''} changed)...`);

      const result = await BudgetAPIHandler.updateBudget(budgetId, updateData);

      if (result.success) {
        const changesSummary = changedFields.join(", ");
        Toast.success(`Budget saved successfully! Changed: ${changesSummary}`);
        hasUnsavedChanges = false;
        document.body.classList.remove("has-unsaved-changes");

        // Update header
        if (updateData.name) {
          elements.budgetNameHeader.textContent = updateData.name;
        }

        // Update budget data with new values
        budgetData = { ...budgetData, ...currentFormData };
        originalBudgetData = JSON.parse(JSON.stringify(budgetData));

        // Refresh audit info if data was updated
        populateAuditInfo();

        return true;
      } else {
        Toast.error(result.message || "Failed to save budget");
        return false;
      }
    } catch (error) {
      console.error("Error saving budget:", error);
      Toast.error("Failed to save budget");
      return false;
    }
  }

  async function saveAndClose() {
    const saved = await saveChanges();
    if (saved) {
      window.location.href =
        "/makueni-west/diocese/budget-management/budget-overview/all-budgets";
    }
  }

  function validateForm() {
    let isValid = true;

    // Budget name
    if (!elements.budgetName.value.trim()) {
      elements.budgetName.classList.add("is-invalid");
      Toast.warning("Budget name is required");
      isValid = false;
    } else {
      elements.budgetName.classList.remove("is-invalid");
    }

    // Fiscal year
    if (!elements.fiscalYear.value) {
      fiscalYearChoices.containerOuter.element.classList.add(
        "is-invalid-choices"
      );
      Toast.warning("Fiscal year is required");
      isValid = false;
    } else {
      fiscalYearChoices.containerOuter.element.classList.remove(
        "is-invalid-choices"
      );
    }

    // Budget type
    if (!elements.budgetType.value) {
      budgetTypeChoices.containerOuter.element.classList.add(
        "is-invalid-choices"
      );
      Toast.warning("Budget type is required");
      isValid = false;
    } else {
      budgetTypeChoices.containerOuter.element.classList.remove(
        "is-invalid-choices"
      );
    }

    return isValid;
  }

  // ========================================================================
  // UTILITY FUNCTIONS
  // ========================================================================

  function isEditable(status) {
    return EDITABLE_STATUSES.includes(status?.toLowerCase());
  }

  function showNonEditableMessage(status) {
    elements.editFormContainer.classList.add("d-none");
    elements.nonEditableAlert.classList.remove("d-none");
    elements.nonEditableReason.textContent = `This budget is in "${capitalize(status)}" status and cannot be edited. Only Draft or Rejected budgets can be modified.`;
    showLoading(false);
  }

  function showLoading(show) {
    if (elements.loadingOverlay) {
      elements.loadingOverlay.style.display = show ? "flex" : "none";
    }
  }

  function markUnsavedChanges() {
    hasUnsavedChanges = true;
    document.body.classList.add("has-unsaved-changes");
  }

  function updateTotals() {
    let totalIncome = 0;
    let totalExpense = 0;

    budgetLineItems.forEach((item) => {
      const lineType = item.budget_line?.line_type || item.line_type || "expense";
      const amount = parseFloat(item.budgeted_amount) || 0;

      if (lineType === "income") {
        totalIncome += amount;
      } else {
        totalExpense += amount;
      }
    });

    const netBudget = totalIncome - totalExpense;

    elements.totalIncome.textContent = formatCurrency(totalIncome);
    elements.totalExpense.textContent = formatCurrency(totalExpense);
    elements.netBudget.textContent = formatCurrency(netBudget);
    elements.netBudget.className =
      "fs-5 fw-bold " + (netBudget >= 0 ? "text-success" : "text-danger");
  }

  function formatCurrency(amount) {
    return `KES ${parseFloat(amount || 0).toLocaleString("en-KE", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  }

  function formatDate(dateString) {
    if (!dateString) return "";
    const date = new Date(dateString);
    return date.toLocaleDateString("en-GB", {
      day: "2-digit",
      month: "short",
      year: "numeric",
    });
  }

  function capitalize(str) {
    if (!str) return "";
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
  }

  function escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.EditBudget = {
    init,
    toggleExpandRow,
    collapseRow,
    saveLineItem,
    showDeleteModal,
  };

  // Initialize when DOM is ready
  document.addEventListener("DOMContentLoaded", init);

  console.log("✅ Edit Budget JS loaded");
})();
