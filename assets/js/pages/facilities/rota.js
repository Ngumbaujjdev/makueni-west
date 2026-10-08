/**
 * ============================================================================
 * FACILITIES - Duty rota (rota.php)
 * ============================================================================
 * Four weeks at a time: a row for each service (from Settings > Service
 * times), a column for each duty, people in each box. Those who manage tap a
 * box to put people on it, and can copy a week onto another. The next
 * service is marked, and whether reminders go out the day before.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const K = PeopleKit;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  const monday = (d) => {
    const x = new Date(d);
    x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
    return x;
  };
  const params = new URLSearchParams(window.location.search);
  const state = { from: monday(params.get("from") ? new Date(`${params.get("from")}T12:00:00`) : new Date()), data: null };
  const addDays = (d, n) => {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
  };

  function render() {
    const d = state.data;
    $("rtTitle").textContent = `Duty rota · ${F.day(d.from, { day: "numeric", month: "short" })} - ${F.day(d.to, { day: "numeric", month: "short" })}`;
    $("reminderChip").innerHTML = d.reminders
      ? `<span class="soft-chip soft-success"><i class="ri-message-3-line"></i>Reminders on · texted ${F.ampm(d.reminder_time)} the day before</span>`
      : `<a class="soft-chip soft-warning" href="${CTX.settingsUrl}"><i class="ri-message-3-line"></i>Reminders off - switch on in Settings</a>`;
    if (!d.rows.length) {
      $("rtTable").innerHTML = `<tbody><tr><td>${MembersUI.empty("ri-calendar-line", "No services in these weeks", "Add your service times in Settings and they show here.")}</td></tr></tbody>`;
      return;
    }
    const next = d.rows.find((r) => r.date >= d.today);
    let week = null;
    const body = d.rows
      .map((r) => {
        const wk = F.iso(monday(new Date(`${r.date}T12:00:00`)));
        const head = wk !== week ? `<tr class="fx-rota-week"><th colspan="${d.duties.length + 1}">Week of ${F.day(wk, { day: "numeric", month: "long" })}</th></tr>` : "";
        week = wk;
        const isNext = next && r.date === next.date && r.service === next.service;
        const past = r.date < d.today;
        return `${head}<tr class="${isNext ? "is-next" : ""}${past ? " is-past" : ""}">
          <th class="fx-rota-service"><strong>${F.day(r.date)}</strong><small>${F.esc(r.service)} · ${F.ampm(r.start)}</small>${isNext ? '<span class="badge bg-primary">Next</span>' : ""}</th>
          ${d.duties
            .map((x) => {
              const people = d.cells[`${r.date}|${r.service}|${x.key}`] || [];
              return `<td><button type="button" class="fx-rota-cell" data-cell="${r.date}|${F.esc(r.service)}|${x.key}"${CTX.can.manage ? "" : " disabled"}>${
                people.length ? people.map((p) => `<span class="fx-person"><span class="avatar avatar-xs avatar-rounded bg-${UI.colorFor(p.name)} text-white">${F.esc(p.initials)}</span>${F.esc(p.name.split(" ")[0])}</span>`).join("") : CTX.can.manage ? '<span class="fx-rota-add"><i class="ri-add-line"></i></span>' : '<span class="fx-nobody">-</span>'
              }</button></td>`;
            })
            .join("")}
        </tr>`;
      })
      .join("");
    $("rtTable").innerHTML = `<thead><tr><th>Service</th>${d.duties.map((x) => `<th><span class="fx-rota-duty"><span class="avatar avatar-xs avatar-rounded bg-${x.color} ${x.color === "warning" ? "text-dark" : "text-white"}"><i class="${x.icon}"></i></span>${F.esc(x.label)}</span></th>`).join("")}</tr></thead><tbody>${body}</tbody>`;
  }

  async function load() {
    const res = await FacilitiesAPI.rota({ from: F.iso(state.from), to: F.iso(addDays(state.from, 27)) });
    if (!res.ok) {
      $("rtTable").innerHTML = `<tbody><tr><td>${MembersUI.errorBox(res.message)}</td></tr></tbody>`;
      return;
    }
    state.data = res.data;
    const q = new URLSearchParams(window.location.search);
    q.set("from", F.iso(state.from));
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    render();
  }

  function copyWindow() {
    const weeks = [...Array(8)].map((_, i) => addDays(monday(new Date()), (i - 3) * 7));
    const opt = (sel) => weeks.map((w) => `<option value="${F.iso(w)}"${F.iso(w) === sel ? " selected" : ""}>Week of ${F.day(F.iso(w), { day: "numeric", month: "short" })}</option>`).join("");
    const el = K.confirmWindow({
      title: "Copy a week",
      subtitle: "The same people on the same duties, another week",
      icon: "ri-file-copy-line",
      go: '<i class="ri-file-copy-line me-1"></i>Copy it',
      body: K.parts([
        { icon: "ri-calendar-line", title: "Copy", body: `<select class="form-select" id="cpFrom">${opt(F.iso(addDays(monday(new Date()), -7)))}</select>` },
        { icon: "ri-calendar-check-line", title: "Onto", hint: "Anyone already on that week is replaced", body: `<select class="form-select" id="cpTo">${opt(F.iso(monday(new Date())))}</select>` },
      ]),
      run: async () => {
        const res = await FacilitiesAPI.copyRota({ from: document.getElementById("cpFrom").value, to: document.getElementById("cpTo").value });
        if (res.ok) load();
        return res;
      },
    });
    UI.enhanceSelect(el.querySelector("#cpFrom"), { search: false });
    UI.enhanceSelect(el.querySelector("#cpTo"), { search: false });
  }

  function init() {
    $("rtPrev").addEventListener("click", () => ((state.from = addDays(state.from, -28)), load()));
    $("rtNext").addEventListener("click", () => ((state.from = addDays(state.from, 28)), load()));
    $("rtToday").addEventListener("click", () => ((state.from = monday(new Date())), load()));
    $("copyBtn")?.addEventListener("click", copyWindow);
    $("rtTable").addEventListener("click", (e) => {
      const c = e.target.closest("[data-cell]");
      if (!c || !CTX.can.manage) return;
      const [date, service, key] = c.dataset.cell.split("|");
      const duty = state.data.duties.find((x) => x.key === key);
      F.rotaWindow({ date, service, duty, people: state.data.cells[`${date}|${service}|${key}`] || [], onDone: () => load() });
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
