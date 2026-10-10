<?php
$accButtons = $accCtx['can']['request'] ? '<button type="button" class="btn btn-primary" id="askBtn" data-own-only><i class="ri-hand-coin-line me-1"></i>Ask for money</button>' : '';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="nav section-tabs" id="rqTabs" role="tablist" aria-label="Requisitions">
    <button class="nav-link section-tab active" data-tab="requisitions" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-hand-coin-line"></i></span>
        <span class="section-tab-text"><strong>Requisitions</strong><small id="rqFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="advances" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-wallet-3-line"></i></span>
        <span class="section-tab-text"><strong>Advances</strong><small id="advFigure">&nbsp;</small></span>
    </button>
</div>

<div id="rqPane">
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title" id="rqTitle">Requisitions</div><span class="card-subtitle-text">Ask, approved, paid - open one to see where it stands</span></div></div>
        <div class="card-body pb-0 pt-3" id="rqPills"></div>
        <div class="card-body p-0" id="rqTableWrap">
            <div id="rqFilters" class="list-filterbar-wrap"></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 pp-table acc-table" id="rqTable">
                    <thead><tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                        <th>Requisition</th><th>Asked</th><th class="d-none d-md-table-cell">By</th><th class="d-none d-lg-table-cell">Where it stands</th><th class="text-end">Amount</th>
                    </tr></thead>
                    <tbody id="rqRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div id="advPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Advances</div><span class="card-subtitle-text">Money given ahead - each is accounted for with receipts and any change</span></div></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 acc-table" id="advTable"><thead><tr><th>To</th><th>For</th><th class="d-none d-md-table-cell">Given</th><th class="text-end">Amount</th><th class="text-end">Still out</th><th class="text-end">Action</th></tr></thead><tbody id="advRows"></tbody></table>
            </div>
        </div>
    </div>
</div>
