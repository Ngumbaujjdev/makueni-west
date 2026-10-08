/**
 * ============================================================================
 * MEMBERS - API (the church's private register; totals for region/diocese)
 * ============================================================================
 * One fetch helper for Members (docs/specs/people-and-care-spec.md, P1). It
 * always sends the role the user is acting in (X-Assignment-Id), and every
 * call resolves to { ok, status, message, errors, data, raw } - it never
 * throws. Photos come through the API (they need the sign-in), as blob URLs.
 * ============================================================================
 */
const MembersAPI = (function () {
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

  const photoCache = new Map();

  /** A member's photo as a blob URL (or null) - fetched once per page. */
  async function photoUrl(id) {
    if (photoCache.has(id)) return photoCache.get(id);
    const p = (async () => {
      try {
        const res = await fetch(url(`/people/${id}/photo`), { headers: headers(false) });
        return res.ok ? URL.createObjectURL(await res.blob()) : null;
      } catch (e) {
        return null;
      }
    })();
    photoCache.set(id, p);
    return p;
  }

  return {
    overview: () => request("GET", "/people/overview"),
    list: (params) => request("GET", "/people", { params }),
    check: (params) => request("GET", "/people/check", { params }),
    get: (id) => request("GET", `/people/${id}`),
    create: (body) => request("POST", "/people", { body }),
    update: (id, body) => request("PUT", `/people/${id}`, { body }),
    history: (id) => request("GET", `/people/${id}/history`),
    archive: (id) => request("POST", `/people/${id}/archive`),
    restore: (id) => request("POST", `/people/${id}/restore`),
    anonymise: (id) => request("POST", `/people/${id}/anonymise`, { body: { confirm: "REMOVE" } }),
    uploadPhoto: (id, file) => {
      const form = new FormData();
      form.append("photo", file);
      photoCache.delete(id);
      return request("POST", `/people/${id}/photo`, { form });
    },
    removePhoto: (id) => {
      photoCache.delete(id);
      return request("DELETE", `/people/${id}/photo`);
    },
    photoUrl,
    transfers: (params) => request("GET", "/people/transfers", { params }),
    transferOut: (id, body) => request("POST", `/people/${id}/transfer-out`, { body }),
    transferIn: (body) => request("POST", "/people/transfer-in", { body }),
    insights: () => request("GET", "/people/insights"),
    totals: () => request("GET", "/people/totals"),
  };
})();

window.MembersAPI = MembersAPI;
