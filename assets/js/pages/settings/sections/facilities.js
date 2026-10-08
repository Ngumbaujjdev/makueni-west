/**
 * SETTINGS - Facilities (docs/specs/people-and-care-spec.md, P5 round 3):
 * the one place to set a church's facilities up.
 *   Rooms              - name, how many it holds, colour, can be booked, order
 *                        (the /rooms routes)
 *   Duties and teams   - each duty, how many people each service needs, and
 *                        its team: people from the register or typed names
 *   Kinds of equipment - name, icon, colour (a kind in use can't go)
 *   then the section's fields (booking hours, lending, duty reminders) - the
 *   generic form, saved with the same Save.
 * Duties and kinds: GET / PUT /settings/facilities-setup.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;
  const ICON_NAMES = {
    "ri-door-open-line": "Door", "ri-hand-heart-line": "Welcome", "ri-mic-line": "Microphone", "ri-shield-check-line": "Shield", "ri-brush-line": "Brush",
    "ri-music-2-line": "Music", "ri-camera-line": "Camera", "ri-car-line": "Car", "ri-parking-box-line": "Parking", "ri-first-aid-kit-line": "First aid",
    "ri-cup-line": "Cup", "ri-book-open-line": "Book", "ri-group-line": "People", "ri-heart-line": "Heart", "ri-table-line": "Table", "ri-restaurant-line": "Kitchen",
    "ri-computer-line": "Computer", "ri-archive-line": "Box", "ri-tools-line": "Tools", "ri-plant-line": "Plant", "ri-gift-line": "Gift", "ri-flashlight-line": "Torch",
  };

  let root = null;
  let data = null;
  let rooms = [];
  let duties = [];
  let kinds = [];
  let roomColours = [];
  let snapshot = "";
  let form = null;
  let can = false;
  let canRooms = false;

  const $ = (sel) => root.querySelector(sel);
  const state = () => JSON.stringify({ rooms: rooms.map(({ _key, ...r }) => r), duties, kinds });

  const SIX = ["primary", "success", "purple", "pink", "warning", "danger"];

  function swatches(name, list, current, label) {
    list = list === data.colours ? [...SIX, ...(SIX.includes(current) ? [] : [current])] : list;
    return `<div class="mn-swatches fs-swatches" role="radiogroup" aria-label="${esc(label)}">${list
      .map((c) => `<label title="${esc(c)}"><input type="radio" name="${name}" value="${c}"${c === current ? " checked" : ""}${can ? "" : " disabled"}><span class="bg-${c}"></span></label>`)
      .join("")}</div>`;
  }

  function iconSelect(attr, current) {
    return `<select class="form-select" ${attr}${can ? "" : " disabled"} aria-label="Icon">${data.icons.map((i) => `<option value="${i}" data-icon="${i}" data-color="primary"${i === current ? " selected" : ""}>${esc(ICON_NAMES[i] || i)}</option>`).join("")}</select>`;
  }

  const move = (list, i, d) => {
    const j = i + d;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
  };

  // ------------------------------------------------------------------ rooms

  function drawRooms() {
    const box = $("#fsRooms");
    const dis = canRooms ? "" : "disabled";
    box.innerHTML = rooms.length
      ? rooms
          .map(
            (r, i) => `<div class="fs-row" data-room="${i}">
              <span class="fs-row-tile bg-${r.colour}"><i class="ri-door-open-line"></i></span>
              <div class="row g-2 flex-fill align-items-end">
                <div class="col-lg-4 col-md-6"><label class="form-label fs-12 mb-1">Room</label><input class="form-control" data-f="name" maxlength="80" value="${esc(r.name)}" placeholder="e.g. Fellowship hall" ${dis}><div class="invalid-feedback d-block" data-error-for="rooms.${i}"></div></div>
                <div class="col-lg-2 col-md-6"><label class="form-label fs-12 mb-1">Holds <span class="fw-normal">(people)</span></label><input type="number" class="form-control" data-f="capacity" min="1" value="${r.capacity ?? ""}" ${dis}></div>
                <div class="col-lg-4 col-md-8"><label class="form-label fs-12 mb-1">Colour on the calendar</label>${swatches(`rc${i}`, roomColours, r.colour, "Colour")}</div>
                <div class="col-lg-2 col-md-4"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" data-f="bookable" id="rb${i}"${r.bookable ? " checked" : ""} ${dis}><label class="form-check-label fs-12" for="rb${i}">Can be booked</label></div></div>
              </div>
              ${canRooms ? `<div class="fs-row-tools"><button type="button" class="btn btn-icon btn-sm btn-light border" data-up="${i}" aria-label="Move up"${i ? "" : " disabled"}><i class="ri-arrow-up-s-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-down="${i}" aria-label="Move down"${i === rooms.length - 1 ? " disabled" : ""}><i class="ri-arrow-down-s-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-remove="${i}" aria-label="Remove ${esc(r.name || "this room")}"><i class="ri-delete-bin-line"></i></button></div>` : ""}
            </div>`,
          )
          .join("")
      : `<div class="settings-empty"><span class="avatar avatar-md bg-primary text-white"><i class="ri-door-open-line"></i></span><div><b>No rooms yet.</b><br>Add the church's rooms to book them and to say where things are kept.</div></div>`;
  }

  // ------------------------------------------------------------------ duties

  function teamHtml(d, i) {
    return `<div class="fs-team">
      <div class="pp-chips">${d.team.length ? d.team.map((p, n) => `<span class="pp-chip"><span>${esc(p.name)}</span>${can ? `<button type="button" data-unteam="${i}:${n}" aria-label="Take ${esc(p.name)} off">&times;</button>` : ""}</span>`).join("") : '<span class="mb-sub">Nobody on this team yet</span>'}</div>
      ${can ? `<div class="pp-picker-search mt-2"><i class="ri-search-line"></i><input type="search" class="form-control" data-team-q="${i}" placeholder="Add someone - search the register, or type a name and press Enter" autocomplete="off"></div><div class="pp-picker-list" data-team-found="${i}"></div>` : ""}
    </div>`;
  }

  function drawDuties() {
    const box = $("#fsDuties");
    const dis = can ? "" : "disabled";
    box.innerHTML = duties.length
      ? duties
          .map(
            (d, i) => `<div class="fs-row fs-duty${d.active ? "" : " is-off"}" data-duty="${i}">
              <span class="fs-row-tile bg-${d.colour}"><i class="${d.icon}"></i></span>
              <div class="flex-fill min-w-0">
                <div class="row g-2 align-items-end">
                  <div class="col-lg-4 col-md-6"><label class="form-label fs-12 mb-1">Duty</label><input class="form-control" data-f="label" maxlength="40" value="${esc(d.label)}" placeholder="e.g. Media" ${dis}><div class="invalid-feedback d-block" data-error-for="duties.${i}.label"></div></div>
                  <div class="col-lg-2 col-md-6"><label class="form-label fs-12 mb-1">People each service</label><input type="number" class="form-control" data-f="needed" min="1" max="20" value="${d.needed}" ${dis}></div>
                  <div class="col-lg-3 col-md-6"><label class="form-label fs-12 mb-1">Icon</label>${iconSelect(`data-icon-for="duty:${i}"`, d.icon)}</div>
                  <div class="col-lg-3 col-md-6"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" data-f="active" id="da${i}"${d.active ? " checked" : ""} ${dis}><label class="form-check-label fs-12" for="da${i}">${d.active ? "In use" : "Switched off"}</label></div></div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2"><span class="form-label fs-12 mb-0">Colour</span>${swatches(`dc${i}`, data.colours, d.colour, "Colour")}</div>
                <div class="d-flex align-items-center gap-2 mt-2 mb-1"><span class="fw-semibold fs-13">Team</span><span class="soft-chip soft-${d.colour}">${d.team.length} ${d.team.length === 1 ? "person" : "people"}</span>${d.on_rota ? `<span class="mb-sub">· on the rota ${d.on_rota} ${d.on_rota === 1 ? "time" : "times"}</span>` : ""}</div>
                ${teamHtml(d, i)}
              </div>
              ${can ? `<div class="fs-row-tools"><button type="button" class="btn btn-icon btn-sm btn-light border" data-dup="${i}" aria-label="Move up"${i ? "" : " disabled"}><i class="ri-arrow-up-s-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-ddown="${i}" aria-label="Move down"${i === duties.length - 1 ? " disabled" : ""}><i class="ri-arrow-down-s-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-dremove="${i}" title="${d.on_rota ? "It is on the rota - it will be switched off" : "Remove"}" aria-label="Remove ${esc(d.label)}"><i class="ri-delete-bin-line"></i></button></div>` : ""}
            </div>`,
          )
          .join("")
      : `<div class="settings-empty"><span class="avatar avatar-md bg-purple text-white"><i class="ri-team-line"></i></span><div><b>No duties.</b><br>Add the duties people do at each service - ushering, welcome, sound...</div></div>`;
    box.querySelectorAll("select[data-icon-for]").forEach((s) => {
      UI.enhanceSelect(s);
      window.jQuery?.(s).on("change", () => {
        const i = Number(s.dataset.iconFor.split(":")[1]);
        duties[i].icon = s.value;
        s.closest(".fs-row").querySelector(".fs-row-tile i").className = s.value;
        changed();
      });
    });
    box.querySelectorAll("[data-team-q]").forEach((input) => {
      const i = Number(input.dataset.teamQ);
      const found = box.querySelector(`[data-team-found="${i}"]`);
      let t = null;
      let hits = [];
      const add = (p) => {
        const key = p.person_id ? `p${p.person_id}` : `n${p.name.toLowerCase()}`;
        if (!duties[i].team.some((x) => (x.person_id ? `p${x.person_id}` : `n${x.name.toLowerCase()}`) === key)) duties[i].team.push(p);
        drawDuties();
        changed();
        box.querySelector(`[data-team-q="${i}"]`)?.focus();
      };
      input.addEventListener("input", () => {
        clearTimeout(t);
        const q = input.value.trim();
        if (q.length < 2) return (found.innerHTML = "");
        t = setTimeout(async () => {
          const res = await SettingsAPI.facilityPeople(q);
          hits = res.ok ? res.data : [];
          found.innerHTML = `${hits.map((p, n) => `<button type="button" class="pp-picker-row w-100 border-0 text-start" data-hit="${n}"><span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(p.name)} text-white flex-shrink-0">${esc(p.initials || "")}</span><span class="flex-fill min-w-0"><strong>${esc(p.name)}</strong><small>${esc([p.kind === "visitor" ? "Visitor" : "Member", p.area].filter(Boolean).join(" · "))}</small></span><i class="ri-add-line"></i></button>`).join("")}<button type="button" class="pp-picker-row w-100 border-0 text-start" data-typed><span class="avatar avatar-sm avatar-rounded bg-light text-dark flex-shrink-0"><i class="ri-edit-2-line"></i></span><span class="flex-fill min-w-0"><strong>Use "${esc(q)}"</strong><small>Not in the register - just the name</small></span></button>`;
        }, 250);
      });
      input.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && input.value.trim().length >= 2) {
          e.preventDefault();
          add({ person_id: null, name: input.value.trim() });
        }
      });
      found.addEventListener("click", (e) => {
        const hit = e.target.closest("[data-hit]");
        if (hit) return add({ person_id: hits[Number(hit.dataset.hit)].id, name: hits[Number(hit.dataset.hit)].name });
        if (e.target.closest("[data-typed]")) add({ person_id: null, name: input.value.trim() });
      });
    });
  }

  // ------------------------------------------------------------------ kinds

  function drawKinds() {
    const box = $("#fsKinds");
    const dis = can ? "" : "disabled";
    box.innerHTML = kinds
      .map(
        (k, i) => `<div class="fs-row fs-kind" data-kind="${i}">
          <span class="fs-row-tile bg-${k.colour}"><i class="${k.icon}"></i></span>
          <div class="row g-2 flex-fill align-items-end">
            <div class="col-lg-4 col-md-6"><label class="form-label fs-12 mb-1 d-flex justify-content-between">Kind <span class="fw-normal">${k.items ? `${k.items} ${k.items === 1 ? "thing" : "things"}` : "Nothing yet"}</span></label><input class="form-control" data-f="label" maxlength="40" value="${esc(k.label)}" placeholder="e.g. Decorations" ${dis}><div class="invalid-feedback d-block" data-error-for="kinds.${i}.label"></div></div>
            <div class="col-lg-3 col-md-6"><label class="form-label fs-12 mb-1">Icon</label>${iconSelect(`data-icon-for="kind:${i}"`, k.icon)}</div>
            <div class="col-lg-5"><label class="form-label fs-12 mb-1">Colour</label>${swatches(`kc${i}`, data.colours, k.colour, "Colour")}</div>
          </div>
          ${can ? `<div class="fs-row-tools"><button type="button" class="btn btn-icon btn-sm btn-light border" data-kremove="${i}" aria-label="Remove ${esc(k.label)}"${k.items ? ` disabled title="${k.items} ${k.items === 1 ? "thing is" : "things are"} this kind - move them first"` : ""}><i class="ri-delete-bin-line"></i></button></div>` : ""}
        </div>`,
      )
      .join("");
    box.querySelectorAll("select[data-icon-for]").forEach((s) => {
      UI.enhanceSelect(s);
      window.jQuery?.(s).on("change", () => {
        const i = Number(s.dataset.iconFor.split(":")[1]);
        kinds[i].icon = s.value;
        s.closest(".fs-row").querySelector(".fs-row-tile i").className = s.value;
        changed();
      });
    });
  }

  // ------------------------------------------------------------------ wiring

  function changed() {
    SettingsHub.changed();
  }

  function onInput(e) {
    const el = e.target;
    const row = el.closest("[data-room], [data-duty], [data-kind]");
    if (!row) return;
    const list = row.dataset.room !== undefined ? rooms : row.dataset.duty !== undefined ? duties : kinds;
    const item = list[Number(row.dataset.room ?? row.dataset.duty ?? row.dataset.kind)];
    if (el.type === "radio") {
      item.colour = el.value;
      row.querySelector(".fs-row-tile").className = `fs-row-tile bg-${el.value}`;
    } else if (el.dataset.f === "bookable" || el.dataset.f === "active") {
      item[el.dataset.f] = el.checked;
      if (el.dataset.f === "active") {
        row.classList.toggle("is-off", !el.checked);
        el.nextElementSibling.textContent = el.checked ? "In use" : "Switched off";
      }
    } else if (el.dataset.f === "capacity") item.capacity = el.value ? Number(el.value) : null;
    else if (el.dataset.f === "needed") item.needed = Math.max(1, Number(el.value || 1));
    else if (el.dataset.f) item[el.dataset.f] = el.value;
    changed();
  }

  function onClick(e) {
    const b = e.target.closest("button");
    if (!b || b.disabled) return;
    const d = b.dataset;
    if (d.up !== undefined) move(rooms, Number(d.up), -1), drawRooms();
    else if (d.down !== undefined) move(rooms, Number(d.down), 1), drawRooms();
    else if (d.remove !== undefined) rooms.splice(Number(d.remove), 1), drawRooms();
    else if (d.dup !== undefined) move(duties, Number(d.dup), -1), drawDuties();
    else if (d.ddown !== undefined) move(duties, Number(d.ddown), 1), drawDuties();
    else if (d.dremove !== undefined) duties.splice(Number(d.dremove), 1), drawDuties();
    else if (d.unteam !== undefined) {
      const [i, n] = d.unteam.split(":").map(Number);
      duties[i].team.splice(n, 1);
      drawDuties();
    } else if (d.kremove !== undefined) kinds.splice(Number(d.kremove), 1), drawKinds();
    else return;
    changed();
  }

  function links() {
    return [
      { id: "card-rooms", label: "Rooms" },
      { id: "card-duties", label: "Duties and teams" },
      { id: "card-kinds", label: "Kinds of equipment" },
      ...[...root.querySelectorAll("#fsForm .card[id^='card-']")].filter((el) => !el.hidden).map((el) => ({ id: el.id, label: el.querySelector(".card-title")?.textContent.trim() || "" })),
    ];
  }

  function draw() {
    root.innerHTML = `<div id="fsWrap">
      ${can ? "" : `<div class="alert alert-primary d-flex align-items-center gap-2"><span class="avatar avatar-sm bg-primary text-white"><i class="ri-eye-line"></i></span><div><b>View only.</b> Your role can see how the facilities are set up, but not change it.</div></div>`}
      ${F.card({ id: "card-rooms", title: "Rooms", icon: "ri-door-open-line", colour: "primary", sub: "Booked by the hour, and where equipment is kept. A room with bookings to come can't be removed - switch off \"Can be booked\".", actions: canRooms ? '<button type="button" class="btn btn-primary btn-sm" id="fsAddRoom"><i class="ri-add-line me-1"></i>Add a room</button>' : "", body: '<div id="fsRooms" class="fs-rows"></div>' })}
      ${F.card({ id: "card-duties", title: "Duties and teams", icon: "ri-team-line", colour: "purple", sub: "The duties at each service and who does each - the rota picks from the team, and \"Fill from the teams\" takes turns.", actions: can ? '<button type="button" class="btn btn-primary btn-sm" id="fsAddDuty"><i class="ri-add-line me-1"></i>Add a duty</button>' : "", body: '<div id="fsDuties" class="fs-rows"></div><div class="invalid-feedback d-block" data-error-for="duties"></div>' })}
      ${F.card({ id: "card-kinds", title: "Kinds of equipment", icon: "ri-archive-line", colour: "success", sub: "How our things are grouped - on Equipment, What we own and the reports. A kind with things in it can't be removed.", actions: can ? '<button type="button" class="btn btn-primary btn-sm" id="fsAddKind"><i class="ri-add-line me-1"></i>Add a kind</button>' : "", body: '<div id="fsKinds" class="fs-rows"></div><div class="invalid-feedback d-block" data-error-for="kinds"></div>' })}
      </div>
      <div id="fsForm"></div>`;
    drawRooms();
    drawDuties();
    drawKinds();
    // On our own wrapper (drawn afresh each time), never on the hub's shared body.
    const wrap = $("#fsWrap");
    wrap.addEventListener("input", onInput);
    wrap.addEventListener("change", (e) => (e.target.type === "radio" || e.target.type === "checkbox" ? onInput(e) : null));
    wrap.addEventListener("click", onClick);
    $("#fsAddRoom")?.addEventListener("click", () => {
      rooms.push({ id: null, name: "", capacity: null, colour: roomColours[rooms.length % roomColours.length] || "primary", bookable: true, active: true });
      drawRooms();
      changed();
      root.querySelector("[data-room]:last-child [data-f=name]")?.focus();
    });
    $("#fsAddDuty")?.addEventListener("click", () => {
      duties.push({ key: null, label: "", icon: "ri-group-line", colour: data.colours[duties.length % data.colours.length], active: true, needed: 1, team: [], on_rota: 0 });
      drawDuties();
      changed();
      root.querySelector("[data-duty]:last-child [data-f=label]")?.focus();
    });
    $("#fsAddKind")?.addEventListener("click", () => {
      kinds.push({ key: null, label: "", icon: "ri-archive-line", colour: data.colours[kinds.length % data.colours.length], items: 0 });
      drawKinds();
      changed();
      root.querySelector("[data-kind]:last-child [data-f=label]")?.focus();
    });
  }

  async function load(body) {
    root = body;
    const [setup, opts] = await Promise.all([SettingsAPI.facilitiesSetup(), SettingsAPI.facilityOptions()]);
    if (!setup.ok) {
      body.innerHTML = `<div class="alert alert-danger">${esc(setup.message)}</div>`;
      return false;
    }
    data = setup.data;
    can = !!data.can?.update;
    canRooms = !!opts.ok && !!opts.data.can?.manage;
    roomColours = opts.ok ? opts.data.colours : ["primary"];
    rooms = (opts.ok ? opts.data.rooms : []).map((r) => ({ id: r.id, name: r.name, capacity: r.capacity, colour: r.colour, bookable: !!r.bookable, active: r.active !== false }));
    duties = data.duties.map((d) => ({ ...d, team: (d.team || []).map((p) => ({ person_id: p.person_id || null, name: p.name })) }));
    kinds = data.kinds.map((k) => ({ ...k }));
    data.rooms = JSON.parse(JSON.stringify(rooms));
    draw();
    snapshot = state();
    // The section's own fields, below - the generic form, saved with the same Save.
    form = SettingsFields.formSection("facilities");
    await form.render(root.querySelector("#fsForm"));
    SettingsHub.subLinks(links());
    // The form re-lists only its own cards when a field changes - keep ours too.
    root.querySelector("#fsForm").addEventListener("change", () => setTimeout(() => SettingsHub.subLinks(links()), 0));
    return true;
  }

  /** Rooms are saved one by one through /rooms: removed, then changed or new, in order. */
  async function saveRooms() {
    const before = new Map(data.rooms.map((r) => [r.id, r]));
    const errors = [];
    for (const r of data.rooms) {
      if (!rooms.some((x) => x.id === r.id)) {
        const res = await SettingsAPI.removeRoom(r.id);
        if (!res.ok) errors.push([-1, `${r.name}: ${res.message}`]);
      }
    }
    for (const [i, r] of rooms.entries()) {
      if (!r.name.trim()) {
        errors.push([i, "Give the room a name."]);
        continue;
      }
      const old = before.get(r.id);
      const body = { name: r.name.trim(), capacity: r.capacity || null, colour: r.colour, bookable: r.bookable, order: i };
      if (old && JSON.stringify({ name: old.name, capacity: old.capacity || null, colour: old.colour, bookable: old.bookable, order: data.rooms.indexOf(old) }) === JSON.stringify(body)) continue;
      const res = await SettingsAPI.saveRoom(r.id, body);
      if (!res.ok) errors.push([i, res.message]);
      else r.id = res.data.id;
    }
    return errors;
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.facilities = {
    render: (body) => load(body),
    isDirty() {
      if (!root || !data) return 0;
      return (state() !== snapshot ? 1 : 0) + (form?.isDirty() || 0);
    },
    async discard() {
      await load(root);
    },
    async save() {
      root.querySelectorAll("[data-error-for]").forEach((e) => (e.textContent = ""));
      let ok = true;
      if (state() !== snapshot) {
        if (canRooms) {
          const errors = await saveRooms();
          errors.forEach(([i, m]) => {
            const el = i >= 0 ? root.querySelector(`[data-error-for="rooms.${i}"]`) : null;
            el ? (el.textContent = m) : Toast.error(m);
          });
          if (errors.length) ok = false;
        }
        if (can) {
          const res = await SettingsAPI.saveFacilitiesSetup({
            duties: duties.map((d) => ({ key: d.key, label: d.label.trim(), icon: d.icon, colour: d.colour, active: !!d.active, needed: Number(d.needed) || 1, team: d.team })),
            kinds: kinds.map((k) => ({ key: k.key, label: k.label.trim(), icon: k.icon, colour: k.colour })),
          });
          if (!res.ok) {
            ok = false;
            Object.entries(res.errors || {}).forEach(([k, msgs]) => {
              const e = root.querySelector(`[data-error-for="${CSS.escape(k)}"]`);
              if (e) e.textContent = [].concat(msgs)[0];
            });
            Toast.error(res.message, { title: "Duties and kinds not saved" });
          }
        }
      }
      if (ok && form?.isDirty()) ok = (await form.save()) && ok;
      if (ok) {
        await load(root);
        Toast.success("Facilities saved.");
      }
      return ok;
    },
  };
})();
