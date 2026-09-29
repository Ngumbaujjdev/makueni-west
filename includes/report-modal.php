<?php
/**
 * Export modal shared by every page (included from header.php) - driven by
 * assets/js/utils/report-center.js. Steps: choose -> preview -> working ->
 * done | error, shown in the header's step indicator as Choose / Preview /
 * Download. Backed by the reports API (docs/specs/reports-spec.md).
 */
?>
<div class="modal fade report-modal" id="reportModal" tabindex="-1" aria-labelledby="reportModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="rp-head">
                <span class="rp-head-icon"><i class="ri-file-download-line"></i></span>
                <div class="rp-head-text">
                    <h5 id="reportModalTitle">Export a report</h5>
                    <span id="reportModalScope">PDF or Excel, with insights and recommendations</span>
                </div>
                <ol class="rp-stepper" id="rpStepper" aria-label="Steps">
                    <li data-step-dot="choose"><span>1</span><em>Choose</em></li>
                    <li data-step-dot="preview"><span>2</span><em>Preview</em></li>
                    <li data-step-dot="download"><span>3</span><em>Download</em></li>
                </ol>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <!-- 1. Choose -->
                <div class="rp-step" data-rp-step="choose">
                    <!-- Opened from a page's Export: just that page's report -->
                    <div class="rp-locked" id="rpLocked" hidden></div>
                    <div id="rpReportsWrap">
                        <div class="rp-label">Report</div>
                        <div class="rp-reports" id="rpReports" role="radiogroup" aria-label="Report"></div>
                    </div>
                    <div class="rp-options">
                        <div class="rp-option" id="rpPeriodWrap">
                            <div class="rp-label" id="rpPeriodTitle">Period</div>
                            <div id="rpPeriod"></div>
                        </div>
                        <div class="rp-option">
                            <div class="rp-label">Format</div>
                            <div class="rp-format-toggle" role="radiogroup" aria-label="Format">
                                <label><input type="radio" name="rp_format" value="pdf" checked><span><i class="ri-file-pdf-line"></i>PDF</span></label>
                                <label><input type="radio" name="rp_format" value="xlsx"><span><i class="ri-file-excel-2-line"></i>Excel</span></label>
                            </div>
                            <div class="rp-format-hint" id="rpFormatHint">For printing and sharing, with a QR code that proves it's genuine.</div>
                        </div>
                    </div>
                </div>

                <!-- 2. Preview -->
                <div class="rp-step" data-rp-step="preview" hidden>
                    <div class="rp-summarybar" id="rpSummaryBar"></div>
                    <div id="rpPreview"></div>
                </div>

                <!-- 3. Working -->
                <div class="rp-step" data-rp-step="working" hidden>
                    <div class="rp-working">
                        <div class="rp-ring" aria-hidden="true">
                            <svg viewBox="0 0 120 120">
                                <circle class="rp-ring-track" cx="60" cy="60" r="52"></circle>
                                <circle class="rp-ring-bar" id="rpRingBar" cx="60" cy="60" r="52"></circle>
                            </svg>
                            <span class="rp-ring-pct" id="rpPct">0%</span>
                        </div>
                        <div class="rp-working-text">
                            <h6 id="rpWorkingTitle">Building your report</h6>
                            <ul class="rp-stages" id="rpStages"></ul>
                            <p class="rp-hint" id="rpHint">You can close this and keep working - it will show under <i class="ri-file-download-line"></i> at the top when it's ready.</p>
                        </div>
                    </div>
                </div>

                <!-- 4. Done -->
                <div class="rp-step" data-rp-step="done" hidden>
                    <div class="rp-done-head">
                        <span class="rp-done-tick"><i class="ri-check-line"></i></span>
                        <div>
                            <h6>Your report is ready</h6>
                            <span id="rpDoneText"></span>
                        </div>
                    </div>
                    <div id="rpFile"></div>
                </div>

                <!-- 5. Error -->
                <div class="rp-step" data-rp-step="error" hidden>
                    <div class="rp-done-head is-error">
                        <span class="rp-done-tick"><i class="ri-error-warning-line"></i></span>
                        <div>
                            <h6>That didn't work</h6>
                            <span id="rpError"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer rp-foot">
                <span class="rp-summary" id="rpSummary"></span>
                <div class="rp-foot-btns" id="rpFooter"></div>
            </div>
        </div>
    </div>
</div>
