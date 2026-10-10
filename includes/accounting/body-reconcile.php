<?php
$accButtons = '<a class="btn btn-outline-primary" href="' . $accCtx['baseUrl'] . '/reconciliation.php" data-keep-place><i class="ri-arrow-left-line me-1"></i>Reconciliation</a><button type="button" class="btn btn-outline-primary" id="printBtn"><i class="ri-file-pdf-line me-1"></i>Statement (PDF)</button>';
include __DIR__ . '/toolbar.php';
?>
<div id="recApp">
    <div class="card custom-card"><div class="card-body"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</div></div>
</div>
