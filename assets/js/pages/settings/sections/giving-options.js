/**
 * SETTINGS - Giving options & funds (docs/specs/accounting-spec.md, A11), in
 * plain words for a church treasurer:
 *   Our giving options   - what givers here can give for, as a clean list:
 *                          the account number givers type, what kind of money
 *                          it counts as, whether it is kept apart. Each is
 *                          added or changed in a window; the Pay Bill ending,
 *                          other words, icon and colour sit under "More".
 *   Standard options     - diocese only: the ones every place keeps for itself
 *   From above           - options set up by the diocese or region, each with
 *                          "Show on our giving page"
 *   Our funds            - money kept apart for one purpose
 * GET / PUT /settings/giving-options - the window saves at once; switches and
 * order changes go with the page's Save bar.
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
  const GROUPS = ["Giving", "Fundraising", "Other income", "Between places"];

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
  const account = (id) => data.accounts.find((a) => String(a.id) === String(id));
  const fundOf = (o) => (o.fund_id ? data.fund_choices.find((f) => f.id === o.fund_id) || funds.find((f) => f.id === o.fund_id) : o.fund_code ? funds.find((f) => f.code === o.fund_code) : null);
  const takenEndings = (except) => new Set([...own, ...standard, ...data.inherited].filter((o) => o !== except).flatMap((o) => [o.suffix, ...(o.words || [])]).filter(Boolean).map((s) => String(s).toUpperCase()));

  /** A Pay Bill ending from the name: its initials, or its first letters - one nobody else here uses. */
  function suggestEnding(label, except) {
    const words = String(label || "").toUpperCase().replace(/[^A-Z ]/g, " ").split(/\s+/).filter(Boolean);
    if (!words.length) return "";
    const taken = takenEndings(except);
    const tries = [words.length > 1 ? words.map((w) => w[0]).join("").slice(0, 6) : "", words[0].slice(0, 4), words.join("").slice(0, 6), words[0].slice(0, 6)];
    return tries.find((t) => t.length >= 2 && !taken.has(t) && !t.endsWith("DS")) || "";
  }
  const suggestCode = (name) => String(name || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);

  // ------------------------------------------------------------------ the window

  function windowEl() {
    let el = document.getElementById("goModal");
    if (el) return el;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="goModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="goModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down modal-lg">
          <div class="modal-content">
            <div class="modal-header"><span class="app-modal-icon bg-success" id="goModalIcon"><i class="ri-hand-coin-line"></i></span><div class="flex-fill" style="min-width:0"><h5 class="modal-title" id="goModalTitle"></h5><div class="app-modal-subtitle" id="goModalSub"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body" id="goModalBody"></div>
            <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="goModalGo"><i class="ri-check-line me-1"></i>Save</button></div>
          </div>
        </div>
      </div>`,
    );
    return document.getElementById("goModal");
  }

  function openWindow({ icon, colour, title, sub, body, run }) {
    const el = windowEl();
    el.querySelector("#goModalIcon").className = `app-modal-icon bg-${colour}`;
    el.querySelector("#goModalIcon").innerHTML = `<i class="${icon}"></i>`;
    el.querySelector("#goModalTitle").textContent = title;
    el.querySelector("#goModalSub").textContent = sub;
    el.querySelector("#goModalBody").innerHTML = body;
    const go = el.querySelector("#goModalGo");
    const fresh = go.cloneNode(true);
    go.replaceWith(fresh);
    fresh.addEventListener("click", async () => {
      UI.setButtonLoading(fresh, "Saving...");
      const ok = await run(el);
      UI.restoreButton(fresh);
      if (ok) bootstrap.Modal.getInstance(el)?.hide();
    });
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  const part = (icon, title, body, hint = "") => `<section class="app-modal-part"><div class="app-modal-part-head"><i class="${icon}"></i>${esc(title)}${hint ? `<small>${esc(hint)}</small>` : ""}</div>${body}</section>`;

  /** Save the page at once (the window's Save), keeping the window open when the server says no. */
  async function saveNow() {
    const ok = await window.SettingsSections.givingoptions.save(true);
    SettingsHub.changed();
    return ok;
  }

  // ------------------------------------------------------------------ an option's window

  function optionWindow(list, i) {
    const rows = list === "std" ? standard : own;
    const isNew = i === null;
    const o = isNew ? blankOption(list) : { ...rows[i], words: [...(rows[i].words || [])] };
    const current = account(o.account_id);
    const kinds = GROUPS.map((g) => {
      const accs = data.accounts.filter((a) => a.group === g && (!a.between || String(a.id) === String(o.account_id)));
      return accs.length
        ? `<div class="go-kind-group"><div class="go-kind-head">${esc(g)}</div>${accs
            .map((a) => `<label class="go-kind${String(a.id) === String(o.account_id) ? " is-on" : ""}"><input type="radio" name="goKind" value="${a.id}"${String(a.id) === String(o.account_id) ? " checked" : ""}><span><strong>${esc(a.name)}</strong><small>${esc(a.about)}</small></span></label>`)
            .join("")}</div>`
        : "";
    }).join("");
    const fundOptions = [...data.fund_choices.filter((f) => f.code !== "GEN" && !funds.some((x) => x.id === f.id)).map((f) => [`id:${f.id}`, f.owner ? `${f.name} (${f.owner.name})` : f.name]), ...funds.filter((f) => f.code).map((f) => [f.id ? `id:${f.id}` : `code:${f.code}`, `${f.name || f.code} (ours)`])];
    const fundNow = o.fund_id ? `id:${o.fund_id}` : o.fund_code ? `code:${o.fund_code}` : "";
    const apart = !!fundNow;
    const body = [
      part("ri-edit-line", "What is it called?", `<input type="text" class="form-control" id="goLabel" maxlength="40" placeholder="e.g. Choir trip, Missions, Region conference" value="${esc(o.label)}">`),
      part("ri-price-tag-3-line", "What kind of money is it?", `<div class="go-kinds">${kinds}</div>`, "It decides where it shows in the books"),
      part(
        "ri-safe-2-line",
        "Keep the money apart?",
        `<div class="d-flex flex-column gap-2">
          <label class="go-kind${apart ? "" : " is-on"}"><input type="radio" name="goApart" value="no"${apart ? "" : " checked"}><span><strong>No - it can be used for anything</strong><small>It joins the general money</small></span></label>
          <label class="go-kind${apart ? " is-on" : ""}"><input type="radio" name="goApart" value="yes"${apart ? " checked" : ""}><span><strong>Yes - keep it for one purpose</strong><small>It shows on its own in the statements</small></span></label>
          <div id="goFundWrap" class="ps-1"${apart ? "" : " hidden"}><select class="form-select" id="goFund" aria-label="Which fund">${fundOptions.map(([v, t]) => `<option value="${esc(v)}"${v === fundNow ? " selected" : ""}>${esc(t)}</option>`).join("")}<option value="new">A new fund…</option></select><input type="text" class="form-control mt-2" id="goNewFund" maxlength="100" placeholder="Name the new fund, e.g. Choir trip fund" hidden></div>
        </div>`,
      ),
      `<details class="go-more"${isNew ? "" : ""}><summary><i class="ri-settings-3-line me-1"></i>More - account number, other words, look</summary><div class="row g-2 mt-1">
        <div class="col-sm-5"><label class="form-label" for="goSuffix">Pay Bill ending</label><input type="text" class="form-control text-uppercase" id="goSuffix" maxlength="6" placeholder="e.g. CONF" value="${esc(o.suffix)}"><div class="form-text" id="goExample">Account number ${esc(data.place.code)}${esc(o.suffix || "…")}</div></div>
        <div class="col-sm-7"><label class="form-label" for="goWords">Other words givers may type</label><input type="text" class="form-control text-uppercase" id="goWords" placeholder="e.g. CONFERENCE, CONF2026 (optional)" value="${esc((o.words || []).join(", "))}"></div>
        ${list !== "std" && !isChurch() ? `<div class="col-12"><label class="form-label" for="goReach">Who can give for it</label><select class="form-select" id="goReach"><option value="self"${o.reach === "self" ? " selected" : ""}>Only ${esc(data.place.name)}</option><option value="below"${o.reach === "below" ? " selected" : ""}>Every ${isDiocese() ? "region and church" : "church"} under ${esc(data.place.name)}</option></select></div>` : ""}
        <div class="col-sm-5"><label class="form-label" for="goIcon">Icon</label><select class="form-select" id="goIcon">${data.icons.map((ic) => `<option value="${ic}" data-icon="${ic}" data-color="${o.colour}"${ic === o.icon ? " selected" : ""}>${esc(ICON_NAMES[ic] || ic)}</option>`).join("")}</select></div>
        <div class="col-sm-7"><label class="form-label d-block">Colour</label><div class="mn-swatches fs-swatches" role="radiogroup" aria-label="Colour">${data.colours.map((c) => `<label title="${esc(c)}"><input type="radio" name="goColour" value="${c}"${c === o.colour ? " checked" : ""}><span class="bg-${c}"></span></label>`).join("")}</div></div>
      </div></details>`,
    ].join("");
    const el = openWindow({
      icon: "ri-hand-coin-line",
      colour: "success",
      title: isNew ? (list === "std" ? "Add a standard option" : "Add a giving option") : `Change ${o.label}`,
      sub: current ? `Counts as ${current.name}` : "What givers can give for",
      body,
      run: async (w) => {
        const v = (id) => w.querySelector(`#${id}`)?.value.trim() ?? "";
        o.label = v("goLabel");
        o.account_id = Number(w.querySelector('input[name="goKind"]:checked')?.value || o.account_id);
        o.suffix = (v("goSuffix") || suggestEnding(o.label, rows[i])).replace(/[^A-Za-z]/g, "").toUpperCase();
        o.words = v("goWords").split(/[,\s]+/).map((x) => x.trim().toUpperCase()).filter(Boolean);
        if (w.querySelector("#goReach")) o.reach = v("goReach");
        o.icon = v("goIcon") || o.icon;
        o.colour = w.querySelector('input[name="goColour"]:checked')?.value || o.colour;
        o.fund_id = null;
        o.fund_code = null;
        if (w.querySelector('input[name="goApart"]:checked')?.value === "yes") {
          const pick = v("goFund");
          if (pick === "new") {
            const name = v("goNewFund");
            if (!name) return Toast.error("Name the new fund."), false;
            let code = suggestCode(name) || "FUND";
            while (funds.some((f) => f.code === code) || data.fund_choices.some((f) => f.code === code)) code = `${code.slice(0, 5)}${Math.floor(Math.random() * 9) + 1}`;
            funds.push({ id: null, code, name, is_restricted: true, reach: isChurch() ? "self" : "below", is_active: true, in_use: 0 });
            o.fund_code = code;
            o.newFund = true;
          } else if (pick.startsWith("id:")) o.fund_id = Number(pick.slice(3));
          else if (pick.startsWith("code:")) o.fund_code = pick.slice(5);
        }
        if (!o.label) return Toast.error("Say what it is called."), false;
        if (isNew) rows.push(o);
        else rows[i] = o;
        const ok = await saveNow();
        if (!ok && isNew) rows.pop();
        if (!ok && o.newFund) funds.pop();
        delete o.newFund;
        return ok;
      },
    });
    const body$ = (s) => el.querySelector(s);
    el.querySelectorAll(".go-kind input").forEach((r) =>
      r.addEventListener("change", () => el.querySelectorAll(`input[name="${r.name}"]`).forEach((x) => x.closest(".go-kind").classList.toggle("is-on", x.checked))),
    );
    el.querySelectorAll('input[name="goApart"]').forEach((r) => r.addEventListener("change", () => (body$("#goFundWrap").hidden = r.value !== "yes" || !r.checked)));
    body$("#goFund").addEventListener("change", () => (body$("#goNewFund").hidden = body$("#goFund").value !== "new"));
    if (!fundOptions.length) {
      body$("#goFund").value = "new";
      body$("#goNewFund").hidden = false;
    }
    const example = () => (body$("#goExample").textContent = `Account number ${data.place.code}${body$("#goSuffix").value.toUpperCase() || "…"}`);
    body$("#goSuffix").addEventListener("input", example);
    body$("#goLabel").addEventListener("input", () => {
      if (isNew && !body$("#goSuffix").dataset.touched) {
        body$("#goSuffix").value = suggestEnding(body$("#goLabel").value, null);
        example();
      }
    });
    body$("#goSuffix").addEventListener("keydown", () => (body$("#goSuffix").dataset.touched = "1"));
    ["#goIcon", "#goFund", "#goReach"].forEach((s) => body$(s) && UI.enhanceSelect(body$(s), { search: false }));
  }

  // ------------------------------------------------------------------ a fund's window

  function fundWindow(i) {
    const isNew = i === null;
    const f = isNew ? { id: null, code: "", name: "", is_restricted: true, reach: isChurch() ? "self" : "below", is_active: true, in_use: 0 } : { ...funds[i] };
    openWindow({
      icon: "ri-safe-2-line",
      colour: "warning",
      title: isNew ? "Add a fund" : `Change ${f.name}`,
      sub: "Money kept for one purpose",
      body: [
        part("ri-edit-line", "What is it for?", `<input type="text" class="form-control" id="gfName" maxlength="100" placeholder="e.g. Choir fund, Church van" value="${esc(f.name)}">`),
        part("ri-safe-2-line", "Kept apart", `<label class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="gfApart"${f.is_restricted ? " checked" : ""}><span class="form-check-label">Only for this purpose - not mixed with the general money</span></label>`),
        !isChurch() ? part("ri-community-line", "Who can use it", `<select class="form-select" id="gfReach"><option value="self"${f.reach === "self" ? " selected" : ""}>Only ${esc(data.place.name)}</option><option value="below"${f.reach === "below" ? " selected" : ""}>Every place under us</option></select>`) : "",
        `<details class="go-more"><summary><i class="ri-settings-3-line me-1"></i>More - short code</summary><div class="mt-2"><input type="text" class="form-control text-uppercase" id="gfCode" maxlength="10" placeholder="Made from the name, e.g. CHOIR" value="${esc(f.code)}"></div></details>`,
      ].join(""),
      run: async (w) => {
        f.name = w.querySelector("#gfName").value.trim();
        if (!f.name) return Toast.error("Say what the fund is for."), false;
        f.code = (w.querySelector("#gfCode").value.trim() || suggestCode(f.name)).replace(/[^A-Za-z0-9]/g, "").toUpperCase();
        f.is_restricted = w.querySelector("#gfApart").checked;
        if (w.querySelector("#gfReach")) f.reach = w.querySelector("#gfReach").value;
        if (isNew) funds.push(f);
        else funds[i] = f;
        const ok = await saveNow();
        if (!ok && isNew) funds.pop();
        return ok;
      },
    });
  }

  // ------------------------------------------------------------------ the lists

  function optionRow(o, i, list) {
    const acc = account(o.account_id);
    const fund = fundOf(o);
    const std = list === "std";
    const rows = std ? standard : own;
    return `<div class="fs-row align-items-center${o.is_active ? "" : " is-off"}" data-list="${list}" data-i="${i}">
      <span class="fs-row-tile bg-${o.colour}"><i class="${o.icon}"></i></span>
      <div class="flex-fill min-w-0">
        <div class="d-flex flex-wrap align-items-center gap-2"><b class="fs-15">${esc(o.label || "Unnamed")}</b>${o.is_active ? "" : '<span class="badge bg-secondary text-dark">Off</span>'}${std && o.key === defaultKey ? '<span class="badge bg-primary text-white">When no ending is typed</span>' : ""}</div>
        <div class="d-flex flex-wrap gap-1 mt-1">
          <span class="soft-chip soft-primary"><i class="ri-smartphone-line"></i>${esc(data.place.code)}<b>${esc(o.suffix || "…")}</b></span>
          <span class="soft-chip soft-secondary"><i class="ri-price-tag-3-line"></i>${acc ? esc(acc.name) : "Pick what it counts as"}</span>
          <span class="soft-chip soft-${fund ? "warning" : "success"}"><i class="ri-safe-2-line"></i>${fund ? `Kept for ${esc(fund.name)}` : "Free to use"}</span>
          ${!std && !isChurch() && o.reach === "below" ? `<span class="soft-chip soft-purple"><i class="ri-community-line"></i>Places below too</span>` : ""}
          ${o.in_use ? `<span class="mb-sub">Given ${o.in_use} ${o.in_use === 1 ? "time" : "times"}</span>` : ""}
        </div>
      </div>
      ${
        can
          ? `<div class="d-flex align-items-center gap-1 flex-shrink-0">
          <div class="form-check form-switch mb-0 me-2" title="${o.is_active ? "On" : "Off"}"><input class="form-check-input" type="checkbox" role="switch" data-on aria-label="${esc(o.label)} on"${o.is_active ? " checked" : ""}></div>
          <button type="button" class="btn btn-sm btn-primary-light" data-edit><i class="ri-edit-line me-1"></i>Change</button>
          <button type="button" class="btn btn-icon btn-sm btn-light border" data-up aria-label="Move up"${i ? "" : " disabled"}><i class="ri-arrow-up-s-line"></i></button>
          <button type="button" class="btn btn-icon btn-sm btn-light border" data-down aria-label="Move down"${i === rows.length - 1 ? " disabled" : ""}><i class="ri-arrow-down-s-line"></i></button>
          <button type="button" class="btn btn-icon btn-sm btn-light border" data-remove aria-label="Remove ${esc(o.label)}"${std && ["T", "O", "TH", "B", "K"].includes(o.key) ? ' disabled title="A standard option can be switched off, not removed"' : ""}><i class="ri-delete-bin-line"></i></button>
        </div>`
          : ""
      }
    </div>`;
  }

  function drawOptions(list) {
    const box = $(list === "std" ? "#goStandard" : "#goOwn");
    if (!box) return;
    const rows = list === "std" ? standard : own;
    box.innerHTML = rows.length
      ? rows.map((o, i) => optionRow(o, i, list)).join("")
      : `<div class="settings-empty"><span class="avatar avatar-md bg-success text-white"><i class="ri-hand-coin-line"></i></span><div><b>None of our own yet.</b><br>${isChurch() ? "For something only this church gives for - a choir trip, a harambee." : `For ${esc(data.place.name)}'s own work - a conference, missions.`}</div></div>`;
  }

  function drawInherited() {
    const box = $("#goInherited");
    if (!box) return;
    box.innerHTML = data.inherited.length
      ? data.inherited
          .map(
            (o) => `<div class="fs-row align-items-center${hidden.has(o.id) ? " is-off" : ""}">
              <span class="fs-row-tile bg-${o.colour}"><i class="${o.icon}"></i></span>
              <div class="flex-fill min-w-0"><b class="fs-15">${esc(o.label)}</b><div class="d-flex flex-wrap gap-1 mt-1"><span class="soft-chip soft-primary"><i class="ri-smartphone-line"></i>${esc(o.example)}</span><span class="soft-chip soft-secondary">${o.owner ? `Goes to ${esc(o.owner.name)}` : "Ours to keep"}</span></div></div>
              <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-show="${o.id}" id="gs${o.id}"${hidden.has(o.id) ? "" : " checked"}${can && !o.is_default ? "" : " disabled"}><label class="form-check-label fs-12" for="gs${o.id}">${o.is_default ? "Always shown" : "On our page"}</label></div>
            </div>`,
          )
          .join("")
      : `<div class="mb-sub">Nothing set up above us.</div>`;
  }

  function drawFunds() {
    const box = $("#goFunds");
    box.innerHTML =
      (funds.length
        ? funds
            .map(
              (f, i) => `<div class="fs-row align-items-center${f.is_active ? "" : " is-off"}" data-fund="${i}">
                <span class="fs-row-tile bg-${f.is_restricted ? "warning" : "success"}"><i class="ri-safe-2-line"></i></span>
                <div class="flex-fill min-w-0"><b class="fs-15">${esc(f.name || f.code)}</b>${f.is_active ? "" : ' <span class="badge bg-secondary text-dark">Off</span>'}<div class="d-flex flex-wrap gap-1 mt-1"><span class="soft-chip soft-${f.is_restricted ? "warning" : "success"}">${f.is_restricted ? "Kept apart" : "Free to use"}</span>${!isChurch() ? `<span class="soft-chip soft-purple">${f.reach === "below" ? "Places below too" : "Only us"}</span>` : ""}${f.in_use ? `<span class="mb-sub">Used ${f.in_use} ${f.in_use === 1 ? "time" : "times"}</span>` : ""}</div></div>
                ${can ? `<div class="d-flex align-items-center gap-1 flex-shrink-0"><div class="form-check form-switch mb-0 me-2"><input class="form-check-input" type="checkbox" role="switch" data-fon="${i}" aria-label="${esc(f.name)} on"${f.is_active ? " checked" : ""}></div><button type="button" class="btn btn-sm btn-primary-light" data-fedit="${i}"><i class="ri-edit-line me-1"></i>Change</button><button type="button" class="btn btn-icon btn-sm btn-light border" data-fremove="${i}" aria-label="Remove ${esc(f.name)}"><i class="ri-delete-bin-line"></i></button></div>` : ""}
              </div>`,
            )
            .join("")
        : `<div class="settings-empty"><span class="avatar avatar-md bg-warning text-white"><i class="ri-safe-2-line"></i></span><div><b>No funds of our own.</b><br>Add one to keep money for one purpose.</div></div>`) +
      (data.funds.inherited.length ? `<div class="d-flex flex-wrap align-items-center gap-2 mt-3"><span class="mb-sub">We can also use</span>${data.funds.inherited.map((x) => `<span class="soft-chip soft-${x.is_restricted ? "warning" : "success"}">${esc(x.name)}${x.owner ? ` · ${esc(x.owner.name)}` : ""}</span>`).join("")}</div>` : "");
  }

  // ------------------------------------------------------------------ wiring

  const changed = () => SettingsHub.changed();

  function onChange(e) {
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
    if (el.dataset.on !== undefined) {
      const row = el.closest("[data-list]");
      (row.dataset.list === "std" ? standard : own)[Number(row.dataset.i)].is_active = el.checked;
      drawOptions(row.dataset.list);
      return changed();
    }
    if (el.dataset.fon !== undefined) {
      funds[Number(el.dataset.fon)].is_active = el.checked;
      drawFunds();
      return changed();
    }
  }

  function onClick(e) {
    const b = e.target.closest("button");
    if (!b || b.disabled) return;
    if (b.dataset.fedit !== undefined) return fundWindow(Number(b.dataset.fedit));
    if (b.dataset.fremove !== undefined) {
      funds.splice(Number(b.dataset.fremove), 1);
      drawFunds();
      drawOptions("own");
      drawOptions("std");
      return changed();
    }
    const row = b.closest("[data-list]");
    if (!row) return;
    const list = row.dataset.list === "std" ? standard : own;
    const i = Number(row.dataset.i);
    if (b.dataset.edit !== undefined) return optionWindow(row.dataset.list, i);
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

  function blankOption(list) {
    const special = data.accounts.find((a) => a.name === "Special collections") || data.accounts.find((a) => a.group === "Giving") || data.accounts[0] || {};
    return { id: null, key: null, label: "", suffix: "", words: [], reach: isChurch() || list === "std" ? (list === "std" ? "below" : "self") : "below", account_id: special.id, fund_id: null, fund_code: null, icon: "ri-hand-coin-line", colour: data.colours[(own.length + 3) % data.colours.length], is_active: true, in_use: 0 };
  }

  function draw() {
    root.innerHTML = `<div id="goWrap">
      ${can ? "" : `<div class="alert alert-primary d-flex align-items-center gap-2"><span class="avatar avatar-sm bg-primary text-white"><i class="ri-eye-line"></i></span><div><b>View only.</b> Your role can't change these.</div></div>`}
      ${F.card({ id: "card-go-own", title: "Our giving options", icon: "ri-hand-coin-line", colour: "success", sub: "What givers can give for here. They pay by Pay Bill with the account number shown.",
        actions: can ? '<button type="button" class="btn btn-primary btn-sm" id="goAddOption"><i class="ri-add-line me-1"></i>Add an option</button>' : "", body: '<div id="goOwn" class="fs-rows"></div><div class="invalid-feedback d-block" data-error-for="purposes"></div>' })}
      ${isDiocese()
        ? F.card({ id: "card-go-std", title: "Standard options", icon: "ri-hand-heart-line", colour: "primary", sub: "On every place's giving page - each place keeps what it collects.",
            actions: can ? '<button type="button" class="btn btn-outline-primary btn-sm" id="goAddStandard"><i class="ri-add-line me-1"></i>Add a standard option</button>' : "",
            body: `<div id="goStandard" class="fs-rows"></div><div class="d-flex flex-wrap align-items-center gap-2 mt-3"><span class="mb-sub">When a giver types no ending, count it as</span><select class="form-select form-select-sm w-auto" id="goDefault"${can ? "" : " disabled"}>${standard.filter((o) => o.key).map((o) => `<option value="${esc(o.key)}"${o.key === defaultKey ? " selected" : ""}>${esc(o.label)}</option>`).join("")}</select></div><div class="invalid-feedback d-block" data-error-for="default"></div>` })
        : F.card({ id: "card-go-above", title: data.place.level === "region" ? "From the diocese" : "From the diocese and region", icon: "ri-git-merge-line", colour: "info", sub: "Switch one off to leave it off our giving page.", body: '<div id="goInherited" class="fs-rows"></div>' })}
      ${F.card({ id: "card-go-funds", title: "Our funds", icon: "ri-safe-2-line", colour: "warning", sub: "Money kept for one purpose, shown on its own in the statements.",
        actions: can ? '<button type="button" class="btn btn-primary btn-sm" id="goAddFund"><i class="ri-add-line me-1"></i>Add a fund</button>' : "", body: '<div id="goFunds" class="fs-rows"></div>' })}
    </div>`;
    drawOptions("own");
    drawOptions("std");
    drawInherited();
    drawFunds();
    const wrap = $("#goWrap");
    wrap.addEventListener("change", onChange);
    wrap.addEventListener("click", onClick);
    $("#goAddOption")?.addEventListener("click", () => optionWindow("own", null));
    $("#goAddStandard")?.addEventListener("click", () => optionWindow("std", null));
    $("#goAddFund")?.addEventListener("click", () => fundWindow(null));
    const def = $("#goDefault");
    if (def) {
      UI.enhanceSelect(def, { search: false });
      window.jQuery?.(def).on("change", () => {
        defaultKey = def.value;
        drawOptions("std");
        changed();
      });
    }
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
    async save(fromWindow = false) {
      root.querySelectorAll("[data-error-for]").forEach((e) => (e.textContent = ""));
      const payload = {
        purposes: own.map(body),
        funds: funds.map((f) => ({ id: f.id, code: f.code, name: (f.name || "").trim(), is_restricted: !!f.is_restricted, reach: f.reach, is_active: !!f.is_active })),
        hidden: [...hidden],
        ...(isDiocese() ? { standard: standard.map(body), default: defaultKey } : {}),
      };
      const res = await SettingsAPI.saveGivingOptions(payload);
      if (!res.ok) {
        // Name the option the server is talking about (the list doesn't show the fields any more).
        const first = Object.entries(res.errors || {})[0];
        const m = first && first[0].match(/^(purposes|standard|funds)\.(\d+)\./);
        const which = m ? (m[1] === "funds" ? funds : m[1] === "standard" ? standard : own)[Number(m[2])] : null;
        Toast.error(first ? `${which ? `${which.label || which.name}: ` : ""}${[].concat(first[1])[0]}` : res.message, { title: "Not saved" });
        return false;
      }
      await load(root);
      Toast.success("Saved.");
      return true;
    },
  };
})();
