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
  /** 12.4k / 1.2m for cards. */
  function short(n) {
    const v = Math.abs(Number(n || 0));
    const neg = Number(n) < 0 ? "-" : "";
    if (v >= 1e6) return `${neg}KES ${(v / 1e6).toFixed(v >= 1e7 ? 0 : 1)}m`;
    if (v >= 1e4) return `${neg}KES ${Math.round(v / 1e3).toLocaleString("en-GB")}k`;
    return `${neg}KES ${v.toLocaleString("en-GB", { maximumFractionDigits: 0 })}`;
  }
  const amount = (n) => (Number(n) ? Number(n).toLocaleString("en-GB", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : "");
  const day = (iso, opts = { day: "numeric", month: "short", year: "numeric" }) => (iso ? new Date(`${String(iso).slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", opts) : "-");
  const num = (n) => Number(n || 0).toLocaleString("en-GB");

  /** Each kind of money account: its icon and colour. */
  const KINDS = {
    cash: { icon: "ri-money-dollar-box-line", color: "success", label: "Cash" },
    petty_cash: { icon: "ri-wallet-3-line", color: "warning", label: "Petty cash" },
    bank: { icon: "ri-bank-line", color: "primary", label: "Bank" },
    mpesa: { icon: "ri-smartphone-line", color: "purple", label: "M-Pesa" },
  };
  const kind = (k) => KINDS[k] || { icon: "ri-coins-line", color: "secondary", label: "Account" };
  const tile = (k, size = "md") => {
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

  const methodChip = (m, label) => (m ? `<span class="soft-chip soft-${{ cash: "success", mpesa: "purple", bank: "primary", cheque: "warning" }[m] || "primary"}">${esc(label || { cash: "Cash", mpesa: "M-Pesa", bank: "Bank", cheque: "Cheque" }[m])}</span>` : "");
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

  return { approvalTimeline, esc, textOn, money, short, amount, day, num, KINDS, kind, tile, DOCS, doc, docPill, docTile, VOUCHER, voucherPill, methodChip, reversedChip, viewingBelow, placeLine, placePicker, ownOnly, options, empty, errorBox, link };
})();

window.AccountingUI = AccountingUI;
