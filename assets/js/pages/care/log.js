/**
 * ============================================================================
 * PASTORAL CARE - the care log (log.php)
 * ============================================================================
 * Every record: pills with counts (open, urgent, hospital, prayer,
 * counselling, closed), a search and a Sort menu, a tick box per row and the
 * floating bar - Close, Give to, Send message. A confidential note shows as
 * a lock to those who may not read it.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const C = CareUI;
  const K = PeopleKit;
  const CTX = window.CARE_CTX;
  const $ = (id) => document.getElementById(id);
  let byId = new Map();
  let kit = null;

  function rowHtml(r) {
    const href = `${CTX.baseUrl}/case?id=${r.id}`;
    const pills = [r.status, r.type, r.priority === "high" && r.status === "open" ? "urgent" : ""].join(" ");
    const note = r.note ? C.esc(r.note.length > 60 ? `${r.note.slice(0, 60)}...` : r.note) : r.note_hidden ? '<span class="mb-sub"><i class="ri-lock-2-line me-1"></i>Confidential</span>' : '<span class="mb-sub">-</span>';
    return `<tr class="mb-row" data-id="${r.id}" data-href="${href}" data-pills="${pills}">
      ${K.checkCell(r.id, r.who)}
      <td data-search="${C.esc(`${r.who} ${r.person?.area || ""} ${r.hospital || ""}`)}" data-order="${C.esc(r.who.toLowerCase())}">
        <div class="d-flex align-items-center gap-2">${C.typeTile(r.type, "sm")}<div class="min-w-0"><a class="fw-semibold mb-link" href="${href}">${C.esc(r.who)}</a><div class="mb-sub">${C.esc(r.person ? (r.person.kind === "visitor" ? "Visitor" : "Member") + (r.person.area ? ` · ${r.person.area}` : "") : "Not in the register")}</div></div></div>
      </td>
      <td data-order="${r.type}">${C.typePill(r.type)}${r.priority === "high" && r.status === "open" ? ' <span class="badge bg-danger">Urgent</span>' : ""}</td>
      <td data-order="${r.on}">${C.day(r.on)}</td>
      <td>${C.statusPill(r.status)}</td>
      <td class="d-none d-md-table-cell" data-order="${r.status === "open" && r.next_on ? r.next_on : "9999"}">${r.status === "open" && r.next_on ? C.dueChip(r.next_on) : '<span class="mb-sub">-</span>'}</td>
      <td class="d-none d-lg-table-cell">${r.carers.map((c) => C.esc(c.name.split(" ")[0])).join(", ") || '<span class="mb-sub">-</span>'}</td>
      <td class="d-none d-xl-table-cell text-wrap" style="max-width:16rem">${note}</td>
      <td class="text-end"><a href="${href}" class="btn btn-sm btn-primary-light">Open<i class="ri-arrow-right-line ms-1"></i></a></td>
    </tr>`;
  }

  async function carers() {
    return (await C.options()).carers || [];
  }

  function actions() {
    const send = {
      key: "sms",
      label: "Send message",
      icon: "ri-chat-3-line",
      primary: true,
      run: (ids) => {
        const people = ids.map((id) => byId.get(id)).filter((r) => r?.person).map((r) => ({ id: r.person.id, name: r.who }));
        if (!people.length) return Toast.error("None of these are in the register, so there's no number to text.");
        K.messagePeople(CTX.messagesUrl, people);
      },
    };
    if (!CTX.can.manage) return [send];
    const sub = (ids) => `${ids.length} ${ids.length === 1 ? "case" : "cases"} picked`;
    return [
      send,
      {
        key: "close",
        label: "Close",
        icon: "ri-checkbox-circle-line",
        run: (ids) =>
          K.confirmWindow({
            title: "Close these cases",
            subtitle: sub(ids),
            icon: "ri-checkbox-circle-line",
            go: '<i class="ri-check-line me-1"></i>Close them',
            body: K.parts([{ icon: "ri-checkbox-circle-line", title: "What happens", body: '<p class="mb-0">Open ones close and leave Needs care. Prayers are better marked answered one by one, with the testimony.</p>' }]),
            run: async () => {
              const res = await CareAPI.bulk({ ids, action: "close" });
              if (res.ok) load();
              return res;
            },
          }),
      },
      {
        key: "assign",
        label: "Give to",
        icon: "ri-user-follow-line",
        run: async (ids) => {
          const list = await carers();
          const el = K.confirmWindow({
            title: "Who cares for them",
            subtitle: sub(ids),
            icon: "ri-user-follow-line",
            go: '<i class="ri-check-line me-1"></i>Give to them',
            body: K.parts([{ icon: "ri-team-line", title: "Leader", hint: "Added to who went", body: `<select class="form-select" id="bkCarer">${list.map((c) => `<option value="${c.id}" data-color="${UI.colorFor(c.name)}">${C.esc(c.name)}</option>`).join("")}</select>` }]),
            run: async () => {
              const res = await CareAPI.bulk({ ids, action: "assign", user_id: Number(document.getElementById("bkCarer").value) });
              if (res.ok) load();
              return res;
            },
          });
          UI.enhanceSelect(el.querySelector("#bkCarer"));
        },
      },
    ];
  }

  async function load() {
    kit?.destroy();
    $("careRows").innerHTML = UI.renderTableLoading(9);
    const res = await CareAPI.list({});
    if (!res.ok) {
      $("careTableWrap").innerHTML = MembersUI.errorBox(res.message);
      return;
    }
    const items = res.data.items;
    byId = new Map(items.map((r) => [r.id, r]));
    if (!items.length) {
      $("careFilters").innerHTML = "";
      $("carePills").innerHTML = "";
      $("careRows").innerHTML = `<tr><td colspan="9">${MembersUI.empty("ri-heart-pulse-line", "No care recorded yet", "Record a visit, a call, counselling or a prayer - only your church's leaders see it.", CTX.can.manage ? '<button type="button" class="btn btn-primary" data-first><i class="ri-add-line me-1"></i>Record care</button>' : "")}</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "careTable",
      stripId: "careFilters",
      pillsId: "carePills",
      rowsId: "careRows",
      items,
      rowHtml,
      noun: "records",
      searchPlaceholder: "Search by name, area or hospital...",
      pills: [
        { key: "open", label: "Open", icon: "ri-folder-open-line", color: "primary", test: (r) => r.status === "open" },
        { key: "urgent", label: "Urgent", icon: "ri-flashlight-line", color: "danger", test: (r) => r.priority === "high" && r.status === "open" },
        { key: "hospital", label: "Hospital", icon: "ri-hospital-line", color: "danger", test: (r) => r.type === "hospital" },
        { key: "prayer", label: "Prayer", icon: "ri-hand-heart-line", color: "pink", test: (r) => r.type === "prayer" },
        { key: "counselling", label: "Counselling", icon: "ri-chat-heart-line", color: "purple", test: (r) => r.type === "counselling" },
        { key: "closed", label: "Closed", icon: "ri-checkbox-circle-line", color: "success", test: (r) => r.status === "closed" || r.status === "answered" },
      ],
      sorts: [
        { key: "newest", label: "Newest", order: [[3, "desc"]] },
        { key: "next", label: "Next step soonest", order: [[5, "asc"]] },
        { key: "name", label: "Name A-Z", order: [[1, "asc"]] },
        { key: "kind", label: "Kind", order: [[2, "asc"], [3, "desc"]] },
      ],
      nonSortable: [6, 7, 8],
      actions: actions(),
    });
  }

  function init() {
    const record = () => C.recordWindow({ userId: CTX.userId, onDone: () => load() });
    $("recordBtn")?.addEventListener("click", record);
    $("careRows").addEventListener("click", (e) => {
      if (e.target.closest("[data-first]")) return record();
      if (e.target.closest("a, input, .pp-check")) return;
      const tr = e.target.closest("tr[data-href]");
      if (tr) window.location.href = tr.dataset.href;
    });
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
