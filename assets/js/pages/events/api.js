/**
 * ============================================================================
 * EVENTS AND INITIATIVES - API (church, region and diocese pages)
 * ============================================================================
 * One fetch helper for Events (docs/specs/events-initiatives-spec.md). It
 * always sends the role the user is acting in (X-Assignment-Id), and every
 * call resolves to { ok, status, message, errors, data } - it never throws.
 * ============================================================================
 */
const EventsAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;
  const KIND = window.EVENTS_CTX?.kind || "event";
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

  const TIMEOUT_MS = 20000;

  async function request(method, path, { params, body } = {}) {
    // Never wait forever: a call that hangs becomes an error the page can show (with Try again).
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    try {
      const res = await fetch(url(path, params), { method, headers: headers(), body: body === undefined ? undefined : JSON.stringify(body), signal: ctrl.signal });
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

  return {
    list: (params) => request("GET", "/activities", { params: { kind: KIND, ...params } }),
    overview: (year) => request("GET", "/activities/overview", { params: { kind: KIND, year } }),
    get: (id) => request("GET", `/activities/${id}`),
    // Facilities (P5): our rooms, for the Book a room select.
    rooms: () => request("GET", "/facilities/options"),
    create: (body) => request("POST", "/activities", { body: { kind: KIND, ...body } }),
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
    sessions: (id) => request("GET", `/activities/${id}/sessions`),
    addSession: (id, body) => request("POST", `/activities/${id}/sessions`, { body }),
    updateSession: (id, body) => request("PUT", `/sessions/${id}`, { body }),
    removeSession: (id) => request("DELETE", `/sessions/${id}`),
  };
})();
