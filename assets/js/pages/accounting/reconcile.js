/**
 * ============================================================================
 * ACCOUNTING - Reconcile one bank or M-Pesa account (reconcile.php)
 * ============================================================================
 * The standard statement, worked live:
 *   balance per statement + deposits in transit - unpresented payments
 *   = balance per cashbook  ->  difference 0.00
 * Tick the book lines that are on the statement, or import the statement
 * (an M-Pesa portal CSV is recognised; any bank CSV gets a "which column is
 * what" step, remembered) and let it match. Add to the books what only the
 * bank knew. Submit at a zero difference; someone else signs it off.
 *   ?account_id= starts (or carries on) one;  ?id= opens one.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const W = AccountingWindows;
  const API = AccountingAPI;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let rec = null;
  let bookFilter = "all";

  const setUrl = (id) => {
    const p = new URLSearchParams(window.location.search);
    p.delete("account_id");
    p.set("id", id);
    history.replaceState(null, "", `${window.location.pathname}?${p}`);
  };

  // ------------------------------------------------------------ start

  async function startCard(accountId) {
    const o = await A.options();
    const acc = o?.cash.find((a) => String(a.id) === String(accountId));
    const today = new Date().toISOString().slice(0, 10);
    const lastMonthEnd = (() => {
      const d = new Date();
      d.setDate(0);
      return d.toISOString().slice(0, 10);
    })();
    $("recApp").innerHTML = `<div class="card custom-card"><div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-3">${A.tile(acc?.cash_kind || "bank")}<div><div class="fs-15 fw-semibold">Reconcile ${esc(acc?.name || "the account")}</div><div class="acc-sub">Take the statement up to a date - its closing balance is what the bank or M-Pesa says you held</div></div></div>
      <div class="row g-2 align-items-end">
        <div class="col-sm-4"><label class="form-label" for="stDate">Statement up to</label><input type="date" class="form-control" id="stDate" value="${lastMonthEnd}" max="${today}"></div>
        <div class="col-sm-4"><label class="form-label" for="stBal">Closing balance on the statement</label><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end fw-semibold" id="stBal" placeholder="0.00"></div></div>
        <div class="col-sm-4"><button type="button" class="btn btn-primary w-100" id="stGo"><i class="ri-play-line me-1"></i>Start</button></div>
      </div></div></div>`;
    if (window.DateField) DateField.enhance($("stDate"), { quick: [] });
    $("stGo").addEventListener("click", async (e) => {
      UI.setButtonLoading(e.currentTarget, "Starting...");
      const res = await API.startRec({ account_id: Number(accountId), statement_date: $("stDate").value, statement_balance: Number(String($("stBal").value).replace(/[^0-9.-]/g, "")) || 0 });
      UI.restoreButton(e.currentTarget);
      if (!res.ok) return Toast.error(res.message);
      setUrl(res.data.id);
      show(res.data);
    });
  }

  // ------------------------------------------------------------ the workspace

  function strip(r) {
    const ok = Math.abs(r.difference) < 0.005;
    return `<div class="acc-recstrip${ok ? " is-ok" : ""}" id="recStrip">
      <div><span>Statement balance</span><strong>${A.amount(r.statement_balance) || "0.00"}</strong></div>
      <i class="ri-add-line"></i>
      <div><span>Deposits in transit</span><strong>${A.amount(r.in_transit) || "0.00"}</strong></div>
      <i class="ri-subtract-line"></i>
      <div><span>Unpresented payments</span><strong>${A.amount(r.unpresented) || "0.00"}</strong></div>
      <i class="ri-subtract-line"></i>
      <div><span>Balance per cashbook</span><strong>${A.amount(r.book_balance) || "0.00"}</strong></div>
      <div class="acc-recstrip-diff"><span>Difference</span><strong>${ok ? '<i class="ri-check-line"></i> 0.00' : A.money(r.difference, { sign: true })}</strong></div>
    </div>`;
  }

  function bookRows(r) {
    const edit = r.can.edit;
    const list = r.book.filter((l) => bookFilter === "all" || (bookFilter === "open" ? !l.cleared : l.cleared));
    if (!list.length) return `<tr><td colspan="5">${A.empty("ri-book-open-line", "Nothing here", bookFilter === "all" ? "No money moved in this account up to the statement date." : "Try another filter.")}</td></tr>`;
    return list
      .map(
        (l) => `<tr class="${l.cleared ? "is-cleared" : ""}">
        <td class="pp-check">${edit ? `<input type="checkbox" class="form-check-input" data-tick="${l.id}"${l.cleared ? " checked" : ""} aria-label="On the statement">` : l.cleared ? '<i class="ri-check-line text-success"></i>' : ""}</td>
        <td class="text-nowrap">${A.day(l.date, { day: "numeric", month: "short" })}</td>
        <td class="acc-details"><div class="fw-semibold text-truncate">${esc(l.party || l.details || A.doc(l.doc_type).label)}</div><div class="acc-sub text-truncate">${esc(l.number.split("/").slice(-3).join("/"))}${l.reference ? ` · ${esc(l.reference)}` : ""}${l.stale ? ' · <span class="text-danger fw-semibold">over 30 days</span>' : ""}</div></td>
        <td class="text-end text-success">${A.amount(l.in)}</td>
        <td class="text-end text-danger">${A.amount(l.out)}</td>
      </tr>`,
      )
      .join("");
  }

  function statementRows(r) {
    if (!r.statement.length) {
      return A.empty("ri-file-upload-line", "No statement imported", r.can.edit ? "Import the CSV to match it automatically - or just tick the book lines that are on your paper statement." : "The lines were ticked against the paper statement.", r.can.edit ? '<button type="button" class="btn btn-outline-primary btn-sm" data-import><i class="ri-upload-2-line me-1"></i>Import a statement</button>' : "");
    }
    const edit = r.can.edit;
    const chip = { matched: ["success", "Matched"], added: ["primary", "Added to the books"], ignored: ["secondary", "Left out"], unmatched: ["warning", "Not in the books"] };
    return `<div class="acc-stmt">${r.statement
      .map((s) => {
        const [c, l] = chip[s.status];
        const amt = s.in ? `<strong class="text-success">+${A.amount(s.in)}</strong>` : `<strong class="text-danger">-${A.amount(s.out)}</strong>`;
        const acts = !edit
          ? ""
          : s.status === "unmatched"
            ? `<button type="button" class="btn btn-sm btn-outline-primary" data-add="${s.id}">Add to books</button><button type="button" class="btn btn-sm btn-outline-secondary" data-match="${s.id}">Match</button><button type="button" class="btn btn-sm btn-link" data-ignore="${s.id}">Leave out</button>`
            : s.status === "matched"
              ? `<button type="button" class="btn btn-sm btn-link" data-unmatch="${s.id}">Unmatch</button>`
              : s.status === "ignored"
                ? `<button type="button" class="btn btn-sm btn-link" data-unignore="${s.id}">Undo</button>`
                : "";
        return `<div class="acc-stmt-row is-${s.status}"><div class="acc-stmt-main"><div class="d-flex justify-content-between gap-2"><span class="fw-semibold text-truncate">${esc(s.description || s.reference || "-")}</span>${amt}</div><div class="acc-sub">${A.day(s.date)}${s.reference ? ` · ${esc(s.reference)}` : ""} · <span class="soft-chip soft-${c === "secondary" ? "primary" : c}">${l}</span></div></div>${acts ? `<div class="acc-stmt-acts">${acts}</div>` : ""}</div>`;
      })
      .join("")}</div>`;
  }

  function show(r) {
    rec = r;
    A.placeLine($("accPlaceLine"), r.place);
    const edit = r.can.edit;
    const counts = { all: r.book.length, open: r.book.filter((l) => !l.cleared).length, cleared: r.book.filter((l) => l.cleared).length };
    const alert =
      r.status === "returned" ? `<div class="alert alert-danger mb-3"><strong>Sent back:</strong> ${esc(r.return_reason)}</div>`
      : r.status === "submitted" ? `<div class="alert alert-warning mb-3"><i class="ri-time-line me-1"></i>Submitted by ${esc(r.prepared_by)} - waiting for someone else to sign it off.${r.can.prepared_this && r.can.authorise ? " You prepared it, so you can't sign it off." : ""}</div>`
      : r.status === "approved" ? `<div class="alert alert-success mb-3"><i class="ri-shield-check-line me-1"></i>Signed off by ${esc(r.approved_by)} on ${A.day(r.approved_at)}. Prepared by ${esc(r.prepared_by)}.</div>`
      : "";
    $("recApp").innerHTML = `${alert}
      <div class="card custom-card"><div class="card-body acc-rechead">
        <div class="d-flex align-items-center gap-3 flex-fill min-w-0">${A.tile(r.account.kind)}<div class="min-w-0"><div class="fs-15 fw-semibold text-truncate">${esc(r.account.name)}</div><div class="acc-sub">${esc(r.account.code)}${r.account.number_masked ? ` · ${esc(r.account.number_masked)}` : ""} · <span class="badge bg-${{ draft: "primary", submitted: "warning text-dark", approved: "success", returned: "danger" }[r.status]}">${esc(r.status_label)}</span></div></div></div>
        <div class="acc-rechead-fields">
          <div><label class="form-label" for="recDate">Statement up to</label><input type="date" class="form-control" id="recDate" value="${r.statement_date}"${edit ? "" : " disabled"}></div>
          <div><label class="form-label" for="recBal">Closing balance</label><div class="input-group"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control text-end fw-semibold" id="recBal" value="${r.statement_balance}"${edit ? "" : " disabled"}></div></div>
          ${edit ? '<button type="button" class="btn btn-outline-primary" data-import><i class="ri-upload-2-line me-1"></i>Import statement</button>' : ""}
        </div>
      </div></div>
      ${strip(r)}
      <div class="row">
        <div class="col-xl-7"><div class="card custom-card">
          <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">In the cashbook</div><span class="card-subtitle-text">${edit ? "Tick what is on the statement - what is left is in transit or not yet presented" : "Ticked against the statement"}</span></div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Show">${[["all", "All"], ["open", "Not on it yet"], ["cleared", "On the statement"]].map(([k, l]) => `<button type="button" class="btn btn-outline-primary${bookFilter === k ? " active" : ""}" data-filter="${k}">${l} <span class="badge bg-light text-dark ms-1">${counts[k]}</span></button>`).join("")}</div></div>
          <div class="card-body p-0"><div class="table-responsive acc-book-wrap"><table class="table table-hover mb-0 acc-table acc-book"><thead><tr><th class="pp-check">${edit ? '<input type="checkbox" class="form-check-input" id="tickAll" aria-label="Tick everything shown">' : ""}</th><th>Date</th><th>Details</th><th class="text-end">In</th><th class="text-end">Out</th></tr></thead><tbody id="bookRows">${bookRows(r)}</tbody></table></div></div>
        </div></div>
        <div class="col-xl-5"><div class="card custom-card">
          <div class="card-header"><div><div class="card-title">The statement</div><span class="card-subtitle-text">${r.statement.length ? `${r.statement.length} lines - ${r.statement.filter((s) => s.status === "unmatched").length} not in the books` : "Imported from the bank or M-Pesa"}</span></div></div>
          <div class="card-body" id="stmtBox">${statementRows(r)}</div>
        </div></div>
      </div>
      <div class="acc-recfoot">
        ${edit ? '<button type="button" class="btn btn-outline-danger me-auto" id="discardBtn"><i class="ri-delete-bin-line me-1"></i>Discard</button>' : '<span class="me-auto"></span>'}
        ${edit ? `<button type="button" class="btn btn-primary" id="submitBtn"${Math.abs(r.difference) < 0.005 ? "" : " disabled"}><i class="ri-send-plane-line me-1"></i>Submit for sign-off</button>` : ""}
        ${r.can.approve_this ? '<button type="button" class="btn btn-outline-danger" id="returnBtn"><i class="ri-arrow-go-back-line me-1"></i>Send back</button><button type="button" class="btn btn-success" id="approveBtn"><i class="ri-shield-check-line me-1"></i>Sign off</button>' : ""}
      </div>`;
    if (window.DateField && edit) DateField.enhance($("recDate"), { quick: [] });
    wire();
  }

  async function act(promise) {
    const res = await promise;
    if (!res.ok) {
      Toast.error(res.message);
      return null;
    }
    show(res.data);
    return res;
  }

  function wire() {
    const r = rec;
    let t = null;
    const save = () => {
      clearTimeout(t);
      t = setTimeout(() => act(API.updateRec(r.id, { statement_date: $("recDate").value, statement_balance: Number(String($("recBal").value).replace(/[^0-9.-]/g, "")) || 0 })), 500);
    };
    $("recDate")?.addEventListener("change", save);
    $("recBal")?.addEventListener("input", save);
    $("recApp").querySelectorAll("[data-import]").forEach((b) => b.addEventListener("click", importWindow));
    $("recApp").querySelectorAll("[data-filter]").forEach((b) =>
      b.addEventListener("click", () => {
        bookFilter = b.dataset.filter;
        show(rec);
      }),
    );
    $("bookRows").addEventListener("change", (e) => {
      const x = e.target.closest("[data-tick]");
      if (x) act(API.tick(r.id, [Number(x.dataset.tick)], x.checked));
    });
    $("tickAll")?.addEventListener("change", (e) => {
      const ids = [...document.querySelectorAll("[data-tick]")].map((x) => Number(x.dataset.tick));
      if (ids.length) act(API.tick(r.id, ids, e.target.checked));
    });
    $("stmtBox").addEventListener("click", (e) => {
      const b = e.target.closest("button");
      if (!b) return;
      if (b.dataset.import !== undefined) return;
      if (b.dataset.unmatch) return act(API.statementLine(r.id, b.dataset.unmatch, "unmatch"));
      if (b.dataset.ignore) return act(API.statementLine(r.id, b.dataset.ignore, "ignore", { ignore: true }));
      if (b.dataset.unignore) return act(API.statementLine(r.id, b.dataset.unignore, "ignore", { ignore: false }));
      if (b.dataset.add) return addWindow(r.statement.find((s) => String(s.id) === b.dataset.add));
      if (b.dataset.match) return matchWindow(r.statement.find((s) => String(s.id) === b.dataset.match));
    });
    $("discardBtn")?.addEventListener("click", async () => {
      if (!confirm("Discard this reconciliation? The ticks come off; nothing in the books changes.")) return;
      const res = await API.discardRec(r.id);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      window.location.href = A.link("reconciliation.php");
    });
    $("submitBtn")?.addEventListener("click", async (e) => {
      UI.setButtonLoading(e.currentTarget, "Submitting...");
      const res = await act(API.submitRec(r.id));
      if (res) Toast.success(res.message);
    });
    $("approveBtn")?.addEventListener("click", async (e) => {
      UI.setButtonLoading(e.currentTarget, "Signing off...");
      const res = await act(API.approveRec(r.id));
      if (res) Toast.success(res.message);
    });
    $("returnBtn")?.addEventListener("click", () => W.reasonWindow({ title: "Send the reconciliation back", subtitle: "Whoever prepared it fixes it and submits again", go: "Send back", placeholder: "e.g. The statement balance looks wrong", run: (reason) => API.returnRec(r.id, reason), onDone: (d) => show(d) }));
  }

  // ------------------------------------------------------------ add / match

  async function addWindow(s) {
    const o = await A.options();
    if (!o) return;
    const isIn = s.in > 0;
    const list = isIn ? [...o.income, ...o.other] : [...o.expense, ...o.other];
    const def = isIn ? o.income.find((a) => a.code === "4210")?.id : o.expense.find((a) => a.code === "5800")?.id;
    const el = PeopleKit.confirmWindow({
      title: "Add to the books",
      subtitle: `${A.day(s.date)} · ${isIn ? "+" : "-"}${A.money(isIn ? s.in : s.out)} · ${s.description || ""}`,
      icon: "ri-add-circle-line",
      go: '<i class="ri-check-line me-1"></i>Add and match',
      body: PeopleKit.parts([
        { icon: "ri-price-tag-3-line", title: isIn ? "What was received?" : "What was it for?", hint: isIn ? "e.g. Interest, a direct deposit" : "e.g. Bank charges", body: `<select class="form-select" id="adAcc">${list.map((a) => `<option value="${a.id}"${a.id === def ? " selected" : ""}>${esc(a.code)} · ${esc(a.name)}</option>`).join("")}</select>` },
        { icon: "ri-edit-line", title: "Note", body: `<input type="text" class="form-control" id="adNote" maxlength="255" value="${esc(s.description || "")}">` },
      ]),
      run: async () => {
        const res = await API.statementLine(rec.id, s.id, "add", { account_id: Number(document.getElementById("adAcc").value), narration: document.getElementById("adNote").value.trim() || null });
        if (res.ok) show(res.data);
        return res;
      },
    });
    UI.enhanceSelect(el.querySelector("#adAcc"), { search: true });
  }

  function matchWindow(s) {
    const amount = s.in || s.out;
    const cands = rec.book.filter((l) => !l.cleared && Math.abs((s.in ? l.in : l.out) - amount) < 0.005);
    if (!cands.length) return Toast.error(`No book line of ${A.money(amount)} ${s.in ? "in" : "out"} is free - add it to the books instead.`);
    PeopleKit.confirmWindow({
      title: "Match to a book line",
      subtitle: `${A.day(s.date)} · ${A.money(amount)}`,
      icon: "ri-links-line",
      go: '<i class="ri-check-line me-1"></i>Match',
      body: PeopleKit.parts([{ icon: "ri-book-open-line", title: "Book lines of the same amount", body: `<div class="acc-pick">${cands.map((l, i) => `<label class="acc-pick-row"><input type="radio" name="mtLine" value="${l.id}"${i === 0 ? " checked" : ""}><span><strong>${A.day(l.date)}</strong> · ${esc(l.party || l.details || l.number)}<small>${esc(l.number)}</small></span></label>`).join("")}</div>` }]),
      run: async () => {
        const res = await API.statementLine(rec.id, s.id, "match", { line_id: Number(document.querySelector('input[name="mtLine"]:checked').value) });
        if (res.ok) show(res.data);
        return res;
      },
    });
  }

  // ------------------------------------------------------------ import a statement

  /** A CSV read in the browser: quoted fields, commas inside quotes, CRLF. */
  function parseCsv(text) {
    const rows = [];
    let row = [];
    let cell = "";
    let q = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (q) {
        if (c === '"' && text[i + 1] === '"') {
          cell += '"';
          i++;
        } else if (c === '"') q = false;
        else cell += c;
      } else if (c === '"') q = true;
      else if (c === ",") {
        row.push(cell.trim());
        cell = "";
      } else if (c === "\n" || c === "\r") {
        if (c === "\r" && text[i + 1] === "\n") i++;
        row.push(cell.trim());
        if (row.some((x) => x !== "")) rows.push(row);
        row = [];
        cell = "";
      } else cell += c;
    }
    row.push(cell.trim());
    if (row.some((x) => x !== "")) rows.push(row);
    return rows;
  }

  const MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12 };
  /** 2026-10-05 14:22 / 05/10/2026 / 05-10-2026 / 5 Oct 2026 / 05-Oct-26 -> 2026-10-05 (day first, as Kenyan statements write it). */
  function toDate(v) {
    const s = String(v || "").trim();
    let m = s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/);
    if (m) return `${m[1]}-${m[2].padStart(2, "0")}-${m[3].padStart(2, "0")}`;
    m = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})/);
    if (m) return `${m[3].length === 2 ? `20${m[3]}` : m[3]}-${m[2].padStart(2, "0")}-${m[1].padStart(2, "0")}`;
    m = s.match(/^(\d{1,2})[\s-]([A-Za-z]{3})[a-z]*[\s-](\d{2,4})/);
    if (m && MONTHS[m[2].toLowerCase()]) return `${m[3].length === 2 ? `20${m[3]}` : m[3]}-${String(MONTHS[m[2].toLowerCase()]).padStart(2, "0")}-${m[1].padStart(2, "0")}`;
    return null;
  }
  /** "1,250.00" / "(500.00)" / "-500" / "KES 300" -> number. */
  function toAmount(v) {
    let s = String(v || "").trim();
    if (!s) return 0;
    const neg = /^\(.*\)$/.test(s) || /^-/.test(s) || /DR$/i.test(s);
    s = s.replace(/[^0-9.]/g, "");
    const n = parseFloat(s) || 0;
    return neg ? -n : n;
  }

  const ROLES = [
    ["date", "Date", true],
    ["description", "Description", false],
    ["reference", "Reference", false],
    ["in", "Money in", false],
    ["out", "Money out", false],
    ["amount", "Amount (+ in, - out)", false],
    ["balance", "Balance", false],
  ];

  function importWindow() {
    let table = null; // {header, rows}
    let mapping = {};
    const remembered = rec.mapping ? (typeof rec.mapping === "string" ? JSON.parse(rec.mapping) : rec.mapping) : null;
    const el = PeopleKit.confirmWindow({
      title: "Import the statement",
      subtitle: "A CSV from the bank, or the M-Pesa statement from the portal - read here, nothing is uploaded until you import",
      icon: "ri-upload-2-line",
      go: '<i class="ri-check-line me-1"></i>Import and match',
      body: PeopleKit.parts([
        { icon: "ri-file-excel-2-line", title: "The file", body: `<input type="file" class="form-control" id="imFile" accept=".csv,text/csv"><div class="acc-sub mt-1" id="imKind"></div>` },
        { icon: "ri-table-line", title: "Which column is what", body: '<div id="imMap" class="acc-im-map"><p class="acc-muted-line mb-0">Pick the file first.</p></div>' },
        { icon: "ri-eye-line", title: "Preview", body: '<div id="imPreview"><p class="acc-muted-line mb-0">-</p></div>' },
      ]),
      run: async () => {
        if (!table) return { ok: false, message: "Pick the statement file first." };
        const rows = normalise();
        if (!rows.length) return { ok: false, message: "No lines with a date and an amount were found - check the columns." };
        const res = await API.importStatement(rec.id, rows, mapping);
        if (res.ok) show(res.data);
        return res;
      },
    });
    el.querySelector(".modal-dialog").classList.add("modal-xl");

    function normalise() {
      const idx = (role) => (mapping[role] !== undefined && mapping[role] !== "" ? table.header.indexOf(mapping[role]) : -1);
      const [d, ds, rf, i, o, am, bl] = ["date", "description", "reference", "in", "out", "amount", "balance"].map(idx);
      return table.rows
        .map((r) => {
          const date = toDate(r[d]);
          let inn = i >= 0 ? Math.abs(toAmount(r[i])) : 0;
          let out = o >= 0 ? Math.abs(toAmount(r[o])) : 0;
          if (am >= 0 && !inn && !out) {
            const a = toAmount(r[am]);
            a >= 0 ? (inn = a) : (out = -a);
          }
          return date && (inn || out) ? { date, description: ds >= 0 ? r[ds] : null, reference: rf >= 0 ? r[rf] : null, money_in: inn, money_out: out, balance: bl >= 0 && r[bl] !== "" ? toAmount(r[bl]) : null } : null;
        })
        .filter(Boolean);
    }

    function preview() {
      const rows = normalise();
      el.querySelector("#imPreview").innerHTML = rows.length
        ? `<div class="table-responsive"><table class="table table-sm acc-table mb-0"><thead><tr><th>Date</th><th>Description</th><th class="text-end">In</th><th class="text-end">Out</th></tr></thead><tbody>${rows
            .slice(0, 6)
            .map((r) => `<tr><td>${A.day(r.date)}</td><td class="text-truncate" style="max-width:20rem">${esc(r.description || r.reference || "")}</td><td class="text-end text-success">${A.amount(r.money_in)}</td><td class="text-end text-danger">${A.amount(r.money_out)}</td></tr>`)
            .join("")}</tbody></table></div><div class="acc-sub mt-1">${rows.length} lines · in ${A.money(rows.reduce((t, r) => t + r.money_in, 0))} · out ${A.money(rows.reduce((t, r) => t + r.money_out, 0))}</div>`
        : '<p class="text-danger mb-0">No lines with a date and an amount yet - check the columns.</p>';
    }

    function mapUi() {
      el.querySelector("#imMap").innerHTML = `<div class="row g-2">${ROLES.map(([k, l, req]) => `<div class="col-sm-6 col-lg-4"><label class="form-label">${l}${req ? " *" : ""}</label><select class="form-select" data-role-map="${k}"><option value="">-</option>${table.header.map((h) => `<option value="${esc(h)}"${mapping[k] === h ? " selected" : ""}>${esc(h)}</option>`).join("")}</select></div>`).join("")}</div>`;
      el.querySelectorAll("[data-role-map]").forEach((s) =>
        s.addEventListener("change", () => {
          mapping[s.dataset.roleMap] = s.value;
          preview();
        }),
      );
      preview();
    }

    el.querySelector("#imFile").addEventListener("change", async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      const rows = parseCsv(await f.text());
      // The table starts at the first row that names a date or receipt column (statements have a title block above).
      const at = Math.max(0, rows.findIndex((r) => r.some((c) => /date|time|receipt no/i.test(c))));
      table = { header: rows[at] || [], rows: rows.slice(at + 1) };
      const has = (re) => table.header.find((h) => re.test(h));
      const mpesa = has(/receipt no/i) && has(/paid in/i) && has(/withdraw/i);
      if (mpesa) {
        mapping = { date: has(/completion time/i) || has(/time|date/i), description: has(/details/i), reference: has(/receipt no/i), in: has(/paid in/i), out: has(/withdraw/i), balance: has(/^balance$/i) || "" };
        el.querySelector("#imKind").innerHTML = '<span class="soft-chip soft-purple"><i class="ri-smartphone-line"></i>M-Pesa statement recognised</span>';
      } else if (remembered && Object.values(remembered).some((h) => table.header.includes(h))) {
        mapping = { ...remembered };
        el.querySelector("#imKind").innerHTML = '<span class="soft-chip soft-primary"><i class="ri-history-line"></i>Columns as last time</span>';
      } else {
        mapping = { date: has(/date/i) || "", description: has(/desc|narr|details|particular/i) || "", reference: has(/ref|cheque/i) || "", in: has(/credit|deposit|paid in|money in/i) || "", out: has(/debit|withdraw|paid out|money out/i) || "", amount: has(/^amount$/i) || "", balance: has(/balance/i) || "" };
        el.querySelector("#imKind").innerHTML = `<span class="soft-chip soft-warning"><i class="ri-table-line"></i>${table.rows.length} rows - check which column is what</span>`;
      }
      mapUi();
    });
  }

  // ------------------------------------------------------------ print

  /** The reconciliation statement: the diocese PDF (accounting.reconciliation). */
  const print = () => rec && A.pdf("accounting.reconciliation", { record_id: rec.id }, `Reconciliation - ${rec.account.name}`);
  async function init() {
    $("printBtn").addEventListener("click", print);
    A.ownOnly();
    const q = new URLSearchParams(window.location.search);
    if (q.get("id")) {
      const res = await API.rec(Number(q.get("id")));
      if (!res.ok) {
        $("recApp").innerHTML = A.errorBox(res.message);
        return;
      }
      return show(res.data);
    }
    if (q.get("account_id")) {
      // Carry on with one still open for this account, else start.
      const list = await API.reconciliation();
      const acc = list.ok ? list.data.accounts.find((a) => String(a.id) === q.get("account_id")) : null;
      if (acc?.last && acc.check === "reconcile" && ["draft", "returned", "submitted"].includes(acc.last.status)) {
        setUrl(acc.last.id);
        const res = await API.rec(acc.last.id);
        if (res.ok) return show(res.data);
      }
      return startCard(q.get("account_id"));
    }
    $("recApp").innerHTML = A.errorBox("Open a reconciliation from the Reconciliation page.");
  }

  document.addEventListener("DOMContentLoaded", init);
})();
