<?php
// Send a message - v1-events' "Send campaign" composer as a page card: compose
// on the left (channel, a saved message to start from, the message with its
// placeholders and SMS counter, a test send), who gets it on the right (with
// the live counter and how it reads on a phone), then Review & send ->
// sending -> done. Filled in by assets/js/pages/messages/new.js.
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
                            <button type="button" class="btn btn-light border btn-sm py-0 px-2 fs-11" data-token="{name}" title="{name} - e.g. Stephen">Their name</button>
                            <button type="button" class="btn btn-light border btn-sm py-0 px-2 fs-11" data-token="{place}" title="{place} - their church or region">Their place</button>
                            <button type="button" class="btn btn-light border btn-sm py-0 px-2 fs-11" data-token="{sender}" title="{sender} - who it's from">Who it's from</button>
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

            <div class="pb-section-title mt-4">How it reads</div>
            <div class="nw-phone">
                <div class="nw-phone-from" id="phoneFrom"></div>
                <div class="nw-bubble" id="phoneBubble"></div>
            </div>
            <div class="nw-seg" id="phoneSeg"></div>

            <div class="pb-section-title mt-4">When</div>
            <div class="pb-segment" role="radiogroup" aria-label="When">
                <input type="radio" name="whenUi" id="whenNow" value="now" checked>
                <label for="whenNow"><i class="ri-send-plane-line me-1"></i>Send now</label>
                <input type="radio" name="whenUi" id="whenLater" value="later">
                <label for="whenLater"><i class="ri-time-line me-1"></i>Schedule</label>
            </div>
            <div class="mt-2" id="laterWrap" hidden>
                <label class="form-label fs-12 mb-1" for="sendAtIn">Send on</label>
                <input type="datetime-local" class="form-control form-control-sm" id="sendAtIn">
            </div>
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
