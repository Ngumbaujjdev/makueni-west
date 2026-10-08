<?php
// Messages - our own inbox, in the Demographics look (not the template's chat):
// section tabs with live figures, then one card with the list on the left and
// the reading pane on the right - the message as a letter, replies as a
// timeline, and a reply box. On a phone the list fills the screen and a
// message opens over it, with Back. Filled in by assets/js/pages/messages/index.js.
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
    <?php foreach (array_filter([
        ['inbox', 'ri-inbox-line', 'primary', 'Inbox', true],
        ['sent', 'ri-send-plane-line', 'success', 'Sent', $canRead],
        ['saved', 'ri-bookmark-line', 'purple', 'Saved', $canSend],
    ], fn ($t) => $t[4]) as $i => [$key, $icon, $color, $label]): ?>
    <button class="nav-link section-tab<?= $key === 'inbox' ? ' active' : '' ?>" data-tab="<?= $key ?>" type="button" role="tab" aria-selected="<?= $key === 'inbox' ? 'true' : 'false' ?>">
        <span class="section-tab-icon bg-<?= $color ?>"><i class="<?= $icon ?>"></i></span>
        <span class="section-tab-text"><strong><?= $label ?></strong><small data-tab-figure="<?= $key ?>">&nbsp;</small></span>
    </button>
    <?php endforeach ?>
</div>

<div class="card custom-card mi-inbox" id="miInbox">
    <div class="mi-list-col">
        <div class="mi-list-tools">
            <div class="input-group">
                <span class="input-group-text"><i class="ri-search-line"></i></span>
                <input type="search" class="form-control" id="miSearch" placeholder="Search messages" aria-label="Search messages">
            </div>
            <div class="d-flex flex-wrap gap-1 mt-2" id="miFrom" role="group" aria-label="Show messages from"></div>
        </div>
        <div class="mi-list" id="miList" aria-live="polite"></div>
    </div>
    <div class="mi-pane-col">
        <div class="mi-pane" id="miPane"></div>
    </div>
</div>
