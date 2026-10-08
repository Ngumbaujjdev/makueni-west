/**
 * ============================================================================
 * BUDGETS - "Record money" window (Overview, Spending and a budget's page)
 * ============================================================================
 * Modelled on the attendance entry window, in the same look:
 *   1 In or out?   2 Which line? (with how much is left)
 *   3 How much, and when?   4 Details (what for, paid to, how, reference)
 * with a live preview ("After this, Electricity has KES 800.00 left"),
 * busy/done states, "Record another", and Change / Delete (with Undo).
 *
 *   BudgetsEntryModal.open({ budgetId, direction: "out", entry: null, onSaved, prefill: {amount, description, counterparty} })
 * ============================================================================
 */
const BudgetsEntryModal = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const MODAL_ID = "budgetEntryModal";
  const METHODS = [
    { value: "cash", label: "Cash" },
    { value: "mpesa", label: "M-Pesa" },
    { value: "bank", label: "Bank" },
    { value: "cheque", label: "Cheque" },
  ];

  let ctx = null; // { budget, lines: {in, out}, extra: {in, out}, entry, direction, onSaved }
  let modal = null;

  function mount() {
    if (document.getElementById(MODAL_ID)) return;
    const html = `
      <div class="modal fade app-modal att-modal budget-entry-modal" id="${MODAL_ID}" tabindex="-1" aria-hidden="true" aria-labelledby="budgetEntryTitle">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
          <div class="modal-content">
            <div class="modal-header">
              <span class="app-modal-icon" id="budgetEntryIcon"><i class="ri-exchange-dollar-line"></i></span>
              <div class="flex-fill" style="min-width: 0;">
                <h5 class="modal-title" id="budgetEntryTitle">Record money</h5>
                <div class="app-modal-subtitle" id="budgetEntrySubtitle"></div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="att-entry">
                <div class="att-entry-main">
                  <section class="att-entry-section">
                    <div class="att-entry-title"><span>1</span>In or out?</div>
                    <div id="entryDirectionWrap"></div>
                  </section>
                  <section class="att-entry-section">
                    <div class="att-entry-title"><span>2</span>Which line?</div>
                    <select class="form-select" id="entryLine" aria-label="Line"></select>
                    <div class="invalid-feedback" id="entryLineError">Choose the line.</div>
                  </section>
                  <section class="att-entry-section">
                    <div class="att-entry-title"><span>3</span>How much, and when?</div>
                    <div class="row g-2">
                      <div class="col-sm-7">
                        <div class="input-group">
                          <span class="input-group-text">KES</span>
                          <input type="text" inputmode="decimal" class="form-control text-end fw-semibold" id="entryAmount" placeholder="0.00" autocomplete="off">
                        </div>
                        <div class="invalid-feedback" id="entryAmountError">Type the amount.</div>
                      </div>
                      <div class="col-sm-5">
                        <input type="date" class="form-control" id="entryDate" aria-label="Date">
                        <div class="invalid-feedback" id="entryDateError"></div>
                        <div class="budget-date-hint" id="entryDateHint" hidden></div>
                      </div>
                    </div>
                  </section>
                  <section class="att-entry-section mb-0">
                    <div class="att-entry-title"><span>4</span>Details</div>
                    <div class="row g-2">
                      <div class="col-12">
                        <input type="text" class="form-control" id="entryDescription" maxlength="255" placeholder="What for? e.g. KPLC bill for March">
                        <div class="invalid-feedback">Say what it was for.</div>
                      </div>
                      <div class="col-sm-6">
                        <input type="text" class="form-control" id="entryCounterparty" maxlength="255" placeholder="Paid to">
                      </div>
                      <div class="col-sm-6">
                        <input type="text" class="form-control" id="entryReference" maxlength="100" placeholder="Receipt / M-Pesa code (optional)">
                      </div>
                      <div class="col-12 d-flex flex-wrap align-items-center gap-2">
                        <span class="fs-12 fw-semibold">How was it paid?</span>
                        <div id="entryMethodWrap"></div>
                      </div>
                      <div class="col-12" id="entryReceiptWrap">
                        <label class="budget-receipt-pick mb-0">
                          <i class="ri-attachment-2"></i>
                          <span id="entryReceiptName">Attach a receipt (photo or PDF, optional)</span>
                          <input type="file" id="entryReceipt" accept="image/jpeg,image/png,image/webp,application/pdf" hidden>
                        </label>
                      </div>
                    </div>
                  </section>
                </div>
                <aside class="att-entry-preview budget-entry-preview" id="entryPreview" aria-live="polite">
                  <div class="att-preview-label">Preview</div>
                  <div class="att-preview-what" id="entryPreviewWhat">-</div>
                  <div class="att-preview-total budget-fit-amount" id="entryPreviewTotal"><small>KES</small><span id="entryPreviewAmount">0.00</span></div>
                  <div class="count-bar att-preview-bar" aria-hidden="true"><span id="entryPreviewBar" class="bg-primary" style="width: 0%"></span></div>
                  <ul class="att-preview-list" id="entryPreviewList"></ul>
                  <div class="att-preview-compare" id="entryPreviewNote"></div>
                </aside>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-danger" id="entryDeleteBtn" hidden><i class="ri-delete-bin-line me-1"></i>Delete</button>
              <div class="budget-entry-summary me-auto" id="entrySummary"></div>
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" id="entrySaveBtn"><i class="ri-check-line me-1"></i><span>Save</span></button>
            </div>
            <div class="app-modal-state is-busy-view" role="status">
              <div class="app-modal-spinner"></div>
              <div class="fw-semibold">Saving...</div>
            </div>
            <div class="app-modal-state is-done-view">
              <div class="app-modal-tick"><i class="ri-check-line"></i></div>
              <div class="fs-5 fw-bold" id="entryDoneTitle">Recorded</div>
              <div class="app-modal-facts" id="entryDoneFacts"></div>
              <div class="d-flex flex-wrap justify-content-center gap-2">
                <button type="button" class="btn btn-primary" id="entryAnotherBtn"><i class="ri-add-line me-1"></i>Record another</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Done</button>
              </div>
            </div>
          </div>
        </div>
      </div>`;
    const holder = document.createElement("div");
    holder.innerHTML = html;
    document.body.appendChild(holder.firstElementChild);

    const el = document.getElementById(MODAL_ID);
    modal = new bootstrap.Modal(el);
    el.addEventListener("hidden.bs.modal", () => el.classList.remove("is-busy", "is-done"));
    document.getElementById("entryLine").addEventListener("change", () => {
      document.getElementById("entryLine").classList.remove("is-invalid");
      preview();
    });
    ["entryAmount", "entryDescription", "entryDate"].forEach((id) =>
      document.getElementById(id).addEventListener("input", () => {
        document.getElementById(id).classList.remove("is-invalid");
        preview();
      }),
    );
    document.getElementById("entryAmount").addEventListener("blur", (e) => {
      const v = parse(e.target.value);
      e.target.value = v ? B.amount(v) : "";
    });
    document.getElementById("entryAmount").addEventListener("focus", (e) => setTimeout(() => e.target.select(), 0));
    document.getElementById("entrySaveBtn").addEventListener("click", save);
    document.getElementById("entryReceipt").addEventListener("change", (e) => {
      const f = e.target.files[0];
      if (f && f.size > 5 * 1024 * 1024) {
        Toast.warning("A receipt can be at most 5 MB.");
        e.target.value = "";
      }
      const kept = e.target.files[0];
      document.getElementById("entryReceiptName").textContent = kept ? kept.name : "Attach a receipt (photo or PDF, optional)";
    });
    document.getElementById("entryDeleteBtn").addEventListener("click", remove);
    document.getElementById("entryAnotherBtn").addEventListener("click", () => {
      el.classList.remove("is-done");
      fill(null);
    });
  }

  /** Open the window for a budget (and optionally an entry to change). */
  async function open({ budgetId, direction = "out", entry = null, lineId = null, onSaved = null, activityId = null, prefill = null } = {}) {
    mount();
    const [detail, form] = await Promise.all([BudgetsAPI.get(budgetId), BudgetsAPI.formFor(budgetId)]);
    if (!detail.ok) {
      Toast.error(detail.message);
      return;
    }
    if (!detail.data.can?.record) {
      Toast.warning(detail.data.budget.status === "draft" ? "Start using this budget before recording money." : "Money can't be recorded on this budget.");
      return;
    }
    const inBudget = detail.data.lines;
    // Lines the budget didn't plan for can still be used - they're added as "unplanned".
    const extra = { in: [], out: [] };
    if (form.ok) {
      ["in", "out"].forEach((side) => {
        const have = new Set(inBudget[side].map((l) => l.line_id));
        extra[side] = form.data.lines[side].filter((l) => !have.has(l.id));
      });
    }
    // Opened from a line's page, the line is already chosen (and its side with it).
    const lineSide = lineId && (inBudget.in.some((l) => l.line_id === lineId) ? "in" : inBudget.out.some((l) => l.line_id === lineId) ? "out" : null);
    // activityId: opened from an event's page - the money is tagged with the event (docs/specs/events-initiatives-spec.md).
    // prefill {amount, description, counterparty}: opened from another page (a repair's cost) - used once, on a new entry.
    ctx = { budget: detail.data.budget, lines: inBudget, extra, entry, lineId, activityId, prefill, direction: entry?.direction || lineSide || direction, onSaved, deductions: detail.data.deductions || [] };
    fill(entry);
    modal.show();
  }

  function fill(entry) {
    ctx.entry = entry;
    const b = ctx.budget;
    document.getElementById("budgetEntryTitle").textContent = entry ? "Change entry" : "Record money";
    document.getElementById("budgetEntrySubtitle").textContent = `${b.period_label} budget · ${b.place?.name || ""}`;
    document.getElementById("entryDeleteBtn").hidden = !entry;
    // A receipt can be attached when recording; a changed entry's receipts live on its page.
    document.getElementById("entryReceiptWrap").hidden = !!entry;
    document.getElementById("entryReceipt").value = "";
    document.getElementById("entryReceiptName").textContent = "Attach a receipt (photo or PDF, optional)";
    document.querySelectorAll(`#${MODAL_ID} .is-invalid`).forEach((x) => x.classList.remove("is-invalid"));

    document.getElementById("entryDirectionWrap").innerHTML = UI.renderSegmented(
      "entryDirection",
      [
        { value: "in", label: '<i class="ri-arrow-down-circle-line me-1"></i>Income' },
        { value: "out", label: '<i class="ri-arrow-up-circle-line me-1"></i>Expenses' },
      ],
      ctx.direction,
      { ariaLabel: "Income or expense" },
    );
    UI.wireSegmented("entryDirection", (value) => {
      ctx.direction = value;
      fillLines(null);
      preview();
    });
    document.getElementById("entryMethodWrap").innerHTML = UI.renderSegmented("entryMethod", METHODS, entry?.method || "mpesa", { ariaLabel: "How it was paid" });
    UI.wireSegmented("entryMethod", () => {});

    fillLines(entry?.line_id ?? ctx.lineId ?? null);
    document.getElementById("entryAmount").value = entry ? B.amount(entry.amount) : "";
    const date = document.getElementById("entryDate");
    date.min = b.start_date;
    date.max = b.end_date;
    const today = new Date().toISOString().slice(0, 10);
    // Today when it falls in the budget's period. Otherwise (a past or future
    // budget) nothing is filled in, so the real day has to be picked - an
    // amount never quietly lands on the period's last day.
    const outside = today < b.start_date || today > b.end_date;
    date.value = entry?.entry_date || (outside ? "" : today);
    const fmt = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
    const hint = document.getElementById("entryDateHint");
    hint.hidden = !outside || !!entry;
    hint.innerHTML = `<i class="ri-calendar-event-line me-1"></i>When was it ${ctx.direction === "in" ? "received" : "paid"}? Pick the day, between ${fmt(b.start_date)} and ${fmt(b.end_date)}.`;
    document.getElementById("entryDescription").value = entry?.description || "";
    document.getElementById("entryCounterparty").value = entry?.counterparty || "";
    document.getElementById("entryReference").value = entry?.reference || "";
    if (!entry && ctx.prefill) {
      if (ctx.prefill.amount) document.getElementById("entryAmount").value = B.amount(ctx.prefill.amount);
      document.getElementById("entryDescription").value = ctx.prefill.description || "";
      document.getElementById("entryCounterparty").value = ctx.prefill.counterparty || "";
      ctx.prefill = null;
    }
    preview();
    setTimeout(() => document.getElementById("entryAmount").focus(), 300);
  }

  function fillLines(selected) {
    const side = ctx.direction;
    const select = document.getElementById("entryLine");
    const planned = ctx.lines[side] || [];
    const extra = ctx.extra[side] || [];
    const opt = (id, label, color) => `<option value="${id}" ${String(id) === String(selected) ? "selected" : ""} data-icon="${side === "in" ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line"}" data-color="${color}">${B.esc(label)}</option>`;
    select.innerHTML = [
      '<option value="">Choose the line</option>',
      planned.length ? `<optgroup label="In this budget">${planned.map((l) => opt(l.line_id, `${l.name} — ${B.money(l.left)} ${side === "in" ? "still to come" : "left"}`, side === "in" ? "success" : l.left < 0 ? "danger" : "primary")).join("")}</optgroup>` : "",
      extra.length ? `<optgroup label="Not planned in this budget">${extra.map((l) => opt(l.id, l.name, "secondary")).join("")}</optgroup>` : "",
    ].join("");
    UI.enhanceSelect(select, { search: planned.length + extra.length > 8 });
    document.getElementById("entryCounterparty").placeholder = side === "in" ? "Received from (optional)" : "Paid to (optional)";
    document.getElementById("entryDescription").placeholder = side === "in" ? "What for? e.g. Sunday offering, 12 March" : "What for? e.g. KPLC bill for March";
  }

  function parse(text) {
    const n = Number(String(text || "").replace(/[^0-9.]/g, ""));
    return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0;
  }

  /** The line picked, with what's planned and recorded (the entry being changed left out). */
  function chosenLine() {
    const id = Number(document.getElementById("entryLine").value);
    if (!id) return null;
    const side = ctx.direction;
    const planned = (ctx.lines[side] || []).find((l) => l.line_id === id);
    const extra = (ctx.extra[side] || []).find((l) => l.id === id);
    const own = ctx.entry && ctx.entry.line_id === id ? Number(ctx.entry.amount) : 0;
    return planned ? { name: planned.name, planned: planned.planned, actual: planned.actual - own, unplanned: false } : extra ? { name: extra.name, planned: 0, actual: 0, unplanned: true } : null;
  }

  function preview() {
    const side = ctx.direction;
    const amount = parse(document.getElementById("entryAmount").value);
    const line = chosenLine();
    document.getElementById("budgetEntryIcon").className = `app-modal-icon bg-${side === "in" ? "success" : "danger"} text-white`;
    document.getElementById("entryPreview").className = `att-entry-preview budget-entry-preview is-${side}`;
    // The footer sums it up, like the report window: "Expenses · Salaries & Wages · KES 1,500.00 · 1 Oct"
    const day = document.getElementById("entryDate").value;
    document.getElementById("entrySummary").innerHTML = `
      <span class="avatar avatar-xs bg-${side === "in" ? "success" : "danger"} text-white"><i class="${side === "in" ? "ri-arrow-down-line" : "ri-arrow-up-line"}"></i></span>
      <span><b>${side === "in" ? "Income" : "Expense"}</b>${line ? ` · ${B.esc(line.name)}` : ""}${amount ? ` · ${B.money(amount)}` : ""}${day ? ` · ${new Date(`${day}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" })}` : ""}</span>`;
    document.getElementById("entryPreviewWhat").textContent = line ? `${side === "in" ? "Income" : "Expense"} · ${line.name}` : side === "in" ? "Income" : "Expense";
    // Smaller as the number gets longer, so it always fits the panel.
    const shown = B.amount(amount);
    document.getElementById("entryPreviewAmount").textContent = shown;
    document.getElementById("entryPreviewTotal").dataset.size = shown.length <= 9 ? "l" : shown.length <= 11 ? "m" : "s";
    const bar = document.getElementById("entryPreviewBar");
    const list = document.getElementById("entryPreviewList");
    const note = document.getElementById("entryPreviewNote");
    if (!line) {
      bar.style.width = "0%";
      list.innerHTML = "";
      note.innerHTML = "Choose a line to see how much is left.";
      return;
    }
    const after = line.actual + amount;
    const pct = line.planned > 0 ? Math.min(100, (after / line.planned) * 100) : after > 0 ? 100 : 0;
    const over = side === "out" && after > line.planned;
    bar.className = `bg-${side === "in" ? "success" : over ? "danger" : pct >= 80 ? "warning" : "primary"}`;
    bar.style.width = `${pct}%`;
    list.innerHTML = `
      <li><span>Planned</span><b>${B.money(line.planned)}</b></li>
      <li><span>${side === "in" ? "Received" : "Spent"} so far</span><b>${B.money(line.actual)}</b></li>
      <li><span>After this</span><b>${B.money(after)}</b></li>`;
    note.innerHTML = line.unplanned
      ? `<span class="soft-chip soft-warning"><i class="ri-error-warning-line me-1"></i>Not planned - it will be added as an unplanned line</span>`
      : over
        ? `<span class="soft-chip soft-danger"><i class="ri-alarm-warning-line me-1"></i>${B.money(after - line.planned)} over plan</span>`
        : side === "in"
          ? `After this, ${B.esc(line.name)} has <b>${B.money(Math.max(line.planned - after, 0))}</b> still to come.`
          : `After this, ${B.esc(line.name)} has <b>${B.money(line.planned - after)}</b> left.`;
    note.insertAdjacentHTML("beforeend", shareNote(side, Number(document.getElementById("entryLine").value), amount));
  }

  /** Income on a line a deduction counts: "10% of this (KES 4,000.00) is the Diocese share". */
  function shareNote(side, lineId, amount) {
    if (side !== "in" || !amount) return "";
    return (ctx.deductions || [])
      .filter((d) => d.rate_type === "percentage" && (d.basis !== "lines" || (d.basis_line_ids || []).includes(lineId)))
      .map((d) => {
        const rate = Number(d.rate_value);
        return `<div class="mt-2"><span class="soft-chip soft-purple text-wrap text-start" style="white-space: normal;"><i class="ri-percent-line me-1"></i>${rate.toLocaleString("en-GB", { maximumFractionDigits: 2 })}% of this (<b>${B.money((amount * rate) / 100)}</b>) is the ${B.esc(d.name)}</span></div>`;
      })
      .join("");
  }

  async function save() {
    const lineId = Number(document.getElementById("entryLine").value);
    const amount = parse(document.getElementById("entryAmount").value);
    const description = document.getElementById("entryDescription").value.trim();
    const date = document.getElementById("entryDate").value;
    const invalid = (id, bad) => document.getElementById(id).classList.toggle("is-invalid", bad);
    invalid("entryLine", !lineId);
    invalid("entryAmount", !amount);
    invalid("entryDescription", !description);
    invalid("entryDate", !date);
    if (!date) document.getElementById("entryDateError").textContent = "Pick the day it happened.";
    if (!lineId || !amount || !description || !date) {
      Toast.warning("Please fill in the highlighted fields.");
      return;
    }
    const methodBtn = document.querySelector("#entryMethod .seg-btn.active");
    const body = {
      budget_id: ctx.budget.id,
      budget_line_id: lineId,
      amount,
      entry_date: date,
      description,
      counterparty: document.getElementById("entryCounterparty").value.trim() || null,
      reference: document.getElementById("entryReference").value.trim() || null,
      method: methodBtn?.dataset.value || null,
      ...(ctx.activityId && !ctx.entry ? { activity_id: ctx.activityId } : {}),
    };
    const el = document.getElementById(MODAL_ID);
    el.classList.add("is-busy");
    const res = ctx.entry ? await BudgetsAPI.changeEntry(ctx.entry.id, body) : await BudgetsAPI.record(body);
    el.classList.remove("is-busy");
    if (!res.ok) {
      if (res.errors?.entry_date) {
        document.getElementById("entryDateError").textContent = [].concat(res.errors.entry_date)[0];
        invalid("entryDate", true);
      }
      Toast.error(res.message);
      return;
    }

    // The receipt goes up once the entry exists.
    const receipt = !ctx.entry && document.getElementById("entryReceipt").files[0];
    if (receipt) {
      const up = await BudgetsAPI.addReceipt(res.data.id, receipt);
      if (!up.ok) Toast.warning(`Saved, but the receipt wasn't attached: ${up.message}`);
      document.getElementById("entryReceipt").value = "";
      document.getElementById("entryReceiptName").textContent = "Attach a receipt (photo or PDF, optional)";
    }

    // Keep the window's figures in step, so "Record another" shows the new "left".
    const side = res.data.direction;
    const planned = ctx.lines[side].find((l) => l.line_id === res.data.line_id);
    if (planned && res.body.line) Object.assign(planned, { actual: res.body.line.actual, left: res.body.line.left });
    else if (res.body.line) {
      ctx.extra[side] = ctx.extra[side].filter((l) => l.id !== res.data.line_id);
      ctx.lines[side].push({ line_id: res.data.line_id, name: res.data.line, ...res.body.line });
    }

    document.getElementById("entryDoneTitle").textContent = ctx.entry ? "Change saved" : side === "in" ? "Income recorded" : "Expense recorded";
    document.getElementById("entryDoneFacts").innerHTML = `
      <span class="soft-chip soft-${side === "in" ? "success" : "danger"}">${B.money(res.data.amount)} ${side === "in" ? "received" : "spent"}</span>
      <span class="soft-chip soft-primary">${B.esc(res.data.line)}</span>
      ${res.body.line ? `<span class="soft-chip soft-${res.body.line.left < 0 && side === "out" ? "danger" : "secondary"}">${side === "out" && res.body.line.left < 0 ? `${B.money(-res.body.line.left)} over plan` : `${B.money(Math.max(res.body.line.left, 0))} ${side === "in" ? "still to come" : "left"}`}</span>` : ""}`;
    el.classList.add("is-done");
    ctx.onSaved?.(res.data);
  }

  function remove() {
    const entry = ctx.entry;
    Toast.confirm(
      `Delete this entry (${B.money(entry.amount)} - ${entry.description})?`,
      async () => {
        const res = await BudgetsAPI.removeEntry(entry.id);
        if (!res.ok) {
          Toast.error(res.message);
          return;
        }
        modal.hide();
        ctx.onSaved?.(null);
        const onSaved = ctx.onSaved;
        Toast.success("Entry deleted", {
          action: {
            label: "Undo",
            onClick: async () => {
              const back = await BudgetsAPI.restoreEntry(entry.id);
              back.ok ? Toast.success("Entry brought back") : Toast.error(back.message);
              onSaved?.(back.data);
            },
          },
        });
      },
      null,
      { title: "Delete entry", confirmText: "Delete", type: "error" },
    );
  }

  return { open };
})();

window.BudgetsEntryModal = BudgetsEntryModal;
