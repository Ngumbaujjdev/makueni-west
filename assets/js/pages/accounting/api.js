/**
 * ============================================================================
 * ACCOUNTING - API (docs/specs/accounting-spec.md)
 * ============================================================================
 * The same shape as FacilitiesAPI: it sends the acting role
 * (X-Assignment-Id), and the place below when the page was opened for one
 * (?territory_id=); every call resolves to { ok, status, message, errors,
 * data, raw }.
 * ============================================================================
 */
const AccountingAPI = (function () {
  "use strict";

  const BASE = AppConfig.API_BASE_URL;
  const territoryId = () => new URLSearchParams(window.location.search).get("territory_id");
  const TIMEOUT_MS = 20000;

  function headers(json = true) {
    const h = { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
    if (json) h["Content-Type"] = "application/json";
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* no role cached - the API uses the primary one */
    }
    return h;
  }

  function url(path, params = null) {
    const q = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== "") q.set(k, v);
    });
    if (territoryId() && !q.has("territory_id")) q.set("territory_id", territoryId());
    const qs = q.toString();
    return `${BASE}${path}${qs ? `?${qs}` : ""}`;
  }

  async function request(method, path, { params, body, form } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    try {
      const res = await fetch(url(path, params), { method, headers: headers(!form), body: form || (body === undefined ? undefined : JSON.stringify(body)), signal: ctrl.signal });
      const json = await res.json().catch(() => null);
      if (json === null) return { ok: false, status: res.status, message: "The server sent an unexpected reply. Please try again.", errors: null, data: null, raw: null };
      const ok = res.ok && json.success !== false;
      const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
      return { ok, status: res.status, message: ok ? json.message : firstError || json.message || "Something went wrong. Please try again.", errors: json.errors || null, data: json.data, raw: json };
    } catch (e) {
      const message = e.name === "AbortError" ? "The server took too long to answer. Please try again." : "Can't reach the server. Check your connection and try again.";
      return { ok: false, status: 0, message, errors: null, data: null, raw: null };
    } finally {
      clearTimeout(timer);
    }
  }

  function upload(path, file) {
    const form = new FormData();
    form.append("file", file);
    return request("POST", path, { form });
  }

  /** Fields (with lines: [{...}]) and files as one multipart form - lines[0][quantity]... */
  function formOf(body, files = {}) {
    const form = new FormData();
    const add = (key, v) => {
      if (v === undefined || v === null) return;
      if (Array.isArray(v)) return v.forEach((x, i) => add(`${key}[${i}]`, x));
      if (typeof v === "object") return Object.entries(v).forEach(([k, x]) => add(`${key}[${k}]`, x));
      form.append(key, typeof v === "boolean" ? (v ? "1" : "0") : v);
    };
    Object.entries(body || {}).forEach(([k, v]) => add(k, v));
    Object.entries(files).forEach(([k, list]) => [].concat(list || []).forEach((f) => form.append(k, f)));
    return form;
  }

  /** A private file through the API, as a blob URL for a new tab. */
  async function fileUrl(path) {
    const h = headers(false);
    h.Accept = "*/*";
    try {
      const res = await fetch(url(path), { headers: h });
      return res.ok ? URL.createObjectURL(await res.blob()) : null;
    } catch (e) {
      return null;
    }
  }

  return {
    overview: () => request("GET", "/accounting/overview"),
    places: () => request("GET", "/accounting/places", { params: { territory_id: "" } }),
    options: () => request("GET", "/accounting/options"),
    accounts: () => request("GET", "/accounting/accounts"),
    saveAccount: (id, body) => request(id ? "PUT" : "POST", id ? `/accounting/accounts/${id}` : "/accounting/accounts", { body }),
    saveChart: (id, body) => request(id ? "PUT" : "POST", id ? `/accounting/chart/${id}` : "/accounting/chart", { body }),
    cashbook: (params) => request("GET", "/accounting/cashbook", { params }),
    trialBalance: (date) => request("GET", "/accounting/trial-balance", { params: { date } }),
    journals: (params) => request("GET", "/accounting/journals", { params }),
    journal: (id) => request("GET", `/accounting/journals/${id}`),
    reverse: (id, body) => request("POST", `/accounting/journals/${id}/reverse`, { body }),
    receipt: (body) => request("POST", "/accounting/receipts", { body }),
    transfer: (body) => request("POST", "/accounting/transfers", { body }),
    journalVoucher: (body) => request("POST", "/accounting/journal-vouchers", { body }),
    addJournalFile: (id, file) => upload(`/accounting/journals/${id}/attachments`, file),
    removeJournalFile: (id, media) => request("DELETE", `/accounting/journals/${id}/attachments/${media}`),
    journalFileUrl: (id, media) => fileUrl(`/accounting/journals/${id}/attachments/${media}`),
    vouchers: (status) => request("GET", "/accounting/payment-vouchers", { params: { status } }),
    voucher: (id) => request("GET", `/accounting/payment-vouchers/${id}`),
    saveVoucher: (id, body) => request(id ? "PUT" : "POST", id ? `/accounting/payment-vouchers/${id}` : "/accounting/payment-vouchers", { body }),
    authorise: (id, note) => request("POST", `/accounting/payment-vouchers/${id}/authorise`, { body: { note } }),
    reject: (id, reason) => request("POST", `/accounting/payment-vouchers/${id}/reject`, { body: { reason } }),
    pay: (id, body) => request("POST", `/accounting/payment-vouchers/${id}/pay`, { body }),
    reversePayment: (id, reason) => request("POST", `/accounting/payment-vouchers/${id}/reverse`, { body: { reason } }),
    cancelVoucher: (id) => request("POST", `/accounting/payment-vouchers/${id}/cancel`),
    addVoucherFile: (id, file) => upload(`/accounting/payment-vouchers/${id}/attachments`, file),
    removeVoucherFile: (id, media) => request("DELETE", `/accounting/payment-vouchers/${id}/attachments/${media}`),
    voucherFileUrl: (id, media) => fileUrl(`/accounting/payment-vouchers/${id}/attachments/${media}`),
    // A2 - reconciliation
    reconciliation: () => request("GET", "/accounting/reconciliation"),
    board: () => request("GET", "/accounting/reconciliation-board", { params: { territory_id: "" } }),
    countCash: (body) => request("POST", "/accounting/cash-counts", { body }),
    approveCount: (id) => request("POST", `/accounting/cash-counts/${id}/approve`),
    rejectCount: (id, reason) => request("POST", `/accounting/cash-counts/${id}/reject`, { body: { reason } }),
    startRec: (body) => request("POST", "/accounting/reconciliations", { body }),
    rec: (id) => request("GET", `/accounting/reconciliations/${id}`),
    updateRec: (id, body) => request("PUT", `/accounting/reconciliations/${id}`, { body }),
    discardRec: (id) => request("DELETE", `/accounting/reconciliations/${id}`),
    tick: (id, lineIds, cleared) => request("POST", `/accounting/reconciliations/${id}/tick`, { body: { line_ids: lineIds, cleared } }),
    importStatement: (id, rows, mapping) => request("POST", `/accounting/reconciliations/${id}/statement`, { body: { rows, mapping } }),
    statementLine: (id, line, action, body = {}) => request("POST", `/accounting/reconciliations/${id}/statement/${line}/${action}`, { body }),
    submitRec: (id) => request("POST", `/accounting/reconciliations/${id}/submit`),
    approveRec: (id) => request("POST", `/accounting/reconciliations/${id}/approve`),
    returnRec: (id, reason) => request("POST", `/accounting/reconciliations/${id}/return`, { body: { reason } }),
    petty: () => request("GET", "/accounting/petty-cash"),
    setFloat: (body) => request("PUT", "/accounting/petty-cash", { body }),
    pettySpend: (body) => request("POST", "/accounting/petty-cash/spend", { body }),
    topUp: (fromAccountId) => request("POST", "/accounting/petty-cash/top-up", { body: { from_account_id: fromAccountId } }),
    periods: (year) => request("GET", "/accounting/periods", { params: { year } }),
    closeMonth: (year, month) => request("POST", "/accounting/periods/close", { body: { year, month } }),
    reopenMonth: (year, month, reason) => request("POST", "/accounting/periods/reopen", { body: { year, month, reason } }),
    // A3 - Sunday collections
    collections: () => request("GET", "/accounting/collections"),
    collectionOptions: (date) => request("GET", "/accounting/collections/options", { params: { date } }),
    collection: (id) => request("GET", `/accounting/collections/${id}`),
    saveCollection: (id, body) => request(id ? "PUT" : "POST", id ? `/accounting/collections/${id}` : "/accounting/collections", { body }),
    deleteCollection: (id) => request("DELETE", `/accounting/collections/${id}`),
    confirmCollection: (id) => request("POST", `/accounting/collections/${id}/confirm`),
    returnCollection: (id, reason) => request("POST", `/accounting/collections/${id}/return`, { body: { reason } }),
    bankCollection: (id, body) => request("POST", `/accounting/collections/${id}/bank`, { body }),
    reverseCollection: (id, reason) => request("POST", `/accounting/collections/${id}/reverse`, { body: { reason } }),
    // A4 - approvals, requisitions, advances
    approvals: (tab) => request("GET", "/approvals", { params: { tab, territory_id: "" } }),
    approval: (id) => request("GET", `/approvals/requests/${id}`, { params: { territory_id: "" } }),
    decideApproval: (id, decision, comment) => request("POST", `/approvals/requests/${id}/${decision}`, { body: { comment } }),
    cancelApproval: (id) => request("POST", `/approvals/requests/${id}/cancel`),
    retryApproval: (id) => request("POST", `/approvals/requests/${id}/retry`),
    approvalFileUrl: (id, media) => fileUrl(`/approvals/requests/${id}/files/${media}`),
    delegations: () => request("GET", "/approvals/delegations"),
    delegate: (body) => request("POST", "/approvals/delegations", { body }),
    undelegate: (id) => request("DELETE", `/approvals/delegations/${id}`),
    workflows: () => request("GET", "/approvals/workflows"),
    approvalPeople: (q) => request("GET", "/approvals/people", { params: { q } }),
    saveWorkflow: (id, body) => request(id ? "PUT" : "POST", id ? `/approvals/workflows/${id}` : "/approvals/workflows", { body }),
    deleteWorkflow: (id) => request("DELETE", `/approvals/workflows/${id}`),
    requisitions: () => request("GET", "/accounting/requisitions"),
    requisitionOptions: () => request("GET", "/accounting/requisitions/options"),
    requisition: (id) => request("GET", `/accounting/requisitions/${id}`),
    saveRequisition: (id, body) => request(id ? "PUT" : "POST", id ? `/accounting/requisitions/${id}` : "/accounting/requisitions", { body }),
    decideRequisition: (id, decision, comment) => request("POST", `/accounting/requisitions/${id}/${decision}`, { body: { comment } }),
    cancelRequisition: (id) => request("POST", `/accounting/requisitions/${id}/cancel`),
    payRequisition: (id, payFrom) => request("POST", `/accounting/requisitions/${id}/pay`, { body: { pay_from_account_id: payFrom } }),
    addRequisitionFile: (id, file) => upload(`/accounting/requisitions/${id}/attachments`, file),
    requisitionFileUrl: (id, media) => fileUrl(`/accounting/requisitions/${id}/attachments/${media}`),
    retireAdvance: (id, body) => request("POST", `/accounting/advances/${id}/retire`, { body }),
    // A5 - procurement
    procurement: () => request("GET", "/accounting/procurement"),
    procurementOptions: () => request("GET", "/accounting/procurement/options"),
    saveSupplier: (id, body) => request(id ? "PUT" : "POST", id ? `/accounting/procurement/suppliers/${id}` : "/accounting/procurement/suppliers", { body }),
    removeSupplier: (id) => request("DELETE", `/accounting/procurement/suppliers/${id}`),
    addQuote: (rid, body, file) => request("POST", `/accounting/requisitions/${rid}/quotes`, { form: formOf(body, { file }) }),
    removeQuote: (rid, qid) => request("DELETE", `/accounting/requisitions/${rid}/quotes/${qid}`),
    chooseQuote: (rid, qid, reason) => request("POST", `/accounting/requisitions/${rid}/quotes/${qid}/choose`, { body: { reason } }),
    quoteFileUrl: (rid, qid) => fileUrl(`/accounting/requisitions/${rid}/quotes/${qid}/file`),
    raiseOrder: (rid, body) => request("POST", `/accounting/requisitions/${rid}/order`, { body }),
    order: (id) => request("GET", `/accounting/procurement/orders/${id}`),
    receiveGoods: (id, body, files) => request("POST", `/accounting/procurement/orders/${id}/receive`, { form: formOf(body, { "files[]": files }) }),
    postBill: (id, body, file) => request("POST", `/accounting/procurement/orders/${id}/bill`, { form: formOf(body, { file }) }),
    closeOrder: (id, reason) => request("POST", `/accounting/procurement/orders/${id}/close`, { body: { reason } }),
    cancelOrder: (id, reason) => request("POST", `/accounting/procurement/orders/${id}/cancel`, { body: { reason } }),
    undoDelivery: (id) => request("POST", `/accounting/procurement/deliveries/${id}/undo`),
    deliveryFileUrl: (id, media) => fileUrl(`/accounting/procurement/deliveries/${id}/files/${media}`),
    payBill: (id, payFrom) => request("POST", `/accounting/procurement/bills/${id}/pay`, { body: { pay_from_account_id: payFrom } }),
    reverseBill: (id, reason) => request("POST", `/accounting/procurement/bills/${id}/reverse`, { body: { reason } }),
    billFileUrl: (id) => fileUrl(`/accounting/procurement/bills/${id}/file`),
    // A6 - remittances between levels
    remittances: (year) => request("GET", "/accounting/remittances", { params: { year } }),
    remittanceOptions: () => request("GET", "/accounting/remittances/options"),
    remittance: (id) => request("GET", `/accounting/remittances/${id}`),
    sendRemittance: (body) => request("POST", "/accounting/remittances", { body }),
    remittanceAct: (id, act, body) => request("POST", `/accounting/remittances/${id}/${act}`, { body }),
    remittanceBoard: (year) => request("GET", "/accounting/remittances/board", { params: { year } }),
    remittanceStatement: (place, year) => request("GET", "/accounting/remittances/statement", { params: { place, year } }),
    // A7 - payroll
    payroll: () => request("GET", "/accounting/payroll"),
    saveEmployee: (id, body) => request(id ? "PUT" : "POST", id ? `/accounting/payroll/employees/${id}` : "/accounting/payroll/employees", { body }),
    startRun: (month) => request("POST", "/accounting/payroll/runs", { body: { month } }),
    payrollRun: (id) => request("GET", `/accounting/payroll/runs/${id}`),
    saveSlip: (id, slip, body) => request("PUT", `/accounting/payroll/runs/${id}/payslips/${slip}`, { body }),
    addSlip: (id, employee) => request("POST", `/accounting/payroll/runs/${id}/payslips`, { body: { employee_id: employee } }),
    removeSlip: (id, slip) => request("DELETE", `/accounting/payroll/runs/${id}/payslips/${slip}`),
    runAct: (id, act, body) => request("POST", `/accounting/payroll/runs/${id}/${act}`, { body }),
    payRun: (id, kind, payFrom) => request("POST", `/accounting/payroll/runs/${id}/pay`, { body: { kind, pay_from_account_id: payFrom } }),
    runReferences: (id, refs) => request("PUT", `/accounting/payroll/runs/${id}/references`, { body: { refs } }),
  };
})();

window.AccountingAPI = AccountingAPI;
