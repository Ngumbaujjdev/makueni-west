<?php
$c = $accCtx['can'];
$accButtons = '<div class="acc-year-pick"><select class="form-select" id="yearPick" aria-label="Year"></select></div>'
    . ($c['receipt'] ? '<button type="button" class="btn btn-primary" id="receiptBtn" data-own-only><i class="ri-bill-line me-1"></i>Write a receipt</button>' : '');
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>
<?php $accListTitle = 'Every document'; $accListSub = 'Receipts, payments, transfers, journals and reversals - open one for its lines and papers'; include __DIR__ . '/doc-table.php'; ?>
