/**
 * ============================================================================
 * STAFF - API (docs/specs/hr-spec.md)
 * ============================================================================
 * The same shape as AccountingAPI: the acting role (X-Assignment-Id), the
 * place below when the page was opened for one (?territory_id=); every call
 * resolves to { ok, status, message, errors, data, raw }.
 * ============================================================================
 */
const HrAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;
  const territoryId = () => new URLSearchParams(window.location.search).get("territory_id");
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
      if (v !== undefined && v !== null && v !== "") q.set(k, v);
    });
    if (territoryId() && !q.has("territory_id")) q.set("territory_id", territoryId());
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

  function upload(path, file, name) {
    const form = new FormData();
    form.append("file", file);
    if (name) form.append("name", name);
    return request("POST", path, { form });
  }

  /** A paper opened in a new tab (the API needs the token, so it comes as a blob). */
  async function openDocument(staffId, mediaId) {
    const res = await fetch(url(`/hr/staff/${staffId}/documents/${mediaId}`), { headers: headers(false) });
    if (!res.ok) return Toast.error("That paper couldn't be opened.");
    window.open(URL.createObjectURL(await res.blob()), "_blank");
  }

  return {
    overview: () => request("GET", "/hr/overview"),
    options: () => request("GET", "/hr/options"),
    people: (q, churchId) => request("GET", "/hr/people", { params: { q, church_id: churchId } }),
    logins: (q) => request("GET", "/hr/logins", { params: { q } }),
    staff: (params) => request("GET", "/hr/staff", { params }),
    person: (id) => request("GET", `/hr/staff/${id}`),
    save: (id, body) => request(id ? "PUT" : "POST", id ? `/hr/staff/${id}` : "/hr/staff", { body }),
    transfer: (id, body) => request("POST", `/hr/staff/${id}/transfer`, { body }),
    end: (id, body) => request("POST", `/hr/staff/${id}/end`, { body }),
    remove: (id) => request("DELETE", `/hr/staff/${id}`),
    addDocument: (id, file, name) => upload(`/hr/staff/${id}/documents`, file, name),
    removeDocument: (id, media) => request("DELETE", `/hr/staff/${id}/documents/${media}`),
    openDocument,
    setup: () => request("GET", "/hr/setup"),
    saveItem: (kind, id, body) => request(id ? "PUT" : "POST", id ? `/hr/setup/${kind}/${id}` : `/hr/setup/${kind}`, { body }),
    removeItem: (kind, id) => request("DELETE", `/hr/setup/${kind}/${id}`),
    here: (kind, id, on) => request("POST", `/hr/setup/${kind}/${id}/here`, { body: { on } }),
    item: (kind, id) => request("GET", `/hr/setup/${kind}/${id}`),
    setOurs: (kind, id, body) => request("PUT", `/hr/setup/${kind}/${id}/ours`, { body }),
    clearOurs: (kind, id) => request("DELETE", `/hr/setup/${kind}/${id}/ours`),
  };
})();

window.HrAPI = HrAPI;
