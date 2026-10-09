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
  let type = new URLSearchParams(window.location.search).get("type") || "asset";

  function cashCards() {
    const own = !A.viewingBelow() && data.can.accounts;
    $("cashCards").innerHTML = data.cash
      .map((a) => {
        const k = A.kind(a.cash_kind);
        return `<div class="col-xxl-3 col-xl-4 col-sm-6 d-flex"><div class="card custom-card flex-fill acc-money-card${a.is_active ? "" : " is-off"}">
          <div class="card-body">
            <div class="d-flex align-items-start gap-3">${A.tile(a.cash_kind)}<div class="min-w-0 flex-fill"><div class="fw-semibold text-truncate">${esc(a.name)}</div><div class="acc-sub">${esc(a.code)} · ${esc(k.label)}${a.bank_name ? ` · ${esc(a.bank_name)}` : ""}</div>${a.number_masked ? `<div class="acc-sub">${esc(a.number_masked)}</div>` : ""}</div>${a.is_active ? "" : '<span class="badge bg-secondary text-dark">Off</span>'}</div>
            <div class="acc-money-value${a.balance < 0 ? " text-danger" : ""}">${A.money(a.balance)}</div>
            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-sm btn-outline-primary" href="${A.link("cashbook.php", { account_id: a.id })}"><i class="ri-book-open-line me-1"></i>Cashbook</a>
              ${own && a.own ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-edit="${a.id}"><i class="ri-edit-line me-1"></i>Change</button>` : ""}
            </div>
          </div></div></div>`;
      })
      .join("");
    if (own) {
      $("cashCards").insertAdjacentHTML(
        "beforeend",
        `<div class="col-xxl-3 col-xl-4 col-sm-6 d-flex"><button type="button" class="card custom-card flex-fill acc-add-card" data-add><span class="avatar avatar-md avatar-rounded bg-primary text-white"><i class="ri-add-line"></i></span><span class="fw-semibold">Add a bank or M-Pesa account</span><small>Each place keeps its own, under the diocese's chart</small></button></div>`,
      );
    }
  }

  function chartTable() {
    const used = $("usedOnly").checked;
    const rows = data.chart.filter((a) => a.type === type && (!used || a.is_header || Math.abs(a.balance || 0) >= 0.005));
    const counts = {};
    data.chart.forEach((a) => !a.is_header && (counts[a.type] = (counts[a.type] || 0) + 1));
    const TYPES = [
      ["asset", "Assets", "ri-safe-2-line", "primary"],
      ["liability", "Liabilities", "ri-hand-coin-line", "danger"],
      ["fund", "Funds", "ri-hand-heart-line", "warning"],
      ["income", "Income", "ri-arrow-down-circle-line", "success"],
      ["expense", "Expenses", "ri-arrow-up-circle-line", "purple"],
    ];
    $("typePills").innerHTML = `<div class="pp-pills" role="tablist">${TYPES.map(([k, l, i, c]) => `<button type="button" class="pp-pill${k === type ? " is-on" : ""}" style="--q: var(--${c}-rgb)" data-type="${k}" role="tab" aria-selected="${k === type}"><i class="${i}"></i>${l}<span class="pp-pill-count">${counts[k] || 0}</span></button>`).join("")}</div>`;
    const shown = rows.filter((a) => !a.is_header || rows.some((x) => x.parent_id === a.id) || !used);
    $("chartRows").innerHTML = shown.length
      ? shown
          .map((a) =>
            a.is_header
              ? `<tr class="acc-group-row"><td>${esc(a.code)}</td><td colspan="3">${esc(a.name)}</td></tr>`
              : `<tr${a.cash_kind ? ` class="acc-row" data-cashbook="${a.id}"` : ""}><td><span class="${a.parent_id ? "ps-3" : ""}">${esc(a.code)}</span></td><td><span class="fw-semibold">${esc(a.name)}</span>${a.own ? ' <span class="soft-chip soft-primary">Ours</span>' : ""}${a.is_active ? "" : ' <span class="soft-chip soft-danger">Off</span>'}${a.description ? `<div class="acc-sub">${esc(a.description)}</div>` : ""}</td><td class="d-none d-md-table-cell">${a.cash_kind ? `<span class="soft-chip soft-${A.kind(a.cash_kind).color}"><i class="${A.kind(a.cash_kind).icon}"></i>${esc(A.kind(a.cash_kind).label)}</span>` : `<span class="acc-sub">${esc(a.type_label)}</span>`}</td><td class="text-end"><strong class="${(a.balance || 0) < 0 ? "text-danger" : ""}">${a.balance ? A.money(a.balance) : '<span class="acc-sub">-</span>'}</strong></td></tr>`,
          )
          .join("")
      : `<tr><td colspan="4">${A.empty("ri-scales-3-line", used ? "Nothing in these accounts yet" : "No accounts", used ? "Switch off \"Only accounts with money\" to see them all." : "")}</td></tr>`;
  }

  async function load() {
    A.ownOnly();
    $("cashCards").innerHTML = DemographicsUI.skeletonCards(4, "col-xxl-3 col-xl-4 col-sm-6");
    const res = await AccountingAPI.accounts();
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
      const tr = e.target.closest("[data-cashbook]");
      if (tr) window.location.href = A.link("cashbook.php", { account_id: tr.dataset.cashbook });
    });
    A.placePicker($("accPlacePick"), load);
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
