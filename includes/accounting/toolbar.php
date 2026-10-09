<?php
// The strip under every Accounting page title: whose books (with the picker for a region or the
// diocese) and the page's write buttons, shown only for our own books (AccountingUI.ownOnly).
// $accButtons: the HTML of the buttons for this page.
?>
<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2" id="accPlaceLine"><span class="fw-semibold"><?= htmlspecialchars($accCtx['place']['name']) ?></span></div>
    <div class="page-toolbar-controls">
        <div class="acc-place-pick" id="accPlacePick" hidden></div>
        <?= $accButtons ?? '' ?>
    </div>
</div>
