<?php
// Send a message - v1-events' "Send campaign" composer as a page card: compose
// on the left (channel, a saved message to start from, the message with its
// placeholders and SMS counter, a test send), who gets it on the right (with
// the live counter and how it reads on a phone), then Review & send ->
// sending -> done. Filled in by assets/js/pages/messages/new.js.
// 2026-10-08: pills in two colours (saved messages teal, people purple), "About
// an event" (pick one of our events - its name, date and venue go in), Schedule
// in its own window, and how it reads in the real phone / mail-app frames.
?>
<div class="card custom-card pb-composer" id="composer">
    <div class="row g-0">

        <!-- Compose -->
        <div class="col-lg-7 pb-compose">
            <div class="pb-section">
                <div class="pb-section-title">Channel</div>
                <div class="pb-channels" id="channelCards"></div>
                <div class="fs-12 text-muted mt-2" id="channelHint"></div>
            </div>

            <div class="pb-section">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="pb-section-title mb-0">The message</div>
                    <a href="<?= $messagesCtx['baseUrl'] ?>/?tab=saved" class="fs-11 pb-footer-link"><i class="ri-edit-line me-1"></i>Manage saved messages</a>
                </div>
                <div class="pb-event mb-3" id="eventRow" hidden>
                    <div class="fs-12 text-muted mb-1">About an event <span class="opacity-75">- pick one and its name, date and venue go in for you.</span></div>
                    <div class="d-flex flex-wrap align-items-center gap-2" id="eventPicked"></div>
                </div>
                <div class="mb-3">
                    <div class="fs-12 text-muted mb-1">Start from a saved message <span class="opacity-75">- or write your own. You can change it after.</span></div>
                    <div class="pb-tpl-scroll d-flex flex-wrap gap-1" id="tplChips"></div>
                </div>
                <div class="mb-3" id="subjectWrap">
                    <label for="subjectIn" class="form-label fw-semibold fs-13">Subject</label>
                    <input type="text" id="subjectIn" class="form-control" maxlength="120" placeholder="e.g. Youth convention this Saturday">
                </div>
                <div>
                    <label for="bodyIn" class="form-label fw-semibold fs-13">Message <span class="text-danger">*</span></label>
                    <textarea id="bodyIn" rows="8" maxlength="10000" class="form-control" placeholder="Dear {name},&#10;&#10;Write your message here, or start from a saved message above..."></textarea>
                    <div class="d-flex justify-content-between gap-2 fs-11 mt-1">
                        <span class="text-muted">Placeholders are filled in for each person, so the real length varies.</span>
                        <span id="counter" class="fw-semibold text-muted text-nowrap"></span>
                    </div>
                    <div class="placeholder-chips mt-2">
                        <div class="text-muted fs-11 mb-1"><i class="ri-code-s-slash-line me-1"></i>Insert a placeholder - it's replaced for each person when sent:</div>
                        <div class="d-flex flex-wrap align-items-center gap-1">
                            <button type="button" class="pb-token" data-token="{name}" title="{name} - e.g. Stephen"><code>{name}</code>Their name</button>
                            <button type="button" class="pb-token" data-token="{place}" title="{place} - their church or region"><code>{place}</code>Their place</button>
                            <button type="button" class="pb-token" data-token="{sender}" title="{sender} - who it's from"><code>{sender}</code>Who it's from</button>
                            <span id="eventTokens" class="d-contents"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Send a test -->
            <div class="collapse" id="pbTestPanel">
                <div class="pb-section">
                    <div class="pb-section-title">Send a test first</div>
                    <label class="form-label fs-12 mb-1" for="testTo">Your phone or email</label>
                    <input type="text" id="testTo" class="form-control form-control-sm" placeholder="07XXXXXXXX or you@example.com">
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="testSendBtn"><i class="ri-send-plane-line me-1"></i>Send test</button>
                        <span class="fs-11 text-muted" id="testHint">Goes only to you, as it will read. It shows under Sent, marked [TEST].</span>
                    </div>
                    <div id="testResult" class="mt-2"></div>
                </div>
            </div>
        </div>

        <!-- Who gets it -->
        <div class="col-lg-5 pb-aside">
            <div class="pb-section-title">Who gets it</div>
            <div id="whoBody"><span class="skel" style="display:block;height:10rem"></span></div>

            <div class="pb-counter mt-3" id="pbCounter" aria-live="polite">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fs-12 fw-semibold text-muted">Who it reaches</span>
                    <span class="spinner-border spinner-border-sm text-muted d-none" id="pbCounterSpin"></span>
                </div>
                <div class="d-flex gap-4">
                    <div data-channel-block="app">
                        <div class="pb-counter-num" id="countApp">-</div>
                        <div class="fs-11 text-muted mt-1"><i class="ri-notification-3-line me-1"></i>in the app</div>
                    </div>
                    <div data-channel-block="sms">
                        <div class="pb-counter-num is-sms" id="countSms">-</div>
                        <div class="fs-11 text-muted mt-1"><i class="ri-message-2-line me-1"></i>SMS</div>
                    </div>
                    <div data-channel-block="email">
                        <div class="pb-counter-num is-email" id="countEmail">-</div>
                        <div class="fs-11 text-muted mt-1"><i class="ri-mail-line me-1"></i>emails</div>
                    </div>
                </div>
                <div class="fs-12 text-muted mt-2" id="countTotal"></div>
                <div id="counterNotes"></div>
            </div>

            <div class="d-flex align-items-center justify-content-between gap-2 mt-4 mb-2">
                <div class="pb-section-title mb-0">How it reads</div>
                <div class="pb-segment pb-reads-seg" id="readsSeg" role="radiogroup" aria-label="Show it as" hidden>
                    <input type="radio" name="readsUi" id="readsPhone" value="phone" checked><label for="readsPhone"><i class="ri-message-2-line me-1"></i>SMS</label>
                    <input type="radio" name="readsUi" id="readsEmail" value="email"><label for="readsEmail"><i class="ri-mail-line me-1"></i>Email</label>
                </div>
            </div>
            <div class="cm-pv-stage pb-reads" id="readsPhoneStage"></div>
            <div class="cm-pv-stage pb-reads" id="readsEmailStage" hidden></div>
            <div class="nw-seg" id="phoneSeg"></div>

            <div class="pb-section-title mt-4">When</div>
            <div class="pb-when" id="whenBox" aria-live="polite"></div>
        </div>
    </div>

    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= $messagesCtx['baseUrl'] ?>/?tab=sent" class="pb-footer-link"><i class="ri-send-plane-line me-1"></i>What we sent</a>
            <a href="#pbTestPanel" data-bs-toggle="collapse" class="pb-footer-link" id="testToggle" role="button" aria-expanded="false" aria-controls="pbTestPanel"><i class="ri-eye-line me-1"></i>Send a test</a>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-light" id="saveTemplateBtn"><i class="ri-bookmark-line me-1"></i>Save for later use</button>
            <button type="button" class="btn btn-primary" id="reviewBtn" disabled>Review &amp; send<i class="ri-arrow-right-line ms-1"></i></button>
        </div>
    </div>
