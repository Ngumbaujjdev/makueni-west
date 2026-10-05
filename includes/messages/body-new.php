<?php
// Send a message: who it goes to, the message, and a live preview. Filled in by assets/js/pages/messages/new.js.
?>
<div class="row g-4" id="composer">
    <div class="col-xl-8">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Who it goes to</div><span class="card-subtitle-text">Your own people, and the places below you</span></div></div>
            <div class="card-body" id="whoBody"><span class="skel" style="display:block;height:10rem"></span></div>
        </div>
        <div class="card custom-card">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">The message</div><span class="card-subtitle-text">{name}, {place} and {sender} are filled in for each person</span></div>
                <div id="templatePickWrap"></div>
            </div>
            <div class="card-body" id="messageBody"></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="intake-aside">
            <div class="card custom-card msg-preview-card">
                <div class="preview-head">
                    <span class="preview-label"><i class="ri-eye-line"></i>Preview</span>
                    <span id="reachChip"></span>
                </div>
                <div class="preview-section" id="previewBody"></div>
                <div class="preview-section">
                    <div class="msg-when" id="whenBox"></div>
                    <div class="d-grid gap-2 mt-3">
                        <button type="button" class="btn btn-success" id="sendBtn"><i class="ri-send-plane-line me-1"></i>Send now</button>
                        <button type="button" class="btn btn-outline-primary" id="saveTemplateBtn"><i class="ri-bookmark-line me-1"></i>Save for later use</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
