/**
 * ============================================================================
 * BUDGETS - API (every budget page, every level)
 * ============================================================================
 * One fetch helper for the budget pages (docs/specs/budgets-spec.md). It
 * always sends the role the user is acting in (X-Assignment-Id), so someone
 * with a church role and a region role works as the one they switched to.
 *
 * Every call resolves to { ok, status, message, errors, data, body } -
 * never throws - so pages can show the API's plain message.
 * ============================================================================
 */
const BudgetsAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;

  function headers() {
    const h = {
      "Content-Type": "application/json",
      Accept: "application/json",
      Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}`,
    };
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* no role cached - the API uses the primary one */
    }
    return h;
  }

  async function request(method, path, body) {
    try {
      const res = await fetch(`${BASE}${path}`, { method, headers: headers(), body: body === undefined ? undefined : JSON.stringify(body) });
      const json = await res.json().catch(() => ({}));
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return {
        ok,
        status: res.status,
        message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.",
        errors: json.errors || null,
        data: json.data,
        body: json,
      };
    } catch (e) {
      return { ok: false, status: 0, message: "Can't reach the server. Check your connection and try again.", errors: null, data: null, body: null };
    }
  }

  const qs = (params) => {
    const p = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v !== null && v !== undefined && v !== "") p.set(k, v);
    });
    const s = p.toString();
    return s ? `?${s}` : "";
  };

  return {
    list: (params) => request("GET", `/budgets${qs(params)}`),
    form: (params) => request("GET", `/budgets/form${qs(params)}`),
    formFor: (id) => request("GET", `/budgets/${id}/form`),
    get: (id) => request("GET", `/budgets/${id}`),
    history: (id) => request("GET", `/budgets/${id}/history`),
    create: (body) => request("POST", "/budgets", body),
    update: (id, body) => request("PUT", `/budgets/${id}`, body),
    remove: (id) => request("DELETE", `/budgets/${id}`),
    start: (id) => request("POST", `/budgets/${id}/start`),
    close: (id) => request("POST", `/budgets/${id}/close`),
    reopen: (id) => request("POST", `/budgets/${id}/reopen`),
    dashboard: (params) => request("GET", `/budgets/dashboard${qs(params)}`),
    entries: (params) => request("GET", `/budget-entries${qs(params)}`),
    record: (body) => request("POST", "/budget-entries", body),
    changeEntry: (id, body) => request("PUT", `/budget-entries/${id}`, body),
    removeEntry: (id) => request("DELETE", `/budget-entries/${id}`),
    restoreEntry: (id) => request("POST", `/budget-entries/${id}/restore`),
    entry: (id) => request("GET", `/budget-entries/${id}`),
    settings: (params) => request("GET", `/budget-settings${qs(params)}`),
    addLine: (body) => request("POST", "/budget-settings/lines", body),
    changeLine: (id, body) => request("PUT", `/budget-settings/lines/${id}`, body),
    removeLine: (id) => request("DELETE", `/budget-settings/lines/${id}`),
    line: (budgetId, lineId) => request("GET", `/budgets/${budgetId}/lines/${lineId}`),
  };
})();

window.BudgetsAPI = BudgetsAPI;
