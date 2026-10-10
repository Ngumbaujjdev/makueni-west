/**
 * ============================================================================
 * ACCOUNTING - Approvals (approvals.php, every level)
 * ============================================================================
 * Waiting for me / I asked for / I decided / Handed over. Each request opens
 * with its document (what, how much, the papers) and the timeline of its
 * stages; whoever's turn it is approves, sends back or rejects in one tap.
 * "Away? Hand over" names someone to approve in my place between two dates.
 * ?request=ID opens one straight away (the link in the bell and the SMS).
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
  let tab = new URLSearchParams(window.location.search).get("tab") || "waiting";

  const ICON = { requisition: "ri-hand-heart-line", payment_voucher: "ri-file-list-3-line", payroll_run: "ri-money-dollar-box-line" };
  const KIND = { requisition: ["success", "Requisition"], payment_voucher: ["primary", "Payment voucher"], payroll_run: ["purple", "Payroll"] };
  const STATUS = { pending: ["warning", "Waiting"], approved: ["success", "Approved"], rejected: ["danger", "Rejected"], returned: ["danger", "Sent back"], cancelled: ["secondary", "Withdrawn"] };
  const pill = (s) => `<span class="badge bg-${STATUS[s][0]} ${A.textOn(STATUS[s][0])}">${STATUS[s][1]}</span>`;
  const recordUrl = (r) => (r.record?.type ? A.link("record.php", { type: r.record.type, id: r.record.id }) : null);
  let board = null;

  /** "3 hours ago", "2 days ago". */
  function ago(iso) {
    if (!iso) return "";
    const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (mins < 1) return "just now";
    if (mins < 60) return `${mins} min ago`;
    const h = Math.round(mins / 60);
    if (h < 24) return `${h} ${h === 1 ? "hour" : "hours"} ago`;
    const d = Math.round(h / 24);
    return d < 30 ? `${d} ${d === 1 ? "day" : "days"} ago` : A.day(iso);
  }
  const hours = (h) => (h === null || h === undefined ? "-" : h < 1 ? `${Math.max(1, Math.round(h * 60))} min` : h < 48 ? `${Math.round(h * 10) / 10} h` : `${Math.round(h / 24)} days`);

  // ------------------------------------------------------------ the feed (each tab)

  /** One request, read like a line of recent activity: who asks how much for what, where it stands, and the buttons. */
  function feedItem(r, first) {
    const s = r.subject;
    const [color, kindLabel] = KIND[s.type] || ["primary", s.label];
    const mine = !!r.my_assignment;
    const blocked = r.stage?.status === "blocked";
    const url = recordUrl(r);
    const where =
      r.status === "pending"
        ? blocked
          ? `<span class="acc-feed-chip is-danger"><i class="ri-error-warning-line"></i>Stuck at ${esc(r.stage.name)}</span>`
          : `<span class="acc-feed-chip"><i class="ri-route-line"></i>${esc(r.stage?.name || "")}${r.waiting_on.length ? ` · ${esc(r.waiting_on.join(", "))}` : ""}</span>`
        : pill(r.status);
    const due = r.due_at ? `<span class="acc-feed-chip ${new Date(r.due_at) < new Date() ? "is-danger" : "is-warning"}"><i class="ri-alarm-line"></i>Due ${A.day(r.due_at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}</span>` : "";
    const acts = mine
      ? `<button type="button" class="btn btn-sm ${first ? "btn-light" : "btn-success"}" data-decide="approve" data-req="${r.id}"><i class="ri-check-line me-1"></i>Approve</button><button type="button" class="btn btn-sm ${first ? "btn-outline-dark" : "btn-outline-danger"}" data-decide="return" data-req="${r.id}"><i class="ri-arrow-go-back-line me-1"></i>Send back</button>`
      : "";
    return `<div class="acc-feed-item${first && mine ? " is-first" : ""}" data-request="${r.id}"${url ? ` data-href="${url}"` : ""} role="button" tabindex="0">
      ${A.avatar(r.requested_by || "?", "md")}
      <div class="acc-feed-body">
        <div class="acc-feed-top"><span class="acc-feed-tag bg-${first && mine ? "dark" : color} text-white"><i class="${ICON[s.type] || "ri-shield-check-line"}"></i>${first && mine ? "Do first" : esc(kindLabel)}</span><span class="acc-feed-num">${esc(s.number)}</span><span class="acc-feed-ago">${esc(ago(r.requested_at))}</span></div>
        <div class="acc-feed-line"><strong>${esc(r.requested_by || "Someone")}</strong> asks <strong class="acc-feed-amt">${A.money(s.amount)}</strong> for ${esc(s.title || kindLabel.toLowerCase())}</div>
        <div class="acc-feed-meta">${where}${due}<span class="acc-feed-chip"><i class="ri-map-pin-line"></i>${esc(r.place.name)}</span></div>
      </div>
      <div class="acc-feed-actions">${acts}<a class="btn btn-sm ${first && mine ? "btn-outline-dark" : "btn-outline-primary"}" href="${url || "#"}" data-open-view="${url ? "" : r.id}"><i class="ri-arrow-right-up-line me-1"></i>Open</a></div>
    </div>`;
  }

  // ------------------------------------------------------------ the board

  /** Who holds what: a lane per person waited on, each request a block from the day it was asked to today. */
  function lanes(open) {
    const DAYS = 7;
    const today = new Date();
    today.setHours(12, 0, 0, 0);
    const days = [...Array(DAYS)].map((_, i) => new Date(today.getTime() - (DAYS - 1 - i) * 86400000));
    const groups = {};
    open.forEach((r) => {
      const who = r.stage?.status === "blocked" || !r.waiting_on.length ? "Nobody - stuck" : r.waiting_on.join(", ");
      (groups[who] = groups[who] || []).push(r);
    });
    const people = Object.keys(groups).sort((a, b) => (a.startsWith("Nobody") ? 1 : b.startsWith("Nobody") ? -1 : groups[b].length - groups[a].length));
    if (!people.length) return A.empty("ri-checkbox-circle-line", "Nothing waits for approval here", "Requisitions, payments and payroll that need a decision show here, on the person they wait on.");
    const head = `<div class="acc-lanes-head"><div class="acc-lane-who">Waiting on</div><div class="acc-lane-days">${days.map((d, i) => `<span class="${i === DAYS - 1 ? "is-today" : ""}"><small>${d.toLocaleDateString("en-GB", { weekday: "short" })}</small><strong>${d.getDate()}</strong></span>`).join("")}</div></div>`;
    const rows = people
      .map((who) => {
        const list = groups[who];
        const stuck = who.startsWith("Nobody");
        const blocks = list
          .map((r, i) => {
            const asked = new Date(r.requested_at);
            asked.setHours(12, 0, 0, 0);
            const back = Math.round((today - asked) / 86400000);
            const start = Math.max(0, Math.min(DAYS - 2, DAYS - 1 - back)); // at least two days wide, so it reads
            const [color] = KIND[r.subject.type] || ["primary"];
            const state = stuck ? ["is-stuck", "Stuck"] : r.overdue ? ["is-overdue", "Overdue"] : r.my_assignment ? ["is-mine", "Your turn"] : ["", "Waiting"];
            return `<a class="acc-lane-block bg-${color} ${state[0]}" href="${recordUrl(r) || "#"}" style="left:calc(${(start / DAYS) * 100}% + 4px);width:calc(${((DAYS - start) / DAYS) * 100}% - 8px);top:${0.5 + i * 3.4}rem" title="${esc(`${r.subject.label} ${r.subject.number} - ${r.subject.title || ""}`)}"><span class="acc-lane-ico"><i class="${ICON[r.subject.type] || "ri-shield-check-line"}"></i></span><span class="acc-lane-text"><strong>${esc(r.subject.number)}</strong><small>${A.money(r.subject.amount)}${back > DAYS - 1 ? ` · since ${A.day(r.requested_at, { day: "numeric", month: "short" })}` : ""}</small></span><span class="acc-lane-pill"><i></i>${state[1]}</span></a>`;
          })
          .join("");
        const avatar = stuck ? '<span class="avatar avatar-sm avatar-rounded bg-danger text-white"><i class="ri-error-warning-line"></i></span>' : A.avatar(who, "sm");
        return `<div class="acc-lane"><div class="acc-lane-who">${avatar}<div class="min-w-0"><strong class="d-block text-truncate">${esc(who)}</strong><small>${list.length} waiting${list.some((r) => r.my_assignment) ? " · you" : ""}</small></div></div><div class="acc-lane-track" style="height:${list.length * 3.4 + 0.6}rem"><div class="acc-lane-cols">${days.map((_, i) => `<span class="${i === DAYS - 1 ? "is-today" : ""}"></span>`).join("")}</div><span class="acc-lane-now"></span>${blocks}</div></div>`;
      })
      .join("");
    // On a phone: the same, as a list per person.
    const small = people
      .map((who) => `<div class="acc-lanes-group"><div class="acc-lanes-group-head">${who.startsWith("Nobody") ? '<i class="ri-error-warning-line text-danger"></i>' : ""}<strong>${esc(who)}</strong><span class="soft-chip soft-warning">${groups[who].length}</span></div>${groups[who].map((r) => `<a class="acc-lanes-item" href="${recordUrl(r) || "#"}"><span class="avatar avatar-xs avatar-rounded bg-${(KIND[r.subject.type] || ["primary"])[0]} text-white"><i class="${ICON[r.subject.type] || "ri-shield-check-line"}"></i></span><span class="flex-fill min-w-0 text-truncate">${esc(r.subject.number)}</span><strong>${A.money(r.subject.amount)}</strong></a>`).join("")}</div>`)
      .join("");
    return `<div class="acc-lanes">${head}${rows}</div><div class="acc-lanes-list">${small}</div>`;
  }

  function statTiles(st) {
    const tile = (icon, color, value, label) => `<div class="acc-stat-tile"><span class="avatar avatar-md avatar-rounded bg-${color} ${A.textOn(color)}"><i class="${icon}"></i></span><div><strong>${value}</strong><small>${esc(label)}</small></div></div>`;
    return `<div class="acc-stat-grid">${tile("ri-time-line", "warning", A.num(st.waiting), st.overdue ? `Waiting · ${st.overdue} overdue` : "Waiting now")}${tile("ri-checkbox-circle-line", "success", A.num(st.approved), "Approved this month")}${tile("ri-arrow-go-back-line", "danger", A.num(st.stopped), "Sent back or rejected")}${tile("ri-timer-line", "primary", hours(st.avg_hours), "Average time to decide")}</div>`;
  }

  function activityList(list) {
    if (!list.length) return '<p class="acc-muted-line mb-0">Nothing has happened yet.</p>';
    return `<ul class="acc-activity">${list
      .slice(0, 8)
      .map((e) => {
        const link = e.record?.number ? (e.record.type ? `<a href="${A.link("record.php", { type: e.record.type, id: e.record.id })}">${esc(e.record.number)}</a>` : esc(e.record.number)) : "";
        const plain = e.text.replace(/\s*\([^)]*\)$/, ""); // the rule's name stays on the record page
        const lower = plain.charAt(0).toLowerCase() + plain.slice(1);
        const text = e.who ? `<strong>${esc(e.who)}</strong> ${/\bit\b/.test(lower) ? esc(lower).replace(/\bit\b/, link) : `${esc(lower)} ${link}`}` : `${link} - ${esc(lower)}`;
        return `<li><span class="avatar avatar-xs avatar-rounded bg-${e.tone} ${A.textOn(e.tone)}"><i class="${e.icon}"></i></span><div class="min-w-0"><div class="acc-act-text">${text}</div>${e.note ? `<div class="acc-act-note">"${esc(e.note)}"</div>` : ""}<small>${esc(ago(e.at))}</small></div></li>`;
      })
      .join("")}</ul>`;
  }

  async function loadBoard() {
    const res = await API.approvalBoard();
    if (!res.ok) {
      $("apBoard").innerHTML = A.errorBox(res.message);
      return;
    }
    board = res.data;
    const st = board.stats;
    $("apBoardChips").innerHTML = `<span class="soft-chip soft-warning"><i class="ri-time-line"></i>${st.waiting} waiting</span>${st.mine ? `<span class="badge bg-warning text-dark"><i class="ri-flashlight-line me-1"></i>${st.mine} on you</span>` : ""}${st.overdue ? `<span class="badge bg-danger"><i class="ri-alarm-warning-line me-1"></i>${st.overdue} overdue</span>` : ""}`;
    $("apBoard").innerHTML = lanes(board.open);
    $("apStats").innerHTML = statTiles(st);
    $("apActivity").innerHTML = activityList(board.activity);
  }

  async function load() {
    document.querySelectorAll("#apTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    const T = { waiting: ["Next steps", "What waits for you, oldest first"], mine: ["What I asked for", "Where each request stands"], decided: ["What I decided", "Approved, sent back or rejected by you"], delegations: ["Handed over", "Who approves for you while you're away"] }[tab];
    $("apListTitle").textContent = T[0];
    $("apListSub").textContent = T[1];
    $("apList").innerHTML = '<div class="placeholder-glow"><span class="placeholder col-12 mb-2" style="height:4rem"></span><span class="placeholder col-12" style="height:4rem"></span></div>';
    $("apList").onclick = null;
    if (tab === "delegations") return delegations();
    const res = await API.approvals(tab);
    if (!res.ok) {
      $("apList").innerHTML = A.errorBox(res.message);
      return;
    }
    $("apWaitingFigure").textContent = res.data.counts.waiting ? `${res.data.counts.waiting} for you` : "Nothing waiting";
    $("apMineFigure").textContent = res.data.counts.mine ? `${res.data.counts.mine} waiting` : "None waiting";
    let items = res.data.items;
    if (tab === "waiting") items = [...items].sort((a, b) => String(a.requested_at).localeCompare(String(b.requested_at)));
    const empty = {
      waiting: ["ri-checkbox-circle-line", "All clear - nothing waits for you", "Requisitions, payments and payroll that need your approval show here - you also get an SMS."],
      mine: ["ri-hand-coin-line", "You haven't asked for anything", "Ask for money on the Requisitions page; you'll see here where it stands."],
      decided: ["ri-shield-check-line", "Nothing decided yet", "What you approve, send back or reject shows here."],
    }[tab];
    $("apList").innerHTML = items.length ? `<div class="acc-feed">${items.map((r, i) => feedItem(r, tab === "waiting" && i === 0)).join("")}</div>` : A.empty(...empty);
  }

  /** Approve (with an optional note) or send back, straight from the list. */
  function quickDecide(id, decision) {
    const go = async (comment) => {
      const out = await API.decideApproval(id, decision, comment);
      if (out.ok) setTimeout(() => (load(), loadBoard()), 300);
      return out;
    };
    if (decision === "approve") {
      const el = K.confirmWindow({
        title: "Approve it",
        subtitle: "It moves to the next step - or can be paid if this was the last",
        icon: "ri-check-line",
        go: '<i class="ri-check-line me-1"></i>Approve',
        body: K.parts([{ icon: "ri-chat-3-line", title: "A note (optional)", body: '<textarea class="form-control" id="qdNote" rows="2" maxlength="500" placeholder="e.g. Pay by Friday"></textarea>' }]),
        run: () => go(document.getElementById("qdNote").value.trim() || null),
      });
      return el;
    }
    AccountingWindows.reasonWindow({ title: "Send it back", subtitle: "Say what needs changing - whoever asked can fix it and send it again", go: "Send back", placeholder: "e.g. Attach the invoice", run: (c) => go(c) });
  }

  // ------------------------------------------------------------ one request

  async function view(id) {
    const res = await API.approval(id);
    if (!res.ok) return Toast.error(res.message);
    const r = res.data;
    const s = r.subject;
    document.getElementById("apWindow")?.remove();
    const facts = `<div class="acc-facts">${r.facts.map(([k, v]) => `<div><span>${esc(k)}</span><strong>${esc(v)}</strong></div>`).join("")}<div><span>From</span><strong>${esc(r.place.name)}</strong></div></div>`;
    const lines = r.lines.length ? `<div class="table-responsive mt-2"><table class="table acc-lines-table mb-0"><tbody>${r.lines.map(([l, a]) => `<tr><td>${esc(l)}</td><td class="text-end">${A.amount(a)}</td></tr>`).join("")}</tbody><tfoot><tr><th>Total</th><th class="text-end">${A.amount(s.amount)}</th></tr></tfoot></table></div>` : "";
    const files = r.files.length ? `<div class="acc-files mt-2">${r.files.map((f) => `<div class="acc-file"><i class="${f.mime === "application/pdf" ? "ri-file-pdf-line text-danger" : "ri-image-line text-primary"}"></i><button type="button" class="btn btn-link p-0 text-start flex-fill" data-file="${f.id}">${esc(f.name)}</button></div>`).join("")}</div>` : '<p class="acc-muted-line mb-0 mt-2">No papers attached.</p>';
    const decide = r.can.decide
      ? `<section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-chat-3-line"></i>Your decision<small>A comment is needed to send back or reject</small></div><textarea class="form-control" id="apComment" rows="2" maxlength="500" placeholder="Comment (optional when approving)"></textarea></section>`
      : "";
    const foot = [
      r.can.cancel ? '<button type="button" class="btn btn-outline-danger me-auto" data-act="cancel"><i class="ri-close-circle-line me-1"></i>Withdraw</button>' : "",
      r.can.retry ? '<button type="button" class="btn btn-outline-primary me-auto" data-act="retry"><i class="ri-refresh-line me-1"></i>Try again</button>' : "",
      r.can.decide ? '<button type="button" class="btn btn-outline-danger" data-act="reject"><i class="ri-close-line me-1"></i>Reject</button><button type="button" class="btn btn-outline-warning" data-act="return"><i class="ri-arrow-go-back-line me-1"></i>Send back</button><button type="button" class="btn btn-success" data-act="approve"><i class="ri-check-line me-1"></i>Approve</button>' : "",
      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>',
    ].join("");
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal acc-modal" id="apWindow" tabindex="-1" aria-labelledby="apWindowTitle"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><span class="app-modal-icon"><i class="${ICON[s.type] || "ri-shield-check-line"}"></i></span><div class="flex-fill min-w-0"><h5 class="modal-title" id="apWindowTitle">${esc(s.label)} ${esc(s.number)}</h5><div class="app-modal-subtitle">${A.money(s.amount)} · ${esc(r.place.name)} · ${esc(r.status_label)}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-file-text-line"></i>What is asked</div>${facts}${lines}${files}</section>
          <section class="app-modal-part"><div class="app-modal-part-head"><i class="ri-route-line"></i>Approval<small>${esc(r.workflow || "")}</small></div>${A.approvalTimeline(r)}</section>
          ${decide}
        </div>
        <div class="modal-footer" id="apFoot">${foot}</div>
      </div></div></div>`,
    );
    const el = document.getElementById("apWindow");
    el.addEventListener("hidden.bs.modal", () => el.remove());
    bootstrap.Modal.getOrCreateInstance(el).show();
    el.addEventListener("click", async (e) => {
      const f = e.target.closest("[data-file]");
      if (f) {
        const w = window.open("", "_blank");
        const u = await API.approvalFileUrl(r.id, Number(f.dataset.file));
        u ? (w.location = u) : (w.close(), Toast.error("That file could not be opened."));
      }
    });
    el.querySelector("#apFoot").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-act]");
      if (!b) return;
      const act = b.dataset.act;
      const comment = el.querySelector("#apComment")?.value.trim() || null;
      if ((act === "reject" || act === "return") && !comment) {
        el.querySelector("#apComment").classList.add("is-invalid");
        return Toast.error(act === "reject" ? "Say why it is rejected." : "Say what needs changing.");
      }
      if (act === "cancel" && !confirm("Withdraw this request? It will no longer wait for approval.")) return;
      UI.setButtonLoading(b, "...");
      const out = act === "cancel" ? await API.cancelApproval(r.id) : act === "retry" ? await API.retryApproval(r.id) : await API.decideApproval(r.id, act, comment);
      UI.restoreButton(b);
      if (!out.ok) return Toast.error(out.message);
      Toast.success(out.message);
      bootstrap.Modal.getInstance(el)?.hide();
      load();
    });
  }

  // ------------------------------------------------------------ delegations

  async function delegations() {
    const res = await API.delegations();
    if (!res.ok) {
      $("apList").innerHTML = `<div class="p-3">${A.errorBox(res.message)}</div>`;
      return;
    }
    const items = res.data.items;
    $("apDelegFigure").textContent = items.some((d) => d.live) ? "Someone approves for you now" : "Nobody";
    const kinds = { "": "Everything", requisition: "Requisitions", payment_voucher: "Payment vouchers" };
    $("apList").innerHTML = items.length
      ? `<div class="acc-ap-list">${items
          .map(
            (d) => `<div class="acc-ap-row"><span class="avatar avatar-md avatar-rounded bg-${d.live ? "success" : "secondary"} text-white"><i class="ri-user-shared-line"></i></span><div class="flex-fill min-w-0"><strong>${esc(d.delegate)}</strong> approves for you<div class="acc-sub">${A.day(d.starts_at)} to ${A.day(d.ends_at)} · ${esc(kinds[d.subject_type || ""])}${d.reason ? ` · ${esc(d.reason)}` : ""}</div></div>${d.live ? '<span class="badge bg-success">Now</span>' : ""}${d.is_active ? `<button type="button" class="btn btn-sm btn-outline-danger" data-stop="${d.id}">Stop</button>` : '<span class="soft-chip soft-primary">Stopped</span>'}</div>`,
          )
          .join("")}</div>`
      : A.empty("ri-user-shared-line", "You haven't handed over", "Going away? Name someone to approve for you between two dates - nothing waits for you while you're gone.", '<button type="button" class="btn btn-primary" data-first-delegate><i class="ri-user-shared-line me-1"></i>Hand over</button>');
    $("apList").querySelector("[data-first-delegate]")?.addEventListener("click", () => delegateWindow(res.data.people));
    $("apList").onclick = async (e) => {
      const st = e.target.closest("[data-stop]");
      if (!st) return;
      const out = await API.undelegate(Number(st.dataset.stop));
      out.ok ? (Toast.success(out.message), delegations()) : Toast.error(out.message);
    };
  }

  function delegateWindow(people) {
    const today = new Date().toISOString().slice(0, 10);
    const el = K.confirmWindow({
      title: "Hand over while you're away",
      subtitle: "They approve in your place between these dates - you still see everything",
      icon: "ri-user-shared-line",
      go: '<i class="ri-check-line me-1"></i>Hand over',
      body: K.parts([
        { icon: "ri-user-line", title: "Who approves for you", body: `<select class="form-select" id="dgWho">${people.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join("")}</select>` },
        { icon: "ri-calendar-line", title: "From - to", body: `<div class="row g-2"><div class="col-6"><input type="date" class="form-control" id="dgFrom" value="${today}" min="${today}"></div><div class="col-6"><input type="date" class="form-control" id="dgTo" value="${today}" min="${today}"></div></div>` },
        { icon: "ri-file-list-3-line", title: "For", body: `<div class="mw-days" role="radiogroup"><label><input type="radio" name="dgKind" value="" checked><span>Everything</span></label><label><input type="radio" name="dgKind" value="requisition"><span>Requisitions</span></label><label><input type="radio" name="dgKind" value="payment_voucher"><span>Payment vouchers</span></label></div><input type="text" class="form-control mt-2" id="dgWhy" maxlength="255" placeholder="Why (optional), e.g. Annual leave">` },
      ]),
      run: async () => {
        const res = await API.delegate({ delegate_id: Number(document.getElementById("dgWho").value), starts_at: document.getElementById("dgFrom").value, ends_at: document.getElementById("dgTo").value, subject_type: document.querySelector('input[name="dgKind"]:checked').value || null, reason: document.getElementById("dgWhy").value.trim() || null });
        if (res.ok) setTimeout(() => ((tab = "delegations"), load()), 300);
        return res;
      },
    });
    UI.enhanceSelect(el.querySelector("#dgWho"), { search: people.length > 8 });
    if (window.DateField) ["#dgFrom", "#dgTo"].forEach((s) => DateField.enhance(el.querySelector(s), { quick: [] }));
  }

  function init() {
    $("apTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b) return;
      tab = b.dataset.tab;
      const p = new URLSearchParams(window.location.search);
      tab === "waiting" ? p.delete("tab") : p.set("tab", tab);
      p.delete("request");
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      load();
    });
    $("apList").addEventListener("click", (e) => {
      const d = e.target.closest("[data-decide]");
      if (d) return quickDecide(Number(d.dataset.req), d.dataset.decide);
      const v = e.target.closest("[data-open-view]");
      if (v && v.dataset.openView) {
        e.preventDefault();
        return view(Number(v.dataset.openView));
      }
      if (e.target.closest("a, button")) return;
      const r = e.target.closest("[data-request]");
      if (r) r.dataset.href ? (window.location.href = r.dataset.href) : view(Number(r.dataset.request));
    });
    $("apList").addEventListener("keydown", (e) => {
      const r = e.target.closest("[data-request]");
      if (r && e.target === r && (e.key === "Enter" || e.key === " ")) {
        e.preventDefault();
        r.dataset.href ? (window.location.href = r.dataset.href) : view(Number(r.dataset.request));
      }
    });
    $("delegateBtn").addEventListener("click", async () => {
      const res = await API.delegations();
      if (!res.ok) return Toast.error(res.message);
      if (!res.data.people.length) return Toast.error("There's nobody at your place to hand over to.");
      delegateWindow(res.data.people);
    });
    A.placeLine($("accPlaceLine"), null);
    // ?request=ID (the bell and the SMS): straight to the record's own page.
    const open = new URLSearchParams(window.location.search).get("request");
    if (open)
      API.approval(Number(open)).then((res) => {
        if (res.ok && res.data.record?.type) window.location.replace(recordUrl(res.data));
        else view(Number(open));
      });
    load();
    loadBoard();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
