/**
 * ============================================================================
 * PASTORAL CARE - API (P3; totals for region/diocese)
 * ============================================================================
 * The same shape as MembersAPI: it sends the acting role (X-Assignment-Id)
 * and every call resolves to { ok, status, message, errors, data, raw }.
 * Loaded on the Pastoral care pages and on a member's or visitor's page.
 * ============================================================================
 */
const CareAPI = (function () {
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
    options: () => request("GET", "/care/options"),
    overview: () => request("GET", "/care/overview"),
    list: (params) => request("GET", "/care", { params }),
    get: (id) => request("GET", `/care/${id}`),
    create: (body) => request("POST", "/care", { body }),
    update: (id, body) => request("PUT", `/care/${id}`, { body }),
    history: (id) => request("GET", `/care/${id}/history`),
    contact: (id, body) => request("POST", `/care/${id}/contacts`, { body }),
    close: (id, body) => request("POST", `/care/${id}/close`, { body }),
    discharge: (id, body = {}) => request("POST", `/care/${id}/discharge`, { body }),
    bulk: (body) => request("POST", "/care/bulk", { body }),
    hospital: () => request("GET", "/care/hospital"),
    prayer: () => request("GET", "/care/prayer"),
    forPerson: (id) => request("GET", `/people/${id}/care`),
    search: (q) => request("GET", "/people/search", { params: { q } }),
    totals: () => request("GET", "/care/totals"),
  };
})();

window.CareAPI = CareAPI;
