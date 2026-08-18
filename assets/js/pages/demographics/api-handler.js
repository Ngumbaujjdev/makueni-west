/**
 * ============================================================================
 * API HANDLER - DEMOGRAPHICS & ATTENDANCE
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Centralized fetch() layer for both the Demographics (monthly snapshot)
 * and Attendance (weekly/event) models - one shared module since they're
 * one cohesive feature area, mirrored on
 * assets/js/pages/budget-management/api-handler.js's shape.
 *
 * Dependencies: config/app.js (AppConfig), config/constants.js (Constants)
 * ============================================================================
 */

(function () {
  "use strict";

  const API_BASE = AppConfig.API_BASE_URL;

  function getAuthToken() {
    return localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
  }

  function getHeaders() {
    return {
      "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
      Accept: Constants.HEADERS.ACCEPT_JSON,
      Authorization: `Bearer ${getAuthToken()}`,
    };
  }

  async function handleResponse(response) {
    try {
      const data = await response.json();
      const isSuccess = response.ok && data.success !== false;

      if (isSuccess) {
        return {
          success: true,
          data: data.data !== undefined ? data.data : data,
          message: data.message || "Success",
          warnings: data.warnings || [],
          status: data.status || null,
        };
      }

      return {
        success: false,
        message: data.message || "Request failed",
        errors: data.errors || null,
        data: data.data || null, // 422 duplicate-period responses carry the existing row here
        status: response.status,
      };
    } catch (error) {
      console.error("Response parse error:", error);
      return { success: false, message: "Failed to parse response", errors: error };
    }
  }

  function handleError(error) {
    console.error("Demographics API Error:", error);
    return {
      success: false,
      message: Constants.MESSAGES.NETWORK_ERROR,
      errors: error,
    };
  }

  // ==========================================================================
  // DEMOGRAPHICS
  // ==========================================================================

  async function getDemographics(territoryId) {
    try {
      const response = await fetch(`${API_BASE}/demographics?territory_id=${territoryId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function getDemographic(id) {
    try {
      const response = await fetch(`${API_BASE}/demographics/${id}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function createDemographic(payload) {
    try {
      const response = await fetch(`${API_BASE}/demographics`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(payload),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function updateDemographic(id, payload) {
    try {
      const response = await fetch(`${API_BASE}/demographics/${id}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(payload),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function submitDemographic(id) {
    try {
      const response = await fetch(`${API_BASE}/demographics/${id}/submit`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function approveDemographic(id, notes = null) {
    try {
      const response = await fetch(`${API_BASE}/demographics/${id}/approve`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify({ notes }),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function flagDemographic(id, notes = null) {
    try {
      const response = await fetch(`${API_BASE}/demographics/${id}/flag`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify({ notes }),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function requestChangesDemographic(id, notes = null) {
    try {
      const response = await fetch(`${API_BASE}/demographics/${id}/request-changes`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify({ notes }),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function getSummary(territoryId, fiscalYearId, fiscalMonthId) {
    try {
      const url = `${API_BASE}/demographics/summary/${territoryId}?fiscal_year_id=${fiscalYearId}&fiscal_month_id=${fiscalMonthId}`;
      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ==========================================================================
  // ENTRY MODE (per-church weekly+monthly vs monthly-only setting)
  // ==========================================================================

  async function getEntryMode(churchId) {
    try {
      const response = await fetch(`${API_BASE}/churches/${churchId}/entry-mode`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function updateEntryMode(churchId, attendanceMode) {
    try {
      const response = await fetch(`${API_BASE}/churches/${churchId}/entry-mode`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify({ attendance_mode: attendanceMode }),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ==========================================================================
  // ATTENDANCE
  // ==========================================================================

  async function getAttendance(territoryId, filters = {}) {
    try {
      const params = new URLSearchParams({ territory_id: territoryId, ...filters });
      const response = await fetch(`${API_BASE}/attendance?${params.toString()}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function createAttendance(payload) {
    try {
      const response = await fetch(`${API_BASE}/attendance`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(payload),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function updateAttendance(id, payload) {
    try {
      const response = await fetch(`${API_BASE}/attendance/${id}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(payload),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ==========================================================================
  // FISCAL PERIOD HELPERS
  //
  // There is no GET /fiscal-months endpoint. Reuses the already-existing,
  // already-generic GET /budget-periods?fiscal_year_id=&budget_type_id=
  // endpoint (eager-loads fiscal_month on each period row) as a lookup for
  // the 12-option month dropdown - deliberate, documented in
  // docs/specs/demographics-module-spec.md, not an accidental cross-module
  // dependency.
  // ==========================================================================

  async function getFiscalYears() {
    try {
      const response = await fetch(`${API_BASE}/fiscal-years`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });
      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  async function getFiscalMonthsForYear(fiscalYearId) {
    try {
      const typesResponse = await fetch(`${API_BASE}/budget-types`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });
      const types = await handleResponse(typesResponse);

      if (!types.success) {
        return types;
      }

      const monthlyType = (types.data || []).find((t) => t.slug === "monthly");

      if (!monthlyType) {
        return { success: false, message: "No monthly budget type configured" };
      }

      const periodsResponse = await fetch(
        `${API_BASE}/budget-periods?fiscal_year_id=${fiscalYearId}&budget_type_id=${monthlyType.id}`,
        { method: Constants.HTTP_METHODS.GET, headers: getHeaders() },
      );
      const periods = await handleResponse(periodsResponse);

      if (!periods.success) {
        return periods;
      }

      const months = (periods.data || [])
        .map((p) => p.fiscal_month)
        .filter(Boolean)
        .reduce((unique, m) => {
          if (!unique.some((u) => u.id === m.id)) unique.push(m);
          return unique;
        }, [])
        .sort((a, b) => a.number - b.number);

      return { success: true, data: months };
    } catch (error) {
      return handleError(error);
    }
  }

  // ==========================================================================
  // PUBLIC API
  // ==========================================================================

  window.DemographicsAPIHandler = {
    getDemographics,
    getDemographic,
    createDemographic,
    updateDemographic,
    submitDemographic,
    approveDemographic,
    flagDemographic,
    requestChangesDemographic,
    getSummary,
    getEntryMode,
    updateEntryMode,
    getAttendance,
    createAttendance,
    updateAttendance,
    getFiscalYears,
    getFiscalMonthsForYear,
  };
})();
