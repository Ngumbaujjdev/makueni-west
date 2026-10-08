<?php
// Communication > Messages > Campaigns (2026-10-08): everything we sent out - this month's figures, then one
// row per message (who it reached, replies, status), filtered in place. Filled in by assets/js/pages/messages/campaigns.js.
?>
<div class="page-toolbar">
    <div class="page-toolbar-sub"><?= htmlspecialchars($messagesCtx['place']['name'] ?: 'Campaigns') ?> · everything we sent out</div>
    <div class="page-toolbar-controls">
        <div class="pb-segment" id="cpYear" role="radiogroup" aria-label="Year"></div>
        <?php if ($messagesCtx['can']['send']): ?>
        <a class="btn btn-primary" href="<?= $messagesCtx['baseUrl'] ?>/new"><i class="ri-add-line me-1"></i>New campaign</a>
        <?php endif ?>
    </div>
</div>
<div class="row" id="cpKpis"></div>
<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm bg-success text-white"><i class="ri-broadcast-line"></i></span>
            <div><div class="card-title mb-0">Campaigns</div><div class="settings-card-sub" id="cpSub">&nbsp;</div></div>
        </div>
    </div>
    <div class="card-body">
        <div id="cpToolbar"></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 cm-table" id="cpTable">
                <thead><tr><th>When</th><th>Message</th><th>Channel</th><th>Reached</th><th>Replies</th><th>Status</th><th></th></tr></thead>
                <tbody id="cpBody"></tbody>
            </table>
        </div>
    </div>
</div>
