/**
 * SETTINGS - Service times: when the place meets (GET / PUT
 * /settings/service-times). One row per service; the week on the right
 * fills in as you type.
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;
  const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const DAY_COLOURS = ["primary", "purple", "success", "pink", "secondary", "danger", "primary"];

  let root = null;
  let data = null;
  let rows = [];
  let snapshot = "[]";
  let can = false;

  const $ = (sel) => root.querySelector(sel);

  function blank() {
    return { name: "", day: 0, start: "10:00", end: "", gathering_type_id: "", language: "" };
  }

  function readRows() {
    return [...root.querySelectorAll(".service-row")].map((r) => ({
      name: r.querySelector('[data-f="name"]').value.trim(),
      day: Number(r.querySelector('[data-f="day"]').value),
      start: r.querySelector('[data-f="start"]').value,
      end: r.querySelector('[data-f="end"]').value,
      gathering_type_id: r.querySelector('[data-f="gathering_type_id"]')?.value || "",
      language: r.querySelector('[data-f="language"]').value.trim(),
    }));
  }

  // Fixed key order: the API's JSON column hands keys back sorted, so a spread copy would never match the form.
  const norm = (list) =>
    JSON.stringify(
      list.map((t) => ({
        name: String(t.name || "").trim(),
        day: Number(t.day),
        start: t.start || "",
        end: t.end || "",
        gathering_type_id: t.gathering_type_id ? String(t.gathering_type_id) : "",
        language: String(t.language || "").trim(),
      })),
    );

  function rowHtml(t, i) {
    const dis = can ? "" : "disabled";
    const types = data.gathering_types || [];
    return `
      <div class="service-row" data-index="${i}">
        <span class="service-row-day bg-${DAY_COLOURS[t.day] || "primary"} text-white" aria-hidden="true">${DAYS[t.day]?.slice(0, 3) || ""}</span>
        <div class="row g-2 flex-fill">
          <div class="col-lg-4 col-md-6">
            <label class="form-label fs-12 mb-1">Service</label>
            <input class="form-control" data-f="name" maxlength="100" value="${esc(t.name)}" placeholder="e.g. Main service" ${dis}>
            <div class="invalid-feedback" data-error-for="times.${i}.name"></div>
          </div>
          <div class="col-lg-2 col-md-6">
            <label class="form-label fs-12 mb-1">Day</label>
            <select class="form-select" data-f="day" ${dis}>${DAYS.map((d, n) => `<option value="${n}"${n === Number(t.day) ? " selected" : ""}>${d}</option>`).join("")}</select>
          </div>
          <div class="col-lg-2 col-6">
            <label class="form-label fs-12 mb-1">Starts</label>
            <input type="time" class="form-control" data-f="start" value="${esc(t.start)}" ${dis}>
            <div class="invalid-feedback" data-error-for="times.${i}.start"></div>
          </div>
          <div class="col-lg-2 col-6">
            <label class="form-label fs-12 mb-1">Ends <span class="fw-normal">(optional)</span></label>
            <input type="time" class="form-control" data-f="end" value="${esc(t.end || "")}" ${dis}>
            <div class="invalid-feedback" data-error-for="times.${i}.end"></div>
          </div>
          ${types.length
            ? `<div class="col-lg-4 col-md-6">
                <label class="form-label fs-12 mb-1">Counts as (attendance)</label>
                <select class="form-select" data-f="gathering_type_id" ${dis}><option value="">Not linked</option>${types
                  .map((g) => `<option value="${g.id}"${String(g.id) === String(t.gathering_type_id || "") ? " selected" : ""}>${esc(g.name)}</option>`)
                  .join("")}</select>
              </div>`
            : ""}
          <div class="col-lg-2 col-md-6">
            <label class="form-label fs-12 mb-1">Language</label>
            <input class="form-control" data-f="language" maxlength="50" value="${esc(t.language || "")}" placeholder="e.g. Kikamba" ${dis}>
          </div>
        </div>
        ${can ? `<button type="button" class="btn btn-icon btn-sm btn-light border service-row-remove" data-remove="${i}" aria-label="Remove ${esc(t.name || "this service")}" title="Remove"><i class="ri-delete-bin-line"></i></button>` : ""}
      </div>`;
  }

  function drawRows() {
    const list = $("#serviceRows");
    list.innerHTML = rows.length
      ? rows.map(rowHtml).join("")
      : `<div class="settings-empty"><span class="avatar avatar-md bg-success text-white"><i class="ri-time-line"></i></span><div><b>No services yet.</b><br>Add when you meet, so visitors and the diocese know.</div></div>`;
    list.querySelectorAll('select[data-f="day"], select[data-f="gathering_type_id"]').forEach((s) => {
      UI.enhanceSelect(s);
      if (window.jQuery) window.jQuery(s).on("change", onChange);
    });
    list.querySelectorAll("input").forEach((el) => el.addEventListener("input", onChange));
    list.querySelectorAll("[data-remove]").forEach((b) =>
      b.addEventListener("click", () => {
        rows = readRows();
        rows.splice(Number(b.dataset.remove), 1);
        drawRows();
        onChange();
      }),
    );
    const add = $("#addService");
    if (add) add.disabled = rows.length >= (data.max || 20);
  }

  function onChange() {
    // Day chips follow the day picked.
    root.querySelectorAll(".service-row").forEach((r) => {
      const day = Number(r.querySelector('[data-f="day"]').value);
      const chip = r.querySelector(".service-row-day");
      chip.className = `service-row-day bg-${DAY_COLOURS[day] || "primary"} text-white`;
      chip.textContent = DAYS[day].slice(0, 3);
    });
    drawWeek();
    SettingsHub.changed();
  }

  function drawWeek() {
    const list = root.querySelector(".service-row") ? readRows() : rows;
    const byDay = DAYS.map((d, n) =>
      list
        .filter((t) => Number(t.day) === n && t.start)
        .sort((a, b) => a.start.localeCompare(b.start)),
    );
    $("#weekBody").innerHTML = `
      <div class="settings-week-grid">
        ${byDay
          .map(
            (items, n) => `
          <div class="settings-week-col${items.length ? "" : " is-free"}">
            <span class="badge bg-${DAY_COLOURS[n]} ${DAY_COLOURS[n] === "secondary" ? "text-dark" : "text-white"} settings-week-day">${DAYS[n]}</span>
            ${items.length
              ? items
                  .map(
                    (t) => `<div class="settings-week-item"><b>${esc(t.start)}${t.end ? `–${esc(t.end)}` : ""}</b><span>${esc(t.name || "Unnamed service")}</span>${t.language ? `<span class="soft-chip soft-primary">${esc(t.language)}</span>` : ""}</div>`,
                  )
                  .join("")
              : '<div class="settings-week-none">No service</div>'}
          </div>`,
          )
          .join("")}
      </div>`;
  }

  function draw() {
    root.innerHTML = `
      ${can ? "" : `<div class="alert alert-primary d-flex align-items-center gap-2"><span class="avatar avatar-sm bg-primary text-white"><i class="ri-eye-line"></i></span><div><b>View only.</b> Your role can see the service times, but not change them.</div></div>`}
      ${F.card({ id: "card-week", title: "Your week", icon: "ri-calendar-2-line", colour: "purple", sub: "What visitors and the diocese see, day by day", body: '<div id="weekBody"></div>' })}
      <div class="row">
        <div class="col-12">
          ${F.card({
            id: "card-services",
            title: "Services",
            icon: "ri-time-line",
            colour: "success",
            sub: `Up to ${data.max || 20}. Link a service to an attendance gathering type and its counts line up.`,
            actions: can ? '<button type="button" class="btn btn-primary btn-sm" id="addService"><i class="ri-add-line me-1"></i>Add a service</button>' : "",
            body: '<div id="serviceRows" class="service-rows"></div><div class="invalid-feedback d-block" data-error-for="times"></div>',
          })}
        </div>
      </div>`;
    drawRows();
    drawWeek();
    $("#addService")?.addEventListener("click", () => {
      rows = readRows();
      const last = rows[rows.length - 1];
      rows.push({ ...blank(), day: last ? last.day : 0 });
      drawRows();
      onChange();
      root.querySelector(".service-row:last-child [data-f=name]")?.focus();
    });
    SettingsHub.subLinks([
      { id: "card-week", label: "Your week" },
      { id: "card-services", label: "Services" },
    ]);
  }

  window.SettingsSections = window.SettingsSections || {};
  window.SettingsSections.servicetimes = {
    async render(body) {
      root = body;
      const res = await SettingsAPI.serviceTimes();
      if (!res.ok) {
        body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
        return;
      }
      data = res.data;
      can = !!data.can?.update;
      rows = (data.times || []).map((t) => ({ ...t }));
      snapshot = norm(rows);
      draw();
    },
    isDirty() {
      if (!root || !data || !$("#serviceRows")) return 0;
      const now = norm(readRows());
      if (now === snapshot) return 0;
      const a = JSON.parse(now);
      const b = JSON.parse(snapshot);
      return Math.max(1, Math.abs(a.length - b.length) + a.filter((t, i) => JSON.stringify(t) !== JSON.stringify(b[i])).length);
    },
    discard() {
      rows = (data.times || []).map((t) => ({ ...t }));
      draw();
    },
    async save() {
      root.querySelectorAll("[data-error-for]").forEach((e) => (e.textContent = ""));
      root.querySelectorAll(".is-invalid").forEach((e) => e.classList.remove("is-invalid"));
      const times = readRows().map((t) => ({ ...t, end: t.end || null, gathering_type_id: t.gathering_type_id || null, language: t.language || null }));
      const res = await SettingsAPI.saveServiceTimes(times);
      if (!res.ok) {
        Object.entries(res.errors || {}).forEach(([k, msgs]) => {
          const e = root.querySelector(`[data-error-for="${CSS.escape(k)}"]`);
          if (e) {
            e.textContent = [].concat(msgs)[0];
            e.classList.add("d-block");
            e.previousElementSibling?.classList?.add("is-invalid");
          }
        });
        Toast.error(res.message, { title: "Service times not saved" });
        return false;
      }
      data = res.data;
      rows = (data.times || []).map((t) => ({ ...t }));
      snapshot = norm(rows);
      draw();
      SettingsRail.setAttention("servicetimes", rows.length === 0);
      Toast.success(res.message);
      return true;
    },
  };
})();
