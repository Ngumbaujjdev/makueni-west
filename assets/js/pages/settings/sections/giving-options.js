/**
 * SETTINGS - Giving options & funds (docs/specs/accounting-spec.md, A11):
 *   Our giving options   - what givers here can give for: name, Pay Bill ending,
 *                          who sees it (this place / every place below), the
 *                          income account and fund it is booked to, icon, colour
 *   Standard options     - diocese only: the ones every place keeps for itself,
 *                          and which one counts when no ending is typed
 *   From above           - options and funds set up by the diocese or region,
 *                          each with "Show on our giving page"
 *   Our funds            - money kept apart: name, code, kept apart or not, reach
 * GET / PUT /settings/giving-options - one Save for all of it.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;
  const ICON_NAMES = {
    "ri-hand-heart-line": "Giving hand", "ri-gift-line": "Gift", "ri-star-smile-line": "Thanks", "ri-building-2-line": "Building", "ri-group-line": "People",
    "ri-heart-line": "Heart", "ri-hand-coin-line": "Coins", "ri-community-line": "Gathering", "ri-earth-line": "Missions", "ri-book-open-line": "Book",
    "ri-music-2-line": "Music", "ri-graduation-cap-line": "School", "ri-plant-line": "Growth", "ri-calendar-event-line": "Event", "ri-home-heart-line": "Home", "ri-service-line": "Service",
  };
  const LEVEL = { diocese: "Diocese", region: "Region", church: "Church" };

  let root = null;
  let data = null;
  let own = [];
  let standard = [];
  let funds = [];
  let hidden = new Set();
  let defaultKey = null;
  let snapshot = "";
  let can = false;

  const $ = (sel) => root.querySelector(sel);
  const isChurch = () => data.place.level === "church";
  const isDiocese = () => data.place.level === "diocese";
  const state = () => JSON.stringify({ own, standard, funds, hidden: [...hidden].sort(), defaultKey });
  const move = (list, i, d) => {
    const j = i + d;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
  };

  function swatches(name, current) {
    return `<div class="mn-swatches fs-swatches" role="radiogroup" aria-label="Colour">${data.colours
      .map((c) => `<label title="${esc(c)}"><input type="radio" name="${name}" value="${c}"${c === current ? " checked" : ""}${can ? "" : " disabled"}><span class="bg-${c}"></span></label>`)
      .join("")}</div>`;
  }

  const select = (attr, items, current, label) =>
    `<select class="form-select" ${attr}${can ? "" : " disabled"} aria-label="${esc(label)}">${items.map(([v, t, extra = ""]) => `<option value="${esc(v)}"${String(v) === String(current) ? " selected" : ""} ${extra}>${esc(t)}</option>`).join("")}</select>`;

  /** The funds an option can be kept in: General, standard and inherited ones, and ours (new ones by code). */
  function fundChoices() {
    const ours = funds.filter((f) => f.code).map((f) => [f.id ? `id:${f.id}` : `code:${f.code}`, `${f.name || f.code} (ours)`]);
    const others = data.fund_choices.filter((f) => !funds.some((x) => x.id === f.id) && f.code !== "GEN").map((f) => [`id:${f.id}`, f.owner ? `${f.name} (${f.owner.name})` : f.name]);
    return [["", "General (not kept apart)"], ...others, ...ours];
  }
  const fundValue = (o) => (o.fund_id ? `id:${o.fund_id}` : o.fund_code ? `code:${o.fund_code}` : "");

  // ------------------------------------------------------------------ an option row (ours or, at the diocese, a standard one)

  function optionRow(o, i, list) {
    const dis = can ? "" : "disabled";
    const std = list === "std";
    const example = `${data.place.code}${o.suffix || "…"}`;
    return `<div class="fs-row${o.is_active ? "" : " is-off"}" data-list="${list}" data-i="${i}">
      <span class="fs-row-tile bg-${o.colour}"><i class="${o.icon}"></i></span>
      <div class="flex-fill min-w-0">
        <div class="row g-2 align-items-end">
          <div class="col-lg-4 col-md-6"><label class="form-label fs-12 mb-1">Giving for</label><input class="form-control" data-f="label" maxlength="40" value="${esc(o.label)}" placeholder="e.g. Region conference" ${dis}><div class="invalid-feedback d-block" data-error-for="${std ? "std" : "purposes"}.${i}.label"></div></div>
          <div class="col-lg-2 col-md-6"><label class="form-label fs-12 mb-1">Pay Bill ending</label><input class="form-control text-uppercase" data-f="suffix" maxlength="6" value="${esc(o.suffix)}" placeholder="e.g. CONF" ${dis}><div class="invalid-feedback d-block" data-error-for="${std ? "std" : "purposes"}.${i}.suffix"></div></div>
          <div class="col-lg-3 col-md-6"><label class="form-label fs-12 mb-1">Booked to</label>${select(`data-f="account_id"`, data.accounts.map((a) => [a.id, `${a.code} ${a.name}`]), o.account_id, "Income account")}<div class="invalid-feedback d-block" data-error-for="${std ? "std" : "purposes"}.${i}.account_id"></div></div>
          <div class="col-lg-3 col-md-6"><label class="form-label fs-12 mb-1">Kept in</label>${select(`data-f="fund"`, fundChoices(), fundValue(o), "Fund")}<div class="invalid-feedback d-block" data-error-for="${std ? "std" : "purposes"}.${i}.fund_id"></div></div>
          ${!std && !isChurch() ? `<div class="col-lg-4 col-md-6"><label class="form-label fs-12 mb-1">Who can give for it</label>${select(`data-f="reach"`, [["self", `Only ${data.place.name}`], ["below", `Every ${data.place.level === "diocese" ? "region and church" : "church"} under ${data.place.name}`]], o.reach, "Who sees it")}</div>` : ""}
          <div class="col-lg-4 col-md-6"><label class="form-label fs-12 mb-1">Other words givers type <span class="fw-normal">(optional, comma between)</span></label><input class="form-control text-uppercase" data-f="words" value="${esc((o.words || []).join(", "))}" placeholder="e.g. CONFERENCE" ${dis}></div>
          <div class="col-lg-2 col-md-6"><label class="form-label fs-12 mb-1">Icon</label>${select(`data-f="icon"`, data.icons.map((ic) => [ic, ICON_NAMES[ic] || ic, `data-icon="${ic}" data-color="${o.colour}"`]), o.icon, "Icon")}</div>
          <div class="col-lg-2 col-md-6"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" data-f="is_active" id="ga${list}${i}"${o.is_active ? " checked" : ""} ${dis}><label class="form-check-label fs-12" for="ga${list}${i}">${o.is_active ? "On" : "Switched off"}</label></div></div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
          <span class="form-label fs-12 mb-0">Colour</span>${swatches(`gc${list}${i}`, o.colour)}
          <span class="soft-chip soft-primary"><i class="ri-smartphone-line me-1"></i>Account number ${esc(example)}</span>
          ${!std && !isChurch() && o.reach === "below" ? `<span class="mb-sub">at each church: its own code + ${esc(o.suffix || "the ending")}</span>` : ""}
          ${o.in_use ? `<span class="mb-sub">· given ${o.in_use} ${o.in_use === 1 ? "time" : "times"} - it can be switched off, not removed</span>` : ""}
          ${std ? `<label class="form-check ms-auto mb-0 fs-13"><input class="form-check-input" type="radio" name="gDefault" value="${esc(o.key)}"${o.key === defaultKey ? " checked" : ""}${o.key && can ? "" : " disabled"}> <span class="form-check-label">When no ending is typed</span></label>` : ""}
        </div>
      </div>
      ${can ? `<div class="fs-row-tools"><button type="button" class="btn btn-icon btn-sm btn-light border" data-up aria-label="Move up"${i ? "" : " disabled"}><i class="ri-arrow-up-s-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-down aria-label="Move down"${i === (std ? standard : own).length - 1 ? " disabled" : ""}><i class="ri-arrow-down-s-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-remove aria-label="Remove ${esc(o.label || "this option")}"${std && ["T", "O", "TH", "B", "K"].includes(o.key) ? ' disabled title="A standard option can be switched off, not removed"' : o.in_use ? ' title="Given before - it will be switched off"' : ""}><i class="ri-delete-bin-line"></i></button></div>` : ""}
    </div>`;
  }

  function drawOptions(list) {
    const box = $(list === "std" ? "#goStandard" : "#goOwn");
    if (!box) return;
    const rows = list === "std" ? standard : own;
    box.innerHTML = rows.length
      ? rows.map((o, i) => optionRow(o, i, list)).join("")
      : `<div class="settings-empty"><span class="avatar avatar-md bg-success text-white"><i class="ri-hand-coin-line"></i></span><div><b>No options of our own yet.</b><br>${isChurch() ? "Add one for something only this church gives for - a choir trip, a harambee..." : `Add one for ${esc(data.place.name)}'s own work - a conference, missions... - and choose whether its churches can give for it too.`}</div></div>`;
    box.querySelectorAll("select").forEach((s) => UI.enhanceSelect(s));
    box.querySelectorAll("select").forEach((s) => window.jQuery?.(s).on("change", () => onField(s)));
  }

  // ------------------------------------------------------------------ inherited options and funds

  function drawInherited() {
    const box = $("#goInherited");
    if (!box) return;
    const rows = data.inherited;
    box.innerHTML = rows.length
      ? `<div class="fs-rows">${rows
          .map(
            (o) => `<div class="fs-row align-items-center${hidden.has(o.id) ? " is-off" : ""}">
              <span class="fs-row-tile bg-${o.colour}"><i class="${o.icon}"></i></span>
              <div class="flex-fill min-w-0"><b>${esc(o.label)}</b><div class="mb-sub">${o.owner ? `From ${esc(o.owner.name)} - its money goes to ${esc(o.owner.name)}` : "Standard - what is given here is ours"} · account number <b>${esc(o.example)}</b></div></div>
              <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-show="${o.id}" id="gs${o.id}"${hidden.has(o.id) ? "" : " checked"}${can && !o.is_default ? "" : " disabled"}><label class="form-check-label fs-12" for="gs${o.id}">${o.is_default ? "Always shown" : "Show on our giving page"}</label></div>
            </div>`,
          )
          .join("")}</div>`
      : `<div class="mb-sub">Nothing set up above us.</div>`;
  }

  function drawFunds() {
    const box = $("#goFunds");
    const dis = can ? "" : "disabled";
    box.innerHTML =
      (funds.length
        ? funds
            .map(
              (f, i) => `<div class="fs-row${f.is_active ? "" : " is-off"}" data-fund="${i}">
                <span class="fs-row-tile bg-${f.is_restricted ? "warning" : "success"}"><i class="ri-safe-2-line"></i></span>
                <div class="row g-2 flex-fill align-items-end">
                  <div class="col-lg-4 col-md-6"><label class="form-label fs-12 mb-1">Fund</label><input class="form-control" data-f="name" maxlength="100" value="${esc(f.name)}" placeholder="e.g. Choir fund" ${dis}><div class="invalid-feedback d-block" data-error-for="funds.${i}.name"></div></div>
                  <div class="col-lg-2 col-md-6"><label class="form-label fs-12 mb-1">Short code</label><input class="form-control text-uppercase" data-f="code" maxlength="10" value="${esc(f.code)}" placeholder="e.g. CHOIR" ${dis}><div class="invalid-feedback d-block" data-error-for="funds.${i}.code"></div></div>
                  ${isChurch() ? "" : `<div class="col-lg-3 col-md-6"><label class="form-label fs-12 mb-1">Who can use it</label>${select(`data-f="reach"`, [["self", `Only ${data.place.name}`], ["below", "Every place under us"]], f.reach || "self", "Who can use it")}</div>`}
                  <div class="col-lg-${isChurch() ? 3 : 2} col-md-6"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" data-f="is_restricted" id="gr${i}"${f.is_restricted ? " checked" : ""} ${dis}><label class="form-check-label fs-12" for="gr${i}">Kept apart</label></div></div>
                  <div class="col-lg-${isChurch() ? 3 : 1} col-md-6"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" data-f="is_active" id="gf${i}"${f.is_active ? " checked" : ""} ${dis}><label class="form-check-label fs-12" for="gf${i}">On</label></div></div>
                </div>
                ${can ? `<div class="fs-row-tools"><button type="button" class="btn btn-icon btn-sm btn-light border" data-fremove="${i}" aria-label="Remove ${esc(f.name || "this fund")}"${f.in_use ? ' title="Used before - it will be switched off"' : ""}><i class="ri-delete-bin-line"></i></button></div>` : ""}
              </div>`,
            )
            .join("")
        : `<div class="settings-empty"><span class="avatar avatar-md bg-warning text-white"><i class="ri-safe-2-line"></i></span><div><b>No funds of our own.</b><br>Add one to keep money for something apart from the general money - and see it on its own in the statements.</div></div>`) +
      (data.funds.inherited.length
        ? `<div class="d-flex flex-wrap align-items-center gap-2 mt-3"><span class="form-label fs-12 mb-0">We can also use</span>${data.funds.inherited.map((f) => `<span class="soft-chip soft-${f.is_restricted ? "warning" : "success"}">${esc(f.name)}${f.owner ? ` · ${esc(f.owner.name)}` : ""}</span>`).join("")}</div>`
        : "");
    box.querySelectorAll("select").forEach((s) => {
      UI.enhanceSelect(s);
      window.jQuery?.(s).on("change", () => onField(s));
    });
  }

  // ------------------------------------------------------------------ wiring

  function changed() {
    SettingsHub.changed();
  }

  function itemOf(el) {
    const row = el.closest("[data-list], [data-fund]");
    if (!row) return [null, null];
    if (row.dataset.fund !== undefined) return [funds[Number(row.dataset.fund)], row];
    return [(row.dataset.list === "std" ? standard : own)[Number(row.dataset.i)], row];
  }

  function onField(el) {
    const [item, row] = itemOf(el);
    if (!item) return;
    const f = el.dataset.f;
    if (el.type === "radio" && el.name.startsWith("gc")) {
      item.colour = el.value;
      row.querySelector(".fs-row-tile").className = `fs-row-tile bg-${el.value}`;
    } else if (f === "is_active" || f === "is_restricted") {
      item[f] = el.checked;
      if (f === "is_active") {
        row.classList.toggle("is-off", !el.checked);
        el.nextElementSibling.textContent = el.checked ? "On" : "Switched off";
      } else row.querySelector(".fs-row-tile").className = `fs-row-tile bg-${el.checked ? "warning" : "success"}`;
    } else if (f === "fund") {
      const v = el.value;
      item.fund_id = v.startsWith("id:") ? Number(v.slice(3)) : null;
      item.fund_code = v.startsWith("code:") ? v.slice(5) : null;
    } else if (f === "icon") {
      item.icon = el.value;
      row.querySelector(".fs-row-tile i").className = el.value;
    } else if (f === "words") item.words = el.value.split(/[,\s]+/).map((w) => w.trim().toUpperCase()).filter(Boolean);
    else if (f === "suffix" || f === "code") {
      item[f] = el.value.replace(/[^A-Za-z0-9]/g, "").toUpperCase();
      if (f === "suffix") row.querySelector(".soft-chip").innerHTML = `<i class="ri-smartphone-line me-1"></i>Account number ${esc(data.place.code + (item.suffix || "…"))}`;
    } else if (f === "account_id") item.account_id = Number(el.value);
    else if (f === "reach") item.reach = el.value;
    else if (f) item[f] = el.value;
    changed();
  }

  function onInput(e) {
    const el = e.target;
    if (el.name === "gDefault") {
      defaultKey = el.value;
      return changed();
    }
    if (el.dataset.show) {
      el.checked ? hidden.delete(Number(el.dataset.show)) : hidden.add(Number(el.dataset.show));
      el.closest(".fs-row").classList.toggle("is-off", !el.checked);
      return changed();
    }
    if (el.tagName !== "SELECT") onField(el);
  }

  function onClick(e) {
    const b = e.target.closest("button");
    if (!b || b.disabled) return;
    const fund = b.dataset.fremove;
    if (fund !== undefined) {
      funds.splice(Number(fund), 1);
      drawFunds();
      drawOptions("own");
      drawOptions("std");
      return changed();
    }
    const row = b.closest("[data-list]");
    if (!row) return;
    const list = row.dataset.list === "std" ? standard : own;
    const i = Number(row.dataset.i);
    if (b.dataset.up !== undefined) move(list, i, -1);
    else if (b.dataset.down !== undefined) move(list, i, 1);
    else if (b.dataset.remove !== undefined) list.splice(i, 1);
    else return;
    drawOptions(row.dataset.list);
    changed();
  }

  function links() {
    return [
      { id: "card-go-own", label: "Our giving options" },
      ...(isDiocese() ? [{ id: "card-go-std", label: "Standard options" }] : [{ id: "card-go-above", label: "From above" }]),
      { id: "card-go-funds", label: "Our funds" },
    ];
  }

  const blankOption = () => ({ id: null, key: null, label: "", suffix: "", words: [], reach: isChurch() ? "self" : "below", account_id: (data.accounts.find((a) => a.code === "4020") || data.accounts[0] || {}).id,
    fund_id: null, fund_code: null, icon: "ri-hand-coin-line", colour: data.colours[(own.length + 3) % data.colours.length], is_active: true, in_use: 0 });

  function draw() {
    const who = isChurch() ? "this church" : data.place.name;
    root.innerHTML = `<div id="goWrap">
      ${can ? "" : `<div class="alert alert-primary d-flex align-items-center gap-2"><span class="avatar avatar-sm bg-primary text-white"><i class="ri-eye-line"></i></span><div><b>View only.</b> Your role can see the giving options and funds, but not change them.</div></div>`}
      ${F.card({ id: "card-go-own", title: "Our giving options", icon: "ri-hand-coin-line", colour: "success",
        sub: `What givers can give for that is ${esc(who)}'s own. Its money comes to ${esc(who)}'s books${isChurch() ? "" : " - even when given at a church under us"}. Members pay by Pay Bill with the account number shown, or on the giving page.`,
        actions: can ? '<button type="button" class="btn btn-primary btn-sm" id="goAddOption"><i class="ri-add-line me-1"></i>Add an option</button>' : "", body: '<div id="goOwn" class="fs-rows"></div><div class="invalid-feedback d-block" data-error-for="purposes"></div>' })}
      ${isDiocese()
        ? F.card({ id: "card-go-std", title: "Standard options", icon: "ri-hand-heart-line", colour: "primary", sub: "On every church's and region's giving page - and each place keeps what it collects. Tithe, Offering and the rest can be renamed or switched off, not removed.",
            actions: can ? '<button type="button" class="btn btn-outline-primary btn-sm" id="goAddStandard"><i class="ri-add-line me-1"></i>Add a standard option</button>' : "", body: '<div id="goStandard" class="fs-rows"></div><div class="invalid-feedback d-block" data-error-for="default"></div>' })
        : F.card({ id: "card-go-above", title: data.place.level === "region" ? "From the diocese" : "From the diocese and region", icon: "ri-git-merge-line", colour: "info", sub: "Options set up above us. Switch one off to leave it off our giving page - a payment with its account number still reaches the right books.", body: '<div id="goInherited"></div>' })}
      ${F.card({ id: "card-go-funds", title: "Our funds", icon: "ri-safe-2-line", colour: "warning", sub: "Money kept for one purpose, apart from the general money - shown on its own in the statements. Pick it on a giving option, a receipt or a payment.",
        actions: can ? '<button type="button" class="btn btn-primary btn-sm" id="goAddFund"><i class="ri-add-line me-1"></i>Add a fund</button>' : "", body: '<div id="goFunds" class="fs-rows"></div>' })}
    </div>`;
    drawOptions("own");
    drawOptions("std");
    drawInherited();
    drawFunds();
    const wrap = $("#goWrap");
    wrap.addEventListener("input", onInput);
    wrap.addEventListener("change", (e) => {
      if (e.target.type === "radio" || e.target.type === "checkbox") return onInput(e);
      // A fund's name or code changed: the options' fund pickers list it.
      if (e.target.closest("[data-fund]") && ["code", "name"].includes(e.target.dataset.f)) drawOptions("own"), drawOptions("std");
    });
    wrap.addEventListener("click", onClick);
    $("#goAddOption")?.addEventListener("click", () => {
      own.push(blankOption());
      drawOptions("own");
      changed();
      root.querySelector("#goOwn [data-i]:last-child [data-f=label]")?.focus();
    });
    $("#goAddStandard")?.addEventListener("click", () => {
      standard.push({ ...blankOption(), reach: "below" });
      drawOptions("std");
      changed();
    });
    $("#goAddFund")?.addEventListener("click", () => {
      funds.push({ id: null, code: "", name: "", is_restricted: true, reach: isChurch() ? "self" : "below", is_active: true, in_use: 0 });
      drawFunds();
      changed();
      root.querySelector("[data-fund]:last-child [data-f=name]")?.focus();
    });
  }

  async function load(body) {
    root = body;
    const res = await SettingsAPI.givingOptions();
    if (!res.ok) {
      body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
      return false;
    }
    data = res.data;
    can = !!data.can?.update;
    const copy = (o) => ({ ...o, words: [...(o.words || [])], fund_code: null });
    own = data.own.map(copy);
    standard = (data.standard || []).map(copy);
    defaultKey = (data.standard || []).find((o) => o.is_default)?.key || null;
    hidden = new Set(data.inherited.filter((o) => !o.shown).map((o) => o.id));
    funds = data.funds.own.map((f) => ({ id: f.id, code: f.code, name: f.name, is_restricted: !!f.is_restricted, reach: f.reach || "self", is_active: f.is_active !== false, in_use: Number(data.own_counts?.[f.id] || 0) }));
    draw();
    snapshot = state();
    SettingsHub.subLinks(links());
    return true;
  }

  const body = (o) => ({ id: o.id, label: (o.label || "").trim(), suffix: o.suffix, words: o.words, reach: o.reach, account_id: o.account_id, fund_id: o.fund_id, fund_code: o.fund_code, icon: o.icon, colour: o.colour, is_active: !!o.is_active });

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.givingoptions = {
    render: (b) => load(b),
    isDirty() {
      return root && data && state() !== snapshot ? 1 : 0;
    },
    async discard() {
      await load(root);
    },
    async save() {
      root.querySelectorAll("[data-error-for]").forEach((e) => (e.textContent = ""));
      const payload = {
        purposes: own.map(body),
        funds: funds.map((f) => ({ id: f.id, code: f.code, name: (f.name || "").trim(), is_restricted: !!f.is_restricted, reach: f.reach, is_active: !!f.is_active })),
        hidden: [...hidden],
        ...(isDiocese() ? { standard: standard.map(body), default: defaultKey } : {}),
      };
      const res = await SettingsAPI.saveGivingOptions(payload);
      if (!res.ok) {
        let shown = false;
        Object.entries(res.errors || {}).forEach(([k, msgs]) => {
          const e = root.querySelector(`[data-error-for="${CSS.escape(k)}"]`);
          if (e) (e.textContent = [].concat(msgs)[0]), (shown = true);
        });
        Toast.error(shown ? res.message : Object.values(res.errors || {}).flat()[0] || res.message, { title: "Giving options not saved" });
        return false;
      }
      await load(root);
      Toast.success("Giving options and funds saved.");
      return true;
    },
  };
})();
