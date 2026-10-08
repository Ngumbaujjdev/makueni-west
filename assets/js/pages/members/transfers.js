/**
 * ============================================================================
 * MEMBERS - transfers in and out (transfers.php)
 * ============================================================================
 * The year's transfers: two cards (in, out), the list with its direction
 * filter, and the two windows - "Transfer in" adds a member who came from
 * another church, "Transfer out" picks a member and where they went.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MembersUI;
  const CTX = window.MEMBERS_CTX;
  const $ = (id) => document.getElementById(id);
  const thisYear = new Date().getFullYear();
  let year = Number(new URLSearchParams(window.location.search).get("year")) || thisYear;
  let data = null;

  function renderCards() {
    PeopleKit.statRow(
      $("statCardsRow"),
      [
        { icon: "ri-login-box-line", label: "Moved in", sub: `From other churches in ${data.year}`, value: M.num(data.in), color: "success" },
        { icon: "ri-logout-box-r-line", label: "Moved out", sub: `To other churches in ${data.year}`, value: M.num(data.out), color: "warning" },
        { icon: "ri-scales-3-line", label: "Net", sub: "In minus out", value: `${data.in - data.out > 0 ? "+" : ""}${data.in - data.out}`, color: data.in - data.out < 0 ? "danger" : "primary" },
      ],
      "col-xl-4 col-md-6",
    );
  }

  const K = PeopleKit;
  const DIR = {
    in: { label: "Moved in", icon: "ri-login-box-line", color: "success", from: "Came from" },
    out: { label: "Moved out", icon: "ri-logout-box-r-line", color: "warning", from: "Went to" },
  };
  const dirPill = (d) => `<span class="badge bg-${DIR[d].color} ${M.textOn(DIR[d].color)}"><i class="${DIR[d].icon} me-1"></i>${DIR[d].label}</span>`;
  const churchChip = (t) => (t.other.in_system ? '<span class="soft-chip soft-purple"><i class="ri-community-line"></i>Our diocese</span>' : '<span class="soft-chip soft-primary"><i class="ri-earth-line"></i>Outside the diocese</span>');

  function rowHtml(t) {
    const p = t.person;
    const name = p ? p.name : "Someone no longer in the register";
    const pills = [t.direction, t.other.in_system ? "diocese" : "", t.notified ? "told" : ""].filter(Boolean).join(" ");
    return `<tr class="mb-row" data-id="${t.id}" data-pills="${pills}">
      ${K.checkCell(t.id, name)}
      <td data-search="${M.esc(`${name} ${t.other.name} ${t.reason || ""}`)}" data-order="${M.esc(name.toLowerCase())}">
        <div class="d-flex align-items-center gap-2">
          ${p ? M.avatar(p, "sm") : '<span class="avatar avatar-sm avatar-rounded bg-light text-dark"><i class="ri-user-line"></i></span>'}
          <div class="min-w-0">${p ? `<a class="fw-semibold mb-link" href="${CTX.baseUrl}/member?id=${p.id}">${M.esc(name)}</a>` : `<span class="fw-semibold">${M.esc(name)}</span>`}<div class="mb-sub">${M.esc((p && (p.phone || p.area)) || "No phone")}</div></div>
        </div>
      </td>
      <td data-order="${t.direction}">${dirPill(t.direction)}</td>
      <td data-order="${M.esc(t.other.name.toLowerCase())}"><div class="fw-semibold">${M.esc(t.other.name)}</div><div class="d-flex flex-wrap gap-1 mt-1">${churchChip(t)}${t.notified ? '<span class="soft-chip soft-success"><i class="ri-notification-3-line"></i>Told</span>' : ""}</div></td>
      <td class="d-none d-lg-table-cell text-wrap tr-why">${t.reason ? M.esc(t.reason) : '<span class="mb-sub">Not given</span>'}</td>
      <td data-order="${t.on}" class="text-nowrap">${M.day(t.on)}</td>
      <td class="text-end"><button type="button" class="btn btn-sm btn-primary-light" data-view="${t.id}">View<i class="ri-arrow-right-s-line ms-1"></i></button></td>
    </tr>`;
  }

  let kit = null;
  function renderTable() {
    $("yearLine").textContent = `Members who moved in or out in ${data.year} - open one for the details`;
    const rows = data.items;
    kit?.destroy();
    kit = null;
    if (!rows.length) {
      $("transferPills").innerHTML = "";
      $("transferFilters").innerHTML = "";
      $("transferRows").innerHTML = `<tr><td colspan="7">${M.empty("ri-arrow-left-right-line", `No transfers in ${data.year}`, "When a member moves to another church, or someone joins from one, it shows here.")}</td></tr>`;
      return;
    }
    const byId = new Map(rows.map((t) => [t.id, t]));
    kit = K.listTable({
      tableId: "transferTable",
      stripId: "transferFilters",
      pillsId: "transferPills",
      rowsId: "transferRows",
      items: rows,
      rowHtml,
      noun: "transfers",
      searchPlaceholder: "Search by name, church or reason...",
      pills: [
        { key: "in", label: "Moved in", icon: "ri-login-box-line", color: "success", test: (t) => t.direction === "in" },
        { key: "out", label: "Moved out", icon: "ri-logout-box-r-line", color: "warning", test: (t) => t.direction === "out" },
        { key: "diocese", label: "Our diocese", icon: "ri-community-line", color: "purple", test: (t) => t.other.in_system },
        { key: "told", label: "Told", icon: "ri-notification-3-line", color: "primary", test: (t) => t.notified },
      ],
      sorts: [
        { key: "newest", label: "Newest first", order: [[5, "desc"]] },
        { key: "oldest", label: "Oldest first", order: [[5, "asc"]] },
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "church", label: "Church A-Z", order: [[3, "asc"], [5, "desc"]] },
      ],
      nonSortable: [6],
      actions: [{ key: "sms", label: "Send message", icon: "ri-chat-3-line", primary: true, run: (ids) => K.messagePeople(CTX.messagesUrl, ids.map((id) => byId.get(id)?.person).filter(Boolean)) }],
    });
  }

  /** One transfer, in a window: who, where from or to, when, why, and who recorded it. */
  function view(t) {
    const p = t.person;
    const d = DIR[t.direction];
    const fact = (icon, color, label, value, solid = false) =>
      `<div class="pp-fact" style="--q: var(--${color}-rgb)"><span class="pp-fact-icon${solid ? " is-solid" : ""}"><i class="${icon}"></i></span><div class="min-w-0"><span>${label}</span><strong>${value}</strong></div></div>`;
    const when = new Date(`${t.on}T12:00:00`).toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
    const recorded = t.recorded_at ? new Date(t.recorded_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "";
    const el = modal(
      d.label,
      d.icon,
      when,
      K.parts([
        {
          icon: "ri-user-3-line",
          title: "Who",
          body: p
            ? `<div class="tr-who">${M.avatar(p, "lg")}<div class="min-w-0"><div class="fw-bold fs-16 text-break">${M.esc(p.name)}</div><div class="d-flex flex-wrap gap-1 mt-1">${M.statusPill(p.status)}${p.congregation ? M.groupChip(p.congregation) : ""}</div>
                <div class="tr-who-contact">${p.phone ? `<span><i class="ri-phone-line"></i>${M.esc(p.phone)}</span>` : ""}${p.area ? `<span><i class="ri-map-pin-line"></i>${M.esc(p.area)}</span>` : ""}${p.gender ? `<span><i class="${p.gender === "male" ? "ri-men-line" : "ri-women-line"}"></i>${p.gender === "male" ? "Male" : "Female"}</span>` : ""}</div></div></div>`
            : '<p class="mb-0 fw-semibold">This person is no longer in the register.</p>',
        },
        {
          icon: "ri-arrow-left-right-line",
          title: "The move",
          body: `<div class="pp-facts">
            ${fact(d.icon, d.color, "Direction", d.label, true)}
            ${fact("ri-calendar-2-line", "primary", "On", M.day(t.on))}
            ${fact("ri-community-line", "purple", d.from, `${M.esc(t.other.name)}<small>${t.other.in_system ? "A church in our diocese" : "Outside the diocese"}</small>`)}
            ${t.direction === "out" ? fact("ri-notification-3-line", t.notified ? "success" : "secondary", "Their leaders", t.notified ? "Told they're coming" : "Not told") : fact("ri-user-follow-line", "pink", "Joined as", "A member")}
          </div>
          <div class="tr-why-box"><span><i class="ri-chat-quote-line"></i>Why</span><p class="mb-0">${t.reason ? M.esc(t.reason) : "No reason was given."}</p></div>`,
        },
        { icon: "ri-history-line", title: "Recorded", body: `<p class="mb-0">${t.recorded_by ? `By <strong>${M.esc(t.recorded_by)}</strong>` : "Recorded"}${recorded ? ` on ${recorded}` : ""}.</p>` },
      ]),
      `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>${p ? `<a class="btn btn-primary" href="${CTX.baseUrl}/member?id=${p.id}"><i class="ri-user-line me-1"></i>Open member</a>` : ""}`,
    );
    return el;
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(3, "col-xl-4 col-md-6");
    kit?.destroy();
    kit = null;
    $("transferRows").innerHTML = UI.renderTableLoading(7);
    const res = await MembersAPI.transfers({ year });
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      $("transferTableWrap").innerHTML = "";
      $("transferPills").innerHTML = "";
      return;
    }
    data = res.data;
    renderCards();
    renderTable();
  }

  // -------------------------------------------------------------- windows
  function modal(title, icon, sub, body, foot) {
    document.getElementById("trModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="trModal" tabindex="-1" aria-labelledby="trModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon"><i class="${icon}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="trModalTitle">${title}</h5><div class="app-modal-subtitle">${sub}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">${body}</div>
          <div class="modal-footer">${foot}</div>
        </div></div>
      </div>`,
    );
    const el = $("trModal");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    return el;
  }

  const churchPart = (churches, title) => ({
    icon: "ri-community-line",
    title,
    body: `<div class="row g-3">
      <div class="col-md-6"><label class="form-label" for="trChurch">The other church</label>
        <select class="form-select" id="trChurch"><option value="" data-icon="ri-earth-line" data-color="primary">Outside the diocese</option>${churches.map((c) => `<option value="${c.id}" data-icon="ri-community-line" data-color="purple">${M.esc(c.name)}</option>`).join("")}</select></div>
      <div class="col-md-6" id="trNameWrap"><label class="form-label" for="trName">Its name</label><input class="form-control" id="trName" maxlength="160" placeholder="e.g. AIC Wote"></div>
      <div class="col-md-6"><label class="form-label" for="trOn">On</label><input type="date" class="form-control" id="trOn" value="${DateField.iso(new Date())}"></div>
      <div class="col-md-6"><label class="form-label" for="trReason">Why <span class="fw-normal">(optional)</span></label><input class="form-control" id="trReason" maxlength="1000" placeholder="e.g. Moved for work"></div>
    </div>`,
  });

  function wireChurch(el, onChange) {
    DateField.enhance($("trOn"), { quick: ["today", "yesterday", "lastSunday"] });
    const sel = $("trChurch");
    UI.enhanceSelect(sel, { dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    const sync = () => {
      $("trNameWrap").hidden = !!sel.value;
      onChange?.(sel.value);
    };
    window.jQuery?.(sel).on("change", sync);
    sync();
    return sel;
  }

  // Two choices as coloured tiles, like the kinds in Record care.
  const pills = (name, options) =>
    `<div class="ec-choices is-varied is-tinted is-two" role="radiogroup">${options
      .map(([v, icon, label, color]) => `<label class="ec-choice" style="--q: var(--${color}-rgb)"><input type="radio" name="${name}" value="${v}"><span class="ec-choice-icon"><i class="${icon}"></i></span><strong>${label}</strong><span class="ec-choice-tick"><i class="ri-check-line"></i></span></label>`)
      .join("")}</div>`;

  function transferIn() {
    const el = modal(
      "Transfer in",
      "ri-login-box-line",
      "Someone joining us from another church",
      PeopleKit.parts([
        {
          icon: "ri-user-3-line",
          title: "Who is joining",
          body: `<div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="tiFirst">First name <span class="text-danger">*</span></label><input class="form-control" id="tiFirst" maxlength="80" placeholder="e.g. Grace" autocomplete="off"></div>
            <div class="col-md-6"><label class="form-label" for="tiLast">Last name</label><input class="form-control" id="tiLast" maxlength="80" placeholder="e.g. Ndinda" autocomplete="off"></div>
            <div class="col-md-6"><label class="form-label" for="tiPhone">Phone</label><input type="tel" class="form-control" id="tiPhone" maxlength="30" placeholder="e.g. 0712 345 678"></div>
            <div class="col-md-6"><label class="form-label" for="tiArea">Area</label><input class="form-control" id="tiArea" maxlength="80" placeholder="Where they live"></div>
            <div class="col-md-6"><span class="form-label d-block">Gender</span>${pills("tiGender", [["female", "ri-women-line", "Female", "pink"], ["male", "ri-men-line", "Male", "primary"]])}</div>
            <div class="col-md-6"><span class="form-label d-block">Part of</span>${pills("tiPart", [["main_church", "ri-community-line", "Main church", "purple"], ["sunday_school", "ri-book-open-line", "Sunday school", "pink"]])}</div>
          </div>`,
        },
        churchPart(data.churches, "Where they come from"),
      ]),
      `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="tiSave"><i class="ri-check-line me-1"></i>Add them</button>`,
    );
    const sel = wireChurch(el);
    const pick = (n) => el.querySelector(`input[name="${n}"]:checked`)?.value || null;
    $("tiSave").addEventListener("click", async (e) => {
      if (!$("tiFirst").value.trim()) return Toast.error("Write their first name.");
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Saving...");
      const res = await MembersAPI.transferIn({
        first_name: $("tiFirst").value.trim(), last_name: $("tiLast").value.trim() || null, phone: $("tiPhone").value.trim() || null, area: $("tiArea").value.trim() || null,
        gender: pick("tiGender"), congregation: pick("tiPart"),
        other_church_id: sel.value || null, other_church_name: sel.value ? null : $("trName").value.trim() || null, on: $("trOn").value, reason: $("trReason").value.trim() || null,
      });
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message);
      load();
    });
  }

  async function transferOut() {
    const people = await MembersAPI.list({ "status[]": "member" });
    const members = people.ok ? people.data.items : [];
    const el = modal(
      "Transfer out",
      "ri-logout-box-r-line",
      "A member moving to another church",
      PeopleKit.parts([
        { icon: "ri-user-3-line", title: "Who is moving", body: `<select class="form-select" id="toWho" aria-label="Who is moving"><option value="">Pick a member</option>${members.map((p) => `<option value="${p.id}">${M.esc(p.name)}${p.area ? ` · ${M.esc(p.area)}` : ""}</option>`).join("")}</select>` },
        churchPart(data.churches, "Where they're going"),
        { icon: "ri-notification-3-line", title: "Tell them", hint: "Only for a church in our diocese", body: `<div id="trNotifyWrap"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="trNotify" checked><label class="form-check-label" for="trNotify">Tell that church's leaders they're coming (with their name and phone)</label></div></div>` },
      ]),
      `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="toSave"><i class="ri-check-line me-1"></i>Transfer out</button>`,
    );
    UI.enhanceSelect($("toWho"), { dropdownParent: window.jQuery ? window.jQuery(el) : undefined, search: true });
    const sel = wireChurch(el, (v) => {
      $("trNotify").disabled = !v;
      if (!v) $("trNotify").checked = false;
    });
    $("toSave").addEventListener("click", async (e) => {
      const who = $("toWho").value;
      if (!who) return Toast.error("Pick who is moving.");
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Saving...");
      const res = await MembersAPI.transferOut(Number(who), { other_church_id: sel.value || null, other_church_name: sel.value ? null : $("trName").value.trim() || null, on: $("trOn").value, reason: $("trReason").value.trim() || null, notify: !!sel.value && $("trNotify").checked });
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      Toast.success(res.message);
      load();
    });
  }

  function init() {
    const years = [thisYear - 2, thisYear - 1, thisYear];
    $("yearSwitchWrap").innerHTML = UI.renderSegmented("trYear", years.map((y) => ({ value: String(y), label: String(y) })), String(year), { ariaLabel: "Year" });
    UI.wireSegmented("trYear", (v) => {
      year = Number(v);
      const q = new URLSearchParams(window.location.search);
      year === thisYear ? q.delete("year") : q.set("year", year);
      history.replaceState(null, "", `${window.location.pathname}${q.toString() ? `?${q}` : ""}`);
      load();
    });
    // A row opens its details (not when ticking it or following the name link).
    $("transferRows").addEventListener("click", (e) => {
      if (e.target.closest("a, input, .pp-check") && !e.target.closest("[data-view]")) return;
      const tr = e.target.closest("tr[data-id]");
      const t = tr && data?.items.find((x) => x.id === Number(tr.dataset.id));
      if (t) view(t);
    });
    $("inBtn")?.addEventListener("click", () => data && transferIn());
    $("outBtn")?.addEventListener("click", () => data && transferOut());
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
