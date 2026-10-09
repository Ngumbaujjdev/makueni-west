/**
 * ============================================================================
 * FACILITIES - Teams (teams.php)
 * ============================================================================
 * Who serves on each duty - ushering, welcome, sound... - one card per duty,
 * the people in the order the rota takes turns. Those who manage add people
 * (from the register, several at once, or a typed name), move them up and
 * down and take them off. Someone who has left stays on the list, marked,
 * and the rota skips them. The duties themselves are set in Settings.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const K = PeopleKit;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  let data = null;

  function cards(c) {
    K.statRow($("statCardsRow"), [
      { icon: "ri-team-line", label: "Teams", sub: c.teams < c.duties ? `${c.duties - c.teams} ${c.duties - c.teams === 1 ? "duty has" : "duties have"} nobody yet` : "Every duty has a team", value: `${F.num(c.teams)}<small class="pp-stat-of"> of ${F.num(c.duties)}</small>`, color: "primary", bar: { pct: c.duties ? Math.round((c.teams / c.duties) * 100) : 0, text: `${c.duties ? Math.round((c.teams / c.duties) * 100) : 0}% of duties` } },
      { icon: "ri-user-heart-line", label: "People serving", sub: c.away ? `${c.away} more no longer active` : "On one team or more", value: F.num(c.people), color: "success" },
      { icon: "ri-calendar-event-line", label: "Next service", sub: c.next_empty ? `${c.next_empty} ${c.next_empty === 1 ? "spot" : "spots"} still to fill` : "Every duty is filled", value: F.day(c.next_date, { weekday: "short", day: "numeric", month: "short" }), color: c.next_empty ? "warning" : "purple" },
      { icon: "ri-user-add-line", label: "Not on a team", sub: "Members who could serve", value: F.num(c.not_on_a_team), color: "pink" },
    ]);
  }

  function personRow(m, i, d) {
    const name = m.person_id && CTX.can.members ? `<a class="fw-semibold mb-link" href="${CTX.membersUrl}/member?id=${m.person_id}">${F.esc(m.name)}</a>` : `<strong>${F.esc(m.name)}</strong>`;
    const next = m.next ? F.day(m.next, { day: "numeric", month: "short" }) : "";
    const served = m.times ? `On duty ${m.times} ${m.times === 1 ? "time" : "times"}${next ? ` · next ${next}` : ""}` : next ? `Next on ${next}` : "Not on duty lately";
    const sub = m.away ? "Skipped by the rota" : [m.typed ? "Name only" : "", served].filter(Boolean).join(" · ");
    const acts = CTX.can.manage
      ? `<div class="fx-team-acts">
          <button type="button" class="btn btn-sm btn-icon btn-light border" data-move="${d.key}:${i}:-1"${i === 0 ? " disabled" : ""} aria-label="Move ${F.esc(m.name)} up"><i class="ri-arrow-up-s-line"></i></button>
          <button type="button" class="btn btn-sm btn-icon btn-light border" data-move="${d.key}:${i}:1"${i === d.members.length - 1 ? " disabled" : ""} aria-label="Move ${F.esc(m.name)} down"><i class="ri-arrow-down-s-line"></i></button>
          <button type="button" class="btn btn-sm btn-icon btn-light border" data-off="${m.id}" aria-label="Take ${F.esc(m.name)} off ${F.esc(d.label)}"><i class="ri-close-line"></i></button>
        </div>`
      : "";
    return `<li class="${m.away ? "is-away" : ""}">
      <span class="fx-team-no">${i + 1}</span>
      <span class="avatar avatar-sm avatar-rounded bg-${m.away ? "light text-dark" : `${UI.colorFor(m.name)} text-white`} flex-shrink-0">${F.esc(m.initials)}</span>
      <div class="flex-fill min-w-0">${name}${m.away ? ' <span class="soft-chip soft-warning">No longer active</span>' : ""}<small>${F.esc(sub)}</small></div>
      ${acts}
    </li>`;
  }

  function teamCard(d) {
    const live = d.members.filter((m) => !m.away).length;
    const add = CTX.can.manage ? `<button type="button" class="btn btn-sm btn-primary text-nowrap flex-shrink-0" data-add="${d.key}"><i class="ri-user-add-line me-1"></i>Add people</button>` : "";
    return `<div class="col-xxl-4 col-lg-6 d-flex">
      <div class="card custom-card fx-team-card flex-fill" id="team-${d.key}">
        <div class="card-header gap-2">
          ${F.dutyTile(d)}
          <div class="flex-fill min-w-0"><div class="card-title">${F.esc(d.label)}</div><span class="card-subtitle-text">${d.needed} ${d.needed === 1 ? "person" : "people"} each service</span></div>
          <span class="soft-chip soft-${d.color}">${live} ${live === 1 ? "person" : "people"}</span>
        </div>
        <div class="card-body">
          ${
            d.members.length
              ? `<ul class="fx-team-list">${d.members.map((m, i) => personRow(m, i, d)).join("")}</ul>`
              : `<div class="fx-team-empty"><i class="ri-user-add-line"></i><div><b>Nobody on ${F.esc(d.label.toLowerCase())} yet.</b><br>${CTX.can.manage ? "Add the people who do it - the rota takes turns through them." : "Those who manage the facilities add the people."}</div></div>`
          }
        </div>
        ${add ? `<div class="card-footer d-flex justify-content-between align-items-center gap-2"><span class="mb-sub">${live && live < d.needed ? `Needs ${d.needed} - add ${d.needed - live} more` : live ? `Each takes a turn about every ${Math.max(1, Math.ceil(live / d.needed))} ${Math.ceil(live / d.needed) === 1 ? "service" : "services"}` : ""}</span>${add}</div>` : ""}
      </div>
    </div>`;
  }

  function render() {
    cards(data.counts);
    $("tmTeams").innerHTML = data.duties.length
      ? data.duties.map(teamCard).join("")
      : `<div class="col-12"><div class="card custom-card"><div class="card-body">${MembersUI.empty("ri-team-line", "No duties set up yet", "Add the duties people do at each service - ushering, welcome, sound - in Settings.")}${CTX.can.manage ? `<div class="text-center"><a class="btn btn-primary" href="${CTX.settingsUrl}"><i class="ri-settings-3-line me-1"></i>Set up duties</a></div>` : ""}</div></div></div>`;
    if (window.location.hash) document.querySelector(window.location.hash)?.scrollIntoView({ block: "start" });
  }

  async function load() {
    const res = await FacilitiesAPI.teams();
    if (!res.ok) {
      $("tmTeams").innerHTML = `<div class="col-12">${MembersUI.errorBox(res.message)}</div>`;
      $("statCardsRow").innerHTML = "";
      return;
    }
    data = res.data;
    render();
  }

  async function move(key, i, by) {
    const d = data.duties.find((x) => x.key === key);
    const list = [...d.members];
    const [m] = list.splice(i, 1);
    list.splice(i + by, 0, m);
    d.members = list;
    render();
    const res = await FacilitiesAPI.orderTeam(key, list.map((x) => x.id));
    if (!res.ok) {
      Toast.error(res.message);
      load();
    }
  }

  function init() {
    $("tmTeams").addEventListener("click", async (e) => {
      const add = e.target.closest("[data-add]");
      if (add) return F.teamAddWindow(data.duties.find((d) => d.key === add.dataset.add), { onDone: () => load() });
      const mv = e.target.closest("[data-move]");
      if (mv) {
        const [key, i, by] = mv.dataset.move.split(":");
        return move(key, Number(i), Number(by));
      }
      const off = e.target.closest("[data-off]");
      if (off) {
        off.disabled = true;
        const res = await FacilitiesAPI.removeFromTeam(off.dataset.off);
        if (!res.ok) return ((off.disabled = false), Toast.error(res.message));
        Toast.success(res.message);
        load();
      }
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
