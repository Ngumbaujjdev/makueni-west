/**
 * ============================================================================
 * EVENTS - API (church, region and diocese events pages)
 * ============================================================================
 * One fetch helper for Events (docs/specs/events-initiatives-spec.md). It
 * always sends the role the user is acting in (X-Assignment-Id), and every
 * call resolves to { ok, status, message, errors, data } - it never throws.
 * ============================================================================
 */
const EventsAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;
  const territoryId = new URLSearchParams(window.location.search).get("territory_id");

  function headers() {
    const h = { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
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

  async function request(method, path, { params, body } = {}) {
    try {
      const res = await fetch(url(path, params), { method, headers: headers(), body: body === undefined ? undefined : JSON.stringify(body) });
      const json = await res.json().catch(() => ({}));
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return { ok, status: res.status, message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.", errors: json.errors || null, data: json.data };
    } catch (e) {
      return { ok: false, status: 0, message: "Can't reach the server. Check your connection and try again.", errors: null, data: null };
    }
  }

  return {
    list: (params) => request("GET", "/activities", { params: { kind: "event", ...params } }),
    overview: (year) => request("GET", "/activities/overview", { params: { kind: "event", year } }),
    get: (id) => request("GET", `/activities/${id}`),
    create: (body) => request("POST", "/activities", { body: { kind: "event", ...body } }),
    update: (id, body) => request("PUT", `/activities/${id}`, { body }),
    publish: (id) => request("POST", `/activities/${id}/publish`),
    complete: (id, reportBack) => request("POST", `/activities/${id}/complete`, { body: { report_back: reportBack } }),
    cancel: (id) => request("POST", `/activities/${id}/cancel`),
    registrations: (id) => request("GET", `/activities/${id}/registrations`),
    register: (id, body) => request("POST", `/activities/${id}/register`, { body }),
    updateRegistration: (id, body) => request("PUT", `/registrations/${id}`, { body }),
    withdraw: (id) => request("POST", `/registrations/${id}/withdraw`),
    money: (id) => request("GET", `/activities/${id}/money`),
    history: (id) => request("GET", `/activities/${id}/history`),
  };
})();
