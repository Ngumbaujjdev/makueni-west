<?php
// Transactions (docs/specs/accounting-spec.md, A10e): every attempt to pay, failed ones included.
$accButtons = '<button type="button" class="btn btn-outline-primary" id="txCheckAll" hidden data-own-only><i class="ri-refresh-line me-1"></i>Check the waiting ones</button>'
    . '<button type="button" class="btn btn-primary" id="txCheckCode" hidden data-own-only><i class="ri-shield-check-line me-1"></i>Check an M-Pesa code</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>
<div id="txLate"></div>
<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Every attempt to pay</div><span class="card-subtitle-text">Gifts on the giving page, M-Pesa prompts and paybill payments - paid, waiting or not paid, with why. Open one to see what happened when.</span></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="btn-group" role="group" aria-label="Period" id="txPeriods"></div>
            <div id="txPlaceWrap" hidden><select class="form-select" id="txPlace" aria-label="Place"></select></div>
        </div>
    </div>
    <div class="card-body pb-0 pt-3" id="txPills"></div>
    <div class="card-body p-0" id="txTableWrap">
        <div id="txFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table acc-table" id="txTable">
                <thead><tr>
                    <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                    <th>When</th><th>Who paid</th><th class="d-none d-md-table-cell">For</th><th class="d-none d-lg-table-cell">How</th><th>Where it stands</th><th class="text-end">Amount</th>
                </tr></thead>
                <tbody id="txRows"></tbody>
            </table>
        </div>
    </div>
</div>
