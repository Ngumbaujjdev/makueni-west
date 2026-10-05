<?php
// The Monthly reports page: our own (church and region) and the places below (region and diocese).
$showOurs = $reportsCtx['reports'];
$showBelow = $reportsCtx['can']['below'];
?>
<div class="page-toolbar">
    <div class="page-toolbar-sub" id="placeLine"><?= htmlspecialchars($reportsCtx['place']['name'] ?: 'Monthly reports') ?></div>
    <div class="page-toolbar-controls" id="toolbarControls">
        <div id="periodSwitchWrap"></div>
        <button type="button" class="btn btn-outline-primary d-none" id="exportStatusBtn" data-module="monthly-reports" data-report-key="monthly.status"><i class="ri-download-2-line me-1"></i>Export</button>
        <a class="btn btn-primary d-none" id="writeBtn" href="#"><i class="ri-edit-2-line me-1"></i>Write the report</a>
    </div>
</div>

<?php if ($showOurs && $showBelow) { ?>
<div class="nav section-tabs" id="reportTabs" role="tablist" aria-label="Monthly reports">
    <button class="nav-link section-tab active" data-tab="ours" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-file-chart-line"></i></span>
        <span class="section-tab-text"><strong>Our reports</strong><small data-tab-figure="ours">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="below" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-community-line"></i></span>
        <span class="section-tab-text"><strong>Our churches' reports</strong><small data-tab-figure="below">&nbsp;</small></span>
    </button>
</div>
<?php } ?>

<?php if ($showOurs) { ?>
<div class="rp-pane" data-pane="ours">
    <div class="row" id="statCardsRow"></div>
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
            <div>
                <div class="card-title">The year at a glance</div>
                <span class="card-subtitle-text" id="yearSub">&nbsp;</span>
            </div>
            <div class="d-flex flex-wrap gap-1" id="yearLegend"></div>
        </div>
        <div class="card-body"><div class="mr-year" id="yearTiles"></div></div>
    </div>
</div>
<?php } ?>

<?php if ($showBelow) { ?>
<div class="rp-pane" data-pane="below" <?= $showOurs ? 'hidden' : '' ?>>
    <div class="row" id="belowCardsRow"></div>
    <div id="noticed"></div>
    <div class="mr-groups" id="groupTiles"></div>
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
            <div class="card-title" id="belowTitle">Reports</div>
        </div>
        <div class="card-body pb-0"><div id="belowFilters"></div></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table text-nowrap w-100" id="belowTable">
                    <thead><tr><th>Place</th><th id="groupHead">Group</th><th>Status</th><th>Sent</th><th class="text-end">Average Sunday</th><th class="text-end">Income</th><th class="text-end">Comments</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php } ?>
