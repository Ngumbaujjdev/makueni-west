/**
 * ============================================================================
 * ACCOUNTING - the documents list (Receipts, Journals, All documents)
 * ============================================================================
 * One list for every kind of document: pills with counts, a search and a
 * Sort menu (PeopleKit.listTable - state kept in the URL), a year picker,
 * tick boxes to export the picked rows as CSV, and a row opens the document.
 *   AccountingDocList.mount({ type: "receipt" | null, pills, cards(items), onLoad })
 * ============================================================================
 */
const AccountingDocList = (function () {
  "use strict";

  const A = AccountingUI;
  const W = AccountingWindows;
  const K = PeopleKit;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;

  function mount(o) {
    let kit = null;
    let items = [];
    const q = new URLSearchParams(window.location.search);
    const thisYear = new Date().getFullYear();
    const yearSel = $("yearPick");
    let year = Number(q.get("year")) || thisYear;
    if (yearSel) {
      yearSel.innerHTML = [0, 1, 2, 3].map((k) => `<option value="${thisYear - k}"${thisYear - k === year ? " selected" : ""} data-icon="ri-calendar-line" data-color="primary">${thisYear - k}</option>`).join("");
      DemographicsUI.enhanceSelect(yearSel, { search: false });
      yearSel.addEventListener("change", () => {
        year = Number(yearSel.value);
        const p = new URLSearchParams(window.location.search);
        year === thisYear ? p.delete("year") : p.set("year", year);
        history.replaceState(null, "", `${window.location.pathname}${p.toString() ? `?${p}` : ""}`);
        load();
      });
    }

    const rowHtml = (j) => {
      const pills = [j.doc_type, j.method || "", j.status === "reversed" ? "reversed" : "", j.attachments ? "files" : "nofiles"].join(" ");
      const signed = j.doc_type === "receipt" ? "text-success" : j.doc_type === "payment" ? "text-danger" : "";
      const rec = A.sourceRecord(j);
      return `<tr class="acc-row${j.status === "reversed" ? " is-reversed" : ""}" data-id="${j.id}" data-pills="${pills}"${rec ? ` data-record="${A.link("record.php", rec)}"` : ""}>
        ${K.checkCell(j.id, j.number)}
        <td data-search="${esc(`${j.number} ${j.party_name || ""} ${j.narration || ""} ${j.reference || ""} ${(j.accounts || []).join(" ")}`)}" data-order="${esc(j.number)}">
          <div class="d-flex align-items-center gap-2">${A.docTile(j.doc_type)}<div class="min-w-0"><div class="d-flex align-items-center gap-2 flex-wrap"><span class="fw-semibold">${esc(j.number)}</span>${A.docPill(j.doc_type)}</div><div class="acc-sub text-truncate">${esc(j.narration || A.doc(j.doc_type).label)}</div></div></div>
        </td>
        <td data-order="${j.date}${String(j.id).padStart(8, "0")}" class="text-nowrap"><div class="d-flex align-items-center gap-2">${A.dateTile(j.date)}<small class="acc-sub">${esc(A.since(j.date))}</small></div></td>
        <td class="d-none d-md-table-cell">${j.party_name ? A.person(j.party_name) : '<span class="acc-sub">Between our accounts</span>'}${j.reference ? `<div class="acc-sub mt-1">${esc(j.reference)}</div>` : ""}</td>
        <td class="d-none d-lg-table-cell">${A.methodChip(j.method, j.method_label)}<div class="acc-sub mt-1 text-truncate acc-cell-accounts">${esc((j.accounts || []).join(", ") || "-")}</div></td>
        <td class="text-end" data-order="${j.amount}">${A.signedAmount(j.doc_type, j.amount)}<div class="d-flex justify-content-end flex-wrap gap-1 mt-1">${j.status === "reversed" ? '<span class="badge bg-danger">Reversed</span>' : '<span class="badge bg-success">Posted</span>'}${j.attachments ? `<span class="badge bg-primary"><i class="ri-attachment-2 me-1"></i>${j.attachments}</span>` : ""}</div></td>
      </tr>`;
    };

    function exportPicked(ids) {
      const pick = items.filter((j) => ids.includes(j.id));
      const cell = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
      const lines = [["Number", "Type", "Date", "From / to", "Details", "Accounts", "Method", "Reference", "Amount", "Status"].map(cell).join(",")];
      pick.forEach((j) => lines.push([j.number, A.doc(j.doc_type).label, j.date, j.party_name, j.narration, (j.accounts || []).join("; "), j.method_label, j.reference, j.amount, j.status].map(cell).join(",")));
      const a = document.createElement("a");
      a.href = URL.createObjectURL(new Blob([lines.join("\n")], { type: "text/csv" }));
      a.download = `Documents ${year}.csv`;
      a.click();
    }

    async function load() {
      kit?.destroy();
      A.ownOnly();
      $("docRows").innerHTML = DemographicsUI.renderTableLoading(6);
      const res = await AccountingAPI.journals({ type: o.type || "", from: `${year}-01-01`, to: year === thisYear ? new Date().toISOString().slice(0, 10) : `${year}-12-31` });
      if (!res.ok) {
        $("docTableWrap").innerHTML = A.errorBox(res.message);
        return;
      }
      items = res.data.items.filter((j) => !o.only || o.only.includes(j.doc_type));
      A.placeLine($("accPlaceLine"), res.data.place);
      o.cards?.(items, res.data);
      o.onLoad?.(res.data);
      if (!items.length) {
        $("docFilters").innerHTML = "";
        $("docPills").innerHTML = "";
        $("docRows").innerHTML = `<tr><td colspan="6">${A.empty(o.emptyIcon || "ri-file-list-3-line", o.emptyTitle || "Nothing here yet", o.emptyText || `No documents in ${year}.`)}</td></tr>`;
        return;
      }
      kit = K.listTable({
        tableId: "docTable",
        stripId: "docFilters",
        pillsId: "docPills",
        rowsId: "docRows",
        items,
        rowHtml,
        noun: "documents",
        searchPlaceholder: "Search number, name, note, reference or account...",
        pills: o.pills,
        sorts: [
          { key: "new", label: "Newest first", order: [[2, "desc"]] },
          { key: "old", label: "Oldest first", order: [[2, "asc"]] },
          { key: "big", label: "Largest first", order: [[5, "desc"]] },
        ],
        actions: [{ key: "csv", label: "Download picked (CSV)", icon: "ri-file-excel-2-line", primary: true, run: exportPicked }],
      });
    }

    $("docRows").addEventListener("click", (e) => {
      if (e.target.closest("a, input, .pp-check, button")) return;
      const tr = e.target.closest("tr[data-id]");
      if (!tr) return;
      if (tr.dataset.record) window.location.href = tr.dataset.record;
      else W.viewJournal(Number(tr.dataset.id), { onChange: load });
    });
    A.placePicker($("accPlacePick"), load);
    load();
    return { reload: load };
  }

  const METHOD_PILLS = [
    { key: "cash", label: "Cash", icon: "ri-money-dollar-box-line", color: "success", test: (j) => j.method === "cash" },
    { key: "mpesa", label: "M-Pesa", icon: "ri-smartphone-line", color: "purple", test: (j) => j.method === "mpesa" },
    { key: "bank", label: "Bank", icon: "ri-bank-line", color: "primary", test: (j) => j.method === "bank" || j.method === "cheque" },
  ];
  const REVERSED_PILL = { key: "reversed", label: "Reversed", icon: "ri-arrow-go-back-line", color: "danger", test: (j) => j.status === "reversed" };
  const NOFILES_PILL = { key: "nofiles", label: "No papers", icon: "ri-attachment-2", color: "warning", test: (j) => !j.attachments };

  return { mount, METHOD_PILLS, REVERSED_PILL, NOFILES_PILL };
})();

window.AccountingDocList = AccountingDocList;
