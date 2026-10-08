/**
 * ============================================================================
 * FACILITIES - one piece of equipment (item.php?id=)
 * ============================================================================
 * An asset: the hero (its photo, kind, asset number, room, condition and the
 * facts) and what you can do - Lend it out (or Ask to borrow it), Report a
 * repair, Change. Then the tabs: Purchase (what it cost, where it was bought,
 * the receipts and photos, and the Budgets entry that paid for it),
 * Borrowing (asks to answer, loans to mark back), Repairs and History. The
 * tab is kept in the URL.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const F = FacilitiesUI;
  const CTX = window.FAC_CTX;
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("id"));
  const TABS = ["purchase", "loans", "repairs", "history"];
  const state = { it: null, tab: TABS.includes(params.get("tab")) ? params.get("tab") : "purchase" };
  let lightbox = null;

  const initials = (n) => F.esc(String(n || "?").split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase());
  const fact = (icon, color, label, value, sub = "") => `<div class="pp-fact" style="--q: var(--${color}-rgb)"><span class="pp-fact-icon"><i class="${icon}"></i></span><div class="min-w-0"><span>${label}</span><strong>${value}${sub ? `<small>${sub}</small>` : ""}</strong></div></div>`;

  function hero() {
    const it = state.it;
    const can = it.can;
    const photos = it.photos || [];
    const mineAsked = it.loans.find((l) => l.mine && l.status === "requested");
    const lendBtn = can.manage
      ? it.available ? '<button type="button" class="btn btn-primary" data-act="lend"><i class="ri-hand-coin-line me-1"></i>Lend it out</button>' : ""
      : mineAsked ? "" : '<button type="button" class="btn btn-primary" data-act="ask"><i class="ri-question-answer-line me-1"></i>Ask to borrow</button>';
    $("itHero").innerHTML = `<div class="card-body">
      <div class="ev-hero-row">
        ${photos.length
          ? `<div class="fx-hero-media"><a class="fx-hero-photo" href="${F.esc(photos[0].url)}" data-photo><img src="${F.esc(photos[0].thumb_url)}" alt="${F.esc(it.name)}"></a>${photos.length > 1 ? `<div class="fx-hero-strip">${photos.slice(1).map((p) => `<a href="${F.esc(p.url)}" data-photo><img src="${F.esc(p.thumb_url)}" alt=""></a>`).join("")}</div>` : ""}</div>`
          : `<div class="fx-hero-media">${F.tile(it.icon, it.colour, "xxl")}${can.manage ? '<button type="button" class="btn btn-sm btn-outline-primary mt-2 w-100" data-act="photos"><i class="ri-camera-line me-1"></i>Add a photo</button>' : ""}</div>`}
        <div class="flex-fill min-w-0">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="ev-hero-title mb-0">${F.esc(it.name)}</h2>${F.conditionPill(it.condition)}${it.open_repairs ? '<span class="soft-chip soft-warning"><i class="ri-tools-line"></i>Being repaired</span>' : ""}</div>
          <div class="d-flex flex-wrap gap-1"><span class="badge bg-dark fx-asset-no"><i class="ri-price-tag-3-line me-1"></i>${F.esc(it.asset_no || "-")}</span><span class="soft-chip soft-${it.colour}"><i class="${it.icon}"></i>${F.esc(it.category_label)}</span><span class="soft-chip soft-primary"><i class="ri-map-pin-line"></i>${it.room ? F.esc(it.room.name) : "No room"}</span>${it.serial ? `<span class="soft-chip soft-primary"><i class="ri-barcode-line"></i>${F.esc(it.serial)}</span>` : ""}</div>
          ${it.notes ? `<p class="cr-note mt-2 mb-0">${F.esc(it.notes)}</p>` : ""}
          ${mineAsked ? `<div class="alert alert-warning d-flex align-items-center gap-2 mt-2 mb-0 py-2"><i class="ri-time-line fs-16"></i><span>You asked to borrow ${mineAsked.quantity > 1 ? `${mineAsked.quantity} of these` : "this"} from ${F.day(mineAsked.out_on)} - waiting for the facilities manager.</span></div>` : ""}
        </div>
        <div class="ev-hero-actions">
          ${lendBtn}
          <button type="button" class="btn btn-outline-primary" data-act="repair"><i class="ri-tools-line me-1"></i>Report a repair</button>
          ${can.manage ? '<button type="button" class="btn btn-outline-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Change</button>' : ""}
        </div>
      </div>
      <div class="pp-facts mn-hero-facts">
        ${fact("ri-hashtag", "primary", "How many", F.num(it.quantity), `${F.num(it.available)} here · ${F.num(it.on_loan)} on loan`)}
        ${fact("ri-money-dollar-circle-line", "success", "What it cost", it.total !== null ? F.money(it.total) : "Not recorded", it.value !== null && it.quantity > 1 ? `${F.money(it.value)} each` : it.in_budgets ? "Recorded in Budgets" : "")}
        ${fact("ri-calendar-line", "warning", "Bought", it.bought_on ? F.day(it.bought_on, { day: "numeric", month: "short", year: "numeric" }) : "-", it.supplier ? F.esc(it.supplier) : "")}
        ${fact("ri-tools-line", "pink", "Repairs", F.num(it.repairs.length), it.repairs_spent ? `${F.money(it.repairs_spent)} spent` : it.open_repairs ? `${F.num(it.open_repairs)} still open` : "Nothing spent")}
      </div>
    </div>`;
    const asks = it.loans.filter((l) => l.status === "requested").length;
    $("tabPurchase").textContent = it.total !== null ? `${F.money(it.total)}${it.receipts.length ? " · receipt kept" : " · no receipt yet"}` : "What it cost, and the receipt";
    $("tabLoans").textContent = asks ? `${asks} ${asks === 1 ? "ask" : "asks"} waiting` : it.loans.length ? `${it.loans.filter((l) => l.status === "out").length} out · ${it.loans.length} in all` : "Who has borrowed it";
    $("tabRepairs").textContent = it.repairs.length ? `${it.repairs.length} ${it.repairs.length === 1 ? "repair" : "repairs"}` : "What was fixed";
    lightboxOn();
  }

  function lightboxOn() {
    if (!window.GLightbox) return;
    lightbox?.destroy();
    lightbox = GLightbox({ selector: "[data-photo]" });
  }

  // ------------------------------------------------------------------ purchase

  function purchaseTab() {
    const it = state.it;
    const manage = it.can.manage;
    const row = (icon, label, value) => `<li><span class="fx-kv-icon"><i class="${icon}"></i></span><span class="fx-kv-label">${label}</span><strong>${value}</strong></li>`;
    const missing = [it.value === null ? "the price" : null, !it.receipts.length ? "a receipt" : null, !it.photos.length ? "a photo" : null].filter(Boolean);
    $("itMain").innerHTML = `<div class="row">
      <div class="col-xl-7">
        <div class="card custom-card">
          <div class="card-header justify-content-between"><div><div class="card-title">What it cost</div><span class="card-subtitle-text">So the church knows what its things are worth</span></div>${manage ? '<button type="button" class="btn btn-sm btn-outline-primary" data-act="edit"><i class="ri-edit-line me-1"></i>Change</button>' : ""}</div>
          <div class="card-body">
            ${missing.length ? `<div class="alert alert-warning d-flex align-items-center gap-2 py-2"><i class="ri-information-line fs-16"></i><span>Still to add: ${missing.join(", ")}.</span></div>` : ""}
            <ul class="fx-kv">
              ${row("ri-price-tag-3-line", "Asset number", `${F.esc(it.asset_no || "-")} <small class="mb-sub ms-1">write it on the item</small>`)}
              ${row("ri-money-dollar-circle-line", "Price each", it.value !== null ? F.money(it.value) : '<span class="text-warning">Not recorded</span>')}
              ${row("ri-hashtag", "How many", F.num(it.quantity))}
              ${row("ri-calculator-line", "Cost in all", it.total !== null ? F.money(it.total) : "-")}
              ${row("ri-calendar-line", "Bought on", it.bought_on ? F.day(it.bought_on, { day: "numeric", month: "long", year: "numeric" }) : "-")}
              ${row("ri-store-2-line", "Where it was bought", it.supplier ? F.esc(it.supplier) : "-")}
            </ul>
          </div>
        </div>
        <div class="card custom-card">
          <div class="card-header justify-content-between"><div><div class="card-title">Receipts</div><span class="card-subtitle-text" id="rcSub">${it.receipts.length ? `${it.receipts.length} kept` : "A photo or PDF of the receipt"}</span></div>${manage && it.receipts.length < 3 ? '<label class="btn btn-sm btn-primary mb-0"><i class="ri-attachment-2 me-1"></i>Attach a receipt<input type="file" id="rcFile" accept="image/png,image/jpeg,image/webp,application/pdf" hidden></label>' : ""}</div>
          <div class="card-body" id="rcList"></div>
        </div>
      </div>
      <div class="col-xl-5">
        <div class="card custom-card">
          <div class="card-header"><div><div class="card-title">In Budgets</div><span class="card-subtitle-text">The money paid for it</span></div></div>
          <div class="card-body" id="bgBox"></div>
        </div>
        <div class="card custom-card">
          <div class="card-header justify-content-between"><div><div class="card-title">Photos</div><span class="card-subtitle-text">${it.photos.length} of 4 · the first is shown everywhere</span></div></div>
          <div class="card-body" id="phBox"></div>
        </div>
      </div>
    </div>`;
    receipts();
    budgets();
    photos();
  }

  async function receipts() {
    const it = state.it;
    const box = $("rcList");
    if (!it.receipts.length) {
      box.innerHTML = `<div class="list-empty py-3"><span class="list-empty-icon bg-success text-white"><i class="ri-bill-line"></i></span><div class="fw-semibold mt-2">No receipt yet</div>${it.can.manage ? '<div class="fs-12">Attach a photo or PDF of it - up to 3, 5 MB each. It is kept private to our church.</div>' : ""}</div>`;
    } else {
      box.innerHTML = `<div class="budget-receipts">${it.receipts
        .map(
          (r) => `<div class="budget-receipt">
            <button type="button" class="budget-receipt-open" data-open="${r.id}" title="Open ${F.esc(r.name)}">${r.type === "image" ? `<span class="budget-receipt-thumb" data-thumb="${r.id}"></span>` : '<span class="budget-receipt-pdf"><i class="ri-file-pdf-line"></i>PDF</span>'}</button>
            <div class="budget-receipt-meta"><span class="text-truncate">${F.esc(r.name)}</span><small>${(r.size / 1024).toFixed(0)} KB</small></div>
            ${it.can.manage ? `<button type="button" class="btn btn-sm btn-danger-light budget-receipt-remove" data-unreceipt="${r.id}" title="Remove" aria-label="Remove"><i class="ri-delete-bin-line"></i></button>` : ""}
          </div>`,
        )
        .join("")}</div>`;
      for (const r of it.receipts.filter((x) => x.type === "image")) {
        const url = await FacilitiesAPI.fileUrl(r.url);
        const thumb = box.querySelector(`[data-thumb="${r.id}"]`);
        if (url && thumb) thumb.style.backgroundImage = `url("${url}")`;
      }
    }
    $("rcFile")?.addEventListener("change", async (e) => {
      const file = e.target.files[0];
      e.target.value = "";
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) return Toast.error("A receipt can be at most 5 MB.");
      box.insertAdjacentHTML("afterbegin", '<div class="gal-progress">Attaching...</div>');
      const res = await FacilitiesAPI.addReceipt(id, file);
      res.ok ? (Toast.success(res.message), refresh(res.data)) : (Toast.error(res.message), receipts());
    });
  }

  function budgets() {
    const it = state.it;
    const e = it.budget_entry;
    const box = $("bgBox");
    if (e) {
      box.innerHTML = `<div class="fx-linked">
          <span class="avatar avatar-md avatar-rounded bg-success text-white flex-shrink-0"><i class="ri-checkbox-circle-line"></i></span>
          <div class="flex-fill min-w-0"><strong>Recorded in Budgets</strong><small>${F.esc(e.description)} · ${F.day(e.date, { day: "numeric", month: "short", year: "numeric" })}${e.counterparty ? ` · ${F.esc(e.counterparty)}` : ""}</small></div>
          <strong class="text-nowrap">${F.money(e.amount)}</strong>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">${CTX.can.budget && !e.deleted ? `<a class="btn btn-sm btn-outline-success" href="${CTX.siteUrl}/church/budget/entry.php?id=${e.id}"><i class="ri-external-link-line me-1"></i>Open it in Budgets</a>` : ""}${it.can.manage ? '<button type="button" class="btn btn-sm btn-light border" data-act="unlink">Not this entry</button>' : ""}</div>
        ${e.deleted ? '<p class="text-danger fw-semibold mt-2 mb-0">That entry was removed from Budgets.</p>' : ""}`;
      return;
    }
    box.innerHTML = `<div class="fx-linked is-empty">
        <span class="avatar avatar-md avatar-rounded bg-warning text-dark flex-shrink-0"><i class="ri-wallet-3-line"></i></span>
        <div class="flex-fill min-w-0"><strong>Not in Budgets yet</strong><small>Recording what was paid keeps the money and our things in step: the budget shows what was spent, and this page shows what it bought.</small></div>
      </div>
      ${it.can.manage
        ? `<div class="d-flex flex-wrap gap-2 mt-3">${it.budget_in_use ? `<button type="button" class="btn btn-sm btn-success" data-act="record"><i class="ri-money-dollar-circle-line me-1"></i>Record it in Budgets${it.total ? ` · ${F.money(it.total)}` : ""}</button>` : ""}<button type="button" class="btn btn-sm btn-outline-success" data-act="link"><i class="ri-links-line me-1"></i>Link one already recorded</button></div>
          ${it.budget_in_use ? "" : '<p class="mb-sub mt-2 mb-0">No budget is in use today, so only an entry already recorded can be linked.</p>'}`
        : ""}`;
  }

  function photos() {
    const it = state.it;
    const box = $("phBox");
    const left = 4 - it.photos.length;
    box.innerHTML = `${it.can.manage && left > 0 ? `<label class="gal-drop" id="phDrop" for="phFile" tabindex="0"><span class="gal-drop-icon"><i class="ri-upload-cloud-2-line"></i></span><div><strong>Drop photos here, or choose them</strong><small>JPG, PNG or WebP, up to 10 MB - ${left} more. We stand them upright and make them small.</small></div></label><input type="file" id="phFile" accept="image/png,image/jpeg,image/webp" multiple hidden>` : ""}
      ${it.photos.length
        ? `<div class="gal-grid">${it.photos
            .map(
              (p, i) => `<figure class="gal-item" data-id="${p.id}"><a class="gal-thumb" href="${F.esc(p.url)}" data-photo><img src="${F.esc(p.thumb_url)}" alt="Photo ${i + 1}" loading="lazy"></a>
              ${it.can.manage ? `<div class="gal-tools">${i ? `<button type="button" class="gal-tool" data-first="${p.id}" title="Show this one first" aria-label="Show this one first"><i class="ri-star-line"></i></button>` : ""}<button type="button" class="gal-tool is-danger" data-unphoto="${p.id}" aria-label="Remove this photo"><i class="ri-delete-bin-line"></i></button></div>` : ""}</figure>`,
            )
            .join("")}</div>`
        : `<div class="gal-empty"><i class="ri-image-line"></i><span>No photo yet${it.can.manage ? " - a photo helps everyone know which one it is." : "."}</span></div>`}`;
    lightboxOn();
    const input = $("phFile");
    const add = async (files) => {
      files = files.filter((f) => /^image\//.test(f.type)).slice(0, left);
      if (!files.length) return;
      if (files.some((f) => f.size > 10 * 1024 * 1024)) return Toast.error("A photo can be at most 10 MB.");
      box.insertAdjacentHTML("afterbegin", `<div class="gal-progress">Adding ${files.length} ${files.length === 1 ? "photo" : "photos"}...</div>`);
      const res = await FacilitiesAPI.addPhotos(id, files);
      res.ok ? (Toast.success(res.message), refresh(res.data)) : (Toast.error(res.message), photos());
    };
    input?.addEventListener("change", (e) => add([...e.target.files]));
    const drop = $("phDrop");
    if (drop) {
      ["dragenter", "dragover"].forEach((t) => drop.addEventListener(t, (e) => (e.preventDefault(), drop.classList.add("is-over"))));
      ["dragleave", "drop"].forEach((t) => drop.addEventListener(t, () => drop.classList.remove("is-over")));
      drop.addEventListener("drop", (e) => (e.preventDefault(), add([...e.dataTransfer.files])));
      drop.addEventListener("keydown", (e) => (e.key === "Enter" || e.key === " ") && (e.preventDefault(), input.click()));
    }
  }

  // ------------------------------------------------------------------ borrowing, repairs, history

  function loansTab() {
    const it = state.it;
    const asks = it.loans.filter((l) => l.status === "requested");
    const rest = it.loans.filter((l) => l.status !== "requested");
    const badge = (l) => (l.status === "returned" ? '<span class="badge bg-success">Back</span>' : l.status === "declined" ? '<span class="badge bg-danger">Declined</span>' : l.overdue ? '<span class="badge bg-danger">Late</span>' : '<span class="badge bg-primary">Out</span>');
    $("itMain").innerHTML = `${asks.length ? `<div class="card custom-card"><div class="card-header"><div><div class="card-title">Asks to borrow</div><span class="card-subtitle-text">${it.can.manage ? "Agree and it is lent from the day asked; decline and they see why" : "Waiting for the facilities manager"}</span></div></div><div class="card-body"><div class="fx-loans" id="itAsks">${asks.map((l) => F.askRow(l)).join("")}</div></div></div>` : ""}
      <div class="card custom-card"><div class="card-header justify-content-between"><div><div class="card-title">Borrowing</div><span class="card-subtitle-text">Who has borrowed it, and when it came back</span></div>${it.can.manage && it.available ? '<button type="button" class="btn btn-sm btn-primary" data-act="lend"><i class="ri-hand-coin-line me-1"></i>Lend it out</button>' : !it.can.manage ? '<button type="button" class="btn btn-sm btn-primary" data-act="ask"><i class="ri-question-answer-line me-1"></i>Ask to borrow</button>' : ""}</div><div class="card-body">${
        rest.length
          ? `<div class="fx-loans">${rest
              .map(
                (l) => `<div class="fx-loan${l.overdue ? " is-late" : ""}${l.status === "returned" || l.status === "declined" ? " is-back" : ""}">
                  <span class="avatar avatar-sm avatar-rounded bg-${UI.colorFor(l.to_name)} text-white flex-shrink-0">${initials(l.to_name)}</span>
                  <div class="flex-fill min-w-0"><strong>${F.esc(l.to_name)}${l.quantity > 1 ? ` ×${l.quantity}` : ""}</strong><small>${l.status === "declined" ? `Asked for ${F.day(l.out_on)}${l.decline_reason ? ` · ${F.esc(l.decline_reason)}` : ""}` : `${F.day(l.out_on)} → ${l.returned_on ? `back ${F.day(l.returned_on)}` : `due ${F.day(l.due_on)}`}`}${l.note ? ` · ${F.esc(l.note)}` : ""}</small></div>
                  ${badge(l)}
                  ${l.status === "out" && it.can.manage ? `<button type="button" class="btn btn-sm btn-outline-success" data-back="${l.id}"><i class="ri-arrow-go-back-line me-1"></i>Back</button>` : ""}
                </div>`,
              )
              .join("")}</div>`
          : '<p class="mb-0 fw-semibold">Never lent out.</p>'
      }</div></div>`;
    if ($("itAsks")) F.wireAsks($("itAsks"), (d) => refresh(d));
  }

  function repairsTab() {
    const list = state.it.repairs;
    $("itMain").innerHTML = `<div class="card custom-card"><div class="card-header justify-content-between"><div class="card-title">Repairs</div><button type="button" class="btn btn-sm btn-outline-primary" data-act="repair"><i class="ri-tools-line me-1"></i>Report one</button></div><div class="card-body">${
      list.length
        ? `<ol class="ev-timeline vs-timeline">${list
            .map((j) => {
              const s = F.STATUS[j.status];
              return `<li style="--q: var(--${s[2]}-rgb)"><span class="ev-timeline-dot"></span><div class="flex-fill min-w-0"><span class="ev-timeline-when">${F.day(j.reported_on)}${j.reported_by ? ` · ${F.esc(j.reported_by)}` : ""}</span><span class="ev-timeline-what fw-semibold">${F.esc(j.title)} ${F.statusPill(j.status)}${j.priority === "urgent" && j.status !== "done" ? ' <span class="badge bg-danger">Urgent</span>' : ""}</span>${j.detail ? `<p class="cr-note">${F.esc(j.detail)}</p>` : ""}<span class="mb-sub">${[j.assignee ? `On it: ${F.esc(j.assignee)}` : null, j.cost !== null ? `Cost ${F.money(j.cost)}` : null, j.done_on ? `Done ${F.day(j.done_on)}` : null].filter(Boolean).join(" · ")}</span></div></li>`;
            })
            .join("")}</ol>`
        : '<p class="mb-0 fw-semibold">Nothing has needed fixing.</p>'
    }</div></div>`;
  }

  function historyTab() {
    const h = state.it.history;
    $("itMain").innerHTML = `<div class="card custom-card"><div class="card-header"><div class="card-title">History</div></div><div class="card-body">${
      h.length ? `<ul class="ev-history">${h.map((x) => `<li><span class="ev-history-dot bg-${UI.colorFor(x.who)}"></span><div><strong>${F.esc(x.sentence)}</strong><small>${new Date(x.at).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" })}</small></div></li>`).join("")}</ul>` : '<p class="mb-0 fw-semibold">Nothing changed yet.</p>'
    }</div></div>`;
  }

  function show() {
    document.querySelectorAll("#itTabs [data-tab]").forEach((b) => b.classList.toggle("active", b.dataset.tab === state.tab));
    const q = new URLSearchParams(window.location.search);
    state.tab === "purchase" ? q.delete("tab") : q.set("tab", state.tab);
    history.replaceState(null, "", `${window.location.pathname}?${q}`);
    ({ purchase: purchaseTab, loans: loansTab, repairs: repairsTab, history: historyTab })[state.tab]();
  }

  async function refresh(data = null) {
    if (!data) {
      const res = await FacilitiesAPI.item(id);
      if (!res.ok) return Toast.error(res.message);
      data = res.data;
    }
    state.it = data;
    hero();
    show();
  }

  function act(name) {
    const it = state.it;
    ({
      lend: () => F.lendWindow(it, { onDone: (d) => refresh(d) }),
      ask: () => F.lendWindow(it, { ask: true, onDone: (d) => ((state.tab = "loans"), refresh(d)) }),
      repair: () => F.reportWindow({ about: { equipment_id: id }, onDone: () => refresh() }),
      edit: () => F.itemWindow({ item: it, onDone: () => refresh() }),
      photos: () => ((state.tab = "purchase"), show(), $("phFile")?.click()),
      record: () => F.recordPurchase(it, it.budget_in_use, (d) => refresh(d)),
      link: () => F.linkWindow(it, { onDone: (d) => refresh(d) }),
      unlink: async () => {
        const r = await FacilitiesAPI.unlinkItemExpense(id);
        r.ok ? (Toast.success(r.message), refresh(r.data)) : Toast.error(r.message);
      },
    })[name]?.();
  }

  async function init() {
    if (!id) return ($("itHero").innerHTML = `<div class="card-body">${MembersUI.errorBox("Open an item from Equipment.", `location.href='${CTX.baseUrl}/equipment'`)}</div>`);
    const res = await FacilitiesAPI.item(id);
    if (!res.ok) return ($("itHero").innerHTML = `<div class="card-body">${MembersUI.errorBox(res.message, `location.href='${CTX.baseUrl}/equipment'`)}</div>`);
    document.title = `${res.data.name} - Equipment - Makueni West Diocese`;
    document.querySelector(".page-header-breadcrumb .breadcrumb-item.active")?.replaceChildren(document.createTextNode(res.data.name));
    $("itTabs").hidden = false;
    refresh(res.data);
    $("itTabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-tab]");
      if (!b || b.dataset.tab === state.tab) return;
      state.tab = b.dataset.tab;
      show();
    });
    document.addEventListener("click", async (e) => {
      const a = e.target.closest("#itHero [data-act], #itMain [data-act]");
      if (a) return act(a.dataset.act);
      const open = e.target.closest("[data-open]");
      if (open) {
        // Inside the app: a photo fitted, a PDF in the browser's own reader.
        const list = state.it.receipts;
        return F.fileViewer(list, Math.max(0, list.findIndex((x) => x.id === Number(open.dataset.open))));
      }
      const unr = e.target.closest("[data-unreceipt]");
      if (unr) {
        return Toast.confirm("Remove this receipt?", async () => {
          const r = await FacilitiesAPI.removeReceipt(id, Number(unr.dataset.unreceipt));
          r.ok ? (Toast.success(r.message), refresh(r.data)) : Toast.error(r.message);
        }, null, { title: "Remove receipt", confirmText: "Remove", type: "error" });
      }
      const unp = e.target.closest("[data-unphoto]");
      if (unp) {
        return Toast.confirm("Remove this photo?", async () => {
          const r = await FacilitiesAPI.removePhoto(id, Number(unp.dataset.unphoto));
          r.ok ? (Toast.success(r.message), refresh(r.data)) : Toast.error(r.message);
        }, null, { title: "Remove photo", confirmText: "Remove", type: "error" });
      }
      const first = e.target.closest("[data-first]");
      if (first) {
        const ids = [Number(first.dataset.first), ...state.it.photos.map((p) => p.id).filter((x) => x !== Number(first.dataset.first))];
        const r = await FacilitiesAPI.orderPhotos(id, ids);
        return r.ok ? refresh(r.data) : Toast.error(r.message);
      }
      const back = e.target.closest("[data-back]");
      if (back) {
        UI.setButtonLoading(back, "...");
        const r = await FacilitiesAPI.giveBack(Number(back.dataset.back));
        UI.restoreButton(back);
        r.ok ? (Toast.success(r.message), refresh(r.data)) : Toast.error(r.message);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
