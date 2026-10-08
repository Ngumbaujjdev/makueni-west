/**
 * ============================================================================
 * DATE FIELDS (2026-10-08)
 * ============================================================================
 * The browser's own date box shows 08/10/2026 - 8 October or 10 August? This
 * turns an <input type="date"> into a plain white field that reads
 * "8 Oct 2026", with a calendar tile and optional one-tap chips (Today,
 * Last Sunday, In 3 days...). The input keeps its id and its yyyy-mm-dd
 * value, so the code that reads it doesn't change. Uses flatpickr
 * (assets/libs/flatpickr) when the page loads it, else stays a date box.
 *
 *   DateField.enhance(input, { quick: ["today", "yesterday", "lastSunday"] })
 *   DateField.set(input, "2026-10-04")
 * ============================================================================
 */
const DateField = (function () {
  "use strict";

  const pad = (n) => String(n).padStart(2, "0");
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const shift = (n) => {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return iso(d);
  };

  /** The one-tap chips: label and the date they set. */
  const QUICK = {
    today: () => ["Today", shift(0)],
    yesterday: () => ["Yesterday", shift(-1)],
    // The most recent Sunday - today, when it is Sunday.
    lastSunday: () => [new Date().getDay() === 0 ? "This Sunday" : "Last Sunday", shift(-new Date().getDay())],
    in3: () => ["In 3 days", shift(3)],
    nextSunday: () => ["Next Sunday", shift((7 - new Date().getDay()) % 7 || 7)],
    in14: () => ["In 2 weeks", shift(14)],
  };

  function set(input, value) {
    if (!input) return;
    if (input._flatpickr) input._flatpickr.setDate(value || null, true);
    else {
      input.value = value || "";
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  function enhance(input, { quick = [] } = {}) {
    if (!input || input.dataset.dateField) return input;
    input.dataset.dateField = "1";

    const wrap = document.createElement("div");
    wrap.className = "date-field";
    input.parentNode.insertBefore(wrap, input);
    wrap.innerHTML = '<span class="date-field-icon" aria-hidden="true"><i class="ri-calendar-2-line"></i></span>';
    wrap.appendChild(input);

    if (typeof flatpickr === "function") {
      const fp = flatpickr(input, {
        altInput: true,
        altFormat: "j M Y",
        dateFormat: "Y-m-d",
        minDate: input.min || null,
        maxDate: input.max || null,
        disableMobile: true,
        // Inside a window the calendar stays in the window, so Bootstrap's focus trap lets it be used.
        static: !!input.closest(".modal"),
        onChange: () => paint(),
      });
      const label = input.getAttribute("aria-label") || (input.id && document.querySelector(`label[for="${input.id}"]`)?.textContent.trim());
      if (label) fp.altInput.setAttribute("aria-label", label);
      if (input.id) {
        // Its <label for> now points at the hidden input - send clicks to the one people see.
        document.querySelectorAll(`label[for="${input.id}"]`).forEach((l) => l.addEventListener("click", (e) => (e.preventDefault(), fp.altInput.focus(), fp.open())));
      }
    }

    let chips = null;
    const items = quick.filter((k) => QUICK[k]).map((k) => QUICK[k]());
    if (items.length) {
      chips = document.createElement("div");
      chips.className = "date-field-quick";
      chips.innerHTML = items
        .filter(([, v]) => (!input.min || v >= input.min) && (!input.max || v <= input.max))
        .map(([label, v]) => `<button type="button" class="date-chip" data-date="${v}">${label}</button>`)
        .join("");
      wrap.after(chips);
      chips.addEventListener("click", (e) => {
        const b = e.target.closest("[data-date]");
        if (b) set(input, b.dataset.date);
      });
    }

    function paint() {
      chips?.querySelectorAll("[data-date]").forEach((b) => b.classList.toggle("is-on", b.dataset.date === input.value));
    }
    if (!input._flatpickr) input.addEventListener("change", paint);
    paint();
    return input;
  }

  return { enhance, set, iso };
})();

window.DateField = DateField;
