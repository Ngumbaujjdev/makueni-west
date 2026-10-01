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
 *   BudgetsEntryModal.open({ budgetId, direction: "out", entry: null, onSaved })
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
    document.getElementById("entryDeleteBtn").addEventListener("click", remove);
    document.getElementById("entryAnotherBtn").addEventListener("click", () => {
      el.classList.remove("is-done");
      fill(null);
    });
  }

  /** Open the window for a budget (and optionally an entry to change). */
  async function open({ budgetId, direction = "out", entry = null, onSaved = null } = {}) {
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
    ctx = { budget: detail.data.budget, lines: inBudget, extra, entry, direction: entry?.direction || direction, onSaved };
    fill(entry);
    modal.show();
  }

  function fill(entry) {
    ctx.entry = entry;
    const b = ctx.budget;
    document.getElementById("budgetEntryTitle").textContent = entry ? "Change entry" : "Record money";
    document.getElementById("budgetEntrySubtitle").textContent = `${b.period_label} budget · ${b.place?.name || ""}`;
    document.getElementById("entryDeleteBtn").hidden = !entry;
    document.querySelectorAll(`#${MODAL_ID} .is-invalid`).forEach((x) => x.classList.remove("is-invalid"));

    document.getElementById("entryDirectionWrap").innerHTML = UI.renderSegmented(
      "entryDirection",
      [
        { value: "in", label: '<i class="ri-arrow-down-circle-line me-1"></i>Money in' },
        { value: "out", label: '<i class="ri-arrow-up-circle-line me-1"></i>Money out' },
      ],
      ctx.direction,
      { ariaLabel: "Money in or out" },
    );
    UI.wireSegmented("entryDirection", (value) => {
      ctx.direction = value;
      fillLines(null);
      preview();
    });
    document.getElementById("entryMethodWrap").innerHTML = UI.renderSegmented("entryMethod", METHODS, entry?.method || "mpesa", { ariaLabel: "How it was paid" });
    UI.wireSegmented("entryMethod", () => {});

    fillLines(entry?.line_id ?? null);
    document.getElementById("entryAmount").value = entry ? B.amount(entry.amount) : "";
    const date = document.getElementById("entryDate");
    date.min = b.start_date;
    date.max = b.end_date;
    const today = new Date().toISOString().slice(0, 10);
    date.value = entry?.entry_date || (today < b.start_date ? b.start_date : today > b.end_date ? b.end_date : today);
    document.getElementById("entryDescription").value = entry?.description || "";
    document.getElementById("entryCounterparty").value = entry?.counterparty || "";
    document.getElementById("entryReference").value = entry?.reference || "";
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
    // The footer sums it up, like the report window: "Money out · Salaries & Wages · KES 1,500.00 · 1 Oct"
    const day = document.getElementById("entryDate").value;
    document.getElementById("entrySummary").innerHTML = `
      <span class="avatar avatar-xs bg-${side === "in" ? "success" : "danger"} text-white"><i class="${side === "in" ? "ri-arrow-down-line" : "ri-arrow-up-line"}"></i></span>
      <span><b>Money ${side}</b>${line ? ` · ${B.esc(line.name)}` : ""}${amount ? ` · ${B.money(amount)}` : ""}${day ? ` · ${new Date(`${day}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" })}` : ""}</span>`;
    document.getElementById("entryPreviewWhat").textContent = line ? `Money ${side} · ${line.name}` : `Money ${side}`;
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
    if (!lineId || !amount || !description) {
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

    // Keep the window's figures in step, so "Record another" shows the new "left".
    const side = res.data.direction;
    const planned = ctx.lines[side].find((l) => l.line_id === res.data.line_id);
    if (planned && res.body.line) Object.assign(planned, { actual: res.body.line.actual, left: res.body.line.left });
    else if (res.body.line) {
      ctx.extra[side] = ctx.extra[side].filter((l) => l.id !== res.data.line_id);
      ctx.lines[side].push({ line_id: res.data.line_id, name: res.data.line, ...res.body.line });
    }

    document.getElementById("entryDoneTitle").textContent = ctx.entry ? "Change saved" : side === "in" ? "Money in recorded" : "Money out recorded";
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
