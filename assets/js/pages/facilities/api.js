/**
 * ============================================================================
 * FACILITIES - API (P5)
 * ============================================================================
 * The same shape as MembersAPI: it sends the acting role (X-Assignment-Id)
 * and every call resolves to { ok, status, message, errors, data, raw } - a
 * clashing booking comes back 409 with data.clash.
 * ============================================================================
 */
const FacilitiesAPI = (function () {
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

  /** Files (photos, a receipt) as multipart - field: "photos[]" or "receipt". */
  function upload(path, field, files) {
    const form = new FormData();
    (Array.isArray(files) ? files : [files]).forEach((f) => form.append(field, f));
    return request("POST", path, { form });
  }

  /** A receipt through the API (receipts aren't public) - as a blob URL for <img> or a new tab. */
  async function fileUrl(path) {
    const h = headers(false);
    h.Accept = "*/*";
    try {
      const res = await fetch(url(path), { headers: h });
      return res.ok ? URL.createObjectURL(await res.blob()) : null;
    } catch (e) {
      return null;
    }
  }

  return {
    overview: () => request("GET", "/facilities/overview"),
    assets: () => request("GET", "/facilities/assets"),
    options: () => request("GET", "/facilities/options"),
    people: (q) => request("GET", "/facilities/people", { params: { q } }),
    saveRoom: (id, body) => request(id ? "PUT" : "POST", id ? `/rooms/${id}` : "/rooms", { body }),
    removeRoom: (id) => request("DELETE", `/rooms/${id}`),
    bookings: (params) => request("GET", "/bookings", { params }),
    check: (params) => request("GET", "/bookings/check", { params }),
    book: (body) => request("POST", "/bookings", { body }),
    changeBooking: (id, body) => request("PUT", `/bookings/${id}`, { body }),
    cancelBooking: (id) => request("DELETE", `/bookings/${id}`),
    equipment: () => request("GET", "/equipment"),
    item: (id) => request("GET", `/equipment/${id}`),
    saveItem: (id, body) => request(id ? "PUT" : "POST", id ? `/equipment/${id}` : "/equipment", { body }),
    removeItem: (id) => request("DELETE", `/equipment/${id}`),
    bulkItems: (body) => request("POST", "/equipment/bulk", { body }),
    lend: (id, body) => request("POST", `/equipment/${id}/loans`, { body }),
    ask: (id, body) => request("POST", `/equipment/${id}/loans`, { body: { ...body, ask: true } }),
    loans: (status = "out") => request("GET", "/loans", { params: { status } }),
    giveBack: (loanId) => request("POST", `/loans/${loanId}/return`),
    approve: (loanId) => request("POST", `/loans/${loanId}/approve`),
    decline: (loanId, reason) => request("POST", `/loans/${loanId}/decline`, { body: { reason } }),
    cancelAsk: (loanId) => request("DELETE", `/loans/${loanId}`),
    addPhotos: (id, files) => upload(`/equipment/${id}/photos`, "photos[]", files),
    removePhoto: (id, photoId) => request("DELETE", `/equipment/${id}/photos/${photoId}`),
    orderPhotos: (id, ids) => request("POST", `/equipment/${id}/photos/order`, { body: { ids } }),
    addReceipt: (id, file) => upload(`/equipment/${id}/receipts`, "receipt", file),
    removeReceipt: (id, mediaId) => request("DELETE", `/equipment/${id}/receipts/${mediaId}`),
    fileUrl,
    expenses: (q) => request("GET", "/equipment/expenses", { params: { q } }),
    linkItemExpense: (id, entryId) => request("POST", `/equipment/${id}/expense`, { body: { budget_entry_id: entryId } }),
    unlinkItemExpense: (id) => request("DELETE", `/equipment/${id}/expense`),
    repairs: () => request("GET", "/repairs"),
    report: (body) => request("POST", "/repairs", { body }),
    updateRepair: (id, body) => request("PUT", `/repairs/${id}`, { body }),
    linkExpense: (id, entryId) => request("POST", `/repairs/${id}/expense`, { body: { budget_entry_id: entryId } }),
    rota: (params) => request("GET", "/rota", { params }),
    saveRota: (body) => request("PUT", "/rota", { body }),
    copyRota: (body) => request("POST", "/rota/copy", { body }),
    fillRota: (body) => request("POST", "/rota/fill", { body }),
    teams: () => request("GET", "/facilities/teams"),
    personTeams: (personId) => request("GET", `/facilities/teams/person/${personId}`),
    addToTeam: (duty, body) => request("POST", `/facilities/teams/${encodeURIComponent(duty)}/members`, { body }),
    orderTeam: (duty, ids) => request("PUT", `/facilities/teams/${encodeURIComponent(duty)}/order`, { body: { ids } }),
    removeFromTeam: (id) => request("DELETE", `/facilities/teams/members/${id}`),
  };
})();

window.FacilitiesAPI = FacilitiesAPI;
