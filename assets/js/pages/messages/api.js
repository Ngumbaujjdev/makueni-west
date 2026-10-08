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

  const TIMEOUT_MS = 20000;

  async function request(method, path, { params, body, form } = {}) {
    // Never wait forever: a call that hangs becomes an error the page can show (with Try again).
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    try {
      const res = await fetch(url(path, params), { method, headers: headers(!form), body: form || (body === undefined ? undefined : JSON.stringify(body)), signal: ctrl.signal });
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

  /**
   * Upcoming published events to write about: ours and those shared with us,
   * this year and next (the activities list is by year). Resolves to a list,
   * or null when the role can't see events.
   */
  async function events() {
    const y = new Date().getFullYear();
    const calls = [y, y + 1].flatMap((year) => ["own", "invited"].map((scope) => request("GET", "/activities", { params: { kind: "event", year, scope } })));
    const res = await Promise.all(calls);
    if (res.every((r) => !r.ok)) return null;
    const now = Date.now();
    const seen = new Set();
    return res
      .filter((r) => r.ok)
      .flatMap((r) => r.data || [])
      .filter((e) => e.status === "published" && new Date(e.ends_at || e.starts_at).getTime() > now && !seen.has(e.id) && seen.add(e.id))
      .sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at));
  }

  return {
    /** Any Messages call - for code shared with Settings > Communication (comms-env.js). */
    call: (method, path, body) => request(method, path, body === undefined ? {} : { body }),
    events,
    /** The real email (and signed SMS) for this text - Settings > Communication's template preview. */
    emailPreview: (subject, body) => request("POST", "/messages/templates/preview", { body: { subject, body } }),
    options: () => request("GET", "/messages/options"),
    /** One person from the church's register, to message them (People & care). */
    people: (q) => request("GET", "/people/search", { params: { q } }),
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
    // Chat (L6): contacts, one-to-one chats and groups.
    contacts: (q = "") => request("GET", "/chat/contacts", { params: q ? { q } : null }),
    chats: () => request("GET", "/chat/chats"),
    chat: (id) => request("GET", `/chat/chats/${id}`),
    chatMessages: (id, before) => request("GET", `/chat/chats/${id}/messages`, { params: before ? { before } : null }),
    chatSend: (id, body) => request("POST", `/chat/chats/${id}/messages`, { body: { body } }),
    chatRead: (id, messageId) => request("POST", `/chat/chats/${id}/read`, { body: { message_id: messageId } }),
    direct: (userId) => request("POST", "/chat/direct", { body: { user_id: userId } }),
    createGroup: (name, memberIds) => request("POST", "/chat/groups", { body: { name, member_ids: memberIds } }),
    renameGroup: (id, name) => request("PATCH", `/chat/groups/${id}`, { body: { name } }),
    groupPhoto: (id, file) => {
      const form = new FormData();
      form.append("photo", file);
      return request("POST", `/chat/groups/${id}/photo`, { form });
    },
    addMembers: (id, userIds) => request("POST", `/chat/groups/${id}/members`, { body: { user_ids: userIds } }),
    removeMember: (id, userId) => request("DELETE", `/chat/groups/${id}/members/${userId}`),
  };
})();
