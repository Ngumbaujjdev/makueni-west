/**
 * ============================================================================
 * MINISTRIES - API (P4; totals for region/diocese)
 * ============================================================================
 * The same shape as MembersAPI: it sends the acting role (X-Assignment-Id)
 * and every call resolves to { ok, status, message, errors, data, raw }.
 * Loaded on the Ministries pages, the members list (Add to ministry) and a
 * member's page.
 * ============================================================================
 */
const MinistriesAPI = (function () {
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
    options: () => request("GET", "/ministries/options"),
    overview: (all = false) => request("GET", "/ministries/overview", { params: { all: all ? 1 : "" } }),
    get: (id) => request("GET", `/ministries/${id}`),
    create: (body) => request("POST", "/ministries", { body }),
    update: (id, body) => request("PUT", `/ministries/${id}`, { body }),
    remove: (id) => request("DELETE", `/ministries/${id}`),
    leaders: (id, leaders) => request("PUT", `/ministries/${id}/leaders`, { body: { leaders } }),
    members: (id) => request("GET", `/ministries/${id}/members`),
    candidates: (id) => request("GET", `/ministries/${id}/candidates`),
    addMembers: (id, personIds) => request("POST", `/ministries/${id}/members`, { body: { person_ids: personIds } }),
    removeMembers: (id, personIds) => request("POST", `/ministries/${id}/members/remove`, { body: { person_ids: personIds } }),
    gatherings: (id) => request("GET", `/ministries/${id}/gatherings`),
    activities: (id) => request("GET", `/ministries/${id}/activities`),
    history: (id) => request("GET", `/ministries/${id}/history`),
    insights: () => request("GET", "/ministries/insights"),
    totals: () => request("GET", "/ministries/totals"),
  };
})();

window.MinistriesAPI = MinistriesAPI;
