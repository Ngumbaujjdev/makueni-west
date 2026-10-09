<?php
$c = $accCtx['can'];
$accButtons = $c['collect'] ? '<button type="button" class="btn btn-primary" id="countBtn"><i class="ri-hand-coin-line me-1"></i>Record a collection</button>' : '';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="card custom-card" id="waitCard" hidden>
    <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">Waiting to be confirmed</div><span class="card-subtitle-text">A second person checks each count - only then is it receipted</span></div><span class="badge bg-warning text-dark" id="waitCount"></span></div>
    <div class="card-body p-0"><div class="acc-wait" id="waitList"></div></div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Every collection</div><span class="card-subtitle-text">Open one to confirm it, bank the cash or print the collection sheet</span></div>
    </div>
    <div class="card-body pb-0 pt-3" id="colPills"></div>
    <div class="card-body p-0" id="colTableWrap">
        <div id="colFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table acc-table" id="colTable">
                <thead><tr>
                    <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                    <th>Service</th><th>Date</th><th class="d-none d-lg-table-cell">What was given</th><th class="d-none d-md-table-cell">Where it stands</th><th class="text-end">Total</th>
                </tr></thead>
                <tbody id="colRows"></tbody>
            </table>
        </div>
    </div>
</div>
