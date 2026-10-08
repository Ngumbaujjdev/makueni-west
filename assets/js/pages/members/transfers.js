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
  let table = null;

  function renderCards() {
    const cards = [
      { icon: "ri-login-box-line", label: "Moved in", value: M.num(data.in), color: "success", sub: `From other churches in ${data.year}` },
      { icon: "ri-logout-box-r-line", label: "Moved out", value: M.num(data.out), color: "secondary", sub: `To other churches in ${data.year}` },
      { icon: "ri-scales-3-line", label: "Net", value: `${data.in - data.out > 0 ? "+" : ""}${data.in - data.out}`, color: data.in - data.out < 0 ? "danger" : "primary", sub: "In minus out" },
    ];
    $("statCardsRow").innerHTML = cards.map((c) => `<div class="col-xl-4 col-md-6">${UI.renderSparkCard(c)}</div>`).join("");
  }

  function renderTable() {
    $("yearLine").textContent = `In ${data.year}`;
    const rows = data.items;
    if (!rows.length) {
      table?.destroy?.();
      table = null;
      $("transferFilters").hidden = true;
      $("transferRows").innerHTML = `<tr><td colspan="5">${M.empty("ri-arrow-left-right-line", `No transfers in ${data.year}`, "When a member moves to another church, or someone joins from one, it shows here.")}</td></tr>`;
      return;
    }
    $("transferFilters").hidden = false;
    $("transferRows").innerHTML = rows
      .map(
        (t) => `<tr>
          <td data-order="${t.on}">${M.day(t.on)}</td>
          <td>${t.person ? `<a class="fw-semibold mb-link" href="${CTX.baseUrl}/member?id=${t.person.id}">${M.esc(t.person.name)}</a>` : "-"}</td>
          <td data-search="${t.direction}">${t.direction === "in" ? '<span class="badge bg-success">Moved in</span>' : '<span class="badge bg-secondary text-dark">Moved out</span>'}</td>
          <td>${M.esc(t.other.name)}${t.other.in_system ? ' <span class="soft-chip soft-primary">Our diocese</span>' : ""}${t.notified ? ' <span class="soft-chip soft-success"><i class="ri-notification-3-line"></i>Told</span>' : ""}</td>
          <td class="text-wrap">${t.reason ? M.esc(t.reason) : '<span class="mb-sub">-</span>'}</td>
        </tr>`,
      )
      .join("");
    const filters = [{ id: "fDir", label: "In and out", options: [{ value: "in", label: "Moved in", color: "success" }, { value: "out", label: "Moved out", color: "secondary" }], columnIndex: 2, exact: true }];
    UI.renderFilterToolbar("transferFilters", { searchPlaceholder: "Search by name or church...", filters });
    table = UI.initListDataTable("transferTable", { hideDefaultSearch: true, order: [[0, "desc"]], pageLength: 25 });
    UI.enhanceSelect($("fDir"), { search: false });
    UI.wireFilterToolbar("transferFilters", table, filters, { noun: "transfers" });
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(3, "col-xl-4 col-md-6");
    $("transferRows").innerHTML = UI.renderTableLoading(5);
    const res = await MembersAPI.transfers({ year });
    if (!res.ok) {
      $("statCardsRow").innerHTML = `<div class="col-12">${M.errorBox(res.message)}</div>`;
      $("transferTableWrap").innerHTML = "";
      return;
    }
    data = res.data;
    renderCards();
    renderTable();
  }

  // -------------------------------------------------------------- windows
  function modal(title, icon, color, sub, body, foot) {
    document.getElementById("trModal")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="trModal" tabindex="-1" aria-labelledby="trModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down"><div class="modal-content">
          <div class="modal-header"><span class="app-modal-icon bg-${color} ${M.textOn(color)}"><i class="${icon}"></i></span><div class="flex-fill"><h5 class="modal-title" id="trModalTitle">${title}</h5><div class="app-modal-subtitle">${sub}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
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

  const churchField = (churches) => `
    <div class="col-md-6"><label class="form-label" for="trChurch">The other church</label>
      <select class="form-select" id="trChurch"><option value="">Outside the diocese</option>${churches.map((c) => `<option value="${c.id}">${M.esc(c.name)}</option>`).join("")}</select></div>
    <div class="col-md-6" id="trNameWrap"><label class="form-label" for="trName">Its name</label><input class="form-control" id="trName" maxlength="160" placeholder="e.g. AIC Wote"></div>
    <div class="col-md-6"><label class="form-label" for="trOn">On</label><input type="date" class="form-control" id="trOn" value="${new Date().toISOString().slice(0, 10)}"></div>
    <div class="col-md-6"><label class="form-label" for="trReason">Why <span class="fw-normal">(optional)</span></label><input class="form-control" id="trReason" maxlength="1000"></div>`;

  function wireChurch(el, onChange) {
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

  function transferIn() {
    const el = modal(
      "Transfer in",
      "ri-login-box-line",
      "success",
      "Someone joining us from another church",
      `<div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="tiFirst">First name <span class="text-danger">*</span></label><input class="form-control" id="tiFirst" maxlength="80"></div>
        <div class="col-md-6"><label class="form-label" for="tiLast">Last name <span class="text-danger">*</span></label><input class="form-control" id="tiLast" maxlength="80"></div>
        <div class="col-md-6"><label class="form-label" for="tiPhone">Phone</label><input type="tel" class="form-control" id="tiPhone" maxlength="30" placeholder="e.g. 0712 345 678"></div>
        <div class="col-md-6"><label class="form-label" for="tiGender">Gender</label><select class="form-select" id="tiGender"><option value="">Not given</option><option value="female">Female</option><option value="male">Male</option></select></div>
        ${churchField(data.churches)}
        <div class="col-12"><div class="form-text">Add the rest of their details on their page afterwards.</div></div>
      </div>`,
      `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" id="tiSave"><i class="ri-check-line me-1"></i>Add them</button>`,
    );
    const sel = wireChurch(el);
    UI.enhanceSelect($("tiGender"), { search: false, dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    $("tiSave").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Saving...");
      const res = await MembersAPI.transferIn({
        first_name: $("tiFirst").value.trim(), last_name: $("tiLast").value.trim(), phone: $("tiPhone").value.trim() || null, gender: $("tiGender").value || null,
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
      "secondary",
      "A member moving to another church",
      `<div class="row g-3">
        <div class="col-12"><label class="form-label" for="toWho">Who is moving <span class="text-danger">*</span></label><select class="form-select" id="toWho"><option value="">Pick a member</option>${members.map((p) => `<option value="${p.id}">${M.esc(p.name)}${p.phone ? ` · ${M.esc(p.phone)}` : ""}</option>`).join("")}</select></div>
        ${churchField(data.churches)}
        <div class="col-12" id="trNotifyWrap" hidden><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="trNotify" checked><label class="form-check-label" for="trNotify">Tell that church's leaders they're coming (with their name and phone)</label></div></div>
      </div>`,
      `<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="toSave"><i class="ri-check-line me-1"></i>Transfer out</button>`,
    );
    UI.enhanceSelect($("toWho"), { dropdownParent: window.jQuery ? window.jQuery(el) : undefined });
    const sel = wireChurch(el, (v) => ($("trNotifyWrap").hidden = !v));
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
    $("inBtn")?.addEventListener("click", () => data && transferIn());
    $("outBtn")?.addEventListener("click", () => data && transferOut());
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
