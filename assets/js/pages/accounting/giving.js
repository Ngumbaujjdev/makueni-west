/**
 * ============================================================================
 * ACCOUNTING - Online giving (giving.php, every level)
 * ============================================================================
 * The place's gifts from its public giving page (docs/specs/accounting-spec.md,
 * A10a) - by M-Pesa through the diocese paybill or by card on Paystack - and
 * its giving link to share. Each gift is in the books once it is paid.
 * ============================================================================
 */
(function () {
  "use strict";

  const UI = DemographicsUI;
  const A = AccountingUI;
  const K = PeopleKit;
  const API = AccountingAPI;
  const $ = (id) => document.getElementById(id);
  const esc = A.esc;
  let data = null;
  let kit = null;

  const ST = { paid: ["success", "ri-checkbox-circle-line"], pending: ["warning", "ri-time-line"], failed: ["danger", "ri-close-circle-line"], abandoned: ["secondary", "ri-close-line"] };
  const PURPOSE_COLOR = { T: "primary", O: "success", TH: "pink", B: "warning", K: "purple" };
  const when = (iso) => (iso ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "-");
  const sum = (arr, f = (g) => g.amount) => arr.reduce((t, g) => t + f(g), 0);

  function cards() {
    const ym = new Date().toISOString().slice(0, 7);
    const paid = data.gifts.filter((g) => g.status === "paid");
    const month = paid.filter((g) => (g.paid_at || "").startsWith(ym));
    K.statRow($("statCardsRow"), [
      { icon: "ri-hand-heart-line", label: "Given online this month", sub: `${month.length} gifts`, value: A.short(sum(month)), color: "success" },
      { icon: "ri-smartphone-line", label: "By M-Pesa", sub: "Through the diocese paybill", value: A.short(sum(month.filter((g) => g.method === "mpesa"))), color: "primary" },
      { icon: "ri-bank-card-line", label: "By card (Paystack)", sub: `Fees ${A.short(sum(month, (g) => g.fee))} this month`, value: A.short(sum(month.filter((g) => g.method === "paystack"))), color: "purple" },
      { icon: "ri-percent-line", label: "Diocese share split off", sub: "At source on card tithes", value: A.short(sum(month, (g) => g.split)), color: "warning" },
    ]);
  }

  function link() {
    const ch = data.paystack.channel;
    $("gvLink").innerHTML = `<div class="give-link mb-3"><code class="d-block text-break fs-6 mb-2">${esc(data.link)}</code><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-primary" data-copy><i class="ri-file-copy-line me-1"></i>Copy</button><a class="btn btn-sm btn-outline-primary" href="${esc(data.link)}" target="_blank" rel="noopener"><i class="ri-external-link-line me-1"></i>Open</a><a class="btn btn-sm btn-outline-success" href="https://wa.me/?text=${encodeURIComponent(`Give your tithe and offering to ${data.place.name} online: ${data.link}`)}" target="_blank" rel="noopener"><i class="ri-whatsapp-line me-1"></i>WhatsApp</a></div></div>
      <div class="acc-facts"><div><span>Our code</span><strong>${esc(data.code)}</strong></div><div><span>Card giving</span><strong>${!data.paystack.ready ? "Not open yet (diocese)" : ch?.status === "active" ? "Our own Paystack - settles to " + esc(ch.settles_into?.name || "our bank") : "Through the diocese - settled monthly"}</strong></div></div>`;
  }

  const rowHtml = (g) => `<tr data-id="${g.id}" data-pills="${g.status} ${g.method}">
    ${K.checkCell(g.id, g.reference)}
    <td data-search="${esc(`${g.giver || ""} ${g.phone || ""} ${g.reference} ${g.receipt || ""}`)}"><div class="fw-semibold">${esc(g.giver || "Online giver")}</div><div class="acc-sub">${esc(g.reference)}${g.phone ? ` · ${esc(g.phone)}` : ""}</div></td>
    <td data-order="${g.paid_at || g.created_at}" class="text-nowrap">${when(g.paid_at || g.created_at)}</td>
    <td class="d-none d-md-table-cell"><span class="badge bg-${PURPOSE_COLOR[g.purpose] || "secondary"} ${A.textOn(PURPOSE_COLOR[g.purpose] || "secondary")}">${esc(g.purpose_label)}</span><div class="acc-sub mt-1">${g.method === "mpesa" ? "M-Pesa" : "Card"}</div></td>
    <td class="d-none d-lg-table-cell"><span class="badge bg-${ST[g.status][0]} ${A.textOn(ST[g.status][0])}"><i class="${ST[g.status][1]} me-1"></i>${esc(g.status_label)}</span><div class="acc-sub mt-1">${g.status === "paid" ? `${g.receipt ? esc(g.receipt) : ""}${g.fee ? ` · fee ${A.short(g.fee)}` : ""}${g.split ? ` · share ${A.short(g.split)}` : ""}` : esc(g.result || "")}</div></td>
    <td class="text-end" data-order="${g.amount}"><strong>${A.money(g.amount)}</strong></td>
  </tr>`;

  function gifts() {
    kit?.destroy();
    if (!data.gifts.length) {
      $("gvPills").innerHTML = "";
      $("gvFilters").innerHTML = "";
      $("gvRows").innerHTML = `<tr><td colspan="6">${A.empty("ri-hand-heart-line", "No gifts online yet", "Share the giving link - members give by M-Pesa or card, and each gift lands in the books.")}</td></tr>`;
      return;
    }
    kit = K.listTable({
      tableId: "gvTable", stripId: "gvFilters", pillsId: "gvPills", rowsId: "gvRows", items: data.gifts, rowHtml, noun: "gifts", defaultPill: "paid",
      searchPlaceholder: "Search giver, phone, reference...",
      pills: [
        { key: "paid", label: "Paid", icon: ST.paid[1], color: "success", test: (g) => g.status === "paid" },
        { key: "pending", label: "Waiting", icon: ST.pending[1], color: "warning", test: (g) => g.status === "pending" },
        { key: "failed", label: "Not paid", icon: ST.failed[1], color: "danger", test: (g) => g.status === "failed" || g.status === "abandoned" },
        { key: "mpesa", label: "M-Pesa", icon: "ri-smartphone-line", color: "primary", test: (g) => g.method === "mpesa" },
        { key: "paystack", label: "Card", icon: "ri-bank-card-line", color: "purple", test: (g) => g.method === "paystack" },
      ],
      sorts: [{ key: "new", label: "Newest first", order: [[2, "desc"]] }, { key: "big", label: "Largest first", order: [[5, "desc"]] }],
      actions: [],
    });
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(data.link);
      Toast.success("Giving link copied.");
    } catch (e) {
      Toast.error("Copy didn't work - select the link instead.");
    }
  }

  async function load() {
    $("statCardsRow").innerHTML = UI.skeletonCards(4, "col-xl-3 col-sm-6");
    $("gvRows").innerHTML = UI.renderTableLoading(6);
    const res = await API.giving();
    if (!res.ok) {
      $("gvTableWrap").innerHTML = A.errorBox(res.message);
      return;
    }
    data = res.data;
    A.placeLine($("accPlaceLine"), data.place);
    cards();
    link();
    gifts();
  }

  document.addEventListener("DOMContentLoaded", () => {
    $("copyLinkBtn").addEventListener("click", copy);
    $("gvLink").addEventListener("click", (e) => e.target.closest("[data-copy]") && copy());
    A.placePicker($("accPlacePick"), load);
    load();
  });
})();
