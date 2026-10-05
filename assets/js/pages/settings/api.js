/**
 * ============================================================================
 * SETTINGS - API (the Settings hub, every level)
 * ============================================================================
 * One fetch helper for the Settings hub (docs/specs/settings-spec.md). Like
 * BudgetsAPI it always sends the role the user is acting in
 * (X-Assignment-Id), and every call resolves to
 * { ok, status, message, errors, data, body } - it never throws.
 *
 * A global admin can open another place's settings with ?territory_id= on
 * the page URL; every call then carries it.
 * ============================================================================
 */
const SettingsAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;
  const territoryId = new URLSearchParams(window.location.search).get("territory_id");

  function headers(json = true) {
    const h = {
      Accept: "application/json",
      Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}`,
    };
    if (json) h["Content-Type"] = "application/json";
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* no role cached - the API uses the primary one */
    }
    return h;
  }

  function url(path) {
    if (!territoryId) return `${BASE}${path}`;
    return `${BASE}${path}${path.includes("?") ? "&" : "?"}territory_id=${encodeURIComponent(territoryId)}`;
  }

  async function request(method, path, body, isForm = false) {
    try {
      const res = await fetch(url(path), {
        method,
        headers: headers(!isForm),
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
      });
      const json = await res.json().catch(() => ({}));
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return {
        ok,
        status: res.status,
        message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.",
        errors: json.errors || null,
        data: json.data,
        body: json,
      };
    } catch (e) {
      return { ok: false, status: 0, message: "Can't reach the server. Check your connection and try again.", errors: null, data: null, body: null };
    }
  }

  function uploadLogo(file) {
    const form = new FormData();
    form.append("logo", file);
    return request("POST", "/settings/profile/logo", form, true);
  }

  return {
    territoryId,
    sections: () => request("GET", "/settings/sections"),
    overview: () => request("GET", "/settings/overview"),
    reference: () => request("GET", "/settings/reference"),
    section: (key) => request("GET", `/settings/sections/${encodeURIComponent(key)}`),
    saveSection: (key, body) => request("PUT", `/settings/sections/${encodeURIComponent(key)}`, body),
    profile: () => request("GET", "/settings/profile"),
    saveProfile: (body) => request("PUT", "/settings/profile", body),
    uploadLogo,
    removeLogo: () => request("DELETE", "/settings/profile/logo"),
    serviceTimes: () => request("GET", "/settings/service-times"),
    saveServiceTimes: (times) => request("PUT", "/settings/service-times", { times }),
    team: () => request("GET", "/settings/team"),
    addPerson: (body) => request("POST", "/settings/team", body),
    teamCheck: (phone, email) => request("GET", `/settings/team/check?${new URLSearchParams({ phone, email })}`),
    changeRole: (id, roleId) => request("PUT", `/settings/team/${id}`, { role_id: roleId }),
    removePerson: (id) => request("DELETE", `/settings/team/${id}`),
    resetAccess: (id, send = []) => request("POST", `/settings/team/${id}/reset-access`, { send }),
    health: (quick = false) => request("GET", `/settings/health${quick ? "?quick=1" : ""}`),
    testSend: (channel, to) => request("POST", `/settings/test/${channel === "sms" ? "sms" : "email"}`, { to }),
    retryFailed: () => request("POST", "/settings/maintenance/retry-failed"),
    placeTest: (channel, to) => request("POST", "/settings/communication/test", { channel, to }),
    messages: () => request("GET", "/settings/messages"),
    message: (id) => request("GET", `/settings/messages/${id}`),
    resendMessage: (id) => request("POST", `/settings/messages/${id}/resend`),
    maintenance: (tool) => request("POST", `/settings/maintenance/${encodeURIComponent(tool)}`),
    audit: () => request("GET", "/settings/audit"),
    access: () => request("GET", "/settings/access"),
  };
})();

window.SettingsAPI = SettingsAPI;
