<?php
// Asset reports (docs/specs/people-and-care-spec.md, P5 round 3). The report cards and
// "Your recent reports" come from assets/js/pages/demographics/reports.js
// (REPORTS_PAGE.module = 'facilities'); each card opens the shared export window (report-center.js).
?>
<script>window.REPORTS_PAGE = { module: 'facilities' };</script>
<div class="page-toolbar">
    <div class="page-toolbar-sub">Reports on what <?= htmlspecialchars($facCtx['place']['name'] ?: 'our church') ?> owns, as PDF or Excel</div>
    <div class="page-toolbar-controls">
        <a href="<?= $facCtx['baseUrl'] ?>/assets" class="btn btn-outline-primary"><i class="ri-pie-chart-2-line me-1"></i>What we own</a>
        <a href="<?= SITE_URL ?>/verify-report" target="_blank" rel="noopener" class="btn btn-light"><i class="ri-shield-check-line me-1"></i>Verify a report</a>
    </div>
</div>

<div class="alert alert-primary d-flex align-items-center gap-3" role="note">
    <span class="avatar avatar-sm bg-success text-white flex-shrink-0"><i class="ri-door-open-line"></i></span>
    <div>Doing a stock-take? Print <b>Room by room</b>, walk each room and tick what you find - anything missing that isn't on loan or being repaired, report it.</div>
</div>

<div class="row g-3 mb-4" id="reportCatalogue">
    <?php for ($i = 0; $i < 3; $i++) : ?>
        <div class="col-md-6 col-xl-4"><div class="skel" style="height: 11rem; border-radius: var(--v2-radius);"></div></div>
    <?php endfor ?>
</div>

<div class="card custom-card">
    <div class="card-header">
        <div>
            <div class="card-title">Your recent reports</div>
            <span class="card-subtitle-text">Files are kept for 7 days. Their verification codes keep working after that.</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div id="runsFilterToolbar" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="runsTable">
                <thead><tr><th>Report</th><th>Format</th><th>Status</th><th>Generated</th><th>Verification code</th><th class="text-end">Action</th></tr></thead>
                <tbody id="runsBody"></tbody>
            </table>
        </div>
    </div>
</div>
