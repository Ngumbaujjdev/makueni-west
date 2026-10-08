/**
 * ============================================================================
 * REALTIME - Chat's live connection (Laravel Reverb, through Laravel Echo)
 * ============================================================================
 * Connects once with the signed-in token: chat channels (new lines, reads,
 * typing), the person's own channel (their list changed) and the "online"
 * presence channel (green dots). If the live server isn't there, `live`
 * stays false and the page checks every few seconds instead - Chat works
 * either way. Echo and pusher-js come from the CDN (no build step here).
 *
 *   await Realtime.start()                 -> true when live
 *   Realtime.chat(id, {message, read, typing}) / Realtime.leaveChat(id)
 *   Realtime.whisperTyping(id, {id, name})
 *   Realtime.me(onChatChanged)             Realtime.online(onChange) / Realtime.isOnline(userId)
 * ============================================================================
 */
const Realtime = (function () {
  "use strict";

  let echo = null;
  let live = false;
  let started = null;
  const onlineIds = new Set();
  const onlineListeners = [];
  const statusListeners = [];

  const token = () => localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
  const myId = () => {
    try {
      return JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.USER_DATA) || "null")?.id || null;
    } catch (e) {
      return null;
    }
  };

  function setLive(v) {
    if (live === v) return;
    live = v;
    statusListeners.forEach((fn) => fn(live));
  }

  /** Connect (once). Resolves true when the live connection is up within a few seconds. */
  function start() {
    if (started) return started;
    started = (async () => {
      if (typeof Echo === "undefined" || typeof Pusher === "undefined") return false;
      let cfg;
      try {
        const res = await fetch(`${AppConfig.API_BASE_URL}/chat/realtime`, { headers: { Accept: "application/json", Authorization: `Bearer ${token()}` } });
        cfg = (await res.json())?.data;
      } catch (e) {
        return false;
      }
      if (!cfg?.live) return false;
      const secure = cfg.scheme === "https";
      try {
        echo = new Echo({
          broadcaster: "reverb",
          key: cfg.key,
          wsHost: cfg.host,
          wsPort: cfg.port,
          wssPort: cfg.port,
          forceTLS: secure,
          enabledTransports: ["ws", "wss"],
          authEndpoint: `${AppConfig.API_BASE_URL}/broadcasting/auth`,
          auth: { headers: { Accept: "application/json", Authorization: `Bearer ${token()}` } },
        });
      } catch (e) {
        return false;
      }
      const conn = echo.connector.pusher.connection;
      conn.bind("state_change", ({ current }) => setLive(current === "connected"));
      joinOnline();
      return new Promise((resolve) => {
        if (conn.state === "connected") return resolve(setLive(true) || true);
        const t = setTimeout(() => resolve(false), 4000);
        conn.bind("connected", () => {
          clearTimeout(t);
          setLive(true);
          resolve(true);
        });
      });
    })();
    return started;
  }

  function joinOnline() {
    const tell = () => onlineListeners.forEach((fn) => fn(onlineIds));
    echo
      .join("online")
      .here((users) => {
        onlineIds.clear();
        users.forEach((u) => onlineIds.add(u.id));
        tell();
      })
      .joining((u) => {
        onlineIds.add(u.id);
        tell();
      })
      .leaving((u) => {
        onlineIds.delete(u.id);
        tell();
      });
  }

  /** A chat's live events: message(line), read({user_id, message_id}), typing({id, name}). */
  function chat(id, handlers = {}) {
    if (!echo) return;
    const ch = echo.private(`chat.${id}`);
    if (handlers.message) ch.listen(".message", (e) => handlers.message(e.message));
    if (handlers.read) ch.listen(".read", (e) => handlers.read(e));
    if (handlers.typing) ch.listenForWhisper("typing", (e) => e.id !== myId() && handlers.typing(e));
  }

  function leaveChat(id) {
    if (echo) echo.leave(`chat.${id}`);
  }

  function whisperTyping(id, who) {
    if (echo) echo.private(`chat.${id}`).whisper("typing", who);
  }

  /** My own channel: a chat of mine changed (new line, new group, added or removed). */
  function me(onChatChanged) {
    if (!echo || !myId()) return;
    echo.private(`user.${myId()}`).listen(".chat", (e) => onChatChanged(e.chat_id));
  }

  return {
    start,
    chat,
    leaveChat,
    whisperTyping,
    me,
    online: (fn) => onlineListeners.push(fn),
    isOnline: (id) => onlineIds.has(Number(id)),
    onStatus: (fn) => statusListeners.push(fn),
    get live() {
      return live;
    },
  };
})();

window.Realtime = Realtime;
