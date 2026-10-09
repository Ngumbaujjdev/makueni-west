<?php
$c = $accCtx['can'];
$accButtons = $c['prepare'] ? '<button type="button" class="btn btn-primary" id="voucherBtn" data-own-only><i class="ri-file-list-3-line me-1"></i>Prepare a payment</button>' : '';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Payment vouchers</div><span class="card-subtitle-text">Every payment is prepared, authorised by someone else, then paid - open one to take the next step</span></div>
    </div>
    <div class="card-body pb-0 pt-3" id="pvPills"></div>
    <div class="card-body p-0" id="pvTableWrap">
        <div id="pvFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table acc-table" id="pvTable">
                <thead><tr>
                    <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                    <th>Voucher</th><th>Date</th><th class="d-none d-md-table-cell">Pay to</th><th class="d-none d-lg-table-cell">Where it stands</th><th class="text-end">Amount</th>
                </tr></thead>
                <tbody id="pvRows"></tbody>
            </table>
        </div>
    </div>
</div>
