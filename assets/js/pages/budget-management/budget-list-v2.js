/**
 * ============================================================================
 * ALL BUDGETS PAGE JAVASCRIPT - VERSION 2
 * ============================================================================
 * Fresh recreation to avoid browser cache issues
 * Simple, direct navigation with window.location.href
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // STATE VARIABLES
  // ========================================================================

  let budgets = [];
  let fiscalYears = [];
  let budgetTypes = [];
  let budgetStatuses = [
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'under_review', label: 'Under Review' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'active', label: 'Active' },
    { value: 'closed', label: 'Closed' }
  ];

  // Filters
  let fiscalYearFilter = '2026'; // Default to current year
  let statusFilter = '';
  let budgetTypeFilter = '';
  let searchQuery = '';
  let currentPage = 1;
  let perPage = 10;

  // Choices instances
  let fiscalYearChoices = null;
  let statusChoices = null;
  let budgetTypeChoices = null;

  // Debounce timer for search
  let searchDebounceTimer = null;

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  function init() {
    console.log("📊 Budget List V2: Initializing...");

    initializeFilterControls();
    bindEventListeners();
    loadInitialData();
  }

  function initializeFilterControls() {
    // Fiscal Year Choices
    const fiscalYearSelect = document.getElementById('fiscalYearFilter');
    if (fiscalYearSelect) {
      fiscalYearChoices = new Choices(fiscalYearSelect, {
        searchEnabled: false,
        itemSelectText: '',
        allowHTML: true
      });
    }

    // Status Choices
    const statusSelect = document.getElementById('statusFilter');
    if (statusSelect) {
      statusChoices = new Choices(statusSelect, {
        searchEnabled: false,
        itemSelectText: '',
        allowHTML: true
      });
    }

    // Budget Type Choices
    const budgetTypeSelect = document.getElementById('budgetTypeFilter');
    if (budgetTypeSelect) {
      budgetTypeChoices = new Choices(budgetTypeSelect, {
        searchEnabled: true,
        itemSelectText: '',
        allowHTML: true
      });
    }
  }

  function bindEventListeners() {
    // Create Budget Button
    const createBtn = document.getElementById('createBudgetBtn');
    if (createBtn) {
      createBtn.addEventListener('click', navigateToCreateBudget);
    }

    // Export Button
    const exportBtn = document.getElementById('exportBudgetsBtn');
    if (exportBtn) {
      exportBtn.addEventListener('click', exportBudgets);
    }

    // Filter Changes
    const fiscalYearSelect = document.getElementById('fiscalYearFilter');
    if (fiscalYearSelect) {
      fiscalYearSelect.addEventListener('change', handleFiscalYearChange);
    }

    const statusSelect = document.getElementById('statusFilter');
    if (statusSelect) {
      statusSelect.addEventListener('change', handleStatusChange);
    }

    const budgetTypeSelect = document.getElementById('budgetTypeFilter');
    if (budgetTypeSelect) {
      budgetTypeSelect.addEventListener('change', handleBudgetTypeChange);
    }

    // Search Input
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
      searchInput.addEventListener('input', handleSearchInput);
    }
  }

  // ========================================================================
  // DATA LOADING
  // ========================================================================

  async function loadInitialData() {
    try {
      // Load filter options in parallel
      const [yearsResult, typesResult] = await Promise.all([
        BudgetAPIHandler.getFiscalYears(),
        BudgetAPIHandler.getBudgetTypes()
      ]);

      if (yearsResult.success) {
        fiscalYears = yearsResult.data || [];
        populateFiscalYearFilter();
      }

      if (typesResult.success) {
        budgetTypes = typesResult.data || [];
        populateBudgetTypeFilter();
      }

      // Populate status filter
      populateStatusFilter();

      // Load budgets
      await loadBudgets();
    } catch (error) {
      console.error('Error loading initial data:', error);
      Toast.error('Failed to load page data');
    }
  }

  async function loadBudgets() {
    try {
      showLoading();

      const filters = {
        page: currentPage,
        per_page: perPage
      };

      if (fiscalYearFilter) filters.fiscal_year = fiscalYearFilter;
      if (statusFilter) filters.status = statusFilter;
      if (budgetTypeFilter) filters.budget_type_id = budgetTypeFilter;
      if (searchQuery) filters.search = searchQuery;

      // Convert filters object to query string
      const queryString = Object.keys(filters)
        .map(key => `${encodeURIComponent(key)}=${encodeURIComponent(filters[key])}`)
        .join('&');

      console.log('🔍 Loading budgets with filters:', queryString);

      const result = await BudgetAPIHandler.getBudgets(queryString);

      console.log('📊 API Result:', result);
      console.log('📊 Meta:', result.meta);
      console.log('📊 Stats:', result.stats);

      if (result.success) {
        budgets = result.data || [];
        renderBudgetsTable();
        renderPagination(result.meta || {});
        updateStatistics(result.stats || {});
      } else {
        throw new Error(result.message || 'Failed to load budgets');
      }
    } catch (error) {
      console.error('Error loading budgets:', error);
      showError('Failed to load budgets');
    }
  }

  // ========================================================================
  // FILTER POPULATION
  // ========================================================================

  function populateFiscalYearFilter() {
    if (!fiscalYearChoices) return;

    const options = fiscalYears.map(year => ({
      value: year.year.toString(),
      label: year.year.toString(),
      selected: year.year.toString() === '2026' // Select 2026 by default
    }));

    options.unshift({ value: '', label: 'All Years' });
    fiscalYearChoices.setChoices(options, 'value', 'label', true);

    // Set the value to 2026
    fiscalYearChoices.setChoiceByValue('2026');
  }

  function populateStatusFilter() {
    if (!statusChoices) return;

    const options = budgetStatuses.map(status => ({
      value: status.value,
      label: status.label
    }));

    options.unshift({ value: '', label: 'All Statuses', selected: true });
    statusChoices.setChoices(options, 'value', 'label', true);
  }

  function populateBudgetTypeFilter() {
    if (!budgetTypeChoices) return;

    const options = budgetTypes.map(type => ({
      value: type.id.toString(),
      label: type.name
    }));

    options.unshift({ value: '', label: 'All Types', selected: true });
    budgetTypeChoices.setChoices(options, 'value', 'label', true);
  }

  // ========================================================================
  // FILTER HANDLERS
  // ========================================================================

  function handleFiscalYearChange(e) {
    fiscalYearFilter = e.target.value;
    currentPage = 1;
    loadBudgets();
  }

  function handleStatusChange(e) {
    statusFilter = e.target.value;
    currentPage = 1;
    loadBudgets();
  }

  function handleBudgetTypeChange(e) {
    budgetTypeFilter = e.target.value;
    currentPage = 1;
    loadBudgets();
  }

  function handleSearchInput(e) {
    const value = e.target.value.trim();

    // Debounce search
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
      searchQuery = value;
      currentPage = 1;
      loadBudgets();
    }, 500);
  }

  // ========================================================================
  // TABLE RENDERING
  // ========================================================================

  function renderBudgetsTable() {
    const tbody = document.getElementById('budgetsTableBody');
    if (!tbody) return;

    if (!budgets || budgets.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center py-5">
            <i class="ri-file-list-line fs-3 d-block mb-2 text-muted"></i>
            <p class="text-muted mb-0">No budgets found</p>
          </td>
        </tr>
      `;
      return;
    }

    const html = budgets.map(budget => {
      const status = budget.status_relation || { name: budget.status, slug: budget.status };
      const budgetType = budget.budget_type || { name: 'N/A' };
      const netBudget = (budget.total_income_budgeted || 0) - (budget.total_expense_budgeted || 0);
      const netClass = netBudget >= 0 ? 'text-success' : 'text-danger';

      return `
        <tr>
          <td><strong>${budget.fiscal_year || 'N/A'}</strong></td>
          <td>${budgetType.name}</td>
          <td>${renderStatusBadge(status)}</td>
          <td class="text-end text-success">${formatCurrency(budget.total_income_budgeted || 0)}</td>
          <td class="text-end text-danger">${formatCurrency(budget.total_expense_budgeted || 0)}</td>
          <td class="text-end ${netClass}"><strong>${formatCurrency(netBudget)}</strong></td>
          <td>${formatDate(budget.created_at)}</td>
          <td class="text-center">${renderActionButtons(budget)}</td>
        </tr>
      `;
    }).join('');

    tbody.innerHTML = html;
  }

  function renderStatusBadge(status) {
    const statusSlug = typeof status === 'object' ? status.slug : status;
    const statusName = typeof status === 'object' ? status.name : status;

    const statusConfig = {
      draft: { class: 'secondary', icon: 'ri-draft-line' },
      submitted: { class: 'info', icon: 'ri-send-plane-line' },
      under_review: { class: 'warning', icon: 'ri-time-line' },
      approved: { class: 'success', icon: 'ri-checkbox-circle-line' },
      rejected: { class: 'danger', icon: 'ri-close-circle-line' },
      active: { class: 'primary', icon: 'ri-play-circle-line' },
      closed: { class: 'dark', icon: 'ri-stop-circle-line' },
    };

    const config = statusConfig[statusSlug] || statusConfig.draft;

    return `
      <span class="badge bg-${config.class}-transparent">
        <i class="${config.icon} me-1"></i>${statusName}
      </span>
    `;
  }

  function renderActionButtons(budget) {
    const statusSlug = budget.status_relation?.slug || budget.status;
    const buttons = [];

    console.log(`🔘 Rendering buttons for Budget ID: ${budget.id}, Status: ${statusSlug}`);

    // View button (always)
    const viewUrl = `${BASE_URL}/diocese/budget-management/budget-overview/budget-details.php?id=${budget.id}`;
    console.log(`   👁️ View URL: ${viewUrl}`);
    buttons.push(`
      <button class="btn btn-icon btn-sm btn-info-light btn-wave" onclick="BudgetList.viewBudget(${budget.id})" title="View Details">
        <i class="ri-eye-line"></i>
      </button>
    `);

    // Edit button (draft, rejected, submitted, under_review)
    if (['draft', 'rejected', 'submitted', 'under_review'].includes(statusSlug)) {
      const editUrl = `${BASE_URL}/diocese/budget-management/budget-overview/edit-budget.php?id=${budget.id}`;
      console.log(`   ✏️ Edit URL: ${editUrl}`);
      buttons.push(`
        <button class="btn btn-icon btn-sm btn-primary-light btn-wave" onclick="BudgetList.editBudget(${budget.id})" title="Edit Budget">
          <i class="ri-edit-line"></i>
        </button>
      `);
    }

    // Submit button (draft only)
    if (statusSlug === 'draft') {
      console.log(`   📤 Submit button added for Budget ID: ${budget.id}`);
      buttons.push(`
        <button class="btn btn-icon btn-sm btn-success-light btn-wave" onclick="BudgetList.submitBudget(${budget.id})" title="Submit for Approval">
          <i class="ri-send-plane-line"></i>
        </button>
      `);
    }

    // Activate button (approved only)
    if (statusSlug === 'approved') {
      console.log(`   ▶️ Activate button added for Budget ID: ${budget.id}`);
      buttons.push(`
        <button class="btn btn-icon btn-sm btn-success-light btn-wave" onclick="BudgetList.activateBudget(${budget.id})" title="Activate Budget">
          <i class="ri-play-circle-line"></i>
        </button>
      `);
    }

    // Print button (always)
    const printUrl = `${BASE_URL}/diocese/budget-management/budget-overview/budget-details.php?id=${budget.id}&print=true`;
    console.log(`   🖨️ Print URL: ${printUrl}`);
    buttons.push(`
      <button class="btn btn-icon btn-sm btn-secondary-light btn-wave" onclick="BudgetList.printBudget(${budget.id})" title="Print/Download">
        <i class="ri-printer-line"></i>
      </button>
    `);

    // Delete button (draft, rejected only)
    if (['draft', 'rejected'].includes(statusSlug)) {
      console.log(`   🗑️ Delete button added for Budget ID: ${budget.id}`);
      buttons.push(`
        <button class="btn btn-icon btn-sm btn-danger-light btn-wave" onclick="BudgetList.deleteBudget(${budget.id})" title="Delete Budget">
          <i class="ri-delete-bin-line"></i>
        </button>
      `);
    }

    console.log(`   ✅ Total buttons rendered: ${buttons.length}`);
    return buttons.join(' ');
  }

  // ========================================================================
  // PAGINATION
  // ========================================================================

  function renderPagination(meta) {
    console.log('📄 renderPagination called with:', meta);

    const pagination = document.getElementById('pagination');
    const resultsInfo = document.getElementById('resultsInfo');

    if (!pagination) return;

    const totalPages = meta.last_page || 1;
    const currentP = meta.current_page || 1;
    const total = meta.total || 0;
    const from = meta.from || 0;
    const to = meta.to || 0;

    console.log('📄 Pagination values:', { totalPages, currentP, total, from, to });

    // Update results info
    if (resultsInfo) {
      resultsInfo.textContent = `Showing ${from} to ${to} of ${total} budgets`;
    }

    if (totalPages <= 1) {
      pagination.innerHTML = '';
      return;
    }

    let html = '';

    // Previous button
    html += `
      <li class="page-item ${currentP === 1 ? 'disabled' : ''}">
        <a class="page-link" href="javascript:void(0);" onclick="BudgetList.goToPage(${currentP - 1})">
          <i class="ri-arrow-left-s-line"></i>
        </a>
      </li>
    `;

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || (i >= currentP - 2 && i <= currentP + 2)) {
        html += `
          <li class="page-item ${i === currentP ? 'active' : ''}">
            <a class="page-link" href="javascript:void(0);" onclick="BudgetList.goToPage(${i})">${i}</a>
          </li>
        `;
      } else if (i === currentP - 3 || i === currentP + 3) {
        html += `<li class="page-item disabled"><a class="page-link">...</a></li>`;
      }
    }

    // Next button
    html += `
      <li class="page-item ${currentP === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="javascript:void(0);" onclick="BudgetList.goToPage(${currentP + 1})">
          <i class="ri-arrow-right-s-line"></i>
        </a>
      </li>
    `;

    pagination.innerHTML = html;
  }

  function goToPage(page) {
    currentPage = page;
    loadBudgets();
  }

  // ========================================================================
  // STATISTICS
  // ========================================================================

  function updateStatistics(stats) {
    console.log('📈 updateStatistics called with:', stats);

    // Count elements
    const totalCount = document.getElementById('totalBudgetsCount');
    const totalTrend = document.getElementById('totalBudgetsTrend');
    const activeCount = document.getElementById('activeBudgetsCount');
    const activeTrend = document.getElementById('activeBudgetsTrend');
    const pendingCount = document.getElementById('pendingApprovalCount');
    const totalAmount = document.getElementById('totalAmountValue');

    console.log('📈 Found elements:', {
      totalCount: !!totalCount,
      totalTrend: !!totalTrend,
      activeCount: !!activeCount,
      activeTrend: !!activeTrend,
      pendingCount: !!pendingCount,
      totalAmount: !!totalAmount
    });

    // Update counts
    if (totalCount) totalCount.textContent = stats.total || 0;
    if (activeCount) activeCount.textContent = stats.active || 0;
    if (pendingCount) pendingCount.textContent = stats.pending_approval || 0;
    if (totalAmount) totalAmount.textContent = formatCompactCurrency(stats.total_amount || 0);

    // Update trends dynamically
    if (totalTrend) {
      const trend = stats.total_trend || 0;
      const trendClass = trend >= 0 ? 'text-success' : 'text-danger';
      const trendIcon = trend >= 0 ? '↑' : '↓';
      totalTrend.innerHTML = `<span class="${trendClass}">${trendIcon}${Math.abs(trend)}% this month</span>`;
    }

    if (activeTrend) {
      const trend = stats.active_trend || 0;
      const trendClass = trend >= 0 ? 'text-success' : 'text-danger';
      const trendIcon = trend >= 0 ? '↑' : '↓';
      activeTrend.innerHTML = `<span class="${trendClass}">${trendIcon}${Math.abs(trend)}% this month</span>`;
    }
  }

  // ========================================================================
  // NAVIGATION FUNCTIONS
  // ========================================================================

  const BASE_URL = window.APP_BASE_URL || '/makueni-west';

  function navigateToCreateBudget() {
    const url = `${BASE_URL}/diocese/budget-management/budget-overview/create-budget`;
    console.log('🔗 Navigating to CREATE:', url);
    window.location.href = url;
  }

  function navigateToViewBudget(id) {
    const url = `${BASE_URL}/diocese/budget-management/budget-overview/budget-details?id=${id}`;
    console.log('🔗 Navigating to VIEW:', url);
    window.location.href = url;
  }

  function navigateToEditBudget(id) {
    const url = `${BASE_URL}/diocese/budget-management/budget-overview/edit-budget?id=${id}`;
    console.log('🔗 Navigating to EDIT:', url);
    window.location.href = url;
  }

  function navigateToPrintBudget(id) {
    const url = `${BASE_URL}/diocese/budget-management/budget-overview/budget-details?id=${id}&print=true`;
    console.log('🔗 Opening PRINT:', url);
    window.open(url, '_blank');
  }

  // ========================================================================
  // ACTION FUNCTIONS
  // ========================================================================

  async function submitBudget(id) {
    if (!confirm('Are you sure you want to submit this budget for approval?')) {
      return;
    }

    try {
      const result = await BudgetAPIHandler.submitBudget(id);

      if (result.success) {
        Toast.success('Budget submitted successfully');
        loadBudgets();
      } else {
        throw new Error(result.message || 'Failed to submit budget');
      }
    } catch (error) {
      console.error('Error submitting budget:', error);
      Toast.error(error.message || 'Failed to submit budget');
    }
  }

  async function activateBudget(id) {
    if (!confirm('Are you sure you want to activate this budget?')) {
      return;
    }

    try {
      const result = await BudgetAPIHandler.activateBudget(id);

      if (result.success) {
        Toast.success('Budget activated successfully');
        loadBudgets();
      } else {
        throw new Error(result.message || 'Failed to activate budget');
      }
    } catch (error) {
      console.error('Error activating budget:', error);
      Toast.error(error.message || 'Failed to activate budget');
    }
  }

  async function deleteBudget(id) {
    if (!confirm('Are you sure you want to delete this budget? This action cannot be undone.')) {
      return;
    }

    try {
      const result = await BudgetAPIHandler.deleteBudget(id);

      if (result.success) {
        Toast.success('Budget deleted successfully');
        loadBudgets();
      } else {
        throw new Error(result.message || 'Failed to delete budget');
      }
    } catch (error) {
      console.error('Error deleting budget:', error);
      Toast.error(error.message || 'Failed to delete budget');
    }
  }

  async function exportBudgets() {
    try {
      Toast.info('Exporting budgets...');
      // TODO: Implement export functionality
      Toast.warning('Export functionality coming soon');
    } catch (error) {
      console.error('Error exporting budgets:', error);
      Toast.error('Failed to export budgets');
    }
  }

  // ========================================================================
  // UTILITY FUNCTIONS
  // ========================================================================

  function showLoading() {
    const tbody = document.getElementById('budgetsTableBody');
    if (tbody) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading budgets...</p>
          </td>
        </tr>
      `;
    }
  }

  function showError(message) {
    const tbody = document.getElementById('budgetsTableBody');
    if (tbody) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center py-5">
            <i class="ri-error-warning-line fs-3 d-block mb-2 text-danger"></i>
            <p class="text-danger mb-0">${message}</p>
          </td>
        </tr>
      `;
    }
  }

  function formatCurrency(amount) {
    return `KES ${parseFloat(amount || 0).toLocaleString('en-KE', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    })}`;
  }

  function formatCompactCurrency(amount) {
    const num = parseFloat(amount || 0);

    if (num >= 1000000000) {
      return `KES ${(num / 1000000000).toFixed(1)}B`;
    } else if (num >= 1000000) {
      return `KES ${(num / 1000000).toFixed(1)}M`;
    } else if (num >= 1000) {
      return `KES ${(num / 1000).toFixed(1)}K`;
    } else {
      return `KES ${num.toFixed(2)}`;
    }
  }

  function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric'
    });
  }

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.BudgetList = {
    init: init,
    viewBudget: navigateToViewBudget,
    editBudget: navigateToEditBudget,
    printBudget: navigateToPrintBudget,
    submitBudget: submitBudget,
    activateBudget: activateBudget,
    deleteBudget: deleteBudget,
    goToPage: goToPage
  };

  console.log("✅ Budget List V2: Module loaded");
})();
