/**
 * ============================================================================
 * BUDGET LINES MANAGEMENT
 * ============================================================================
 * Handles CRUD operations for budget lines
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // STATE MANAGEMENT
  // ========================================================================

  let currentTerritoryScope = "";
  let currentBudgetLines = [];
  let budgetCategories = [];
  let isInitialized = false;
  let editingLineId = null;

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.BudgetLinesManagement = {
    init: initializeBudgetLines,
    loadBudgetLines: loadBudgetLines,
    loadCategories: loadCategories,
    openCreateModal: openCreateModal,
    openEditModal: openEditModal,
    openViewModal: openViewModal,
    saveBudgetLine: saveBudgetLine,
    deleteBudgetLine: deleteBudgetLine,
  };

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  function initializeBudgetLines() {
    if (isInitialized) {
      console.log(
        "⏭️ Budget Lines Management already initialized, skipping..."
      );
      loadBudgetLines();
      return;
    }

    console.log("🔧 Initializing Budget Lines Management...");
    setupEventListeners();
    loadCategories();
    loadBudgetLines();
    isInitialized = true;
  }

  function setupEventListeners() {
    // Territory filter - auto-load on change
    const territoryFilter = document.getElementById("territoryFilter");
    if (territoryFilter) {
      territoryFilter.addEventListener("change", () => {
        currentTerritoryScope = territoryFilter.value;
        console.log(
          "🔄 Territory scope changed to:",
          currentTerritoryScope || "All"
        );
        // Automatically reload data when filter changes
        loadBudgetLines();
      });
    }

    // Create budget line button
    const createBtn = document.getElementById("createBudgetLineBtn");
    if (createBtn) {
      createBtn.addEventListener("click", openCreateModal);
    }

    // Save budget line button
    const saveBtn = document.getElementById("saveBudgetLineBtn");
    if (saveBtn) {
      saveBtn.addEventListener("click", saveBudgetLine);
    }

    // Delete confirmation button
    const confirmDeleteBtn = document.getElementById("confirmDeleteBtn");
    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener("click", confirmDelete);
    }
  }

  // ========================================================================
  // DATA LOADING
  // ========================================================================

  async function loadCategories() {
    try {
      const API_BASE =
        window.AppConfig.API_BASE_URL;
      const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

      const response = await fetch(`${API_BASE}/budget-categories`, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${authToken}`,
        },
      });

      const result = await response.json();

      if (result.success && result.data) {
        budgetCategories = result.data;
        console.log("✅ Categories loaded:", budgetCategories.length);
        populateCategoryDropdown();
      }
    } catch (error) {
      console.error("❌ Error loading categories:", error);
    }
  }

  function populateCategoryDropdown() {
    const categorySelect = document.getElementById("categoryId");
    if (!categorySelect) return;

    categorySelect.innerHTML = '<option value="">Select Category</option>';
    budgetCategories.forEach((category) => {
      const option = document.createElement("option");
      option.value = category.id;
      option.textContent = category.name;
      categorySelect.appendChild(option);
    });
  }

  async function loadBudgetLines() {
    try {
      showLoadingInAllContainers();

      console.log("📡 Fetching budget lines...");

      const API_BASE =
        window.AppConfig.API_BASE_URL;
      const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

      let url = `${API_BASE}/budget-lines/grouped`;
      if (currentTerritoryScope) {
        url += `?territory_scope=${currentTerritoryScope}`;
      }

      const response = await fetch(url, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${authToken}`,
        },
      });

      const result = await response.json();
      console.log("📦 API Response:", result);

      if (result.success && result.data) {
        console.log(
          "✅ Budget lines loaded:",
          result.data.length,
          "categories"
        );
        console.log("📊 Sample category structure:", result.data[0]);
        currentBudgetLines = result.data;

        // Render all three tabs
        renderAllLines(result.data);
        renderIncomeLines(result.data);
        renderExpenseLines(result.data);
      } else {
        throw new Error(result.message || "Failed to load budget lines");
      }
    } catch (error) {
      console.error("❌ Error loading budget lines:", error);
      showErrorInAllContainers(error.message);
      Toast.error("Failed to load budget lines: " + error.message);
    }
  }

  function showLoadingInAllContainers() {
    const containers = [
      "allLinesContainer",
      "incomeLinesContainer",
      "expenseLinesContainer",
    ];
    let foundCount = 0;
    containers.forEach((containerId) => {
      const container = document.getElementById(containerId);
      if (container) {
        foundCount++;
        container.innerHTML = `
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted mt-2 mb-0">Loading budget lines...</p>
                    </div>`;
      } else {
        console.error(`❌ Container not found: ${containerId}`);
      }
    });
    console.log(`✅ Found ${foundCount}/${containers.length} containers`);
  }

  function showErrorInAllContainers(message) {
    const containers = [
      "allLinesContainer",
      "incomeLinesContainer",
      "expenseLinesContainer",
    ];
    containers.forEach((containerId) => {
      const container = document.getElementById(containerId);
      if (container) {
        container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="ri-list-check fs-48 text-danger mb-3 d-block"></i>
                        <p class="text-danger mb-0">Failed to load budget lines</p>
                        <small class="text-muted">${message}</small>
                    </div>`;
      }
    });
  }

  // ========================================================================
  // RENDERING
  // ========================================================================

  function renderAllLines(categories) {
    console.log("🎨 Rendering ALL lines with", categories.length, "categories");
    renderBudgetLinesInContainer("allLinesContainer", categories, "all");
  }

  function renderIncomeLines(categories) {
    // Filter income categories (slug contains 'income')
    const incomeCategories = categories.filter(
      (cat) => cat.slug && cat.slug.toLowerCase().includes("income")
    );
    console.log(
      "🎨 Rendering INCOME lines with",
      incomeCategories.length,
      "categories"
    );
    renderBudgetLinesInContainer(
      "incomeLinesContainer",
      incomeCategories,
      "income"
    );
  }

  function renderExpenseLines(categories) {
    // Filter expense categories (slug contains 'expense')
    const expenseCategories = categories.filter(
      (cat) => cat.slug && cat.slug.toLowerCase().includes("expense")
    );
    console.log(
      "🎨 Rendering EXPENSE lines with",
      expenseCategories.length,
      "categories"
    );
    renderBudgetLinesInContainer(
      "expenseLinesContainer",
      expenseCategories,
      "expense"
    );
  }

  function renderBudgetLinesInContainer(containerId, categories, type) {
    console.log(`📍 Rendering in ${containerId} for type "${type}"`);
    const container = document.getElementById(containerId);
    if (!container) {
      console.error(`❌ Container "${containerId}" not found in DOM`);
      return;
    }

    console.log(`✅ Container "${containerId}" found`);

    if (!categories || categories.length === 0) {
      console.log(`⚠️ No categories to display for type "${type}"`);
      container.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-list-check fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No ${type} budget lines found</p>
                </div>`;
      return;
    }

    console.log(`📦 Processing ${categories.length} categories for "${type}"`);

    let html = "";

    categories.forEach((category) => {
      const lines = category.budget_lines || [];
      const linesCount = lines.length;
      console.log(`  - Category "${category.name}" has ${linesCount} lines`);

      html += `
                <div class="card mb-3 border">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-folder-3-line me-2 text-primary"></i>${escapeHtml(
                                      category.name
                                    )}
                                </h6>
                                <small class="text-muted">${linesCount} line${
        linesCount !== 1 ? "s" : ""
      }</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">`;

      if (lines.length === 0) {
        html += `
                    <div class="text-center py-4">
                        <p class="text-muted mb-0">No lines in this category</p>
                    </div>`;
      } else {
        html += `
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Territory Scope</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>`;

        lines.forEach((line) => {
          const statusBadge = line.is_active
            ? '<span class="badge bg-success-transparent">Active</span>'
            : '<span class="badge bg-secondary-transparent">Inactive</span>';

          const typeBadge = line.is_system_default
            ? '<span class="badge bg-info-transparent">System</span>'
            : '<span class="badge bg-warning-transparent">Custom</span>';

          const canDelete = !line.is_system_default;

          html += `
                        <tr>
                            <td>
                                <div class="fw-semibold">${escapeHtml(
                                  line.name
                                )}</div>
                                ${
                                  line.description
                                    ? `<small class="text-muted">${escapeHtml(
                                        line.description
                                      )}</small>`
                                    : ""
                                }
                            </td>
                            <td>
                                <span class="badge bg-primary-transparent">${formatTerritoryScope(
                                  line.territory_scope
                                )}</span>
                            </td>
                            <td>${typeBadge}</td>
                            <td>${statusBadge}</td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-settings-3-line me-1"></i>Actions
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a class="dropdown-item" href="javascript:void(0);" onclick="BudgetLinesManagement.openViewModal(${
                                              line.id
                                            })">
                                                <i class="ri-eye-line me-2 text-primary"></i>View Details
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="javascript:void(0);" onclick="BudgetLinesManagement.openEditModal(${
                                              line.id
                                            })">
                                                <i class="ri-edit-line me-2 text-info"></i>Edit
                                            </a>
                                        </li>
                                        ${
                                          canDelete
                                            ? `
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="BudgetLinesManagement.deleteBudgetLine(${
                                                  line.id
                                                }, '${escapeHtml(line.name)}')">
                                                    <i class="ri-delete-bin-line me-2"></i>Delete
                                                </a>
                                            </li>
                                        `
                                            : `
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-muted" href="javascript:void(0);" disabled style="cursor: not-allowed;">
                                                    <i class="ri-lock-line me-2"></i>Protected
                                                </a>
                                            </li>
                                        `
                                        }
                                    </ul>
                                </div>
                            </td>
                        </tr>`;
        });

        html += `
                            </tbody>
                        </table>
                    </div>`;
      }

      html += `
                    </div>
                </div>`;
    });

    container.innerHTML = html;
  }

  // ========================================================================
  // MODAL OPERATIONS
  // ========================================================================

  function openCreateModal() {
    editingLineId = null;

    document.getElementById("modalTitle").textContent = "Create Budget Line";
    document.getElementById("budgetLineId").value = "";
    document.getElementById("lineName").value = "";
    document.getElementById("categoryId").value = "";
    document.getElementById("territoryScope").value = "";
    document.getElementById("displayOrder").value = "";
    document.getElementById("lineDescription").value = "";
    document.getElementById("lineActive").checked = true;

    const modal = new bootstrap.Modal(
      document.getElementById("budgetLineModal")
    );
    modal.show();
  }

  function openEditModal(lineId) {
    // Find line in current data
    let line = null;
    for (const category of currentBudgetLines) {
      const found = category.budget_lines?.find((l) => l.id === lineId);
      if (found) {
        line = found;
        break;
      }
    }

    if (!line) {
      Toast.error("Budget line not found");
      return;
    }

    editingLineId = lineId;

    document.getElementById("modalTitle").textContent = "Edit Budget Line";
    document.getElementById("budgetLineId").value = line.id;
    document.getElementById("lineName").value = line.name || "";
    document.getElementById("categoryId").value = line.budget_category_id || "";
    document.getElementById("territoryScope").value =
      line.territory_scope || "";
    document.getElementById("displayOrder").value = line.display_order || "";
    document.getElementById("lineDescription").value = line.description || "";
    document.getElementById("lineActive").checked = line.is_active;

    const modal = new bootstrap.Modal(
      document.getElementById("budgetLineModal")
    );
    modal.show();
  }

  // ========================================================================
  // CRUD OPERATIONS
  // ========================================================================

  async function saveBudgetLine() {
    const saveBtn = document.getElementById("saveBudgetLineBtn");
    const originalBtnContent = saveBtn.innerHTML;

    try {
      const name = document.getElementById("lineName").value.trim();
      const categoryId = document.getElementById("categoryId").value;
      const territoryScope = document.getElementById("territoryScope").value;
      const displayOrder = document.getElementById("displayOrder").value;
      const description = document
        .getElementById("lineDescription")
        .value.trim();
      const isActive = document.getElementById("lineActive").checked;

      if (!name || !categoryId || !territoryScope) {
        Toast.error("Please fill in all required fields");
        return;
      }

      // Disable button and show loading animation
      saveBtn.disabled = true;
      saveBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Saving...';

      const data = {
        name,
        budget_category_id: parseInt(categoryId),
        territory_scope: territoryScope,
        description,
        is_active: isActive,
      };

      if (displayOrder) {
        data.display_order = parseInt(displayOrder);
      }

      const API_BASE =
        window.AppConfig.API_BASE_URL;
      const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

      const isEdit = editingLineId !== null;
      const url = isEdit
        ? `${API_BASE}/budget-lines/${editingLineId}`
        : `${API_BASE}/budget-lines`;

      const response = await fetch(url, {
        method: isEdit ? "PUT" : "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${authToken}`,
        },
        body: JSON.stringify(data),
      });

      const result = await response.json();

      if (result.success) {
        Toast.success(
          isEdit
            ? "Budget line updated successfully"
            : "Budget line created successfully"
        );

        const modal = bootstrap.Modal.getInstance(
          document.getElementById("budgetLineModal")
        );
        modal.hide();

        loadBudgetLines();
      } else {
        // Check if it's a duplicate entry error
        const errorMessage = result.error || result.message || "";
        const isDuplicateError =
          errorMessage.includes("Duplicate entry") ||
          errorMessage.includes("budget_lines_slug_unique") ||
          errorMessage.includes("1062");

        if (isDuplicateError) {
          Toast.error(
            "A budget line with this name already exists. Please use a different name."
          );
        } else {
          // Show the message from the API, not the technical error
          Toast.error(result.message || "Failed to save budget line");
        }
      }
    } catch (error) {
      console.error("❌ Error saving budget line:", error);
      Toast.error("Failed to save budget line: " + error.message);
    } finally {
      // Re-enable button and restore original content
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalBtnContent;
    }
  }

  function deleteBudgetLine(lineId, lineName) {
    editingLineId = lineId;
    document.getElementById("deleteBudgetLineName").textContent = lineName;

    const modal = new bootstrap.Modal(
      document.getElementById("deleteBudgetLineModal")
    );
    modal.show();
  }

  async function confirmDelete() {
    const deleteBtn = document.getElementById("confirmDeleteBtn");
    const originalBtnContent = deleteBtn.innerHTML;

    try {
      // Disable button and show loading animation
      deleteBtn.disabled = true;
      deleteBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Deleting...';

      const API_BASE =
        window.AppConfig.API_BASE_URL;
      const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

      const response = await fetch(
        `${API_BASE}/budget-lines/${editingLineId}`,
        {
          method: "DELETE",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            Authorization: `Bearer ${authToken}`,
          },
        }
      );

      const result = await response.json();

      if (result.success) {
        Toast.success("Budget line deleted successfully");

        const modal = bootstrap.Modal.getInstance(
          document.getElementById("deleteBudgetLineModal")
        );
        modal.hide();

        loadBudgetLines();
      } else {
        Toast.error(result.message || "Failed to delete budget line");
      }
    } catch (error) {
      console.error("❌ Error deleting budget line:", error);
      Toast.error("Failed to delete budget line: " + error.message);
    } finally {
      // Re-enable button and restore original content
      deleteBtn.disabled = false;
      deleteBtn.innerHTML = originalBtnContent;
    }
  }

  // ========================================================================
  // VIEW MODAL & AUDIT TRAIL
  // ========================================================================

  async function openViewModal(lineId) {
    // Find line in current data
    let line = null;
    for (const category of currentBudgetLines) {
      const found = category.budget_lines?.find((l) => l.id === lineId);
      if (found) {
        line = found;
        break;
      }
    }

    if (!line) {
      Toast.error("Budget line not found");
      return;
    }

    // Populate details tab
    document.getElementById("view-line-name").textContent = line.name || "-";
    document.getElementById("view-line-slug").textContent = line.slug || "-";
    document.getElementById("view-line-category").textContent =
      line.budget_category?.name || "-";
    document.getElementById("view-line-territory").innerHTML =
      line.territory_scope
        ? `<span class="badge bg-primary-transparent">${formatTerritoryScope(
            line.territory_scope
          )}</span>`
        : "-";
    document.getElementById("view-line-order").textContent =
      line.display_order || "-";

    const systemDefaultBadge = line.is_system_default
      ? '<span class="badge bg-info"><i class="ri-shield-check-fill me-1"></i>System Default</span>'
      : '<span class="badge bg-warning"><i class="ri-user-fill me-1"></i>User Created</span>';
    document.getElementById("view-line-system-default").innerHTML =
      systemDefaultBadge;

    const statusBadge = line.is_active
      ? '<span class="badge bg-success"><i class="ri-checkbox-circle-fill me-1"></i>Active</span>'
      : '<span class="badge bg-secondary"><i class="ri-close-circle-fill me-1"></i>Inactive</span>';
    document.getElementById("view-line-status").innerHTML = statusBadge;

    document.getElementById("view-line-description").textContent =
      line.description || "No description provided";
    document.getElementById("view-line-created-at").textContent =
      line.created_at ? new Date(line.created_at).toLocaleString() : "-";
    document.getElementById("view-line-updated-at").textContent =
      line.updated_at ? new Date(line.updated_at).toLocaleString() : "-";
    document.getElementById("view-line-id").textContent = line.id || "-";

    // Reset audit trail tab to loading state
    const auditContainer = document.getElementById("lineAuditContainer");
    auditContainer.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2 mb-0">Loading audit trail...</p>
            </div>`;

    // Show modal
    const modal = new bootstrap.Modal(
      document.getElementById("viewBudgetLineModal")
    );
    modal.show();

    // Load audit trail in background
    loadLineAuditTrail(lineId);
  }

  async function loadLineAuditTrail(lineId) {
    try {
      const API_BASE =
        window.AppConfig.API_BASE_URL;
      const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

      const response = await fetch(
        `${API_BASE}/budget-lines/${lineId}/audits`,
        {
          method: "GET",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            Authorization: `Bearer ${authToken}`,
          },
        }
      );

      const result = await response.json();

      if (result.success && result.data) {
        renderLineAuditTrail(result.data);
      } else {
        throw new Error(result.message || "Failed to load audit trail");
      }
    } catch (error) {
      console.error("❌ Error loading audit trail:", error);
      const auditContainer = document.getElementById("lineAuditContainer");
      auditContainer.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-error-warning-line fs-48 text-danger mb-3 d-block"></i>
                    <p class="text-danger mb-0">Failed to load audit trail</p>
                    <small class="text-muted">${error.message}</small>
                </div>`;
    }
  }

  function renderLineAuditTrail(audits) {
    const auditContainer = document.getElementById("lineAuditContainer");

    if (!audits || audits.length === 0) {
      auditContainer.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-history-line fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No audit trail available</p>
                </div>`;
      return;
    }

    let html = '<ul class="list-unstyled profile-timeline">';

    audits.forEach((audit, index) => {
      const eventColors = {
        created: "success",
        updated: "info",
        deleted: "danger",
      };
      const color = eventColors[audit.event] || "secondary";

      const eventIcons = {
        created: "ri-add-circle-line",
        updated: "ri-edit-circle-line",
        deleted: "ri-delete-bin-line",
      };
      const icon = eventIcons[audit.event] || "ri-information-line";

      html += `
                <li>
                    <div class="d-flex align-items-top">
                        <div class="me-3 flex-shrink-0">
                            <span class="avatar avatar-md bg-${color}-transparent text-${color} avatar-rounded">
                                <i class="${icon} fs-18"></i>
                            </span>
                        </div>
                        <div class="flex-fill">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <h6 class="fw-semibold mb-0 text-capitalize">
                                    ${audit.event}
                                    <span class="badge bg-${color}-transparent text-${color} ms-2">${
        audit.event
      }</span>
                                </h6>
                                <span class="text-muted fs-11">${
                                  audit.created_at_human
                                }</span>
                            </div>
                            <p class="mb-2 text-muted fs-12">
                                <i class="ri-calendar-line me-1"></i>${
                                  audit.created_at
                                }
                            </p>

                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <div class="p-2 bg-light border rounded">
                                        <small class="text-muted d-block mb-1">
                                            <i class="ri-user-line me-1"></i>Changed By
                                        </small>
                                        <span class="fw-semibold fs-13">
                                            ${
                                              audit.user
                                                ? `${audit.user.name}<br><small class="text-muted">${audit.user.email}</small>`
                                                : "System"
                                            }
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 bg-light border rounded">
                                        <small class="text-muted d-block mb-1">
                                            <i class="ri-map-pin-line me-1"></i>IP Address
                                        </small>
                                        <code class="fs-12">${
                                          audit.ip_address || "N/A"
                                        }</code>
                                    </div>
                                </div>
                            </div>`;

      // Show changes for update events
      if (audit.event === "updated" && audit.old_values && audit.new_values) {
        html += `
                    <div class="accordion accordion-flush mt-2" id="lineAccordion${index}">
                        <div class="accordion-item border">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 fs-13 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#lineCollapse${index}">
                                    <i class="ri-file-list-line me-2 text-warning"></i>View Changes Made
                                </button>
                            </h2>
                            <div id="lineCollapse${index}" class="accordion-collapse collapse" data-bs-parent="#lineAccordion${index}">
                                <div class="accordion-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="fw-semibold fs-12">Field</th>
                                                    <th class="fw-semibold fs-12 text-danger">Old Value</th>
                                                    <th class="fw-semibold fs-12 text-success">New Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>`;

        Object.keys(audit.new_values).forEach((key) => {
          if (audit.old_values[key] !== audit.new_values[key]) {
            html += `
                            <tr>
                                <td class="fw-medium fs-12">${formatFieldName(
                                  key
                                )}</td>
                                <td><span class="badge bg-danger-transparent fs-11">${formatValue(
                                  audit.old_values[key]
                                )}</span></td>
                                <td><span class="badge bg-success-transparent fs-11">${formatValue(
                                  audit.new_values[key]
                                )}</span></td>
                            </tr>`;
          }
        });

        html += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
      }

      // Show values for create events
      if (audit.event === "created" && audit.new_values) {
        html += `
                    <div class="accordion accordion-flush mt-2" id="lineAccordionCreate${index}">
                        <div class="accordion-item border">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 fs-13 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#lineCollapseCreate${index}">
                                    <i class="ri-file-list-line me-2 text-success"></i>View Initial Values
                                </button>
                            </h2>
                            <div id="lineCollapseCreate${index}" class="accordion-collapse collapse" data-bs-parent="#lineAccordionCreate${index}">
                                <div class="accordion-body bg-light p-3">`;

        Object.keys(audit.new_values).forEach((key) => {
          html += `
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="fw-semibold fs-12">${formatFieldName(
                              key
                            )}:</span>
                            <span class="badge bg-primary-transparent fs-11">${formatValue(
                              audit.new_values[key]
                            )}</span>
                        </div>`;
        });

        html += `
                                </div>
                            </div>
                        </div>
                    </div>`;
      }

      html += `
                        </div>
                    </div>
                </li>`;
    });

    html += "</ul>";
    auditContainer.innerHTML = html;
  }

  function formatFieldName(fieldName) {
    return fieldName
      .replace(/_/g, " ")
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function formatValue(value) {
    if (value === null || value === undefined) return "-";
    if (typeof value === "boolean") return value ? "Yes" : "No";
    if (typeof value === "object") return JSON.stringify(value);
    return escapeHtml(value.toString());
  }

  // ========================================================================
  // UTILITY FUNCTIONS
  // ========================================================================

  function formatTerritoryScope(scope) {
    const scopes = {
      diocese: "Diocese",
      region: "Region",
      subregion: "Sub-Region",
      church: "Church",
      all: "All Levels",
    };
    return scopes[scope] || scope;
  }

  function escapeHtml(unsafe) {
    if (!unsafe) return "";
    return unsafe
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }
})();
