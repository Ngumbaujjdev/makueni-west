/**
 * ============================================================================
 * MEMBERS - shared look (docs/specs/people-and-care-spec.md, P1)
 * ============================================================================
 * Status pills, the initials-or-photo avatar, dates and the "Private to our
 * church" chip, used by every Members page.
 * ============================================================================
 */
const MembersUI = (function () {
  "use strict";

  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  const STATUS = {
    member: { label: "Member", color: "success" },
    visitor: { label: "Visitor", color: "info" },
    inactive: { label: "Inactive", color: "secondary" },
    transferred_out: { label: "Transferred out", color: "purple" },
    deceased: { label: "Deceased", color: "dark" },
  };
  const HOW_JOINED = { conversion: "Conversion", transfer: "Transfer", baptism: "Baptism", birth: "Born into the church", other: "Other" };
  const MARITAL = { single: "Single", married: "Married", widowed: "Widowed", divorced: "Divorced", other: "Other" };
  const BANDS = { children: "Children", youth: "Youth", adults: "Adults", seniors: "Seniors" };
  const AVATAR_COLORS = ["primary", "success", "purple", "pink", "secondary", "info"];

  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");
  const statusPill = (s) => {
    const m = STATUS[s] || { label: s, color: "light" };
    return `<span class="badge bg-${m.color} ${textOn(m.color)}">${esc(m.label)}</span>`;
  };
  /** A stable colour per person, so their initials look the same everywhere. */
  const colorFor = (id) => AVATAR_COLORS[Math.abs(Number(id) || 0) % AVATAR_COLORS.length];

  /** Initials now; the photo swaps in once it loads (photos need the sign-in, so they come as blobs). */
  function avatar(p, size = "md") {
    const c = colorFor(p.id);
    return `<span class="avatar avatar-${size} avatar-rounded bg-${c} ${textOn(c)} mb-avatar" data-person-photo="${p.has_photo ? p.id : ""}">${esc(p.initials || "?")}</span>`;
  }
  async function loadPhotos(root = document) {
    for (const el of root.querySelectorAll("[data-person-photo]:not([data-person-photo=''])")) {
      const src = await MembersAPI.photoUrl(Number(el.dataset.personPhoto));
      if (src) el.innerHTML = `<img src="${src}" alt="">`;
      el.removeAttribute("data-person-photo");
    }
  }

  const day = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "-");
  const num = (n) => Number(n || 0).toLocaleString("en-GB");
  const privateChip = () => '<span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span>';
  const empty = (icon, title, text, action = "") =>
    `<div class="mb-empty"><span class="avatar avatar-lg avatar-rounded bg-primary text-white mb-2"><i class="${icon} fs-20"></i></span><h6 class="mb-1">${title}</h6><p class="mb-3">${text}</p>${action}</div>`;
  const errorBox = (message, retry = "location.reload()") =>
    `<div class="alert alert-danger d-flex align-items-center gap-2 mb-0"><i class="ri-error-warning-line"></i><span class="flex-fill">${esc(message)}</span><button type="button" class="btn btn-sm btn-danger" onclick="${retry}">Try again</button></div>`;

  return { esc, STATUS, HOW_JOINED, MARITAL, BANDS, textOn, statusPill, colorFor, avatar, loadPhotos, day, num, privateChip, empty, errorBox };
})();

window.MembersUI = MembersUI;
