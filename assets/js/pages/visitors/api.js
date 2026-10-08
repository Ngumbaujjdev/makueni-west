/**
 * ============================================================================
 * VISITORS - API (visitors and their follow-up; totals for region/diocese)
 * ============================================================================
 * One fetch helper for Visitors (docs/specs/people-and-care-spec.md, P2), the
 * same shape as MembersAPI: it sends the acting role (X-Assignment-Id) and
 * every call resolves to { ok, status, message, errors, data, raw }.
 * ============================================================================
 */
const VisitorsAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;
  const territoryId = new URLSearchParams(window.location.search).get("territory_id");
  const TIMEOUT_MS = 20000;

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
    Object.entries(params || {}).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach((x) => q.append(`${k}[]`, x));
      else if (v !== undefined && v !== null && v !== "") q.set(k, v);
    });
    if (territoryId) q.set("territory_id", territoryId);
    const qs = q.toString();
    return `${BASE}${path}${qs ? `?${qs}` : ""}`;
  }

  async function request(method, path, { params, body, form } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    try {
      const res = await fetch(url(path, params), { method, headers: headers(!form), body: form || (body === undefined ? undefined : JSON.stringify(body)), signal: ctrl.signal });
      const json = await res.json().catch(() => null);
      if (json === null) return { ok: false, status: res.status, message: "The server sent an unexpected reply. Please try again.", errors: null, data: null, raw: null };
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return { ok, status: res.status, message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.", errors: json.errors || null, data: json.data, raw: json };
    } catch (e) {
      const message = e.name === "AbortError" ? "The server took too long to answer. Please try again." : "Can't reach the server. Check your connection and try again.";
      return { ok: false, status: 0, message, errors: null, data: null, raw: null };
    } finally {
      clearTimeout(timer);
    }
  }

  return {
    options: () => request("GET", "/visitors/options"),
    overview: () => request("GET", "/visitors/overview"),
    list: (params) => request("GET", "/visitors", { params }),
    check: (phone) => request("GET", "/visitors/check", { params: { phone } }),
    batch: (body) => request("POST", "/visitors/batch", { body }),
    get: (id) => request("GET", `/visitors/${id}`),
    update: (id, body) => request("PUT", `/visitors/${id}`, { body }),
    history: (id) => request("GET", `/visitors/${id}/history`),
    stage: (id, stage) => request("POST", `/visitors/${id}/stage`, { body: { stage } }),
    assign: (id, userId) => request("POST", `/visitors/${id}/assign`, { body: { user_id: userId || null } }),
    visit: (id, body) => request("POST", `/visitors/${id}/visits`, { body }),
    followup: (id, body) => request("POST", `/visitors/${id}/followups`, { body }),
    sms: (id, text) => request("POST", `/visitors/${id}/sms`, { body: { text } }),
    becomeMember: (id, body = {}) => request("POST", `/visitors/${id}/become-member`, { body }),
    bulk: (body) => request("POST", "/visitors/bulk", { body }),
    archive: (id) => request("POST", `/visitors/${id}/archive`),
    restore: (id) => request("POST", `/visitors/${id}/restore`),
    anonymise: (id) => request("POST", `/visitors/${id}/anonymise`, { body: { confirm: "REMOVE" } }),
    insights: (year) => request("GET", "/visitors/insights", { params: { year } }),
    totals: () => request("GET", "/visitors/totals"),
  };
})();

window.VisitorsAPI = VisitorsAPI;
