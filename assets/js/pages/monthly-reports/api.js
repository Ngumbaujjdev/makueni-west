/**
 * ============================================================================
 * MONTHLY REPORTS - API (church, region and diocese pages)
 * ============================================================================
 * One fetch helper for Monthly reports (docs/specs/monthly-reports-spec.md).
 * It always sends the role the user is acting in (X-Assignment-Id), and
 * every call resolves to { ok, status, message, errors, data } - it never
 * throws.
 * ============================================================================
 */
const ReportsAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;
  const territoryId = new URLSearchParams(window.location.search).get("territory_id");

  function headers(json = true) {
    const h = { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
    if (json) h["Content-Type"] = "application/json";
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* no role cached - the API uses the primary one */
    }
    return h;
  }

  function url(path, params = null) {
    const q = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => v !== undefined && v !== null && v !== "" && q.set(k, v));
    if (territoryId) q.set("territory_id", territoryId);
    const qs = q.toString();
    return `${BASE}${path}${qs ? `?${qs}` : ""}`;
  }

  const TIMEOUT_MS = 20000;

  async function request(method, path, { params, body, form } = {}) {
    // Never wait forever: a call that hangs becomes an error the page can show (with Try again).
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    try {
      const res = await fetch(url(path, params), { method, headers: headers(!form), body: form || (body === undefined ? undefined : JSON.stringify(body)), signal: ctrl.signal });
      const json = await res.json().catch(() => null);
      if (json === null) return { ok: false, status: res.status, message: "The server sent an unexpected reply. Please try again.", errors: null, data: null };
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return { ok, status: res.status, message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.", errors: json.errors || null, data: json.data };
    } catch (e) {
      const message = e.name === "AbortError" ? "The server took too long to answer. Please try again." : "Can't reach the server. Check your connection and try again.";
      return { ok: false, status: 0, message, errors: null, data: null };
    } finally {
      clearTimeout(timer);
    }
  }

  /** A protected file as a local object URL (it needs the sign-in header), or null. */
  async function fileUrl(path) {
    try {
      const res = await fetch(url(path), { headers: { ...headers(false), Accept: "*/*" } });
      return res.ok ? URL.createObjectURL(await res.blob()) : null;
    } catch (e) {
      return null;
    }
  }

  return {
    year: (year) => request("GET", "/monthly-reports", { params: { year } }),
    month: (year, month) => request("GET", `/monthly-reports/${year}/${month}`),
    save: (year, month, body) => request("PUT", `/monthly-reports/${year}/${month}`, { body }),
    send: (year, month) => request("POST", `/monthly-reports/${year}/${month}/send`),
    reopen: (year, month) => request("POST", `/monthly-reports/${year}/${month}/reopen`),
    get: (id) => request("GET", `/monthly-reports/${id}`),
    seen: (id) => request("POST", `/monthly-reports/${id}/seen`),
    comment: (id, body) => request("POST", `/monthly-reports/${id}/comments`, { body: { body } }),
    attach: (id, file) => {
      const form = new FormData();
      form.append("file", file);
      return request("POST", `/monthly-reports/${id}/attachments`, { form });
    },
    detach: (id, media) => request("DELETE", `/monthly-reports/${id}/attachments/${media}`),
    fileUrl: (id, media) => fileUrl(`/monthly-reports/${id}/attachments/${media}`),
    below: (year, month) => request("GET", "/monthly-reports/below", { params: { year, month } }),
  };
})();
