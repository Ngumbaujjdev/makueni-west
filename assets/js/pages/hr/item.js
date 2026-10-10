/**
 * ============================================================================
 * STAFF - one position, grade or allowance (hr/item.php?kind=&id=)
 * ============================================================================
 * docs/specs/hr-spec.md: what applies here - this place's own version, else
 * the nearest above - beside the version set by the diocese or region; set
 * our own (a position's pay package, a grade's range, an allowance's amount)
 * or go back to the one from above; who holds it.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const K = PeopleKit;
  const API = HrAPI;
  const CTX = window.HR_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const params = new URLSearchParams(window.location.search);
  const KIND = ["position", "grade", "allowance"].includes(params.get("kind")) ? params.get("kind") : "position";
  const ID = Number(params.get("id"));
  const NAMES = { position: ["Position", "ri-briefcase-4-line", "primary"], grade: ["Grade", "ri-bar-chart-box-line", "success"], allowance: ["Allowance", "ri-hand-coin-line", "warning"] };
  const LEVELS = { church: "Churches", region: "Regions", diocese: "The diocese" };
  let d = null;
  let opts = null;

  const money = (v) => (v === null || v === undefined || v === "" ? null : A.money(v, { cents: false }));
  const gradeName = (id) => {
    const g = (opts?.grades || []).find((x) => String(x.id) === String(id));
    return id ? (g ? `${g.code} · ${g.name}` : "A grade not used here") : null;
  };
  const card = (title, icon, color, body, extra = "") => `<div class="card custom-card acc-rec-card"><div class="card-header"><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-${color} text-white"><i class="${icon}"></i></span>${title}</div>${extra}</div><div class="card-body">${body}</div></div>`;
  const line = (label, value) => `<div class="d-flex justify-content-between gap-3 py-2 border-bottom"><span>${label}</span><span class="text-end fw-semibold">${value ?? '<span class="acc-sub fw-normal">Not set</span>'}</span></div>`;

  /** The values of a version, as lines. */
  function values(v) {
    if (!v) return "";
    if (KIND === "grade") return line("Least a month", money(v.min_pay)) + line("Most a month", money(v.max_pay)) + line("Usual basic pay", money(v.default_pay));
    if (KIND === "allowance") return line("Usual amount a month", money(v.default_amount));
    const allow = (v.allowances || []).length ? v.allowances.map((a) => `${esc(a.name)} ${a.amount ? A.money(a.amount, { cents: false }) : ""}${a.follows ? ' <span class="acc-sub fw-normal">(usual)</span>' : ""}`).join("<br>") : null;
    return line("Usual grade", gradeName(v.grade_id) ? esc(gradeName(v.grade_id)) : null) + line("Usual basic pay", money(v.default_pay)) + line("Allowances", allow) + (v.duties ? `<div class="mt-2"><span class="acc-sub">Duties</span><p class="mb-0" style="white-space:pre-line">${esc(v.duties)}</p></div>` : "") + (v.notes ? `<div class="mt-2"><span class="acc-sub">Notes</span><p class="mb-0">${esc(v.notes)}</p></div>` : "");
  }

  function render() {
    const [kindLabel, icon, color] = NAMES[KIND];
    const here = d.version_from;
    const fromChip = d.own ? '<span class="soft-chip soft-success"><i class="ri-home-4-line"></i>Ours</span>' : `<span class="soft-chip soft-${d.owner.level === "diocese" ? "primary" : "purple"}"><i class="ri-government-line"></i>Set by ${esc(d.owner.name)}</span>`;
    const state = !d.is_active ? '<span class="badge bg-secondary text-dark">Switched off</span>' : d.hidden ? '<span class="badge bg-secondary text-dark">Not used here</span>' : '<span class="badge bg-success text-white"><i class="ri-check-line me-1"></i>In use here</span>';
    const actions = [
      d.can.edit ? '<button type="button" class="btn btn-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Change</button>' : "",
      d.can.ours ? `<button type="button" class="btn btn-primary" data-act="ours"><i class="ri-edit-2-line me-1"></i>${d.ours ? "Change our version" : "Set our version"}</button>` : "",
      d.can.ours && d.ours ? `<button type="button" class="btn btn-outline-danger" data-act="clear"><i class="ri-arrow-go-back-line me-1"></i>Back to ${esc(d.from_above.from?.name || "the one from above")}'s</button>` : "",
      `<a class="btn btn-outline-primary" href="${CTX.baseUrl}/positions.php${KIND === "position" ? "" : `?tab=${KIND}`}"><i class="ri-arrow-left-line me-1"></i>All ${KIND === "position" ? "positions" : KIND === "grade" ? "grades" : "allowances"}</a>`,
    ].join("");
    const facts =
      KIND === "position"
        ? [["Used at", d.levels.length ? d.levels.map((l) => LEVELS[l]).join(", ") : "Any level"], ["Usual grade", gradeName(d.grade_id) || "None"], ["Usual pay", money(d.default_pay) || "Not set"], ["People", String(d.holders.length)]]
        : KIND === "grade"
          ? [["Least", money(d.min_pay) || "-"], ["Most", money(d.max_pay) || "-"], ["Usual", money(d.default_pay) || "-"], ["People", String(d.holders.length)]]
          : [["Usual amount", money(d.default_amount) || "Per person"], ["Set by", d.owner.name], ["This version", here?.name || "-"], ["People", String(d.holders.length)]];
    const holders = d.holders.length
      ? `<div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>Person</th>${CTX.can.below ? "<th>Place</th>" : ""}<th class="text-end">${KIND === "allowance" ? "Allowance" : "Pay a month"}</th></tr></thead><tbody>${d.holders
          .map((h) => `<tr><td><div class="d-flex align-items-center gap-2">${A.avatar(h.name, "sm")}<div><a class="fw-semibold mb-link" href="${CTX.baseUrl}/person.php?id=${h.id}">${esc(h.name)}</a><div class="acc-sub">${esc(h.position || "")}</div></div></div></td>${CTX.can.below ? `<td>${esc(h.place || "")}</td>` : ""}<td class="text-end"><strong>${A.money(KIND === "allowance" ? h.amount : h.gross)}</strong></td></tr>`)
          .join("")}</tbody></table></div>`
      : A.empty(icon, "Nobody yet", KIND === "position" ? "When someone is given this position, they show here." : "Nobody on the staff has it yet.");

    $("itApp").innerHTML = `
      <div class="card custom-card acc-rec-hero"><div class="card-body">
        <div class="acc-rec-top">
          <span class="avatar avatar-lg avatar-rounded bg-${color} ${A.textOn(color)} flex-shrink-0"><i class="${icon} fs-22"></i></span>
          <div class="min-w-0 flex-fill"><span class="acc-rec-kind">${kindLabel}</span><h4 class="mb-1">${KIND === "grade" ? `${esc(d.code)} · ` : ""}${esc(d.name)}</h4>${d.description ? `<p class="mb-2">${esc(d.description)}</p>` : ""}<div class="d-flex flex-wrap gap-2 align-items-center">${fromChip}${d.ours ? '<span class="soft-chip soft-success"><i class="ri-edit-2-line"></i>Our version applies</span>' : !d.own && here && here.id !== d.owner.id ? `<span class="soft-chip soft-purple"><i class="ri-git-branch-line"></i>${esc(here.name)}'s version applies</span>` : ""}${state}${d.can.toggle_here && d.is_active ? `<label class="form-check form-switch mb-0 ms-1"><input class="form-check-input" type="checkbox" id="itHere"${d.hidden ? "" : " checked"}><span class="form-check-label">Use it here</span></label>` : ""}</div></div>
          <div class="d-flex flex-wrap gap-2">${actions}</div>
        </div>
        <div class="acc-rec-facts">${facts.map(([l, v]) => `<div><span>${esc(l)}</span><strong>${esc(v)}</strong></div>`).join("")}</div>
      </div></div>
      <div class="row">
        <div class="col-xl-8">
          ${card(`What applies at ${esc(d.place.name)}`, icon, color, values(d) + `<p class="acc-sub mb-0 mt-2"><i class="ri-information-line me-1"></i>${d.own ? "Ours - we set it up." : d.ours ? `Our own version of ${esc(d.owner.name)}'s.` : `${esc(here?.name || d.owner.name)}'s version - set our own to change it here.`}</p>`)}
          ${card("Who holds it", "ri-team-line", "purple", holders, `<span class="soft-chip soft-primary">${d.holders.length}</span>`)}
        </div>
        <div class="col-xl-4">
          ${d.own ? "" : card(`As ${esc(d.owner.name)} set it`, "ri-government-line", "primary", values(d.as_set))}
          ${!d.own && d.from_above?.from && d.from_above.from.id !== d.owner.id ? card(`${esc(d.from_above.from.name)}'s version`, "ri-git-branch-line", "purple", values(d.from_above)) : ""}
          ${d.ours ? card("Our version", "ri-edit-2-line", "success", values(d.our_version)) : ""}
          ${d.own ? card("How it reaches the places below", "ri-git-branch-line", "info", `<p class="mb-0">${CTX.level === "church" ? "It's used only here." : "The places below use it as it is, unless they set their own version."}</p>`) : ""}
        </div>
      </div>`;
    document.title = `${d.name} - ${kindLabel} - Makueni West Diocese`;
    const crumb = document.querySelector(".breadcrumb-item.active");
    if (crumb) crumb.textContent = d.name;
    const title = document.querySelector(".page-title");
    if (title) title.textContent = kindLabel;
  }

  async function load() {
    const [res, o] = await Promise.all([API.item(KIND, ID), opts ? { ok: true, data: opts } : API.options()]);
    if (o.ok) {
      opts = o.data;
      HrWindows.setup(opts, load);
    }
    if (!res.ok) {
      $("itApp").innerHTML = A.errorBox(res.message);
      return;
    }
    d = res.data;
    render();
  }

  document.addEventListener("DOMContentLoaded", () => {
    if (!ID) {
      $("itApp").innerHTML = A.errorBox("Open a position, grade or allowance from Positions & pay.");
      return;
    }
    $("itApp").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-act]");
      if (!b || !d) return;
      if (b.dataset.act === "ours") return HrWindows.ours(KIND, d, load);
      if (b.dataset.act === "edit") {
        const setup = await API.setup();
        return HrWindows.item(KIND, d, setup.ok ? setup.data.grades : [], load);
      }
      if (b.dataset.act === "clear")
        return K.confirmWindow({
          title: `Back to ${d.from_above.from?.name || "the one from above"}'s ${d.name}?`,
          subtitle: "Our own version is removed - people already on the staff keep the pay they have",
          icon: "ri-arrow-go-back-line",
          danger: true,
          go: '<i class="ri-arrow-go-back-line me-1"></i>Use theirs',
          body: `<p class="mb-0">From now on, adding someone as ${esc(d.name)} uses ${esc(d.from_above.from?.name || "the")} version.</p>`,
          run: async () => {
            const out = await API.clearOurs(KIND, d.id);
            if (out.ok) load();
            return out;
          },
        });
    });
    $("itApp").addEventListener("change", async (e) => {
      if (e.target.id !== "itHere") return;
      const out = await API.here(KIND, d.id, e.target.checked);
      if (!out.ok) {
        e.target.checked = !e.target.checked;
        return Toast.error(out.message);
      }
      Toast.success(out.message);
      load();
    });
    load();
  });
})();
