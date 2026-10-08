<?php
// Messages - our inbox in the template's chat layout (chat.html, 2026-10-08):
// the list (.chat-info) with All / Diocese / Region / Church tabs, the
// conversation (.main-chat-area) - the message as their bubble, your replies
// as yours, and the reply box - and who sent it (.chat-user-details): the
// person, their place, its photos and their other messages. On a phone the
// list fills the screen and a message opens over it, with Back. Filled in by
// assets/js/pages/messages/index.js.
$canSend = $messagesCtx['can']['send'];
?>
<div class="main-chart-wrapper gap-2 d-lg-flex mi-chat" id="miChat">
    <div class="chat-info border">
        <?php if ($canSend): ?>
        <a aria-label="Send a message" title="Send a message" href="<?= $messagesCtx['baseUrl'] ?>/new" class="btn btn-primary btn-icon rounded-circle chat-add-icon"><i class="ri-add-line"></i></a>
        <?php endif ?>
        <div class="d-flex align-items-center justify-content-between gap-2 w-100 p-3 border-bottom">
            <div class="min-w-0">
                <h5 class="fw-semibold mb-0">Messages <span class="badge bg-primary rounded-pill fs-11 ms-1 align-middle" id="miUnread" hidden></span></h5>
                <div class="fs-12 text-truncate mi-chat-place"><?= htmlspecialchars($messagesCtx['place']['name'] ?: '') ?></div>
            </div>
            <?php if ($canSend): ?>
            <a class="btn btn-sm btn-primary-light flex-shrink-0" href="<?= $messagesCtx['baseUrl'] ?>/new"><i class="ri-send-plane-line me-1"></i>New</a>
            <?php endif ?>
        </div>
        <div class="chat-search p-3 border-bottom">
            <div class="input-group">
                <input type="search" class="form-control bg-light border-0" placeholder="Search your messages" id="miSearch" aria-label="Search your messages">
                <span class="input-group-text bg-light border-0"><i class="ri-search-line"></i></span>
            </div>
        </div>
        <ul class="nav nav-tabs tab-style-2 nav-justified mb-0 border-bottom d-flex mi-chat-tabs" id="miFrom" role="tablist" aria-label="Show messages from"></ul>
        <ul class="list-unstyled mb-0 mt-2 chat-users-tab mi-chat-list" id="miList" aria-live="polite"></ul>
    </div>

    <div class="main-chat-area border" id="miMain">
        <div class="mi-chat-empty">
            <span class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading</span></span>
        </div>
    </div>

    <div class="chat-user-details border" id="chat-user-details"></div>
</div>
