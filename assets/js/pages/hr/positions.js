/**
 * ============================================================================
 * STAFF - Positions & pay (hr/positions.php, every level)
 * ============================================================================
 * docs/specs/hr-spec.md: the positions, grades and allowances this place
 * uses - its own, and those set by the diocese or region (switched on or off
 * here, or given our own version on their page). Every list filters, sorts
 * and pages; tick several to switch them on or off here at once.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const K = PeopleKit;
  const API = HrAPI;
  const CTX = window.HR_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  let tab = ["position", "grade", "allowance"].includes(new URLSearchParams(window.location.search).get("tab")) ? new URLSearchParams(window.location.search).get("tab") : "position";

  const KIND = {
    position: { key: "positions", noun: "positions", title: "Positions", add: "Add a position", icon: "ri-briefcase-4-line", color: "primary" },
    grade: { key: "grades", noun: "grades", title: "Grades", add: "Add a grade", icon: "ri-bar-chart-box-line", color: "success" },
    allowance: { key: "allowances", noun: "allowances", title: "Allowances", add: "Add an allowance", icon: "ri-hand-coin-line", color: "warning" },
  };
  const LEVELS = { church: "Churches", region: "Regions", diocese: "The diocese" };
  const money = (v) => (v === null || v === undefined ? null : A.money(v, { cents: false }));
  const itemUrl = (r) => `${CTX.baseUrl}/item.php?kind=${tab}&id=${r.id}`;
  const usedHere = (r) => r.is_active && !r.hidden;

  /** One short line under the name: what it pays or where it is used. */
  function subline(r) {
    if (tab === "position") return [r.levels.length ? r.levels.map((l) => LEVELS[l]).join(", ") : "Any level", r.grade, money(r.default_pay) && `usually ${money(r.default_pay)}`, r.allowances?.length && `+ ${r.allowances.map((a) => a.name).join(", ")}`].filter(Boolean).map(esc).join(" · ");
    if (tab === "grade") return r.min_pay !== null || r.max_pay !== null ? `${money(r.min_pay) || "-"} to ${money(r.max_pay) || "-"}${r.default_pay ? ` · usually ${money(r.default_pay)}` : ""}` : r.default_pay ? `Usually ${money(r.default_pay)}` : "No range set";
    return r.default_amount ? `Usually ${money(r.default_amount)} a month` : "Amount set per person";
  }

  const setBy = (r) =>
    r.own
      ? '<span class="soft-chip soft-success"><i class="ri-home-4-line"></i>Ours</span>'
      : `<span class="soft-chip soft-${r.owner.level === "diocese" ? "primary" : "purple"}"><i class="${r.owner.level === "diocese" ? "ri-government-line" : "ri-map-pin-line"}"></i>${esc(r.owner.name)}</span>${r.ours ? '<div class="mt-1"><span class="soft-chip soft-success"><i class="ri-edit-2-line"></i>Our version</span></div>' : ""}`;
  const here = (r) => (!r.is_active ? '<span class="badge bg-secondary text-dark">Switched off</span>' : r.hidden ? '<span class="badge bg-secondary text-dark">Not used here</span>' : '<span class="badge bg-success text-white"><i class="ri-check-line me-1"></i>In use</span>');

  function actions(r) {
    const open = `<a href="${itemUrl(r)}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a>`;
    if (r.can.edit) return `<div class="d-inline-flex gap-1 align-items-center"><button type="button" class="btn btn-sm btn-icon btn-outline-primary" data-edit="${r.id}" title="Change" aria-label="Change ${esc(r.name)}"><i class="ri-edit-line"></i></button>${r.in_use ? "" : `<button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-remove="${r.id}" title="Remove" aria-label="Remove ${esc(r.name)}"><i class="ri-delete-bin-line"></i></button>`}${open}</div>`;
    if (r.can.toggle_here && r.is_active) return `<div class="d-inline-flex gap-2 align-items-center"><label class="form-check form-switch mb-0" title="Use it here"><input class="form-check-input" type="checkbox" data-here="${r.id}"${r.hidden ? "" : " checked"} aria-label="Use ${esc(r.name)} here"></label>${open}</div>`;
    return open;
  }

  function rowHtml(r) {
    const k = KIND[tab];
    return `<tr class="mb-row${usedHere(r) ? "" : " opacity-75"}" data-id="${r.id}" data-href="${itemUrl(r)}" data-pills="${[r.own ? "ours" : "above", r.ours ? "ourversion" : "", usedHere(r) ? "" : "off"].join(" ")}" data-f-owner="${r.owner.id}" data-f-level="${tab === "position" ? (r.levels.length ? r.levels.join(" ") : "any") : "any"}">
      ${K.checkCell(r.id, r.name)}
      <td data-search="${esc(`${r.code || ""} ${r.name} ${r.description || ""}`)}" data-order="${esc((r.code || r.name).toLowerCase())}">
        <div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${k.color} ${A.textOn(k.color)} flex-shrink-0"><i class="${k.icon}"></i></span><div class="min-w-0"><a class="fw-semibold mb-link" href="${itemUrl(r)}">${tab === "grade" ? `${esc(r.code)} · ` : ""}${esc(r.name)}</a><div class="mb-sub">${subline(r)}</div></div></div>
      </td>
      <td class="d-none d-md-table-cell" data-order="${r.own ? "0" : esc(r.owner.name)}">${setBy(r)}</td>
      <td data-order="${usedHere(r) ? 0 : 1}">${here(r)}</td>
      <td class="text-end d-none d-sm-table-cell" data-order="${r.in_use}">${r.in_use ? `<strong>${r.in_use}</strong>` : '<span class="mb-sub">-</span>'}</td>
      <td class="text-end">${actions(r)}</td>
    </tr>`;
  }

  async function toggleMany(ids, on) {
    const rows = ids.map((id) => data[KIND[tab].key].find((r) => r.id === id)).filter((r) => r && r.can.toggle_here && r.is_active);
    if (!rows.length) return Toast.error("Only ones set above us can be switched on or off here - change your own instead.");
    for (const r of rows) {
      const out = await API.here(tab, r.id, on);
      if (!out.ok) return Toast.error(out.message);
    }
    Toast.success(`${rows.length} ${on ? "now used here" : "no longer used here"}.`);
    load();
  }

  function render() {
    const k = KIND[tab];
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("setTitle").textContent = k.title;
    if ($("addLabel")) $("addLabel").textContent = k.add;
    const rows = data[k.key];
    $("setCount").textContent = rows.filter(usedHere).length;
    HrWindows.fresh("setTable");
    if (!rows.length) {
      $("setPills").innerHTML = "";
      $("setFilters").innerHTML = "";
      $("setRows").innerHTML = `<tr><td colspan="6">${A.empty(k.icon, `No ${k.noun} yet`, CTX.can.setup ? `Add the ${k.noun} this place uses.` : "None set up yet.")}</td></tr>`;
      return;
    }
    const owners = [...new Map(rows.map((r) => [r.owner.id, r.own ? `${r.owner.name} (ours)` : r.owner.name])).entries()];
    K.listTable({
      tableId: "setTable",
      stripId: "setFilters",
      pillsId: "setPills",
      rowsId: "setRows",
      items: rows,
      rowHtml,
      noun: k.noun,
      searchPlaceholder: `Search ${k.noun}...`,
      pills: [
        { key: "ours", label: "Ours", icon: "ri-home-4-line", color: "success", test: (r) => r.own },
        { key: "above", label: "Set above us", icon: "ri-git-branch-line", color: "primary", test: (r) => !r.own },
        { key: "ourversion", label: "Our version", icon: "ri-edit-2-line", color: "purple", test: (r) => r.ours },
        { key: "off", label: "Not used here", icon: "ri-eye-off-line", color: "secondary", test: (r) => !usedHere(r) },
      ],
      selects: [
        ...(tab === "position" ? [{ key: "level", label: "Used anywhere", options: [["church", "Churches"], ["region", "Regions"], ["diocese", "The diocese"], ["any", "Any level"]].map(([value, label]) => ({ value, label, icon: "ri-community-line", color: "primary" })) }] : []),
        ...(owners.length > 1 ? [{ key: "owner", label: "Set by anyone", options: owners.map(([value, label]) => ({ value: String(value), label, icon: "ri-government-line", color: "purple" })) }] : []),
      ],
      sorts: [
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "people", label: "Most people", order: [[4, "desc"]] },
        { key: "setby", label: "Set by", order: [[2, "asc"], [1, "asc"]] },
      ],
      nonSortable: [5],
      actions: CTX.can.setup
        ? [
            { key: "on", label: "Use here", icon: "ri-eye-line", primary: true, run: (ids) => toggleMany(ids, true) },
            { key: "off", label: "Don't use here", icon: "ri-eye-off-line", run: (ids) => toggleMany(ids, false) },
          ]
        : [],
    });
  }

  async function load() {
    $("setRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.setup();
    if (!res.ok) {
      $("setWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    const used = (list) => list.filter(usedHere).length;
    $("figPosition").textContent = `${used(data.positions)} in use`;
    $("figGrade").textContent = `${used(data.grades)} in use`;
    $("figAllowance").textContent = `${used(data.allowances)} in use`;
    render();
  }

  // ------------------------------------------------------------ wiring

  document.addEventListener("DOMContentLoaded", () => {
    $("addBtn")?.addEventListener("click", () => HrWindows.item(tab, null, data.grades, load));
    document.querySelectorAll("#hrTabs [data-tab]").forEach((b) =>
      b.addEventListener("click", () => {
        tab = b.dataset.tab;
        const p = new URLSearchParams(window.location.search);
        tab === "position" ? p.delete("tab") : p.set("tab", tab);
        ["q", "pill", "sort", "level", "owner"].forEach((x) => p.delete(x));
        history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
        render();
      }),
    );
    // On the wrapper: the table itself is swapped for a clean copy on each draw.
    $("setWrap").addEventListener("click", async (ev) => {
      const list = data[KIND[tab].key];
      const ed = ev.target.closest("[data-edit]");
      if (ed) return HrWindows.item(tab, list.find((r) => String(r.id) === ed.dataset.edit), data.grades, load);
      const rm = ev.target.closest("[data-remove]");
      if (rm) {
        const r = list.find((x) => String(x.id) === rm.dataset.remove);
        return K.confirmWindow({
          title: `Remove ${r.name}?`,
          subtitle: "Nobody uses it",
          icon: "ri-delete-bin-line",
          danger: true,
          go: '<i class="ri-delete-bin-line me-1"></i>Remove',
          body: `<p class="mb-0">${esc(r.name)} will no longer be offered${CTX.level !== "church" ? " here or below" : ""}.</p>`,
          run: async () => {
            const out = await API.removeItem(tab, r.id);
            if (out.ok) load();
            return out;
          },
        });
      }
      const tr = ev.target.closest("tr[data-href]");
      if (tr && !ev.target.closest("a, button, input, label")) window.location.href = tr.dataset.href;
    });
    $("setWrap").addEventListener("change", async (ev) => {
      const sw = ev.target.closest("[data-here]");
      if (!sw) return;
      const out = await API.here(tab, sw.dataset.here, sw.checked);
      if (!out.ok) {
        sw.checked = !sw.checked;
        return Toast.error(out.message);
      }
      Toast.success(out.message);
      load();
    });
    load();
  });
})();
