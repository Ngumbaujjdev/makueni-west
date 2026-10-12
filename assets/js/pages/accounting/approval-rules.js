/**
 * ============================================================================
 * ACCOUNTING - Approval rules (approval-rules.php, every level)
 * ============================================================================
 * Who signs what here (docs/specs/accounting-spec.md, A4b), laid out like
 * the OrchestrateIQ dashboard the user chose:
 *   - a pill tab bar: Overview, Rules, and the Approvals board's own views
 *   - the hero: "Who signs what at …" with the lane map - approvers down the
 *     side, amounts along the top, each stage as a block across the amounts
 *     it covers; amounts only the diocese's rules decide are hatched; the
 *     amount being tried is the blue column with the dashed line
 *   - Next steps, Detected, and "Try an amount" (who would sign today)
 *   - Rules: our own, those from above, the diocese's standard (and, for a
 *     region or the diocese, the places' own) - with the editor
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const API = AccountingAPI;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  const PALETTE = ["purple", "primary", "success", "warning", "pink"];
  const params = new URLSearchParams(window.location.search);
  let tab = params.get("view") === "rules" ? "rules" : "overview";
  let subject = ["payment_voucher", "requisition", "payroll_run"].includes(params.get("subject")) ? params.get("subject") : "payment_voucher";
  let map = null;
  let meta = null;
  let tried = null; // the amount being tried, and its answer

  const short = (v) => (v >= 1000000 ? `${v / 1000000}M` : v >= 1000 ? `${v / 1000}k` : String(v));
  const firstName = () => (window.AuthHelpers?.getUser?.()?.firstname || (JSON.parse(localStorage.getItem("user") || "null") || {}).firstname || "").trim();

  function keepUrl() {
    const p = new URLSearchParams(window.location.search);
    tab === "rules" ? p.set("view", "rules") : p.delete("view");
    subject === "payment_voucher" ? p.delete("subject") : p.set("subject", subject);
    history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
  }

  // ------------------------------------------------------------ the hero and its lane map

  function hero() {
    const d = map.detected;
    const setUp = map.checks.filter((c) => c.first).length;
    const marks = map.marks;
    const triedCol = tried ? marks.reduce((at, m, i) => (tried.amount > m ? i : at), 0) : null;
    const cols = marks.length;
    const lanes = map.rows.length
      ? map.rows
          .map((r, ri) => {
            const colour = PALETTE[ri % PALETTE.length];
            const blocks = map.blocks.filter((b) => b.row === ri);
            const taken = new Set(blocks.flatMap((b) => Array.from({ length: b.to - b.from + 1 }, (_, k) => b.from + k)));
            const row = ri + 2;
            const hatch = map.segments.map((s, si) => (s.standard_only && !taken.has(si) ? `<span class="ap-hatch" style="grid-column:${si + 2};grid-row:${row}"></span>` : "")).join("");
            return `<div class="ap-lane">
                <div class="ap-who" style="grid-column:1;grid-row:${row}"><span class="ap-who-icon soft-${colour}"><i class="${esc(r.icon)}"></i></span><div class="min-w-0"><div class="ap-who-name">${esc(r.name)}</div><div class="ap-who-sub">${r.has_someone ? `${esc(r.people.slice(0, 2).join(", ") || r.who)} · ${r.approvals} ${r.approvals === 1 ? "approval" : "approvals"}` : '<span class="text-danger fw-semibold">Nobody here</span>'}</div></div></div>
                ${hatch}
                ${blocks
                  .map(
                    (b) => `<div class="ap-block bg-${colour}" style="grid-column:${b.from + 2} / ${b.to + 3};grid-row:${row}" title="${esc(b.rule)} - ${esc(b.owner)}">
                    <span class="ap-block-icon"><i class="${esc(r.icon)}"></i></span><span class="ap-block-label">${esc(b.label)}</span>
                    <span class="ap-block-pill"><span class="ap-dot ${b.status === "ok" ? "is-ok" : b.status === "skipped" ? "is-skip" : "is-no"}"></span>${b.status === "ok" ? "Has someone" : b.status === "skipped" ? "Skipped" : "Nobody"}</span></div>`,
                  )
                  .join("")}
              </div>`;
          })
          .join("")
      : `<div class="ap-lanes-empty" style="grid-column:1 / -1">${A.empty("ri-shield-check-line", "No rule covers these documents here", "Anyone who can authorise payments approves them - never their own.")}</div>`;
    $("apHero").innerHTML = `<div class="card-body">
      <div class="ap-hero-head">
        <div class="min-w-0"><h2 class="ap-hero-title">Who signs what at ${esc(map.place.name)}</h2>
          <p class="ap-hero-sub">Worked out from ${d.rules} ${d.rules === 1 ? "rule" : "rules"}${d.own_rules ? ` (${d.own_rules} our own)` : ""} and ${d.decided_30} ${d.decided_30 === 1 ? "approval" : "approvals"} in the last 30 days. ${map.place.level === "diocese" ? "" : `Up to KES ${A.num(map.ceiling)} ${map.place.level === "church" ? "this church" : "this region"} can set its own; above it the diocese's rules apply.`}</p></div>
        <div class="ap-hero-tools">
          <span class="ap-chip-ok"><i class="ri-check-line"></i>${setUp ? `${setUp} to fix` : "All set"} · ${d.stages} ${d.stages === 1 ? "step" : "steps"}</span>
          <button type="button" class="btn ap-btn-soft" data-go-rules><i class="ri-equalizer-line me-1"></i>Rules</button>
          <a class="btn ap-btn-soft" href="${CTX.baseUrl}/approvals.php"><i class="ri-arrow-right-up-line me-1"></i>Live map</a>
        </div>
      </div>
      <div class="ap-lanes-wrap"><div class="ap-lanes" style="grid-template-columns: minmax(11rem, 12.5rem) repeat(${cols}, minmax(4.6rem, 1fr)); grid-template-rows: auto repeat(${Math.max(map.rows.length, 1)}, 3.6rem);">
        <div class="ap-lanes-corner" style="grid-column:1;grid-row:1">Who signs</div>
        ${marks.map((m, i) => `<div class="ap-mark${i === triedCol ? " is-now" : ""}${map.segments[i]?.standard_only ? " is-std" : ""}" style="grid-column:${i + 2};grid-row:1"><small>KES</small><b>${i === cols - 1 ? `${short(m)}+` : short(m)}</b></div>`).join("")}
        ${lanes}
        ${triedCol !== null ? `<div class="ap-now-line" style="grid-column:${triedCol + 2};grid-row:2 / span ${Math.max(map.rows.length, 1)}"></div>` : ""}
      </div></div>
      <div class="ap-legend"><span><span class="ap-hatch-key"></span>The diocese's rules decide these amounts</span><span><span class="ap-dot is-ok"></span>Someone holds it here</span><span><span class="ap-dot is-no"></span>Nobody holds it here</span></div>
    </div>`;
  }

  // ------------------------------------------------------------ next steps, detected

  function next() {
    const c = map.checks;
    const chips = (x) => `<div class="ap-next-chips">${x.count ? `<span class="ap-pill"><i class="ri-community-line"></i>${x.count} ${x.count === 1 ? "church" : "churches"}</span>` : ""}<span class="ap-pill"><i class="ri-time-line"></i>~2 min</span><span class="ap-pill"><i class="${{ roles: "ri-user-add-line", rules: "ri-equalizer-line", blocked: "ri-error-warning-line", admin: "ri-key-2-line" }[x.action] || "ri-arrow-right-line"}"></i>${{ roles: "Roles", rules: "Rules", blocked: "Waiting", admin: "Permissions" }[x.action] || "Open"}</span></div>`;
    $("apNext").innerHTML = `<div class="card-body">
      <div class="ap-card-head"><h3>Next steps</h3><button type="button" class="btn ap-btn-soft btn-sm" data-go-rules><i class="ri-arrow-right-up-line me-1"></i>View all</button></div>
      ${c.length
        ? c.slice(0, 3).map((x, i) => `<button type="button" class="ap-next${i === 0 && x.first ? " is-first" : ""}" data-action="${esc(x.action)}"><div class="d-flex justify-content-between gap-2"><span class="ap-next-title">${esc(x.title)}</span>${i === 0 && x.first ? '<span class="ap-first"><span class="ap-dot is-dark"></span>Do first</span>' : ""}</div><div class="ap-next-text">${esc(x.text)}</div>${i === 0 ? chips(x) : ""}</button>`).join("")
        : `<div class="ap-next is-done"><div class="ap-next-title"><i class="ri-checkbox-circle-line text-success me-1"></i>Nothing to fix</div><div class="ap-next-text">Every step here has someone, and nothing is stuck.</div></div>`}
    </div>`;
  }

  function detected() {
    const d = map.detected;
    const tile = (icon, colour, n, label, chip, chipIcon) => `<div class="ap-tile"><span class="ap-tile-icon soft-${colour}"><i class="${icon}"></i></span><div class="ap-tile-n">${n} ${label}</div><span class="ap-tile-chip"><i class="${chipIcon}"></i>${chip}</span></div>`;
    $("apDetected").innerHTML = `<div class="card-body">
      <div class="ap-card-head"><h3>Detected</h3><a class="btn ap-btn-soft btn-sm" href="${CTX.baseUrl}/approvals.php"><i class="ri-arrow-right-up-line me-1"></i>View all</a></div>
      <div class="ap-tiles">
        ${tile("ri-equalizer-line", "success", d.rules, d.rules === 1 ? "rule" : "rules", d.own_rules ? `${d.own_rules} our own` : "from the diocese", "ri-checkbox-circle-line")}
        ${tile("ri-route-line", "purple", d.stages, d.stages === 1 ? "step" : "steps", d.nobody ? `${d.nobody} with nobody` : "all have someone", d.nobody ? "ri-error-warning-line" : "ri-checkbox-circle-line")}
        ${tile("ri-time-line", "info", d.waiting, "waiting", d.oldest_days !== null ? `oldest ${d.oldest_days} ${d.oldest_days === 1 ? "day" : "days"}` : "none waiting", "ri-time-line")}
        ${tile("ri-flashlight-line", "warning", d.blocked, "stuck", d.blocked ? "needs someone" : "none stuck", "ri-pulse-line")}
      </div>
    </div>`;
  }

  // ------------------------------------------------------------ try an amount

  function tryPanel() {
    const name = firstName();
    const result = tried?.answer;
    $("apTry").innerHTML = `<div class="card-body ap-try">
      <div class="ap-orb"><i class="ri-shield-check-line"></i></div>
      <h3 class="ap-try-title">${name ? `Welcome, ${esc(name)}` : "Welcome"}<br>Who would sign a payment?</h3>
      <div class="ap-try-chips">${map.subjects.map((s) => `<button type="button" class="ap-pill${s.key === subject ? " is-on" : ""}" data-subject="${s.key}"><i class="${{ payment_voucher: "ri-file-list-3-line", requisition: "ri-hand-coin-line", payroll_run: "ri-team-line" }[s.key]}"></i>${esc(s.label)}</button>`).join("")}</div>
      ${result
        ? `<div class="ap-try-answer"><div class="ap-try-rule"><span class="soft-chip soft-primary">${esc(result.rule?.owner || "No rule")}</span> ${result.rule ? `<b>${esc(result.rule.name)}</b>` : ""} · KES ${A.num(result.amount)}</div>
            ${result.stages.length ? `<ol class="ap-try-path">${result.stages.map((s, i) => `<li><span class="ap-try-n">${i + 1}</span><div><b>${esc(s.name)}</b><div class="acc-sub">${s.people.length ? esc(s.people.join(", ")) : esc(s.who)}</div>${s.note ? `<div class="ap-try-note">${esc(s.note)}</div>` : ""}</div></li>`).join("")}</ol>` : `<p class="acc-sub mb-0">${esc(result.note || "")}</p>`}</div>`
        : `<div class="ap-ask-label">Ask about what we found</div>
           ${map.questions.length ? map.questions.map((q) => `<a class="ap-ask" href="${q.type ? A.link("record.php", { type: q.type, id: q.id }) : `${CTX.baseUrl}/approvals.php`}"><i class="ri-sparkling-2-line"></i><span>${esc(q.text)}</span><i class="ri-arrow-right-line ms-auto"></i></a>`).join("") : '<div class="ap-ask is-quiet"><i class="ri-sparkling-2-line"></i><span>Nothing is waiting here right now</span></div>'}`}
      <form class="ap-try-form" id="apTryForm"><input type="text" inputmode="numeric" class="form-control" id="apTryAmount" placeholder="Type an amount, e.g. 75,000" value="${tried ? A.num(tried.amount) : ""}" aria-label="Amount to try"><button type="submit" class="btn btn-primary btn-icon" aria-label="Try it"><i class="ri-send-plane-2-line"></i></button></form>
      <div class="ap-try-foot">${result ? '<button type="button" class="btn btn-link btn-sm p-0" data-clear-try>Clear</button>' : `<span>Try one: </span>${[25000, 75000, 250000].map((v) => `<button type="button" class="btn btn-link btn-sm p-0" data-try="${v}">${A.num(v)}</button>`).join(" · ")}`}</div>
    </div>`;
  }

  async function runTry(amount) {
    if (!amount || amount < 1) return Toast.error("Type an amount, e.g. 75,000.");
    const r = await API.ruleTry(subject, amount);
    if (!r.ok) return Toast.error(r.message);
    tried = { amount, answer: r.data };
    hero();
    tryPanel();
  }

  // ------------------------------------------------------------ rules

  const band = (w) => (w.amount_min !== null && w.amount_max !== null ? `KES ${A.num(w.amount_min)} to ${A.num(w.amount_max)}` : w.amount_max !== null ? `Up to KES ${A.num(w.amount_max)}` : w.amount_min !== null ? `Above KES ${A.num(w.amount_min)}` : w.custom_conditions ? "Custom conditions" : "Any amount");
  const LEVEL = { church: "Churches", region: "Regions", diocese: "The diocese", "": "Every level" };

  function ruleCard(w) {
    const who = (s) => s.steps.map((st) => (st.people?.length ? st.people.map((p) => p.name).join(", ") : st.label || "")).join(" or ");
    return `<div class="ap-rule${w.is_active ? "" : " is-off"}">
      <div class="ap-rule-head"><div class="min-w-0"><div class="ap-rule-name">${esc(w.name)}</div><div class="acc-sub">${esc(w.subject_label)} · ${esc(band(w))} · ${esc(LEVEL[w.level || ""])}${w.reach === "below" ? " · and its churches" : ""}${w.is_active ? "" : " · switched off"}</div></div>
        ${w.editable ? `<div class="d-flex gap-1 flex-shrink-0"><button type="button" class="btn btn-sm btn-outline-primary" data-edit="${w.id}"><i class="ri-edit-line me-1"></i>Change</button><button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-del="${w.id}" aria-label="Remove ${esc(w.name)}"><i class="ri-delete-bin-line"></i></button></div>` : '<span class="soft-chip soft-secondary flex-shrink-0"><i class="ri-eye-line"></i>See only</span>'}</div>
      <ol class="ap-rule-steps">${w.stages.map((s, i) => `<li><span class="ap-try-n">${i + 1}</span><div><b>${esc(s.name)}</b><div class="acc-sub">${esc(who(s))}${s.type === "all" ? " - everyone" : s.type === "quorum" ? ` - any ${s.quorum}` : ""}${s.sla_hours ? ` · ${s.sla_hours}h to decide` : ""}${s.escalate_label ? ` · then ${esc(s.escalate_label)}` : ""}</div></div></li>`).join("")}</ol>
    </div>`;
  }

  function rules() {
    const groups = [
      ["own", "Our own rules", "ri-home-heart-line", "success", "Set here - they come first, up to the ceiling."],
      ["above", "From above", "ri-arrow-up-circle-line", "purple", "Set by the region for its churches."],
      ["standard", "The diocese's standard rules", "ri-government-line", "primary", "For every place, unless it has its own."],
      ["below", "The places' own rules", "ri-community-line", "info", "What the churches and regions under us set for themselves."],
    ];
    $("apRules").innerHTML = groups
      .map(([key, title, icon, colour, sub]) => {
        const items = meta.items.filter((w) => w.group === key);
        if (!items.length && key !== "own") return "";
        if (key === "own" && CTX.level === "diocese") return "";
        return `<div class="card custom-card"><div class="card-header justify-content-between flex-wrap gap-2"><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm bg-${colour} text-white"><i class="${icon}"></i></span><div><div class="card-title">${title}</div><span class="card-subtitle-text">${sub}</span></div></div><span class="soft-chip soft-${colour}">${items.length} ${items.length === 1 ? "rule" : "rules"}</span></div>
          <div class="card-body"><div class="ap-rules">${items.length ? items.map(ruleCard).join("") : `<p class="mb-0 acc-sub">None yet${meta.can.manage ? " - add one for amounts up to KES " + A.num(meta.ceiling) + "." : "."}</p>`}</div></div></div>`;
      })
      .join("");
  }

  // ------------------------------------------------------------ the editor

  const rolesFor = (level) => meta.roles.filter((r) => !level || r.level === level);
  function whoBlock(step, key, ruleLevel) {
    const t = step?.resolver_type || (key === "esc" ? "" : "role_here");
    const cfg = step?.resolver_config || {};
    const role = (cfg.roles || [cfg.role])[0] || "";
    const aboveLevel = cfg.level || (ruleLevel === "church" ? "region" : "diocese");
    return `<div class="acc-who" data-who="${key}">
      <select class="form-select" data-k="type" aria-label="Who">${key === "esc" ? `<option value=""${!t ? " selected" : ""}>Nobody - it just waits</option>` : ""}<option value="role_here"${t === "role_here" ? " selected" : ""}>A role here</option><option value="role_above"${t === "role_above" ? " selected" : ""}>A role at the place above</option><option value="permission_here"${t === "permission_here" ? " selected" : ""}>Anyone here who can…</option><option value="user"${t === "user" ? " selected" : ""}>Named people</option></select>
      <select class="form-select" data-k="level" aria-label="Which place above"${t === "role_above" ? "" : " hidden"}><option value="region"${aboveLevel === "region" ? " selected" : ""}>The region above</option><option value="diocese"${aboveLevel === "diocese" ? " selected" : ""}>The diocese</option></select>
      <select class="form-select" data-k="role" aria-label="Role"${["role_here", "role_above"].includes(t) ? "" : " hidden"}><option value="">Pick a role...</option>${rolesFor(t === "role_above" ? aboveLevel : ruleLevel).map((r) => `<option value="${esc(r.name)}"${r.name === role ? " selected" : ""}>${esc(r.name)}</option>`).join("")}</select>
      <select class="form-select" data-k="perm" aria-label="What they can do"${t === "permission_here" ? "" : " hidden"}>${meta.permissions.map((p) => `<option value="${esc(p.key)}"${p.key === cfg.permission ? " selected" : ""}>${esc(p.label)}</option>`).join("")}</select>
      <div data-k="users"${t === "user" ? "" : " hidden"}><input type="search" class="form-control" data-k="search" placeholder="Search a name, e.g. Benson"><div class="acc-who-picked" data-k="picked">${(step?.people || (cfg.user_ids || []).map((id) => ({ id, name: `#${id}` }))).map((p) => `<span class="soft-chip soft-primary" data-uid="${p.id}">${esc(p.name)}<button type="button" class="btn-close btn-close-sm ms-1" aria-label="Remove"></button></span>`).join("")}</div><div class="acc-who-found" data-k="found"></div></div>
      ${key === "who" ? '<button type="button" class="btn btn-sm btn-icon btn-light border" data-remove-who aria-label="Remove this approver"><i class="ri-close-line"></i></button>' : ""}
    </div>`;
  }
  const readWho = (box) => {
    const type = box.querySelector('[data-k="type"]').value;
    if (!type) return null;
    if (type === "user") return { resolver_type: "user", resolver_config: { user_ids: [...box.querySelectorAll("[data-uid]")].map((x) => Number(x.dataset.uid)) } };
    if (type === "permission_here") return { resolver_type: "permission_here", resolver_config: { permission: box.querySelector('[data-k="perm"]').value } };
    const cfg = { roles: [box.querySelector('[data-k="role"]').value] };
    if (type === "role_above") cfg.level = box.querySelector('[data-k="level"]').value;
    return { resolver_type: type, resolver_config: cfg };
  };
  function wireWho(box, ruleLevel) {
    const refreshRoles = () => {
      const t = box.querySelector('[data-k="type"]').value;
      const lv = t === "role_above" ? box.querySelector('[data-k="level"]').value : ruleLevel;
      const sel = box.querySelector('[data-k="role"]');
      const cur = sel.value;
      sel.innerHTML = `<option value="">Pick a role...</option>${rolesFor(lv).map((r) => `<option value="${esc(r.name)}"${r.name === cur ? " selected" : ""}>${esc(r.name)}</option>`).join("")}`;
    };
    const show = () => {
      const t = box.querySelector('[data-k="type"]').value;
      box.querySelector('[data-k="level"]').hidden = t !== "role_above";
      box.querySelector('[data-k="role"]').hidden = !["role_here", "role_above"].includes(t);
      box.querySelector('[data-k="perm"]').hidden = t !== "permission_here";
      box.querySelector('[data-k="users"]').hidden = t !== "user";
      refreshRoles();
    };
    box.querySelector('[data-k="type"]').addEventListener("change", show);
    box.querySelector('[data-k="level"]').addEventListener("change", refreshRoles);
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
      if (add && !box.querySelector(`[data-uid="${add.dataset.addUid}"]`)) box.querySelector('[data-k="picked"]').insertAdjacentHTML("beforeend", `<span class="soft-chip soft-primary" data-uid="${add.dataset.addUid}">${esc(add.dataset.name)}<button type="button" class="btn-close btn-close-sm ms-1" aria-label="Remove"></button></span>`);
      const x = e.target.closest("[data-uid] .btn-close");
      if (x) x.closest("[data-uid]").remove();
    });
  }

  function stageHtml(s, ruleLevel) {
    return `<div class="acc-stage-edit" data-stage>
      <div class="d-flex gap-2 align-items-center mb-2"><span class="ap-try-n" data-n></span><input type="text" class="form-control" data-f="name" maxlength="120" placeholder="Step name, e.g. Pastor" value="${esc(s.name || "")}"><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="-1" aria-label="Move up"><i class="ri-arrow-up-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="1" aria-label="Move down"><i class="ri-arrow-down-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-outline-danger" data-remove-stage aria-label="Remove step"><i class="ri-close-line"></i></button></div>
      <div class="row g-2">
        <div class="col-lg-7"><label class="form-label">Who approves</label><div data-whos>${(s.steps?.length ? s.steps : [null]).map((st) => whoBlock(st, "who", ruleLevel)).join("")}</div><button type="button" class="btn btn-sm btn-link p-0 mt-1" data-add-who><i class="ri-add-line me-1"></i>Or also…</button></div>
        <div class="col-lg-5"><label class="form-label">How many must agree</label><div class="d-flex gap-2"><select class="form-select" data-f="type"><option value="single"${!s.type || s.type === "single" ? " selected" : ""}>Any one of them</option><option value="all"${s.type === "all" ? " selected" : ""}>Every one of them</option><option value="quorum"${s.type === "quorum" ? " selected" : ""}>A number of them</option></select><input type="number" min="1" max="20" class="form-control" data-f="quorum" value="${s.quorum || 2}" style="max-width:5.5rem"${s.type === "quorum" ? "" : " hidden"} aria-label="How many" placeholder="2"></div>
          <label class="form-label mt-2">If nobody here holds it</label><select class="form-select" data-f="on_empty"><option value="escalate"${s.on_empty === "escalate" ? " selected" : ""}>Send it to the escalation below</option><option value="skip"${s.on_empty === "skip" ? " selected" : ""}>Skip this step</option><option value="block"${!s.on_empty || s.on_empty === "block" ? " selected" : ""}>Wait, and tell whoever looks after the rules</option></select></div>
        <div class="col-sm-4"><label class="form-label">Hours to decide</label><input type="number" min="1" max="720" class="form-control" data-f="sla_hours" value="${s.sla_hours || ""}" placeholder="e.g. 48"></div>
        <div class="col-sm-4"><label class="form-label">Then pass it up after (hours)</label><input type="number" min="1" max="720" class="form-control" data-f="escalate_after_hours" value="${s.escalate_after_hours || ""}" placeholder="e.g. 24"></div>
        <div class="col-sm-4"><label class="form-label">If someone says no</label><select class="form-select" data-f="on_reject"><option value="terminate"${!["return_previous", "continue"].includes(s.on_reject) ? " selected" : ""}>It stops</option><option value="return_previous"${s.on_reject === "return_previous" ? " selected" : ""}>Back to the step before</option><option value="continue"${s.on_reject === "continue" ? " selected" : ""}>Carry on anyway</option></select></div>
        <div class="col-12"><label class="form-label">When late, or nobody: pass it to</label>${whoBlock(s.escalate_to, "esc", ruleLevel)}</div>
      </div>
    </div>`;
  }

  function editor(w = null) {
    const own = CTX.level !== "diocese";
    const fixedLevel = CTX.level === "church" ? "church" : null;
    const ruleLevel = () => fixedLevel || (CTX.level === "region" ? ($("rlFor")?.value === "churches" ? "church" : "region") : $("rlLevel")?.value || null);
    document.getElementById("ruleWindow")?.remove();
    const forRegion = CTX.level === "region" ? `<div class="col-lg-4 col-sm-6"><label class="form-label">For</label><select class="form-select" id="rlFor"><option value="self"${w?.reach !== "below" ? " selected" : ""}>The region's own payments</option><option value="churches"${w?.reach === "below" && w?.level === "church" ? " selected" : ""}>Our churches' payments</option></select></div>` : "";
    const levelPick = CTX.level === "diocese" ? `<div class="col-lg-2 col-sm-6"><label class="form-label">Level</label><select class="form-select" id="rlLevel">${Object.entries(LEVEL).map(([v, l]) => `<option value="${v}"${(w?.level || "") === v ? " selected" : ""}>${l}</option>`).join("")}</select></div>` : "";
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="ruleWindow" tabindex="-1" aria-labelledby="ruleTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-shield-check-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="ruleTitle">${w ? `Change "${esc(w.name)}"` : own ? `Add a rule for ${esc(map.place.name)}` : "Add a standard rule"}</h5><div class="app-modal-subtitle">New requests follow it; ones already waiting keep their rule</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-filter-3-line"></i>When it applies${own ? `<small>Up to KES ${A.num(meta.ceiling)} - above it the diocese's rules apply</small>` : ""}</div>
            <div class="row g-2"><div class="col-lg-4"><label class="form-label">Name</label><input type="text" class="form-control" id="rlName" maxlength="150" value="${esc(w?.name || "")}" placeholder="e.g. Small payments"></div>
            <div class="col-lg-3 col-sm-6"><label class="form-label">For which documents</label><select class="form-select" id="rlSubject">${meta.subjects.map((s) => `<option value="${s.key}"${(w?.subject_type || "*") === s.key ? " selected" : ""}>${esc(s.label)}</option>`).join("")}</select></div>
            ${levelPick}${forRegion}
            <div class="col-lg-3"><label class="form-label">Amount (KES)</label><div class="d-flex gap-1 align-items-center"><input type="number" min="0" class="form-control" id="rlMin" value="${w?.amount_min ?? ""}" placeholder="Above, e.g. 0"><span class="acc-sub">to</span><input type="number" min="0" class="form-control" id="rlMax" value="${w?.amount_max ?? ""}" placeholder="${own ? `Up to ${A.num(meta.ceiling)}` : "Up to (any)"}"></div></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="rlActive"${w?.is_active === false ? "" : " checked"}><label class="form-check-label" for="rlActive">In use</label></div></div></div></section>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Steps, in order</div><div id="rlStages"></div><button type="button" class="btn btn-sm btn-outline-primary" id="rlAdd"><i class="ri-add-line me-1"></i>Add a step</button></section>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rlGo"><i class="ri-check-line me-1"></i>Save rule</button></div>
      </div></div></div>`,
    );
    const el = $("ruleWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    const box = el.querySelector("#rlStages");
    const number = () => box.querySelectorAll("[data-stage]").forEach((s, i) => (s.querySelector("[data-n]").textContent = i + 1));
    const addStage = (s) => {
      box.insertAdjacentHTML("beforeend", stageHtml(s, ruleLevel()));
      const st = box.lastElementChild;
      st.querySelectorAll("[data-who]").forEach((b) => wireWho(b, ruleLevel()));
      st.querySelector('[data-f="type"]').addEventListener("change", (e) => (st.querySelector('[data-f="quorum"]').hidden = e.target.value !== "quorum"));
      number();
    };
    (w?.stages?.length ? w.stages : [{}]).forEach(addStage);
    el.querySelector("#rlAdd").addEventListener("click", () => addStage({}));
    box.addEventListener("click", (e) => {
      const st = e.target.closest("[data-stage]");
      if (!st) return;
      if (e.target.closest("[data-add-who]")) {
        st.querySelector("[data-whos]").insertAdjacentHTML("beforeend", whoBlock(null, "who", ruleLevel()));
        wireWho(st.querySelector("[data-whos]").lastElementChild, ruleLevel());
      }
      const rw = e.target.closest("[data-remove-who]");
      if (rw && st.querySelectorAll('[data-whos] [data-who="who"]').length > 1) rw.closest("[data-who]").remove();
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
        return {
          name: f("name").trim(), type: f("type"), quorum: f("type") === "quorum" ? Number(f("quorum")) : null, on_empty: f("on_empty"), on_reject: f("on_reject"),
          sla_hours: Number(f("sla_hours")) || null, escalate_after_hours: Number(f("escalate_after_hours")) || null,
          escalate_to: readWho(st.querySelector('[data-who="esc"]')),
          steps: [...st.querySelectorAll('[data-whos] [data-who="who"]')].map(readWho).filter(Boolean),
        };
      });
      const body = {
        name: el.querySelector("#rlName").value.trim(),
        subject_type: el.querySelector("#rlSubject").value,
        amount_min: el.querySelector("#rlMin").value === "" ? null : Number(el.querySelector("#rlMin").value),
        amount_max: el.querySelector("#rlMax").value === "" ? null : Number(el.querySelector("#rlMax").value),
        is_active: el.querySelector("#rlActive").checked,
        stages,
      };
      if (CTX.level === "diocese") body.level = el.querySelector("#rlLevel").value || null;
      if (CTX.level === "region") Object.assign(body, el.querySelector("#rlFor").value === "churches" ? { reach: "below", level: "church" } : { reach: "self" });
      UI.setButtonLoading(b, "Saving...");
      const res = await API.saveWorkflow(w?.id, body);
      UI.restoreButton(b);
      if (!res.ok) return Toast.error(Object.values(res.errors || {}).flat()[0] || res.message);
      Toast.success(res.message);
      bootstrap.Modal.getInstance(el)?.hide();
      load();
    });
  }

  // ------------------------------------------------------------ loading and the tabs

  function showTab() {
    document.querySelectorAll("[data-ap-tab]").forEach((b) => {
      b.classList.toggle("is-on", b.dataset.apTab === tab);
      b.setAttribute("aria-selected", b.dataset.apTab === tab);
    });
    $("apOverview").hidden = tab !== "overview";
    $("apRules").hidden = tab !== "rules";
    keepUrl();
  }

  async function load() {
    $("apHero").innerHTML = `<div class="card-body">${UI.skeletonCards ? UI.skeletonCards(1, "col-12") : ""}</div>`;
    const [m, w] = await Promise.all([API.ruleMap(subject), API.workflows()]);
    if (!m.ok || !w.ok) {
      $("apHero").innerHTML = `<div class="card-body">${A.errorBox((m.ok ? w : m).message)}</div>`;
      return;
    }
    map = m.data;
    meta = w.data;
    A.placeLine($("accPlaceLine"), meta.place);
    $("addRuleBtn").hidden = !meta.can.manage;
    if (tried) {
      const r = await API.ruleTry(subject, tried.amount);
      tried = r.ok ? { amount: tried.amount, answer: r.data } : null;
    }
    hero();
    next();
    detected();
    tryPanel();
    rules();
    showTab();
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("apSubject").value = subject;
    UI.enhanceSelect?.($("apSubject"), { search: false });
    window.jQuery?.($("apSubject")).on("change", (e) => {
      subject = e.target.value;
      keepUrl();
      load();
    });
    $("addRuleBtn").addEventListener("click", () => editor());
    document.querySelector(".ap-topbar").addEventListener("click", (e) => {
      const b = e.target.closest("[data-ap-tab]");
      if (!b) return;
      tab = b.dataset.apTab;
      showTab();
    });
    document.addEventListener("click", async (e) => {
      if (e.target.closest("[data-go-rules]")) {
        tab = "rules";
        return showTab();
      }
      const act = e.target.closest("[data-action]");
      if (act) {
        const a = act.dataset.action;
        if (a === "rules") return (tab = "rules"), showTab();
        if (a === "blocked") return (window.location.href = `${CTX.baseUrl}/approvals.php`);
        return (window.location.href = CTX.canAdmin ? `${CTX.siteUrl}/diocese/settings/admin/role-management.php` : `${CTX.siteUrl}/${CTX.level}/settings/?section=team`);
      }
      const sb = e.target.closest("[data-subject]");
      if (sb) {
        subject = sb.dataset.subject;
        $("apSubject").value = subject;
        UI.syncSelect?.($("apSubject"));
        keepUrl();
        return load();
      }
      const tv = e.target.closest("[data-try]");
      if (tv) return runTry(Number(tv.dataset.try));
      if (e.target.closest("[data-clear-try]")) {
        tried = null;
        hero();
        return tryPanel();
      }
      const ed = e.target.closest("[data-edit]");
      if (ed) return editor(meta.items.find((x) => x.id === Number(ed.dataset.edit)));
      const del = e.target.closest("[data-del]");
      if (del && confirm("Remove this rule? Requests already waiting keep it.")) {
        const r = await API.deleteWorkflow(Number(del.dataset.del));
        r.ok ? (Toast.success(r.message), load()) : Toast.error(r.message);
      }
    });
    document.addEventListener("submit", (e) => {
      if (e.target.id !== "apTryForm") return;
      e.preventDefault();
      runTry(Number(String($("apTryAmount").value).replace(/[^0-9.]/g, "")));
    });
    showTab();
    load();
  });
})();
