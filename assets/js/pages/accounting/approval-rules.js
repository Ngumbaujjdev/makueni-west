/**
 * ============================================================================
 * ACCOUNTING - Approval rules (approval-rules.php, every level)
 * ============================================================================
 * Who signs what here (docs/specs/accounting-spec.md, A4b), in the manner of
 * the OrchestrateIQ dashboard the user chose:
 *   - three capsules: the place, the pill nav, Live + how far back
 *   - the hero "Your approvals, live": approvers down the side, time along the
 *     top, each approval a block from when it reached them to the decision
 *     (or now while it waits); weekends and nights hatched; the dashed line
 *     is now
 *   - Next steps, Detected, and the assistant panel ("Who would sign
 *     75,000?", what we found, an amount box)
 *   - Rules: who signs which amounts, then our own, from above, the
 *     diocese's standard and the places' own - with the editor
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
  const TONES = ["purple", "success", "sky", "gold", "pink"];
  const SOFT = { purple: "purple", success: "success", sky: "primary", gold: "warning", pink: "pink" };
  const STATUS = { approved: ["Approved", "is-ok"], waiting: ["Waiting", "is-wait"], late: ["Late", "is-no"], refused: ["Refused", "is-no"], returned: ["Sent back", "is-back"] };
  const params = new URLSearchParams(window.location.search);
  let tab = params.get("view") === "rules" ? "rules" : "overview";
  let subject = ["payment_voucher", "requisition", "payroll_run"].includes(params.get("subject")) ? params.get("subject") : "payment_voucher";
  let days = [1, 7, 14, 30].includes(Number(params.get("days"))) ? Number(params.get("days")) : 14;
  let map = null;
  let live = null;
  let meta = null;
  let tried = null;
  let explain = false;

  const short = (v) => (v >= 1000000 ? `${v / 1000000}M` : v >= 1000 ? `${v / 1000}k` : String(v));
  const me = () => {
    try {
      const raw = JSON.parse(localStorage.getItem("mwd_user_data") || "null") || {};
      const u = raw.user || raw.data || raw;
      return { firstname: u.firstname || u.first_name || String(u.full_name || u.name || "").split(" ")[0] || "" };
    } catch (e) {
      return {};
    }
  };
  const initials = (n) => String(n || "").split(" ").filter(Boolean).slice(0, 2).map((p) => p[0]).join("").toUpperCase();

  function keepUrl() {
    const p = new URLSearchParams(window.location.search);
    tab === "rules" ? p.set("view", "rules") : p.delete("view");
    subject === "payment_voucher" ? p.delete("subject") : p.set("subject", subject);
    days === 14 ? p.delete("days") : p.set("days", days);
    history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
  }

  // ------------------------------------------------------------ the hero: approvers over time

  function setUpCount() {
    const d = map.detected;
    const done = [d.rules > 0, d.nobody === 0, d.blocked === 0].filter(Boolean).length;
    return [done, 3];
  }

  function hero() {
    const [done, of] = setUpCount();
    const from = Date.parse(live.window.from);
    const span = Date.parse(live.window.to) - from;
    const pos = (iso) => Math.max(0, Math.min(100, ((Date.parse(iso) - from) / span) * 100));
    const nowAt = pos(live.window.now);
    const n = live.ticks.length;
    const quietCells = live.ticks.map((t, i) => (t.quiet ? i : null)).filter((i) => i !== null);
    const rows = live.rows.length
      ? live.rows
          .map((r, ri) => {
            const tone = TONES[ri % TONES.length];
            // Each bar is drawn at least two columns wide (an approval often takes hours of a fortnight),
            // then packed into lanes by where it is drawn, so bars never sit on each other.
            const minW = Math.min((100 / n) * 4.2, 50);
            const ends = [];
            let hidden = live.more?.[ri] || 0;
            const blocks = live.blocks
              .filter((b) => b.row === ri)
              .map((b) => {
                const right = Math.max(pos(b.to), pos(b.from) + minW);
                const left = Math.min(pos(b.from), 100 - minW);
                return { ...b, left, width: Math.min(right, 100) - left };
              })
              .sort((a, b) => a.left - b.left)
              .filter((b) => {
                let lane = ends.findIndex((e) => e <= b.left + 0.2);
                if (lane === -1 && ends.length < 3) lane = ends.push(0) - 1;
                if (lane === -1) return (hidden++, false);
                ends[lane] = b.left + b.width;
                b.lane = lane;
                return true;
              });
            const lanes = Math.max(1, ends.length);
            const more = hidden;
            const away = live.away.filter((a) => a.row === ri);
            return `<div class="ap-agent" style="grid-row:${ri + 2}"><span class="ap-agent-icon soft-${SOFT[tone]}"><i class="${esc(r.icon)}"></i></span><div class="min-w-0"><div class="ap-agent-name">${esc(r.name)}</div><div class="ap-agent-sub">${esc(r.role)} · ${r.approvals} ${r.approvals === 1 ? "approval" : "approvals"}${more ? ` · +${more} more` : ""}</div></div></div>
              <div class="ap-track" style="grid-row:${ri + 2};grid-column:2 / span ${n};--lanes:${lanes}">
                ${quietCells.map((i) => `<span class="ap-quiet" style="left:calc(${(i / n) * 100}% + 2px);width:calc(${100 / n}% - 4px)"></span>`).join("")}
                ${away.map((a) => `<span class="ap-away" style="left:${pos(a.from)}%;width:${Math.max(pos(a.to) - pos(a.from), 2)}%" title="Away - handed over to ${esc(a.to_name || "")}"><i class="ri-user-shared-line"></i>Away</span>`).join("")}
                ${blocks
                  .map((b) => {
                    const { left, width } = b;
                    const [label, dot] = STATUS[b.status] || STATUS.waiting;
                    const href = b.type ? A.link("record.php", { type: b.type, id: b.id }) : "#";
                    return `<a class="ap-run ap-run-${tone}" href="${href}" style="left:${left}%;width:${width}%;top:calc(${b.lane} * var(--lane-h))" title="${esc(b.label)}${b.amount ? ` - KES ${A.num(b.amount)}` : ""}"><span class="ap-run-icon"><i class="${esc(r.icon)}"></i></span><span class="ap-run-label">${esc(b.label.replace(/^(Approved|Approving|Refused|Sent back) /, ""))}</span><span class="ap-run-pill"><span class="ap-dot ${dot}"></span>${label}</span></a>`;
                  })
                  .join("")}
              </div>`;
          })
          .join("")
      : `<div class="ap-empty" style="grid-column:1 / -1">${A.empty("ri-shield-check-line", "Nothing to approve yet", "When a payment needs signing here, it shows on this map.")}</div>`;
    const stamp = new Date(live.window.now);
    $("apHero").innerHTML = `
      <div class="ap-hero-head">
        <div class="min-w-0"><h2 class="ap-hero-title">Your approvals, live</h2>
          <p class="ap-hero-sub">Everything below is from ${live.steps} approval ${live.steps === 1 ? "step" : "steps"} at ${esc(map.place.name)} in the last ${days === 1 ? "24 hours" : `${days} days`}. Nothing was entered by hand.</p></div>
        <div class="ap-hero-tools">
          <span class="ap-setup${done === of ? "" : " is-todo"}"><i class="${done === of ? "ri-check-line" : "ri-error-warning-line"}"></i>${done === of ? "Setup complete" : "Setup"} · ${done} of ${of}</span>
          <label class="ap-tool"><i class="ri-calendar-line"></i><span>${stamp.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })} · ${stamp.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}</span><select data-days aria-label="How far back">${[1, 7, 14, 30].map((v) => `<option value="${v}"${v === days ? " selected" : ""}>${v === 1 ? "24 hours" : `${v} days`}</option>`).join("")}</select><i class="ri-arrow-down-s-line"></i></label>
          <label class="ap-tool"><i class="ri-equalizer-line"></i><span>Filter</span><select data-subject-pick aria-label="Which documents">${map.subjects.map((s) => `<option value="${s.key}"${s.key === subject ? " selected" : ""}>${esc(s.label)}</option>`).join("")}</select></label>
          <a class="ap-tool" href="${CTX.baseUrl}/approvals.php"><i class="ri-arrow-right-up-line"></i><span>Live Map</span></a>
        </div>
      </div>
      <div class="ap-grid-wrap"><div class="ap-grid" style="grid-template-columns:minmax(11.5rem, 12.5rem) repeat(${n}, minmax(${days === 30 ? 2.6 : days === 1 ? 2.4 : 2.9}rem, 1fr));">
        <div class="ap-grid-label" style="grid-row:1">Approvers</div>
        ${live.ticks.map((t, i) => `<div class="ap-tick${t.now ? " is-now" : t.quiet ? " is-quiet" : ""}" style="grid-column:${i + 2};grid-row:1"><small>${esc(t.top)}</small><b>${esc(t.bottom)}</b></div>`).join("")}
        ${rows}
        <div class="ap-now" style="grid-column:2 / span ${n};grid-row:2 / span ${Math.max(live.rows.length, 1)}"><span style="left:${nowAt}%"></span></div>
      </div></div>`;
  }

  // ------------------------------------------------------------ next steps, detected

  function next() {
    const people = live.rows.slice(0, 3);
    const stack = `<span class="ap-stack">${people.map((p, i) => `<span class="ap-stack-face soft-${SOFT[TONES[i % TONES.length]]}">${esc(initials(p.name))}</span>`).join("")}<span class="ap-stack-n">${live.rows.length}</span></span>`;
    const items = map.checks.slice(0, 3).map((c) => ({ ...c, check: true }));
    const standing = [
      { title: "Hand over while you're away", text: "Approvals go to someone you trust until you're back - and come back to you after.", action: "handover", chips: [["ri-calendar-line", "Pick dates"], ["ri-user-shared-line", "Someone here"]] },
      { title: "Try a big payment", text: "See who would sign KES 250,000 here - and where it goes past the church.", action: "try", chips: [["ri-money-dollar-circle-line", "KES 250,000"], ["ri-route-line", "The path"]] },
      ...(map.can.manage ? [{ title: "Add a rule of your own", text: `Up to KES ${A.num(map.ceiling)} you choose who signs here.`, action: "rules", chips: [["ri-add-line", "New rule"], ["ri-equalizer-line", "Up to " + short(map.ceiling)]] }] : []),
    ];
    while (items.length < 3 && standing.length) items.push(standing.shift());
    $("apNext").innerHTML = `
      <div class="ap-panel-head"><h3>Next steps</h3><button type="button" class="ap-viewall" data-go-rules><i class="ri-arrow-right-up-line"></i>View all</button></div>
      ${items
        .map((x, i) => {
          const first = i === 0;
          const chips = x.chips ? x.chips.map(([ic, t]) => `<span class="ap-chip"><i class="${ic}"></i>${esc(t)}</span>`).join("") : `${x.count ? `<span class="ap-chip"><i class="ri-community-line"></i>${x.count} ${x.count === 1 ? "church" : "churches"}</span>` : `<span class="ap-chip"><i class="ri-equalizer-line"></i>${map.detected.rules} rules</span>`}<span class="ap-chip"><i class="ri-time-line"></i>~2 min</span>`;
          return `<button type="button" class="ap-step${first ? " is-first" : ""}" data-action="${esc(x.action)}">
            <div class="ap-step-top"><span class="ap-step-title">${esc(x.title)}</span>${first ? `<span class="ap-dofirst"><span class="ap-dot is-dark"></span>${x.check ? "Do first" : "Try this"}</span>` : ""}</div>
            <div class="ap-step-text">${esc(x.text)}</div>
            <div class="ap-step-chips">${chips}${first ? stack : ""}</div>
          </button>`;
        })
        .join("")}`;
  }

  function detected() {
    const d = map.detected;
    const tile = (icon, colour, text, chip, chipIcon) => `<div class="ap-found"><span class="ap-found-icon soft-${colour}"><i class="${icon}"></i></span><div class="ap-found-text">${text}</div><span class="ap-found-chip"><i class="${chipIcon}"></i>${chip}</span></div>`;
    $("apDetected").innerHTML = `
      <div class="ap-panel-head"><h3>Detected</h3><a class="ap-viewall" href="${CTX.baseUrl}/approvals.php"><i class="ri-arrow-right-up-line"></i>View all</a></div>
      <div class="ap-found-grid">
        ${tile("ri-user-follow-line", "success", `${live.rows.length} ${live.rows.length === 1 ? "approver" : "approvers"}`, `${live.active_now} busy now`, "ri-checkbox-circle-line")}
        ${tile("ri-git-branch-line", "purple", `${d.rules} ${d.rules === 1 ? "rule" : "rules"}`, d.own_rules ? `${d.own_rules} our own` : "from the diocese", "ri-checkbox-circle-line")}
        ${tile("ri-shuffle-line", "primary", `${d.waiting} waiting`, d.oldest_days !== null ? `${d.oldest_days} ${d.oldest_days === 1 ? "day" : "days"} oldest` : "none waiting", "ri-time-line")}
        ${tile("ri-flashlight-line", "warning", `${d.decided_30} decided`, "streaming", "ri-pulse-line")}
      </div>`;
  }

  // ------------------------------------------------------------ the assistant panel

  function assistant() {
    const name = (me().firstname || "").trim();
    const r = tried?.answer;
    const body = explain
      ? `<div class="ap-answer"><b>Reading the map</b><ul class="mb-0 ps-3"><li>Each row is someone who signs here.</li><li>Each bar is one approval - from when it reached them to when they decided. A bar still running is waiting.</li><li>Grey striped columns are weekends${days === 1 ? " and nights" : ""}; the dashed line is now.</li><li>Green dot approved, amber waiting, red late or refused.</li></ul></div>`
      : r
        ? `<div class="ap-answer"><div class="ap-answer-rule"><span class="ap-chip">${esc(r.rule?.owner || "No rule")}</span>${r.rule ? `<b>${esc(r.rule.name)}</b>` : ""}<span class="ap-answer-amt">KES ${A.num(r.amount)}</span></div>
            ${r.stages.length ? `<ol class="ap-path">${r.stages.map((s, i) => `<li><span class="ap-path-n">${i + 1}</span><div><b>${esc(s.name)}</b><div class="ap-path-who">${s.people.length ? esc(s.people.join(", ")) : esc(s.who)}</div>${s.note ? `<div class="ap-path-note">${esc(s.note)}</div>` : ""}</div></li>`).join("")}</ol>` : `<p class="mb-0">${esc(r.note || "")}</p>`}</div>`
        : `<div class="ap-ask-label">Ask about what we found</div>
           ${map.questions.length ? map.questions.slice(0, 2).map((q) => `<a class="ap-ask" href="${q.type ? A.link("record.php", { type: q.type, id: q.id }) : `${CTX.baseUrl}/approvals.php`}"><i class="ri-sparkling-2-fill"></i><span>${esc(q.text)}</span><i class="ri-arrow-right-line"></i></a>`).join("") : `<button type="button" class="ap-ask" data-try="75000"><i class="ri-sparkling-2-fill"></i><span>Who would sign KES 75,000 here?</span><i class="ri-arrow-right-line"></i></button><button type="button" class="ap-ask" data-explain><i class="ri-sparkling-2-fill"></i><span>What does the map show?</span><i class="ri-arrow-right-line"></i></button>`}`;
    $("apTry").innerHTML = `<div class="ap-assist">
      <div class="ap-orb"></div>
      <h3 class="ap-assist-title">Welcome${name ? `, ${esc(name)}` : ""}<br>What can I help with today?</h3>
      <div class="ap-assist-chips">
        <button type="button" class="ap-chip is-btn" data-try="75000"><i class="ri-price-tag-3-line"></i>Who signs 75,000?</button>
        <button type="button" class="ap-chip is-btn" data-explain><i class="ri-file-text-line"></i>Explain the map</button>
        <a class="ap-chip is-btn" href="${CTX.baseUrl}/approvals.php?tab=delegations"><i class="ri-user-add-line"></i>Hand over</a>
      </div>
      <div class="ap-assist-body">${body}${r || explain ? '<button type="button" class="ap-back" data-clear><i class="ri-arrow-left-line"></i>Back to what we found</button>' : ""}</div>
      <form class="ap-ask-box" id="apTryForm">
        <div class="ap-ask-row"><input type="text" inputmode="numeric" id="apTryAmount" placeholder="Type an amount, e.g. 75,000" value="${tried ? A.num(tried.amount) : ""}" aria-label="Amount to try" autocomplete="off"><button type="submit" class="ap-send" aria-label="Who would sign it?"><i class="ri-send-plane-2-line"></i></button></div>
        <div class="ap-ask-tools">${map.subjects.map((s) => `<button type="button" class="ap-tool-chip${s.key === subject ? " is-on" : ""}" data-subject="${s.key}"><i class="${{ payment_voucher: "ri-file-list-3-line", requisition: "ri-hand-coin-line", payroll_run: "ri-team-line" }[s.key]}"></i>${esc({ payment_voucher: "Voucher", requisition: "Requisition", payroll_run: "Payroll" }[s.key])}</button>`).join("")}<span class="ap-ask-hint">KES</span></div>
      </form>
    </div>`;
  }

  async function runTry(amount) {
    if (!amount || amount < 1) return Toast.error("Type an amount, e.g. 75,000.");
    const r = await API.ruleTry(subject, amount);
    if (!r.ok) return Toast.error(r.message);
    tried = { amount, answer: r.data };
    explain = false;
    assistant();
    if (tab === "rules") rules();
  }

  // ------------------------------------------------------------ rules: who signs which amounts, then the rules

  const band = (w) => (w.amount_min !== null && w.amount_max !== null ? `KES ${A.num(w.amount_min)} to ${A.num(w.amount_max)}` : w.amount_max !== null ? `Up to KES ${A.num(w.amount_max)}` : w.amount_min !== null ? `Above KES ${A.num(w.amount_min)}` : w.custom_conditions ? "Custom conditions" : "Any amount");
  const LEVEL = { church: "Churches", region: "Regions", diocese: "The diocese", "": "Every level" };

  function amountMap() {
    const marks = map.marks;
    const cols = marks.length;
    const triedCol = tried ? marks.reduce((at, m, i) => (tried.amount > m ? i : at), 0) : null;
    const rows = map.rows
      .map((r, ri) => {
        const tone = TONES[ri % TONES.length];
        const row = ri + 2;
        const blocks = map.blocks.filter((b) => b.row === ri);
        return `<div class="ap-agent" style="grid-row:${row}"><span class="ap-agent-icon soft-${SOFT[tone]}"><i class="${esc(r.icon)}"></i></span><div class="min-w-0"><div class="ap-agent-name">${esc(r.name)}</div><div class="ap-agent-sub">${r.has_someone ? esc(r.people.slice(0, 2).join(", ") || r.who) : '<span class="text-danger">Nobody here</span>'}</div></div></div>
          ${map.segments.map((s, si) => (s.standard_only && !blocks.some((b) => si >= b.from && si <= b.to) ? `<span class="ap-quiet-cell" style="grid-column:${si + 2};grid-row:${row}"></span>` : "")).join("")}
          ${blocks.map((b) => `<div class="ap-run ap-run-${tone} is-cell" style="grid-column:${b.from + 2} / ${b.to + 3};grid-row:${row}" title="${esc(b.rule)} - ${esc(b.owner)}"><span class="ap-run-icon"><i class="${esc(r.icon)}"></i></span><span class="ap-run-label">${esc(b.label)}</span><span class="ap-run-pill"><span class="ap-dot ${b.status === "ok" ? "is-ok" : b.status === "skipped" ? "is-skip" : "is-no"}"></span>${b.status === "ok" ? "Has someone" : b.status === "skipped" ? "Skipped" : "Nobody"}</span></div>`).join("")}`;
      })
      .join("");
    return `<section class="ap-panel ap-hero">
      <div class="ap-hero-head"><div class="min-w-0"><h2 class="ap-hero-title is-sm">Who signs which amounts</h2><p class="ap-hero-sub">${map.place.level === "diocese" ? "The diocese's standard rules, as they apply here." : `Up to KES ${A.num(map.ceiling)} ${esc(map.place.name)} can set its own; above it (grey) the diocese's rules apply.`} ${tried ? `The blue column is KES ${A.num(tried.amount)}.` : ""}</p></div>
        <div class="ap-hero-tools">${meta.can.manage ? '<button type="button" class="btn btn-primary rounded-pill" id="addRuleBtn"><i class="ri-add-line me-1"></i>Add a rule</button>' : '<span class="ap-chip"><i class="ri-eye-line"></i>See only</span>'}</div></div>
      <div class="ap-grid-wrap"><div class="ap-grid is-amounts" style="grid-template-columns:minmax(11.5rem, 12.5rem) repeat(${cols}, minmax(4.4rem, 1fr));">
        <div class="ap-grid-label" style="grid-row:1">Who signs</div>
        ${marks.map((m, i) => `<div class="ap-tick${i === triedCol ? " is-now" : map.segments[i]?.standard_only ? " is-quiet" : ""}" style="grid-column:${i + 2};grid-row:1"><small>KES</small><b>${i === cols - 1 ? `${short(m)}+` : short(m)}</b></div>`).join("")}
        ${rows || `<div class="ap-empty" style="grid-column:1 / -1">${A.empty("ri-shield-check-line", "No rule covers these documents here", "Anyone who can authorise payments approves them - never their own.")}</div>`}
      </div></div></section>`;
  }

  function ruleCard(w) {
    const who = (s) => s.steps.map((st) => (st.people?.length ? st.people.map((p) => p.name).join(", ") : st.label || "")).join(" or ");
    return `<div class="ap-rule${w.is_active ? "" : " is-off"}">
      <div class="ap-rule-head"><div class="min-w-0"><div class="ap-rule-name">${esc(w.name)}</div><div class="ap-rule-sub">${esc(w.subject_label)} · ${esc(band(w))} · ${esc(LEVEL[w.level || ""])}${w.reach === "below" ? " · and its churches" : ""}${w.is_active ? "" : " · switched off"}</div></div>
        ${w.editable ? `<div class="d-flex gap-1 flex-shrink-0"><button type="button" class="ap-chip is-btn" data-edit="${w.id}"><i class="ri-edit-line"></i>Change</button><button type="button" class="ap-chip is-btn is-danger" data-del="${w.id}" aria-label="Remove ${esc(w.name)}"><i class="ri-delete-bin-line"></i></button></div>` : '<span class="ap-chip"><i class="ri-eye-line"></i>See only</span>'}</div>
      <ol class="ap-path">${w.stages.map((s, i) => `<li><span class="ap-path-n">${i + 1}</span><div><b>${esc(s.name)}</b><div class="ap-path-who">${esc(who(s))}${s.type === "all" ? " - everyone" : s.type === "quorum" ? ` - any ${s.quorum}` : ""}${s.sla_hours ? ` · ${s.sla_hours}h to decide` : ""}${s.escalate_label ? ` · then ${esc(s.escalate_label)}` : ""}</div></div></li>`).join("")}</ol>
    </div>`;
  }

  function rules() {
    const groups = [
      ["own", "Our own rules", "ri-home-heart-line", "success", "Set here - they come first, up to the ceiling."],
      ["above", "From above", "ri-arrow-up-circle-line", "purple", "Set by the region for its churches."],
      ["standard", "The diocese's standard rules", "ri-government-line", "primary", "For every place, unless it has its own."],
      ["below", "The places' own rules", "ri-community-line", "warning", "What the churches and regions under us set for themselves."],
    ];
    $("apRules").innerHTML =
      amountMap() +
      groups
        .map(([key, title, icon, colour, sub]) => {
          const items = meta.items.filter((w) => w.group === key);
          if (!items.length && (key !== "own" || CTX.level === "diocese")) return "";
          return `<section class="ap-panel"><div class="ap-panel-head"><div class="d-flex align-items-center gap-2"><span class="ap-found-icon soft-${colour} is-sm"><i class="${icon}"></i></span><div><h3>${title}</h3><div class="ap-rule-sub">${sub}</div></div></div><span class="ap-chip">${items.length} ${items.length === 1 ? "rule" : "rules"}</span></div>
            <div class="ap-rules">${items.length ? items.map(ruleCard).join("") : `<p class="mb-0 ap-rule-sub">None yet${meta.can.manage ? ` - add one for amounts up to KES ${A.num(meta.ceiling)}.` : "."}</p>`}</div></section>`;
        })
        .join("");
    $("addRuleBtn")?.addEventListener("click", () => editor());
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
      <div class="d-flex gap-2 align-items-center mb-2"><span class="ap-path-n" data-n></span><input type="text" class="form-control" data-f="name" maxlength="120" placeholder="Step name, e.g. Pastor" value="${esc(s.name || "")}"><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="-1" aria-label="Move up"><i class="ri-arrow-up-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="1" aria-label="Move down"><i class="ri-arrow-down-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-outline-danger" data-remove-stage aria-label="Remove step"><i class="ri-close-line"></i></button></div>
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
    $("ruleWindow")?.remove();
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
    $("apHero").innerHTML = `<div class="ap-loading">${A.empty("ri-loader-4-line", "Reading the approvals…", "")}</div>`;
    const [m, l, w] = await Promise.all([API.ruleMap(subject), API.ruleLive(days, subject), API.workflows()]);
    const bad = [m, l, w].find((r) => !r.ok);
    if (bad) {
      $("apHero").innerHTML = A.errorBox(bad.message);
      return;
    }
    map = m.data;
    live = l.data;
    meta = w.data;
    A.placeLine($("accPlaceLine"), meta.place);
    $("apBrandName").textContent = map.place.name;
    if (tried) {
      const r = await API.ruleTry(subject, tried.amount);
      tried = r.ok ? { amount: tried.amount, answer: r.data } : null;
    }
    hero();
    next();
    detected();
    assistant();
    rules();
    showTab();
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("apDays").value = String(days);
    $("apDays").addEventListener("change", (e) => {
      days = Number(e.target.value);
      keepUrl();
      load();
    });
    document.querySelector(".ap-nav").addEventListener("click", (e) => {
      const b = e.target.closest("[data-ap-tab]");
      if (!b) return;
      tab = b.dataset.apTab;
      showTab();
    });
    document.addEventListener("change", (e) => {
      if (e.target.matches("[data-days]")) {
        days = Number(e.target.value);
        $("apDays").value = String(days);
        keepUrl();
        return load();
      }
      if (e.target.matches("[data-subject-pick]")) {
        subject = e.target.value;
        keepUrl();
        return load();
      }
    });
    document.addEventListener("click", async (e) => {
      if (e.target.closest("[data-go-rules]")) return (tab = "rules"), showTab();
      const act = e.target.closest("[data-action]");
      if (act) {
        const a = act.dataset.action;
        if (a === "rules") return (tab = "rules"), showTab();
        if (a === "try") return runTry(250000);
        if (a === "handover") return (window.location.href = `${CTX.baseUrl}/approvals.php?tab=delegations`);
        if (a === "blocked") return (window.location.href = `${CTX.baseUrl}/approvals.php`);
        return (window.location.href = CTX.canAdmin ? `${CTX.siteUrl}/diocese/settings/admin/role-management.php` : `${CTX.siteUrl}/${CTX.level}/settings/?section=team`);
      }
      const sb = e.target.closest("[data-subject]");
      if (sb) {
        subject = sb.dataset.subject;
        keepUrl();
        return load();
      }
      const tv = e.target.closest("[data-try]");
      if (tv) return runTry(Number(tv.dataset.try));
      if (e.target.closest("[data-explain]")) return (explain = true), (tried = null), assistant();
      if (e.target.closest("[data-clear]")) return (explain = false), (tried = null), assistant(), tab === "rules" && rules();
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
