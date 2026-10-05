/**
 * NOTIFICATIONS - the page (every signed-in person; docs/specs/events-initiatives-spec.md).
 * KPI cards, the shared filter bar (kind, read state, search) and the list,
 * filtered and paged in place. Opening one marks it read and goes where
 * it's about; the header bell follows (MwdNotifications.refresh()).
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const N = window.MwdNotifications;
  const esc = N.esc;
  let data = null;

  function stats() {
    const k = data.by_kind;
    const cards = [
      UI.renderSparkCard({ icon: "ri-notification-badge-line", label: "Unread", value: String(data.unread), color: data.unread ? "danger" : "success", sub: data.unread ? "Waiting for you" : "All caught up" }),
      UI.renderSparkCard({ icon: "ri-calendar-check-line", label: "This week", value: String(data.week), color: "primary", sub: "Arrived in the last 7 days" }),
      UI.renderSparkCard({ icon: k.invitation.icon, label: "Invitations", value: String(k.invitation.unread), color: "purple", sub: "Unread" }),
      UI.renderSparkCard({ icon: k.report.icon, label: "Reports", value: String(k.report.unread), color: "pink", sub: "Unread" }),
    ];
    document.getElementById("notifStats").innerHTML = cards.map((c) => `<div class="col-xxl-3 col-md-6">${c}</div>`).join("");
  }

  function rows() {
    const body = document.getElementById("notifBody");
    if (!data.items.length) {
      body.innerHTML = UI.renderTableEmpty(5, "No notifications yet - invitations, reports and messages will show here", "ri-notification-off-line");
      return null;
    }
    body.innerHTML = data.items
      .map((n) => {
        const kind = data.by_kind[n.kind] || { label: n.kind, colour: "secondary" };
        const tone = n.colour === "warning" || n.colour === "secondary" ? "text-dark" : "text-white";
        return `
          <tr data-row-id="${esc(n.id)}" data-date="${esc(n.at.slice(0, 10))}" class="${n.read ? "" : "notif-row-unread"}">
            <td class="text-nowrap" data-order="${esc(n.at)}" data-search="${esc(N.ago(n.at))}">${esc(N.ago(n.at))}</td>
            <td>
              <div class="d-flex align-items-start gap-2">
                <span class="avatar avatar-sm avatar-rounded bg-${esc(n.colour)} ${tone} flex-shrink-0"><i class="${esc(n.icon)}"></i></span>
                <div style="min-width:0"><div class="fw-semibold">${esc(n.title)}</div><div class="fs-13">${esc(n.body)}</div>${n.place ? `<div class="fs-12">${esc(n.place.name)}</div>` : ""}</div>
              </div>
            </td>
            <td data-search="${esc(kind.label)}">${UI.pill(esc(kind.label), kind.colour, kind.icon)}</td>
            <td data-search="${n.read ? "Read" : "Unread"}">${n.read ? '<span class="soft-chip soft-secondary">Read</span>' : '<span class="badge bg-danger">Unread</span>'}</td>
            <td class="text-end text-nowrap"><a class="btn btn-sm btn-primary" href="${esc(N.href(n))}" data-open="${esc(n.id)}"><i class="ri-arrow-right-up-line me-1"></i>Open</a></td>
          </tr>`;
      })
      .join("");
    return UI.initListDataTable("notifTable", { order: [[0, "desc"]], nonSortableColumns: [4], hideDefaultSearch: true, noun: "notifications", pageLength: 25 });
  }

  function draw() {
    stats();
    UI.renderFilterToolbar("notifToolbar", {
      searchPlaceholder: "Search notifications…",
      filters: [
        { id: "notifKind", label: "All kinds", options: Object.values(data.by_kind).map((k) => ({ value: k.label, label: k.label, color: k.colour })) },
        { id: "notifState", label: "Read and unread", options: [{ value: "Unread", label: "Unread", color: "danger" }, { value: "Read", label: "Read", color: "secondary" }] },
      ],
      dateRange: true,
    });
    const table = rows();
    UI.wireFilterToolbar("notifToolbar", table, [{ id: "notifKind", columnIndex: 2, exact: true }, { id: "notifState", columnIndex: 3, exact: true }], { noun: "notifications" });
  }

  async function load() {
    document.getElementById("notifStats").innerHTML = UI.skeletonCards(4, "col-xxl-3 col-md-6");
    const d = await N.call("GET", "/notifications?limit=200");
    if (!d) {
      document.getElementById("notifStats").innerHTML = '<div class="col-12"><div class="alert alert-danger">Notifications couldn\'t be loaded. Please refresh the page.</div></div>';
      return;
    }
    data = d;
    draw();
  }

  document.addEventListener("DOMContentLoaded", () => {
    load();
    document.getElementById("notifTable").addEventListener("click", async (e) => {
      const a = e.target.closest("[data-open]");
      if (!a) return;
      e.preventDefault();
      await N.markRead(a.dataset.open);
      window.location.href = a.href;
    });
    document.getElementById("pageReadAll").addEventListener("click", async (e) => {
      const btn = e.currentTarget;
      UI.setButtonLoading(btn, "Marking…");
      await N.readAll();
      UI.restoreButton(btn);
      Toast.success("All marked as read.");
      load();
    });
  });
})();
