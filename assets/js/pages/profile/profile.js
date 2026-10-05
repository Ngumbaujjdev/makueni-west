/**
 * My Profile (profile.php) - the signed-in person's own page.
 *
 * Left: the header card, contact information and account details.
 * Right: section tabs - Personal info, Security, Roles, Activity, Sign-ins -
 * in the same look as the Demographics, Attendance and Budgets pages
 * (DemographicsUI cards, filter bars and paged tables, .app-modal windows).
 *
 * API (all "self" routes): GET /users/{id}, GET /auth/user/{id}/audits,
 * .../audits/login-history, .../audits/password-changes, PUT /auth/profile,
 * POST /password-reset/change-password. Errors come back as
 * {success:false, status, errors} - read the body, not the HTTP code.
 */
(function () {
  "use strict";

  // ui-helpers.js declares DemographicsUI as a top-level const (not on window).
  const UI = typeof DemographicsUI !== "undefined" ? DemographicsUI : null;
  const BASE = AppConfig.API_BASE_URL;
  const KEYS = Constants.STORAGE_KEYS;

  let me = null;
  let activity = [];
  let signins = [];
  let passwords = [];
  let userId = null;

  // ---------------------------------------------------------------- helpers

  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  async function api(method, path, body) {
    const res = await fetch(`${BASE}${path}`, {
      method,
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        Authorization: `Bearer ${localStorage.getItem(KEYS.AUTH_TOKEN)}`,
      },
      body: body ? JSON.stringify(body) : undefined,
    });
    let json = {};
    try {
      json = await res.json();
    } catch (e) {
      /* empty body */
    }
    return { ok: res.ok && json.success !== false, status: json.status || res.status, message: json.message, errors: json.errors || {}, data: json.data };
  }

  /** The API sends "2026-10-05 12:41:44" (UTC, no zone) or ISO with Z. */
  function when(value) {
    if (!value) return null;
    const s = String(value);
    const d = new Date(/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? s : `${s.replace(" ", "T")}Z`);
    return isNaN(d) ? null : d;
  }
  const dateText = (d) => (d ? d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");
  const timeText = (d) => (d ? d.toLocaleTimeString("en-GB", { hour: "numeric", minute: "2-digit", hour12: true }).replace(" ", " ") : "");
  const dayKey = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

  function friendlyWhen(d) {
    if (!d) return "Never";
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    if (dayKey(d) === dayKey(today)) return `Today, ${timeText(d)}`;
    if (dayKey(d) === dayKey(yesterday)) return `Yesterday, ${timeText(d)}`;
    return `${dateText(d)}, ${timeText(d)}`;
  }

  function ago(d) {
    if (!d) return "";
    const s = Math.round((Date.now() - d.getTime()) / 1000);
    if (s < 60) return "just now";
    const m = Math.round(s / 60);
    if (m < 60) return `${m} min ago`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h} hour${h === 1 ? "" : "s"} ago`;
    const days = Math.round(h / 24);
    if (days < 31) return `${days} day${days === 1 ? "" : "s"} ago`;
    const months = Math.round(days / 30);
    return months < 12 ? `${months} month${months === 1 ? "" : "s"} ago` : `${Math.round(months / 12)} year${months < 24 ? "" : "s"} ago`;
  }

  const daysBetween = (a, b) => Math.round((b.getTime() - a.getTime()) / 86400000);
  const initials = (name) => (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join("");
  const titleCase = (s) => String(s || "").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

  /** "Chrome 144 on Mac" + an icon, from a user agent. */
  function browserOf(ua) {
    if (!ua) return { name: "Unknown browser", icon: "ri-global-line" };
    const v = (re) => (ua.match(re) || [])[1];
    let name = "Browser";
    let icon = "ri-global-line";
    if (/Edg\//.test(ua)) [name, icon] = [`Edge ${v(/Edg\/(\d+)/)}`, "ri-edge-line"];
    else if (/OPR\//.test(ua)) [name, icon] = [`Opera ${v(/OPR\/(\d+)/)}`, "ri-opera-line"];
    else if (/Firefox\//.test(ua)) [name, icon] = [`Firefox ${v(/Firefox\/(\d+)/)}`, "ri-firefox-line"];
    else if (/Chrome\//.test(ua)) [name, icon] = [`${/Headless/.test(ua) ? "Automated Chrome" : "Chrome"} ${v(/Chrome\/(\d+)/)}`, "ri-chrome-line"];
    else if (/Safari\//.test(ua)) [name, icon] = [`Safari ${v(/Version\/(\d+)/) || ""}`.trim(), "ri-safari-line"];
    else if (/curl|Symfony|Guzzle|PostmanRuntime/i.test(ua)) [name, icon] = ["A script", "ri-terminal-box-line"];
    const os = /iPhone|iPad/.test(ua) ? "iPhone" : /Android/.test(ua) ? "Android" : /Mac OS X|Macintosh/.test(ua) ? "Mac" : /Windows/.test(ua) ? "Windows" : /Linux/.test(ua) ? "Linux" : "";
    return { name: os ? `${name} on ${os}` : name, icon, mobile: /iPhone|Android|Mobile/.test(ua) };
  }

  const LEVEL = {
    church: { color: "primary", icon: "ri-building-line", label: "Church" },
    subregion: { color: "info", icon: "ri-map-2-line", label: "Subregion" },
    region: { color: "success", icon: "ri-map-pin-line", label: "Region" },
    diocese: { color: "purple", icon: "ri-government-line", label: "Diocese" },
    global: { color: "danger", icon: "ri-earth-line", label: "Whole system" },
  };

  const FIELDS = {
    firstname: "first name",
    lastname: "last name",
    email: "email",
    phone: "phone",
    username: "username",
    position: "position",
    status: "status",
    employee_code: "sign-in code",
    must_change_password: "must-change-password flag",
    password_expires_at: "password expiry",
  };
  // Changes that happen on every sign-in - not worth a sentence.
  const NOISE = ["last_login_at", "login_attempts", "remember_token", "updated_at", "created_at", "phone_key", "password", "password_changed_at", "login_success", "login_method", "locked_until"];

  /** What kind of activity, for the filter and the colour. */
  const KINDS = {
    signin: { label: "Signed in", icon: "ri-login-circle-line", color: "success" },
    failed: { label: "Sign-in failed", icon: "ri-close-circle-line", color: "danger" },
    password: { label: "Password", icon: "ri-lock-password-line", color: "warning" },
    profile: { label: "Details changed", icon: "ri-user-settings-line", color: "primary" },
    status: { label: "Account status", icon: "ri-shield-user-line", color: "purple" },
    other: { label: "Other", icon: "ri-history-line", color: "info" },
  };

  function kindOf(a) {
    const e = a.event || "";
    if (e === "user_login") return "signin";
    if (e === "login_failed" || e === "account_locked") return "failed";
    if (e.includes("password")) return "password";
    if (e.startsWith("status_changed")) return "status";
    if (e === "updated" || e === "profile_updated") return "profile";
    return "other";
  }

  const shown = (v) => (v === null || v === undefined || v === "" ? "nothing" : typeof v === "boolean" ? (v ? "yes" : "no") : String(v));

  /** One plain sentence for an activity entry. */
  function sentence(a) {
    const e = a.event || "";
    const nv = a.new_values || {};
    if (e === "user_login") return nv.login_method === "employee_code" ? "You signed in with your code" : "You signed in";
    if (e === "login_failed") return "A sign-in to your account failed";
    if (e === "account_locked") return "Your account was locked after failed sign-ins";
    if (e === "password_changed" || e.includes("password")) return "Your password was changed";
    if (e.startsWith("status_changed")) {
      const m = e.match(/status_changed_(\w+)_to_(\w+)/);
      return m ? `Your account went from ${titleCase(m[1])} to ${titleCase(m[2])}` : "Your account status changed";
    }
    if (e === "created") return "Your account was created";
    if (e === "updated" || e === "profile_updated") {
      const keys = changedKeys(a);
      if (!keys.length) return "Your account was updated";
      if (keys.length === 1) {
        const k = keys[0];
        const ov = (a.old_values || {})[k];
        return ov === undefined || ov === null || ov === "" ? `You added your ${FIELDS[k] || titleCase(k).toLowerCase()}: ${shown(nv[k])}` : `You changed your ${FIELDS[k] || titleCase(k).toLowerCase()} from ${shown(ov)} to ${shown(nv[k])}`;
      }
      const names = keys.map((k) => FIELDS[k] || titleCase(k).toLowerCase());
      return `You changed your ${names.slice(0, -1).join(", ")} and ${names.slice(-1)}`;
    }
    return titleCase(e);
  }

  function changedKeys(a) {
    return Object.keys({ ...(a.old_values || {}), ...(a.new_values || {}) }).filter((k) => !NOISE.includes(k));
  }

  function emptyState(icon, color, title, sub) {
    return `<div class="list-empty"><span class="list-empty-icon bg-${color} text-white"><i class="${icon}"></i></span><div class="fw-semibold mt-2">${title}</div>${sub ? `<div class="fs-12">${sub}</div>` : ""}</div>`;
  }

  function setFigure(tab, text) {
    const el = document.querySelector(`[data-tab-figure="${tab}"]`);
    if (el) el.textContent = text;
  }

  function actingAssignmentId() {
    try {
      return JSON.parse(localStorage.getItem(KEYS.CURRENT_ROLE) || "null")?.assignment_id || null;
    } catch (e) {
      return null;
    }
  }

  // ------------------------------------------------------------------ load

  async function init() {
    try {
      userId = JSON.parse(localStorage.getItem(KEYS.USER_DATA) || "null")?.id;
    } catch (e) {
      userId = null;
    }
    if (!userId || !localStorage.getItem(KEYS.AUTH_TOKEN)) {
      window.location.href = `${AppConfig.FRONTEND_BASE_URL || "/makueni-west"}/login`;
      return;
    }

    wireTabs();
    wireEditProfile();
    wirePassword();
    document.querySelectorAll("[data-copy-field]").forEach((btn) => btn.addEventListener("click", () => copy(me?.[btn.dataset.copyField], btn)));

    const profile = await api("GET", `/users/${userId}`);
    if (!profile.ok) {
      document.getElementById("detailsGrid").innerHTML = `<div class="col-12">${emptyState("ri-error-warning-line", "danger", "Your profile couldn't be loaded", "Check your connection and refresh the page.")}</div>`;
      Toast.error(profile.message || "Your profile couldn't be loaded");
      return;
    }
    me = profile.data;
    renderProfile();

    const [acts, logs, pwd] = await Promise.all([
      api("GET", `/auth/user/${userId}/audits?limit=200`),
      api("GET", `/auth/user/${userId}/audits/login-history?limit=200`),
      api("GET", `/auth/user/${userId}/audits/password-changes`),
    ]);
    activity = acts.ok ? acts.data?.audits || [] : [];
    signins = logs.ok ? logs.data || [] : [];
    passwords = pwd.ok ? pwd.data || [] : [];
    renderSecurity();
    renderActivity();
    renderSignins();
    if (!acts.ok || !logs.ok) Toast.warning("Some of your history couldn't be loaded");
  }

  function renderProfile() {
    renderHeader();
    renderAccountDetails();
    renderDetails();
    renderSecurity();
    renderRoles();
    document.querySelectorAll("[data-edit-profile]").forEach((b) => (b.disabled = false));
  }

  // ---------------------------------------------------------------- left side

  function renderHeader() {
    document.getElementById("profileCover").classList.remove("placeholder-glow");
    document.getElementById("profileHeaderAvatar").textContent = initials(me.full_name);
    document.getElementById("profileHeaderName").textContent = me.full_name || "";
    document.getElementById("profileHeaderPosition").textContent = me.position || "No position set";
    document.getElementById("profileHeaderContact").innerHTML = [
      me.email ? `<span class="me-3 d-inline-block"><i class="ri-mail-line me-1 align-middle"></i>${esc(me.email)}</span>` : "",
      me.phone ? `<span class="d-inline-block"><i class="ri-phone-line me-1 align-middle"></i>${esc(me.phone)}</span>` : "",
    ].join("") || "&nbsp;";
    document.getElementById("profileAssignmentsCount").textContent = (me.active_assignments || []).length;
    const active = me.status === "active";
    document.getElementById("profileHeaderStatus").innerHTML = `<span class="badge bg-${active ? "success" : "danger"} fs-13">${esc(titleCase(me.status))}</span>`;

    document.getElementById("contactList").classList.remove("placeholder-glow");
    ["email", "phone", "username"].forEach((key) => {
      const el = document.querySelector(`[data-contact="${key}"]`);
      el.innerHTML = me[key] ? esc(me[key]) : `<span class="fw-normal fst-italic">Not added yet</span>`;
      document.querySelector(`[data-copy-field="${key}"]`).classList.toggle("d-none", !me[key]);
    });
  }

  function renderAccountDetails() {
    const actingId = actingAssignmentId();
    const acting = (me.active_assignments || []).find((a) => a.id === actingId) || (me.active_assignments || []).find((a) => a.is_primary) || (me.active_assignments || [])[0];
    const rows = [
      {
        icon: "ri-key-2-line",
        color: "primary",
        label: "Your sign-in code",
        value: `<span class="profile-code" data-code-hidden="1">••••••</span><button type="button" class="btn btn-sm btn-icon btn-light ms-2" id="codeToggle" aria-label="Show your code" title="Show"><i class="ri-eye-line"></i></button>`,
        note: "Keep it private - it signs you in.",
      },
      { icon: "ri-login-circle-line", color: "success", label: "Last sign-in", value: esc(friendlyWhen(when(me.last_login_at))) },
      { icon: "ri-calendar-check-line", color: "info", label: "Member since", value: esc(dateText(when(me.created_at)) || "-") },
      {
        icon: "ri-user-star-line",
        color: "purple",
        label: "Acting as",
        value: acting ? `${esc(acting.role?.name)} <span class="fw-normal">· ${esc(acting.territory?.name)}</span>` : "No role yet",
      },
    ];
    const box = document.getElementById("accountDetails");
    box.classList.remove("placeholder-glow");
    box.innerHTML = rows
      .map(
        (r) => `
        <div class="profile-fact">
          <span class="avatar avatar-sm avatar-rounded bg-${r.color} text-white flex-shrink-0"><i class="${r.icon}"></i></span>
          <div class="flex-fill" style="min-width: 0;">
            <div class="profile-fact-label">${r.label}</div>
            <div class="profile-fact-value">${r.value}</div>
            ${r.note ? `<div class="fs-11">${r.note}</div>` : ""}
          </div>
        </div>`,
      )
      .join("");
    document.getElementById("codeToggle")?.addEventListener("click", (ev) => {
      const code = box.querySelector(".profile-code");
      const hidden = code.dataset.codeHidden === "1";
      code.textContent = hidden ? me.employee_code || "-" : "••••••";
      code.dataset.codeHidden = hidden ? "0" : "1";
      ev.currentTarget.innerHTML = `<i class="ri-eye${hidden ? "-off" : ""}-line"></i>`;
      ev.currentTarget.setAttribute("aria-label", hidden ? "Hide your code" : "Show your code");
    });
  }

  async function copy(text, btn) {
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      const icon = btn.querySelector("i");
      icon.className = "ri-check-line text-success";
      setTimeout(() => (icon.className = "ri-file-copy-line"), 1500);
    } catch (e) {
      Toast.info(text);
    }
  }

  // ---------------------------------------------------------- personal info

  function renderDetails() {
    const items = [
      ["firstname", "First name", "ri-user-line", "primary"],
      ["lastname", "Last name", "ri-user-3-line", "info"],
      ["email", "Email", "ri-mail-line", "success"],
      ["phone", "Phone", "ri-phone-line", "warning"],
      ["username", "Username", "ri-at-line", "purple"],
      ["position", "Position", "ri-briefcase-line", "pink"],
    ];
    const filled = items.filter(([k]) => me[k]).length;
    const missing = items.filter(([k]) => !me[k]).map(([, label]) => label.toLowerCase());
    const pct = Math.round((filled / items.length) * 100);
    setFigure("details", `${filled} of ${items.length} filled`);

    const box = document.getElementById("profileComplete");
    box.classList.remove("placeholder-glow");
    box.innerHTML = `
      <div class="d-flex align-items-center gap-3">
        <span class="avatar avatar-md avatar-rounded bg-${pct === 100 ? "success" : "warning"} text-white flex-shrink-0"><i class="${pct === 100 ? "ri-checkbox-circle-line" : "ri-edit-2-line"} fs-18"></i></span>
        <div class="flex-fill">
          <div class="d-flex justify-content-between flex-wrap gap-1">
            <span class="fw-semibold">${pct === 100 ? "Your profile is complete" : `${filled} of ${items.length} filled`}</span>
            <span class="fw-semibold text-${pct === 100 ? "success" : "warning"}">${pct}%</span>
          </div>
          <div class="progress progress-sm my-1" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-${pct === 100 ? "success" : "warning"}" style="width: ${pct}%"></div></div>
          <div class="fs-12">${pct === 100 ? "People can reach you by phone and email." : `Add your ${missing.join(" and ")} so people can reach you.`}</div>
        </div>
      </div>`;

    const grid = document.getElementById("detailsGrid");
    grid.classList.remove("placeholder-glow");
    grid.innerHTML =
      items
        .map(
          ([key, label, icon, color]) => `
        <div class="col-md-6">
          <div class="profile-detail">
            <span class="avatar avatar-sm avatar-rounded bg-${color} text-white flex-shrink-0"><i class="${icon}"></i></span>
            <div class="flex-fill" style="min-width: 0;">
              <div class="profile-fact-label">${label}</div>
              <div class="profile-fact-value text-break">${me[key] ? esc(me[key]) : `<button type="button" class="btn btn-link p-0 fw-semibold" data-edit-profile>Add your ${label.toLowerCase()}</button>`}</div>
            </div>
            ${me[key] && ["email", "phone", "username"].includes(key) ? `<button type="button" class="btn btn-sm btn-icon btn-light profile-copy" data-copy="${key}" title="Copy" aria-label="Copy ${label.toLowerCase()}"><i class="ri-file-copy-line"></i></button>` : ""}
          </div>
        </div>`,
        )
        .join("") +
      `
        <div class="col-md-6">
          <div class="profile-detail">
            <span class="avatar avatar-sm avatar-rounded bg-success text-white flex-shrink-0"><i class="ri-shield-check-line"></i></span>
            <div class="flex-fill"><div class="profile-fact-label">Account</div><div class="profile-fact-value">${UI.pill(titleCase(me.status), me.status === "active" ? "success" : "danger")}</div></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="profile-detail">
            <span class="avatar avatar-sm avatar-rounded bg-primary text-white flex-shrink-0"><i class="ri-briefcase-4-line"></i></span>
            <div class="flex-fill"><div class="profile-fact-label">Roles</div><div class="profile-fact-value">${(me.active_assignments || []).length} active · <button type="button" class="btn btn-link p-0 fw-semibold" data-go-tab="roles">See them</button></div></div>
          </div>
        </div>`;
    grid.querySelectorAll("[data-copy]").forEach((b) => b.addEventListener("click", () => copy(me[b.dataset.copy], b)));
    grid.querySelectorAll("[data-edit-profile]").forEach((b) => b.addEventListener("click", openEditProfile));
    grid.querySelectorAll("[data-go-tab]").forEach((b) => b.addEventListener("click", () => showTab(b.dataset.goTab)));
  }

  // ---------------------------------------------------------------- security

  function renderSecurity() {
    if (!me) return;
    const changed = when(me.password_changed_at);
    const expires = when(me.password_expires_at);
    const daysLeft = expires ? daysBetween(new Date(), expires) : null;
    const fails = Number(me.login_attempts || 0);
    const active = me.status === "active";

    UI.renderStatCardsRow("securityCards", [
      { icon: "ri-shield-user-line", label: "Account", value: titleCase(me.status), color: active ? "success" : "danger", sub: active ? "You can sign in" : "Ask an administrator to open it" },
      { icon: "ri-key-2-line", label: "Password changed", value: changed ? ago(changed) : "Not yet", color: "primary", sub: changed ? dateText(changed) : "Since your account was made" },
      {
        icon: "ri-calendar-event-line",
        label: "Password expires",
        value: expires ? (daysLeft <= 0 ? "Expired" : `In ${daysLeft} day${daysLeft === 1 ? "" : "s"}`) : "Doesn't expire",
        color: expires && daysLeft <= 7 ? "danger" : "purple",
        sub: expires ? dateText(expires) : "The diocese sets this in Security settings",
      },
      { icon: "ri-error-warning-line", label: "Failed sign-ins", value: String(fails), color: fails >= 3 ? "danger" : fails > 0 ? "warning" : "success", sub: fails ? "Since your last sign-in" : "None since your last sign-in" },
    ]);

    const alerts = [];
    if (me.must_change_password) alerts.push(["warning", "ri-error-warning-line", "Please change your password", "You were given a temporary one. Choose your own now."]);
    if (expires && daysLeft <= 0) alerts.push(["danger", "ri-lock-line", "Your password has expired", "Change it to keep using the system."]);
    else if (expires && daysLeft <= 7) alerts.push(["warning", "ri-time-line", `Your password expires in ${daysLeft} day${daysLeft === 1 ? "" : "s"}`, "Change it before then."]);
    if (fails >= 3) alerts.push(["danger", "ri-alarm-warning-line", `${fails} failed sign-ins on your account`, "If that wasn't you, change your password."]);
    document.getElementById("securityAlerts").innerHTML = alerts
      .map(([c, i, t, s]) => `<div class="alert alert-${c} d-flex align-items-start gap-2 mb-3" role="alert"><i class="${i} fs-18"></i><div><div class="fw-semibold">${t}</div><div class="fs-12">${s}</div></div></div>`)
      .join("");

    setFigure("security", alerts.length ? `${alerts.length} to look at` : changed ? `Changed ${ago(changed)}` : "All good");

    const box = document.getElementById("passwordTimeline");
    if (!passwords.length) {
      box.innerHTML = emptyState("ri-lock-password-line", "danger", "No password changes yet", "When your password is changed, it shows here with the date and the browser.");
      return;
    }
    UI.renderTimeline(
      "passwordTimeline",
      passwords.map((p) => {
        const d = when(p.created_at);
        const by = p.changed_by?.id && p.changed_by.id !== me.id ? `Reset by ${p.changed_by.name}` : "You changed it";
        return {
          day: d ? d.getDate() : "",
          weekday: d ? d.toLocaleDateString("en-GB", { month: "short", year: "2-digit" }) : "",
          title: `<span class="fw-semibold">${esc(by)}</span>`,
          time: `${esc(timeText(d))} · ${esc(browserOf(p.user_agent).name)}`,
          badgeLabel: p.must_change_password ? "Temporary" : "Password",
          badgeColor: p.must_change_password ? "warning" : "danger",
        };
      }),
    );
  }

  // ------------------------------------------------------------------ roles

  function renderRoles() {
    const actingId = actingAssignmentId();
    const roles = me.active_assignments || [];
    setFigure("roles", `${roles.length} active`);
    const grid = document.getElementById("rolesGrid");
    grid.innerHTML = roles.length
      ? roles
          .map((a) => {
            const lv = LEVEL[a.territory?.type] || LEVEL[a.role?.territory_level] || LEVEL.church;
            const acting = actingId ? a.id === actingId : a.is_primary;
            return `
            <div class="col-md-6">
              <div class="profile-role${acting ? " is-acting" : ""}">
                <span class="avatar avatar-md avatar-rounded bg-${lv.color} text-white flex-shrink-0"><i class="${lv.icon} fs-18"></i></span>
                <div class="flex-fill" style="min-width: 0;">
                  <div class="d-flex flex-wrap align-items-center gap-1">
                    <span class="fw-semibold">${esc(a.role?.name)}</span>
                    ${acting ? UI.pill("Acting now", "success", "ri-checkbox-circle-line") : ""}
                  </div>
                  <div class="text-break">${esc(a.territory?.name)}</div>
                  <div class="d-flex flex-wrap gap-1 mt-2">
                    <span class="soft-chip soft-${lv.color}">${lv.label}</span>
                    <span class="soft-chip soft-${a.is_primary ? "primary" : "secondary"}">${a.is_primary ? "Main role" : "Also"}</span>
                    ${a.assigned_at ? `<span class="soft-chip soft-info">Since ${esc(dateText(when(a.assigned_at)))}</span>` : ""}
                  </div>
                </div>
              </div>
            </div>`;
          })
          .join("")
      : `<div class="col-12">${emptyState("ri-briefcase-4-line", "success", "You have no role yet", "An administrator gives you a role at a church, region or the diocese.")}</div>`;

    const history = me.assignment_history || [];
    const body = document.getElementById("rolesTableBody");
    if (!history.length) {
      body.innerHTML = `<tr><td colspan="5">${emptyState("ri-history-line", "primary", "No role history yet", "")}</td></tr>`;
      UI.initListDataTable("rolesTable", {});
      return;
    }
    body.innerHTML = history
      .map((h) => {
        const d = when(h.assigned_at);
        const ended = when(h.removed_at);
        const status = h.is_active ? "Active" : "Ended";
        return `
          <tr>
            <td data-search="${esc(h.role_name)}"><span class="fw-semibold">${esc(h.role_name)}</span>${h.is_primary ? ` <span class="soft-chip soft-primary ms-1">Main</span>` : ""}</td>
            <td>${esc(h.territory_name)}</td>
            <td class="d-none d-md-table-cell">${esc(h.assigned_by || "-")}</td>
            <td data-order="${d ? d.getTime() : 0}">${esc(dateText(d) || "-")}${ended ? `<div class="fs-12">Ended ${esc(dateText(ended))}</div>` : ""}</td>
            <td data-search="${status}">${UI.pill(status, h.is_active ? "success" : "secondary")}</td>
          </tr>`;
      })
      .join("");
    UI.renderFilterToolbar("rolesToolbar", {
      searchPlaceholder: "Search roles and places...",
      filters: [{ id: "roleStatus", label: "Active and ended", options: [{ value: "Active", label: "Active" }, { value: "Ended", label: "Ended" }] }],
    });
    const table = UI.initListDataTable("rolesTable", { order: [[3, "desc"]], hideDefaultSearch: true, noun: "roles", pageLength: 10 });
    UI.wireFilterToolbar("rolesToolbar", table, [{ id: "roleStatus", columnIndex: 4, exact: true }], { noun: "roles", urlSync: false });
  }

  // --------------------------------------------------------------- activity

  function renderActivity() {
    const body = document.getElementById("activityTableBody");
    const last = when(activity[0]?.created_at);
    setFigure("activity", activity.length ? `${activity.length}${activity.length >= 200 ? "+" : ""} entries` : "Nothing yet");
    document.getElementById("activitySub").textContent = activity.length >= 200 ? "Your 200 most recent sign-ins and changes, newest first" : "Sign-ins and changes to your account, newest first";
    if (!activity.length) {
      body.innerHTML = `<tr><td colspan="4">${emptyState("ri-history-line", "purple", "No activity yet", "Your sign-ins and changes will show here.")}</td></tr>`;
      UI.initListDataTable("activityTable", {});
      return;
    }
    body.innerHTML = activity
      .map((a, i) => {
        const d = when(a.created_at);
        const k = KINDS[kindOf(a)];
        const b = browserOf(a.user_agent);
        const text = sentence(a);
        return `
          <tr data-date="${d ? dayKey(d) : ""}">
            <td data-order="${d ? d.getTime() : 0}">
              <div class="d-flex align-items-center gap-2">
                <span class="budget-recent-date"><b>${d ? d.getDate() : ""}</b><small>${d ? d.toLocaleDateString("en-GB", { month: "short" }) : ""}</small></span>
                <div class="text-nowrap"><div class="fw-semibold">${esc(timeText(d))}</div><div class="fs-12">${esc(ago(d))}</div></div>
              </div>
            </td>
            <td data-search="k-${kindOf(a)} ${esc(text)}">
              <div class="d-flex align-items-center gap-2">
                <span class="avatar avatar-sm avatar-rounded bg-${k.color} text-white flex-shrink-0"><i class="${k.icon}"></i></span>
                <div style="min-width: 0;"><div class="fw-semibold text-break">${esc(text)}</div><span class="soft-chip soft-${k.color}">${k.label}</span></div>
              </div>
            </td>
            <td class="d-none d-md-table-cell" data-search="${esc(`${b.name} ${a.ip_address || ""}`)}">
              <div class="d-flex flex-wrap gap-1">
                <span class="soft-chip soft-primary"><i class="${b.icon} me-1"></i>${esc(b.name)}</span>
                ${a.ip_address ? `<span class="soft-chip soft-secondary">${esc(a.ip_address)}</span>` : ""}
              </div>
            </td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-primary-light" data-activity="${i}" title="Details" aria-label="Details"><i class="ri-eye-line"></i></button></td>
          </tr>`;
      })
      .join("");

    UI.renderFilterToolbar("activityToolbar", {
      searchPlaceholder: "Search what happened, browser or IP...",
      filters: [{ id: "activityKind", label: "Everything", options: Object.entries(KINDS).map(([key, k]) => ({ value: `k-${key}`, label: k.label })) }],
      dateRange: true,
    });
    const table = UI.initListDataTable("activityTable", { order: [[0, "desc"]], nonSortableColumns: [3], hideDefaultSearch: true, noun: "entries", pageLength: 10 });
    UI.wireFilterToolbar("activityToolbar", table, [{ id: "activityKind", columnIndex: 1 }], { noun: "entries" });
    body.onclick = (ev) => {
      const btn = ev.target.closest("[data-activity]");
      if (btn) openActivity(activity[Number(btn.dataset.activity)]);
    };
  }

  function openActivity(a) {
    if (!a) return;
    const d = when(a.created_at);
    const kind = kindOf(a);
    const k = KINDS[kind];
    const b = browserOf(a.user_agent);
    document.getElementById("activityModalIcon").className = `app-modal-icon bg-${k.color}`;
    document.getElementById("activityModalIcon").innerHTML = `<i class="${k.icon}"></i>`;
    document.getElementById("activityModalTitle").textContent = sentence(a);
    document.getElementById("activityModalSub").textContent = `${friendlyWhen(d)} · ${ago(d)}`;

    const facts = [
      ["ri-time-line", "primary", "When", esc(friendlyWhen(d))],
      [b.icon, "success", "Browser", esc(b.name)],
      ["ri-map-pin-line", "warning", "Network (IP)", esc(a.ip_address || "Not recorded")],
      ["ri-user-line", "purple", "Done by", esc(a.changed_by?.name || "The system")],
    ];
    const keys = changedKeys(a);
    const nv = a.new_values || {};
    const ov = a.old_values || {};
    const changes = keys.length
      ? `
        <div class="budget-field-label mt-3">What changed</div>
        <div class="table-responsive border rounded">
          <table class="table mb-0 align-middle">
            <thead><tr><th>Field</th><th>Before</th><th></th><th>After</th></tr></thead>
            <tbody>
              ${keys
                .map(
                  (key) => `<tr>
                    <td class="fw-semibold">${esc(titleCase(FIELDS[key] || key))}</td>
                    <td>${ov[key] === undefined ? `<span class="fst-italic">-</span>` : `<span class="soft-chip soft-danger text-break">${esc(shown(ov[key]))}</span>`}</td>
                    <td class="text-center"><i class="ri-arrow-right-line"></i></td>
                    <td><span class="soft-chip soft-success text-break">${esc(shown(nv[key]))}</span></td>
                  </tr>`,
                )
                .join("")}
            </tbody>
          </table>
        </div>`
      : kind === "signin" || kind === "failed"
        ? `<div class="alert alert-${kind === "signin" ? "success" : "danger"} mt-3 mb-0 fs-13">${kind === "signin" ? `Signed in ${nv.login_method === "employee_code" ? "with your sign-in code" : "with your password"}.` : "Someone tried to sign in to your account and it failed. If that wasn't you, change your password."}</div>`
        : "";

    document.getElementById("activityModalBody").innerHTML = `
      <div class="row g-2">
        ${facts
          .map(
            ([icon, color, label, value]) => `
          <div class="col-sm-6">
            <div class="profile-detail h-100">
              <span class="avatar avatar-sm avatar-rounded bg-${color} text-white flex-shrink-0"><i class="${icon}"></i></span>
              <div class="flex-fill" style="min-width: 0;"><div class="profile-fact-label">${label}</div><div class="profile-fact-value text-break">${value}</div></div>
            </div>
          </div>`,
          )
          .join("")}
      </div>
      ${changes}
      ${a.user_agent ? `<details class="mt-3"><summary class="fs-12 fw-semibold">Full browser details</summary><div class="fs-12 font-monospace mt-2 p-2 rounded border text-break">${esc(a.user_agent)}</div></details>` : ""}`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("activityModal")).show();
  }

  // --------------------------------------------------------------- sign-ins

  function renderSignins() {
    const ok = (s) => s.event === "user_login" && s.login_success !== false;
    const now = new Date();
    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    const thisMonth = signins.filter((s) => ok(s) && when(s.created_at) >= monthStart).length;
    const failed = signins.filter((s) => !ok(s)).length;
    const browsers = new Set(signins.map((s) => browserOf(s.user_agent).name)).size;
    setFigure("signins", `${thisMonth} in ${now.toLocaleDateString("en-GB", { month: "short" })}`);

    document.getElementById("signinFacts").innerHTML = [
      { icon: "ri-login-circle-line", color: "success", label: "Sign-ins this month", value: String(thisMonth), sub: `${signins.length} in your recent history` },
      { icon: "ri-window-line", color: "primary", label: "Browsers used", value: String(browsers), sub: browsers > 1 ? "Different browsers or devices" : "One browser" },
      { icon: "ri-close-circle-line", color: failed ? "danger" : "purple", label: "Failed sign-ins", value: String(failed), sub: failed ? "Check each one was you" : "None in your recent history" },
    ]
      .map((c) => `<div class="flex-fill profile-signin-fact">${UI.renderSparkCard(c)}</div>`)
      .join("");

    // Last 30 days, day by day.
    const days = [];
    for (let i = 29; i >= 0; i--) {
      const d = new Date(now);
      d.setDate(now.getDate() - i);
      days.push(d);
    }
    const count = (pred) => days.map((d) => signins.filter((s) => pred(s) && when(s.created_at) && dayKey(when(s.created_at)) === dayKey(d)).length);
    const good = count(ok);
    const bad = count((s) => !ok(s));
    const chartEl = document.getElementById("signinChart");
    const emptyEl = document.getElementById("signinChartEmpty");
    chartEl.innerHTML = "";
    if (good.some(Boolean) || bad.some(Boolean)) {
      emptyEl.hidden = true;
      // Day numbers under the bars; the dates covered are in the card's subtitle.
      const labels = days.map((d) => String(d.getDate()));
      const span = (d) => d.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
      document.getElementById("signinChartSub").textContent = `Successful and failed, day by day · ${span(days[0])} – ${span(days[29])}`;
      UI.renderTrendChart("signinChart", {
        categories: labels,
        series: [
          { name: "Signed in", data: good },
          { name: "Failed", data: bad },
        ],
        type: "bar",
        colors: [UI.brandHex("success"), UI.brandHex("danger")],
        extra: {
          chart: { type: "bar", height: 260, stacked: true, toolbar: { show: false }, foreColor: UI.chartTextColor() },
          legend: { show: true, position: "top", horizontalAlign: "right" },
          plotOptions: { bar: { columnWidth: "55%", borderRadius: 3 } },
          xaxis: { categories: labels, labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: UI.chartTextColor(), fontWeight: 600, fontSize: "11px" } } },
          tooltip: { x: { formatter: (v, o) => days[o.dataPointIndex].toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" }) } },
        },
      });
    } else {
      emptyEl.hidden = false;
      emptyEl.innerHTML = emptyState("ri-bar-chart-2-line", "warning", "No sign-ins in the last 30 days", "");
    }

    const body = document.getElementById("signinTableBody");
    if (!signins.length) {
      body.innerHTML = `<tr><td colspan="4">${emptyState("ri-login-circle-line", "warning", "No sign-ins yet", "")}</td></tr>`;
      UI.initListDataTable("signinTable", {});
      return;
    }
    body.innerHTML = signins
      .map((s) => {
        const d = when(s.created_at);
        const b = browserOf(s.user_agent);
        const success = ok(s);
        const result = success ? "Signed in" : s.event === "account_locked" ? "Locked" : "Failed";
        return `
          <tr data-date="${d ? dayKey(d) : ""}">
            <td data-order="${d ? d.getTime() : 0}">
              <div class="d-flex align-items-center gap-2">
                <span class="budget-recent-date is-${success ? "in" : "out"}"><b>${d ? d.getDate() : ""}</b><small>${d ? d.toLocaleDateString("en-GB", { month: "short" }) : ""}</small></span>
                <div class="text-nowrap"><div class="fw-semibold">${esc(timeText(d))}</div><div class="fs-12">${esc(ago(d))}</div></div>
              </div>
            </td>
            <td data-search="${esc(b.name)}"><span class="d-inline-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-primary text-white"><i class="${b.mobile ? "ri-smartphone-line" : b.icon}"></i></span><span class="fw-semibold">${esc(b.name)}</span></span></td>
            <td class="d-none d-md-table-cell">${s.ip_address ? `<span class="soft-chip soft-secondary">${esc(s.ip_address)}</span>` : "-"}</td>
            <td data-search="${result}">${UI.pill(result, success ? "success" : "danger", success ? "ri-check-line" : "ri-close-line")}</td>
          </tr>`;
      })
      .join("");
    UI.renderFilterToolbar("signinToolbar", {
      searchPlaceholder: "Search browser or IP...",
      filters: [{ id: "signinResult", label: "Any result", options: ["Signed in", "Failed", "Locked"].map((r) => ({ value: r, label: r })) }],
      dateRange: true,
    });
    const table = UI.initListDataTable("signinTable", { order: [[0, "desc"]], hideDefaultSearch: true, noun: "sign-ins", pageLength: 10 });
    UI.wireFilterToolbar("signinToolbar", table, [{ id: "signinResult", columnIndex: 3, exact: true }], { noun: "sign-ins", urlSync: false });
  }

  // ------------------------------------------------------------------- tabs

  function showTab(key) {
    const btn = document.getElementById(`tab-${key}-btn`);
    if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
  }

  function wireTabs() {
    document.querySelectorAll("#profileTabs [data-tab]").forEach((btn) =>
      btn.addEventListener("shown.bs.tab", () => {
        const params = new URLSearchParams(window.location.search);
        if (btn.dataset.tab === "details") params.delete("tab");
        else params.set("tab", btn.dataset.tab);
        const qs = params.toString();
        history.replaceState(null, "", `${window.location.pathname}${qs ? `?${qs}` : ""}`);
        // Tables drawn in a hidden tab need their widths worked out again.
        if (window.jQuery?.fn?.dataTable) window.jQuery.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
        window.dispatchEvent(new Event("resize"));
      }),
    );
    const start = new URLSearchParams(window.location.search).get("tab");
    if (start) showTab(start);
    document.getElementById("changePasswordBtn").addEventListener("click", openPassword);
  }

  // ------------------------------------------------------- edit my details

  const EDITABLE = ["firstname", "lastname", "phone", "email", "username", "position"];

  function openEditProfile() {
    if (!me) return;
    const form = document.getElementById("editProfileForm");
    EDITABLE.forEach((k) => {
      form.elements[k].value = me[k] || "";
      form.elements[k].classList.remove("is-invalid");
    });
    updateEditSummary();
    bootstrap.Modal.getOrCreateInstance(document.getElementById("editProfileModal")).show();
  }

  function editChanges() {
    const form = document.getElementById("editProfileForm");
    return EDITABLE.filter((k) => form.elements[k].value.trim() !== (me[k] || ""));
  }

  function updateEditSummary() {
    const changes = editChanges();
    document.getElementById("editProfileSummary").textContent = changes.length ? `Changing your ${changes.map((k) => FIELDS[k]).join(", ")}` : "Nothing changed yet";
    document.getElementById("editProfileSave").disabled = !changes.length;
  }

  function wireEditProfile() {
    document.querySelectorAll("[data-edit-profile]").forEach((b) => b.addEventListener("click", openEditProfile));
    const form = document.getElementById("editProfileForm");
    form.addEventListener("input", (ev) => {
      ev.target.classList.remove("is-invalid");
      updateEditSummary();
    });
    form.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const changes = editChanges();
      if (!changes.length) return;
      let bad = false;
      ["firstname", "lastname"].forEach((k) => {
        if (!form.elements[k].value.trim()) {
          showFieldError(form, k, "This can't be empty.");
          bad = true;
        }
      });
      if (bad) return;

      const payload = {};
      changes.forEach((k) => (payload[k] = form.elements[k].value.trim()));
      const btn = document.getElementById("editProfileSave");
      UI.setButtonLoading(btn, "Saving...");
      const res = await api("PUT", "/auth/profile", payload);
      UI.restoreButton(btn);
      if (!res.ok) {
        const fields = Object.keys(res.errors || {});
        fields.forEach((k) => showFieldError(form, k, [].concat(res.errors[k])[0]));
        if (!fields.length) Toast.error(res.message || "Your details couldn't be saved");
        updateEditSummary();
        return;
      }
      bootstrap.Modal.getInstance(document.getElementById("editProfileModal"))?.hide();
      const fresh = await api("GET", `/users/${userId}`);
      if (fresh.ok) me = fresh.data;
      renderProfile();
      syncStoredUser();
      Toast.success("Your details are saved");
      const acts = await api("GET", `/auth/user/${userId}/audits?limit=200`);
      if (acts.ok) {
        activity = acts.data?.audits || [];
        rebuildTable("activityTable");
        renderActivity();
      }
    });
  }

  function showFieldError(form, name, message) {
    const input = form.elements[name];
    const box = form.querySelector(`[data-error="${name}"]`);
    if (input) input.classList.add("is-invalid");
    if (box) box.textContent = message;
  }

  /** The header and sidebar read the name from storage - keep it current. */
  function syncStoredUser() {
    try {
      const stored = JSON.parse(localStorage.getItem(KEYS.USER_DATA) || "null");
      if (!stored) return;
      ["firstname", "lastname", "email", "phone", "username", "position", "full_name"].forEach((k) => {
        if (me[k] !== undefined) stored[k] = me[k];
      });
      localStorage.setItem(KEYS.USER_DATA, JSON.stringify(stored));
    } catch (e) {
      /* storage not available - the page itself is already updated */
    }
  }

  function rebuildTable(id) {
    if (window.jQuery?.fn?.dataTable?.isDataTable(`#${id}`)) window.jQuery(`#${id}`).DataTable().destroy();
  }

  // --------------------------------------------------------- change password

  // The diocese can raise the minimum (Settings > Security); the server's
  // "Use at least N characters" tells us when it's more than 8.
  let minLen = 8;
  const CHECKS = [
    ["length", () => `At least ${minLen} characters`, (p) => p.length >= minLen],
    ["letter", () => "A letter", (p) => /[A-Za-z]/.test(p)],
    ["number", () => "A number", (p) => /\d/.test(p)],
    ["match", () => "Both new passwords match", (p, c) => p.length > 0 && p === c],
  ];

  function strength(p) {
    let score = 0;
    if (p.length >= 8) score++;
    if (p.length >= 12) score++;
    if (/[a-z]/.test(p) && /[A-Z]/.test(p)) score++;
    if (/\d/.test(p)) score++;
    if (/[^A-Za-z0-9]/.test(p)) score++;
    return Math.min(score, 4);
  }

  function openPassword() {
    const form = document.getElementById("passwordForm");
    form.reset();
    form.querySelectorAll(".is-invalid").forEach((el) => el.classList.remove("is-invalid"));
    updatePassword();
    bootstrap.Modal.getOrCreateInstance(document.getElementById("passwordModal")).show();
    setTimeout(() => form.elements.current_password.focus(), 300);
  }

  function updatePassword() {
    const form = document.getElementById("passwordForm");
    const p = form.elements.new_password.value;
    const c = form.elements.new_password_confirmation.value;
    const cur = form.elements.current_password.value;
    const results = CHECKS.map(([key, label, test]) => [key, label(), test(p, c)]);
    document.getElementById("passwordChecklist").innerHTML = `<div class="d-flex flex-wrap gap-2">${results
      .map(([, label, pass]) => `<span class="soft-chip soft-${pass ? "success" : "secondary"}"><i class="ri-${pass ? "checkbox-circle" : "checkbox-blank-circle"}-line me-1"></i>${label}</span>`)
      .join("")}</div>`;
    const s = p ? strength(p) : 0;
    const levels = [
      ["Too weak", "danger"],
      ["Weak", "danger"],
      ["Fair", "warning"],
      ["Good", "primary"],
      ["Strong", "success"],
    ];
    const bar = document.getElementById("strengthBar");
    bar.style.width = p ? `${((s + 1) / 5) * 100}%` : "0";
    bar.className = p ? `bg-${levels[s][1]}` : "";
    document.getElementById("strengthLabel").innerHTML = p ? `<span class="text-${levels[s][1]}">${levels[s][0]}</span>` : "&nbsp;";
    const ready = cur && results.every(([, , pass]) => pass);
    document.getElementById("passwordSave").disabled = !ready;
    document.getElementById("passwordSummary").textContent = !cur ? "Start with your current password" : ready ? "Ready to change" : "Choose a new password that ticks every box";
  }

  function wirePassword() {
    const form = document.getElementById("passwordForm");
    form.addEventListener("input", (ev) => {
      ev.target.classList.remove("is-invalid");
      updatePassword();
    });
    form.querySelectorAll("[data-toggle-password]").forEach((btn) =>
      btn.addEventListener("click", () => {
        const input = document.getElementById(btn.dataset.togglePassword);
        const show = input.type === "password";
        input.type = show ? "text" : "password";
        btn.innerHTML = `<i class="ri-eye${show ? "-off" : ""}-line"></i>`;
        btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
      }),
    );
    form.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const btn = document.getElementById("passwordSave");
      if (btn.disabled) return;
      UI.setButtonLoading(btn, "Changing...");
      const res = await api("POST", "/password-reset/change-password", {
        current_password: form.elements.current_password.value,
        new_password: form.elements.new_password.value,
        new_password_confirmation: form.elements.new_password_confirmation.value,
      });
      UI.restoreButton(btn);
      if (!res.ok) {
        const fields = Object.keys(res.errors || {});
        fields.forEach((k) => showFieldError(form, k, [].concat(res.errors[k])[0]));
        const longer = String([].concat(res.errors.new_password || [])[0] || "").match(/at least (\d+)/);
        if (longer) minLen = Number(longer[1]);
        if (!fields.length) {
          // "New password must be different from current password" comes back without a field.
          showFieldError(form, "new_password", res.message || "Your password couldn't be changed");
        }
        updatePassword();
        return;
      }
      bootstrap.Modal.getInstance(document.getElementById("passwordModal"))?.hide();
      Toast.success("Your password is changed");
      const [fresh, pwd] = await Promise.all([api("GET", `/users/${userId}`), api("GET", `/auth/user/${userId}/audits/password-changes`)]);
      if (fresh.ok) me = fresh.data;
      if (pwd.ok) passwords = pwd.data || [];
      renderSecurity();
    });
  }

  window.ProfilePage = { init, openEditProfile, openPassword };
})();
