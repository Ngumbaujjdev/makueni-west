/**
 * ============================================================================
 * ACCOUNTING - Approval rules (approval-rules.php, the diocese)
 * ============================================================================
 * Every rule by level - its amount band and its stages in order (who, how
 * many must agree, what happens if nobody holds the role, how long they
 * have, who it goes to when late). The editor builds a rule stage by stage
 * from the roles that exist: of the place asking, of the place above, or
 * named people.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const K = PeopleKit;
  const API = AccountingAPI;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let meta = null;

  const LEVELS = [
    ["church", "Churches", "ri-community-line", "success"],
    ["region", "Regions", "ri-map-pin-line", "purple"],
    ["diocese", "The diocese", "ri-government-line", "primary"],
    ["", "Every level", "ri-stack-line", "secondary"],
  ];
  const band = (w) => (w.amount_min !== null && w.amount_max !== null ? `KES ${A.num(w.amount_min)} to ${A.num(w.amount_max)}` : w.amount_max !== null ? `Up to KES ${A.num(w.amount_max)}` : w.amount_min !== null ? `Above KES ${A.num(w.amount_min)}` : w.custom_conditions ? "Custom conditions" : "Any amount");

  function render() {
    $("ruleGroups").innerHTML = LEVELS.map(([lv, label, icon, color]) => {
      const rules = meta.items.filter((w) => (w.level || "") === lv);
      if (!rules.length && lv === "") return "";
      return `<div class="card custom-card"><div class="card-header"><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon}"></i></span><div><div class="card-title">${label}</div><span class="card-subtitle-text">${rules.length} ${rules.length === 1 ? "rule" : "rules"}</span></div></div></div>
        <div class="card-body"><div class="acc-rules">${rules.length ? rules.map(card).join("") : '<p class="acc-muted-line mb-0">No rule - anyone who authorises payments there approves (never their own).</p>'}</div></div></div>`;
    }).join("");
  }

  function card(w) {
    return `<div class="acc-rule${w.is_active ? "" : " is-off"}">
      <div class="d-flex justify-content-between gap-2 flex-wrap"><div><div class="fw-semibold">${esc(w.name)}</div><div class="acc-sub">${esc(w.subject_label)} · ${esc(band(w))}${w.is_active ? "" : " · switched off"}</div></div>
      <div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary" data-edit="${w.id}"><i class="ri-edit-line me-1"></i>Change</button><button type="button" class="btn btn-sm btn-outline-danger" data-del="${w.id}" aria-label="Remove ${esc(w.name)}"><i class="ri-delete-bin-line"></i></button></div></div>
      <ol class="acc-rule-stages">${w.stages.map((s, i) => `<li><span class="acc-rule-n">${i + 1}</span><div><strong>${esc(s.name)}</strong><small>${s.steps.map((st) => esc(st.label || "")).join(" or ")}${s.type === "all" ? " - everyone" : s.type === "quorum" ? ` - any ${s.quorum}` : ""}${s.sla_hours ? ` · ${s.sla_hours}h` : ""}${s.escalate_label ? ` · then ${esc(s.escalate_label)}` : ""}</small></div></li>`).join("")}</ol>
    </div>`;
  }

  // ------------------------------------------------------------ the editor

  const roleOptions = (level, selected) => meta.roles.filter((r) => !level || r.level === level).map((r) => `<option value="${esc(r.name)}"${r.name === selected ? " selected" : ""}>${esc(r.name)}</option>`).join("");
  function whoBlock(step, key) {
    const t = step?.resolver_type || "role_here";
    const cfg = step?.resolver_config || {};
    const role = (cfg.roles || [cfg.role])[0] || "";
    return `<div class="acc-who" data-who="${key}">
      <select class="form-select" data-k="type"><option value="role_here"${t === "role_here" ? " selected" : ""}>A role at the place asking</option><option value="role_above"${t === "role_above" ? " selected" : ""}>A role at the place above</option><option value="user"${t === "user" ? " selected" : ""}>Named people</option>${key === "esc" ? `<option value=""${!step ? " selected" : ""}>Nobody - it just waits</option>` : ""}</select>
      <select class="form-select" data-k="level"${t === "role_above" ? "" : " hidden"}><option value="region"${cfg.level === "region" ? " selected" : ""}>The region above</option><option value="diocese"${cfg.level === "diocese" ? " selected" : ""}>The diocese</option></select>
      <select class="form-select" data-k="role"${t === "user" || (key === "esc" && !step) ? " hidden" : ""}>${roleOptions(null, role)}</select>
      <div data-k="users"${t === "user" ? "" : " hidden"}><input type="search" class="form-control" data-k="search" placeholder="Search a name..."><div class="acc-who-picked" data-k="picked">${(cfg.user_ids || []).map((id) => `<span class="soft-chip soft-primary" data-uid="${id}">#${id}<button type="button" class="btn-close btn-close-sm ms-1" aria-label="Remove"></button></span>`).join("")}</div><div class="acc-who-found" data-k="found"></div></div>
    </div>`;
  }
  const readWho = (box) => {
    const type = box.querySelector('[data-k="type"]').value;
    if (!type) return null;
    if (type === "user") return { resolver_type: "user", resolver_config: { user_ids: [...box.querySelectorAll("[data-uid]")].map((x) => Number(x.dataset.uid)) } };
    const cfg = { roles: [box.querySelector('[data-k="role"]').value] };
    if (type === "role_above") cfg.level = box.querySelector('[data-k="level"]').value;
    return { resolver_type: type, resolver_config: cfg };
  };
  function wireWho(box) {
    const show = () => {
      const t = box.querySelector('[data-k="type"]').value;
      box.querySelector('[data-k="level"]').hidden = t !== "role_above";
      box.querySelector('[data-k="role"]').hidden = t === "user" || !t;
      box.querySelector('[data-k="users"]').hidden = t !== "user";
    };
    box.querySelector('[data-k="type"]').addEventListener("change", show);
    let timer = null;
    box.querySelector('[data-k="search"]').addEventListener("input", (e) => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const r = await API.approvalPeople(e.target.value.trim());
        box.querySelector('[data-k="found"]').innerHTML = r.ok ? r.data.slice(0, 8).map((p) => `<button type="button" class="btn btn-sm btn-light border" data-add-uid="${p.id}" data-name="${esc(p.name)}">${esc(p.name)}</button>`).join(" ") : "";
      }, 250);
    });
    box.addEventListener("click", (e) => {
      const add = e.target.closest("[data-add-uid]");
      if (add && !box.querySelector(`[data-uid="${add.dataset.addUid}"]`)) {
        box.querySelector('[data-k="picked"]').insertAdjacentHTML("beforeend", `<span class="soft-chip soft-primary" data-uid="${add.dataset.addUid}">${add.dataset.name}<button type="button" class="btn-close btn-close-sm ms-1" aria-label="Remove"></button></span>`);
      }
      const x = e.target.closest("[data-uid] .btn-close");
      if (x) x.closest("[data-uid]").remove();
    });
  }

  function stageHtml(s = {}) {
    return `<div class="acc-stage-edit" data-stage>
      <div class="d-flex gap-2 align-items-center mb-2"><span class="acc-rule-n" data-n></span><input type="text" class="form-control" data-f="name" maxlength="120" placeholder="Stage name, e.g. Pastor" value="${esc(s.name || "")}"><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="-1" aria-label="Move up"><i class="ri-arrow-up-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="1" aria-label="Move down"><i class="ri-arrow-down-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-outline-danger" data-remove-stage aria-label="Remove stage"><i class="ri-close-line"></i></button></div>
      <div class="row g-2">
        <div class="col-lg-6"><label class="form-label">Who approves</label>${whoBlock(s.steps?.[0], "who")}</div>
        <div class="col-lg-6"><label class="form-label">How many must agree</label><div class="d-flex gap-2"><select class="form-select" data-f="type"><option value="single"${s.type === "single" || !s.type ? " selected" : ""}>Any one of them</option><option value="all"${s.type === "all" ? " selected" : ""}>Every one of them</option><option value="quorum"${s.type === "quorum" ? " selected" : ""}>A number of them</option></select><input type="number" min="1" max="20" class="form-control" data-f="quorum" value="${s.quorum || 2}" style="max-width:5.5rem"${s.type === "quorum" ? "" : " hidden"} aria-label="How many"></div>
          <label class="form-label mt-2">If nobody else holds the role (e.g. they asked)</label><select class="form-select" data-f="on_empty"><option value="escalate"${s.on_empty === "escalate" ? " selected" : ""}>Go to whoever it escalates to</option><option value="skip"${s.on_empty === "skip" ? " selected" : ""}>Skip this stage</option><option value="block"${s.on_empty === "block" || !s.on_empty ? " selected" : ""}>Stop and tell the finance officer</option></select></div>
        <div class="col-sm-4"><label class="form-label">Hours to decide</label><input type="number" min="1" max="720" class="form-control" data-f="sla_hours" value="${s.sla_hours || ""}" placeholder="No limit"></div>
        <div class="col-sm-4"><label class="form-label">Then pass it up after (hours)</label><input type="number" min="1" max="720" class="form-control" data-f="escalate_after_hours" value="${s.escalate_after_hours || ""}" placeholder="24"></div>
        <div class="col-sm-4"><label class="form-label">If someone says no</label><select class="form-select" data-f="on_reject"><option value="terminate"${s.on_reject !== "return_previous" && s.on_reject !== "continue" ? " selected" : ""}>It stops</option><option value="return_previous"${s.on_reject === "return_previous" ? " selected" : ""}>Back to the stage before</option><option value="continue"${s.on_reject === "continue" ? " selected" : ""}>Carry on anyway</option></select></div>
        <div class="col-12"><label class="form-label">When late, or nobody: escalate to</label>${whoBlock(s.escalate_to, "esc")}</div>
      </div>
    </div>`;
  }

  function editor(w = null) {
    document.getElementById("ruleWindow")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="ruleWindow" tabindex="-1" aria-labelledby="ruleTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-shield-check-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="ruleTitle">${w ? `Change "${esc(w.name)}"` : "Add an approval rule"}</h5><div class="app-modal-subtitle">New requests follow it; ones already waiting keep their rule</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-filter-3-line"></i>When it applies</div>
            <div class="row g-2"><div class="col-lg-4"><label class="form-label">Name</label><input type="text" class="form-control" id="rlName" maxlength="150" value="${esc(w?.name || "")}" placeholder="e.g. Church - up to KES 50,000"></div>
            <div class="col-lg-3 col-sm-6"><label class="form-label">For</label><select class="form-select" id="rlSubject">${meta.subjects.map((s) => `<option value="${s.key}"${(w?.subject_type || "*") === s.key ? " selected" : ""}>${esc(s.label)}</option>`).join("")}</select></div>
            <div class="col-lg-2 col-sm-6"><label class="form-label">Level</label><select class="form-select" id="rlLevel">${LEVELS.map(([v, l]) => `<option value="${v}"${(w?.level || "") === v ? " selected" : ""}>${l}</option>`).join("")}</select></div>
            <div class="col-lg-3"><label class="form-label">Amount (KES)</label><div class="d-flex gap-1 align-items-center"><input type="number" min="0" class="form-control" id="rlMin" value="${w?.amount_min ?? ""}" placeholder="above"><span class="acc-sub">to</span><input type="number" min="0" class="form-control" id="rlMax" value="${w?.amount_max ?? ""}" placeholder="up to"></div></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="rlActive"${w?.is_active === false ? "" : " checked"}><label class="form-check-label" for="rlActive">In use</label></div></div></div></section>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Stages, in order</div><div id="rlStages"></div><button type="button" class="btn btn-sm btn-outline-primary" id="rlAdd"><i class="ri-add-line me-1"></i>Add a stage</button></section>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rlGo"><i class="ri-check-line me-1"></i>Save rule</button></div>
      </div></div></div>`,
    );
    const el = document.getElementById("ruleWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    const box = el.querySelector("#rlStages");
    const number = () => box.querySelectorAll("[data-stage]").forEach((s, i) => (s.querySelector("[data-n]").textContent = i + 1));
    const addStage = (s) => {
      box.insertAdjacentHTML("beforeend", stageHtml(s));
      const st = box.lastElementChild;
      st.querySelectorAll("[data-who]").forEach(wireWho);
      st.querySelector('[data-f="type"]').addEventListener("change", (e) => (st.querySelector('[data-f="quorum"]').hidden = e.target.value !== "quorum"));
      number();
    };
    (w?.stages?.length ? w.stages : [{}]).forEach(addStage);
    el.querySelector("#rlAdd").addEventListener("click", () => addStage({}));
    box.addEventListener("click", (e) => {
      const st = e.target.closest("[data-stage]");
      if (!st) return;
      if (e.target.closest("[data-remove-stage]") && box.querySelectorAll("[data-stage]").length > 1) st.remove();
      const mv = e.target.closest("[data-move]");
      if (mv) {
        const sib = mv.dataset.move === "-1" ? st.previousElementSibling : st.nextElementSibling;
        if (sib) mv.dataset.move === "-1" ? box.insertBefore(st, sib) : box.insertBefore(sib, st);
      }
      number();
    });
    bootstrap.Modal.getOrCreateInstance(el).show();
    el.querySelector("#rlGo").addEventListener("click", async (e) => {
      const b = e.currentTarget;
      const stages = [...box.querySelectorAll("[data-stage]")].map((st) => {
        const f = (k) => st.querySelector(`[data-f="${k}"]`).value;
        const who = readWho(st.querySelector('[data-who="who"]'));
        return { name: f("name").trim(), type: f("type"), quorum: f("type") === "quorum" ? Number(f("quorum")) : null, on_empty: f("on_empty"), on_reject: f("on_reject"), sla_hours: Number(f("sla_hours")) || null, escalate_after_hours: Number(f("escalate_after_hours")) || null, escalate_to: readWho(st.querySelector('[data-who="esc"]')), steps: who ? [who] : [] };
      });
      UI.setButtonLoading(b, "Saving...");
      const res = await API.saveWorkflow(w?.id, {
        name: el.querySelector("#rlName").value.trim(),
        subject_type: el.querySelector("#rlSubject").value,
        level: el.querySelector("#rlLevel").value || null,
        amount_min: el.querySelector("#rlMin").value === "" ? null : Number(el.querySelector("#rlMin").value),
        amount_max: el.querySelector("#rlMax").value === "" ? null : Number(el.querySelector("#rlMax").value),
        is_active: el.querySelector("#rlActive").checked,
        stages,
      });
      UI.restoreButton(b);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      load();
    });
  }

  async function load() {
    $("ruleGroups").innerHTML = `<div class="card custom-card"><div class="card-body">${A.empty("ri-loader-4-line", "Loading", "")}</div></div>`;
    const res = await API.workflows();
    if (!res.ok) {
      $("ruleGroups").innerHTML = A.errorBox(res.message);
      return;
    }
    meta = res.data;
    render();
  }

  document.addEventListener("DOMContentLoaded", () => {
    A.placeLine($("accPlaceLine"), null);
    $("addRuleBtn").addEventListener("click", () => editor());
    $("ruleGroups").addEventListener("click", async (e) => {
      const ed = e.target.closest("[data-edit]");
      if (ed) return editor(meta.items.find((w) => w.id === Number(ed.dataset.edit)));
      const del = e.target.closest("[data-del]");
      if (del && confirm("Remove this rule? Requests already waiting keep it.")) {
        const r = await API.deleteWorkflow(Number(del.dataset.del));
        r.ok ? (Toast.success(r.message), load()) : Toast.error(r.message);
      }
    });
    load();
  });
})();
