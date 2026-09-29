<?php
/**
 * Export modal shared by every page (included from header.php) - driven by
 * assets/js/utils/report-center.js. Steps: choose -> preview -> working ->
 * done | error. Backed by the reports API (docs/specs/reports-spec.md).
 */
?>
<div class="modal fade app-modal report-modal" id="reportModal" tabindex="-1" aria-labelledby="reportModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <span class="app-modal-icon"><i class="ri-file-download-line"></i></span>
                <div class="flex-fill" style="min-width: 0;">
                    <h5 class="modal-title" id="reportModalTitle">Export a report</h5>
                    <div class="app-modal-subtitle" id="reportModalScope">PDF or Excel, with insights and recommendations</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- 1. Choose -->
                <div class="rp-step" data-rp-step="choose">
                    <div class="app-modal-section-title">Report</div>
                    <div class="rp-reports" id="rpReports" role="radiogroup" aria-label="Report"></div>
                    <div id="rpPeriodWrap" class="mt-3">
                        <div class="app-modal-section-title" id="rpPeriodTitle">Period</div>
                        <div id="rpPeriod"></div>
                    </div>
                    <div class="app-modal-section-title mt-3">Format</div>
                    <div class="rp-formats" role="radiogroup" aria-label="Format">
                        <label class="rp-format">
                            <input type="radio" name="rp_format" value="pdf" checked>
                            <span class="rp-format-icon bg-danger text-white"><i class="ri-file-pdf-line"></i></span>
                            <span><strong>PDF</strong><small>For printing and sharing. Carries a QR code that proves it's genuine.</small></span>
                        </label>
                        <label class="rp-format">
                            <input type="radio" name="rp_format" value="xlsx">
                            <span class="rp-format-icon bg-success text-white"><i class="ri-file-excel-2-line"></i></span>
                            <span><strong>Excel</strong><small>For working with the numbers - one sheet per table.</small></span>
                        </label>
                    </div>
                </div>

                <!-- 2. Preview -->
                <div class="rp-step" data-rp-step="preview" hidden>
                    <div id="rpPreview"></div>
                </div>

                <!-- 3. Working -->
                <div class="rp-step" data-rp-step="working" hidden>
                    <div class="app-modal-state">
                        <span class="app-modal-spinner"></span>
                        <h6 class="fw-bold mt-3 mb-1" id="rpWorkingTitle">Building your report</h6>
                        <p class="mb-3" id="rpStage">Waiting in the queue</p>
                        <div class="rp-progress"><i id="rpBar" style="width: 4%"></i></div>
                        <span class="rp-progress-pct" id="rpPct">0%</span>
                        <p class="rp-hint mt-3 mb-0" id="rpHint">You can close this and keep working - it will appear under <i class="ri-file-download-line"></i> Reports at the top when it's ready.</p>
                    </div>
                </div>

                <!-- 4. Done -->
                <div class="rp-step" data-rp-step="done" hidden>
                    <div class="app-modal-state">
                        <span class="app-modal-tick"><i class="ri-check-line"></i></span>
                        <h6 class="fw-bold mt-3 mb-1">Your report is ready</h6>
                        <p class="mb-3" id="rpDoneText"></p>
                    </div>
                    <div class="rp-file" id="rpFile"></div>
                </div>

                <!-- 5. Error -->
                <div class="rp-step" data-rp-step="error" hidden>
                    <div class="app-modal-state">
                        <span class="rp-error-icon bg-danger text-white"><i class="ri-error-warning-line"></i></span>
                        <h6 class="fw-bold mt-3 mb-1">That didn't work</h6>
                        <p class="mb-0" id="rpError"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="rpFooter"></div>
        </div>
    </div>
</div>
