/**
 * ============================================================================
 * MINISTRIES - shared look and windows (P4, round 2)
 * ============================================================================
 * A ministry's tile and card (its own icon and colour), when it meets, and
 * the windows, shaped like v1-events' bd-modal - a form in numbered steps
 * beside a live preview, saving through a spinner to a done view:
 *   ministryWindow()      - Add / Edit a ministry
 *   meetsWindow()         - When it meets (all its own leader may change)
 *   addMembersWindow()    - tick many from the register, the picked ones aside
 *   addToMinistryWindow() - from the members list
 * ============================================================================
 */
const MinistriesUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");
  const num = (n) => Number(n || 0).toLocaleString("en-GB");
  const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const initials = (name) => String(name || "?").split(/\s+/).filter(Boolean).map((w) => w[0]).slice(0, 2).join("").toUpperCase();

  const tile = (m, size = "md") => `<span class="avatar avatar-${size} avatar-rounded bg-${m.colour} ${textOn(m.colour)} flex-shrink-0"><i class="${m.icon}"></i></span>`;
  const day = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "-");
  const todayIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  };
  /** "Today", "Tomorrow", "11 Oct" - short enough for a card's fact tile. */
  const nextLabel = (iso) => {
    if (!iso) return null;
    const d = Math.round((new Date(`${iso}T12:00:00`) - new Date(`${todayIso()}T12:00:00`)) / 86400000);
    if (d === 0) return "Today";
    if (d === 1) return "Tomorrow";
    return new Date(`${iso}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" });
  };
  /** "Saturdays 3:00 pm" from a day and "15:00". */
  const meetsText = (dayNo, time) => {
    if (dayNo === null || dayNo === undefined || dayNo === "") return "No set day";
    if (!time) return `${DAYS[dayNo]}s`;
    const [h, mi] = time.split(":").map(Number);
    return `${DAYS[dayNo]}s ${((h + 11) % 12) + 1}:${String(mi).padStart(2, "0")} ${h < 12 ? "am" : "pm"}`;
  };
  /** The average of the months it met in, or null. */
  const average = (series) => {
    const met = (series || []).filter(Boolean);
    return met.length ? Math.round(met.reduce((a, b) => a + b, 0) / met.length) : null;
  };
  const lead = (m) => m.leaders.find((l) => l.role === "leader") || m.leaders[0] || null;

  /**
   * The inside of a ministry card - the same on the Ministries page and in
   * the Add / Edit window's preview. ctx {months, membersTotal, link}
   */
  function cardBody(m, ctx = {}) {
    const avg = average(m.attendance_series);
    const share = ctx.membersTotal ? Math.round((m.members / ctx.membersTotal) * 100) : 0;
    const l = lead(m);
    const facts = [
      { icon: "ri-group-line", color: "primary", label: "Members", value: num(m.members) },
      { icon: "ri-bar-chart-box-line", color: "success", label: "Average", value: avg === null ? "-" : num(avg), sub: !m.gathering_type ? "Not linked" : avg === null ? "None yet" : "each time" },
      { icon: "ri-calendar-event-line", color: "warning", label: "Next", value: m.next_meeting ? nextLabel(m.next_meeting) : "-", sub: m.next_meeting ? "" : "No set day" },
    ];
    const series = m.attendance_series || [];
    const spark = m.gathering_type && series.some(Boolean)
      ? `<div class="mn-card-spark" data-spark='${JSON.stringify({ labels: ctx.months || series.map(() => ""), data: series }).replace(/'/g, "&#39;")}' data-spark-color="${m.colour}" data-spark-height="44"></div>`
      : `<div class="mn-card-spark is-empty"><i class="ri-line-chart-line"></i>${m.gathering_type ? "No gatherings recorded yet" : "Link its gathering to see attendance"}</div>`;
    return `<div class="card-body d-flex flex-column">
        <div class="d-flex align-items-start gap-3">
          ${tile(m)}
          <div class="flex-fill min-w-0">
            <h6 class="ev-card-title mb-1">${esc(m.name || "Your ministry")}</h6>
            <div class="d-flex flex-wrap gap-1">
              <span class="soft-chip soft-primary"><i class="ri-repeat-line"></i>${esc(m.meets || "No set day")}</span>
              ${m.gathering_type ? `<span class="soft-chip soft-success"><i class="ri-links-line"></i>${esc(m.gathering_type.name)}</span>` : `<span class="soft-chip soft-${m.colour}"><i class="${m.icon}"></i>${esc(m.kind_label || "Ministry")}</span>`}
            </div>
          </div>
          ${m.active === false ? '<span class="badge bg-secondary text-dark">Switched off</span>' : m.mine ? '<span class="badge bg-success">You lead it</span>' : ""}
        </div>
        <div class="mn-facts">${facts.map((f) => `<div><small><span class="mn-fact-icon" style="--q: var(--${f.color}-rgb)"><i class="${f.icon}"></i></span>${f.label}</small><strong>${f.value}</strong>${f.sub ? `<em>${f.sub}</em>` : ""}</div>`).join("")}</div>
        ${ctx.membersTotal ? `<div class="mn-share"><div class="d-flex justify-content-between"><span>${num(m.members)} of ${num(ctx.membersTotal)} members</span><strong>${share}%</strong></div><div class="progress progress-xs"><div class="progress-bar bg-${m.colour}" style="width:${Math.min(100, share)}%"></div></div></div>` : ""}
        ${spark}
        <div class="mn-card-foot mt-auto">
          <div class="d-flex align-items-center gap-2 min-w-0">
            ${l ? `<span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(l.name)} text-white flex-shrink-0">${esc(l.initials || initials(l.name))}</span><span class="min-w-0"><strong class="mn-card-leaders">${esc(l.name)}${m.leaders.length > 1 ? ` <span class="mb-sub">+${m.leaders.length - 1}</span>` : ""}</strong><small class="d-block mb-sub">${esc(l.role_label || "Leader")}</small></span>` : '<span class="avatar avatar-sm avatar-rounded bg-warning text-dark flex-shrink-0"><i class="ri-user-star-line"></i></span><span class="mn-card-leaders">No leader yet</span>'}
          </div>
          ${ctx.link ? '<span class="mn-open">Open<i class="ri-arrow-right-line"></i></span>' : ""}
        </div>
      </div>`;
  }

  /** A ministry on the Ministries page - every card the same height. */
  function card(m, ctx) {
    return `<div class="col-xxl-4 col-lg-6 d-flex">
      <a class="card custom-card mn-card flex-fill${m.active ? "" : " is-off"}" href="${ctx.baseUrl}/ministry?id=${m.id}">${cardBody(m, { ...ctx, link: true })}</a>
    </div>`;
  }

  // ---------------------------------------------------------------- windows
  /**
   * A window: a navy band, the body, the footer - and the saving and done
   * views (.app-modal.is-busy / .is-done).
   */
  function windowEl({ id = "mnModal", title, subtitle = "", icon, size = "", bodyClass = "", body, foot }) {
    document.getElementById(id)?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal mn-modal" id="${id}" tabindex="-1" aria-labelledby="${id}Title" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ${size}"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon"><i class="${icon}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="${id}Title">${esc(title)}</h5>${subtitle ? `<div class="app-modal-subtitle">${esc(subtitle)}</div>` : ""}</div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body ${bodyClass}">${body}</div>
          <div class="modal-footer">${foot}</div>
          <div class="app-modal-state is-busy-view" role="status"><div class="app-modal-spinner"></div><div class="fw-semibold">Saving...</div></div>
          <div class="app-modal-state is-done-view"><div class="app-modal-tick"><i class="ri-check-line"></i></div><div class="fs-5 fw-bold" data-done-title>Saved</div><div class="app-modal-facts" data-done-facts></div><div class="d-flex flex-wrap justify-content-center gap-2" data-done-actions></div></div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById(id);
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  /**
   * Save through the spinner. done(data) returns {title, facts[], actions[{label, icon, primary, run}]}
   * for the done view - or nothing, and the window simply closes.
   */
  async function submit(el, call, done) {
    el.classList.add("is-busy");
    const res = await call();
    el.classList.remove("is-busy");
    if (!res.ok) return Toast.error(res.message);
    const view = done?.(res.data, res.message);
    if (!view) {
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message);
      return;
    }
    el.querySelector("[data-done-title]").textContent = view.title;
    el.querySelector("[data-done-facts]").innerHTML = (view.facts || []).map((f) => `<span class="soft-chip soft-${f.color || "primary"}"><i class="${f.icon}"></i>${esc(f.text)}</span>`).join("");
    const actions = el.querySelector("[data-done-actions]");
    actions.innerHTML = (view.actions || []).map((a, i) => `<button type="button" class="btn ${a.primary ? "btn-primary" : "btn-light border"}" data-done="${i}"><i class="${a.icon} me-1"></i>${esc(a.label)}</button>`).join("");
    actions.onclick = (e) => {
      const b = e.target.closest("[data-done]");
      if (b) view.actions[Number(b.dataset.done)].run();
    };
    el.classList.add("is-done");
  }
  const close = (el) => bootstrap.Modal.getInstance(el)?.hide();

  /** One step of a window: a coloured tile, its number, a title and a line of help. */
  const step = (n, { icon, color, title, help = "", body }) =>
    `<section class="mw-step"><header><span class="mw-num bg-${color} ${textOn(color)}"><i class="${icon}"></i></span><div class="min-w-0"><h6>${esc(title)}</h6>${help ? `<p>${esc(help)}</p>` : ""}</div><span class="mw-step-no">${n}</span></header>${body}</section>`;
  const field = (label, html, { id = "", optional = false, wide = true } = {}) =>
    `<div class="mw-field${wide ? " is-wide" : ""}"><label${id ? ` for="${id}"` : ""}>${esc(label)}${optional ? '<span class="mw-opt">Optional</span>' : ""}</label>${html}</div>`;
  const affix = (icon, input) => `<div class="mw-affix"><i class="${icon}"></i>${input}</div>`;

  let optionsCache = null;
  const options = async () => {
    if (!optionsCache) {
      const res = await MinistriesAPI.options();
      if (!res.ok) {
        Toast.error(res.message);
        return null;
      }
      optionsCache = res.data;
    }
    return optionsCache;
  };

  /** The day pills and the time - shared by the ministry window and When it meets. */
  const meetsFields = (m) =>
    `<div class="mw-fields">
      ${field("Day", `<div class="mw-days" role="radiogroup" aria-label="Day">${[["", "No set day"], ...DAYS.map((d, i) => [String(i), d.slice(0, 3)])].map(([v, t]) => `<label><input type="radio" name="mwDay" value="${v}"${String(m?.meets_day ?? "") === v ? " checked" : ""}><span>${t}</span></label>`).join("")}</div>`)}
      ${field("Time", affix("ri-time-line", `<input type="time" class="form-control mw-input" id="mwTime" value="${m?.meets_time || ""}">`), { id: "mwTime", optional: true, wide: false })}
    </div>`;
  const readMeets = (el) => {
    const v = el.querySelector('input[name="mwDay"]:checked')?.value ?? "";
    return { meets_day: v === "" ? null : Number(v), meets_time: el.querySelector("#mwTime").value || null };
  };

  /** When it meets - all a ministry's own leader may change. */
  function meetsWindow(m, { onDone } = {}) {
    const el = windowEl({
      title: "When it meets",
      subtitle: m.name,
      icon: "ri-repeat-line",
      size: "modal-lg",
      body: `<div class="mw-form">${step(1, { icon: "ri-repeat-line", color: "warning", title: "When it meets", help: "It shows on the ministry and its next meeting on the card", body: meetsFields(m) })}</div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="mwSave"><i class="ri-check-line me-1"></i>Save</button>`,
    });
    el.querySelector("#mwSave").addEventListener("click", () => submit(el, () => MinistriesAPI.update(m.id, readMeets(el)), (d) => (onDone?.(d), null)));
  }

  /**
   * Add a ministry, or edit one - in steps beside a live preview of its card.
   * members: the people serving in it (an edit can name one of them leader).
   */
  async function ministryWindow({ ministry = null, members = [], onDone } = {}) {
    const o = await options();
    if (!o) return;
    const m = ministry;
    const kindOf = (k) => o.kinds.find((x) => x.key === k) || o.kinds[o.kinds.length - 1];
    const startKind = m?.kind || "other";
    const look = { icon: m?.icon || kindOf(startKind).icon, colour: m?.colour || kindOf(startKind).color };
    const people = [...o.leaders.map((u) => ({ key: `u${u.id}`, name: u.name, hint: "Church leader" })), ...members.map((p) => ({ key: `p${p.id}`, name: p.name, hint: "Serves in it" }))];
    const leaderRow = (l = null) => {
      const sel = l ? (l.user_id ? `u${l.user_id}` : `p${l.person_id}`) : "";
      const who = people.find((p) => p.key === sel);
      return `<div class="mw-leader" data-leader>
        <span class="avatar avatar-sm avatar-rounded ${who ? `bg-${UI.colorFor(who.name)} text-white` : "bg-light text-dark"} flex-shrink-0" data-avatar>${who ? esc(initials(who.name)) : '<i class="ri-user-line"></i>'}</span>
        <div class="flex-fill min-w-0"><select class="form-select" data-who aria-label="Leader"><option value="">Pick someone</option><optgroup label="Church leaders (with a login)">${o.leaders.map((u) => `<option value="u${u.id}"${sel === `u${u.id}` ? " selected" : ""}>${esc(u.name)}</option>`).join("")}</optgroup>${members.length ? `<optgroup label="Serving in it">${members.map((p) => `<option value="p${p.id}"${sel === `p${p.id}` ? " selected" : ""}>${esc(p.name)}</option>`).join("")}</optgroup>` : ""}</select></div>
        <div class="mw-seg" role="radiogroup" aria-label="Role">${o.roles.map((r) => `<label><input type="radio" data-role value="${r.key}"${(l?.role || "leader") === r.key ? " checked" : ""}><span>${esc(r.label)}</span></label>`).join("")}</div>
        <button type="button" class="btn btn-icon btn-sm btn-light border" data-drop aria-label="Remove this leader"><i class="ri-close-line"></i></button>
      </div>`;
    };
    let rowNo = 0;
    const el = windowEl({
      title: m ? `Edit ${m.name}` : "Add a ministry",
      subtitle: m ? "Change how it shows, when it meets and who leads it" : "Youth, a choir, ushers - any group that serves",
      icon: m ? "ri-edit-line" : "ri-team-line",
      size: "modal-xl",
      bodyClass: "p-0",
      body: `<div class="mw-grid">
        <div class="mw-form">
          ${step(1, {
            icon: "ri-team-line",
            color: "primary",
            title: "Name and kind",
            help: m?.standard ? "One every church has - it keeps its kind" : "What it is called, and what kind of group it is",
            body: `<div class="mw-fields">${field("Name", affix("ri-edit-2-line", `<input class="form-control mw-input" id="mwName" maxlength="80" value="${esc(m?.name || "")}" placeholder="e.g. Ushers">`), { id: "mwName" })}
              ${field("Kind", `<div class="mw-kinds" role="radiogroup" aria-label="Kind">${o.kinds.map((k) => `<label><input type="radio" name="mwKind" value="${k.key}"${k.key === startKind ? " checked" : ""}${m?.standard ? " disabled" : ""}><span><i class="${k.icon}"></i>${esc(k.label)}</span></label>`).join("")}</div>`)}</div>`,
          })}
          ${step(2, {
            icon: "ri-palette-line",
            color: "pink",
            title: "Look",
            help: "Its icon and colour on the cards and charts",
            body: `<div class="mw-fields">${field("Icon", `<div class="mn-icon-pick" role="radiogroup" aria-label="Icon">${o.icons.map((i) => `<label><input type="radio" name="mwIcon" value="${i}"${i === look.icon ? " checked" : ""}><span><i class="${i}"></i></span></label>`).join("")}</div>`)}
              ${field("Colour", `<div class="mn-swatches" role="radiogroup" aria-label="Colour">${o.colours.map((c) => `<label title="${c}"><input type="radio" name="mwColour" value="${c}"${c === look.colour ? " checked" : ""}><span class="bg-${c}"></span></label>`).join("")}</div>`)}</div>`,
          })}
          ${step(3, { icon: "ri-repeat-line", color: "warning", title: "When it meets", help: "Its next meeting shows on the card", body: meetsFields(m) })}
          ${step(4, {
            icon: "ri-bar-chart-box-line",
            color: "success",
            title: "Attendance",
            help: "Its numbers come from Attendance - nothing is counted twice",
            body: `<div class="mw-fields">${field("Gathering type", `<select class="form-select" id="mwGathering" aria-label="Gathering type"><option value="" data-icon="ri-link-unlink" data-color="secondary">Not linked</option>${o.gathering_types.map((g) => `<option value="${g.id}" data-icon="ri-links-line" data-color="success"${m?.gathering_type?.id === g.id ? " selected" : ""}>${esc(g.name)}${g.category ? ` · ${esc(g.category)}` : ""}</option>`).join("")}</select>`, { id: "mwGathering", optional: true })}</div>`,
          })}
          ${step(5, {
            icon: "ri-user-star-line",
            color: "purple",
            title: "Leaders",
            help: "A leader with a login can look after its members",
            body: `<div id="mwLeaders">${(m?.leaders || []).map(leaderRow).join("")}</div><button type="button" class="btn btn-sm btn-outline-primary mt-1" id="mwAddLeader"><i class="ri-add-line me-1"></i>Add a leader</button>`,
          })}
          ${
            m
              ? step(6, {
                  icon: "ri-toggle-line",
                  color: "primary",
                  title: "Running",
                  body: `<label class="mw-toggle"><span class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="mwActive"${m.active ? " checked" : ""}></span><span><strong>It is running</strong><small>Switch off to take it off the page - its people and history stay</small></span></label>`,
                })
              : ""
          }
        </div>
        <aside class="mw-preview" aria-live="polite"><div class="mw-sticky">
          <small class="mw-preview-label">On the Ministries page</small>
          <div class="card custom-card mn-card mb-0" id="mwCard"></div>
          <small class="mw-preview-label mt-3">Good to know</small>
          <ul class="mw-notes">
            <li><i class="ri-lock-2-line"></i>Only our church's leaders see who serves in it.</li>
            <li><i class="ri-user-star-line"></i>Its leaders can add and take out its members.</li>
            ${startKind === "children" ? '<li><i class="ri-book-open-line"></i>Sunday-school children are always in it, straight from the register.</li>' : ""}
          </ul>
        </div></aside>
      </div>`,
      foot: `${m && !m.standard ? '<button type="button" class="btn btn-outline-danger me-auto" id="mwRemove"><i class="ri-delete-bin-line me-1"></i>Remove</button>' : ""}<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="mwSave"><i class="ri-check-line me-1"></i>${m ? "Save changes" : "Add it"}</button>`,
    });

    UI.enhanceSelect(el.querySelector("#mwGathering"));
    const enhanceRow = (row) => {
      const sel = row.querySelector("[data-who]");
      sel.id = `mwWho${rowNo++}`;
      row.querySelectorAll("[data-role]").forEach((r) => (r.name = `${sel.id}Role`));
      UI.enhanceSelect(sel, { placeholder: "Pick someone" });
      $(sel).on("change", () => {
        const who = people.find((p) => p.key === sel.value);
        const av = row.querySelector("[data-avatar]");
        av.className = `avatar avatar-sm avatar-rounded ${who ? `bg-${UI.colorFor(who.name)} text-white` : "bg-light text-dark"} flex-shrink-0`;
        av.innerHTML = who ? esc(initials(who.name)) : '<i class="ri-user-line"></i>';
        preview();
      });
    };
    el.querySelectorAll("[data-leader]").forEach(enhanceRow);

    const current = () => {
      const kind = el.querySelector('input[name="mwKind"]:checked')?.value || startKind;
      const meets = readMeets(el);
      const g = o.gathering_types.find((x) => String(x.id) === el.querySelector("#mwGathering").value);
      const leaders = [...el.querySelectorAll("[data-leader]")]
        .map((r) => ({ who: people.find((p) => p.key === r.querySelector("[data-who]").value), role: r.querySelector("[data-role]:checked")?.value || "leader" }))
        .filter((l) => l.who)
        .map((l) => ({ name: l.who.name, initials: initials(l.who.name), role: l.role, role_label: o.roles.find((r) => r.key === l.role)?.label }));
      return {
        ...(m || {}),
        name: el.querySelector("#mwName").value.trim(),
        kind,
        kind_label: kindOf(kind).label,
        icon: el.querySelector('input[name="mwIcon"]:checked').value,
        colour: el.querySelector('input[name="mwColour"]:checked').value,
        meets: meetsText(meets.meets_day, meets.meets_time),
        next_meeting: m && meets.meets_day === m.meets_day ? m.next_meeting : null,
        gathering_type: g ? { id: g.id, name: g.name } : null,
        members: m?.members || 0,
        leaders,
        active: m ? el.querySelector("#mwActive").checked : true,
        attendance_series: m?.attendance_series || [],
      };
    };
    function preview() {
      el.querySelector("#mwCard").innerHTML = cardBody(current(), { months: [] });
      UI.mountSparklines(el.querySelector("#mwCard"));
    }
    el.querySelector(".mw-form").addEventListener("input", preview);
    el.querySelector(".mw-form").addEventListener("change", preview);
    $(el.querySelector("#mwGathering")).on("change", preview);
    // A kind picked brings its usual icon and colour.
    el.querySelectorAll('input[name="mwKind"]').forEach((r) =>
      r.addEventListener("change", () => {
        const k = kindOf(r.value);
        el.querySelector(`input[name="mwIcon"][value="${k.icon}"]`)?.click();
        el.querySelector(`input[name="mwColour"][value="${k.color}"]`)?.click();
      }),
    );
    preview();
    el.querySelector("#mwAddLeader").addEventListener("click", () => {
      el.querySelector("#mwLeaders").insertAdjacentHTML("beforeend", leaderRow());
      enhanceRow(el.querySelector("#mwLeaders").lastElementChild);
    });
    el.querySelector("#mwLeaders").addEventListener("click", (e) => {
      const row = e.target.closest("[data-drop]")?.closest("[data-leader]");
      if (row) {
        row.remove();
        preview();
      }
    });

    // Remove asks once more, in the footer - not a second window on top.
    const foot = el.querySelector(".modal-footer");
    const footHtml = foot.innerHTML;
    function bind() {
      el.querySelector("#mwSave").addEventListener("click", save);
      el.querySelector("#mwRemove")?.addEventListener("click", () => {
        foot.innerHTML = `<span class="me-auto fw-semibold text-danger">Remove ${esc(m.name)}? Its members stay in the register.</span><button type="button" class="btn btn-light border" data-keep>Keep it</button><button type="button" class="btn btn-danger" data-yes><i class="ri-delete-bin-line me-1"></i>Yes, remove</button>`;
        foot.querySelector("[data-keep]").addEventListener("click", () => {
          foot.innerHTML = footHtml;
          bind();
        });
        foot.querySelector("[data-yes]").addEventListener("click", () => submit(el, () => MinistriesAPI.remove(m.id), () => (window.location.href = `${window.MIN_CTX.baseUrl}/`)));
      });
    }
    bind();

    function save() {
      const c = current();
      if (!c.name) {
        el.querySelector("#mwName").focus();
        return Toast.error("Give it a name.");
      }
      const body = {
        name: c.name,
        icon: c.icon,
        colour: c.colour,
        gathering_type_id: c.gathering_type ? c.gathering_type.id : null,
        leaders: [...el.querySelectorAll("[data-leader]")]
          .map((r) => ({ who: r.querySelector("[data-who]").value, role: r.querySelector("[data-role]:checked")?.value || "leader" }))
          .filter((l) => l.who)
          .map((l) => (l.who.startsWith("u") ? { user_id: Number(l.who.slice(1)), role: l.role } : { person_id: Number(l.who.slice(1)), role: l.role })),
        ...readMeets(el),
      };
      if (!m?.standard) body.kind = c.kind;
      if (m) body.active = c.active;
      submit(
        el,
        () => (m ? MinistriesAPI.update(m.id, body) : MinistriesAPI.create(body)),
        (saved) => {
          onDone?.(saved, { quiet: true });
          return {
            title: m ? `${saved.name} saved` : `${saved.name} added`,
            facts: [
              { icon: "ri-repeat-line", text: saved.meets || "No set day", color: "primary" },
              { icon: "ri-user-star-line", text: saved.leaders.length ? `${saved.leaders.length} ${saved.leaders.length === 1 ? "leader" : "leaders"}` : "No leader yet", color: "purple" },
              { icon: "ri-links-line", text: saved.gathering_type ? saved.gathering_type.name : "Not linked", color: "success" },
            ],
            actions: m
              ? [{ label: "Done", icon: "ri-check-line", primary: true, run: () => close(el) }]
              : [
                  { label: "Add another", icon: "ri-add-line", run: () => (close(el), setTimeout(() => ministryWindow({ onDone }), 350)) },
                  { label: `Open ${saved.name}`, icon: "ri-arrow-right-line", primary: true, run: () => (window.location.href = `${window.MIN_CTX.baseUrl}/ministry?id=${saved.id}`) },
                ],
          };
        },
      );
    }
  }

  /** Add members: tick many from the register (members and visitors not in it yet); the picked ones gather on the side. */
  async function addMembersWindow(m, { onDone } = {}) {
    const picked = new Map();
    let filter = "all";
    const FILTERS = [
      { key: "all", label: "Everyone", color: "primary", test: () => true },
      { key: "main_church", label: "Main church", color: "primary", test: (p) => p.kind !== "visitor" && p.congregation !== "sunday_school" },
      { key: "sunday_school", label: "Sunday school", color: "pink", test: (p) => p.congregation === "sunday_school" },
      { key: "female", label: "Women", color: "pink", test: (p) => p.gender === "female" },
      { key: "male", label: "Men", color: "info", test: (p) => p.gender === "male" },
      { key: "visitor", label: "Visitors", color: "purple", test: (p) => p.kind === "visitor" },
    ];
    const el = windowEl({
      title: `Add members to ${m.name}`,
      subtitle: "Tick as many as you like - from our members and visitors",
      icon: "ri-user-add-line",
      size: "modal-xl",
      bodyClass: "p-0",
      body: `<div class="mw-grid">
        <div class="mw-form">
          ${step(1, {
            icon: "ri-search-line",
            color: "primary",
            title: "Find them",
            help: "Search, or narrow the list - then tick",
            body: `<div class="mw-fields">${field("Search", affix("ri-search-line", '<input type="search" class="form-control mw-input" id="amSearch" placeholder="Name or area" autocomplete="off">'), { id: "amSearch" })}</div>
              <div class="mn-filter-row" id="amFilters"></div>
              <div class="d-flex align-items-center justify-content-between mt-3 mb-2"><span class="mb-sub" id="amShown">Loading the register...</span><button type="button" class="btn btn-sm btn-light border" id="amAll" disabled><i class="ri-checkbox-multiple-line me-1"></i>Tick all shown</button></div>
              <div class="pp-picker-list mn-pick-list" id="amList"></div>`,
          })}
        </div>
        <aside class="mw-preview"><div class="mw-sticky">
          <small class="mw-preview-label">Adding to</small>
          <div class="d-flex align-items-center gap-2 mb-3">${tile(m, "sm")}<strong>${esc(m.name)}</strong><span class="mb-sub ms-auto">${num(m.members)} now</span></div>
          <small class="mw-preview-label">Picked</small>
          <div class="mw-picked" id="amPicked"><p class="mb-0 mb-sub">Nobody yet - tick people on the left.</p></div>
        </div></aside>
      </div>`,
      foot: `<span class="me-auto fw-semibold" id="amCount">Nobody picked yet</span><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="amSave" disabled><i class="ri-check-line me-1"></i>Add them</button>`,
    });
    const res = await MinistriesAPI.candidates(m.id);
    if (!res.ok) return (el.querySelector("#amList").innerHTML = `<div class="pp-picker-none">${esc(res.message)}</div>`);
    const people = res.data;
    const shownList = () => {
      const q = el.querySelector("#amSearch").value.trim().toLowerCase();
      const f = FILTERS.find((x) => x.key === filter);
      return people.filter((p) => f.test(p) && (!q || `${p.name} ${p.area || ""}`.toLowerCase().includes(q)));
    };
    const sideAndCount = () => {
      el.querySelector("#amPicked").innerHTML = picked.size
        ? `<div class="pp-chips mt-0">${[...picked.values()].map((p) => `<span class="pp-chip"><span>${esc(p.name)}</span><button type="button" data-unpick="${p.id}" aria-label="Remove ${esc(p.name)}">&times;</button></span>`).join("")}</div>`
        : '<p class="mb-0 mb-sub">Nobody yet - tick people on the left.</p>';
      el.querySelector("#amCount").textContent = picked.size ? `${picked.size} picked` : "Nobody picked yet";
      el.querySelector("#amSave").disabled = !picked.size;
      el.querySelector("#amSave").innerHTML = `<i class="ri-check-line me-1"></i>${picked.size ? `Add ${picked.size} to ${esc(m.name)}` : "Add them"}`;
    };
    const paint = () => {
      el.querySelector("#amFilters").innerHTML = FILTERS.map((f) => `<button type="button" class="pp-pill${f.key === filter ? " is-on" : ""}" style="--q: var(--${f.color}-rgb)" data-f="${f.key}">${esc(f.label)}<span class="pp-pill-count">${people.filter(f.test).length}</span></button>`).join("");
      const list = shownList();
      el.querySelector("#amShown").textContent = `${list.length} shown of ${people.length} not in it yet`;
      el.querySelector("#amAll").disabled = !list.length;
      el.querySelector("#amList").innerHTML = list.length
        ? list
            .slice(0, 300)
            .map(
              (p) => `<label class="pp-picker-row${picked.has(p.id) ? " is-checked" : ""}"><input type="checkbox" class="form-check-input mt-0" data-pick="${p.id}"${picked.has(p.id) ? " checked" : ""}>
                <span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(p.name)} text-white flex-shrink-0">${esc(p.initials || initials(p.name))}</span>
                <span class="flex-fill min-w-0"><strong>${esc(p.name)}</strong><small>${esc([p.kind === "visitor" ? "Visitor" : p.congregation === "sunday_school" ? "Sunday school" : "Member", p.area].filter(Boolean).join(" · "))}</small></span>
                ${p.gender ? `<span class="soft-chip soft-${p.gender === "female" ? "pink" : "info"}">${p.gender === "female" ? "F" : "M"}</span>` : ""}</label>`,
            )
            .join("")
        : `<div class="pp-picker-none">${people.length ? "Nobody matches." : `Everyone in the register is already in ${esc(m.name)}.`}</div>`;
      sideAndCount();
    };
    paint();
    let t = null;
    el.querySelector("#amSearch").addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(paint, 150);
    });
    el.querySelector("#amFilters").addEventListener("click", (e) => {
      const b = e.target.closest("[data-f]");
      if (!b) return;
      filter = b.dataset.f;
      paint();
    });
    el.querySelector("#amAll").addEventListener("click", () => {
      shownList().forEach((p) => picked.set(p.id, p));
      paint();
    });
    el.querySelector("#amList").addEventListener("change", (e) => {
      const box = e.target.closest("[data-pick]");
      if (!box) return;
      const p = people.find((x) => x.id === Number(box.dataset.pick));
      box.checked ? picked.set(p.id, p) : picked.delete(p.id);
      box.closest(".pp-picker-row").classList.toggle("is-checked", box.checked);
      sideAndCount();
    });
    el.querySelector("#amPicked").addEventListener("click", (e) => {
      const b = e.target.closest("[data-unpick]");
      if (!b) return;
      picked.delete(Number(b.dataset.unpick));
      paint();
    });
    el.querySelector("#amSave").addEventListener("click", () =>
      submit(
        el,
        () => MinistriesAPI.addMembers(m.id, [...picked.keys()]),
        (d) => {
          onDone?.(d);
          return {
            title: `${d.added} added to ${m.name}`,
            facts: [{ icon: "ri-group-line", text: `${num(m.members + d.added)} serve in it now`, color: "primary" }, ...(d.already ? [{ icon: "ri-information-line", text: `${d.already} were already in it`, color: "warning" }] : [])],
            actions: [{ label: "Done", icon: "ri-check-line", primary: true, run: () => close(el) }],
          };
        },
      ),
    );
  }

  /** From the members list: add the people ticked there to one ministry. */
  async function addToMinistryWindow(ids, { onDone } = {}) {
    const res = await MinistriesAPI.overview();
    if (!res.ok) return Toast.error(res.message);
    const list = res.data.items;
    const el = windowEl({
      title: "Add to a ministry",
      subtitle: `${ids.length} ${ids.length === 1 ? "person" : "people"} picked`,
      icon: "ri-team-line",
      size: "modal-lg",
      body: `<div class="mw-form">${step(1, {
        icon: "ri-team-line",
        color: "primary",
        title: "Which ministry",
        help: "Anyone already in it stays as they are",
        body: `<div class="mw-ministries" role="radiogroup" aria-label="Ministry">${list.map((m, i) => `<label><input type="radio" name="atMinistry" value="${m.id}"${i === 0 ? " checked" : ""}><span>${tile(m, "sm")}<span class="min-w-0"><strong>${esc(m.name)}</strong><small>${num(m.members)} ${m.members === 1 ? "member" : "members"}</small></span></span></label>`).join("")}</div>`,
      })}</div>`,
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="atGo"><i class="ri-check-line me-1"></i>Add them</button>`,
    });
    el.querySelector("#atGo").addEventListener("click", () => {
      const id = Number(el.querySelector('input[name="atMinistry"]:checked').value);
      const m = list.find((x) => x.id === id);
      submit(
        el,
        () => MinistriesAPI.addMembers(id, ids),
        (d) => {
          onDone?.(d);
          return {
            title: `${d.added} added to ${m.name}`,
            facts: d.already ? [{ icon: "ri-information-line", text: `${d.already} were already in it`, color: "warning" }] : [],
            actions: [{ label: "Done", icon: "ri-check-line", primary: true, run: () => close(el) }],
          };
        },
      );
    });
  }

  /** The ministries someone serves in, for their page. */
  function personCard(person, ctx) {
    const list = person.ministries || [];
    return `<div class="card custom-card">
      <div class="card-header"><div class="card-title">Ministries</div></div>
      <div class="card-body">${
        list.length
          ? `<ul class="mb-mini-list">${list.map((m) => `<li>${tile(m, "sm")}<div class="flex-fill min-w-0">${ctx.ministriesUrl ? `<a class="fw-semibold mb-link" href="${ctx.ministriesUrl}/ministry?id=${m.id}">${esc(m.name)}</a>` : `<strong>${esc(m.name)}</strong>`}</div></li>`).join("")}</ul>`
          : `<p class="mb-0 fw-semibold">Not in a ministry yet.</p>`
      }</div>
    </div>`;
  }

  // windowEl, submit, step, field, affix and close are shared with Facilities' windows.
  return { DAYS, esc, textOn, num, initials, tile, day, nextLabel, average, cardBody, card, windowEl, submit, step, field, affix, close, options, ministryWindow, meetsWindow, addMembersWindow, addToMinistryWindow, personCard };
})();

window.MinistriesUI = MinistriesUI;
