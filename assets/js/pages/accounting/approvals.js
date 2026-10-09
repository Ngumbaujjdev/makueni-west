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

  const ICON = { requisition: "ri-hand-coin-line", payment_voucher: "ri-file-list-3-line" };
  const STATUS = { pending: ["warning", "Waiting"], approved: ["success", "Approved"], rejected: ["danger", "Rejected"], returned: ["danger", "Sent back"], cancelled: ["secondary", "Withdrawn"] };
  const pill = (s) => `<span class="badge bg-${STATUS[s][0]} ${A.textOn(STATUS[s][0])}">${STATUS[s][1]}</span>`;

  function row(r) {
    const s = r.subject;
    const blocked = r.stage?.status === "blocked";
    const where = r.status === "pending" ? (blocked ? `<span class="text-danger fw-semibold">Stuck at ${esc(r.stage.name)}</span>` : `${esc(r.stage?.name || "")}${r.waiting_on.length ? ` · ${esc(r.waiting_on.join(", "))}` : ""}`) : `${esc(r.workflow || "")}`;
    return `<div class="acc-ap-row" data-request="${r.id}" role="button" tabindex="0">
      <span class="avatar avatar-md avatar-rounded bg-${r.status === "pending" ? (blocked ? "danger" : "warning") : STATUS[r.status][0]} ${A.textOn(r.status === "pending" ? "warning" : STATUS[r.status][0])}"><i class="${ICON[s.type] || "ri-shield-check-line"}"></i></span>
      <div class="flex-fill min-w-0">
        <div class="d-flex flex-wrap gap-2 align-items-center"><strong>${esc(s.label)} ${esc(s.number)}</strong>${pill(r.status)}${r.due_at ? `<span class="soft-chip soft-${new Date(r.due_at) < new Date() ? "danger" : "warning"}"><i class="ri-time-line"></i>Due ${A.day(r.due_at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}</span>` : ""}</div>
        <div class="text-truncate">${esc(s.title || "")}</div>
        <div class="acc-sub">${esc(r.place.name)} · asked by ${esc(r.requested_by || "")} · ${where}</div>
      </div>
      <div class="text-end"><strong class="fs-15">${A.money(s.amount)}</strong>${r.my_assignment ? '<div><span class="badge bg-success mt-1">Your turn</span></div>' : ""}</div>
    </div>`;
  }

  async function load() {
    document.querySelectorAll("#apTabs [data-tab]").forEach((b) => {
      b.classList.toggle("active", b.dataset.tab === tab);
      b.setAttribute("aria-selected", b.dataset.tab === tab);
    });
    $("apList").innerHTML = `<div class="p-4">${UI.renderTableLoading ? '<div class="placeholder-glow"><span class="placeholder col-12 mb-2"></span><span class="placeholder col-10 mb-2"></span><span class="placeholder col-8"></span></div>' : ""}</div>`;
    if (tab === "delegations") return delegations();
    const res = await API.approvals(tab);
    if (!res.ok) {
      $("apList").innerHTML = `<div class="p-3">${A.errorBox(res.message)}</div>`;
      return;
    }
    $("apWaitingFigure").textContent = res.data.counts.waiting ? `${res.data.counts.waiting} for you` : "Nothing waiting";
    $("apMineFigure").textContent = res.data.counts.mine ? `${res.data.counts.mine} waiting` : "None waiting";
    const items = res.data.items;
    const empty = {
      waiting: ["ri-checkbox-circle-line", "Nothing waits for you", "Requisitions and payments that need your approval show here - you also get an SMS."],
      mine: ["ri-hand-coin-line", "You haven't asked for anything", "Ask for money on the Requisitions page; you'll see here where it stands."],
      decided: ["ri-shield-check-line", "Nothing decided yet", "What you approve, send back or reject shows here."],
    }[tab];
    $("apList").innerHTML = items.length ? `<div class="acc-ap-list">${items.map(row).join("")}</div>` : A.empty(...empty);
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
      const r = e.target.closest("[data-request]");
      if (r) view(Number(r.dataset.request));
    });
    $("apList").addEventListener("keydown", (e) => {
      const r = e.target.closest("[data-request]");
      if (r && (e.key === "Enter" || e.key === " ")) {
        e.preventDefault();
        view(Number(r.dataset.request));
      }
    });
    $("delegateBtn").addEventListener("click", async () => {
      const res = await API.delegations();
      if (!res.ok) return Toast.error(res.message);
      if (!res.data.people.length) return Toast.error("There's nobody at your place to hand over to.");
      delegateWindow(res.data.people);
    });
    A.placeLine($("accPlaceLine"), null);
    const open = new URLSearchParams(window.location.search).get("request");
    if (open) view(Number(open));
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
