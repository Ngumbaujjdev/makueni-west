<?php
// Messages: the Inbox (everyone), our sent messages and saved messages (those who send).
// Filled in by assets/js/pages/messages/index.js.
$canSend = $messagesCtx['can']['send'];
$canRead = $messagesCtx['can']['read'];
?>
<div class="page-toolbar">
    <div class="page-toolbar-sub" id="placeLine"><?= htmlspecialchars($messagesCtx['place']['name'] ?: 'Messages') ?></div>
    <div class="page-toolbar-controls">
        <?php if ($canSend): ?>
        <a class="btn btn-primary" href="<?= $messagesCtx['baseUrl'] ?>/new"><i class="ri-send-plane-line me-1"></i>Send a message</a>
        <?php endif ?>
    </div>
</div>

<div class="nav section-tabs" id="msgTabs" role="tablist" aria-label="Messages">
    <button class="nav-link section-tab active" data-tab="inbox" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-inbox-line"></i></span>
        <span class="section-tab-text"><strong>Inbox</strong><small data-tab-figure="inbox">&nbsp;</small></span>
    </button>
    <?php if ($canRead): ?>
    <button class="nav-link section-tab" data-tab="sent" type="button" role="tab">
        <span class="section-tab-icon bg-success"><i class="ri-send-plane-line"></i></span>
        <span class="section-tab-text"><strong>Sent</strong><small data-tab-figure="sent">&nbsp;</small></span>
    </button>
    <?php endif ?>
    <?php if ($canSend): ?>
    <button class="nav-link section-tab" data-tab="saved" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-bookmark-line"></i></span>
        <span class="section-tab-text"><strong>Saved messages</strong><small data-tab-figure="saved">&nbsp;</small></span>
    </button>
    <?php endif ?>
</div>

<div class="msg-pane" data-pane="inbox">
    <div class="row g-4">
        <div class="col-xl-4 col-lg-5">
            <div class="card custom-card msg-list-card">
                <div class="card-header justify-content-between flex-wrap gap-2">
                    <div class="card-title">Inbox</div>
                    <div class="d-flex flex-wrap gap-1" id="inboxFilters"></div>
                </div>
                <div class="card-body p-0" id="inboxList"><div class="p-3"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:70%"></span></div></div>
            </div>
        </div>
        <div class="col-xl-8 col-lg-7">
            <div class="card custom-card msg-read" id="inboxRead"></div>
        </div>
    </div>
</div>

<?php if ($canRead): ?>
<div class="msg-pane" data-pane="sent" hidden>
    <div class="row" id="sentCardsRow"></div>
    <div class="card custom-card">
        <div class="card-header"><div class="card-title">Sent and scheduled</div></div>
        <div class="card-body pb-0"><div id="sentFilters"></div></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table text-nowrap w-100" id="sentTable">
                    <thead><tr><th>Message</th><th>To</th><th>How</th><th>Status</th><th>When</th><th class="text-end">Reached</th><th class="text-end">Replies</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif ?>

<?php if ($canSend): ?>
<div class="msg-pane" data-pane="saved" hidden>
    <div class="d-flex justify-content-end mb-3"><button type="button" class="btn btn-outline-primary" id="newTemplateBtn"><i class="ri-add-line me-1"></i>New saved message</button></div>
    <div class="row g-3" id="savedGrid"></div>
</div>
<?php endif ?>
