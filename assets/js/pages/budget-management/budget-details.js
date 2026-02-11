(function () {
  "use strict";

  // ========================================================================
  // STATE MANAGEMENT
  // ========================================================================

  let currentBudget = null;
  let budgetLines = [];
  let deductions = [];
  let activityLog = [];
  let mode = "view"; // view or edit
  let printMode = false;
  let budgetId = null;

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.BudgetDetailsManagement = {
    init: initializeBudgetDetails,
    loadBudget: loadBudget,
    loadBudgetLines: loadBudgetLines,
    loadDeductions: loadDeductions,
    loadActivityLog: loadActivityLog,
  };

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  function initializeBudgetDetails(id, pageMode = "view", isPrintMode = false) {
    console.log("🔧 Initializing Budget Details Management...");

    budgetId = id;
    mode = pageMode;
    printMode = isPrintMode;

    setupEventListeners();
    loadBudget();

    console.log("✅ Budget Details Management initialized");
  }

  function setupEventListeners() {
    // Action buttons
    const editBtn = document.getElementById("editBudgetBtn");
    const submitBtn = document.getElementById("submitBudgetBtn");
    const approveBtn = document.getElementById("approveBudgetBtn");
    const rejectBtn = document.getElementById("rejectBudgetBtn");
    const activateBtn = document.getElementById("activateBudgetBtn");
    const printBtn = document.getElementById("printBudgetBtn");

    if (editBtn) editBtn.addEventListener("click", () => editBudget());
    if (submitBtn) submitBtn.addEventListener("click", () => submitBudget());
    if (approveBtn) approveBtn.addEventListener("click", () => approveBudget());
    if (rejectBtn) rejectBtn.addEventListener("click", () => rejectBudget());
    if (activateBtn)
      activateBtn.addEventListener("click", () => activateBudget());
    if (printBtn) printBtn.addEventListener("click", () => window.print());

    // Budget Lines filters
    const categoryFilter = document.getElementById("linesCategoryFilter");
    const searchInput = document.getElementById("linesSearchInput");

    if (categoryFilter) {
      categoryFilter.addEventListener("change", () => renderBudgetLines());
    }

    if (searchInput) {
      let searchTimeout;
      searchInput.addEventListener("input", () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => renderBudgetLines(), 300);
      });
    }

    // Activity log filters
    const actionFilter = document.getElementById("logActionFilter");
    const startDate = document.getElementById("logStartDate");
    const endDate = document.getElementById("logEndDate");
    const refreshLogBtn = document.getElementById("refreshLogBtn");

    if (actionFilter)
      actionFilter.addEventListener("change", () => renderActivityLog());
    if (startDate)
      startDate.addEventListener("change", () => renderActivityLog());
    if (endDate) endDate.addEventListener("change", () => renderActivityLog());
    if (refreshLogBtn)
      refreshLogBtn.addEventListener("click", () => loadActivityLog());

    // Print mode handling
    if (printMode) {
      setTimeout(() => window.print(), 1000);
    }
  }

  // ========================================================================
  // DATA LOADING FUNCTIONS
  // ========================================================================

  async function loadBudget() {
    try {
      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      // If no budget ID provided, fetch current year's active budget
      if (!budgetId) {
        console.log(
          "📋 No budget ID provided, loading current year's budget...",
        );
        const currentYear = new Date().getFullYear();

        const response = await fetch(
          `${API_BASE}/budgets?territory_type=diocese&fiscal_year=${currentYear}&status=active&per_page=1`,
          {
            headers: {
              Authorization: `Bearer ${token}`,
              "Content-Type": "application/json",
            },
          },
        );

        const result = await response.json();

        if (
          result.success &&
          result.data &&
          result.data.data &&
          result.data.data.length > 0
        ) {
          budgetId = result.data.data[0].id;
          currentBudget = result.data.data[0];

          // Update URL without reloading the page
          window.history.replaceState({}, "", `?id=${budgetId}`);

          renderBudgetHeader();
          updateActionButtons();
          loadBudgetLines();
          loadDeductions();
          loadActivityLog();

          console.log(
            "✅ Current year's budget loaded successfully:",
            currentBudget,
          );
        } else {
          throw new Error("No active budget found for the current year");
        }
        return;
      }

      console.log(`📋 Loading budget ${budgetId}...`);

      const response = await fetch(`${API_BASE}/budgets/${budgetId}`, {
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
      });

      const result = await response.json();

      if (result.success && result.data) {
        currentBudget = result.data;
        renderBudgetHeader();
        renderDetailsTab();
        updateActionButtons();

        // Load related data
        loadBudgetLines();
        loadDeductions();
        loadActivityLog();

        console.log("✅ Budget loaded successfully:", currentBudget);
      } else {
        throw new Error(result.message || "Failed to load budget");
      }
    } catch (error) {
      console.error("❌ Error loading budget:", error);
      showError("Failed to load budget details. Please try again.");
    }
  }

  async function loadBudgetLines() {
    try {
      console.log(`📋 Loading budget lines for budget ${budgetId}...`);

      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      const response = await fetch(
        `${API_BASE}/budgets/${budgetId}/line-items`,
        {
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
          },
        },
      );

      const result = await response.json();

      if (result.success && result.data) {
        budgetLines = result.data;
        renderBudgetLines();
        renderOverviewTab();
        console.log("✅ Budget lines loaded:", budgetLines.length);
      } else {
        throw new Error(result.message || "Failed to load budget lines");
      }
    } catch (error) {
      console.error("❌ Error loading budget lines:", error);
      showError("Failed to load budget lines.");
    }
  }

  async function loadDeductions() {
    try {
      console.log(`📋 Loading deductions for budget ${budgetId}...`);

      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      const response = await fetch(
        `${API_BASE}/budgets/${budgetId}/deductions`,
        {
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
          },
        },
      );

      const result = await response.json();

      if (result.success && result.data) {
        deductions = result.data;
        renderDeductions();
        console.log("✅ Deductions loaded:", deductions.length);
      } else {
        throw new Error(result.message || "Failed to load deductions");
      }
    } catch (error) {
      console.error("❌ Error loading deductions:", error);
      // Don't show error for deductions as it's not critical
    }
  }

  async function loadActivityLog() {
    try {
      console.log(`📋 Loading activity log for budget ${budgetId}...`);

      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      const response = await fetch(`${API_BASE}/budgets/${budgetId}/logs`, {
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
      });

      const result = await response.json();

      if (result.success && result.data) {
        activityLog = result.data;
        renderActivityLog();
        console.log("✅ Activity log loaded:", activityLog.length);
      } else {
        throw new Error(result.message || "Failed to load activity log");
      }
    } catch (error) {
      console.error("❌ Error loading activity log:", error);
      // Don't show error for activity log as it's not critical
    }
  }

  // ========================================================================
  // RENDERING FUNCTIONS
  // ========================================================================

  function renderBudgetHeader() {
    if (!currentBudget) return;

    // Basic info
    document.getElementById("budgetName").textContent =
      currentBudget.name || "N/A";
    document.getElementById("budgetYear").textContent =
      currentBudget.fiscal_year || "N/A";
    document.getElementById("budgetType").textContent =
      currentBudget.budget_type?.name || "N/A";
    document.getElementById("budgetDescription").textContent =
      currentBudget.description || "No description";

    // Status badge
    const statusEl = document.getElementById("budgetStatus");
    const status = currentBudget.status || {};
    const statusClass = getStatusClass(status.slug);
    statusEl.className = `badge bg-${statusClass}`;
    statusEl.textContent = status.name || "Draft";

    // Key metrics
    const totalIncome = parseFloat(currentBudget.total_income_budgeted || 0);
    const totalExpense = parseFloat(currentBudget.total_expense_budgeted || 0);
    const netPosition = totalIncome - totalExpense;
    const totalBudget = totalIncome + totalExpense;
    const utilization =
      totalBudget > 0 ? ((totalExpense / totalBudget) * 100).toFixed(1) : 0;

    document.getElementById("totalIncome").textContent =
      `KES ${formatNumber(totalIncome)}`;
    document.getElementById("totalExpense").textContent =
      `KES ${formatNumber(totalExpense)}`;
    document.getElementById("netPosition").textContent =
      `KES ${formatNumber(netPosition)}`;

    // Update net position color
    const netPosEl = document.getElementById("netPosition");
    if (netPosition >= 0) {
      netPosEl.classList.remove("text-danger");
      netPosEl.classList.add("text-success");
    } else {
      netPosEl.classList.remove("text-success");
      netPosEl.classList.add("text-danger");
    }

    // Actual amounts (will be 0 for now)
    const incomeActual = parseFloat(currentBudget.total_income_actual || 0);
    const expenseActual = parseFloat(currentBudget.total_expense_actual || 0);

    document.getElementById("incomeActual").textContent =
      `Actual: KES ${formatNumber(incomeActual)}`;
    document.getElementById("expenseActual").textContent =
      `Actual: KES ${formatNumber(expenseActual)}`;

    // Utilization rate
    const utilizationEl = document.getElementById("utilizationRate");
    if (utilizationEl) {
      utilizationEl.textContent = `${utilization}% Utilized`;
    }

    // Total lines count
    const totalLinesEl = document.getElementById("totalLines");
    if (totalLinesEl) {
      totalLinesEl.textContent = budgetLines.length;
    }

    // Update creator, updater, and approver info in header
    updateHeaderUserInfo();
  }

  function updateHeaderUserInfo() {
    if (!currentBudget) return;

    // Creator Info
    const creatorEl = document.getElementById("budgetCreator");
    const createdAtEl = document.getElementById("budgetCreatedAt");
    if (creatorEl && currentBudget.creator) {
      const creator = currentBudget.creator;
      creatorEl.textContent =
        `${creator.firstname || ""} ${creator.lastname || ""}`.trim() ||
        "System";
    }
    if (createdAtEl && currentBudget.created_at) {
      const date = new Date(currentBudget.created_at);
      createdAtEl.textContent = date.toLocaleDateString();
    }

    // Approver Info
    const approverEl = document.getElementById("budgetApprover");
    const approvedAtEl = document.getElementById("budgetApprovedAt");
    if (approverEl) {
      if (currentBudget.approver) {
        const approver = currentBudget.approver;
        approverEl.textContent =
          `${approver.firstname || ""} ${approver.lastname || ""}`.trim() ||
          "System";
      } else {
        approverEl.textContent = "-";
      }
    }
    if (approvedAtEl) {
      if (currentBudget.approved_at) {
        const date = new Date(currentBudget.approved_at);
        approvedAtEl.textContent = date.toLocaleDateString();
      } else {
        approvedAtEl.textContent = "-";
      }
    }

    // Updater Info
    const updaterEl = document.getElementById("budgetUpdater");
    const updatedAtEl = document.getElementById("budgetUpdatedAt");
    if (updaterEl) {
      if (currentBudget.updater) {
        const updater = currentBudget.updater;
        updaterEl.textContent =
          `${updater.firstname || ""} ${updater.lastname || ""}`.trim() ||
          "System";
      } else {
        updaterEl.textContent = "-";
      }
    }
    if (updatedAtEl) {
      if (currentBudget.updated_at && currentBudget.updated_by) {
        const date = new Date(currentBudget.updated_at);
        updatedAtEl.textContent = date.toLocaleDateString();
      } else {
        updatedAtEl.textContent = "-";
      }
    }
  }

  function renderDetailsTab() {
    if (!currentBudget) return;

    // Territory Information
    const territoryType = currentBudget.territory_type || "N/A";
    const territoryTypeEl = document.getElementById("territoryType");
    if (territoryTypeEl) {
      territoryTypeEl.textContent =
        territoryType.charAt(0).toUpperCase() + territoryType.slice(1);
    }

    // Territory Name - we'd need to fetch this from territory data
    // For now, we'll show the territory type
    const territoryNameEl = document.getElementById("territoryName");
    if (territoryNameEl) {
      territoryNameEl.textContent =
        territoryType === "diocese"
          ? "Makueni West Diocese"
          : `${territoryType.charAt(0).toUpperCase() + territoryType.slice(1)} ID: ${currentBudget.territory_id || "N/A"}`;
    }

    // Budget Type
    const budgetTypeEl = document.getElementById("budgetTypeDetail");
    if (budgetTypeEl) {
      budgetTypeEl.textContent = currentBudget.budget_type?.name || "N/A";
    }

    // Fiscal Year
    const fiscalYearEl = document.getElementById("fiscalYearDetail");
    if (fiscalYearEl) {
      fiscalYearEl.textContent = currentBudget.fiscal_year || "N/A";
    }

    // Current Status
    const statusEl = document.getElementById("currentStatusDetail");
    if (statusEl) {
      const status = currentBudget.status || "draft";
      const statusClass = getStatusClass(status);
      statusEl.innerHTML = `<span class="badge bg-${statusClass}">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
    }

    // Creator Information
    const creatorEl = document.getElementById("creatorDetail");
    if (creatorEl && currentBudget.creator) {
      const creator = currentBudget.creator;
      creatorEl.textContent =
        `${creator.firstname || ""} ${creator.lastname || ""}`.trim() ||
        "System";
    }

    const createdAtEl = document.getElementById("createdAtDetail");
    if (createdAtEl && currentBudget.created_at) {
      const date = new Date(currentBudget.created_at);
      createdAtEl.textContent = date.toLocaleString();
    }

    // Updater Information
    const updaterEl = document.getElementById("updaterDetail");
    if (updaterEl) {
      if (currentBudget.updater) {
        const updater = currentBudget.updater;
        updaterEl.textContent =
          `${updater.firstname || ""} ${updater.lastname || ""}`.trim() ||
          "System";
      } else {
        updaterEl.textContent = "-";
      }
    }

    const updatedAtEl = document.getElementById("updatedAtDetail");
    if (updatedAtEl) {
      if (currentBudget.updated_at && currentBudget.updated_by) {
        const date = new Date(currentBudget.updated_at);
        updatedAtEl.textContent = date.toLocaleString();
      } else {
        updatedAtEl.textContent = "-";
      }
    }

    // Approver Information
    const approverEl = document.getElementById("approverDetail");
    if (approverEl) {
      if (currentBudget.approver) {
        const approver = currentBudget.approver;
        approverEl.textContent =
          `${approver.firstname || ""} ${approver.lastname || ""}`.trim() ||
          "System";
      } else {
        approverEl.textContent = "-";
      }
    }

    const approvedAtEl = document.getElementById("approvedAtDetail");
    if (approvedAtEl) {
      if (currentBudget.approved_at) {
        const date = new Date(currentBudget.approved_at);
        approvedAtEl.textContent = date.toLocaleString();
      } else {
        approvedAtEl.textContent = "-";
      }
    }

    // Approval Notes
    const approvalNotesEl = document.getElementById("approvalNotesText");
    if (approvalNotesEl) {
      if (currentBudget.approval_notes) {
        approvalNotesEl.textContent = currentBudget.approval_notes;
      } else if (currentBudget.rejection_reason) {
        approvalNotesEl.textContent = `Rejection Reason: ${currentBudget.rejection_reason}`;
        approvalNotesEl.parentElement.classList.remove("alert-light");
        approvalNotesEl.parentElement.classList.add("alert-danger");
      } else {
        approvalNotesEl.textContent =
          "No approval notes or comments available.";
      }
    }
  }

  function renderBudgetLines() {
    const tbody = document.getElementById("budgetLinesTableBody");
    if (!tbody) return;

    // Get filter values
    const categoryFilter =
      document.getElementById("linesCategoryFilter")?.value || "";
    const searchQuery =
      document.getElementById("linesSearchInput")?.value.toLowerCase() || "";

    // Filter lines
    let filteredLines = budgetLines;

    if (categoryFilter) {
      filteredLines = filteredLines.filter((line) => {
        const category = line.budget_line?.budget_category?.slug || "";
        return category === categoryFilter;
      });
    }

    if (searchQuery) {
      filteredLines = filteredLines.filter((line) => {
        const lineName = (line.budget_line?.name || "").toLowerCase();
        const lineCode = (line.budget_line?.code || "").toLowerCase();
        return lineName.includes(searchQuery) || lineCode.includes(searchQuery);
      });
    }

    // Update counts
    const incomeCount = budgetLines.filter(
      (l) => l.budget_line?.budget_category?.slug === "income",
    ).length;
    const expenseCount = budgetLines.filter(
      (l) => l.budget_line?.budget_category?.slug === "expense",
    ).length;
    const overBudgetCount = budgetLines.filter((l) => {
      const budgeted = parseFloat(l.budgeted_amount || 0);
      const actual = parseFloat(l.actual_amount || 0);
      return actual > budgeted;
    }).length;

    document.getElementById("linesTotalCount").textContent = budgetLines.length;
    document.getElementById("linesIncomeCount").textContent = incomeCount;
    document.getElementById("linesExpenseCount").textContent = expenseCount;
    document.getElementById("linesOverBudgetCount").textContent =
      overBudgetCount;

    // Render lines
    if (filteredLines.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="9" class="text-center py-4">
            <div class="text-muted">
              <i class="ri-inbox-line fs-24 mb-2 d-block"></i>
              <p class="mb-0">No budget lines found</p>
            </div>
          </td>
        </tr>
      `;
      return;
    }

    let totalBudgeted = 0;
    let totalActual = 0;

    tbody.innerHTML = filteredLines
      .map((line) => {
        const budgeted = parseFloat(line.budgeted_amount || 0);
        const actual = parseFloat(line.actual_amount || 0);
        const remaining = budgeted - actual;
        const utilization = budgeted > 0 ? (actual / budgeted) * 100 : 0;

        totalBudgeted += budgeted;
        totalActual += actual;

        const category = line.budget_line?.budget_category?.name || "N/A";
        const categorySlug = line.budget_line?.budget_category?.slug || "";
        const categoryClass = categorySlug === "income" ? "success" : "danger";

        const utilizationClass =
          utilization > 100
            ? "danger"
            : utilization > 80
              ? "warning"
              : "success";
        const statusClass =
          remaining < 0 ? "danger" : remaining === 0 ? "secondary" : "success";
        const statusText =
          remaining < 0
            ? "Over Budget"
            : remaining === 0
              ? "Fully Used"
              : "Available";

        return `
        <tr>
          <td>
            <span class="badge bg-light text-dark">${line.budget_line?.code || "N/A"}</span>
          </td>
          <td>
            <div class="fw-semibold">${line.budget_line?.name || "N/A"}</div>
          </td>
          <td>
            <span class="badge bg-${categoryClass}-transparent">${category}</span>
          </td>
          <td class="text-end fw-semibold">KES ${formatNumber(budgeted)}</td>
          <td class="text-end">KES ${formatNumber(actual)}</td>
          <td class="text-end ${remaining < 0 ? "text-danger" : "text-success"}">
            KES ${formatNumber(Math.abs(remaining))}
          </td>
          <td class="text-center">
            <div class="d-flex align-items-center justify-content-center gap-2">
              <div class="progress flex-fill" style="height: 6px; max-width: 80px;">
                <div class="progress-bar bg-${utilizationClass}" style="width: ${Math.min(utilization, 100)}%"></div>
              </div>
              <span class="badge bg-${utilizationClass}-transparent">${utilization.toFixed(0)}%</span>
            </div>
          </td>
          <td class="text-center">
            <span class="badge bg-${statusClass}-transparent">${statusText}</span>
          </td>
          <td class="text-center no-print">
            <button class="btn btn-sm btn-light btn-wave" onclick="viewLineDetails(${line.id})">
              <i class="ri-eye-line"></i>
            </button>
          </td>
        </tr>
      `;
      })
      .join("");

    // Update footer totals
    const totalRemaining = totalBudgeted - totalActual;
    document.getElementById("totalBudgetedFooter").textContent =
      `KES ${formatNumber(totalBudgeted)}`;
    document.getElementById("totalActualFooter").textContent =
      `KES ${formatNumber(totalActual)}`;
    document.getElementById("totalRemainingFooter").textContent =
      `KES ${formatNumber(totalRemaining)}`;
    document.getElementById("totalLines").textContent = budgetLines.length;
  }

  function renderOverviewTab() {
    if (budgetLines.length === 0) return;

    // Calculate totals
    const incomeLines = budgetLines.filter(
      (l) => l.budget_line?.budget_category?.slug === "income",
    );
    const expenseLines = budgetLines.filter(
      (l) => l.budget_line?.budget_category?.slug === "expense",
    );

    const incomeBudgeted = incomeLines.reduce(
      (sum, l) => sum + parseFloat(l.budgeted_amount || 0),
      0,
    );
    const incomeActual = incomeLines.reduce(
      (sum, l) => sum + parseFloat(l.actual_amount || 0),
      0,
    );
    const expenseBudgeted = expenseLines.reduce(
      (sum, l) => sum + parseFloat(l.budgeted_amount || 0),
      0,
    );
    const expenseActual = expenseLines.reduce(
      (sum, l) => sum + parseFloat(l.actual_amount || 0),
      0,
    );

    const netBudgeted = incomeBudgeted - expenseBudgeted;
    const netActual = incomeActual - expenseActual;

    // Update cards
    document.getElementById("overviewIncomeBudgeted").textContent =
      `KES ${formatNumber(incomeBudgeted)}`;
    document.getElementById("overviewIncomeActual").textContent =
      `KES ${formatNumber(incomeActual)}`;
    document.getElementById("overviewExpenseBudgeted").textContent =
      `KES ${formatNumber(expenseBudgeted)}`;
    document.getElementById("overviewExpenseActual").textContent =
      `KES ${formatNumber(expenseActual)}`;
    document.getElementById("overviewNetPosition").textContent =
      `KES ${formatNumber(netBudgeted)}`;

    const incomeVariance =
      incomeBudgeted > 0
        ? ((incomeActual - incomeBudgeted) / incomeBudgeted) * 100
        : 0;
    const expenseVariance =
      expenseBudgeted > 0
        ? ((expenseActual - expenseBudgeted) / expenseBudgeted) * 100
        : 0;

    document.getElementById("overviewIncomeVariance").textContent =
      `${incomeVariance >= 0 ? "+" : ""}${incomeVariance.toFixed(1)}%`;
    document.getElementById("overviewExpenseVariance").textContent =
      `${expenseVariance >= 0 ? "+" : ""}${expenseVariance.toFixed(1)}%`;

    // Utilization
    const utilization =
      expenseBudgeted > 0 ? (expenseActual / expenseBudgeted) * 100 : 0;
    document.getElementById("overviewUtilization").textContent =
      `${utilization.toFixed(1)}%`;
    document.getElementById("overviewUtilizationBar").style.width =
      `${Math.min(utilization, 100)}%`;
    document.getElementById("overviewUtilizationText").textContent =
      `KES ${formatNumber(expenseActual)} of KES ${formatNumber(expenseBudgeted)}`;

    // Net status
    const netStatus = netActual >= 0 ? "Surplus" : "Deficit";
    const netPercentage = netBudgeted > 0 ? (netActual / netBudgeted) * 100 : 0;
    document.getElementById("overviewNetStatus").textContent = netStatus;
    document.getElementById("overviewNetPercentage").textContent =
      `${netPercentage.toFixed(1)}%`;

    // Initialize charts
    initializeCharts();

    // Render top expense lines
    renderTopExpenseLines();
  }

  function renderDeductions() {
    const tbody = document.getElementById("deductionsTableBody");
    const noDeductionsState = document.getElementById("noDeductionsState");

    if (!tbody) return;

    if (deductions.length === 0) {
      tbody.innerHTML = "";
      if (noDeductionsState) noDeductionsState.style.display = "block";

      // Update summary
      document.getElementById("totalDeductionsCount").textContent = "0";
      document.getElementById("totalDeductedAmount").textContent = "KES 0";
      document.getElementById("affectedLinesCount").textContent = "0";
      return;
    }

    if (noDeductionsState) noDeductionsState.style.display = "none";

    // Calculate totals
    const totalDeducted = deductions.reduce(
      (sum, d) => sum + parseFloat(d.total_deducted || 0),
      0,
    );
    const affectedLines = deductions.reduce(
      (sum, d) => sum + parseInt(d.affected_lines_count || 0),
      0,
    );

    document.getElementById("totalDeductionsCount").textContent =
      deductions.length;
    document.getElementById("totalDeductedAmount").textContent =
      `KES ${formatNumber(totalDeducted)}`;
    document.getElementById("affectedLinesCount").textContent = affectedLines;

    // Render table
    tbody.innerHTML = deductions
      .map((deduction) => {
        const type = deduction.deduction?.deduction_type || "N/A";
        const rate = deduction.deduction?.rate || 0;
        const amount = deduction.deduction?.fixed_amount || 0;
        const rateDisplay =
          type === "percentage" ? `${rate}%` : `KES ${formatNumber(amount)}`;

        const appliedBy = deduction.applied_by_user?.full_name || "System";
        const appliedDate = deduction.applied_at
          ? new Date(deduction.applied_at).toLocaleDateString()
          : "N/A";

        return `
        <tr>
          <td>
            <div class="d-flex align-items-center">
              <span class="avatar avatar-sm bg-primary-transparent me-2">
                <i class="ri-wallet-3-line text-primary fs-14"></i>
              </span>
              <div>
                <div class="fw-bold text-dark">${deduction.deduction?.name || "N/A"}</div>
                <small class="text-muted">${deduction.deduction?.description || "No description"}</small>
              </div>
            </div>
          </td>
          <td>
            <span class="badge bg-${type === "percentage" ? "info" : "success"}-transparent px-3 py-2">
              ${type.toUpperCase()}
            </span>
          </td>
          <td class="fw-bold text-primary">${rateDisplay}</td>
          <td class="text-center">
            <span class="badge bg-warning-transparent rounded-pill px-3">${deduction.affected_lines_count || 0} Lines</span>
          </td>
          <td class="text-end">
            <div class="fw-bold text-danger fs-14">KES ${formatNumber(parseFloat(deduction.total_deducted || 0))}</div>
          </td>
          <td>
            <div class="d-flex align-items-center">
              <span class="avatar avatar-xs bg-success-transparent rounded-circle me-1">
                <i class="ri-user-3-line text-success fs-10"></i>
              </span>
              <span class="text-dark fw-medium">${appliedBy}</span>
            </div>
          </td>
          <td>
            <div class="d-flex align-items-center text-muted">
              <i class="ri-calendar-event-line text-warning me-1"></i>
              ${appliedDate}
            </div>
          </td>
          <td class="text-center no-print">
            <div class="hstack gap-2 justify-content-center">
              <button class="btn btn-icon btn-sm btn-info-light btn-wave" onclick="viewDeductionDetails(${deduction.id})" title="View Details">
                <i class="ri-eye-line"></i>
              </button>
              <button class="btn btn-icon btn-sm btn-danger-light btn-wave" onclick="reverseDeduction(${deduction.id})" title="Reverse Deduction">
                <i class="ri-delete-bin-line"></i>
              </button>
            </div>
          </td>
        </tr>
      `;
      })
      .join("");
  }

  function renderActivityLog() {
    const timeline = document.getElementById("activityLogTimeline");
    const noActivityState = document.getElementById("noActivityState");

    if (!timeline) return;

    // Get filter values
    const actionFilter =
      document.getElementById("logActionFilter")?.value || "";
    const startDate = document.getElementById("logStartDate")?.value || "";
    const endDate = document.getElementById("logEndDate")?.value || "";

    // Filter log
    let filteredLog = activityLog;

    if (actionFilter) {
      filteredLog = filteredLog.filter((log) => log.action === actionFilter);
    }

    if (startDate) {
      filteredLog = filteredLog.filter((log) => {
        const logDate = new Date(log.created_at);
        return logDate >= new Date(startDate);
      });
    }

    if (endDate) {
      filteredLog = filteredLog.filter((log) => {
        const logDate = new Date(log.created_at);
        return logDate <= new Date(endDate);
      });
    }

    // Update summary
    const statusChanges = activityLog.filter(
      (l) =>
        l.action?.includes("status") ||
        l.action?.includes("approved") ||
        l.action?.includes("rejected"),
    ).length;
    const lineChanges = activityLog.filter((l) =>
      l.action?.includes("line"),
    ).length;
    const contributors = [
      ...new Set(activityLog.map((l) => l.performed_by?.id).filter(Boolean)),
    ].length;

    document.getElementById("logTotalCount").textContent = activityLog.length;
    document.getElementById("logStatusChangesCount").textContent =
      statusChanges;
    document.getElementById("logLineChangesCount").textContent = lineChanges;
    document.getElementById("logContributorsCount").textContent = contributors;

    // Render timeline
    if (filteredLog.length === 0) {
      timeline.innerHTML = "";
      if (noActivityState) noActivityState.style.display = "block";
      return;
    }

    if (noActivityState) noActivityState.style.display = "none";

    timeline.innerHTML = filteredLog
      .map((log) => {
        const user = log.performed_by?.full_name || "System";
        const dateObj = log.created_at ? new Date(log.created_at) : new Date();
        const dayNumber = dateObj.getDate().toString().padStart(2, "0");
        const dayName = dateObj.toLocaleDateString("en-US", {
          weekday: "short",
        });
        const timeString = dateObj.toLocaleTimeString("en-US", {
          hour: "2-digit",
          minute: "2-digit",
        });

        const action = log.action || "Unknown";
        const description = log.description || "";

        const iconClass = getActionIcon(action);
        const colorClass = getActionColor(action);

        return `
         <li class="timeline-widget-list pb-4">
           <div class="d-flex align-items-top">
             <div class="me-4 text-center">
               <span class="avatar avatar-md avatar-rounded bg-${colorClass}-transparent text-${colorClass} shadow-none">
                 <i class="${iconClass} fs-20"></i>
               </span>
             </div>
             <div class="d-flex flex-wrap flex-fill align-items-top justify-content-between">
               <div class="flex-fill">
                 <div class="d-flex align-items-center mb-2">
                    <h6 class="fw-bold mb-0 text-${colorClass}">${formatActionName(action)}</h6>
                    <span class="badge bg-${colorClass}-transparent ms-2 rounded-pill px-2">
                        ${timeString}
                    </span>
                 </div>
                 <p class="mb-2 fs-13 text-dark bg-light p-2 rounded border border-light">
                    ${description}
                 </p>
                 <div class="d-flex align-items-center fs-12 text-muted">
                   <span class="d-flex align-items-center me-3">
                        <i class="ri-user-3-line me-1 text-primary"></i>
                        <span class="fw-medium text-dark">${user}</span>
                   </span>
                   <span class="d-flex align-items-center me-3">
                        <i class="ri-calendar-line me-1 text-warning"></i>
                        <span>${dayName}, ${dateObj.toLocaleDateString()}</span>
                   </span>
                   ${
                     log.ip_address
                       ? `
                   <span class="d-flex align-items-center">
                        <i class="ri-global-line me-1 text-info"></i>
                        <span>${log.ip_address}</span>
                   </span>`
                       : ""
                   }
                 </div>
               </div>
             </div>
           </div>
         </li>
       `;
      })
      .join("");
  }

  // ========================================================================
  // CHARTS & TOP LINE ITEMS (Refactored)
  // ========================================================================

  function initializeCharts() {
    // We need to render:
    // 1. Income Trend Chart (#incomeTrendChart) - Visual style of Water Tracking
    // 2. Expense Trend Chart (#expenseTrendChart) - Visual style of Sleep Tracking

    // Prepare Data
    // For now, we simulate a "trend" by using the budget lines categories as X-axis or just 12 months if we had that data.
    // Since we only have "Line items", let's make the chart show the "Top 10 Lines" value distribution for the sparkline effect.
    // Ideally, this should be "Monthly Trend" but we don't have monthly data loaded here yet.
    // So we will use the *lines* as the data points to create a "wave" visuals for now, or just dummy trend if lines < 2.

    const incomeLines = budgetLines.filter(
      (l) => l.budget_line?.budget_category?.slug === "income",
    );
    const expenseLines = budgetLines.filter(
      (l) => l.budget_line?.budget_category?.slug === "expense",
    );

    // Income Chart Config (Purple Gradient - Water Style)
    // We create a data series from the line items amounts to show "shape" of the budget
    const incomeData = incomeLines.map((l) =>
      parseFloat(l.budgeted_amount || 0),
    );

    const incomeOptions = {
      series: [
        {
          name: "Budgeted",
          data: incomeData.length > 0 ? incomeData : [0, 0, 0, 0],
        },
      ],
      chart: {
        height: 115,
        type: "area", // Index-11 Style
        fontFamily: "Roboto, Arial, sans-serif",
        foreColor: "#5d6162",
        zoom: { enabled: false },
        sparkline: { enabled: true },
        toolbar: { show: false },
      },
      tooltip: {
        enabled: true,
        x: { show: false },
        y: {
          formatter: function (val) {
            return "KES " + formatNumber(val);
          },
        },
        marker: { show: false },
      },
      dataLabels: { enabled: false },
      stroke: { curve: "smooth", width: 2 }, // Smooth curve for that "liquid" look
      title: { text: undefined },
      grid: { borderColor: "transparent" },
      xaxis: { crosshairs: { show: false } },
      colors: ["rgb(132, 90, 223)"], // Primary Purple
      fill: {
        type: "gradient",
        gradient: {
          opacityFrom: 0.5,
          opacityTo: 0.2,
          stops: [0, 60],
        },
      },
      yaxis: { min: 0 },
    };

    const incomeChartEl = document.querySelector("#incomeTrendChart");
    if (incomeChartEl) {
      incomeChartEl.innerHTML = "";
      const chart1 = new ApexCharts(incomeChartEl, incomeOptions);
      chart1.render();
    }

    // Expense Chart Config (Green Gradient - Sleep Style)
    const expenseData = expenseLines.map((l) =>
      parseFloat(l.budgeted_amount || 0),
    );

    // Check if we have data, otherwise flatline
    const finalExpenseData =
      expenseData.length > 0 ? expenseData : [0, 0, 0, 0];

    const expenseOptions = {
      series: [
        {
          name: "Budgeted",
          data: finalExpenseData,
        },
      ],
      chart: {
        height: 115,
        type: "area",
        fontFamily: "Roboto, Arial, sans-serif",
        foreColor: "#5d6162",
        zoom: { enabled: false },
        sparkline: { enabled: true },
        toolbar: { show: false },
      },
      tooltip: {
        enabled: true,
        x: { show: false },
        y: {
          formatter: function (val) {
            return "KES " + formatNumber(val);
          },
        },
        marker: { show: false },
      },
      dataLabels: { enabled: false },
      stroke: { curve: "smooth", width: 2 },
      title: { text: undefined },
      grid: { borderColor: "transparent" },
      xaxis: { crosshairs: { show: false } }, // No crosshairs for clean look
      colors: ["#64af6d"], // Success Green
      fill: {
        type: "gradient",
        gradient: {
          opacityFrom: 0.5,
          opacityTo: 0.2,
          stops: [0, 60],
        },
      },
      yaxis: { min: 0 },
    };

    const expenseChartEl = document.querySelector("#expenseTrendChart");
    if (expenseChartEl) {
      expenseChartEl.innerHTML = "";
      const chart2 = new ApexCharts(expenseChartEl, expenseOptions);
      chart2.render();
    }
  }

  function renderTopExpenseLines() {
    const tbody = document.getElementById("topExpenseLinesTable");
    if (!tbody) return;

    // Filter expenses and sort by budgeted amount descending
    const expenseLines = budgetLines
      .filter((l) => l.budget_line?.budget_category?.slug === "expense")
      .sort(
        (a, b) =>
          parseFloat(b.budgeted_amount || 0) -
          parseFloat(a.budgeted_amount || 0),
      )
      .slice(0, 5);

    if (expenseLines.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="5" class="text-center py-4">
            <div class="text-muted">
              <p class="mb-0">No expense lines found</p>
            </div>
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = expenseLines
      .map((line) => {
        const budgeted = parseFloat(line.budgeted_amount || 0);
        const actual = parseFloat(line.actual_amount || 0);
        const utilization = budgeted > 0 ? (actual / budgeted) * 100 : 0;

        const utilizationClass =
          utilization > 100
            ? "danger"
            : utilization > 80
              ? "warning"
              : "success";
        const statusText = actual > budgeted ? "Over" : "Within";
        const statusClass = actual > budgeted ? "danger" : "success";

        return `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <span class="avatar avatar-xs bg-light text-dark avatar-rounded me-2">
                           ${line.budget_line?.code?.substring(0, 2) || "GL"}
                        </span>
                        <div>
                            <span class="fw-semibold d-block">${line.budget_line?.name}</span>
                            <span class="fs-11 text-muted">${line.budget_line?.code}</span>
                        </div>
                    </div>
                </td>
                <td class="fw-semibold text-end">KES ${formatNumber(budgeted)}</td>
                <td class="text-end text-muted">KES ${formatNumber(actual)}</td>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="progress progress-xs flex-fill me-2">
                             <div class="progress-bar bg-${utilizationClass}" style="width: ${Math.min(utilization, 100)}%"></div>
                        </div>
                        <span class="fs-11 text-muted">${utilization.toFixed(0)}%</span>
                    </div>
                </td>
                 <td>
                    <span class="badge bg-${statusClass}-transparent">${statusText}</span>
                </td>
            </tr>
        `;
      })
      .join("");
  }

  // ========================================================================
  // ACTION FUNCTIONS
  // ========================================================================

  function updateActionButtons() {
    if (!currentBudget) return;

    const status = currentBudget.status?.slug || "draft";

    // Show/hide buttons based on status
    const editBtn = document.getElementById("editBudgetBtn");
    const submitBtn = document.getElementById("submitBudgetBtn");
    const approveBtn = document.getElementById("approveBudgetBtn");
    const rejectBtn = document.getElementById("rejectBudgetBtn");
    const activateBtn = document.getElementById("activateBudgetBtn");

    if (editBtn)
      editBtn.style.display = ["draft", "rejected"].includes(status)
        ? "inline-block"
        : "none";
    if (submitBtn)
      submitBtn.style.display = ["draft"].includes(status)
        ? "inline-block"
        : "none";
    if (approveBtn)
      approveBtn.style.display = ["submitted", "under_review"].includes(status)
        ? "inline-block"
        : "none";
    if (rejectBtn)
      rejectBtn.style.display = ["submitted", "under_review"].includes(status)
        ? "inline-block"
        : "none";
    if (activateBtn)
      activateBtn.style.display = ["approved"].includes(status)
        ? "inline-block"
        : "none";
  }

  function editBudget() {
    window.location.href = `/makueni-west/diocese/budget-management/budget-overview/edit-budget.php?id=${budgetId}`;
  }

  async function submitBudget() {
    if (!confirm("Are you sure you want to submit this budget for approval?"))
      return;

    try {
      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      const response = await fetch(`${API_BASE}/budgets/${budgetId}/submit`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
      });

      const result = await response.json();

      if (result.success) {
        showSuccess("Budget submitted successfully!");
        loadBudget(); // Reload to update status
      } else {
        throw new Error(result.message || "Failed to submit budget");
      }
    } catch (error) {
      console.error("Error submitting budget:", error);
      showError("Failed to submit budget. Please try again.");
    }
  }

  async function approveBudget() {
    if (!confirm("Are you sure you want to approve this budget?")) return;

    try {
      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      const response = await fetch(`${API_BASE}/budgets/${budgetId}/approve`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
      });

      const result = await response.json();

      if (result.success) {
        showSuccess("Budget approved successfully!");
        loadBudget();
      } else {
        throw new Error(result.message || "Failed to approve budget");
      }
    } catch (error) {
      console.error("Error approving budget:", error);
      showError("Failed to approve budget. Please try again.");
    }
  }

  async function rejectBudget() {
    const reason = prompt("Please provide a reason for rejecting this budget:");
    if (!reason) return;

    try {
      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      const response = await fetch(`${API_BASE}/budgets/${budgetId}/reject`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ reason }),
      });

      const result = await response.json();

      if (result.success) {
        showSuccess("Budget rejected successfully!");
        loadBudget();
      } else {
        throw new Error(result.message || "Failed to reject budget");
      }
    } catch (error) {
      console.error("Error rejecting budget:", error);
      showError("Failed to reject budget. Please try again.");
    }
  }

  async function activateBudget() {
    if (!confirm("Are you sure you want to activate this budget?")) return;

    try {
      const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
      const API_BASE =
        window.AppConfig?.API_BASE_URL || "http://localhost:8000/api";

      const response = await fetch(`${API_BASE}/budgets/${budgetId}/activate`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
      });

      const result = await response.json();

      if (result.success) {
        showSuccess("Budget activated successfully!");
        loadBudget();
      } else {
        throw new Error(result.message || "Failed to activate budget");
      }
    } catch (error) {
      console.error("Error activating budget:", error);
      showError("Failed to activate budget. Please try again.");
    }
  }

  // ========================================================================
  // UTILITY FUNCTIONS
  // ========================================================================

  function getStatusClass(status) {
    const statusMap = {
      draft: "secondary",
      submitted: "info",
      under_review: "warning",
      approved: "success",
      rejected: "danger",
      active: "primary",
      closed: "dark",
    };
    return statusMap[status] || "secondary";
  }

  function getActionIcon(action) {
    const iconMap = {
      created: "ri-add-circle-line",
      updated: "ri-edit-line",
      status_changed: "ri-exchange-line",
      submitted: "ri-send-plane-line",
      approved: "ri-checkbox-circle-line",
      rejected: "ri-close-circle-line",
      activated: "ri-play-circle-line",
      closed: "ri-lock-line",
      line_added: "ri-add-line",
      line_updated: "ri-edit-2-line",
      line_deleted: "ri-delete-bin-line",
      deduction_applied: "ri-subtract-line",
      deduction_reversed: "ri-refresh-line",
    };
    return iconMap[action] || "ri-record-circle-line";
  }

  function getActionColor(action) {
    const colorMap = {
      created: "primary",
      updated: "info",
      submitted: "info",
      approved: "success",
      rejected: "danger",
      activated: "primary",
      closed: "dark",
      status_changed: "warning",
      line_added: "success",
      line_updated: "info",
      line_deleted: "danger",
      deduction_applied: "warning",
      deduction_reversed: "info",
    };
    return colorMap[action] || "secondary";
  }

  function formatActionName(action) {
    return action
      .split("_")
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(" ");
  }

  function formatNumber(num) {
    return new Intl.NumberFormat().format(parseFloat(num || 0).toFixed(2));
  }

  function showSuccess(message) {
    if (window.Toast) {
      Toast.success(message);
    } else {
      alert(message);
    }
  }

  function showError(message) {
    if (window.Toast) {
      Toast.error(message);
    } else {
      alert(message);
    }
  }

  // Global functions for inline onclick handlers
  window.viewLineDetails = function (id) {
    // TODO: Implement line details modal
    console.log("View line details:", id);
  };

  window.viewDeductionDetails = function (id) {
    // TODO: Implement deduction details modal
    console.log("View deduction details:", id);
  };

  window.reverseDeduction = function (id) {
    // TODO: Implement reverse deduction
    console.log("Reverse deduction:", id);
  };
})();
