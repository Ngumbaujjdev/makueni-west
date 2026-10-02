/**
 * SETTINGS - System health (diocese, global admins): whether email, SMS,
 * background jobs, the scheduler and storage are working
 * (GET /settings/health). Each tile says what to do when it needs checking.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;

  let root = null;

  const ACTIONS = {
    "test-email": { label: "Send a test email", icon: "ri-mail-send-line", go: "email" },
    "test-sms": { label: "Send a test SMS", icon: "ri-message-3-line", go: "sms" },
    "retry-failed": { label: "Retry failed jobs", icon: "ri-restart-line" },
  };

  function tile(t) {
    const ok = t.status === "ok";
    const actions = (t.actions || [])
      .map((a) => ACTIONS[a])
      .filter(Boolean)
      .map((a, i) => `<button type="button" class="btn btn-sm ${a.go ? "btn-outline-primary" : "btn-primary"}" data-action="${esc(t.actions[i])}"${a.go ? ` data-go="${a.go}"` : ""}><i class="${a.icon} me-1"></i>${a.label}</button>`)
      .join("");
    return `
      <div class="col-xl-4 col-md-6">
        <div class="card custom-card health-tile h-100">
          <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex align-items-center gap-2">
              <span class="avatar avatar-md bg-${ok ? "success" : "danger"} text-white"><i class="${esc(t.icon)}"></i></span>
              <div class="flex-fill">
                <div class="fw-semibold">${esc(t.label)}</div>
                <div class="fs-15 fw-bold">${esc(t.value)}</div>
              </div>
              <span class="badge bg-${ok ? "success" : "danger"}">${ok ? "Working" : "Check"}</span>
            </div>
            <p class="mb-0 fs-13">${esc(t.detail)}</p>
            ${actions || t.fix ? `<div class="d-flex flex-wrap gap-2 mt-auto">${actions}${t.fix && !(t.actions || []).length ? `<button type="button" class="btn btn-sm btn-outline-primary" data-go="${esc(t.fix)}">Open ${esc(t.label)} settings</button>` : ""}</div>` : ""}
          </div>
        </div>
      </div>`;
  }

  function draw(h) {
    const c = h.counts;
    const kpis = [
      UI.renderSparkCard({ icon: "ri-mail-line", label: "Emails this month", value: String(c.emails), color: "primary", sub: "Sent or logged" }),
      UI.renderSparkCard({ icon: "ri-message-3-line", label: "SMS this month", value: String(c.sms), color: "pink", sub: "Sent or logged" }),
      UI.renderSparkCard({ icon: "ri-error-warning-line", label: "Failed this month", value: String(c.failed), color: c.failed ? "danger" : "success", sub: c.failed ? "See the tiles below" : "Nothing failed" }),
    ];
    const needs = h.tiles.filter((t) => t.status !== "ok").length;
    root.innerHTML = `
      <div class="row">${kpis.map((k) => `<div class="col-xl-4 col-md-6">${k}</div>`).join("")}</div>
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3" id="card-tiles">
        <div class="fw-semibold fs-15">${needs ? `${needs} thing${needs === 1 ? " needs" : "s need"} checking` : "Everything is working"}</div>
        <button type="button" class="btn btn-sm btn-light border" id="healthRefresh"><i class="ri-refresh-line me-1"></i>Check again</button>
      </div>
      <div class="row">${h.tiles.map(tile).join("")}</div>
      ${F.card({
        id: "card-running",
        title: "What's running",
        icon: "ri-server-line",
        colour: "purple",
        body: `<ul class="list-unstyled settings-facts mb-0">
          <li><i class="ri-code-s-slash-line"></i><span>Laravel ${esc(h.app.laravel)} · PHP ${esc(h.app.php)}</span></li>
          <li><i class="ri-earth-line"></i><span>Environment: <b>${esc(h.app.environment)}</b> ${h.app.debug ? '<span class="badge bg-danger ms-1">Debug on</span>' : '<span class="badge bg-success ms-1">Debug off</span>'}</span></li>
          <li><i class="ri-settings-4-line"></i><span>${h.app.settings_changed} setting${h.app.settings_changed === 1 ? "" : "s"} changed from their defaults</span></li>
        </ul>`,
      })}`;
    root.querySelector("#healthRefresh").addEventListener("click", load);
    root.querySelectorAll("[data-go]").forEach((b) => b.addEventListener("click", () => SettingsHub.show(b.dataset.go)));
    root.querySelector('[data-action="retry-failed"]')?.addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Retrying…");
      const res = await SettingsAPI.retryFailed();
      UI.restoreButton(btn);
      res.ok ? Toast.success(res.message) : Toast.error(res.message);
      load();
    });
    SettingsHub.subLinks([
      { id: "card-tiles", label: "Health" },
      { id: "card-running", label: "What's running" },
    ]);
  }

  async function load() {
    const res = await SettingsAPI.health();
    if (!res.ok) {
      root.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
      return;
    }
    draw(res.data);
    SettingsRail.setAttention("health", res.data.tiles.some((t) => t.status !== "ok"));
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.health = {
    async render(body) {
      root = body;
      await load();
    },
  };
})();
