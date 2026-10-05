/**
 * ============================================================================
 * CALENDAR - API (church, region and diocese calendars)
 * ============================================================================
 * One fetch helper for the Calendar (docs/specs/calendar-spec.md). Like
 * SettingsAPI it always sends the role the user is acting in
 * (X-Assignment-Id), and every call resolves to
 * { ok, status, message, errors, data } - it never throws.
 * ============================================================================
 */
const CalendarAPI = (function () {
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
    Object.entries(params || {}).forEach(([k, v]) => (Array.isArray(v) ? v.forEach((x) => q.append(`${k}[]`, x)) : v !== undefined && v !== null && q.set(k, v)));
    if (territoryId) q.set("territory_id", territoryId);
    const qs = q.toString();
    return `${BASE}${path}${qs ? `?${qs}` : ""}`;
  }

  async function request(method, path, { params, body, form } = {}) {
    try {
      const res = await fetch(url(path, params), { method, headers: headers(!form), body: form || (body === undefined ? undefined : JSON.stringify(body)) });
      if (res.status === 204) return { ok: true, status: 204, message: "Done.", errors: null, data: null };
      const json = await res.json().catch(() => ({}));
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return { ok, status: res.status, message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.", errors: json.errors || null, data: json.data };
    } catch (e) {
      return { ok: false, status: 0, message: "Can't reach the server. Check your connection and try again.", errors: null, data: null };
    }
  }

  /** Download a file the API sends (it needs the sign-in header, so no plain link). */
  async function download(path, filename) {
    try {
      const res = await fetch(url(path), { headers: headers(false) });
      if (!res.ok) return false;
      const blob = await res.blob();
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => (URL.revokeObjectURL(a.href), a.remove()), 1000);
      return true;
    } catch (e) {
      return false;
    }
  }

  return {
    events: (params) => request("GET", "/calendar/events", { params }),
    overview: () => request("GET", "/calendar/overview"),
    create: (body) => request("POST", "/calendar/events", { body }),
    update: (id, body) => request("PUT", `/calendar/events/${id}`, { body }),
    remove: (id) => request("DELETE", `/calendar/events/${id}`),
    cci: (year) => request("GET", "/calendar/cci", { params: { year } }),
    importCci: (file, commit) => {
      const form = new FormData();
      form.append("file", file);
      form.append("commit", commit ? "1" : "0");
      return request("POST", "/calendar/cci/import", { form });
    },
    template: () => download("/calendar/cci/template", `cci-calendar-template-${new Date().getFullYear()}.csv`),
  };
})();

window.CalendarAPI = CalendarAPI;
