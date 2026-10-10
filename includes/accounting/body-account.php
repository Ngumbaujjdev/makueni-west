<?php
// One account's page (Redesign R2): its balance, money in and out, twelve months, the latest
// movements and the budget lines that post to it - drawn by account.js.
$accButtons = '<a class="btn btn-outline-primary" href="' . $accCtx['baseUrl'] . '/accounts.php" data-keep-place><i class="ri-arrow-left-line me-1"></i>Cash &amp; bank</a>'
    . '<a class="btn btn-primary" id="acCashbook" href="' . $accCtx['baseUrl'] . '/cashbook.php" data-keep-place hidden><i class="ri-book-open-line me-1"></i>Cashbook</a>';
include __DIR__ . '/toolbar.php';
?>
<div id="acApp">
    <div class="card custom-card"><div class="card-body"><div class="placeholder-glow"><span class="placeholder col-4 mb-3"></span><span class="placeholder col-8 mb-2"></span><span class="placeholder col-6"></span></div></div></div>
</div>
