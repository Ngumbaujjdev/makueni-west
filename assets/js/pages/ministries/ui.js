/**
 * ============================================================================
 * MINISTRIES - shared look and windows (P4)
 * ============================================================================
 * A ministry's tile and card (its own icon and colour), when it meets, and
 * the windows - Add / Edit a ministry, When it meets (for its own leader),
 * Add members (tick many from the register) and Add to a ministry (from the
 * members list) - as navy windows in titled parts (PeopleKit).
 * ============================================================================
 */
const MinistriesUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");
  const num = (n) => Number(n || 0).toLocaleString("en-GB");
  const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

  const tile = (m, size = "md") => `<span class="avatar avatar-${size} avatar-rounded bg-${m.colour} ${textOn(m.colour)} flex-shrink-0"><i class="${m.icon}"></i></span>`;
  const day = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "-");
  const todayIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  };
  /** "Today", "Tomorrow", "Sat 11 Oct". */
  const nextLabel = (iso) => {
    if (!iso) return null;
    const d = Math.round((new Date(`${iso}T12:00:00`) - new Date(`${todayIso()}T12:00:00`)) / 86400000);
    if (d === 0) return "Today";
    if (d === 1) return "Tomorrow";
    return new Date(`${iso}T12:00:00`).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
  };
  /** The average of the months it met in, or null. */
  const average = (series) => {
    const met = (series || []).filter(Boolean);
    return met.length ? Math.round(met.reduce((a, b) => a + b, 0) / met.length) : null;
  };
  const leaderLine = (m) => {
    const lead = m.leaders.filter((l) => l.role === "leader");
    const show = (lead.length ? lead : m.leaders).slice(0, 2);
    return show.length ? show.map((l) => esc(l.name)).join(", ") + (m.leaders.length > show.length ? ` +${m.leaders.length - show.length}` : "") : "No leader yet";
  };

  /** A ministry on the Ministries page: its tile, when it meets, members, attendance and leaders. */
  function card(m, ctx) {
    const avg = average(m.attendance_series);
    const facts = [
      { label: "Members", value: num(m.members) },
      { label: "Average", value: avg === null ? "-" : num(avg), sub: !m.gathering_type ? "Not linked" : avg === null ? "None recorded" : "each time" },
      { label: "Next", value: m.next_meeting ? nextLabel(m.next_meeting) : "-", sub: m.next_meeting ? "" : "No set day" },
    ];
    const spark = m.gathering_type && m.attendance_series.some(Boolean) ? `<div class="mn-card-spark" data-spark='${JSON.stringify({ labels: ctx.months, data: m.attendance_series }).replace(/'/g, "&#39;")}' data-spark-color="${m.colour}" data-spark-height="36"></div>` : "";
    return `<div class="col-xxl-4 col-lg-6">
      <a class="card custom-card mn-card h-100${m.active ? "" : " is-off"}" href="${ctx.baseUrl}/ministry?id=${m.id}">
        <div class="card-body">
          <div class="d-flex align-items-start gap-3">
            ${tile(m)}
            <div class="flex-fill min-w-0">
              <h6 class="ev-card-title mb-0">${esc(m.name)}</h6>
              <div class="ev-card-meta mt-1"><span><i class="ri-repeat-line"></i>${esc(m.meets || "No set day")}</span>${m.gathering_type ? `<span><i class="ri-links-line"></i>${esc(m.gathering_type.name)}</span>` : ""}</div>
            </div>
            ${!m.active ? '<span class="badge bg-secondary text-dark">Switched off</span>' : m.mine ? '<span class="soft-chip soft-success"><i class="ri-star-smile-line"></i>You lead it</span>' : ""}
          </div>
          <div class="mn-facts">${facts.map((f) => `<div><span>${f.label}</span><strong>${f.value}</strong>${f.sub ? `<small>${f.sub}</small>` : ""}</div>`).join("")}</div>
          <div class="mn-card-foot">
            <div class="d-flex align-items-center gap-2 min-w-0">
              ${m.leaders.length ? `<span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(m.leaders[0].name)} text-white flex-shrink-0">${esc(m.leaders[0].initials)}</span>` : '<i class="ri-user-star-line mn-no-leader"></i>'}
              <span class="mn-card-leaders">${leaderLine(m)}</span>
            </div>
            ${spark}
          </div>
        </div>
      </a>
    </div>`;
  }

  // ---------------------------------------------------------------- windows
  function windowEl({ id = "mnModal", title, subtitle = "", icon, danger = false, size = "", body, foot }) {
    document.getElementById(id)?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal${danger ? " is-danger" : ""}" id="${id}" tabindex="-1" aria-labelledby="${id}Title">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ${size}"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon"><i class="${icon}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="${id}Title">${esc(title)}</h5>${subtitle ? `<div class="app-modal-subtitle">${esc(subtitle)}</div>` : ""}</div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">${body}</div>
          <div class="modal-footer">${foot}</div>
        </div></div>
      </div>`,
    );
    const el = document.getElementById(id);
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }
  async function submit(el, btn, call, done) {
    UI.setButtonLoading(btn, "Saving...");
    const res = await call();
    UI.restoreButton(btn);
    if (!res.ok) return Toast.error(res.message);
    bootstrap.Modal.getInstance(el)?.hide();
    Toast.success(res.message);
    done?.(res.data);
  }

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

  const meetsPart = (m) => ({
    icon: "ri-repeat-line",
    title: "When it meets",
    hint: "Optional",
    body: `<div class="row g-3">
      <div class="col-sm-7"><label class="form-label" for="mnDay">Day</label><select class="form-select" id="mnDay"><option value="">No set day</option>${DAYS.map((d, i) => `<option value="${i}"${m?.meets_day === i ? " selected" : ""}>Every ${d}</option>`).join("")}</select></div>
      <div class="col-sm-5"><label class="form-label" for="mnTime">Time</label><input type="time" class="form-control" id="mnTime" value="${m?.meets_time || ""}"></div>
    </div>`,
  });
  const readMeets = (el) => ({ meets_day: el.querySelector("#mnDay").value === "" ? null : Number(el.querySelector("#mnDay").value), meets_time: el.querySelector("#mnTime").value || null });

  /** When it meets - all a ministry's own leader may change. */
  function meetsWindow(m, { onDone } = {}) {
    const el = windowEl({
      title: "When it meets",
      subtitle: m.name,
      icon: "ri-repeat-line",
      body: PeopleKit.parts([meetsPart(m)]),
      foot: `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="mnSave"><i class="ri-check-line me-1"></i>Save</button>`,
    });
    UI.enhanceSelect(el.querySelector("#mnDay"), { search: false });
    el.querySelector("#mnSave").addEventListener("click", (e) => submit(el, e.currentTarget, () => MinistriesAPI.update(m.id, readMeets(el)), onDone));
  }

  /**
   * Add a ministry, or edit one: name and kind, its look, when it meets, the
   * gathering its attendance is under, and its leaders (church leaders with
   * a login, or people already serving in it).
   */
  async function ministryWindow({ ministry = null, members = [], onDone } = {}) {
    const o = await options();
    if (!o) return;
    const m = ministry;
    const kind = m?.kind || "other";
    const look = { icon: m?.icon || o.kinds.find((k) => k.key === kind)?.icon || "ri-team-line", colour: m?.colour || o.kinds.find((k) => k.key === kind)?.color || "teal" };
    const kindChoice = (k) => `<label class="ec-choice"><input type="radio" name="mnKind" value="${k.key}"${k.key === kind ? " checked" : ""}${m?.standard ? " disabled" : ""}><span class="ec-choice-icon"><i class="${k.icon}"></i></span><strong>${esc(k.label)}</strong><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`;
    const leaderOptions = (sel) =>
      `<option value="">Pick someone</option><optgroup label="Church leaders (with a login)">${o.leaders.map((u) => `<option value="u${u.id}"${sel === `u${u.id}` ? " selected" : ""} data-color="${UI.colorFor(u.name)}">${esc(u.name)}</option>`).join("")}</optgroup>${
        members.length ? `<optgroup label="Serving in it">${members.map((p) => `<option value="p${p.id}"${sel === `p${p.id}` ? " selected" : ""} data-color="${UI.colorFor(p.name)}">${esc(p.name)}</option>`).join("")}</optgroup>` : ""
      }`;
    const leaderRow = (l = null) => `<div class="mn-leader-row" data-leader>
        <select class="form-select" data-who aria-label="Leader">${leaderOptions(l ? (l.user_id ? `u${l.user_id}` : `p${l.person_id}`) : "")}</select>
        <select class="form-select" data-role aria-label="Role">${o.roles.map((r) => `<option value="${r.key}"${(l?.role || "leader") === r.key ? " selected" : ""}>${esc(r.label)}</option>`).join("")}</select>
        <button type="button" class="btn btn-icon btn-light border" data-drop aria-label="Remove this leader"><i class="ri-close-line"></i></button>
      </div>`;
    const el = windowEl({
      title: m ? "Edit ministry" : "Add a ministry",
      subtitle: m ? m.name : "Youth, a choir, ushers - any group that serves",
      icon: m ? "ri-edit-line" : "ri-team-line",
      size: "modal-lg",
      body: PeopleKit.parts([
        {
          icon: "ri-team-line",
          title: "Name and kind",
          body: `<label class="form-label" for="mnName">Name</label><input class="form-control mb-3" id="mnName" maxlength="80" value="${esc(m?.name || "")}" placeholder="e.g. Ushers">
            <div class="ec-choices" role="radiogroup" aria-label="Kind">${o.kinds.map(kindChoice).join("")}</div>
            ${m?.standard ? '<div class="form-text">One every church has - it keeps its kind.</div>' : ""}`,
        },
        {
          icon: "ri-palette-line",
          title: "Look",
          hint: "Its icon and colour",
          body: `<div class="mn-preview" id="mnPreview"></div>
            <div class="mn-icon-pick" role="radiogroup" aria-label="Icon">${o.icons.map((i) => `<label><input type="radio" name="mnIcon" value="${i}"${i === look.icon ? " checked" : ""}><span><i class="${i}"></i></span></label>`).join("")}</div>
            <div class="mn-swatches" role="radiogroup" aria-label="Colour">${o.colours.map((c) => `<label title="${c}"><input type="radio" name="mnColour" value="${c}"${c === look.colour ? " checked" : ""}><span class="bg-${c}"></span></label>`).join("")}</div>`,
        },
        meetsPart(m),
        {
          icon: "ri-bar-chart-box-line",
          title: "Attendance",
          hint: "Its gathering in Attendance",
          body: `<select class="form-select" id="mnGathering" aria-label="Gathering type"><option value="">Not linked</option>${o.gathering_types.map((g) => `<option value="${g.id}"${m?.gathering_type?.id === g.id ? " selected" : ""}>${esc(g.name)}${g.category ? ` · ${esc(g.category)}` : ""}</option>`).join("")}</select>
            <div class="form-text">Its numbers come from the attendance recorded under this gathering - nothing is counted twice.</div>`,
        },
        {
          icon: "ri-user-star-line",
          title: "Leaders",
          hint: "A leader with a login can look after its members",
          body: `<div id="mnLeaders">${(m?.leaders || []).map(leaderRow).join("")}</div><button type="button" class="btn btn-sm btn-light border mt-2" id="mnAddLeader"><i class="ri-add-line me-1"></i>Add a leader</button>`,
        },
        ...(m
          ? [
              {
                icon: "ri-toggle-line",
                title: "Running",
                body: `<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="mnActive"${m.active ? " checked" : ""}><label class="form-check-label" for="mnActive">It is running - switch off to take it off the page (its people and history stay)</label></div>`,
              },
            ]
          : []),
      ]),
      foot: `${m && !m.standard ? '<button type="button" class="btn btn-outline-danger me-auto" id="mnRemove"><i class="ri-delete-bin-line me-1"></i>Remove</button>' : ""}<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="mnSave"><i class="ri-check-line me-1"></i>${m ? "Save" : "Add it"}</button>`,
    });
    const enhanceRow = (row) => {
      UI.enhanceSelect(row.querySelector("[data-who]"), { placeholder: "Pick someone" });
      UI.enhanceSelect(row.querySelector("[data-role]"), { search: false });
    };
    UI.enhanceSelect(el.querySelector("#mnDay"), { search: false });
    UI.enhanceSelect(el.querySelector("#mnGathering"));
    el.querySelectorAll("[data-leader]").forEach(enhanceRow);
    const preview = () => {
      const p = { icon: el.querySelector('input[name="mnIcon"]:checked').value, colour: el.querySelector('input[name="mnColour"]:checked').value };
      el.querySelector("#mnPreview").innerHTML = `${tile(p)}<strong>${esc(el.querySelector("#mnName").value.trim() || "Your ministry")}</strong>`;
    };
    el.querySelectorAll('input[name="mnIcon"], input[name="mnColour"]').forEach((r) => r.addEventListener("change", preview));
    el.querySelector("#mnName").addEventListener("input", preview);
    // A kind picked for a new ministry brings its usual icon and colour.
    el.querySelectorAll('input[name="mnKind"]').forEach((r) =>
      r.addEventListener("change", () => {
        const k = o.kinds.find((x) => x.key === r.value);
        const icon = el.querySelector(`input[name="mnIcon"][value="${k.icon}"]`);
        const colour = el.querySelector(`input[name="mnColour"][value="${k.color}"]`);
        if (icon) icon.checked = true;
        if (colour) colour.checked = true;
        preview();
      }),
    );
    preview();
    el.querySelector("#mnAddLeader").addEventListener("click", () => {
      el.querySelector("#mnLeaders").insertAdjacentHTML("beforeend", leaderRow());
      enhanceRow(el.querySelector("#mnLeaders").lastElementChild);
    });
    el.querySelector("#mnLeaders").addEventListener("click", (e) => e.target.closest("[data-drop]")?.closest("[data-leader]").remove());
    // Remove asks once more, in the footer - not a second window on top.
    el.querySelector("#mnRemove")?.addEventListener("click", () => {
      const foot = el.querySelector(".modal-footer");
      const was = foot.innerHTML;
      foot.innerHTML = `<span class="me-auto fw-semibold text-danger">Remove ${esc(m.name)}? Its members stay in the register.</span><button type="button" class="btn btn-light border" data-keep>Keep it</button><button type="button" class="btn btn-danger" data-yes><i class="ri-delete-bin-line me-1"></i>Yes, remove</button>`;
      foot.querySelector("[data-keep]").addEventListener("click", () => {
        foot.innerHTML = was;
        bindSave();
      });
      foot.querySelector("[data-yes]").addEventListener("click", (e) => submit(el, e.currentTarget, () => MinistriesAPI.remove(m.id), () => (window.location.href = `${window.MIN_CTX.baseUrl}/`)));
    });
    const bindSave = () => el.querySelector("#mnSave").addEventListener("click", save);
    bindSave();
    function save(e) {
      const name = el.querySelector("#mnName").value.trim();
      if (!name) return Toast.error("Give it a name.");
      const leaders = [...el.querySelectorAll("[data-leader]")]
        .map((r) => ({ who: r.querySelector("[data-who]").value, role: r.querySelector("[data-role]").value }))
        .filter((l) => l.who)
        .map((l) => (l.who.startsWith("u") ? { user_id: Number(l.who.slice(1)), role: l.role } : { person_id: Number(l.who.slice(1)), role: l.role }));
      const body = {
        name,
        icon: el.querySelector('input[name="mnIcon"]:checked').value,
        colour: el.querySelector('input[name="mnColour"]:checked').value,
        gathering_type_id: el.querySelector("#mnGathering").value ? Number(el.querySelector("#mnGathering").value) : null,
        leaders,
        ...readMeets(el),
      };
      if (!m?.standard) body.kind = el.querySelector('input[name="mnKind"]:checked').value;
      if (m) body.active = el.querySelector("#mnActive").checked;
      submit(el, e.currentTarget, () => (m ? MinistriesAPI.update(m.id, body) : MinistriesAPI.create(body)), onDone);
    }
  }

  /** Add members: tick many from the register (members and visitors not in it yet). */
  async function addMembersWindow(m, { onDone } = {}) {
    const picked = new Set();
    let filter = "all";
    const FILTERS = [
      { key: "all", label: "Everyone", test: () => true },
      { key: "main_church", label: "Main church", test: (p) => p.congregation === "main_church" },
      { key: "sunday_school", label: "Sunday school", test: (p) => p.congregation === "sunday_school" },
      { key: "female", label: "Women", test: (p) => p.gender === "female" },
      { key: "male", label: "Men", test: (p) => p.gender === "male" },
      { key: "visitor", label: "Visitors", test: (p) => p.kind === "visitor" },
    ];
    const el = windowEl({
      title: "Add members",
      subtitle: m.name,
      icon: "ri-user-add-line",
      size: "modal-lg",
      body: PeopleKit.parts([
        {
          icon: "ri-search-line",
          title: "Find them",
          hint: "Tick as many as you like",
          body: `<div class="pp-picker-search"><i class="ri-search-line"></i><input type="search" class="form-control" id="amSearch" placeholder="Name or area" autocomplete="off" aria-label="Search the register"></div>
            <div class="mn-filter-row" id="amFilters">${FILTERS.map((f) => `<button type="button" class="pp-pill${f.key === "all" ? " is-on" : ""}" style="--q: var(--primary-rgb)" data-f="${f.key}">${esc(f.label)}</button>`).join("")}</div>
            <div class="pp-picker-list mn-pick-list" id="amList"><div class="pp-picker-none">Loading the register...</div></div>`,
        },
      ]),
      foot: `<span class="me-auto fw-semibold" id="amCount">Nobody picked yet</span><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="amSave" disabled><i class="ri-check-line me-1"></i>Add them</button>`,
    });
    const res = await MinistriesAPI.candidates(m.id);
    if (!res.ok) return (el.querySelector("#amList").innerHTML = `<div class="pp-picker-none">${esc(res.message)}</div>`);
    const people = res.data;
    const paint = () => {
      const q = el.querySelector("#amSearch").value.trim().toLowerCase();
      const f = FILTERS.find((x) => x.key === filter);
      const list = people.filter((p) => f.test(p) && (!q || `${p.name} ${p.area || ""}`.toLowerCase().includes(q)));
      el.querySelector("#amList").innerHTML = list.length
        ? list
            .slice(0, 300)
            .map((p) => `<label class="pp-picker-row${picked.has(p.id) ? " is-checked" : ""}"><input type="checkbox" class="form-check-input mt-0" data-pick="${p.id}"${picked.has(p.id) ? " checked" : ""}><span class="flex-fill min-w-0"><strong>${esc(p.name)}</strong><small>${esc([p.kind === "visitor" ? "Visitor" : p.congregation === "sunday_school" ? "Sunday school" : "Member", p.area].filter(Boolean).join(" · "))}</small></span></label>`)
            .join("")
        : `<div class="pp-picker-none">${people.length ? "Nobody matches." : `Everyone in the register is already in ${esc(m.name)}.`}</div>`;
      el.querySelector("#amCount").textContent = picked.size ? `${picked.size} picked` : "Nobody picked yet";
      el.querySelector("#amSave").disabled = !picked.size;
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
      el.querySelectorAll("#amFilters [data-f]").forEach((x) => x.classList.toggle("is-on", x === b));
      paint();
    });
    el.querySelector("#amList").addEventListener("change", (e) => {
      const box = e.target.closest("[data-pick]");
      if (!box) return;
      box.checked ? picked.add(Number(box.dataset.pick)) : picked.delete(Number(box.dataset.pick));
      box.closest(".pp-picker-row").classList.toggle("is-checked", box.checked);
      el.querySelector("#amCount").textContent = picked.size ? `${picked.size} picked` : "Nobody picked yet";
      el.querySelector("#amSave").disabled = !picked.size;
    });
    el.querySelector("#amSave").addEventListener("click", (e) => submit(el, e.currentTarget, () => MinistriesAPI.addMembers(m.id, [...picked]), onDone));
  }

  /** From the members list: add the people ticked there to one ministry. */
  async function addToMinistryWindow(ids, { onDone } = {}) {
    const res = await MinistriesAPI.overview();
    if (!res.ok) return Toast.error(res.message);
    const list = res.data.items;
    const el = PeopleKit.confirmWindow({
      title: "Add to a ministry",
      subtitle: `${ids.length} ${ids.length === 1 ? "person" : "people"} picked`,
      icon: "ri-team-line",
      go: '<i class="ri-check-line me-1"></i>Add them',
      body: PeopleKit.parts([
        {
          icon: "ri-team-line",
          title: "Ministry",
          hint: "Anyone already in it stays as they are",
          body: `<select class="form-select" id="atMinistry" aria-label="Ministry">${list.map((m) => `<option value="${m.id}" data-icon="${m.icon}" data-color="${m.colour}">${esc(m.name)} · ${num(m.members)} ${m.members === 1 ? "member" : "members"}</option>`).join("")}</select>`,
        },
      ]),
      run: async () => {
        const r = await MinistriesAPI.addMembers(Number(document.getElementById("atMinistry").value), ids);
        if (r.ok) onDone?.(r.data);
        return r;
      },
    });
    UI.enhanceSelect(el.querySelector("#atMinistry"));
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

  return { DAYS, esc, textOn, num, tile, day, nextLabel, average, card, windowEl, options, ministryWindow, meetsWindow, addMembersWindow, addToMinistryWindow, personCard };
})();

window.MinistriesUI = MinistriesUI;
