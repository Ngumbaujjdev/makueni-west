<?php
// Messages - the template's chat page (chat.html), its markup, classes and
// ids as they are: the list (.chat-info), the conversation (.main-chat-area)
// and the details (#chat-user-details). Our tabs are Inbox / Sent / Saved in
// the template's Recent / Groups / Calls panes. Filled in by
// assets/js/pages/messages/index.js.
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

<div class="row" id="msgCards"></div>

<div class="main-chart-wrapper p-2 gap-2 d-lg-flex">
    <div class="chat-info border">
        <?php if ($canSend): ?>
        <a aria-label="Send a message" href="<?= $messagesCtx['baseUrl'] ?>/new" class="btn btn-secondary btn-icon rounded-circle chat-add-icon"><i class="ri-add-line"></i></a>
        <?php endif ?>
        <div class="d-flex align-items-center justify-content-between w-100 p-3 border-bottom">
            <div><h5 class="fw-semibold mb-0">Messages</h5></div>
            <span class="badge bg-primary rounded-pill" id="unreadBadge" hidden></span>
        </div>
        <div class="chat-search p-3 border-bottom">
            <div class="input-group">
                <input type="text" class="form-control bg-light border-0" placeholder="Search messages" id="msgSearch" aria-label="Search messages">
                <button aria-label="Search" class="btn btn-light" type="button"><i class="ri-search-line text-muted"></i></button>
            </div>
        </div>
        <ul class="nav nav-tabs tab-style-2 nav-justified mb-0 border-bottom d-flex" id="myTab1" role="tablist">
            <li class="nav-item border-end me-0" role="presentation">
                <button class="nav-link active h-100" id="users-tab" data-bs-toggle="tab" data-bs-target="#users-tab-pane" data-tab="inbox" type="button" role="tab" aria-controls="users-tab-pane" aria-selected="true"><i class="ri-inbox-line me-1 align-middle d-inline-block"></i>Inbox</button>
            </li>
            <?php if ($canRead): ?>
            <li class="nav-item border-end me-0" role="presentation">
                <button class="nav-link h-100" id="groups-tab" data-bs-toggle="tab" data-bs-target="#groups-tab-pane" data-tab="sent" type="button" role="tab" aria-controls="groups-tab-pane" aria-selected="false"><i class="ri-send-plane-line me-1 align-middle d-inline-block"></i>Sent</button>
            </li>
            <?php endif ?>
            <?php if ($canSend): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link h-100" id="calls-tab" data-bs-toggle="tab" data-bs-target="#calls-tab-pane" data-tab="saved" type="button" role="tab" aria-controls="calls-tab-pane" aria-selected="false"><i class="ri-bookmark-line me-1 align-middle d-inline-block"></i>Saved</button>
            </li>
            <?php endif ?>
        </ul>
        <div class="tab-content" id="myTabContent">
            <div class="tab-pane fade show active border-0 chat-users-tab" id="users-tab-pane" role="tabpanel" aria-labelledby="users-tab" tabindex="0">
                <ul class="list-unstyled mb-0 mt-2 chat-users-tab" id="chat-msg-scroll"></ul>
            </div>
            <div class="tab-pane fade border-0 chat-groups-tab" id="groups-tab-pane" role="tabpanel" aria-labelledby="groups-tab" tabindex="0">
                <ul class="list-unstyled mb-0 mt-2 chat-users-tab" id="sentList"></ul>
            </div>
            <div class="tab-pane fade border-0 chat-calls-tab" id="calls-tab-pane" role="tabpanel" aria-labelledby="calls-tab" tabindex="0">
                <ul class="list-unstyled mb-0 mt-2 chat-users-tab" id="savedList"></ul>
            </div>
        </div>
    </div>

    <div class="main-chat-area border">
        <div class="d-flex align-items-center p-2 border-bottom">
            <div class="me-2 lh-1">
                <span class="avatar avatar-lg me-2 avatar-rounded bg-primary text-white chatstatusperson"><i class="ri-chat-3-line fs-20"></i></span>
            </div>
            <div class="flex-fill" style="min-width:0">
                <p class="mb-0 fw-semibold fs-14 text-truncate"><a href="javascript:void(0);" class="chatnameperson responsive-userinfo-open">Messages</a></p>
                <p class="text-muted mb-0 chatpersonstatus text-truncate">Pick one on the left</p>
            </div>
            <div class="d-flex flex-wrap rightIcons">
                <span class="my-1 ms-2 align-self-center" id="chatChannel"></span>
                <button aria-label="About this message" type="button" class="btn btn-icon btn-outline-light my-1 ms-2 responsive-userinfo-open"><i class="ri-information-line"></i></button>
                <button aria-label="Back to the list" type="button" class="btn btn-icon btn-outline-light my-1 ms-2 responsive-chat-close"><i class="ri-close-line"></i></button>
            </div>
        </div>
        <div class="chat-content" id="main-chat-content">
            <ul class="list-unstyled" id="chatThread"></ul>
        </div>
        <div class="chat-footer" id="chatFooter">
            <input class="form-control" placeholder="Pick a message to reply" type="text" disabled>
            <a aria-label="Send" class="btn btn-primary btn-icon btn-send ms-2 disabled" href="javascript:void(0)"><i class="ri-send-plane-2-line"></i></a>
        </div>
    </div>

    <div class="chat-user-details border" id="chat-user-details">
        <button aria-label="Close" type="button" class="btn btn-icon btn-outline-light my-1 ms-2 responsive-chat-close2"><i class="ri-close-line"></i></button>
        <div class="text-center mb-5">
            <span class="avatar avatar-rounded avatar-xxl me-2 mb-3 bg-primary text-white chatstatusperson" id="detailsAvatar"><i class="ri-chat-3-line fs-24"></i></span>
            <p class="mb-1 fs-15 fw-semibold text-dark lh-1 chatnameperson">Messages</p>
            <p class="fs-12 text-muted mb-0" id="detailsSub">&nbsp;</p>
        </div>
        <div class="mb-5" id="detailsBody"></div>
    </div>
</div>