</div>

<!-- Review -> sending -> done, or error: one state at a time (v1's #pbConfirmModal). -->
<div class="modal fade" id="pbConfirmModal" tabindex="-1" aria-labelledby="pbConfirmTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div data-pb-state="confirm">
                <div class="modal-body p-4">
                    <div class="pb-confirm-icon pb-confirm-icon-navy"><i class="ri-send-plane-line"></i></div>
                    <h5 class="text-center fw-bold mb-1" id="pbConfirmTitle">Ready to send?</h5>
                    <p class="text-center text-muted fs-13 mb-4" id="pbConfirmSub">Once sent, an SMS or email can't be called back.</p>
                    <div class="pb-summary" id="pbSummary"></div>
                    <div id="pbSummaryNotes" class="mt-3"></div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light" id="pbBackBtn"><i class="ri-arrow-left-line me-1"></i>Back to edit</button>
                    <button type="button" class="btn btn-primary" id="pbSendBtn"><i class="ri-send-plane-line me-1"></i>Yes, send it</button>
                </div>
            </div>
            <div data-pb-state="sending" class="d-none">
                <div class="modal-body p-4 text-center">
                    <div class="pb-confirm-icon pb-confirm-icon-navy"><span class="spinner-border spinner-border-sm"></span></div>
                    <h5 class="fw-bold mb-1" id="pbSendingMsg">Sending...</h5>
                    <p class="text-muted fs-13 mb-3">Putting it in each person's Inbox and handing the SMS and emails over.</p>
                    <div class="progress pb-progress mb-2"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%" role="progressbar" aria-label="Sending"></div></div>
                </div>
            </div>
            <div data-pb-state="done" class="d-none">
                <div class="modal-body p-4 text-center">
                    <div class="pb-confirm-icon pb-confirm-icon-green"><i class="ri-check-line"></i></div>
                    <h5 class="fw-bold mb-1" id="pbDoneTitle">Sent</h5>
                    <p class="text-muted fs-13 mb-3" id="pbDoneSub"></p>
                    <div class="row g-2" id="pbDoneTiles"></div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4 justify-content-center">
                    <a href="#" class="btn btn-light" id="pbDoneOpen"><i class="ri-list-check-2 me-1"></i>Who it went to</a>
                    <a href="<?= $messagesCtx['baseUrl'] ?>/?tab=sent" class="btn btn-primary">Done</a>
                </div>
            </div>
            <div data-pb-state="error" class="d-none">
                <div class="modal-body p-4 text-center">
                    <div class="pb-confirm-icon pb-confirm-icon-red"><i class="ri-error-warning-line"></i></div>
                    <h5 class="fw-bold mb-1">Nothing was sent</h5>
                    <div class="text-muted fs-13 mb-0" id="pbErrorBody"></div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4 justify-content-center">
                    <button type="button" class="btn btn-primary" id="pbErrorBackBtn"><i class="ri-arrow-left-line me-1"></i>Back to edit</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- About an event: pick one of our upcoming events (2026-10-08) -->
<div class="modal fade app-modal cm-modal" id="evPickModal" tabindex="-1" aria-labelledby="evPickTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <span class="app-modal-icon bg-purple text-white"><i class="ri-calendar-event-line"></i></span>
                <div class="flex-fill" style="min-width:0"><h5 class="modal-title" id="evPickTitle">Which event is it about?</h5><div class="app-modal-subtitle">Its name, date, time and venue go into the message for you</div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" id="evPickSearch" placeholder="Search events" aria-label="Search events">
                </div>
                <div class="pb-ev-list" id="evPickList"></div>
            </div>
        </div>
    </div>
</div>

<!-- Schedule: quick picks, the event's own times, or a set day and time (2026-10-08) -->
<div class="modal fade app-modal cm-modal" id="schedModal" tabindex="-1" aria-labelledby="schedTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <span class="app-modal-icon bg-primary text-white"><i class="ri-time-line"></i></span>
                <div class="flex-fill" style="min-width:0"><h5 class="modal-title" id="schedTitle">When should it go out?</h5><div class="app-modal-subtitle">From 5 minutes to 90 days ahead - it sends by itself at that time</div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="pb-sub">Quick picks</div>
                <div class="ec-choices pb-sched-picks" id="schedPicks" role="radiogroup" aria-label="Quick picks"></div>
                <div id="schedEventWrap" hidden>
                    <div class="pb-sub mt-3" id="schedEventTitle">Around the event</div>
                    <div class="ec-choices is-varied pb-sched-picks" id="schedEventPicks" role="radiogroup" aria-label="Around the event"></div>
                </div>
                <div class="pb-sub mt-3">Or pick a day and time</div>
                <div class="row g-2">
                    <div class="col-sm-7"><label class="form-label fs-12 mb-1" for="schedDate">Day</label><input type="date" class="form-control" id="schedDate"></div>
                    <div class="col-sm-5"><label class="form-label fs-12 mb-1" for="schedTime">Time</label><input type="time" class="form-control" id="schedTime" step="300"></div>
                </div>
                <div class="pb-sched-line mt-3" id="schedLine"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border me-auto" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="schedSave" disabled><i class="ri-time-line me-1"></i>Schedule it</button>
            </div>
        </div>
    </div>
</div>
