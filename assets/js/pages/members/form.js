/**
 * ============================================================================
 * MEMBERS - add or edit a member (new.php, ?id= to edit)
 * ============================================================================
 * Four steps - who, contact, church life, check - with the member card on
 * the right. The phone is checked against the register as you leave it
 * ("Mary Mutua already has this number"). Saves without reloading, then
 * opens their page.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);
  const editId = Number(new URLSearchParams(window.location.search).get("id")) || null;
  const FIELDS = ["first_name", "last_name", "other_names", "date_of_birth", "marital_status", "occupation", "national_id", "phone", "email", "address", "next_of_kin_name", "next_of_kin_phone", "joined_on", "previous_church", "saved_on", "baptised_on", "status", "notes"];
  const STEP_OF = { first_name: 1, last_name: 1, other_names: 1, gender: 1, date_of_birth: 1, marital_status: 1, occupation: 1, national_id: 1, phone: 2, email: 2, address: 2, next_of_kin_name: 2, next_of_kin_phone: 2, how_joined: 3, joined_on: 3, previous_church: 3, saved_on: 3, baptised_on: 3, status: 3, notes: 3 };
  const HOW_ICONS = { conversion: "ri-heart-line", transfer: "ri-arrow-left-right-line", baptism: "ri-drop-line", birth: "ri-parent-line", other: "ri-more-line" };
  let step = 1;
  let dirty = false;
  let confirmDuplicate = false;

  // -------------------------------------------------------------- fill the form
  function fillOptions() {
    $("f_marital_status").innerHTML += Object.entries(M.MARITAL).map(([v, l]) => `<option value="${v}">${l}</option>`).join("");
    $("f_status").innerHTML = Object.entries(M.STATUS).filter(([k]) => k !== "visitor").map(([v, s]) => `<option value="${v}">${s.label}</option>`).join("");
    $("howJoinedChoices").innerHTML = Object.entries(M.HOW_JOINED)
      .map(([k, label]) => `<label class="ec-choice"><input type="radio" name="how_joined" value="${k}"><span class="ec-choice-icon"><i class="${HOW_ICONS[k]}"></i></span><strong>${label}</strong><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`)
      .join("");
    UI.enhanceSelect($("f_marital_status"), { search: false });
    UI.enhanceSelect($("f_status"), { search: false });
  }

  function fill(p) {
    FIELDS.forEach((f) => {
      const el = $(`f_${f}`);
      if (el) el.value = p[f] ?? "";
    });
    document.querySelectorAll('input[name="gender"]').forEach((r) => (r.checked = r.value === p.gender));
    document.querySelectorAll('input[name="how_joined"]').forEach((r) => (r.checked = r.value === p.how_joined));
    UI.syncSelect($("f_marital_status"));
    UI.syncSelect($("f_status"));
  }

  function body() {
    const b = {};
    FIELDS.forEach((f) => {
      const v = ($(`f_${f}`)?.value || "").trim();
      b[f] = v === "" ? null : v;
    });
    b.gender = document.querySelector('input[name="gender"]:checked')?.value || null;
    b.how_joined = document.querySelector('input[name="how_joined"]:checked')?.value || null;
    b.status = b.status || "member";
    if (confirmDuplicate) b.confirm_duplicate = true;
    return b;
  }

  // -------------------------------------------------------------- the card
  function ageOf(dob) {
    if (!dob) return null;
    const d = new Date(`${dob}T12:00:00`);
    const now = new Date();
    let a = now.getFullYear() - d.getFullYear();
    if (now.getMonth() < d.getMonth() || (now.getMonth() === d.getMonth() && now.getDate() < d.getDate())) a--;
    return a >= 0 ? a : null;
  }
  const bandOf = (a) => (a === null ? null : a <= 12 ? "children" : a <= 35 ? "youth" : a <= 59 ? "adults" : "seniors");

  function renderPreview() {
    const b = body();
    const name = [b.first_name, b.last_name].filter(Boolean).join(" ");
    const initials = `${(b.first_name || "?")[0]}${(b.last_name || "")[0] || ""}`.toUpperCase();
    const age = ageOf(b.date_of_birth);
    $("ageHint").textContent = age !== null ? `${age} years old - in the ${M.BANDS[bandOf(age)].toLowerCase()} band.` : "Their age puts them in an age band and on the birthday list.";
    const facts = [
      ["ri-phone-line", b.phone || "No phone yet"],
      ["ri-map-pin-line", b.address ? "Where they live is recorded" : "Where they live - not given"],
      ["ri-calendar-check-line", b.joined_on ? `Joined ${M.day(b.joined_on)}` : "Date joined - not given"],
      ["ri-drop-line", b.baptised_on ? `Baptised ${M.day(b.baptised_on)}` : "Not baptised yet"],
    ];
    $("previewCard").innerHTML = `
      <div class="d-flex align-items-center gap-3 mb-3">
        <span class="avatar avatar-lg avatar-rounded bg-primary text-white">${M.esc(initials)}</span>
        <div class="min-w-0">
          <div class="fw-bold fs-16 text-break">${M.esc(name) || "Their name"}</div>
          <div class="d-flex flex-wrap gap-1 mt-1">${M.statusPill(b.status)}${age !== null ? `<span class="soft-chip soft-primary">${age} · ${M.BANDS[bandOf(age)]}</span>` : ""}${b.how_joined ? `<span class="soft-chip soft-success">${M.HOW_JOINED[b.how_joined]}</span>` : ""}</div>
        </div>
      </div>
      <ul class="ev-facts">${facts.map(([i, t]) => `<li><i class="${i}"></i><span>${M.esc(t)}</span></li>`).join("")}</ul>`;
  }

  function renderReview() {
    const b = body();
    const rows = [
      ["Name", [b.first_name, b.other_names, b.last_name].filter(Boolean).join(" ")],
      ["Gender", b.gender ? (b.gender === "male" ? "Male" : "Female") : "Not given"],
      ["Date of birth", b.date_of_birth ? `${M.day(b.date_of_birth)} (${ageOf(b.date_of_birth)} years)` : "Not given"],
      ["Phone", b.phone || "Not given"],
      ["Email", b.email || "Not given"],
      ["Next of kin", [b.next_of_kin_name, b.next_of_kin_phone].filter(Boolean).join(" · ") || "Not given"],
      ["Joined", b.joined_on ? `${M.day(b.joined_on)}${b.how_joined ? ` · ${M.HOW_JOINED[b.how_joined]}` : ""}` : "Not given"],
      ["Saved", b.saved_on ? M.day(b.saved_on) : "Not given"],
      ["Baptised", b.baptised_on ? M.day(b.baptised_on) : "Not yet"],
      ["In the register as", M.STATUS[b.status]?.label || b.status],
      ["Private details", [b.national_id ? "National ID" : null, b.address ? "address" : null, b.notes ? "notes" : null].filter(Boolean).join(", ") || "None"],
    ];
    $("reviewBody").innerHTML = `<div class="mb-review">${rows.map(([k, v]) => `<div class="mb-review-row"><span>${k}</span><strong>${M.esc(v)}</strong></div>`).join("")}</div>`;
  }

  // -------------------------------------------------------------- steps
  function problems(n) {
    const b = body();
    const e = {};
    if (n === 1) {
      if (!b.first_name) e.first_name = "Write their first name.";
      if (!b.last_name) e.last_name = "Write their last name.";
      if (b.date_of_birth && b.date_of_birth > new Date().toISOString().slice(0, 10)) e.date_of_birth = "The date of birth can't be in the future.";
    }
    if (n === 2 && b.email && !/^\S+@\S+\.\S+$/.test(b.email)) e.email = "That email doesn't look right.";
    return e;
  }

  function showErrors(errors) {
    document.querySelectorAll(".intake-errors").forEach((box) => {
      box.hidden = true;
      box.innerHTML = "";
    });
    document.querySelectorAll("#memberFormCard .is-invalid").forEach((el) => el.classList.remove("is-invalid"));
    const byStep = {};
    Object.entries(errors).forEach(([field, msg]) => {
      const s = STEP_OF[field] || 1;
      (byStep[s] ||= []).push(Array.isArray(msg) ? msg[0] : msg);
      $(`f_${field}`)?.classList.add("is-invalid");
    });
    Object.entries(byStep).forEach(([s, msgs]) => {
      const box = document.querySelector(`[data-errors-for="${s}"]`);
      box.innerHTML = `<i class="ri-error-warning-line"></i><div><strong>Check this step</strong><ul>${msgs.map((m) => `<li>${M.esc(m)}</li>`).join("")}</ul></div>`;
      box.hidden = false;
    });
    return Number(Object.keys(byStep).sort()[0]) || null;
  }

  function go(n) {
    step = n;
    document.querySelectorAll(".intake-step").forEach((s) => (s.hidden = Number(s.dataset.step) !== n));
    document.querySelectorAll(".intake-step-btn").forEach((b) => {
      const k = Number(b.dataset.go);
      b.classList.toggle("is-on", k === n);
      b.classList.toggle("is-done", k < n);
    });
    $("intakeStepsBar").style.width = `${(n / 4) * 100}%`;
    $("intakeStepsMobile").textContent = `Step ${n} of 4 · ${document.querySelector(`[data-go="${n}"] strong`).textContent}`;
    $("backBtn").hidden = n === 1;
    $("nextBtn").hidden = n === 4;
    $("saveBtn").hidden = n !== 4;
    if (n === 4) renderReview();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function next() {
    const errs = problems(step);
    if (Object.keys(errs).length) return showErrors(errs);
    showErrors({});
    go(step + 1);
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
      box.innerHTML = `<i class="ri-user-search-line"></i><div><strong>${M.esc(p.name)}</strong> already has this number${same.length > 1 ? ` (and ${same.length - 1} more)` : ""}. <a href="${CTX.baseUrl}/member?id=${p.id}">Open them</a> or <button type="button" class="btn btn-link p-0 align-baseline" id="dupOk">it's someone else - keep going</button>.</div>`;
      $("dupOk").addEventListener("click", () => {
        confirmDuplicate = true;
        box.hidden = true;
      });
    }
  }

  // -------------------------------------------------------------- save
  async function save() {
    const btn = $("saveBtn");
    UI.setButtonLoading(btn, "Saving...");
    const res = editId ? await MembersAPI.update(editId, body()) : await MembersAPI.create(body());
    UI.restoreButton(btn);
    if (!res.ok) {
      if (res.raw?.duplicate) {
        go(2);
        await checkPhone();
        return Toast.error(res.message);
      }
      const first = showErrors(res.errors || {});
      if (first) go(first);
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
    fillOptions();
    if (editId) {
      const res = await MembersAPI.get(editId);
      if (!res.ok) {
        $("memberForm").innerHTML = M.errorBox(res.message);
        return;
      }
      fill(res.data);
      $("saveBtn").querySelector("span").textContent = "Save changes";
      $("intakeSaved").textContent = `Editing ${res.data.name}`;
      document.title = `Edit ${res.data.name} - Makueni West Diocese`;
    } else {
      $("f_joined_on").value = new Date().toISOString().slice(0, 10);
    }
    renderPreview();
    $("memberFormCard").addEventListener("input", () => {
      dirty = true;
      renderPreview();
    });
    $("memberFormCard").addEventListener("change", () => {
      dirty = true;
      renderPreview();
    });
    $("f_phone").addEventListener("blur", checkPhone);
    $("nextBtn").addEventListener("click", next);
    $("backBtn").addEventListener("click", () => go(step - 1));
    $("saveBtn").addEventListener("click", save);
    document.querySelectorAll(".intake-step-btn").forEach((b) =>
      b.addEventListener("click", () => {
        const to = Number(b.dataset.go);
        if (to < step) return go(to);
        for (let s = step; s < to; s++) {
          const errs = problems(s);
          if (Object.keys(errs).length) {
            go(s);
            return showErrors(errs);
          }
        }
        go(to);
      }),
    );
    window.addEventListener("beforeunload", (e) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
