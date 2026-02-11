/**
 * ============================================================================
 * API HANDLER - BUDGET MANAGEMENT
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Centralized API calls for:
 * - Budgets (GET, POST, PUT, DELETE)
 * - Budget Types (GET)
 * - Budget Lines (GET)
 * - Budget Categories (GET)
 * - Budget Line Items (POST, PUT, DELETE)
 * - Budget Workflow (submit, approve, reject, activate, close)
 * - Budget Deductions (apply, reverse)
 * - Budget Logs (GET)
 *
 * All functions return standardized response format
 *
 * Dependencies: Constants.js
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // CONFIGURATION
  // ========================================================================

  const API_BASE =
    window.AppConfig?.API_BASE_URL || "http://127.0.0.1:8000/api";

  /**
   * Get auth token from localStorage
   */
  function getAuthToken() {
    return localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
  }

  /**
   * Get default headers for API requests
   */
  function getHeaders() {
    return {
      "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
      Accept: Constants.HEADERS.ACCEPT_JSON,
      Authorization: `Bearer ${getAuthToken()}`,
    };
  }

  /**
   * Handle API response
   */
  async function handleResponse(response) {
    try {
      const data = await response.json();

      // Determine success: HTTP range 200-299 AND body.success is not explicitly false
      const isSuccess = response.ok && data.success !== false;

      console.log(
        `📡 [Budget API] ${response.url} | Status: ${response.status} | Success: ${isSuccess}`,
      );

      if (isSuccess) {
        return {
          success: true,
          data: data.data !== undefined ? data.data : data,
          message: data.message || "Success",
          meta: data.meta || null,
          stats: data.stats || null,
          pagination: data.pagination || null,
        };
      } else {
        return {
          success: false,
          message: data.message || "Request failed",
          errors: data.errors || null,
          status: response.status,
        };
      }
    } catch (error) {
      console.error("Response parse error:", error);
      return {
        success: false,
        message: "Failed to parse response",
        errors: error,
      };
    }
  }

  /**
   * Handle network/fetch errors
   */
  function handleError(error) {
    console.error("Budget API Error:", error);
    return {
      success: false,
      message: Constants.MESSAGES.NETWORK_ERROR,
      errors: error,
    };
  }

  // ========================================================================
  // BUDGETS API
  // ========================================================================

  /**
   * Get list of budgets with filters and pagination
   * @param {string} queryParams - URL query parameters (e.g., "page=1&per_page=15&status=draft")
   */
  async function getBudgets(queryParams = "") {
    try {
      const url = `${API_BASE}/budgets${queryParams ? "?" + queryParams : ""}`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single budget by ID
   * @param {number} budgetId - Budget ID
   */
  async function getBudget(budgetId) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new budget
   * @param {object} budgetData - Budget data object
   * Example: {
   *   name: 'Annual Budget 2024',
   *   budget_type_id: 1,
   *   territory_type: 'diocese',
   *   territory_id: 1,
   *   fiscal_year: 2024,
   *   start_date: '2024-01-01',
   *   end_date: '2024-12-31',
   *   description: '...',
   *   line_items: [...]
   * }
   */
  async function createBudget(budgetData) {
    try {
      const response = await fetch(`${API_BASE}/budgets`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(budgetData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing budget
   * @param {number} budgetId - Budget ID
   * @param {object} budgetData - Budget data object
   */
  async function updateBudget(budgetId, budgetData) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(budgetData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete budget
   * @param {number} budgetId - Budget ID
   */
  async function deleteBudget(budgetId) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}`, {
        method: Constants.HTTP_METHODS.DELETE,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET WORKFLOW API
  // ========================================================================

  /**
   * Submit budget for approval
   * @param {number} budgetId - Budget ID
   */
  async function submitBudget(budgetId) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}/submit`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Approve budget
   * @param {number} budgetId - Budget ID
   * @param {object} approvalData - Approval data {notes: '...'}
   */
  async function approveBudget(budgetId, approvalData = {}) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}/approve`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(approvalData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Reject budget
   * @param {number} budgetId - Budget ID
   * @param {object} rejectionData - Rejection data {reason: '...'}
   */
  async function rejectBudget(budgetId, rejectionData) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}/reject`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(rejectionData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Activate budget
   * @param {number} budgetId - Budget ID
   */
  async function activateBudget(budgetId) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}/activate`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Close budget
   * @param {number} budgetId - Budget ID
   */
  async function closeBudget(budgetId) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}/close`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Clone budget
   * @param {number} budgetId - Budget ID
   * @param {object} cloneData - Clone data {name: '...', fiscal_year: 2025}
   */
  async function cloneBudget(budgetId, cloneData) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}/clone`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(cloneData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET TYPES API
  // ========================================================================

  /**
   * Get list of budget types
   */
  async function getBudgetTypes() {
    try {
      const response = await fetch(`${API_BASE}/budget-types`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single budget type by ID
   * @param {number} typeId - Budget Type ID
   */
  async function getBudgetType(typeId) {
    try {
      const response = await fetch(`${API_BASE}/budget-types/${typeId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET LINES API
  // ========================================================================

  /**
   * Get list of budget lines (templates)
   * @param {string} queryParams - Optional query parameters (e.g., "line_type=income")
   */
  async function getBudgetLines(queryParams = "") {
    try {
      const url = `${API_BASE}/budget-lines${
        queryParams ? "?" + queryParams : ""
      }`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single budget line by ID
   * @param {number} lineId - Budget Line ID
   */
  async function getBudgetLine(lineId) {
    try {
      const response = await fetch(`${API_BASE}/budget-lines/${lineId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET CATEGORIES API
  // ========================================================================

  /**
   * Get list of budget categories
   */
  async function getBudgetCategories() {
    try {
      const response = await fetch(`${API_BASE}/budget-categories`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single budget category by ID
   * @param {number} categoryId - Budget Category ID
   */
  async function getBudgetCategory(categoryId) {
    try {
      const response = await fetch(
        `${API_BASE}/budget-categories/${categoryId}`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        },
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET LINE ITEMS API
  // ========================================================================

  /**
   * Add line item to budget
   * @param {number} budgetId - Budget ID
   * @param {object} lineItemData - Line item data
   */
  async function addLineItem(budgetId, lineItemData) {
    try {
      const response = await fetch(
        `${API_BASE}/budgets/${budgetId}/line-items`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: getHeaders(),
          body: JSON.stringify(lineItemData),
        },
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update budget line item
   * @param {number} budgetId - Budget ID
   * @param {number} lineItemId - Line Item ID
   * @param {object} lineItemData - Line item data
   */
  async function updateLineItem(budgetId, lineItemId, lineItemData) {
    try {
      const response = await fetch(
        `${API_BASE}/budgets/${budgetId}/line-items/${lineItemId}`,
        {
          method: Constants.HTTP_METHODS.PUT,
          headers: getHeaders(),
          body: JSON.stringify(lineItemData),
        },
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete budget line item
   * @param {number} budgetId - Budget ID
   * @param {number} lineItemId - Line Item ID
   */
  async function deleteLineItem(budgetId, lineItemId) {
    try {
      const response = await fetch(
        `${API_BASE}/budgets/${budgetId}/line-items/${lineItemId}`,
        {
          method: Constants.HTTP_METHODS.DELETE,
          headers: getHeaders(),
        },
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET DEDUCTIONS API
  // ========================================================================

  /**
   * Apply deduction to budget
   * @param {number} budgetId - Budget ID
   * @param {object} deductionData - Deduction data {budget_deduction_id: 1, notes: '...'}
   */
  async function applyDeduction(budgetId, deductionData) {
    try {
      const response = await fetch(
        `${API_BASE}/budgets/${budgetId}/apply-deduction`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: getHeaders(),
          body: JSON.stringify(deductionData),
        },
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Reverse deduction from budget
   * @param {number} budgetId - Budget ID
   * @param {number} deductionItemId - Budget Deduction Item ID
   * @param {object} reversalData - Reversal data {reason: '...'}
   */
  async function reverseDeduction(budgetId, deductionItemId, reversalData) {
    try {
      const response = await fetch(
        `${API_BASE}/budgets/${budgetId}/reverse-deduction/${deductionItemId}`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: getHeaders(),
          body: JSON.stringify(reversalData),
        },
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET LOGS API
  // ========================================================================

  /**
   * Get budget logs (audit trail)
   * @param {number} budgetId - Budget ID
   * @param {string} queryParams - Optional query parameters
   */
  async function getBudgetLogs(budgetId, queryParams = "") {
    try {
      const url = `${API_BASE}/budgets/${budgetId}/logs${
        queryParams ? "?" + queryParams : ""
      }`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get budget summary
   * @param {number} budgetId - Budget ID
   */
  async function getBudgetSummary(budgetId) {
    try {
      const response = await fetch(`${API_BASE}/budgets/${budgetId}/summary`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // FISCAL YEARS API
  // ========================================================================

  /**
   * Get list of fiscal years
   * @param {boolean} activeOnly - If true, only return active years
   */
  async function getFiscalYears(activeOnly = false) {
    try {
      const url = `${API_BASE}/fiscal-years${activeOnly ? "?is_active=true" : ""}`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single fiscal year with quarters and semi-annuals
   * @param {number} fiscalYearId - Fiscal Year ID
   */
  async function getFiscalYear(fiscalYearId) {
    try {
      const response = await fetch(`${API_BASE}/fiscal-years/${fiscalYearId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // BUDGET PERIODS API
  // ========================================================================

  /**
   * Get list of budget periods with filtering
   * @param {number} fiscalYearId - Fiscal Year ID (required for useful results)
   * @param {number} budgetTypeId - Budget Type ID (monthly, quarterly, etc.)
   */
  async function getBudgetPeriods(fiscalYearId, budgetTypeId = null) {
    try {
      let url = `${API_BASE}/budget-periods?fiscal_year_id=${fiscalYearId}`;

      if (budgetTypeId) {
        url += `&budget_type_id=${budgetTypeId}`;
      }

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single budget period details
   * @param {number} periodId - Budget Period ID
   */
  async function getBudgetPeriod(periodId) {
    try {
      const response = await fetch(`${API_BASE}/budget-periods/${periodId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.BudgetAPIHandler = {
    // Budgets
    getBudgets,
    getBudget,
    createBudget,
    updateBudget,
    deleteBudget,

    // Workflow
    submitBudget,
    approveBudget,
    rejectBudget,
    activateBudget,
    closeBudget,
    cloneBudget,

    // Budget Types
    getBudgetTypes,
    getBudgetType,

    // Budget Lines
    getBudgetLines,
    getBudgetLine,

    // Budget Categories
    getBudgetCategories,
    getBudgetCategory,

    // Line Items
    addLineItem,
    updateLineItem,
    deleteLineItem,

    // Deductions
    applyDeduction,
    reverseDeduction,

    // Logs & Summary
    getBudgetLogs,
    getBudgetSummary,

    // Fiscal Years
    getFiscalYears,
    getFiscalYear,

    // Budget Periods
    getBudgetPeriods,
    getBudgetPeriod,
  };

  console.log("✅ Budget API Handler loaded successfully");
})();
