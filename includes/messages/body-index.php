<?php
// Messages - Chat (docs/specs/messages-spec.md, L6), in the template's
// chat.html as it is: the list (.chat-info) with Recent / Groups / Contacts
// (the template's Calls tab), the conversation (.main-chat-area) and the
// details (.chat-user-details). Recent holds one-to-one chats, groups and
// the announcements sent down to us. Live through Reverb; on a phone the
// list and the chat take turns. Filled in by assets/js/pages/messages/index.js.
$canSend = $messagesCtx['can']['send'];
?>
<div class="main-chart-wrapper gap-2 d-lg-flex mi-chat" id="miChat">
    <div class="chat-info border">
        <a aria-label="New group" title="New group" href="javascript:void(0);" class="btn btn-primary btn-icon rounded-circle chat-add-icon" data-new-group><i class="ri-add-line"></i></a>
        <div class="d-flex align-items-center justify-content-between w-100 p-3 border-bottom">
            <div class="min-w-0">
                <h5 class="fw-semibold mb-0">Messages <span class="badge bg-primary rounded-pill fs-11 ms-1 align-middle" id="miUnread" hidden></span></h5>
                <div class="fs-12 mi-chat-live" id="miLive"><span class="mi-chat-live-dot"></span><span id="miLiveText">Connecting...</span></div>
            </div>
            <div class="dropdown">
                <button aria-label="More" title="More" class="btn btn-icon btn-secondary-light btn-wave waves-light" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-settings-3-line"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="javascript:void(0);" data-new-group><i class="ri-group-2-line me-2"></i>New group</a></li>
                    <li><a class="dropdown-item" href="javascript:void(0);" data-go-contacts><i class="ri-chat-new-line me-2"></i>New chat</a></li>
                    <?php if ($canSend): ?>
                    <li><a class="dropdown-item" href="<?= $messagesCtx['baseUrl'] ?>/new"><i class="ri-broadcast-line me-2"></i>Send an announcement</a></li>
                    <?php endif ?>
                </ul>
            </div>
        </div>
        <div class="chat-search p-3 border-bottom">
            <div class="input-group">
                <input type="search" class="form-control bg-light border-0" placeholder="Search chats and people" id="miSearch" aria-label="Search chats and people">
                <button aria-label="Search" class="btn btn-light" type="button"><i class="ri-search-line text-muted"></i></button>
            </div>
        </div>
        <ul class="nav nav-tabs tab-style-2 nav-justified mb-0 border-bottom d-flex" id="myTab1" role="tablist">
            <li class="nav-item border-end me-0" role="presentation">
                <button class="nav-link active h-100" id="users-tab" data-bs-toggle="tab" data-bs-target="#users-tab-pane" data-tab="recent" type="button" role="tab" aria-controls="users-tab-pane" aria-selected="true"><i class="ri-history-line me-1 align-middle d-inline-block"></i>Recent</button>
            </li>
            <li class="nav-item border-end me-0" role="presentation">
                <button class="nav-link h-100" id="groups-tab" data-bs-toggle="tab" data-bs-target="#groups-tab-pane" data-tab="groups" type="button" role="tab" aria-controls="groups-tab-pane" aria-selected="false"><i class="ri-group-2-line me-1 align-middle d-inline-block"></i>Groups</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link h-100" id="calls-tab" data-bs-toggle="tab" data-bs-target="#calls-tab-pane" data-tab="contacts" type="button" role="tab" aria-controls="calls-tab-pane" aria-selected="false"><i class="ri-contacts-book-line me-1 align-middle d-inline-block"></i>Contacts</button>
            </li>
        </ul>
        <div class="tab-content" id="myTabContent">
            <div class="tab-pane fade show active border-0 chat-users-tab" id="users-tab-pane" role="tabpanel" aria-labelledby="users-tab" tabindex="0">
                <ul class="list-unstyled mb-0 mt-2 chat-users-tab" id="chat-msg-scroll" aria-live="polite"></ul>
            </div>
            <div class="tab-pane fade border-0 chat-groups-tab" id="groups-tab-pane" role="tabpanel" aria-labelledby="groups-tab" tabindex="0">
                <ul class="list-unstyled mb-0 mt-2" id="miGroups"></ul>
            </div>
            <div class="tab-pane fade border-0 chat-calls-tab" id="calls-tab-pane" role="tabpanel" aria-labelledby="calls-tab" tabindex="0">
                <ul class="list-unstyled mb-0 mt-2 chat-calls-tab" id="miContacts"></ul>
            </div>
        </div>
    </div>

    <div class="main-chat-area border" id="miMain">
        <div class="mi-chat-empty"><span class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading</span></span></div>
    </div>

    <div class="chat-user-details border" id="chat-user-details"></div>
</div>

<!-- New group / Add people: the contacts, ticked -->
<div class="modal fade app-modal" id="miPeopleModal" tabindex="-1" aria-labelledby="miPeopleTitle">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <span class="app-modal-icon"><i class="ri-group-2-line"></i></span>
                <div class="flex-fill min-w-0"><h5 class="modal-title" id="miPeopleTitle">New group</h5><div class="app-modal-subtitle" id="miPeopleSub">Name it, and pick who's in it</div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <section class="app-modal-part" id="miGroupNamePart">
                    <div class="app-modal-part-head"><i class="ri-edit-line"></i>Group name</div>
                    <input class="form-control" id="miGroupName" maxlength="80" placeholder="e.g. Sultan Hamud leaders">
                </section>
                <section class="app-modal-part">
                    <div class="app-modal-part-head"><i class="ri-user-add-line"></i>People<small id="miPickedCount">None picked yet</small></div>
                    <div class="pp-chips mb-2" id="miPicked"></div>
                    <div class="pp-picker-search mb-2"><i class="ri-search-line"></i><input type="search" class="form-control" id="miPickSearch" placeholder="Search by name, church or role" autocomplete="off" aria-label="Search people"></div>
                    <div class="mi-pick-list" id="miPickList"></div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="miPeopleGo"><i class="ri-check-line me-1"></i>Create group</button>
            </div>
        </div>
    </div>
</div>
