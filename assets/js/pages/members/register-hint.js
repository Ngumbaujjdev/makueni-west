/**
 * ============================================================================
 * MEMBERS - the register's hint on the Demographics form
 * ============================================================================
 * Beside Total members, Male, Female, Youth, New members and Baptisms, a line
 * "From your register: N · Use" (docs/specs/people-and-care-spec.md, P1).
 * Demographics stays the church's own count: the hint never fills a box in
 * by itself - "Use" copies the number when the pastor wants it. Shows
 * nothing when the church keeps no register or the role can't read it.
 * ============================================================================
 */
(function () {
  "use strict";

  const FIELDS = { total_members: "total", male_count: "male", female_count: "female", youth_count: "youth", new_members_count: "new_this_month", baptisms_count: "baptised_this_month" };

  async function init() {
    if (typeof AppConfig === "undefined" || !document.getElementById("total_members")) return;
    const h = { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* the API uses the primary role */
    }
    let counts = null;
    try {
      const res = await fetch(`${AppConfig.API_BASE_URL}/people/register-counts`, { headers: h });
      if (!res.ok) return;
      counts = (await res.json()).data;
    } catch (e) {
      return;
    }
    if (!counts || !counts.total) return;
    Object.entries(FIELDS).forEach(([id, key]) => {
      const input = document.getElementById(id);
      const tile = input?.closest(".num-tile");
      if (!tile || tile.querySelector(".mb-register-hint")) return;
      tile.insertAdjacentHTML("beforeend", `<div class="mb-register-hint"><i class="ri-contacts-book-2-line"></i>From your register: <strong>${Number(counts[key] || 0).toLocaleString("en-GB")}</strong> · <button type="button" class="btn btn-link p-0 align-baseline" data-use="${counts[key] || 0}">Use</button></div>`);
      tile.querySelector("[data-use]").addEventListener("click", (e) => {
        input.value = e.currentTarget.dataset.use;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });
  }

  document.addEventListener("DOMContentLoaded", () => setTimeout(init, 300));
})();
