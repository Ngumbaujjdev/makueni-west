/**
 * SETTINGS - Communication > Messages (S6c): every email and SMS sent for
 * this place (a region also sees its churches', the diocese everything),
 * with KPI cards, the shared filter bar and a preview window. Emails show
 * in a sandboxed frame, SMS as a phone bubble; secrets were masked before
 * they were stored. Filtering and paging happen in place.
 */
const SettingsMessages = (function () {
  "use strict";

  const UI = DemographicsUI;
  const F = window.SettingsFields;
  const esc = F.esc;

  const STATUS = { sent: ["Sent", "success"], failed: ["Failed", "danger"], logged: ["Log only", "warning"] };
  const VIA = { diocese: ["Diocese", "primary"], own: ["Own account", "success"], system: ["System", "secondary"] };
  const KINDS = { test: "Test", sign_in_details: "Sign-in details", account: "Account email", resend: "Sent again" };

  let host = null;
  let data = null;

  const when = (iso) => {
    const d = new Date(iso);
    return { day: d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }), time: d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }), iso: iso.slice(0, 10) };
  };
  const statusPill = (s) => UI.pill(STATUS[s]?.[0] || s, STATUS[s]?.[1] || "secondary");
  const viaChip = (v) => `<span class="soft-chip soft-${VIA[v]?.[1] || "secondary"}">${VIA[v]?.[0] || esc(v)}</span>`;

  function kpis(rows) {
    const ok = rows.filter((r) => r.status !== "failed");
    const series = UI.monthlySeries(ok, { dateField: "at", months: 6 });
    const thisMonth = series.data[series.data.length - 1];
    const lastMonth = series.data[series.data.length - 2];
    const start = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    const month = rows.filter((r) => new Date(r.at) >= start);
    const failed = month.filter((r) => r.status === "failed").length;
    const emails = month.filter((r) => r.channel === "email").length;
    const latest = rows[0];
    return [
      UI.renderSparkCard({ icon: "ri-send-plane-line", label: "Sent this month", value: String(thisMonth), color: "primary", delta: UI.periodDelta(thisMonth, lastMonth), series }),
      UI.renderSparkCard({ icon: "ri-error-warning-line", label: "Failed this month", value: String(failed), color: failed ? "danger" : "success", sub: failed ? "Open one to see why" : "Nothing failed" }),
      UI.renderSparkCard({ icon: "ri-mail-line", label: "Emails · SMS", value: `${emails} · ${month.length - emails}`, color: "purple", sub: "This month" }),
      UI.renderSparkCard({ icon: "ri-time-line", label: "Last sent", value: latest ? when(latest.at).day : "Nothing yet", color: "pink", sub: latest ? `${latest.channel === "email" ? "Email" : "SMS"} to ${latest.to}` : "Messages show here once sent" }),
    ];
  }

  function row(r, showPlace) {
    const t = when(r.at);
    const icon = r.channel === "email" ? "ri-mail-line" : "ri-message-3-line";
    return `
      <tr data-row-id="${r.id}" data-date="${t.iso}">
        <td class="text-nowrap" data-order="${esc(r.at)}" data-search="${esc(t.day)}"><div class="fw-semibold">${esc(t.day)}</div><div class="fs-12">${esc(t.time)}</div></td>
        <td data-search="${r.channel === "email" ? "Email" : "SMS"}">${UI.pill(r.channel === "email" ? "Email" : "SMS", r.channel === "email" ? "primary" : "pink", icon)}</td>
        <td>
          <div class="fw-semibold text-truncate msg-preview">${esc(r.preview || "(no text)")}</div>
          <div class="fs-12">To ${esc(r.to)}${r.kind && KINDS[r.kind] ? ` · ${KINDS[r.kind]}` : ""}${r.by ? ` · by ${esc(r.by)}` : ""}</div>
        </td>
        ${showPlace ? `<td data-search="${esc(r.place.name)}"><span class="soft-chip soft-${r.place.type === "system" ? "secondary" : "primary"}">${esc(r.place.name)}</span></td>` : ""}
        <td data-search="${esc(VIA[r.via]?.[0] || r.via)}">${viaChip(r.via)}</td>
        <td data-search="${esc(STATUS[r.status]?.[0] || r.status)}">${statusPill(r.status)}</td>
        <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-primary text-nowrap" data-preview="${r.id}"><i class="ri-eye-line me-1"></i>Preview</button></td>
      </tr>`;
  }

  function draw() {
    const rows = data.rows;
    const showPlace = data.scope !== "own";
    const cols = ["When", "Type", "Message", ...(showPlace ? ["Where"] : []), "Sent through", "Status", ""];
    host.innerHTML = `
      <div class="row">${kpis(rows)
        .map((k) => `<div class="col-xxl-3 col-md-6">${k}</div>`)
        .join("")}</div>
      ${F.card({
        id: "card-messages",
        title: "Messages",
        icon: "ri-history-line",
        colour: "pink",
        sub: `${data.scope === "all" ? "Every email and SMS sent anywhere" : data.scope === "below" ? "Sent for you and your churches" : "Every email and SMS sent for you"} - text kept for ${data.keep_days} days, secrets masked`,
        body: `
          <div id="msgFilterToolbar"></div>
          <div class="table-responsive">
            <table class="table align-middle mb-0 msg-table" id="msgTable">
              <thead><tr>${cols.map((c, i) => `<th${i === 2 ? ' class="all"' : ""}>${c}</th>`).join("")}</tr></thead>
              <tbody>${rows.length ? rows.map((r) => row(r, showPlace)).join("") : UI.renderTableEmpty(cols.length, "No messages sent yet", "ri-mail-send-line")}</tbody>
            </table>
          </div>`,
      })}`;
    UI.mountSparklines(host);

    const opts = (list, color) => list.map((l) => ({ value: l, label: l, color }));
    const filters = [
      { id: "msgTypeFilter", label: "Email and SMS", options: opts(["Email", "SMS"], "primary") },
      { id: "msgStatusFilter", label: "Any status", options: [{ value: "Sent", label: "Sent", color: "success" }, { value: "Failed", label: "Failed", color: "danger" }, { value: "Log only", label: "Log only", color: "warning" }] },
      ...(showPlace ? [{ id: "msgPlaceFilter", label: "Everywhere", options: [...(data.scope === "all" ? [{ value: "System", label: "System", color: "secondary" }] : []), ...data.places.map((p) => ({ value: p.label, label: p.label, color: "primary" }))] }] : []),
    ];
    UI.renderFilterToolbar("msgFilterToolbar", { searchPlaceholder: "Search messages…", filters, dateRange: true });
    const table = rows.length ? UI.initListDataTable("msgTable", { order: [[0, "desc"]], nonSortableColumns: [cols.length - 1], hideDefaultSearch: true, noun: "messages", pageLength: 25 }) : null;
    UI.wireFilterToolbar(
      "msgFilterToolbar",
      table,
      [
        { id: "msgTypeFilter", columnIndex: 1, exact: true },
        { id: "msgStatusFilter", columnIndex: showPlace ? 5 : 4, exact: true },
        ...(showPlace ? [{ id: "msgPlaceFilter", columnIndex: 3, exact: true }] : []),
      ],
      { noun: "messages", urlSync: false },
    );
    host.querySelector("#msgTable").addEventListener("click", (e) => {
      const b = e.target.closest("[data-preview]");
      if (b) preview(Number(b.dataset.preview));
    });
  }

  // ---------------------------------------------------------------- preview

  function modal() {
    let el = document.getElementById("msgModal");
    if (el) return el;
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="modal fade app-modal" id="msgModal" tabindex="-1" aria-labelledby="msgModalTitle">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon bg-primary" id="msgModalIcon"><i class="ri-mail-line"></i></span>
              <div class="flex-fill" style="min-width:0"><h5 class="modal-title" id="msgModalTitle">Message</h5><div class="app-modal-subtitle" id="msgModalSub">&nbsp;</div></div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="msgModalBody"></div>
            <div class="modal-footer" id="msgModalFoot"></div>
          </div>
        </div>
      </div>`,
    );
    return document.getElementById("msgModal");
  }

  async function preview(id) {
    const el = modal();
    el.querySelector("#msgModalBody").innerHTML = '<div class="py-5 text-center"><span class="spinner-border text-primary"></span></div>';
    el.querySelector("#msgModalFoot").innerHTML = '<button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>';
    bootstrap.Modal.getOrCreateInstance(el).show();
    const res = await SettingsAPI.message(id);
    if (!res.ok) {
      el.querySelector("#msgModalBody").innerHTML = `<div class="alert alert-danger mb-0">${esc(res.message)}</div>`;
      return;
    }
    const m = res.data;
    const email = m.channel === "email";
    const t = when(m.at);
    const icon = el.querySelector("#msgModalIcon");
    icon.className = `app-modal-icon bg-${email ? "primary" : "pink"}`;
    icon.innerHTML = `<i class="${email ? "ri-mail-line" : "ri-message-3-line"}"></i>`;
    el.querySelector("#msgModalTitle").textContent = email ? m.subject || "Email" : `SMS to ${m.to}`;
    el.querySelector("#msgModalSub").innerHTML = `${esc(t.day)} at ${esc(t.time)} · ${statusPill(m.status)}`;

    const facts = [
      ["From", m.from || (email ? "" : "The provider's default sender")],
      ["To", m.to],
      ...(email ? [["Reply-to", m.reply_to || "-"], ["Subject", m.subject || "-"]] : []),
      ["Sent through", VIA[m.via]?.[0] || m.via],
      ["For", m.place.name],
      ...(m.by ? [["Sent by", m.by]] : []),
      ...(KINDS[m.kind] ? [["Kind", KINDS[m.kind]]] : []),
      ...(m.provider_ref ? [["Reference", m.provider_ref]] : []),
    ];
    let content = "";
    if (!m.body) {
      content = `<div class="alert alert-primary mb-0 d-flex gap-2"><i class="ri-information-line fs-18"></i><span>${esc(m.body_note || "No copy of the text was kept.")}</span></div>`;
    } else if (email && m.body_type === "html") {
      content = `<iframe class="msg-frame" sandbox title="Email preview" srcdoc="${esc(m.body)}"></iframe>`;
    } else {
      const len = m.body.length;
      content = `
        <div class="msg-phone">
          <div class="msg-phone-sender">${esc(m.from || "SMS")}</div>
          <div class="msg-bubble">${esc(m.body)}</div>
          <div class="fs-12 mt-2">${len} characters · ${Math.max(1, Math.ceil(len / 160))} SMS</div>
        </div>`;
    }
    el.querySelector("#msgModalBody").innerHTML = `
      <div class="msg-facts">${facts.map(([k, v]) => `<div><span>${esc(k)}</span><b>${esc(v)}</b></div>`).join("")}</div>
      ${m.error ? `<div class="alert alert-danger d-flex gap-2 mt-3 mb-0"><i class="ri-error-warning-line fs-18"></i><span><b>Why it failed:</b> ${esc(m.error)}</span></div>` : ""}
      <div class="mt-3">${content}</div>`;
    if (m.can_resend) {
      el.querySelector("#msgModalFoot").insertAdjacentHTML("beforeend", '<button type="button" class="btn btn-primary" id="msgResend"><i class="ri-restart-line me-1"></i>Send again</button>');
      el.querySelector("#msgResend").addEventListener("click", async (e) => {
        const btn = e.currentTarget;
        UI.setButtonLoading(btn, "Sending…");
        const r = await SettingsAPI.resendMessage(m.id);
        UI.restoreButton(btn);
        if (!r.ok) return Toast.error(r.message);
        Toast.success(r.message);
        bootstrap.Modal.getInstance(el)?.hide();
        load();
      });
    }
  }

  async function load() {
    const res = await SettingsAPI.messages();
    if (!res.ok) {
      host.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`;
      return;
    }
    data = res.data;
    draw();
  }

  return {
    /** Draws the log into a container (Communication's last card). */
    async mount(container) {
      host = container;
      host.innerHTML = `<div class="row">${UI.skeletonCards(4, "col-xxl-3 col-md-6")}</div>`;
      await load();
    },
  };
})();

window.SettingsMessages = SettingsMessages;
