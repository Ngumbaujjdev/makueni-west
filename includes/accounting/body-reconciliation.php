<?php
$c = $accCtx['can'];
$accButtons = $c['reconcile'] ? '<button type="button" class="btn btn-primary" id="countBtn" data-own-only><i class="ri-calculator-line me-1"></i>Count cash</button>' : '';
include __DIR__ . '/toolbar.php';
$board = $accCtx['level'] !== 'church' && $c['below'];
?>
<?php if ($board): ?>
<div class="nav section-tabs" id="recTabs" role="tablist" aria-label="Reconciliation">
    <button class="nav-link section-tab active" data-bs-toggle="tab" data-bs-target="#tab-ours" type="button" role="tab" aria-controls="tab-ours" aria-selected="true" data-tab="ours">
        <span class="section-tab-icon bg-primary"><i class="ri-safe-2-line"></i></span>
        <span class="section-tab-text"><strong>Our accounts</strong><small id="oursFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-bs-toggle="tab" data-bs-target="#tab-below" type="button" role="tab" aria-controls="tab-below" aria-selected="false" data-tab="below">
        <span class="section-tab-icon bg-purple"><i class="ri-community-line"></i></span>
        <span class="section-tab-text"><strong>Places below</strong><small id="belowFigure">&nbsp;</small></span>
    </button>
</div>
<div class="tab-content section-tab-content">
<div class="tab-pane fade show active" id="tab-ours" role="tabpanel">
<?php endif ?>

<div class="row" id="recCards"></div>

<div class="card custom-card" id="waitCard" hidden>
    <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">Waiting for approval</div><span class="card-subtitle-text">Cash differences and reconciliations someone else must approve</span></div><span class="badge bg-warning text-dark" id="waitCount"></span></div>
    <div class="card-body p-0"><div class="acc-wait" id="waitList"></div></div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">History</div><span class="card-subtitle-text">Every count and reconciliation, newest first</span></div></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 acc-table">
                <thead><tr><th>Date</th><th>Account</th><th class="d-none d-md-table-cell">By</th><th class="text-end">Book</th><th class="text-end">Difference</th><th>Status</th></tr></thead>
                <tbody id="histRows"></tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($board): ?>
</div>
<div class="tab-pane fade" id="tab-below" role="tabpanel">
    <div class="row" id="boardCards"></div>
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">How up to date each place is</div><span class="card-subtitle-text">The last count or reconciliation of every money account, and the last month closed - open a place to see its books</span></div></div>
        <div class="card-body pb-0 pt-3" id="boardPills"></div>
        <div class="card-body p-0" id="boardWrap">
            <div id="boardFilters" class="list-filterbar-wrap"></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 pp-table acc-table" id="boardTable">
                    <thead><tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick every place on this page"></th>
                        <th>Place</th><th class="d-none d-lg-table-cell">Accounts</th><th class="text-end">Money held</th><th class="d-none d-md-table-cell">Last month closed</th><th>Where it stands</th>
                    </tr></thead>
                    <tbody id="boardRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
<?php endif ?>
