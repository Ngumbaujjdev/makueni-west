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
 *   - each rule reads When -> Then -> Tells; opening one shows who it tells
 *     and when, and the messages it has sent (bell, SMS, email)
 *   - Rules: who signs which amounts, then our own, from above, the
 *     diocese's standard and the places' own - with the editor
 * The Filter covers all documents by default; the amount map and the amount
 * box work on one document at a time (`doc`, payment vouchers first).
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
  const DOCS = ["payment_voucher", "requisition", "payroll_run"];
  const DOC_CHIP = { payment_voucher: ["ri-file-list-3-line", "Voucher"], requisition: ["ri-hand-coin-line", "Requisition"], payroll_run: ["ri-team-line", "Payroll"] };
  let subject = DOCS.includes(params.get("subject")) ? params.get("subject") : "all";
  let doc = DOCS.includes(params.get("doc")) ? params.get("doc") : "payment_voucher";
  let days = [1, 7, 14, 30].includes(Number(params.get("days"))) ? Number(params.get("days")) : 14;
  let map = null;
  let lanes = null;
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
    subject === "all" ? p.delete("subject") : p.set("subject", subject);
    doc === "payment_voucher" ? p.delete("doc") : p.set("doc", doc);
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
          <label class="ap-tool"><i class="ri-equalizer-line"></i><span>${subject === "all" ? "All documents" : esc(map.subjects.find((s) => s.key === subject)?.label || "Filter")}</span><select data-subject-pick aria-label="Which documents"><option value="all"${subject === "all" ? " selected" : ""}>All documents</option>${map.subjects.map((s) => `<option value="${s.key}"${s.key === subject ? " selected" : ""}>${esc(s.label)}</option>`).join("")}</select></label>
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
      <h3 class="ap-assist-title">Welcome${name ? `, ${esc(name)}` : ""}<br>What can I help with today?</h3>
      <div class="ap-assist-chips">
        <button type="button" class="ap-chip is-btn" data-try="75000"><i class="ri-price-tag-3-line"></i>Who signs 75,000?</button>
        <button type="button" class="ap-chip is-btn" data-explain><i class="ri-file-text-line"></i>Explain the map</button>
        <a class="ap-chip is-btn" href="${CTX.baseUrl}/approvals.php?tab=delegations"><i class="ri-user-add-line"></i>Hand over</a>
      </div>
      <div class="ap-assist-body">${body}${r || explain ? '<button type="button" class="ap-back" data-clear><i class="ri-arrow-left-line"></i>Back to what we found</button>' : ""}</div>
      <form class="ap-ask-box" id="apTryForm">
        <div class="ap-ask-row"><input type="text" inputmode="numeric" id="apTryAmount" placeholder="Type an amount, e.g. 75,000" value="${tried ? A.num(tried.amount) : ""}" aria-label="Amount to try" autocomplete="off"><button type="submit" class="ap-send" aria-label="Who would sign it?"><i class="ri-send-plane-2-line"></i></button></div>
        <div class="ap-ask-tools">${docChips()}<span class="ap-ask-hint">KES</span></div>
      </form>
    </div>`;
  }

  const docChips = () => DOCS.map((k) => `<button type="button" class="ap-tool-chip${k === doc ? " is-on" : ""}" data-doc="${k}"><i class="${DOC_CHIP[k][0]}"></i>${DOC_CHIP[k][1]}</button>`).join("");

  async function runTry(amount) {
    if (!amount || amount < 1) return Toast.error("Type an amount, e.g. 75,000.");
    const r = await API.ruleTry(doc, amount);
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
    const map = lanes;
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
        <div class="ap-hero-tools"><div class="ap-ask-tools is-inline">${docChips()}</div>${meta.can.manage ? '<button type="button" class="btn btn-primary rounded-pill" id="addRuleBtn"><i class="ri-add-line me-1"></i>Add a rule</button>' : '<span class="ap-chip"><i class="ri-eye-line"></i>See only</span>'}</div></div>
      <div class="ap-grid-wrap"><div class="ap-grid is-amounts" style="grid-template-columns:minmax(11.5rem, 12.5rem) repeat(${cols}, minmax(4.4rem, 1fr));">
        <div class="ap-grid-label" style="grid-row:1">Who signs</div>
        ${marks.map((m, i) => `<div class="ap-tick${i === triedCol ? " is-now" : map.segments[i]?.standard_only ? " is-quiet" : ""}" style="grid-column:${i + 2};grid-row:1"><small>KES</small><b>${i === cols - 1 ? `${short(m)}+` : short(m)}</b></div>`).join("")}
        ${rows || `<div class="ap-empty" style="grid-column:1 / -1">${A.empty("ri-shield-check-line", "No rule covers these documents here", "Anyone who can authorise payments approves them - never their own.")}</div>`}
      </div></div></section>`;
  }

  const DOC_ICON = { "*": "ri-stack-line", payment_voucher: "ri-file-list-3-line", requisition: "ri-hand-coin-line", payroll_run: "ri-team-line" };
  const OWNER = { own: "Our own rule", above: "Set up above for us", standard: "The diocese's standard rule", below: "A place's own rule" };
  const whoOf = (s) => s.steps.map((st) => (st.people?.length ? st.people.map((p) => p.name).join(", ") : st.label || "")).join(" or ");
  const whereOf = (w) => (w.owner ? (w.reach === "below" ? `${w.owner.name}'s churches` : w.owner.name) : LEVEL[w.level || ""]);
  /** ① Pastor -> ② Committee -> Approved */
  const flow = (w) =>
    `<div class="ap-flow">${w.stages
      .map((s, i) => `<div class="ap-flow-step"><span class="ap-path-n">${i + 1}</span><div class="min-w-0"><b>${esc(s.name)}</b><small>${esc(whoOf(s))}${s.type === "all" ? " · all of them" : s.type === "quorum" ? ` · any ${s.quorum}` : ""}</small>${s.sla_hours || s.escalate_label ? `<small class="ap-flow-meta"><i class="ri-time-line"></i>${s.sla_hours ? `${s.sla_hours}h` : ""}${s.escalate_label ? `${s.sla_hours ? " · " : ""}then ${esc(s.escalate_label)}` : ""}</small>` : ""}</div></div><i class="ri-arrow-right-line ap-flow-arrow" aria-hidden="true"></i>`)
      .join("")}<div class="ap-flow-step is-end"><span class="ap-flow-done"><i class="ri-check-line"></i></span><b>Approved</b></div></div>`;
  const CHANNELS = [["bell", "ri-notification-3-line", "Bell"], ["sms", "ri-message-2-line", "SMS"], ["email", "ri-mail-line", "Email"]];
  const tellsChips = () => CHANNELS.map(([k, icon, label]) => { const on = k === "bell" || meta.notify?.[k]; return `<span class="ap-tell${on ? "" : " is-off"}" title="${label}${on ? "" : " - switched off in Settings"}"><i class="${icon}"></i>${label}</span>`; }).join("");
  const whenThen = (w) => `
      <div class="ap-cond"><span class="ap-cond-k">When</span><div class="ap-cond-v"><span class="soft-chip soft-primary"><i class="${DOC_ICON[w.subject_type] || "ri-file-line"}"></i>${esc(w.subject_label)}</span><span class="soft-chip soft-success"><i class="ri-money-dollar-circle-line"></i>${esc(band(w))}</span><span class="soft-chip soft-purple"><i class="ri-map-pin-line"></i>${esc(whereOf(w))}</span></div></div>
      <div class="ap-cond"><span class="ap-cond-k">Then</span><div class="ap-cond-v">${flow(w)}</div></div>
      <div class="ap-cond"><span class="ap-cond-k">Tells</span><div class="ap-cond-v">${tellsChips()}</div></div>`;

  function ruleCard(w) {
    return `<div class="ap-rule${w.is_active ? "" : " is-off"}" data-open="${w.id}" role="button" tabindex="0" aria-label="Open ${esc(w.name)}">
      <div class="ap-rule-head"><div class="min-w-0"><div class="ap-rule-name">${esc(w.name)}</div><div class="ap-rule-sub">${esc(OWNER[w.group] || "")}</div></div><span class="badge ${w.is_active ? "bg-success" : "bg-secondary"}">${w.is_active ? "In use" : "Off"}</span></div>
      ${whenThen(w)}
      <div class="ap-rule-foot"><button type="button" class="btn btn-sm btn-icon btn-primary" data-open="${w.id}" aria-label="Open ${esc(w.name)}" title="Open"><i class="ri-eye-line"></i></button>${w.editable ? `<button type="button" class="btn btn-sm btn-icon btn-warning" data-edit="${w.id}" aria-label="Change ${esc(w.name)}" title="Change"><i class="ri-edit-line"></i></button><button type="button" class="btn btn-sm btn-icon btn-danger" data-del="${w.id}" aria-label="Remove ${esc(w.name)}" title="Remove"><i class="ri-delete-bin-line"></i></button>` : ""}</div>
    </div>`;
  }

  // ------------------------------------------------------------ a rule opened: who it tells, and what it has sent

  const MOMENT = {
    assigned: ["ri-user-received-line", "primary"], reminder: ["ri-alarm-line", "warning"], escalated: ["ri-arrow-up-circle-line", "purple"], blocked: ["ri-error-warning-line", "danger"],
    approved: ["ri-checkbox-circle-line", "success"], rejected: ["ri-close-circle-line", "danger"], returned: ["ri-arrow-go-back-line", "warning"], cancelled: ["ri-forbid-line", "secondary"],
  };
  const STATE = { sent: ["Sent", "success"], logged: ["Logged", "secondary"], failed: ["Failed", "danger"], read: ["Read", "success"], unread: ["Not read yet", "warning"] };
  async function openRule(id) {
    const w = meta.items.find((x) => x.id === id);
    if (!w) return;
    $("ruleView")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="ruleView" tabindex="-1" aria-labelledby="rvTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-git-branch-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="rvTitle">${esc(w.name)}</h5><div class="app-modal-subtitle">${esc(OWNER[w.group] || "")} · ${w.is_active ? "in use" : "switched off"}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-git-branch-line"></i>The rule</div><div class="ap-rule is-flat">${whenThen(w)}</div></section>
          <div id="rvBody"><section class="app-modal-part"><div class="placeholder-glow"><span class="placeholder col-12 mb-2" style="height:3rem"></span><span class="placeholder col-12" style="height:8rem"></span></div></section></div>
        </div>
        <div class="modal-footer">${w.editable ? `<button type="button" class="btn btn-warning" data-edit="${w.id}"><i class="ri-edit-line me-1"></i>Change</button>` : ""}<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button></div>
      </div></div></div>`,
    );
    const el = $("ruleView");
    el.addEventListener("hidden.bs.modal", () => {
      const p = new URLSearchParams(window.location.search);
      ["rmq", "rmpill", "rmsort"].forEach((k) => p.delete(k));
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      el.remove();
    });
    el.querySelector("[data-edit]")?.addEventListener("click", () => {
      bootstrap.Modal.getInstance(el)?.hide();
      editor(w);
    });
    bootstrap.Modal.getOrCreateInstance(el).show();
    const r = await API.ruleMessages(id);
    if (!r.ok) return ($("rvBody").innerHTML = A.errorBox(r.message));
    const d = r.data;
    const ch = (channels) => CHANNELS.filter(([k]) => channels[k]).map(([, icon, label]) => `<span class="ap-tell"><i class="${icon}"></i>${label}</span>`).join("");
    const tells = d.tells
      .map((t) => {
        const [icon, colour] = MOMENT[t.moment] || MOMENT.assigned;
        return `<li class="ap-tells-item"><span class="ap-tells-icon bg-${colour} text-white"><i class="${icon}"></i></span><div class="min-w-0 flex-fill">
          <div class="ap-tells-head"><b>${esc(t.title)}</b><span class="ap-tells-ch">${ch(d.channels)}</span></div>
          <div class="ap-tells-to"><i class="ri-user-line"></i>${esc(t.to || "-")}</div>
          <div class="ap-quote"><i class="ri-chat-quote-line"></i><span>${esc(t.sms)}</span></div></div></li>`;
      })
      .join("");
    const c = d.counts;
    $("rvBody").innerHTML = `
      <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-notification-badge-line"></i>Who is told, and when<small>Example wording for a payment this rule covers</small></div><ol class="ap-tells">${tells}</ol></section>
      <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-mail-send-line"></i>Messages sent<small>${c.requests} ${c.requests === 1 ? "request" : "requests"} under this rule</small></div>
        <div class="d-flex flex-wrap gap-2 mb-3"><span class="soft-chip soft-primary"><i class="ri-notification-3-line"></i>Bell ${c.bell}</span><span class="soft-chip soft-success"><i class="ri-message-2-line"></i>SMS ${c.sms}</span><span class="soft-chip soft-purple"><i class="ri-mail-line"></i>Email ${c.email}</span><span class="soft-chip soft-warning"><i class="ri-team-line"></i>${c.people} ${c.people === 1 ? "person" : "people"}</span>${c.failed ? `<span class="badge bg-danger"><i class="ri-error-warning-line me-1"></i>${c.failed} failed</span>` : ""}</div>
        <div class="table-responsive"><table class="table text-nowrap ap-msg-table" id="rvTable"><thead><tr><th>When</th><th>Document</th><th>What</th><th>To</th><th>How</th></tr></thead><tbody></tbody></table></div></section>`;
    A.tableKit({
      tableId: "rvTable",
      prefix: "rm",
      items: d.items,
      noun: "messages",
      search: "Search a name, document or message...",
      pageLength: 10,
      pills: [
        { key: "sms", label: "SMS", icon: "ri-message-2-line", color: "success", test: (i) => i.channel === "sms" },
        { key: "email", label: "Email", icon: "ri-mail-line", color: "purple", test: (i) => i.channel === "email" },
        { key: "bell", label: "Bell", icon: "ri-notification-3-line", color: "primary", test: (i) => i.channel === "bell" },
        { key: "failed", label: "Failed", icon: "ri-error-warning-line", color: "danger", test: (i) => i.status === "failed" },
      ],
      sorts: [{ key: "new", label: "Newest first", order: [[0, "desc"]] }, { key: "old", label: "Oldest first", order: [[0, "asc"]] }],
      empty: A.empty("ri-mail-send-line", "Nothing sent under this rule yet", "When a payment follows this rule, every message it sends shows here."),
      rowHtml: (i) => {
        const [icon, colour] = MOMENT[i.event] || ["ri-notification-3-line", "secondary"];
        const [state, sc] = STATE[i.status] || [i.status, "secondary"];
        const [, chIcon, chLabel] = CHANNELS.find(([k]) => k === i.channel) || CHANNELS[0];
        const doc = i.document;
        return `<tr data-pills="${i.channel}${i.status === "failed" ? " failed" : ""}">
          <td data-order="${esc(i.at)}">${A.dateChip(i.at)} <small class="ap-rule-sub">${new Date(i.at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}</small></td>
          <td>${doc?.type ? `<a href="${A.link("record.php", { type: doc.type, id: doc.id })}" class="fw-semibold">${esc(doc.label)}</a>` : esc(doc?.label || "-")}</td>
          <td><span class="badge bg-${colour}"><i class="${icon} me-1"></i>${esc(i.event_label)}</span></td>
          <td>${i.to_name ? A.person(i.to_name) : `<span class="ap-rule-sub">${esc(i.to_masked || "-")}</span>`}</td>
          <td title="${esc(i.text)}"><span class="ap-tell"><i class="${chIcon}"></i>${chLabel}</span> <span class="soft-chip soft-${sc}">${esc(state)}</span></td>
        </tr>`;
      },
    });
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

  // ------------------------------------------------------------ the editor: the rule window
  // In the manner of the v1-events promo-code window: the form on the left in two numbered steps
  // (which payments, then who signs in order) and "How it will run" beside it, live as you type.

  const jq = window.jQuery;
  let uid = 0;
  const nid = (p) => `${p}${++uid}`;
  const rolesFor = (level) => meta.roles.filter((r) => !level || r.level === level);
  const roleOptions = (level, cur) => `<option value="">Pick a role…</option>${rolesFor(level).map((r) => `<option value="${esc(r.name)}"${r.name === cur ? " selected" : ""}>${esc(r.name)}</option>`).join("")}`;
  const readMoney = (el) => {
    const v = String(el.value).replace(/[^0-9.]/g, "");
    return v === "" ? null : Number(v);
  };
  /** A pill segmented control: Bootstrap btn-check radios, read with segVal(box, key). */
  function seg(key, options, value, cls = "") {
    const name = nid(`rw-${key}-`);
    return `<div class="btn-group rw-seg ${cls}" role="group" data-seg="${key}">${options
      .map(([v, label, icon]) => {
        const id = nid("rws");
        return `<input type="radio" class="btn-check" name="${name}" id="${id}" value="${esc(v)}"${String(v) === String(value ?? "") ? " checked" : ""}><label class="btn btn-outline-primary" for="${id}">${icon ? `<i class="${icon}"></i>` : ""}${label}</label>`;
      })
      .join("")}</div>`;
  }
  const segVal = (box, key) => box.querySelector(`[data-seg="${key}"] input:checked`)?.value ?? "";

  const KINDS = [
    ["role_here", "A role here", "ri-user-star-line"],
    ["role_above", "A role above", "ri-arrow-up-circle-line"],
    ["permission_here", "Anyone who can…", "ri-key-2-line"],
    ["user", "Named people", "ri-user-search-line"],
  ];
  function whoBlock(step, key, ruleLevel) {
    const t = step?.resolver_type || (key === "esc" ? "" : "role_here");
    const cfg = step?.resolver_config || {};
    const role = (cfg.roles || [cfg.role])[0] || "";
    const above = cfg.level || (ruleLevel === "church" ? "region" : "diocese");
    const people = step?.people || (cfg.user_ids || []).map((id) => ({ id, name: `#${id}` }));
    const hide = (on) => (on ? "" : " d-none");
    return `<div class="rw-who" data-who="${key}">
      <div class="rw-who-kinds">${seg("kind", key === "esc" ? [["", "Nobody - it waits", "ri-pause-circle-line"], ...KINDS] : KINDS, t, "is-sm")}</div>
      <div class="rw-who-row">
        <div class="rw-who-pick${hide(t === "role_above")}" data-wrap="above">${seg("above", [["region", "The region above"], ["diocese", "The diocese"]], above, "is-sm")}</div>
        <div class="rw-who-pick is-grow${hide(["role_here", "role_above"].includes(t))}" data-wrap="role"><select class="form-select" data-k="role" aria-label="Role">${roleOptions(t === "role_above" ? above : ruleLevel, role)}</select></div>
        <div class="rw-who-pick is-grow${hide(t === "permission_here")}" data-wrap="perm"><select class="form-select" data-k="perm" aria-label="What they can do">${meta.permissions.map((p) => `<option value="${esc(p.key)}"${p.key === cfg.permission ? " selected" : ""}>${esc(p.label)}</option>`).join("")}</select></div>
        <div class="rw-who-pick is-grow${hide(t === "user")}" data-wrap="users"><select class="form-select" data-k="users" multiple aria-label="People">${people.map((p) => `<option value="${p.id}" selected>${esc(p.name)}</option>`).join("")}</select></div>
        ${key === "who" ? '<button type="button" class="btn btn-icon btn-sm btn-light border rw-who-x" data-remove-who aria-label="Remove this approver"><i class="ri-close-line"></i></button>' : ""}
      </div>
    </div>`;
  }
  const readWho = (box) => {
    const type = segVal(box, "kind");
    if (!type) return null;
    const k = (n) => box.querySelector(`[data-k="${n}"]`);
    if (type === "user") return { resolver_type: "user", resolver_config: { user_ids: [...k("users").selectedOptions].map((o) => Number(o.value)) } };
    if (type === "permission_here") return { resolver_type: "permission_here", resolver_config: { permission: k("perm").value } };
    const cfg = { roles: [k("role").value] };
    if (type === "role_above") cfg.level = segVal(box, "above");
    return { resolver_type: type, resolver_config: cfg };
  };
  /** In words, for the preview: "Senior Pastor here", "anyone here who can pay vouchers", "Benson Manoo". */
  const whoWords = (box) => {
    const type = segVal(box, "kind");
    const k = (n) => box.querySelector(`[data-k="${n}"]`);
    if (!type) return "";
    if (type === "user") return [...k("users").selectedOptions].map((o) => o.textContent).join(", ") || "named people";
    if (type === "permission_here") return (meta.permissions.find((p) => p.key === k("perm").value)?.label || "anyone who can…").replace(/^Anyone/, "anyone");
    return `${k("role").value || "a role"} ${type === "role_above" ? (segVal(box, "above") === "region" ? "at the region above" : "at the diocese") : "here"}`;
  };
  function wireWho(box, ruleLevel) {
    const k = (n) => box.querySelector(`[data-k="${n}"]`);
    UI.enhanceSelect(k("role"), { search: true });
    UI.enhanceSelect(k("perm"));
    UI.enhanceSelect(k("users"), {
      placeholder: "Search a name, e.g. Benson",
      ajax: {
        delay: 250,
        transport: (p, ok, fail) => {
          API.approvalPeople(p.data.term || "").then((r) => (r.ok ? ok({ results: r.data.map((x) => ({ id: x.id, text: x.name })) }) : fail()));
          return { abort() {} };
        },
      },
      templateResult: (o) => (o.id ? jq(A.person(o.text)) : o.text),
      templateSelection: (o) => o.text,
    });
    box.addEventListener("change", (e) => {
      if (e.target.closest('[data-seg="kind"]')) {
        const t = segVal(box, "kind");
        const show = (n, on) => box.querySelector(`[data-wrap="${n}"]`).classList.toggle("d-none", !on);
        show("above", t === "role_above");
        show("role", ["role_here", "role_above"].includes(t));
        show("perm", t === "permission_here");
        show("users", t === "user");
      }
      if (e.target.closest('[data-seg="kind"]') || e.target.closest('[data-seg="above"]')) {
        const t = segVal(box, "kind");
        const sel = k("role");
        sel.innerHTML = roleOptions(t === "role_above" ? segVal(box, "above") : ruleLevel(), sel.value);
        UI.syncSelect(sel);
      }
    });
  }

  const ON_EMPTY = [["block", "Wait, and tell whoever looks after the rules"], ["skip", "Skip this step"], ["escalate", "Pass it to the person below"]];
  const ON_REJECT = [["terminate", "It stops"], ["return_previous", "Back to the step before"], ["continue", "Carry on anyway"]];
  const opts = (list, cur) => list.map(([v, l]) => `<option value="${v}"${v === cur ? " selected" : ""}>${l}</option>`).join("");
  function stageHtml(s, ruleLevel) {
    const type = s.type || "single";
    const more = !!(s.sla_hours || s.escalate_after_hours || s.escalate_to || (s.on_reject && s.on_reject !== "terminate") || (s.on_empty && s.on_empty !== "block"));
    return `<div class="rw-stage" data-stage>
      <div class="rw-stage-head"><span class="rw-stage-n" data-n></span><input type="text" class="form-control" data-f="name" maxlength="120" placeholder="Step name, e.g. Pastor" value="${esc(s.name || "")}" aria-label="Step name">
        <div class="rw-stage-tools"><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="-1" aria-label="Move up"><i class="ri-arrow-up-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-light border" data-move="1" aria-label="Move down"><i class="ri-arrow-down-line"></i></button><button type="button" class="btn btn-icon btn-sm btn-danger" data-remove-stage aria-label="Remove step"><i class="ri-delete-bin-line"></i></button></div></div>
      <div class="rw-label">Who approves</div>
      <div data-whos>${(s.steps?.length ? s.steps : [null]).map((st) => whoBlock(st, "who", ruleLevel)).join("")}</div>
      <button type="button" class="rw-link" data-add-who><i class="ri-add-line"></i>Or someone else as well</button>
      <div class="rw-label">How many must agree</div>
      <div class="d-flex flex-wrap align-items-center gap-2">${seg("type", [["single", "Any one"], ["all", "Everyone"], ["quorum", "A number"]], type, "is-sm")}<input type="number" min="1" max="20" class="form-control rw-quorum${type === "quorum" ? "" : " d-none"}" data-f="quorum" value="${s.quorum || 2}" aria-label="How many" placeholder="2"></div>
      <details class="rw-more"${more ? " open" : ""}><summary><i class="ri-timer-line"></i>Time limits, and what happens if…<i class="ri-arrow-down-s-line ms-auto"></i></summary>
        <div class="rw-fields">
          <div class="rw-field"><label>Hours to decide</label><input type="number" min="1" max="720" class="form-control" data-f="sla_hours" value="${s.sla_hours || ""}" placeholder="e.g. 48"></div>
          <div class="rw-field"><label>Pass it up after (hours)</label><input type="number" min="1" max="720" class="form-control" data-f="escalate_after_hours" value="${s.escalate_after_hours || ""}" placeholder="e.g. 24"></div>
          <div class="rw-field"><label>If someone says no</label><select class="form-select" data-f="on_reject" aria-label="If someone says no">${opts(ON_REJECT, ["return_previous", "continue"].includes(s.on_reject) ? s.on_reject : "terminate")}</select></div>
          <div class="rw-field"><label>If nobody here holds it</label><select class="form-select" data-f="on_empty" aria-label="If nobody here holds it">${opts(ON_EMPTY, s.on_empty || "block")}</select></div>
          <div class="rw-field is-wide"><label>When it's late, or nobody holds it, pass it to</label>${whoBlock(s.escalate_to, "esc", ruleLevel)}</div>
        </div>
      </details>
    </div>`;
  }

  const SUBJECT_CARD = { "*": ["ri-stack-line", "primary", "Vouchers, requisitions and payroll"], payment_voucher: ["ri-file-list-3-line", "success", "Paying a bill or a person"], requisition: ["ri-hand-coin-line", "purple", "Asking for money to spend"], payroll_run: ["ri-team-line", "warning", "The month's pay"] };

  function editor(w = null) {
    const own = CTX.level !== "diocese";
    const ceiling = own ? Number(meta.ceiling) || 0 : 0;
    const scaleMax = own && ceiling ? ceiling : 1000000;
    let el = null;
    const ruleLevel = () => (CTX.level === "church" ? "church" : CTX.level === "region" ? (segVal(el, "for") === "churches" ? "church" : "region") : segVal(el, "level") || null);
    const presets = own && ceiling
      ? [[null, Math.min(10000, ceiling)], [10000, Math.min(50000, ceiling)], [50000, ceiling], [null, ceiling]].filter(([a, b], i, all) => (a === null || a < b) && all.findIndex(([x, y]) => x === a && y === b) === i)
      : [[null, 50000], [50000, 200000], [200000, null], [null, null]];
    const presetLabel = ([a, b]) => (a === null && b === null ? "Any amount" : a === null ? `Up to ${short(b)}` : b === null ? `Above ${short(a)}` : `${short(a)} - ${short(b)}`);
    const bandWords = (lo, hi) => (lo !== null && hi !== null ? `KES ${A.num(lo)} - ${A.num(hi)}` : hi !== null ? `Up to KES ${A.num(hi)}` : lo !== null ? (own ? `KES ${A.num(lo)} - ${A.num(ceiling)}` : `Above KES ${A.num(lo)}`) : own ? `Up to KES ${A.num(ceiling)}` : "Any amount");
    const subjectNow = w?.subject_type || "*";
    const cards = meta.subjects
      .map((s) => {
        const [icon, colour, sub] = SUBJECT_CARD[s.key] || ["ri-file-line", "primary", ""];
        return `<label class="rw-card"><input type="radio" class="btn-check" name="rlSubject" value="${esc(s.key)}"${s.key === subjectNow ? " checked" : ""}><span class="rw-card-body"><span class="rw-card-icon bg-${colour} text-white"><i class="${icon}"></i></span><span class="min-w-0"><b>${esc(s.label)}</b><small>${esc(sub)}</small></span></span></label>`;
      })
      .join("");
    const forRegion = CTX.level === "region" ? `<div class="rw-field is-wide"><label>For</label>${seg("for", [["self", "The region's own payments", "ri-government-line"], ["churches", "Our churches' payments", "ri-community-line"]], w?.reach === "below" && w?.level === "church" ? "churches" : "self")}</div>` : "";
    const levelPick = CTX.level === "diocese" ? `<div class="rw-field is-wide"><label>Which places</label>${seg("level", [["", "Every level"], ["church", "Churches"], ["region", "Regions"], ["diocese", "The diocese"]], w?.level || "")}</div>` : "";
    $("ruleWindow")?.remove();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal rw-modal" id="ruleWindow" tabindex="-1" aria-labelledby="ruleTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="ri-shield-check-line"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="ruleTitle">${w ? `Change "${esc(w.name)}"` : own ? `Add a rule for ${esc(map.place.name)}` : "Add a standard rule"}</h5><div class="app-modal-subtitle">New requests follow it; ones already waiting keep their rule</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body rw-body"><div class="rw-grid">
          <div class="rw-form">
            <section class="rw-step">
              <header class="rw-step-head"><span class="rw-num bg-primary text-white"><i class="ri-filter-3-line"></i></span><div><h6>Which payments</h6><p>The documents and amounts this rule covers.</p></div></header>
              <div class="rw-fields">
                <div class="rw-field is-wide"><label for="rlName">Name</label><input type="text" class="form-control" id="rlName" maxlength="150" value="${esc(w?.name || "")}" placeholder="e.g. Small payments"></div>
                <div class="rw-field is-wide"><label>Documents</label><div class="rw-cards">${cards}</div></div>
                ${forRegion}${levelPick}
                <div class="rw-field is-wide"><label for="rlMin">Amount</label>
                  <div class="rw-range"><label class="rw-money"><span>From KES</span><input type="text" inputmode="numeric" id="rlMin" value="${w?.amount_min ? A.num(w.amount_min) : ""}" placeholder="0" autocomplete="off"></label><span class="rw-range-to"><i class="ri-arrow-right-line"></i></span><label class="rw-money"><span>To KES</span><input type="text" inputmode="numeric" id="rlMax" value="${w?.amount_max !== null && w?.amount_max !== undefined ? A.num(w.amount_max) : ""}" placeholder="${own ? A.num(ceiling) : "No limit"}" autocomplete="off"></label></div>
                  <div class="rw-presets">${presets.map((p) => `<button type="button" class="rw-preset" data-preset="${p[0] ?? ""}|${p[1] ?? ""}">${presetLabel(p)}</button>`).join("")}</div>
                  <div class="rw-ruler"><span class="rw-ruler-band" id="rlBand"></span></div>
                  <div class="rw-ruler-scale"><span>0</span><span>${short(scaleMax / 2)}</span><span>${short(scaleMax)}${own ? "" : "+"}</span></div>
                  ${own ? `<div class="rw-hint"><i class="ri-information-line"></i>Above KES ${A.num(ceiling)} the diocese's rules apply.</div>` : ""}
                </div>
              </div>
            </section>
            <section class="rw-step">
              <header class="rw-step-head"><span class="rw-num bg-purple text-white"><i class="ri-route-line"></i></span><div><h6>Who signs, in order</h6><p>Each step agrees before the next one sees it.</p></div></header>
              <div id="rlStages"></div>
              <button type="button" class="rw-add" id="rlAdd"><i class="ri-add-line"></i>Add a step</button>
            </section>
          </div>
          <aside class="rw-preview"><div class="rw-preview-in">
            <div class="rw-preview-label">How it will run</div>
            <div id="rlPreview"></div>
            <label class="rw-toggle" for="rlActive"><span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="rlActive"${w?.is_active === false ? "" : " checked"}></span><span><strong>In use</strong><small>Off keeps the rule without using it</small></span></label>
          </div></aside>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rlGo"><i class="ri-check-line me-1"></i>Save rule</button></div>
      </div></div></div>`,
    );
    el = $("ruleWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    const box = el.querySelector("#rlStages");
    const minEl = el.querySelector("#rlMin");
    const maxEl = el.querySelector("#rlMax");

    const refresh = () => {
      const lo = readMoney(minEl);
      const hi = readMoney(maxEl);
      const left = Math.min(100, ((lo || 0) / scaleMax) * 100);
      const right = hi === null ? 100 : Math.min(100, (hi / scaleMax) * 100);
      const bandEl = el.querySelector("#rlBand");
      bandEl.style.left = `${Math.min(left, 98.5)}%`;
      bandEl.style.width = `${Math.max(right - left, 1.5)}%`;
      bandEl.classList.toggle("is-over", own && hi !== null && hi > ceiling);
      el.querySelectorAll("[data-preset]").forEach((b) => b.classList.toggle("is-on", b.dataset.preset === `${lo ?? ""}|${hi ?? ""}`));
      const subj = el.querySelector('input[name="rlSubject"]:checked')?.value;
      const stages = [...box.querySelectorAll("[data-stage]")];
      const agree = (st) => ({ single: "any one", all: "all", quorum: `any ${st.querySelector('[data-f="quorum"]').value || 2}` })[segVal(st, "type")] || "any one";
      const forWords = CTX.level === "region" ? (segVal(el, "for") === "churches" ? "Our churches" : "The region") : CTX.level === "diocese" ? { "": "Every level", church: "Churches", region: "Regions", diocese: "The diocese" }[segVal(el, "level")] : esc(map.place.name);
      el.querySelector("#rlPreview").innerHTML = `
        <div class="rw-pv-name">${esc(el.querySelector("#rlName").value.trim() || "New rule")}</div>
        <div class="rw-pv-facts"><span class="badge bg-primary">${esc(meta.subjects.find((s) => s.key === subj)?.label || "")}</span><span class="soft-chip soft-success"><i class="ri-money-dollar-circle-line"></i>${bandWords(lo, hi)}</span><span class="soft-chip soft-purple"><i class="ri-map-pin-line"></i>${forWords}</span></div>
        <ol class="rw-pv-path">${stages
          .map((st, i) => {
            const whos = [...st.querySelectorAll('[data-whos] [data-who="who"]')].map(whoWords).filter(Boolean);
            const sla = st.querySelector('[data-f="sla_hours"]').value;
            return `<li><span class="rw-stage-n">${i + 1}</span><div class="min-w-0"><b>${esc(st.querySelector('[data-f="name"]').value.trim() || `Step ${i + 1}`)}</b><div class="rw-pv-who">${esc(whos.join(" or ") || "Nobody yet")}</div><div class="rw-pv-meta">${whos.length > 1 ? `${agree(st)} of them` : ""}${sla ? `${whos.length > 1 ? " · " : ""}${sla}h to decide` : ""}</div></div></li>`;
          })
          .join("")}</ol>
        <div class="rw-pv-end"><i class="ri-checkbox-circle-fill"></i>Then it's approved</div>`;
    };
    const number = () => box.querySelectorAll("[data-stage]").forEach((s, i) => (s.querySelector("[data-n]").textContent = i + 1));
    const addStage = (s) => {
      box.insertAdjacentHTML("beforeend", stageHtml(s, ruleLevel()));
      const st = box.lastElementChild;
      st.querySelectorAll("[data-who]").forEach((b) => wireWho(b, ruleLevel));
      ["on_reject", "on_empty"].forEach((f) => UI.enhanceSelect(st.querySelector(`[data-f="${f}"]`)));
      number();
      refresh();
    };
    (w?.stages?.length ? w.stages : [{}]).forEach(addStage);
    el.querySelector("#rlAdd").addEventListener("click", () => addStage({}));
    [minEl, maxEl].forEach((i) =>
      i.addEventListener("input", () => {
        const v = readMoney(i);
        i.value = v === null ? "" : A.num(v);
      }),
    );
    el.addEventListener("input", refresh);
    el.addEventListener("change", (e) => {
      const st = e.target.closest("[data-stage]");
      if (st && e.target.closest('[data-seg="type"]')) st.querySelector('[data-f="quorum"]').classList.toggle("d-none", segVal(st, "type") !== "quorum");
      if (e.target.closest('[data-seg="for"]') || e.target.closest('[data-seg="level"]')) {
        // The roles offered follow the level the rule is for.
        box.querySelectorAll('[data-who]').forEach((b) => {
          if (segVal(b, "kind") !== "role_here") return;
          const sel = b.querySelector('[data-k="role"]');
          sel.innerHTML = roleOptions(ruleLevel(), sel.value);
          UI.syncSelect(sel);
        });
      }
      refresh();
    });
    jq(el).on("change select2:unselect", "select", refresh);
    el.addEventListener("click", (e) => {
      const pr = e.target.closest("[data-preset]");
      if (pr) {
        const [a, b] = pr.dataset.preset.split("|");
        minEl.value = a ? A.num(Number(a)) : "";
        maxEl.value = b ? A.num(Number(b)) : "";
        return refresh();
      }
      const st = e.target.closest("[data-stage]");
      if (!st) return;
      if (e.target.closest("[data-add-who]")) {
        st.querySelector("[data-whos]").insertAdjacentHTML("beforeend", whoBlock(null, "who", ruleLevel()));
        wireWho(st.querySelector("[data-whos]").lastElementChild, ruleLevel);
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
      refresh();
    });

    // Errors show at the field they are about.
    const showErrors = (errs) => {
      el.querySelectorAll(".rw-error").forEach((x) => x.remove());
      el.querySelectorAll(".is-invalid").forEach((x) => x.classList.remove("is-invalid"));
      Object.entries(errs || {}).forEach(([k, msgs]) => {
        const msg = `<div class="rw-error"><i class="ri-error-warning-line"></i>${esc([].concat(msgs)[0])}</div>`;
        const m = /^stages\.(\d+)/.exec(k);
        if (m) return box.querySelectorAll("[data-stage]")[Number(m[1])]?.insertAdjacentHTML("beforeend", msg);
        const at = { name: "#rlName", amount_min: ".rw-range", amount_max: ".rw-range", subject_type: ".rw-cards", level: ".rw-cards", reach: ".rw-cards" }[k];
        if (k === "name") el.querySelector("#rlName").classList.add("is-invalid");
        at ? el.querySelector(at).insertAdjacentHTML("afterend", msg) : el.querySelector(".rw-form").insertAdjacentHTML("afterbegin", msg);
      });
      el.querySelector(".rw-error")?.scrollIntoView({ behavior: "smooth", block: "center" });
    };

    bootstrap.Modal.getOrCreateInstance(el).show();
    el.querySelector("#rlGo").addEventListener("click", async (e) => {
      const b = e.currentTarget;
      const stages = [...box.querySelectorAll("[data-stage]")].map((st) => {
        const f = (k) => st.querySelector(`[data-f="${k}"]`).value;
        const type = segVal(st, "type") || "single";
        return {
          name: f("name").trim(), type, quorum: type === "quorum" ? Number(f("quorum")) : null, on_empty: f("on_empty"), on_reject: f("on_reject"),
          sla_hours: Number(f("sla_hours")) || null, escalate_after_hours: Number(f("escalate_after_hours")) || null,
          escalate_to: readWho(st.querySelector('[data-who="esc"]')),
          steps: [...st.querySelectorAll('[data-whos] [data-who="who"]')].map(readWho).filter(Boolean),
        };
      });
      const body = {
        name: el.querySelector("#rlName").value.trim(),
        subject_type: el.querySelector('input[name="rlSubject"]:checked')?.value || "*",
        amount_min: readMoney(minEl),
        amount_max: readMoney(maxEl),
        is_active: el.querySelector("#rlActive").checked,
        stages,
      };
      if (CTX.level === "diocese") body.level = segVal(el, "level") || null;
      if (CTX.level === "region") Object.assign(body, segVal(el, "for") === "churches" ? { reach: "below", level: "church" } : { reach: "self" });
      UI.setButtonLoading(b, "Saving...");
      const res = await API.saveWorkflow(w?.id, body);
      UI.restoreButton(b);
      if (!res.ok) {
        showErrors(res.errors);
        return Toast.error(Object.values(res.errors || {}).flat()[0] || res.message);
      }
      b.innerHTML = '<i class="ri-check-double-line me-1"></i>Saved';
      Toast.success(res.message);
      setTimeout(() => bootstrap.Modal.getInstance(el)?.hide(), 450);
      load();
    });
  }

  // ------------------------------------------------------------ loading and the tabs

  function showTab() {
    document.querySelectorAll("[data-ap-tab]").forEach((b) => {
      b.classList.toggle("is-on", b.dataset.apTab === tab);
      b.setAttribute("aria-pressed", b.dataset.apTab === tab);
    });
    $("apOverview").hidden = tab !== "overview";
    $("apRules").hidden = tab !== "rules";
    keepUrl();
  }

  // The amount map is the overview's map when that is for the same document (or "all", which draws vouchers).
  const lanesNeedOwn = () => !(subject === doc || (subject === "all" && doc === "payment_voucher"));

  async function loadDoc() {
    const [d, t] = await Promise.all([lanesNeedOwn() ? API.ruleMap(doc) : { ok: true, data: map }, tried ? API.ruleTry(doc, tried.amount) : null]);
    if (!d.ok) return Toast.error(d.message);
    lanes = d.data;
    if (t) tried = t.ok ? { amount: tried.amount, answer: t.data } : null;
    assistant();
    rules();
  }

  async function load() {
    $("apHero").innerHTML = `<div class="ap-loading">${A.empty("ri-loader-4-line", "Reading the approvals…", "")}</div>`;
    const [m, l, w, d] = await Promise.all([API.ruleMap(subject), API.ruleLive(days, subject === "all" ? null : subject), API.workflows(), lanesNeedOwn() ? API.ruleMap(doc) : null]);
    const bad = [m, l, w, d].find((r) => r && !r.ok);
    if (bad) {
      $("apHero").innerHTML = A.errorBox(bad.message);
      return;
    }
    map = m.data;
    lanes = d ? d.data : map;
    live = l.data;
    meta = w.data;
    A.placeLine($("accPlaceLine"), meta.place);
    $("apBrandName").textContent = map.place.name;
    if (tried) {
      const r = await API.ruleTry(doc, tried.amount);
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
      const db = e.target.closest("[data-doc]");
      if (db) {
        doc = db.dataset.doc;
        keepUrl();
        return loadDoc();
      }
      const tv = e.target.closest("[data-try]");
      if (tv) return runTry(Number(tv.dataset.try));
      if (e.target.closest("[data-explain]")) return (explain = true), (tried = null), assistant();
      if (e.target.closest("[data-clear]")) return (explain = false), (tried = null), assistant(), tab === "rules" && rules();
      const ed = e.target.closest("[data-edit]");
      if (ed && !ed.closest("#ruleView")) return editor(meta.items.find((x) => x.id === Number(ed.dataset.edit)));
      const del = e.target.closest("[data-del]");
      if (del) {
        if (!confirm("Remove this rule? Requests already waiting keep it.")) return;
        const r = await API.deleteWorkflow(Number(del.dataset.del));
        return r.ok ? (Toast.success(r.message), load()) : Toast.error(r.message);
      }
      const op = e.target.closest("[data-open]");
      if (op && !e.target.closest(".modal")) return openRule(Number(op.dataset.open));
    });
    document.addEventListener("keydown", (e) => {
      if ((e.key === "Enter" || e.key === " ") && e.target.matches(".ap-rule[data-open]")) {
        e.preventDefault();
        openRule(Number(e.target.dataset.open));
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
