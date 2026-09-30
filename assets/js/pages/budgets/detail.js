/**
 * ============================================================================
 * PAGE - ONE BUDGET (includes/budget/budget.php, every level)
 * ============================================================================
 * Header with what you can do (Change, Start using, Close, Reopen, Delete -
 * only what the API says is allowed), the year's figures, each line's
 * planned amount and how much came in or went out, and the History.
 * Opened from a level above, it's view only. Actions update in place.
 * ============================================================================
 */
const BudgetsDetail = (function () {
  "use strict";

  const UI = DemographicsUI;
  const B = BudgetsUI;
  const id = Number(new URLSearchParams(window.location.search).get("id"));
  let d = null;
  let whereSide = "out";
  let donut = null;

  async function init() {
    B.showFlash();
    if (!id) {
      showError("No budget was chosen.");
      return;
    }
    wireActions();
    wireTabs();
    const res = await BudgetsAPI.get(id);
    if (!res.ok) {
      showError(res.message);
      return;
    }
    render(res.data);
    loadHistory();
  }

  function render(data) {
    d = data;
    const b = d.budget;
    document.title = `${b.period_label} budget - Makueni West Diocese`;
    document.getElementById("budgetIcon").innerHTML = `<span class="avatar avatar-md bg-${b.period_month ? "primary" : "purple"} text-white"><i class="${b.period_month ? "ri-calendar-line" : "ri-calendar-2-line"}"></i></span>`;
    document.getElementById("budgetTitle").textContent = `${b.period_label} budget`;
    const when = (iso) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");
    const bits = [
      B.esc(b.place?.name || ""),
      B.statusPill(b.status),
      b.prepared_by ? `Prepared by ${B.esc(b.prepared_by)}` : "",
      b.status === "active" && b.started_by ? `In use since ${when(b.started_at)} (${B.esc(b.started_by)})` : "",
      b.status === "closed" && b.closed_by ? `Closed ${when(b.closed_at)} by ${B.esc(b.closed_by)}` : "",
    ].filter(Boolean);
    document.getElementById("budgetSub").innerHTML = bits.join(" · ");

    // Only what's allowed
    const can = d.can || {};
    document.getElementById("editBtn").hidden = !can.edit;
    document.getElementById("editBtn").href = B.url("form.php", { id: b.id });
    document.getElementById("startBtn").hidden = !can.start;
    document.getElementById("closeBtn").hidden = !can.close;
    document.getElementById("reopenBtn").hidden = !can.reopen;
    document.getElementById("deleteBtn").hidden = !can.delete;

    const viewOnly = document.getElementById("viewOnlyBanner");
    viewOnly.classList.toggle("d-none", !d.view_only);
    viewOnly.classList.toggle("d-flex", !!d.view_only);
    if (d.view_only) {
      document.getElementById("viewOnlyText").textContent = `This is ${b.place?.name}'s budget. Only ${b.place?.name} can change it.`;
      document.getElementById("backBtn").href = B.url("budgets.php", { territory_id: b.place.id, year: b.fiscal_year });
    }
    const draft = document.getElementById("draftBanner");
    const showDraft = b.status === "draft" && !d.view_only;
    draft.classList.toggle("d-none", !showDraft);
    draft.classList.toggle("d-flex", showDraft);

    renderStats(b);
    renderWhere();
    renderStatusCard(b);
    renderLines("in", d.lines.in);
    renderLines("out", d.lines.out);
    document.querySelector('[data-tab-figure="lines"]').textContent = `${d.lines.in.length + d.lines.out.length} lines`;

    const notesCard = document.getElementById("notesCard");
    notesCard.hidden = !b.notes;
    if (b.notes) document.getElementById("budgetNotes").textContent = b.notes;
  }

  function renderStats(b) {
    const left = b.in_planned - b.out_planned;
    const prev = d.previous;
    const vs = prev?.period_label;
    const pct = (a, p) => (p > 0 ? `${Math.round((a / p) * 100)}% of planned` : "");
    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-arrow-down-circle-line",
        label: "Money in (planned)",
        value: B.money(b.in_planned),
        color: "success",
        delta: prev ? B.delta(b.in_planned, prev.in_planned, vs) : null,
        sub: b.in_actual ? `Received ${B.money(b.in_actual)} · ${pct(b.in_actual, b.in_planned)}` : "Nothing received yet",
      },
      {
        icon: "ri-arrow-up-circle-line",
        label: "Money out (planned)",
        value: B.money(b.out_planned),
        color: "danger",
        delta: prev ? B.delta(b.out_planned, prev.out_planned, vs) : null,
        sub: b.out_actual ? `Spent ${B.money(b.out_actual)} · ${pct(b.out_actual, b.out_planned)}` : "Nothing spent yet",
      },
      {
        icon: "ri-scales-3-line",
        label: "Money left (planned)",
        value: B.money(left),
        color: left < 0 ? "danger" : "purple",
        delta: prev ? B.delta(left, prev.left_planned, vs) : null,
        sub: left < 0 ? "Planning to spend more than comes in" : "Money in minus money out",
      },
      {
        icon: "ri-list-check-2",
        label: "Lines",
        value: d.lines.in.length + d.lines.out.length,
        color: "primary",
        sub: `${d.lines.in.length} money in · ${d.lines.out.length} money out`,
      },
    ]);
  }

  /** "Where the money goes" - the top five lines of this budget and the rest. */
  function renderWhere() {
    const wrap = document.getElementById("whereSwitchWrap");
    if (!wrap.innerHTML) {
      wrap.innerHTML = UI.renderSegmented("whereSwitch", [{ value: "out", label: "Out" }, { value: "in", label: "In" }], whereSide, { ariaLabel: "Money in or out" });
      UI.wireSegmented("whereSwitch", (value) => {
        whereSide = value;
        renderWhere();
      });
    }
    const lines = [...(d.lines[whereSide] || [])].sort((a, b) => b.planned - a.planned);
    document.getElementById("whereSub").textContent = whereSide === "out" ? "This budget's money out, by line" : "Where this budget's money in comes from";
    donut?.destroy?.();
    if (!lines.length) {
      document.getElementById("whereDonut").innerHTML = `<p class="fw-semibold mb-0">No money ${whereSide} planned.</p>`;
      return;
    }
    const top = lines.slice(0, 5);
    const rest = lines.slice(5);
    const labels = [...top.map((l) => B.esc(l.name)), ...(rest.length ? [`Other (${rest.length} lines)`] : [])];
    const series = [...top.map((l) => l.planned), ...(rest.length ? [rest.reduce((t, l) => t + l.planned, 0)] : [])];
    donut = UI.renderRingDonut("whereDonut", {
      labels,
      series,
      colors: whereSide === "out" ? ["danger", "warning", "purple", "pink", "primary", "secondary"] : ["success", "primary", "purple", "warning", "pink", "secondary"],
      centerLabel: whereSide === "out" ? "Money out" : "Money in",
      format: (v) => B.shortMoney(v),
    });
  }

  /** "This budget": how far into its period we are, the in/out split and the facts. */
  function renderStatusCard(b) {
    const start = new Date(`${b.start_date}T00:00:00`);
    const end = new Date(`${b.end_date}T23:59:59`);
    const now = new Date();
    const totalDays = Math.round((end - start) / 86400000);
    const gone = Math.min(totalDays, Math.max(0, Math.ceil((now - start) / 86400000)));
    const periodText = now < start ? `Starts ${start.toLocaleDateString("en-GB", { day: "numeric", month: "short" })}` : now > end ? "This period has ended" : `${gone} of ${totalDays} days gone`;
    const pct = totalDays ? Math.round((gone / totalDays) * 100) : 0;
    const when = (iso) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "-");
    const both = b.in_planned + b.out_planned;
    const facts = [
      ["Status", B.statusPill(b.status)],
      ["Prepared by", B.esc(b.prepared_by || "-")],
      b.status !== "draft" ? ["In use since", `${when(b.started_at)}${b.started_by ? ` · ${B.esc(b.started_by)}` : ""}`] : null,
      b.status === "closed" ? ["Closed", `${when(b.closed_at)}${b.closed_by ? ` · ${B.esc(b.closed_by)}` : ""}`] : null,
      d.previous ? ["Budget before", `<a href="${B.url("budget.php", { id: d.previous.id })}">${B.esc(d.previous.period_label)}</a>`] : null,
    ].filter(Boolean);
    document.getElementById("statusSub").textContent = `${b.period_label} · ${B.esc(b.place?.name || "")}`;
    document.getElementById("statusCard").innerHTML = `
      <div class="d-flex align-items-end justify-content-between gap-2">
        <div><span class="composition-total">${now > end ? "100" : now < start ? "0" : pct}%</span> <span class="kpi-caption">of the period gone</span></div>
        <span class="soft-chip soft-primary">${periodText}</span>
      </div>
      <div class="count-bar my-2"><span class="bg-primary" style="width: ${now > end ? 100 : now < start ? 0 : pct}%"></span></div>
      <div class="d-flex justify-content-between fs-12 fw-semibold mb-1 mt-3"><span class="text-success">Money in ${B.shortMoney(b.in_planned)}</span><span class="text-danger">Money out ${B.shortMoney(b.out_planned)}</span></div>
      <div class="count-bar composition-bar" aria-hidden="true"><span class="bg-success" style="width: ${both ? (b.in_planned / both) * 100 : 50}%"></span><span class="bg-danger" style="width: ${both ? (b.out_planned / both) * 100 : 50}%"></span></div>
      <ul class="list-unstyled mb-0 mt-3 budget-facts">
        ${facts.map(([k, v]) => `<li><span>${k}</span><span class="fw-semibold">${v}</span></li>`).join("")}
      </ul>`;
  }

  function renderLines(side, lines) {
    const el = document.getElementById(side === "in" ? "linesIn" : "linesOut");
    const planned = lines.reduce((t, l) => t + l.planned, 0);
    document.getElementById(side === "in" ? "inChip" : "outChip").innerHTML = `<b>${B.money(planned)}</b>`;
    if (!lines.length) {
      el.innerHTML = `<p class="fw-semibold mb-0">No ${side === "in" ? "money in" : "money out"} planned.</p>`;
      return;
    }
    const verb = side === "in" ? "received" : "spent";
    el.innerHTML = `<div class="budget-progress">${lines
      .map((l) => {
        const pct = l.planned > 0 ? (l.actual / l.planned) * 100 : l.actual > 0 ? 100 : 0;
        const over = side === "out" && l.actual > l.planned;
        const color = side === "in" ? "success" : over ? "danger" : pct >= 80 ? "warning" : "primary";
        const status = over
          ? `<span class="text-danger fw-semibold">Over by ${B.money(l.actual - l.planned)}</span>`
          : side === "in"
            ? `${B.money(Math.max(l.left, 0))} still to come`
            : `${B.money(l.left)} left`;
        return `
          <div class="budget-progress-row">
            <div class="budget-progress-top">
              <span class="fw-semibold">${B.esc(l.name)}${l.is_own ? ' <span class="soft-chip soft-primary">Ours</span>' : ""}${changeChip(l)}</span>
              <span class="fw-semibold">${B.money(l.planned)}</span>
            </div>
            <div class="count-bar"><span class="bg-${color}" style="width: ${Math.min(pct, 100)}%"></span></div>
            <div class="budget-progress-foot">
              <span>${l.actual ? `${B.money(l.actual)} ${verb}` : `Nothing ${verb} yet`}</span>
              <span>${status}</span>
            </div>
          </div>`;
      })
      .join("")}</div>`;
  }

  /** A small "+10% vs Dec" / "New" chip against the budget before. */
  function changeChip(line) {
    const prev = d.previous?.lines?.[line.line_id];
    if (!d.previous) return "";
    if (prev === undefined) return ' <span class="soft-chip soft-purple">New</span>';
    if (!prev || Math.abs(line.planned - prev) < 0.005) return "";
    const pct = Math.round(((line.planned - prev) / prev) * 100);
    const short = d.previous.period_label.split(" ")[0].slice(0, 3);
    return ` <span class="soft-chip soft-${pct > 0 ? "warning" : "primary"}">${pct > 0 ? "+" : ""}${pct}% vs ${short}</span>`;
  }

  // ------------------------------------------------------------------ history

  const HISTORY_ICONS = {
    created: ["ri-add-line", "success"],
    updated: ["ri-edit-line", "primary"],
    started: ["ri-play-circle-line", "success"],
    closed: ["ri-lock-line", "secondary"],
    reopened: ["ri-lock-unlock-line", "purple"],
    deleted: ["ri-delete-bin-line", "danger"],
    retired: ["ri-archive-line", "secondary"],
  };

  async function loadHistory() {
    const el = document.getElementById("budgetHistory");
    el.innerHTML = '<span class="skel" style="height: 6rem; display: block;"></span>';
    const res = await BudgetsAPI.history(id);
    const items = res.ok ? res.data || [] : [];
    document.querySelector('[data-tab-figure="history"]').textContent = `${items.length} ${items.length === 1 ? "entry" : "entries"}`;
    if (!items.length) {
      el.innerHTML = '<p class="fw-semibold mb-0">Nothing recorded yet.</p>';
      return;
    }
    el.innerHTML = `<ul class="record-history">${items
      .map(
        (h) => `
        <li class="is-${h.action === "created" ? "created" : "updated"}">
          <span class="record-history-dot bg-${(HISTORY_ICONS[h.action] || [, "primary"])[1]} text-white"><i class="${(HISTORY_ICONS[h.action] || ["ri-edit-line"])[0]}"></i></span>
          <div>
            <strong>${B.esc(h.description)}</strong>
            <small>${h.who ? `${B.esc(h.who)} · ` : ""}${B.esc(h.when_label || "")}</small>
          </div>
        </li>`,
      )
      .join("")}</ul>`;
  }

  // ------------------------------------------------------------------ actions

  function wireActions() {
    const act = (btnId, call, confirm) => {
      document.getElementById(btnId).addEventListener("click", (e) => {
        const btn = e.currentTarget;
        const run = async () => {
          UI.setButtonLoading(btn, "Working...");
          const res = await call(id);
          UI.restoreButton(btn);
          if (!res.ok) {
            Toast.error(res.message);
            return;
          }
          Toast.success(res.message);
          render(res.data);
          loadHistory();
        };
        confirm ? Toast.confirm(confirm.message(), run, null, confirm.options) : run();
      });
    };

    act("startBtn", BudgetsAPI.start, {
      message: () => `Start using the ${d.budget.period_label} budget? You can still change it afterwards.`,
      options: { title: "Start using", confirmText: "Start using", type: "success" },
    });
    act("closeBtn", BudgetsAPI.close, {
      message: () => `Close the ${d.budget.period_label} budget? It can't be changed while closed, but you can reopen it.`,
      options: { title: "Close budget", confirmText: "Close", type: "warning" },
    });
    act("reopenBtn", BudgetsAPI.reopen, null);

    document.getElementById("deleteBtn").addEventListener("click", () => {
      Toast.confirm(
        `Delete the draft ${d.budget.period_label} budget?`,
        async () => {
          const res = await BudgetsAPI.remove(id);
          if (!res.ok) {
            Toast.error(res.message);
            return;
          }
          B.flash(res.message);
          window.location.href = B.url("budgets.php", { year: d.budget.fiscal_year });
        },
        null,
        { title: "Delete draft", confirmText: "Delete", type: "error" },
      );
    });
  }

  function wireTabs() {
    const wanted = new URLSearchParams(window.location.search).get("tab");
    if (wanted) document.querySelector(`#budgetTabs [data-tab="${wanted}"]`)?.click();
    document.querySelectorAll("#budgetTabs [data-tab]").forEach((btn) =>
      btn.addEventListener("shown.bs.tab", () => {
        const p = new URLSearchParams(window.location.search);
        p.set("tab", btn.dataset.tab);
        history.replaceState(null, "", `${window.location.pathname}?${p}`);
      }),
    );
  }

  function showError(message) {
    document.getElementById("budgetTitle").textContent = "Budget not available";
    document.getElementById("budgetIcon").innerHTML = '<span class="avatar avatar-md bg-danger text-white"><i class="ri-error-warning-line"></i></span>';
    document.getElementById("budgetSub").textContent = message;
    ["statCardsRow", "linesIn", "linesOut"].forEach((elId) => (document.getElementById(elId).innerHTML = ""));
    document.getElementById("budgetTabs").hidden = true;
    document.querySelector(".section-tab-content").hidden = true;
  }

  return { init };
})();

window.BudgetsDetail = BudgetsDetail;
