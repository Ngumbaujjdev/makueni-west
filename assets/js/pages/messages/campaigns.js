/**
 * ============================================================================
 * MESSAGES - Campaigns (Communication > Messages, 2026-10-08)
 * ============================================================================
 * Everything we sent out (GET /messages/sent?year=): this month's figures,
 * then one row per message - when, what, channel, who it reached, replies
 * and status - with the shared filter bar (channel, status, dates). A row
 * opens the message's own page; a scheduled one can be cancelled and a failed
 * one retried here (senders).
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const M = MessagesUI;
  const CTX = window.MESSAGES_CTX;
  const $ = (id) => document.getElementById(id);
  const thisYear = new Date().getFullYear();
  let year = Number(new URLSearchParams(window.location.search).get("year")) || thisYear;
  let items = [];

  const statusOf = (b) => (b.status === "sent" && b.failed_count ? "failed" : b.status);
  const STATUS = { sent: ["Sent", "success"], failed: ["Some failed", "danger"], scheduled: ["Scheduled", "warning"], sending: ["Sending", "primary"], cancelled: ["Cancelled", "secondary"] };
  const statusPill = (b) => {
    const s = statusOf(b);
    // Solid for what happened; pale for what's waiting or stopped.
    return s === "scheduled" || s === "cancelled" ? `<span class="soft-chip soft-${STATUS[s][1]}">${STATUS[s][0]}</span>` : UI.pill(s === "failed" ? `${b.failed_count} failed` : STATUS[s][0], STATUS[s][1]);
  };
  const whenOf = (b) => b.sent_at || b.scheduled_at || b.created_at;

  function kpis(data) {
    const f = data.figures || {};
    const series = UI.monthlySeries(items.filter((b) => b.status === "sent"), { dateField: "created_at", months: 6 });
    const last = series.data[series.data.length - 2];
    return [
      UI.renderSparkCard({ icon: "ri-send-plane-line", label: "Sent this month", value: M.num(f.sent || 0), color: "primary", delta: UI.periodDelta(f.sent || 0, last), series }),
      UI.renderSparkCard({ icon: "ri-group-line", label: "People reached", value: M.num(f.delivered || 0), color: "purple", sub: `of ${M.num(f.people || 0)} this month` }),
      UI.renderSparkCard({ icon: "ri-reply-line", label: "Replies", value: M.num(f.replies || 0), color: "success", sub: "This month" }),
      UI.renderSparkCard({ icon: f.failed ? "ri-error-warning-line" : "ri-time-line", label: f.failed ? "Didn't arrive" : "Scheduled", value: M.num(f.failed || f.scheduled || 0), color: f.failed ? "danger" : "warning", sub: f.failed ? "Open one to retry" : "Waiting to go out" }),
    ];
  }

  function row(b) {
    const d = new Date(whenOf(b));
    const reached = b.recipient_count ? Math.round(((b.recipient_count - (b.failed_count || 0)) / b.recipient_count) * 100) : 0;
    const ch = M.CHANNELS[b.channel] || M.CHANNELS.app;
    const actions = [
      `<a class="btn btn-sm btn-primary text-nowrap" href="${CTX.baseUrl}/message?id=${b.id}"><i class="ri-eye-line me-1"></i>Open</a>`,
      CTX.can.send && b.status === "scheduled" ? `<button type="button" class="btn btn-sm btn-light border text-nowrap" data-cancel="${b.id}">Cancel</button>` : "",
      CTX.can.send && b.status === "sent" && b.failed_count ? `<button type="button" class="btn btn-sm btn-light border text-nowrap" data-retry="${b.id}"><i class="ri-restart-line me-1"></i>Retry</button>` : "",
    ].join(" ");
    return `
      <tr data-date="${whenOf(b).slice(0, 10)}">
        <td data-label="When" class="text-nowrap" data-order="${M.esc(whenOf(b))}"><div class="fw-semibold">${d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })}</div><div class="fs-12">${d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}</div></td>
        <td data-label="Message" data-search="${M.esc(`${b.subject || ""} ${b.preview || ""} ${b.summary || ""}`)}">
          <div class="cm-tpl-text"><strong>${M.esc(b.subject || b.preview || "Message")}</strong><small>${M.esc(b.summary ? `To ${b.summary}` : b.preview || "")}${b.by ? ` · by ${M.esc(b.by)}` : ""}</small></div>
        </td>
        <td data-label="Channel" data-search="${ch.label}">${UI.pill(ch.label, ch.color, ch.icon)}</td>
        <td data-label="Reached" data-order="${reached}" style="min-width:8rem"><div class="fs-12 fw-semibold">${M.num(b.recipient_count - (b.failed_count || 0))} of ${M.num(b.recipient_count)}</div><div class="cm-camp-meter mt-1 mb-0"><span style="width:${reached}%"></span></div></td>
        <td data-label="Replies" data-order="${b.replies || 0}">${b.replies ? `<span class="soft-chip soft-purple"><i class="ri-reply-line"></i>${b.replies}</span>` : "-"}</td>
        <td data-label="Status" data-search="${STATUS[statusOf(b)]?.[0] || b.status}">${statusPill(b)}</td>
        <td class="text-end text-nowrap cm-tpl-actions">${actions}</td>
      </tr>`;
  }

  async function load() {
    $("cpKpis").innerHTML = UI.skeletonCards(4, "col-xl-3 col-md-6");
    $("cpBody").innerHTML = `<tr><td colspan="7" class="text-center py-4"><span class="spinner-border text-primary"></span></td></tr>`;
    const res = await MessagesAPI.sent(year);
    if (!res.ok) {
      $("cpKpis").innerHTML = "";
      $("cpBody").innerHTML = UI.renderTableEmpty(7, res.message, "ri-error-warning-line");
      return;
    }
    items = res.data.items || [];
    $("cpKpis").innerHTML = kpis(res.data).map((k) => `<div class="col-xl-3 col-md-6">${k}</div>`).join("");
    UI.mountSparklines($("cpKpis"));
    $("cpSub").textContent = `${M.num(items.length)} sent in ${year}${res.data.figures?.scheduled ? ` · ${res.data.figures.scheduled} scheduled` : ""}`;
    $("cpBody").innerHTML = items.length ? items.map(row).join("") : UI.renderTableEmpty(7, CTX.can.send ? "Nothing sent yet - New campaign sends the first one" : "Nothing sent yet", "ri-broadcast-line");
    const opts = (list) => list.map(([v, c]) => ({ value: v, label: v, color: c }));
    UI.renderFilterToolbar("cpToolbar", {
      searchPlaceholder: "Search campaigns…",
      filters: [
        { id: "cpChannel", label: "Every channel", options: Object.values(M.CHANNELS).map((c) => ({ value: c.label, label: c.label, color: c.color })) },
        { id: "cpStatus", label: "Any status", options: opts(Object.values(STATUS)) },
      ],
      dateRange: true,
    });
    const table = items.length ? UI.initListDataTable("cpTable", { order: [[0, "desc"]], nonSortableColumns: [6], hideDefaultSearch: true, noun: "campaigns", pageLength: 25, responsive: false }) : null;
    UI.wireFilterToolbar(
      "cpToolbar",
      table,
      [
        { id: "cpChannel", columnIndex: 2, exact: true },
        { id: "cpStatus", columnIndex: 5, exact: true },
      ],
      { noun: "campaigns", urlSync: false },
    );
  }

  async function act(id, kind, btn) {
    const go = async () => {
      UI.setButtonLoading(btn, kind === "cancel" ? "Cancelling…" : "Retrying…");
      const res = kind === "cancel" ? await MessagesAPI.cancel(id) : await MessagesAPI.retry(id);
      UI.restoreButton(btn);
      if (!res.ok) return Toast.error(res.message);
      Toast.success(res.message);
      load();
    };
    if (kind === "retry") return go();
    if (await M.ask({ title: "Cancel this message?", text: "It won't be sent. You can write it again any time.", icon: "ri-close-circle-line", action: "Cancel it" })) go();
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("cpYear").innerHTML = [thisYear, thisYear - 1].map((y) => `<input type="radio" name="cpYearUi" id="cpY${y}" value="${y}"${y === year ? " checked" : ""}><label for="cpY${y}">${y}</label>`).join("");
    $("cpYear").addEventListener("change", (e) => {
      year = Number(e.target.value);
      const url = new URL(window.location.href);
      year === thisYear ? url.searchParams.delete("year") : url.searchParams.set("year", year);
      history.replaceState(null, "", url);
      load();
    });
    $("cpTable").addEventListener("click", (e) => {
      const b = e.target.closest("[data-cancel],[data-retry]");
      if (b) act(Number(b.dataset.cancel || b.dataset.retry), b.dataset.cancel ? "cancel" : "retry", b);
    });
    load();
  });
})();
