<?php
$c = $accCtx['can'];
$accButtons = '<div class="acc-year-pick"><select class="form-select" id="yearPick" aria-label="Year"></select></div>'
    . '<button type="button" class="btn btn-outline-primary" id="openingBtn" data-own-only><i class="ri-scales-3-line me-1"></i>Opening balances</button>'
    . '<button type="button" class="btn btn-primary" id="journalBtn" data-own-only><i class="ri-book-2-line me-1"></i>Post a journal</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="row">
    <div class="col-xl-8">
        <?php $accListTitle = 'Journals'; $accListSub = 'Opening balances and corrections - always balanced'; include __DIR__ . '/doc-table.php'; ?>
    </div>
    <div class="col-xl-4">
        <div class="card custom-card">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">Trial balance</div><span class="card-subtitle-text">Every account's balance - the two sides must agree</span></div>
                <span id="tbBadge"></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive acc-tb-wrap">
                    <table class="table table-hover mb-0 acc-table acc-tb" id="tbTable">
                        <thead><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                        <tbody id="tbRows"></tbody>
                        <tfoot id="tbFoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
