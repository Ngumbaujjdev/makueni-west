/**
 * ============================================================================
 * VISITORS - record visitors (new.php), quick Sunday entry
 * ============================================================================
 * The date, the gathering and who follows them up; then a row per person -
 * just name, phone and area (2026-10-09). Enter adds the next row. A phone
 * we already know shows "Returning - 3rd visit" (or "Already a member"), and
 * saving adds a visit to that person instead of a new one. The welcome SMS
 * goes to first-timers with a phone.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const V = VisitorsUI;
  const CTX = window.VISITORS_CTX;
  const $ = (id) => document.getElementById(id);
  const state = { options: null, rows: [], seq: 0, checks: new Map() };

  const blank = () => ({ key: ++state.seq, name: "", phone: "", area: "", known: null });
  const filled = () => state.rows.filter((r) => r.name.trim() || r.phone.trim());
  const digits = (s) => String(s || "").replace(/\D+/g, "");
  const phoneKey = (s) => (digits(s).length >= 9 ? digits(s).slice(-9) : "");

  // -------------------------------------------------------------- rows
  function rowHtml(r, i) {
    return `<div class="vs-entry" data-key="${r.key}">
      <div class="vs-entry-num">${i + 1}</div>
      <div class="vs-entry-body">
        <div class="row g-2">
          <div class="col-md-5"><label class="form-label visually-hidden" for="n${r.key}">Name</label><input class="form-control" id="n${r.key}" data-f="name" placeholder="Full name" maxlength="160" value="${M.esc(r.name)}"></div>
          <div class="col-md-3 col-sm-6"><label class="form-label visually-hidden" for="p${r.key}">Phone</label><input class="form-control" id="p${r.key}" data-f="phone" inputmode="tel" placeholder="Phone (0712...)" maxlength="30" value="${M.esc(r.phone)}"></div>
          <div class="col-md-4 col-sm-6"><label class="form-label visually-hidden" for="a${r.key}">Area</label><input class="form-control" id="a${r.key}" data-f="area" list="vsAreas" placeholder="Area, e.g. Kasikeu" maxlength="80" value="${M.esc(r.area)}"></div>
        </div>
        <div class="vs-entry-known" data-known></div>
      </div>
      <button type="button" class="btn btn-icon btn-sm btn-light vs-entry-remove" data-remove aria-label="Remove this person"><i class="ri-close-line"></i></button>
    </div>`;
  }

  function renderRows(focusKey = null) {
    $("vsRows").innerHTML = state.rows.map(rowHtml).join("");
    state.rows.forEach((r) => paintKnown(r));
    if (focusKey) $(`n${focusKey}`)?.focus();
    tally();
  }

  function addRow(focus = true) {
    const r = blank();
    state.rows.push(r);
    $("vsRows").insertAdjacentHTML("beforeend", rowHtml(r, state.rows.length - 1));
    if (focus) $(`n${r.key}`).focus();
    tally();
  }

  const rowOf = (el) => state.rows.find((r) => r.key === Number(el.closest(".vs-entry")?.dataset.key));

  // -------------------------------------------------------------- known phones
  function paintKnown(r) {
    const box = document.querySelector(`.vs-entry[data-key="${r.key}"] [data-known]`);
    if (!box) return;
    const twin = phoneKey(r.phone) && state.rows.find((o) => o !== r && phoneKey(o.phone) === phoneKey(r.phone) && state.rows.indexOf(o) < state.rows.indexOf(r));
    if (twin) {
      box.innerHTML = `<span class="vs-known is-warn"><i class="ri-error-warning-line"></i>Same phone as row ${state.rows.indexOf(twin) + 1} - they'll be counted once</span>`;
      return;
    }
    const k = r.known;
    if (!k) return (box.innerHTML = "");
    box.innerHTML =
      k.status === "visitor"
        ? `<span class="vs-known"><i class="ri-repeat-line"></i>Returning - this is <a href="${CTX.baseUrl}/visitor?id=${k.id}" target="_blank" rel="noopener">${M.esc(k.name)}</a>'s ${V.ordinal(k.visits + 1)} visit</span>`
        : `<span class="vs-known is-warn"><i class="ri-home-heart-line"></i>${M.esc(k.name)} is already a member - they won't be counted as a visitor</span>`;
  }

  let checkTimer = null;
  function checkPhone(r) {
    clearTimeout(checkTimer);
    const key = phoneKey(r.phone);
    if (!key) {
      r.known = null;
      paintKnown(r);
      return tally();
    }
    checkTimer = setTimeout(async () => {
      if (!state.checks.has(key)) state.checks.set(key, VisitorsAPI.check(r.phone).then((res) => (res.ok ? res.data : null)));
      r.known = await state.checks.get(key);
      paintKnown(r);
      tally();
    }, 300);
  }

  // -------------------------------------------------------------- the tally
  function tally() {
    const rows = filled();
    const seen = new Set();
    let fresh = 0;
    let back = 0;
    let members = 0;
    let welcome = 0;
    rows.forEach((r) => {
      const key = phoneKey(r.phone);
      if (key && seen.has(key)) return;
      if (key) seen.add(key);
      if (r.known?.status && r.known.status !== "visitor") members++;
      else if (r.known) back++;
      else {
        fresh++;
        if (key && !key.startsWith("700000")) welcome++;
      }
    });
    $("vsTally").innerHTML = [
      ["ri-star-smile-line", "primary", "New visitors", fresh],
      ["ri-repeat-line", "warning", "Coming back", back],
      ["ri-home-heart-line", "purple", "Members (not counted)", members],
    ]
      .map(([icon, c, label, n]) => `<div class="vs-tally-row"><span class="ev-tile is-sm is-soft" style="--q: var(--${c}-rgb)"><i class="${icon}"></i></span><span class="flex-fill">${label}</span><strong>${n}</strong></div>`)
      .join("");
    const o = state.options;
    if (o.welcome_template) {
      $("vsWelcome").hidden = false;
      $("vsWelcomeWho").textContent = welcome ? `${welcome} new ${welcome === 1 ? "visitor has" : "visitors have"} a phone and will get it.` : "New visitors with a phone get it.";
      const first = rows.find((r) => !r.known && r.phone.trim())?.name.trim().split(" ")[0] || "Mary";
      $("vsWelcomeText").textContent = o.welcome_template.replace(/\{first_name\}/g, first).replace(/\{church\}/g, o.church_name || "");
    }
    $("vsSave").innerHTML = `<i class="ri-check-line me-1"></i>Save ${rows.length ? `${rows.length} ${rows.length === 1 ? "visitor" : "visitors"}` : "visitors"}`;
  }

  // -------------------------------------------------------------- saving
  async function save(e) {
    e.preventDefault();
    const rows = filled();
    document.querySelectorAll(".vs-entry .is-invalid").forEach((el) => el.classList.remove("is-invalid"));
    if (!rows.length) {
      Toast.error("Add at least one visitor.");
      return $(`n${state.rows[0].key}`)?.focus();
    }
    const nameless = rows.find((r) => !r.name.trim());
    if (nameless) {
      $(`n${nameless.key}`).classList.add("is-invalid");
      $(`n${nameless.key}`).focus();
      return Toast.error("Each visitor needs a name.");
    }
    const btn = $("vsSave");
    UI.setButtonLoading(btn, "Saving...");
    const res = await VisitorsAPI.batch({
      on: $("vsOn").value,
      gathering_type_id: $("vsGathering").value || null,
      assigned_to: $("vsAssign").value || null,
      welcome_sms: !$("vsWelcome").hidden && $("vsWelcomeSw").checked,
      rows: rows.map((r) => ({ name: r.name.trim(), phone: r.phone.trim() || null, area: r.area.trim() || null })),
    });
    UI.restoreButton(btn);
    if (!res.ok) {
      const bad = res.errors && Object.keys(res.errors).find((k) => k.startsWith("rows."));
      if (bad) {
        const [, i, field] = bad.split(".");
        const r = rows[Number(i)];
        const el = r && $(`${{ name: "n", phone: "p", area: "a" }[field] || "n"}${r.key}`);
        el?.classList.add("is-invalid");
        el?.focus();
      }
      return Toast.error(res.message);
    }
    done(res.data, rows);
  }

  function done(d, rows) {
    const RESULT = { new: ["New visitor", "primary"], returning: ["Came back", "warning"], member: ["Already a member", "purple"] };
    $("vsEntryMain").innerHTML = `
      <div class="card custom-card">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span class="avatar avatar-lg avatar-rounded bg-success text-white"><i class="ri-check-line fs-22"></i></span>
            <div><h5 class="mb-0">Saved</h5><p class="mb-0">${d.created} new, ${d.returning} came back${d.members ? `, ${d.members} already ${d.members === 1 ? "a member" : "members"}` : ""}${d.sms_sent ? ` · ${d.sms_sent} welcome ${d.sms_sent === 1 ? "SMS" : "SMSes"} sent` : ""}.</p></div>
          </div>
          <ul class="mb-mini-list">${d.items
            .map((it) => {
              const [label, c] = RESULT[it.result];
              const href = it.result === "member" ? `${CTX.membersUrl}/member?id=${it.id}` : `${CTX.baseUrl}/visitor?id=${it.id}`;
              return `<li>${M.avatar({ id: it.id, initials: it.name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase() }, "sm")}<div class="flex-fill min-w-0"><a class="fw-semibold mb-link" href="${href}">${M.esc(it.name)}</a><small>${it.result === "member" ? "Not counted as a visitor" : `${V.ordinal(it.visits)} visit`}</small></div><span class="badge bg-${c} ${M.textOn(c)}">${label}</span></li>`;
            })
            .join("")}</ul>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <a class="btn btn-primary" href="${CTX.baseUrl}/"><i class="ri-layout-column-line me-1"></i>See the board</a>
            <a class="btn btn-outline-primary" href="${CTX.baseUrl}/new"><i class="ri-user-add-line me-1"></i>Record more</a>
          </div>
        </div>
      </div>`;
    $("vsSave").disabled = true;
    $("vsSave").innerHTML = '<i class="ri-check-line me-1"></i>Saved';
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // -------------------------------------------------------------- wiring
  function wire() {
    const rowsEl = $("vsRows");
    rowsEl.addEventListener("input", (e) => {
      const r = rowOf(e.target);
      const f = e.target.dataset.f;
      if (!r || !f) return;
      r[f] = e.target.value;
      if (f === "phone") checkPhone(r);
      if (f === "name") tally();
    });
    rowsEl.addEventListener("keydown", (e) => {
      if (e.key !== "Enter") return;
      e.preventDefault();
      const r = rowOf(e.target);
      const last = state.rows[state.rows.length - 1];
      if (r === last) addRow();
      else $(`n${state.rows[state.rows.indexOf(r) + 1].key}`)?.focus();
    });
    rowsEl.addEventListener("click", (e) => {
      if (!e.target.closest("[data-remove]")) return;
      const r = rowOf(e.target);
      state.rows = state.rows.filter((x) => x !== r);
      if (!state.rows.length) state.rows.push(blank());
      renderRows();
    });
    $("vsAddRow").addEventListener("click", () => addRow());
    $("vsEntry").addEventListener("submit", save);
  }

  async function init() {
    const res = await VisitorsAPI.options();
    if (!res.ok) {
      $("vsEntry").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      return;
    }
    const o = (state.options = res.data);
    $("vsOn").value = V.todayIso();
    $("vsOn").max = V.todayIso();
    $("vsGathering").insertAdjacentHTML("beforeend", o.gathering_types.map((g) => `<option value="${g.id}">${M.esc(g.name)}</option>`).join(""));
    $("vsAssign").insertAdjacentHTML("beforeend", o.leaders.map((l) => `<option value="${l.id}" data-color="${UI.colorFor(l.name)}"${l.id === CTX.userId ? " selected" : ""}>${M.esc(l.name)}${l.id === CTX.userId ? " (me)" : ""}</option>`).join(""));
    $("vsAreas").innerHTML = (o.areas || []).map((a) => `<option value="${M.esc(a)}">`).join("");
    UI.enhanceSelect($("vsGathering"), { search: o.gathering_types.length > 8 });
    UI.enhanceSelect($("vsAssign"));
    $("vsWelcomeSw").checked = !!o.welcome_sms;
    state.rows = [blank(), blank(), blank()];
    renderRows();
    wire();
    $(`n${state.rows[0].key}`).focus();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
