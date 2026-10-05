/**
 * ============================================================================
 * MESSAGES - API (church, region and diocese pages)
 * ============================================================================
 * One fetch helper for Messages (docs/specs/messages-spec.md).
 * It always sends the role the user is acting in (X-Assignment-Id), and
 * every call resolves to { ok, status, message, errors, data } - it never
 * throws.
 * ============================================================================
 */
const MessagesAPI = (function () {
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

  async function request(method, path, { params, body, form } = {}) {
    try {
      const res = await fetch(url(path, params), { method, headers: headers(!form), body: form || (body === undefined ? undefined : JSON.stringify(body)) });
      const json = await res.json().catch(() => ({}));
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return { ok, status: res.status, message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.", errors: json.errors || null, data: json.data };
    } catch (e) {
      return { ok: false, status: 0, message: "Can't reach the server. Check your connection and try again.", errors: null, data: null };
    }
  }

  return {
    options: () => request("GET", "/messages/options"),
    preview: (body) => request("POST", "/messages/preview", { body }),
    send: (body) => request("POST", "/messages", { body }),
    sent: (year) => request("GET", "/messages/sent", { params: { year } }),
    get: (id) => request("GET", `/messages/${id}`),
    cancel: (id) => request("POST", `/messages/${id}/cancel`),
    retry: (id) => request("POST", `/messages/${id}/retry`),
    inbox: (from) => request("GET", "/messages/inbox", { params: { from } }),
    read: (rid) => request("POST", `/messages/inbox/${rid}/read`),
    reply: (rid, body) => request("POST", `/messages/inbox/${rid}/reply`, { body: { body } }),
    templates: () => request("GET", "/messages/templates"),
    saveTemplate: (id, body) => (id ? request("PUT", `/messages/templates/${id}`, { body }) : request("POST", "/messages/templates", { body })),
    deleteTemplate: (id) => request("DELETE", `/messages/templates/${id}`),
  };
})();
