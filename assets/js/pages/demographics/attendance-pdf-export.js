/**
 * ============================================================================
 * ATTENDANCE PDF EXPORT - "PDF Reports" tab on Attendance Reports
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Generates the branded PDF from GET /attendance-reports/export-pdf
 * (DemographicsAPIHandler.exportAttendanceReportPdf) and previews it in an
 * iframe via a blob: URL - the vanilla-JS equivalent of the fetch/.blob()/
 * URL.createObjectURL() pattern the reference project (ifms-core-server)
 * uses from its own SPA frontend.
 *
 * Deliberately a separate file from attendance-reports.js - dashboard
 * rendering and PDF export are different concerns - but reads the same
 * #reportFiscalYear/#reportFiscalMonth period-picker selects that page's
 * script already writes to, rather than adding a second, competing date
 * control (this app's attendance data is fiscal-year-scoped; there's
 * already a filter for that on this exact page).
 *
 * Dependencies: config/app.js, config/constants.js, api-handler.js,
 * utils/toast.js - all already loaded on church/attendance/reports.php
 * before this script tag.
 * ============================================================================
 */
(function () {
  "use strict";

  let currentBlobUrl = null;

  function currentPeriodFilters() {
    const fiscalYearId = document.getElementById("reportFiscalYear")?.value;
    const fiscalMonthId = document.getElementById("reportFiscalMonth")?.value;
    const filters = {};
    if (fiscalYearId) filters.fiscal_year_id = fiscalYearId;
    if (fiscalMonthId) filters.fiscal_month_id = fiscalMonthId;
    return filters;
  }

  async function loadReportTypes() {
    const select = document.getElementById("pdfReportType");
    if (!select) return;

    const result = await DemographicsAPIHandler.getGatheringCategories();
    const categories = result.success && Array.isArray(result.data) ? result.data : [];

    select.innerHTML = "";
    if (categories.length === 0) {
      select.innerHTML = '<option value="">No report types available</option>';
      return;
    }

    categories.forEach((category) => {
      const option = document.createElement("option");
      option.value = category.id;
      option.textContent = category.name;
      select.appendChild(option);
    });
  }

  function setBusy(busy) {
    const btn = document.getElementById("pdfGenerateBtn");
    if (!btn) return;
    btn.disabled = busy;
    btn.innerHTML = busy
      ? '<span class="spinner-border spinner-border-sm me-1"></span>Generating...'
      : '<i class="ri-file-download-line me-1"></i>Generate PDF';
  }

  async function generatePdf() {
    const categoryId = document.getElementById("pdfReportType")?.value;
    const filters = currentPeriodFilters();

    if (!categoryId) {
      Toast.warning("Pick a report type first.");
      return;
    }
    if (!filters.fiscal_year_id) {
      Toast.warning("Pick a fiscal year in the period filter above first.");
      return;
    }

    setBusy(true);
    const result = await DemographicsAPIHandler.exportAttendanceReportPdf(USER_TERRITORY.id, {
      ...filters,
      gathering_category_id: categoryId,
    });
    setBusy(false);

    if (!result.success) {
      Toast.error(result.message || "Failed to generate PDF report.");
      return;
    }

    if (currentBlobUrl) URL.revokeObjectURL(currentBlobUrl);
    currentBlobUrl = URL.createObjectURL(result.blob);

    const frame = document.getElementById("pdfPreviewFrame");
    const openBtn = document.getElementById("pdfOpenNewTabBtn");
    const previewCard = document.getElementById("pdfPreviewCard");
    if (frame) frame.src = currentBlobUrl;
    if (openBtn) openBtn.href = currentBlobUrl;
    if (previewCard) previewCard.classList.remove("d-none");
  }

  /** Mirrors the period-picker button's own label - keeps this tab's copy
   *  in sync without a second, independently-maintained date control. */
  function syncPeriodLabel() {
    const source = document.getElementById("periodPickerLabel");
    const mirror = document.getElementById("pdfPeriodLabel");
    if (source && mirror) mirror.textContent = source.textContent;
  }

  function init() {
    const generateBtn = document.getElementById("pdfGenerateBtn");
    if (!generateBtn) return;

    loadReportTypes();
    generateBtn.addEventListener("click", generatePdf);

    const pdfTabBtn = document.getElementById("tab-pdf-btn");
    pdfTabBtn?.addEventListener("shown.bs.tab", syncPeriodLabel);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
