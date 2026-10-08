/**
 * ============================================================================
 * MEMBERS - add or edit a member (new.php, ?id= to edit)
 * ============================================================================
 * One short card: name, phone, area, gender, and Sunday school or main
 * church - nothing more is collected (2026-10-09). Editing also opens
 * "Church life" (joined, saved, baptised), added whenever the church has
 * it. The phone is checked against the register as you leave it, and the
 * area box suggests the areas already in use. Saves without reloading,
 * then opens their page.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);
  const editId = Number(new URLSearchParams(window.location.search).get("id")) || null;
  const BASIC = ["first_name", "last_name", "phone", "area"];
  const LATER = ["joined_on", "how_joined", "saved_on", "baptised_on", "previous_church", "status"];
  let dirty = false;
  let confirmDuplicate = false;
  let person = null;

  const radio = (name) => document.querySelector(`input[name="${name}"]:checked`)?.value || null;

  function body() {
    const b = {};
    [...BASIC, ...(editId ? LATER : [])].forEach((f) => {
      const v = ($(`f_${f}`)?.value || "").trim();
      b[f] = v === "" ? null : v;
    });
    b.gender = radio("gender");
    b.congregation = radio("congregation");
    if (editId) b.status = b.status || "member";
    if (confirmDuplicate) b.confirm_duplicate = true;
    return b;
  }

  function fill(p) {
    [...BASIC, ...LATER].forEach((f) => {
      const el = $(`f_${f}`);
      if (el) el.value = p[f] ?? "";
    });
    document.querySelectorAll('input[name="gender"]').forEach((r) => (r.checked = r.value === p.gender));
    document.querySelectorAll('input[name="congregation"]').forEach((r) => (r.checked = r.value === p.congregation));
    ["f_how_joined", "f_status"].forEach((id) => UI.syncSelect($(id)));
  }

  // -------------------------------------------------------------- the card
  function renderPreview() {
    const b = body();
    const name = [b.first_name, b.last_name].filter(Boolean).join(" ");
    const initials = `${(b.first_name || "?")[0]}${(b.last_name || "")[0] || ""}`.toUpperCase();
    const facts = [
      ["ri-phone-line", b.phone || "No phone yet"],
      ["ri-map-pin-line", b.area || "Area not given"],
      ["ri-user-3-line", b.gender ? (b.gender === "male" ? "Male" : "Female") : "Gender not given"],
    ];
    if (editId) {
      facts.push(["ri-calendar-check-line", b.joined_on ? `Joined ${M.day(b.joined_on)}` : "Date joined - add later"], ["ri-drop-line", b.baptised_on ? `Baptised ${M.day(b.baptised_on)}` : "Not baptised yet"]);
    }
    $("previewCard").innerHTML = `
      <div class="d-flex align-items-center gap-3 mb-3">
        <span class="avatar avatar-lg avatar-rounded bg-primary text-white">${M.esc(initials)}</span>
        <div class="min-w-0">
          <div class="fw-bold fs-16 text-break">${M.esc(name) || "Their name"}</div>
          <div class="d-flex flex-wrap gap-1 mt-1">${M.statusPill(b.status || person?.status || "member")}${b.congregation ? M.groupChip(b.congregation) : ""}</div>
        </div>
      </div>
      <ul class="ev-facts">${facts.map(([i, t]) => `<li><i class="${i}"></i><span>${M.esc(t)}</span></li>`).join("")}</ul>`;
  }

  function showErrors(errors) {
    document.querySelectorAll("#memberFormCard .is-invalid").forEach((el) => el.classList.remove("is-invalid"));
    const msgs = Object.entries(errors).map(([field, msg]) => {
      $(`f_${field}`)?.classList.add("is-invalid");
      return Array.isArray(msg) ? msg[0] : msg;
    });
    const box = $("formErrors");
    box.hidden = !msgs.length;
    box.innerHTML = msgs.length ? `<i class="ri-error-warning-line"></i><div><strong>Check these</strong><ul>${msgs.map((m) => `<li>${M.esc(m)}</li>`).join("")}</ul></div>` : "";
    if (Object.keys(errors).some((f) => LATER.includes(f))) $("laterBox").open = true;
  }

  // -------------------------------------------------------------- duplicates
  async function checkPhone() {
    const phone = $("f_phone").value.trim();
    const box = $("dupBox");
    confirmDuplicate = false;
    if (phone.replace(/\D/g, "").length < 9) {
      box.hidden = true;
      return;
    }
    const res = await MembersAPI.check({ phone, except: editId || "" });
    const same = res.ok ? res.data : [];
    box.hidden = !same.length;
    if (same.length) {
      const p = same[0];
      const href = p.status === "visitor" ? `${CTX.siteUrl}/church/visitors/visitor?id=${p.id}` : `${CTX.baseUrl}/member?id=${p.id}`;
      box.innerHTML = `<i class="ri-user-search-line"></i><div><strong>${M.esc(p.name)}</strong>${p.status === "visitor" ? " (a visitor)" : ""} already has this number. <a href="${href}">Open them</a> or <button type="button" class="btn btn-link p-0 align-baseline" id="dupOk">it's someone else - keep going</button>.</div>`;
      $("dupOk").addEventListener("click", () => {
        confirmDuplicate = true;
        box.hidden = true;
      });
    }
  }

  // -------------------------------------------------------------- save
  async function save(e) {
    e.preventDefault();
    const b = body();
    if (!b.first_name) return showErrors({ first_name: "Write their first name." });
    showErrors({});
    const btn = $("saveBtn");
    UI.setButtonLoading(btn, "Saving...");
    const res = editId ? await MembersAPI.update(editId, b) : await MembersAPI.create(b);
    UI.restoreButton(btn);
    if (!res.ok) {
      if (res.raw?.duplicate) {
        await checkPhone();
        return Toast.error(res.message);
      }
      showErrors(res.errors || {});
      return Toast.error(res.message);
    }
    dirty = false;
    Toast.success(res.message || "Saved.");
    window.location.href = `${CTX.baseUrl}/member?id=${res.data.id}`;
  }

  async function init() {
    if (!CTX.can.manage) {
      $("memberForm").innerHTML = M.errorBox("Your role can't add or change members.", `location.href='${CTX.baseUrl}/'`);
      return;
    }
    $("f_how_joined").innerHTML += Object.entries(M.HOW_JOINED).map(([v, l]) => `<option value="${v}">${l}</option>`).join("");
    $("f_status").innerHTML = Object.entries(M.STATUS).filter(([k]) => k !== "visitor").map(([v, s]) => `<option value="${v}">${s.label}</option>`).join("");
    const [p, o] = await Promise.all([editId ? MembersAPI.get(editId) : Promise.resolve(null), MembersAPI.overview()]);
    if (o?.ok) $("areaList").innerHTML = (o.data.areas || []).map((a) => `<option value="${M.esc(a)}">`).join("");
    if (editId) {
      if (!p.ok) {
        $("memberForm").innerHTML = M.errorBox(p.message);
        return;
      }
      person = p.data;
      $("laterBox").hidden = false;
      UI.enhanceSelect($("f_how_joined"), { search: false });
      UI.enhanceSelect($("f_status"), { search: false });
      fill(person);
      // A visitor who just became a member: their name, phone and area are here - pick gender and part.
      $("formTitle").textContent = person.came_as_visitor && !person.gender ? `Welcome ${person.first_name} as a member` : `Edit ${person.name}`;
      $("saveBtn").querySelector("span").textContent = "Save changes";
      document.title = `Edit ${person.name} - Makueni West Diocese`;
    }
    renderPreview();
    ["input", "change"].forEach((ev) =>
      $("memberFormCard").addEventListener(ev, () => {
        dirty = true;
        renderPreview();
      }),
    );
    $("f_phone").addEventListener("blur", checkPhone);
    $("memberFormCard").addEventListener("submit", save);
    window.addEventListener("beforeunload", (e) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
    $("f_first_name").focus();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
