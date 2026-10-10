/**
 * ============================================================================
 * ACCOUNTING - shared look (docs/specs/accounting-spec.md)
 * ============================================================================
 * Money, dates, the tile for each kind of account (cash, petty cash, bank,
 * M-Pesa), the pill for each document and voucher status, the read-only
 * note when looking at a place below, and the "Whose books" picker for a
 * region or the diocese - one look for every level.
 * ============================================================================
 */
const AccountingUI = (function () {
  "use strict";

  const UI = DemographicsUI;
  const CTX = window.ACC_CTX;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");

  /** KES 12,400.00 - negatives in brackets, as accountants write them. */
  function money(n, { cents = true, sign = false } = {}) {
    const v = Number(n || 0);
    const s = Math.abs(v).toLocaleString("en-GB", { minimumFractionDigits: cents ? 2 : 0, maximumFractionDigits: cents ? 2 : 0 });
    if (v < 0) return sign ? `-KES ${s}` : `(KES ${s})`;
    return `KES ${s}`;
  }
  /**
   * Amounts are always written in full (2026-10-10: no "12k" / "1.2m"). The
   * card figure keeps the full number with a small "KES" in front of it.
   */
  const short = (n) => money(n);
  function figure(n) {
    const v = Number(n || 0);
    const s = Math.abs(v).toLocaleString("en-GB", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `<span class="acc-figure${v < 0 ? " is-neg" : ""}"><small>KES</small>${v < 0 ? "-" : ""}${s}</span>`;
  }
  const amount = (n) => (Number(n) ? Number(n).toLocaleString("en-GB", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : "");
  const day = (iso, opts = { day: "numeric", month: "short", year: "numeric" }) => (iso ? new Date(`${String(iso).slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", opts) : "-");
  const num = (n) => Number(n || 0).toLocaleString("en-GB");

  /** Each kind of money account: its icon and colour. */
  const KINDS = {
    cash: { icon: "ri-money-dollar-box-line", color: "success", label: "Cash" },
    petty_cash: { icon: "ri-wallet-3-line", color: "warning", label: "Petty cash" },
    bank: { icon: "ri-bank-line", color: "primary", label: "Bank" },
    mpesa: { icon: "ri-smartphone-line", color: "success", label: "M-Pesa" },
    airtel: { icon: "ri-smartphone-line", color: "danger", label: "Airtel Money" },
  };
  const kind = (k) => KINDS[k] || { icon: "ri-coins-line", color: "secondary", label: "Account" };

  /**
   * The real marks (2026-10-10): M-Pesa, Airtel Money, and Visa + Mastercard
   * for card - official logos from Wikimedia Commons in
   * assets/images/payments; an icon for cash, bank and cheque.
   */
  const LOGOS = { mpesa: ["mpesa.svg", "M-Pesa"], airtel: ["airtel-money.svg", "Airtel Money"] };
  const METHOD_ICONS = { cash: ["ri-money-dollar-circle-line", "success"], petty_cash: ["ri-wallet-3-line", "warning"], bank: ["ri-bank-line", "primary"], cheque: ["ri-file-paper-2-line", "warning"] };
  const imgBase = () => `${CTX.siteUrl || ""}/assets/images/payments/`;
  function methodLogo(m, size = "sm") {
    if (m === "card") return `<span class="acc-logo acc-logo-${size} is-card" title="Card (Visa, Mastercard)"><img src="${imgBase()}visa.svg" alt="Visa"><img src="${imgBase()}mastercard.svg" alt="Mastercard"></span>`;
    if (LOGOS[m]) return `<span class="acc-logo acc-logo-${size}" title="${LOGOS[m][1]}"><img src="${imgBase()}${LOGOS[m][0]}" alt="${LOGOS[m][1]}"></span>`;
    const [icon, color] = METHOD_ICONS[m] || ["ri-coins-line", "secondary"];
    return `<span class="acc-logo acc-logo-${size} is-icon bg-${color} ${textOn(color)}"><i class="${icon}"></i></span>`;
  }
  const tile = (k, size = "md") => {
    if (LOGOS[k]) return `<span class="avatar avatar-${size} avatar-rounded acc-logo-avatar flex-shrink-0"><img src="${imgBase()}${LOGOS[k][0]}" alt="${LOGOS[k][1]}"></span>`;
    const m = kind(k);
    return `<span class="avatar avatar-${size} avatar-rounded bg-${m.color} ${textOn(m.color)} flex-shrink-0"><i class="${m.icon}"></i></span>`;
  };

  /** Documents: money in is green, out red, the rest neutral. */
  const DOCS = {
    receipt: { label: "Receipt", color: "success", icon: "ri-arrow-down-circle-line" },
    payment: { label: "Payment", color: "danger", icon: "ri-arrow-up-circle-line" },
    transfer: { label: "Transfer", color: "primary", icon: "ri-arrow-left-right-line" },
    journal: { label: "Journal", color: "purple", icon: "ri-book-2-line" },
    reversal: { label: "Reversal", color: "secondary", icon: "ri-arrow-go-back-line" },
    bill: { label: "Supplier bill", color: "warning", icon: "ri-file-list-2-line" },
    payroll: { label: "Payroll", color: "pink", icon: "ri-money-dollar-box-line" },
  };
  const doc = (t) => DOCS[t] || DOCS.journal;
  const docPill = (t) => `<span class="badge bg-${doc(t).color} ${textOn(doc(t).color)}"><i class="${doc(t).icon} me-1"></i>${doc(t).label}</span>`;
  const docTile = (t, size = "sm") => `<span class="avatar avatar-${size} avatar-rounded bg-${doc(t).color} ${textOn(doc(t).color)} flex-shrink-0"><i class="${doc(t).icon}"></i></span>`;

  const VOUCHER = {
    prepared: { label: "Waiting to be authorised", short: "Waiting", color: "warning", icon: "ri-time-line" },
    authorised: { label: "Authorised - to pay", short: "To pay", color: "primary", icon: "ri-shield-check-line" },
    paid: { label: "Paid", short: "Paid", color: "success", icon: "ri-checkbox-circle-line" },
    rejected: { label: "Sent back", short: "Sent back", color: "danger", icon: "ri-arrow-go-back-line" },
    cancelled: { label: "Cancelled", short: "Cancelled", color: "secondary", icon: "ri-close-circle-line" },
  };
  const voucherPill = (s, long = false) => {
    const m = VOUCHER[s] || VOUCHER.prepared;
    return `<span class="badge bg-${m.color} ${textOn(m.color)}"><i class="${m.icon} me-1"></i>${long ? m.label : m.short}</span>`;
  };

  const METHOD_LABELS = { cash: "Cash", mpesa: "M-Pesa", airtel: "Airtel Money", bank: "Bank", cheque: "Cheque", card: "Card" };
  const methodChip = (m, label) => (m ? `<span class="acc-method">${methodLogo(m, "xs")}<span>${esc(label || METHOD_LABELS[m] || m)}</span></span>` : "");

  /** A date as a small calendar tile (today in solid colour). */
  function dateTile(iso) {
    if (!iso) return "";
    const d = new Date(`${String(iso).slice(0, 10)}T12:00:00`);
    const today = new Date().toISOString().slice(0, 10) === String(iso).slice(0, 10);
    return `<span class="acc-date-tile${today ? " is-today" : ""}"><strong>${d.getDate()}</strong><small>${d.toLocaleDateString("en-GB", { month: "short" })}</small></span>`;
  }
  /** "Today", "Yesterday", "3 days ago", or the year when it is older. */
  function since(iso) {
    const days = Math.round((new Date(new Date().toISOString().slice(0, 10)) - new Date(String(iso).slice(0, 10))) / 86400000);
    return days <= 0 ? "Today" : days === 1 ? "Yesterday" : days < 31 ? `${days} days ago` : day(iso, { month: "short", year: "numeric" });
  }
  /** Money in green with an arrow down, out red with an arrow up, a transfer neutral. */
  function signedAmount(docType, amount) {
    const dir = { receipt: "in", payment: "out", bill: "out", payroll: "out" }[docType];
    const icon = dir === "in" ? "ri-arrow-left-down-line" : dir === "out" ? "ri-arrow-right-up-line" : "ri-arrow-left-right-line";
    return `<span class="acc-signed is-${dir || "neutral"}"><i class="${icon}"></i>${money(amount)}</span>`;
  }
  /** Which record page a posted document belongs to, if any. */
  function sourceRecord(j) {
    const t = { payment_voucher: "voucher", collection: "collection", collection_banking: "collection", payroll_run: "payroll" }[j.source];
    return t && j.source_id ? { type: t, id: j.source_id } : null;
  }
  const reversedChip = () => '<span class="soft-chip soft-danger"><i class="ri-arrow-go-back-line"></i>Reversed</span>';

  /** Is the page looking at a place below (read-only)? */
  const viewingBelow = () => !!new URLSearchParams(window.location.search).get("territory_id") && Number(new URLSearchParams(window.location.search).get("territory_id")) !== CTX.place.id;

  /** The line under the page title: the place, and a read-only chip when it's one below. */
  function placeLine(el, place) {
    if (!el) return;
    const below = place && place.id !== CTX.place.id;
    el.innerHTML = `<span class="fw-semibold">${esc(place?.name || CTX.place.name)}</span>${below ? '<span class="soft-chip soft-warning"><i class="ri-eye-line"></i>Viewing only - their own books</span>' : '<span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Our books</span>'}`;
  }

  /**
   * "Whose books" for a region or the diocese: our own and every place
   * below. Switching keeps the page and reloads its data (?territory_id=).
   */
  async function placePicker(holder, onChange) {
    if (!holder || !CTX.can.below) return;
    const res = await AccountingAPI.places();
    if (!res.ok || res.data.length < 2) return;
    const current = String(new URLSearchParams(window.location.search).get("territory_id") || CTX.place.id);
    holder.innerHTML = `<select class="form-select" id="accPlace" aria-label="Whose books">${res.data
      .map((p) => `<option value="${p.id}" data-icon="${p.own ? "ri-home-4-line" : p.level === "region" ? "ri-map-pin-line" : "ri-community-line"}" data-color="${p.own ? "primary" : p.level === "region" ? "purple" : "success"}"${String(p.id) === current ? " selected" : ""}>${esc(p.own ? `${p.name} (ours)` : p.parent && p.level === "church" ? `${p.name} - ${p.parent}` : p.name)}</option>`)
      .join("")}</select>`;
    holder.hidden = false;
    const sel = holder.querySelector("select");
    UI.enhanceSelect(sel, { search: res.data.length > 8 });
    sel.addEventListener("change", () => {
      const p = new URLSearchParams(window.location.search);
      if (Number(sel.value) === CTX.place.id) p.delete("territory_id");
      else p.set("territory_id", sel.value);
      history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
      onChange();
    });
  }

  /** The write buttons on a page show only for our own books; links to other pages keep the place. */
  function ownOnly(root = document) {
    const below = viewingBelow();
    root.querySelectorAll("[data-own-only]").forEach((el) => (el.hidden = below));
    root.querySelectorAll("a[data-keep-place]").forEach((a) => {
      const u = new URL(a.href, window.location.href);
      const t = new URLSearchParams(window.location.search).get("territory_id");
      t ? u.searchParams.set("territory_id", t) : u.searchParams.delete("territory_id");
      a.href = u.toString();
    });
  }

  let optionsCache = null;
  /** What the forms pick from (accounts, funds, budget lines) - once per page and place. */
  async function options(force = false) {
    const key = new URLSearchParams(window.location.search).get("territory_id") || "";
    if (!force && optionsCache && optionsCache.key === key) return optionsCache.data;
    const res = await AccountingAPI.options();
    if (!res.ok) {
      Toast.error(res.message);
      return null;
    }
    optionsCache = { key, data: res.data };
    return res.data;
  }

  const empty = (icon, title, text, action = "") =>
    `<div class="mb-empty"><span class="avatar avatar-lg avatar-rounded bg-primary text-white mb-2"><i class="${icon} fs-20"></i></span><h6 class="mb-1">${esc(title)}</h6><p class="mb-3">${esc(text)}</p>${action}</div>`;
  const errorBox = (message) => `<div class="alert alert-danger d-flex align-items-center gap-2 mb-0"><i class="ri-error-warning-line"></i><span class="flex-fill">${esc(message)}</span><button type="button" class="btn btn-sm btn-danger" onclick="location.reload()">Try again</button></div>`;

  /** The link to open a place below on another Accounting page, keeping the place. */
  const link = (page, params = {}) => {
    const p = new URLSearchParams();
    const t = new URLSearchParams(window.location.search).get("territory_id");
    if (t) p.set("territory_id", t);
    Object.entries(params).forEach(([k, v]) => v !== undefined && v !== null && v !== "" && p.set(k, v));
    return `${CTX.baseUrl}/${page}${p.toString() ? `?${p}` : ""}`;
  };

  /**
   * An approval's stages as a timeline: each stage, who was asked, what they
   * decided and said - for the Approvals, Requisitions and voucher windows.
   */
  function approvalTimeline(ap) {
    if (!ap) return "";
    const P = {
      approved: ["success", "ri-check-line", "Approved"],
      rejected: ["danger", "ri-close-line", "Rejected"],
      returned: ["danger", "ri-arrow-go-back-line", "Sent back"],
      pending: ["warning", "ri-time-line", "Waiting"],
      skipped: ["secondary", "ri-subtract-line", "Not needed"],
      superseded: ["secondary", "ri-arrow-up-line", "Passed up"],
    };
    const S = { pending: "Not yet", active: "Now", approved: "Approved", rejected: "Stopped here", skipped: "Not needed", blocked: "Stuck - nobody holds the role" };
    const stages = (ap.stages || [])
      .map((st, i) => {
        const people = st.people.length
          ? st.people
              .map((p) => {
                const [c, icon, label] = P[p.status] || P.pending;
                return `<div class="acc-tl-person"><span class="avatar avatar-xs avatar-rounded bg-${c} ${textOn(c)}"><i class="${icon}"></i></span><div class="min-w-0"><div><strong>${esc(p.name || "")}</strong>${p.for ? ` <small>for ${esc(p.for)}</small>` : ""}${p.escalated ? ' <span class="soft-chip soft-purple">passed up</span>' : ""}</div><small>${esc(label)}${p.decided_at ? ` · ${day(p.decided_at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}` : ""}${p.due_at ? ` · due ${day(p.due_at, { day: "numeric", month: "short" })}` : ""}</small>${p.comment ? `<div class="acc-tl-comment">"${esc(p.comment)}"</div>` : ""}</div></div>`;
              })
              .join("")
          : `<small class="acc-sub">${st.blocked_reason ? esc(st.blocked_reason) : "Not reached yet"}</small>`;
        return `<li class="acc-tl-stage is-${st.status}"><span class="acc-tl-dot">${i + 1}</span><div class="flex-fill min-w-0"><div class="d-flex justify-content-between gap-2"><strong>${esc(st.name)}</strong><span class="acc-sub">${esc(S[st.status] || st.status)}${st.type === "all" ? " · everyone" : ""}</span></div>${people}</div></li>`;
      })
      .join("");
    return `<ol class="acc-tl">${stages}</ol>`;
  }

  /**
   * The journey (2026-10-10): a record's steps as a lane, left to right -
   * done (green), now (gold, pulsing), next (grey), stopped (red), blocked
   * (nobody holds the role), skipped. Each step: {title, state, icon, who, when, note}.
   */
  const STEP = { done: "ri-check-line", now: "ri-time-line", next: "ri-more-line", stopped: "ri-close-line", blocked: "ri-error-warning-line", skipped: "ri-subtract-line" };
  const when = (iso) => (iso ? new Date(iso.length > 10 ? iso : `${iso}T12:00:00`).toLocaleString("en-GB", iso.length > 10 ? { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" } : { day: "numeric", month: "short", year: "numeric" }) : "");
  function journey(steps, { compact = false } = {}) {
    const items = steps
      .filter(Boolean)
      .map((st, i) => {
        const state = st.state || "next";
        const icon = state === "done" || state === "next" ? st.icon || STEP[state] : STEP[state];
        return `<li class="acc-jstep is-${state}"><span class="acc-jdot"><i class="${icon}"></i></span><div class="acc-jtext"><span class="acc-jn">Step ${i + 1}</span><strong>${esc(st.title)}</strong>${!compact && st.who ? `<small class="acc-jwho">${esc(st.who)}</small>` : ""}${!compact && st.when ? `<small>${esc(when(st.when))}</small>` : ""}${!compact && st.note ? `<small class="acc-jnote">${esc(st.note)}</small>` : ""}</div></li>`;
      })
      .join("");
    return `<ol class="acc-journey${compact ? " is-compact" : ""}">${items}</ol>`;
  }

  /**
   * A compact journey for a table row: the step names, how far it has got
   * (`at` = the step happening now; at >= length means all done) and,
   * when it stopped (sent back, cancelled), the label of the stop.
   */
  function mini(labels, at, { stop = null } = {}) {
    const steps = labels.map((title, i) => ({ title, state: i < at ? "done" : i === at ? (stop ? "stopped" : "now") : "next" }));
    if (stop && steps[at]) steps[at].title = stop;
    return journey(steps, { compact: true });
  }

  /** An approval request's stages as journey steps (the engine's stages, in order). */
  function approvalSteps(ap) {
    if (!ap || !ap.stages) return [];
    return ap.stages.map((st) => {
      const live = st.people.filter((p) => !p.superseded);
      const decided = live.filter((p) => ["approved", "rejected", "returned"].includes(p.status));
      const last = decided.sort((a, b) => String(b.decided_at).localeCompare(String(a.decided_at)))[0];
      const state = { approved: "done", active: "now", pending: "next", rejected: "stopped", returned: "stopped", skipped: "skipped", blocked: "blocked" }[st.status] || "next";
      const waiting = live.filter((p) => p.status === "pending").map((p) => p.name);
      return {
        title: st.name,
        state,
        icon: "ri-shield-check-line",
        who: state === "now" ? `Waiting on ${waiting.join(", ") || "-"}` : state === "blocked" ? st.blocked_reason || "Nobody holds the role" : decided.map((p) => p.name).join(", ") || (state === "next" ? "Not reached yet" : ""),
        when: last?.decided_at || null,
        note: last?.comment ? `"${last.comment}"` : "",
      };
    });
  }

  /**
   * "What happens next", in plain words. tone: mine (the viewer acts - solid
   * gold, like the screenshot's "Do first"), wait (someone else), done, stopped.
   */
  function nextCard({ tone = "wait", title, text, actions = "" }) {
    const T = { mine: ["ri-flashlight-line", "Your turn"], wait: ["ri-time-line", "Waiting"], done: ["ri-checkbox-circle-line", "Done"], stopped: ["ri-arrow-go-back-line", "Stopped"] }[tone];
    return `<div class="acc-next is-${tone}"><span class="acc-next-icon"><i class="${T[0]}"></i></span><div class="acc-next-text"><span class="acc-next-tag">${T[1]}</span><strong>${esc(title)}</strong>${text ? `<p>${esc(text)}</p>` : ""}</div>${actions ? `<div class="acc-next-actions">${actions}</div>` : ""}</div>`;
  }

  /**
   * Key data in colour (2026-10-10, "too much black"): a person as an
   * initials circle and their name, a date with its calendar icon, an
   * account with its kind tile. Each name keeps the same colour everywhere.
   */
  const PERSON_COLORS = ["primary", "success", "purple", "pink", "warning", "info", "danger"];
  const initials = (name) => String(name || "?").trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();
  const personColor = (name) => PERSON_COLORS[[...String(name || "")].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7) % PERSON_COLORS.length];
  function person(name, { size = "xs" } = {}) {
    if (!name) return "-";
    const c = personColor(name);
    return `<span class="acc-person"><span class="avatar avatar-${size} avatar-rounded bg-${c} ${textOn(c)}">${esc(initials(name))}</span><span>${esc(name)}</span></span>`;
  }
  /** Just the initials circle. */
  const avatar = (name, size = "md") => {
    const c = personColor(name);
    return `<span class="avatar avatar-${size} avatar-rounded bg-${c} ${textOn(c)} flex-shrink-0 acc-initials">${esc(initials(name))}</span>`;
  };
  const dateChip = (iso, opts) => (iso ? `<span class="acc-date"><i class="ri-calendar-line"></i>${day(iso, opts)}</span>` : "-");
  function accountChip(acc) {
    if (!acc) return "-";
    if (LOGOS[acc.kind]) return `<span class="acc-acct">${methodLogo(acc.kind, "xs")}<span>${esc(acc.name)}</span></span>`;
    const m = kind(acc.kind);
    return `<span class="acc-acct"><span class="acc-acct-tile bg-${m.color} ${textOn(m.color)}"><i class="${m.icon}"></i></span><span>${esc(acc.name)}</span></span>`;
  }

  /**
   * A diocese PDF (Redesign R4): the export window, locked to one Accounting
   * report, for the place being viewed - the letterhead, QR and page numbers
   * come from the report engine (Settings > Documents & PDF).
   */
  function pdf(reportKey, params = {}, title = null) {
    if (typeof ReportCenter === "undefined") return Toast.error("Reports are still loading - try again in a moment.");
    const below = new URLSearchParams(window.location.search).get("territory_id");
    ReportCenter.open({ territoryId: Number(below) || CTX.place.id, reportKey, module: "accounting", params, locked: true, title });
  }

  return { pdf, approvalTimeline, methodLogo, dateTile, since, signedAmount, sourceRecord, person, avatar, personColor, initials, dateChip, accountChip, journey, mini, approvalSteps, nextCard, esc, textOn, money, short, figure, amount, day, num, KINDS, kind, tile, DOCS, doc, docPill, docTile, VOUCHER, voucherPill, methodChip, reversedChip, viewingBelow, placeLine, placePicker, ownOnly, options, empty, errorBox, link };
})();

window.AccountingUI = AccountingUI;
