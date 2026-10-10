/**
 * ============================================================================
 * ACCOUNTING - Cash & bank (accounts.php)
 * ============================================================================
 * A card per money account (cash at hand, petty cash, our banks and M-Pesa)
 * with its balance, its cashbook and Change; then the chart of accounts -
 * the diocese's standard accounts with ours under them - by type, with what
 * each holds in our books.
 * ============================================================================
 */
(function () {
  "use strict";

  const A = AccountingUI;
  const W = AccountingWindows;
  const CTX = window.ACC_CTX;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  let petty = null;
  let type = new URLSearchParams(window.location.search).get("type") || "asset";

  function cashCards() {
    const own = !A.viewingBelow() && data.can.accounts;
    $("cashCards").innerHTML = data.cash
      .map((a) => {
        const k = A.kind(a.cash_kind);
        return `<div class="col-xxl-3 col-xl-4 col-sm-6 d-flex"><div class="card custom-card flex-fill acc-money-card${a.is_active ? "" : " is-off"}">
          <div class="card-body">
            <div class="d-flex align-items-start gap-3">${A.tile(a.cash_kind)}<div class="min-w-0 flex-fill"><a class="fw-semibold text-truncate d-block acc-card-link" href="${A.link("account.php", { id: a.id })}">${esc(a.name)}</a><div class="acc-sub">${esc(a.code)} · ${esc(k.label)}${a.bank_name ? ` · ${esc(a.bank_name)}` : ""}</div>${a.number_masked ? `<div class="acc-sub">${esc(a.number_masked)}</div>` : ""}</div>${a.is_active ? "" : '<span class="badge bg-secondary text-dark">Off</span>'}</div>
            <div class="acc-money-value${a.balance < 0 ? " text-danger" : ""}">${A.money(a.balance)}</div>
            ${a.cash_kind === "petty_cash" ? pettyLine() : ""}
            <div class="d-flex gap-2 flex-wrap">
              ${a.cash_kind === "petty_cash" && !A.viewingBelow() ? pettyButtons() : ""}
              <a class="btn btn-sm btn-primary" href="${A.link("account.php", { id: a.id })}"><i class="ri-line-chart-line me-1"></i>In and out</a>
              <a class="btn btn-sm btn-outline-primary" href="${A.link("cashbook.php", { account_id: a.id })}"><i class="ri-book-open-line me-1"></i>Cashbook</a>
              ${own && a.own ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-edit="${a.id}"><i class="ri-edit-line me-1"></i>Change</button>` : ""}
            </div>
          </div></div></div>`;
      })
      .join("");
    if (own && !data.cash.some((a) => a.cash_kind === "petty_cash")) {
      $("cashCards").insertAdjacentHTML(
        "beforeend",
        `<div class="col-xxl-3 col-xl-4 col-sm-6 d-flex"><button type="button" class="card custom-card flex-fill acc-add-card" data-petty="float"><span class="avatar avatar-md avatar-rounded bg-warning text-dark"><i class="ri-wallet-3-line"></i></span><span class="fw-semibold">Set up petty cash</span><small>A fixed float for small spends, topped up for exactly what was spent</small></button></div>`,
      );
    }
    if (own) {
      $("cashCards").insertAdjacentHTML(
        "beforeend",
        `<div class="col-xxl-3 col-xl-4 col-sm-6 d-flex"><button type="button" class="card custom-card flex-fill acc-add-card" data-add><span class="avatar avatar-md avatar-rounded bg-primary text-white"><i class="ri-add-line"></i></span><span class="fw-semibold">Add a bank or M-Pesa account</span><small>Each place keeps its own, under the diocese's chart</small></button></div>`,
      );
    }
  }

  /** The float, cash in the box, what to top up. */
  function pettyLine() {
    if (!petty) return "";
    if (petty.float === null) return '<div class="acc-float"><span>No float set</span></div>';
    return `<div class="acc-float"><span>Float ${A.money(petty.float)}${petty.custodian ? ` · kept by ${esc(petty.custodian.name)}` : ""}</span><span>${petty.pending_top_up ? `Top-up ${esc(petty.pending_top_up.number)} waiting` : petty.top_up > 0 ? `${A.money(petty.top_up)} to top up` : "Full"}</span></div>`;
  }

  function pettyButtons() {
    const c = data.can;
    return [
      c.petty ? '<button type="button" class="btn btn-sm btn-primary" data-petty="spend"><i class="ri-wallet-3-line me-1"></i>Spend</button>' : "",
      c.prepare && petty?.float !== null && petty?.top_up > 0 && !petty?.pending_top_up ? '<button type="button" class="btn btn-sm btn-outline-primary" data-petty="topup"><i class="ri-refresh-line me-1"></i>Top up</button>' : "",
      c.accounts ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-petty="float"><i class="ri-settings-3-line me-1"></i>Float</button>' : "",
    ].join("");
  }

  function chartTable() {
    const used = $("usedOnly").checked;
    const rows = data.chart.filter((a) => a.type === type && (!used || a.is_header || Math.abs(a.balance || 0) >= 0.005));
    // The counts follow "Only accounts with money", so a pill never promises rows the table hides.
    const counts = {};
    data.chart.forEach((a) => !a.is_header && (!used || Math.abs(a.balance || 0) >= 0.005) && (counts[a.type] = (counts[a.type] || 0) + 1));
    const TYPES = [
      ["asset", "Assets", "ri-safe-2-line", "primary"],
      ["liability", "Liabilities", "ri-hand-coin-line", "danger"],
      ["fund", "Funds", "ri-hand-heart-line", "warning"],
      ["income", "Income", "ri-arrow-down-circle-line", "success"],
      ["expense", "Expenses", "ri-arrow-up-circle-line", "purple"],
    ];
    $("typePills").innerHTML = `<div class="pp-pills" role="tablist">${TYPES.map(([k, l, i, c]) => `<button type="button" class="pp-pill${k === type ? " is-on" : ""}${counts[k] ? "" : " is-empty"}" style="--q: var(--${c}-rgb)" data-type="${k}" role="tab" aria-selected="${k === type}"><i class="${i}"></i>${l}<span class="pp-pill-count">${counts[k] || 0}</span></button>`).join("")}</div>`;
    // Search and sort within the kind: the header rows (groups) give way to the codes, which keep the grouping.
    A.tableKit({
      tableId: "chartTable",
      prefix: "c_",
      items: rows.filter((a) => !a.is_header),
      noun: "accounts",
      search: "Search code or name...",
      sorts: [
        { key: "code", label: "By code", order: [[0, "asc"]] },
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "big", label: "Largest balance", order: [[3, "desc"]] },
      ],
      empty: A.empty("ri-scales-3-line", used ? "Nothing in these accounts yet" : "No accounts", used ? "Switch off \"Only accounts with money\" to see them all." : ""),
      rowHtml: (a) => `<tr class="acc-row" data-id="${a.id}"><td data-order="${esc(a.code)}"><span class="${a.parent_id ? "ps-3" : ""}">${esc(a.code)}</span></td><td data-order="${esc(a.name)}"><span class="fw-semibold">${esc(a.name)}</span>${a.own ? ' <span class="soft-chip soft-primary">Ours</span>' : ""}${a.is_active ? "" : ' <span class="soft-chip soft-danger">Off</span>'}${a.description ? `<div class="acc-sub">${esc(a.description)}</div>` : ""}</td><td class="d-none d-md-table-cell">${a.cash_kind ? A.methodChip(a.cash_kind === "petty_cash" ? "cash" : a.cash_kind, A.kind(a.cash_kind).label) : `<span class="acc-sub">${esc(a.type_label)}</span>`}</td><td class="text-end" data-order="${a.balance || 0}"><strong class="${(a.balance || 0) < 0 ? "text-danger" : ""}">${a.balance ? A.money(a.balance) : '<span class="acc-sub">-</span>'}</strong></td></tr>`,
    });
  }

  async function load() {
    A.ownOnly();
    $("cashCards").innerHTML = DemographicsUI.skeletonCards(4, "col-xxl-3 col-xl-4 col-sm-6");
    const [res, pc] = await Promise.all([AccountingAPI.accounts(), AccountingAPI.petty()]);
    petty = pc.ok ? pc.data : null;
    if (!res.ok) {
      $("cashCards").innerHTML = `<div class="col-12">${A.errorBox(res.message)}</div>`;
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cashCards();
    chartTable();
  }

  function init() {
    const add = () => W.account({ onDone: load });
    $("addBtn")?.addEventListener("click", add);
    $("openingBtn")?.addEventListener("click", () => W.journal({ opening: true, onDone: load }));
    $("transferBtn")?.addEventListener("click", () => W.transfer({ onDone: load }));
    $("cashCards").addEventListener("click", (e) => {
      if (e.target.closest("[data-add]")) return add();
      const pb = e.target.closest("[data-petty]");
      if (pb) return { spend: () => W.pettySpend({ onDone: load }), topup: () => W.topUp({ onDone: load }), float: () => W.setFloat({ onDone: load }) }[pb.dataset.petty]();
      const ed = e.target.closest("[data-edit]");
      if (ed) W.account({ account: data.cash.find((a) => a.id === Number(ed.dataset.edit)), onDone: load });
    });
    $("typePills").addEventListener("click", (e) => {
      const b = e.target.closest("[data-type]");
      if (!b) return;
      type = b.dataset.type;
      const p = new URLSearchParams(window.location.search);
      type === "asset" ? p.delete("type") : p.set("type", type);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      chartTable();
    });
    $("usedOnly").addEventListener("change", chartTable);
    $("chartRows").addEventListener("click", (e) => {
      const tr = e.target.closest("tr[data-id]");
      if (tr) window.location.href = A.link("account.php", { id: tr.dataset.id });
    });
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
