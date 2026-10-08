<!-- Record visitors - quick Sunday entry (docs/specs/people-and-care-spec.md, P2); filled in by assets/js/pages/visitors/new.js. -->
<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span>Everyone who visited, in one go - just a name, phone and area. A phone we know adds a visit, not a new person</span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
</div>

<form class="row g-4 vs-form" id="vsEntry" novalidate autocomplete="off">
    <div class="col-xl-8" id="vsEntryMain">
        <div class="card custom-card intake-form">
            <section class="intake-step">
                <div class="intake-step-head">
                    <span class="intake-step-num">1</span>
                    <div><h5>The gathering</h5><p>When they came, and who follows them up</p></div>
                </div>
                <div class="intake-step-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="vsOn">Date</label><input type="date" class="form-control" id="vsOn" required></div>
                        <div class="col-md-4"><label class="form-label" for="vsGathering">Service or gathering</label><select class="form-select" id="vsGathering"></select></div>
                        <div class="col-md-4"><label class="form-label" for="vsAssign">Follows them up</label><select class="form-select" id="vsAssign"><option value="">Nobody yet</option></select></div>
                    </div>
                </div>
            </section>
            <section class="intake-step">
                <div class="intake-step-head">
                    <span class="intake-step-num vs-step-purple">2</span>
                    <div class="flex-fill min-w-0"><h5>Who came</h5><p>Just a name, phone and area - a phone we know adds a visit, not a new person</p></div>
                    <span class="soft-chip soft-purple flex-shrink-0" id="vsCount">0 people</span>
                </div>
                <div class="intake-step-body vs-rows" id="vsRows"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:70%"></span></div>
                <datalist id="vsAreas"></datalist>
            </section>
            <div class="intake-foot">
                <span class="me-auto vs-foot-hint"><i class="ri-keyboard-line"></i>Press Enter to add the next person</span>
                <button type="button" class="btn btn-outline-primary" id="vsAddRow"><i class="ri-user-add-line me-1"></i>Add a person</button>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="intake-aside vs-aside">
            <div class="card custom-card preview-card">
                <div class="preview-head">
                    <span class="preview-label"><i class="ri-eye-line"></i>This gathering</span>
                    <span class="badge bg-primary" id="vsPrevCount">0 visitors</span>
                </div>
                <div class="preview-period" id="vsPrevWhen">&nbsp;</div>
                <div class="vs-prev-meta" id="vsPrevMeta">&nbsp;</div>
                <div class="preview-section">
                    <div class="preview-section-title">Who came</div>
                    <div class="vs-tally" id="vsTally"></div>
                </div>
                <div class="preview-section vs-welcome" id="vsWelcome" hidden>
                    <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" id="vsWelcomeSw"><label class="form-check-label fw-semibold" for="vsWelcomeSw">Send the welcome SMS</label></div>
                    <p class="mb-sub mb-2" id="vsWelcomeWho"></p>
                    <div class="vs-welcome-text" id="vsWelcomeText"></div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-3" id="vsSave"><i class="ri-check-line me-1"></i>Save visitors</button>
            </div>
        </div>
    </div>
</form>
