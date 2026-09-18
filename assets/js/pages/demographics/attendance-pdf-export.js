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
  // Keyed by category id - populated once by loadReportTypes(), read by
  // the Report Type change handler to decide whether the Gathering Type
  // drill-down applies (only non-weekly categories have "types").
  let categoriesById = {};

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

    categoriesById = {};
    categories.forEach((category) => {
      categoriesById[category.id] = category;
      const option = document.createElement("option");
      option.value = category.id;
      option.textContent = category.name;
      select.appendChild(option);
    });

    await onReportTypeChange();
  }

  /**
   * Sunday Service has no "types" concept (see
   * AttendanceReportWidgetService::widgetsFor()'s own is_weekly branch) -
   * the Gathering Type drill-down only makes sense for, and only appears
   * for, Ministry Gatherings/Special Events.
   */
  async function onReportTypeChange() {
    const categoryId = document.getElementById("pdfReportType")?.value;
    const wrap = document.getElementById("pdfGatheringTypeWrap");
    const typeSelect = document.getElementById("pdfGatheringType");
    if (!wrap || !typeSelect) return;

    const category = categoriesById[categoryId];
    if (!category || category.is_weekly) {
      wrap.classList.add("d-none");
      typeSelect.innerHTML = '<option value="">All types</option>';
      return;
    }

    wrap.classList.remove("d-none");
    typeSelect.innerHTML = '<option value="">Loading types...</option>';

    const result = await DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, {
      gathering_category_id: categoryId,
    });
    const types = result.success && Array.isArray(result.data) ? result.data : [];

    typeSelect.innerHTML = '<option value="">All types</option>';
    types.forEach((type) => {
      const option = document.createElement("option");
      option.value = type.id;
      option.textContent = type.name;
      typeSelect.appendChild(option);
    });
  }

  function setBusy(btnId, busy, busyLabel, idleHtml) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = busy;
    btn.innerHTML = busy ? `<span class="spinner-border spinner-border-sm me-1"></span>${busyLabel}` : idleHtml;
  }

  /** Shared by generatePdf()/generateExcel() - the two exports take the
   *  exact same request shape, only the API call and the result handling differ. */
  function currentExportFilters() {
    const categoryId = document.getElementById("pdfReportType")?.value;
    const gatheringTypeId = document.getElementById("pdfGatheringType")?.value;
    const filters = currentPeriodFilters();

    if (!categoryId) {
      Toast.warning("Pick a report type first.");
      return null;
    }
    if (!filters.fiscal_year_id) {
      Toast.warning("Pick a fiscal year in the period filter above first.");
      return null;
    }

    filters.gathering_category_id = categoryId;
    if (gatheringTypeId) filters.gathering_type_id = gatheringTypeId;

    return filters;
  }

  async function generatePdf() {
    const filters = currentExportFilters();
    if (!filters) return;

    setBusy("pdfGenerateBtn", true, "Generating...", '<i class="ri-file-download-line me-1"></i>Generate PDF');
    const result = await DemographicsAPIHandler.exportAttendanceReportPdf(USER_TERRITORY.id, filters);
    setBusy("pdfGenerateBtn", false, "", '<i class="ri-file-download-line me-1"></i>Generate PDF');

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

  /** Excel isn't meant to be inline-previewed like the PDF - triggers a
   *  real file download via a throwaway <a download> instead of the iframe. */
  async function generateExcel() {
    const filters = currentExportFilters();
    if (!filters) return;

    setBusy("pdfExcelBtn", true, "Generating...", '<i class="ri-file-excel-2-line me-1"></i>Export Excel');
    const result = await DemographicsAPIHandler.exportAttendanceReportExcel(USER_TERRITORY.id, filters);
    setBusy("pdfExcelBtn", false, "", '<i class="ri-file-excel-2-line me-1"></i>Export Excel');

    if (!result.success) {
      Toast.error(result.message || "Failed to generate Excel report.");
      return;
    }

    const url = URL.createObjectURL(result.blob);
    const categoryName = categoriesById[document.getElementById("pdfReportType")?.value]?.name || "Attendance";
    const link = document.createElement("a");
    link.href = url;
    link.download = `${categoryName.replace(/\s+/g, "-")}-Attendance-Report.xlsx`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
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
    document.getElementById("pdfExcelBtn")?.addEventListener("click", generateExcel);
    document.getElementById("pdfReportType")?.addEventListener("change", onReportTypeChange);

    const pdfTabBtn = document.getElementById("tab-pdf-btn");
    pdfTabBtn?.addEventListener("shown.bs.tab", syncPeriodLabel);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
