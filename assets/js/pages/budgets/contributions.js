/**
 * ============================================================================
 * PAGE - CONTRIBUTIONS (includes/budget/contributions.php, every level)
 * ============================================================================
 * What a place sends up for a year - the share due on what was received,
 * what was sent and what is still to send - month by month, each with a
 * status (Sent, Still to send, Late, Nothing due), and "Record what was
 * sent". A region / the diocese also sees each of its churches. ?year= and
 * ?territory_id= (a place below, view only) are kept in the URL.
 * ============================================================================
 */
const BudgetsContributions = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const params = new URLSearchParams(window.location.search);
  const territoryId = params.get("territory_id") || "";
  let year = Number(params.get("year")) || new Date().getFullYear();
  let d = null;
  let table = null;

  const STATUS = {
    sent: { label: "Sent", color: "success", icon: "ri-checkbox-circle-line" },
    pending: { label: "Still to send", color: "warning", icon: "ri-time-line" },
    late: { label: "Late", color: "danger", icon: "ri-error-warning-line" },
    none: { label: "Nothing due", color: "primary", icon: "" },
  };
  const statusPill = (s) => (s === "none" ? '<span class="soft-chip soft-primary">Nothing due</span>' : UI.pill(STATUS[s].label, STATUS[s].color, STATUS[s].icon));

  function init() {
    const now = new Date().getFullYear();
    const years = [...new Set([now - 1, now, now + 1, year])].sort((a, b) => a - b);
    document.getElementById("yearSwitchWrap").innerHTML = UI.renderSegmented("yearSwitch", years.map((y) => ({ value: y, label: String(y) })), year, { ariaLabel: "Year" });
    UI.wireSegmented("yearSwitch", (v) => {
      year = Number(v);
      const q = new URLSearchParams(window.location.search);
      q.set("year", year);
      history.replaceState(null, "", `${window.location.pathname}?${q}`);
      load();
    });
    load();
  }

  async function load() {
    document.getElementById("statCardsRow").innerHTML = UI.skeletonCards(4);
    const res = await BudgetsAPI.contributions({ year, territory_id: territoryId });
    if (!res.ok) {
      document.getElementById("statCardsRow").innerHTML = `<div class="col-12"><div class="alert alert-danger">${B.esc(res.message)}</div></div>`;
      document.getElementById("ownBody").innerHTML = "";
      return;
    }
    d = res.data;
    B.syncExport({ key: "budget.contributions", territoryId: d.place?.id, year });
    renderHeader();
    renderStats();
    renderOwn();
    renderPayTo();
    renderBelow();
  }

  function renderHeader() {
    const name = d.place?.name || "";
    document.getElementById("placeLine").textContent = `${name} · ${year}`;
    const banner = document.getElementById("viewOnlyBanner");
    banner.classList.toggle("d-none", !d.view_only);
    banner.classList.toggle("d-flex", !!d.view_only);
    if (d.view_only) {
      document.getElementById("viewOnlyText").innerHTML = `This is ${B.esc(name)}'s. Only ${B.esc(name)} can record what it sends. <a class="fw-semibold ms-1" href="${B.url("contributions.php", { year })}"><i class="ri-arrow-left-line me-1"></i>Back to our churches</a>`;
    }
  }

  function renderStats() {
    // Above a church, the cards are about the churches; a church's are its own.
    const below = d.below;
    if (below) {
      const sum = (k) => below.reduce((t, p) => t + p[k], 0);
      const late = below.filter((p) => p.late > 0).length;
      const owing = below.filter((p) => p.owed > 0).length;
      UI.renderStatCardsRow("statCardsRow", [
        { icon: "ri-hand-coin-line", label: "Due from churches", value: B.shortMoney(sum("due")), color: "primary", sub: `On ${B.shortMoney(sum("received"))} of tithes received` },
        { icon: "ri-send-plane-line", label: "Sent", value: B.shortMoney(sum("sent")), color: "success", sub: sum("due") > 0 ? `${Math.round((sum("sent") / sum("due")) * 100)}% of what is due` : "Nothing due yet" },
        { icon: "ri-time-line", label: "Still to send", value: B.shortMoney(sum("owed")), color: "warning", sub: `${owing} ${owing === 1 ? "church" : "churches"} with something to send` },
        { icon: "ri-error-warning-line", label: "Late", value: `${late}`, color: late ? "danger" : "purple", sub: late ? `${late === 1 ? "church has" : "churches have"} a month past its end` : "No church is late" },
      ]);
      return;
    }
    const t = d.totals;
    UI.renderStatCardsRow("statCardsRow", [
      { icon: "ri-arrow-down-circle-line", label: "Received", value: B.shortMoney(t.received), color: "success", sub: "On the lines the share counts" },
      { icon: "ri-hand-coin-line", label: "Due", value: B.shortMoney(t.due), color: "primary", sub: d.rows[0] ? B.esc(d.rows[0].rule) : "No share worked out yet" },
      { icon: "ri-send-plane-line", label: "Sent", value: B.shortMoney(t.sent), color: "purple", sub: t.due > 0 ? `${Math.round((t.sent / t.due) * 100)}% of what is due` : "Nothing due yet" },
      { icon: t.late ? "ri-error-warning-line" : "ri-time-line", label: "Still to send", value: B.shortMoney(t.owed), color: t.late ? "danger" : "warning", sub: t.late ? `${t.late} ${t.late === 1 ? "month is" : "months are"} late` : t.owed > 0 ? "Nothing late" : t.due > 0 ? "All sent" : "Nothing due yet" },
    ]);
  }

  function renderOwn() {
    const card = document.getElementById("ownCard");
    const rows = d.rows || [];
    // A region or the diocese with no share of its own just shows its churches.
    card.hidden = !!d.below && !rows.length;
    if (card.hidden) return;
    document.getElementById("ownTitle").textContent = d.below ? "What we send" : "Month by month";
    const t = d.totals;
    document.getElementById("ownChips").innerHTML = rows.length
      ? `<span class="soft-chip soft-primary">Due ${B.shortMoney(t.due)}</span><span class="soft-chip soft-success">Sent ${B.shortMoney(t.sent)}</span>${t.owed > 0 ? `<span class="soft-chip soft-warning">To send ${B.shortMoney(t.owed)}</span>` : ""}`
      : "";
    const body = document.getElementById("ownBody");
    if (!rows.length) {
      body.innerHTML = `<div class="list-empty py-5"><span class="list-empty-icon bg-primary text-white"><i class="ri-hand-coin-line"></i></span><div class="fw-semibold mt-2">No share worked out for ${year}</div><div class="fs-12">It shows here once a budget for ${year} is in use and money is recorded.</div></div>`;
      return;
    }
    const many = new Set(rows.map((r) => r.deduction_id)).size > 1;
    body.innerHTML = `
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead><tr><th>Period</th>${many ? "<th>Share</th>" : ""}<th class="text-end">Received</th><th class="text-end">Due</th><th class="text-end">Sent</th><th class="text-end">Still to send</th><th>Status</th><th class="text-end"></th></tr></thead>
          <tbody>${rows
            .map((r) => {
              const canSend = d.can_record && r.owed > 0 && r.budget_status === "active";
              const pct = r.due > 0 ? Math.min(100, (r.sent / r.due) * 100) : 0;
              return `
                <tr>
                  <td><a href="${B.url("budget.php", { id: r.budget_id, tab: "deductions" })}" class="fw-semibold text-reset">${B.esc(r.label)}</a></td>
                  ${many ? `<td>${B.esc(r.name)}</td>` : ""}
                  <td class="text-end">${B.amount(r.received)}</td>
                  <td class="text-end fw-semibold">${B.amount(r.due)}</td>
                  <td class="text-end">
                    <div class="fw-semibold text-success">${B.amount(r.sent)}</div>
                    ${r.due > 0 ? `<div class="budget-mini-bar"><span class="count-bar"><span class="bg-${r.owed > 0 ? "warning" : "success"}" style="width: ${pct}%"></span></span></div>` : ""}
                  </td>
                  <td class="text-end fw-bold ${r.status === "late" ? "text-danger" : ""}">${r.owed > 0 ? B.amount(r.owed) : "-"}</td>
                  <td>${statusPill(r.status)}</td>
                  <td class="text-end">${canSend ? `<button type="button" class="btn btn-sm btn-primary" data-send="${r.budget_id}" data-line="${r.line_id}"><i class="ri-send-plane-line me-1"></i>Record what was sent</button>` : ""}</td>
                </tr>`;
            })
            .join("")}</tbody>
        </table>
      </div>`;
    body.querySelectorAll("[data-send]").forEach((b) =>
      b.addEventListener("click", () => BudgetsEntryModal.open({ budgetId: Number(b.dataset.send), lineId: Number(b.dataset.line), direction: "out", onSaved: load })),
    );
  }

  /** How to send the share: each receiving place's M-Pesa and bank details (Settings > Payment details). */
  function renderPayTo() {
    const card = document.getElementById("payToCard");
    const places = d.pay_to || [];
    card.hidden = !places.length || document.getElementById("ownCard").hidden;
    if (card.hidden) return;
    const copy = (text, label) =>
      `<button type="button" class="btn btn-sm btn-icon btn-light border ms-1" data-copy="${B.esc(text)}" title="Copy ${B.esc(label)}" aria-label="Copy ${B.esc(label)}"><i class="ri-file-copy-line"></i></button>`;
    const line = (label, value, canCopy = false) =>
      value ? `<div class="d-flex align-items-center flex-wrap gap-1"><span class="fw-semibold me-1">${label}</span><span class="fw-bold">${B.esc(value)}</span>${canCopy ? copy(value, label) : ""}</div>` : "";
    document.getElementById("payToBody").innerHTML = places
      .map((p) => {
        if (!p.set) {
          return `<div class="alert alert-warning d-flex align-items-center gap-2 mb-0"><i class="ri-information-line fs-18"></i><span><b>${B.esc(p.name)}</b> hasn't added its payment details yet - ask its treasurer how to send the share.</span></div>`;
        }
        const ways = [
          p.mpesa
            ? `<div class="col-md-6"><div class="d-flex gap-3 align-items-start pay-to-way">
                <span class="avatar avatar-md bg-success text-white flex-shrink-0"><i class="ri-smartphone-line"></i></span>
                <div class="d-flex flex-column gap-1"><div class="fw-semibold">M-Pesa</div>${line(p.mpesa.type === "till" ? "Till" : "Paybill", p.mpesa.number, true)}${line("Account", p.mpesa.account, true)}</div>
              </div></div>`
            : "",
          p.bank
            ? `<div class="col-md-6"><div class="d-flex gap-3 align-items-start pay-to-way">
                <span class="avatar avatar-md bg-primary text-white flex-shrink-0"><i class="ri-bank-line"></i></span>
                <div class="d-flex flex-column gap-1"><div class="fw-semibold">${B.esc(p.bank.name || "Bank")}${p.bank.branch ? ` · ${B.esc(p.bank.branch)}` : ""}</div>${line("Account name", p.bank.account_name)}${line("Account number", p.bank.account_number, true)}</div>
              </div></div>`
            : "",
        ].join("");
        return `
          <div class="pay-to-place">
            ${places.length > 1 ? `<div class="fw-semibold fs-15 mb-2">${B.esc(p.name)}</div>` : `<div class="mb-2">To <b>${B.esc(p.name)}</b></div>`}
            <div class="row g-3">${ways}</div>
            ${p.note ? `<div class="soft-chip soft-primary mt-3 d-inline-flex align-items-start gap-1 text-wrap"><i class="ri-chat-quote-line"></i><span>${B.esc(p.note)}</span></div>` : ""}
          </div>`;
      })
      .join("");
    document.querySelectorAll("#payToBody [data-copy]").forEach((btn) =>
      btn.addEventListener("click", async () => {
        try {
          await navigator.clipboard.writeText(btn.dataset.copy);
          btn.innerHTML = '<i class="ri-check-line"></i>';
          setTimeout(() => (btn.innerHTML = '<i class="ri-file-copy-line"></i>'), 1500);
        } catch (e) {
          Toast.error("Couldn't copy - select it and copy instead.");
        }
      }),
    );
  }

  function renderBelow() {
    const card = document.getElementById("belowCard");
    card.hidden = !d.below;
    if (!d.below) return;
    const isDiocese = d.place?.type === "diocese";
    if (table) {
      table.destroy();
      table = null;
    }
    const groups = [...new Set(d.below.map((p) => p.group).filter(Boolean))].sort();
    document.getElementById("groupHead").textContent = isDiocese ? "Region" : "Subregion";
    document.getElementById("belowTableBody").innerHTML = d.below
      .map(
        (p) => `
        <tr>
          <td data-search="${B.esc(p.name)}"><a href="${B.url("contributions.php", { territory_id: p.id, year })}" class="fw-semibold text-reset">${B.esc(p.name)}</a></td>
          <td data-search="${B.esc(p.group || "-")}">${p.group ? `<span class="soft-chip soft-${UI.colorFor(p.group)}">${B.esc(p.group)}</span>` : "-"}</td>
          <td class="text-end" data-order="${p.due}">${B.amount(p.due)}</td>
          <td class="text-end text-success fw-semibold" data-order="${p.sent}">${B.amount(p.sent)}</td>
          <td class="text-end fw-bold ${p.late ? "text-danger" : ""}" data-order="${p.owed}">${p.owed > 0 ? B.amount(p.owed) : "-"}${p.late ? `<div class="fs-12">${p.late} late</div>` : ""}</td>
          <td data-search="${STATUS[p.status].label}">${statusPill(p.status)}</td>
          <td class="text-end"><a href="${B.url("contributions.php", { territory_id: p.id, year })}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
        </tr>`,
      )
      .join("");
    const filters = [{ id: "statusFilter", label: "All statuses", options: Object.values(STATUS).map((s) => ({ value: s.label, label: s.label })) }];
    if (groups.length > 1) filters.unshift({ id: "groupFilter", label: isDiocese ? "All regions" : "All subregions", options: groups.map((g) => ({ value: g, label: g })) });
    UI.renderFilterToolbar("filterToolbar", { searchPlaceholder: "Search churches...", filters });
    table = UI.initListDataTable("belowTable", { order: [[4, "desc"]], nonSortableColumns: [6], hideDefaultSearch: true, noun: "churches", pageLength: 25 });
    if (table) table.column(1).visible(groups.length > 0);
    UI.wireFilterToolbar("filterToolbar", table, [...(groups.length > 1 ? [{ id: "groupFilter", columnIndex: 1, exact: true }] : []), { id: "statusFilter", columnIndex: 5, exact: true }], { noun: "churches" });
  }

  return { init };
})();

window.BudgetsContributions = BudgetsContributions;
