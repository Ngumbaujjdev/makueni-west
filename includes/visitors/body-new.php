<!-- Record visitors - quick Sunday entry (docs/specs/people-and-care-spec.md, P2); filled in by assets/js/pages/visitors/new.js. -->
<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span>Everyone who visited, in one go - a phone we know adds a visit, not a new person</span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
</div>

<form class="row g-4 vs-form" id="vsEntry" novalidate autocomplete="off">
    <div class="col-xl-8" id="vsEntryMain">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">The gathering</div><span class="card-subtitle-text">When they came, and who follows them up</span></div></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label" for="vsOn">Date</label><input type="date" class="form-control" id="vsOn" required></div>
                    <div class="col-md-4"><label class="form-label" for="vsGathering">Gathering <span class="fw-normal">(optional)</span></label><select class="form-select" id="vsGathering"><option value="">Not linked to one</option></select></div>
                    <div class="col-md-4"><label class="form-label" for="vsAssign">Follows them up</label><select class="form-select" id="vsAssign"><option value="">Nobody yet</option></select></div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">Visitors</div><span class="card-subtitle-text">Press Enter to add the next person</span></div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="vsAddRow"><i class="ri-add-line me-1"></i>Add a person</button>
            </div>
            <div class="card-body" id="vsRows"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:70%"></span></div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card custom-card vs-sticky">
            <div class="card-header"><div class="card-title">This gathering</div></div>
            <div class="card-body">
                <div class="vs-tally" id="vsTally"></div>
                <div class="vs-welcome" id="vsWelcome" hidden>
                    <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" id="vsWelcomeSw"><label class="form-check-label fw-semibold" for="vsWelcomeSw">Send the welcome SMS</label></div>
                    <p class="mb-sub mb-2" id="vsWelcomeWho"></p>
                    <div class="vs-welcome-text" id="vsWelcomeText"></div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-3" id="vsSave"><i class="ri-check-line me-1"></i>Save visitors</button>
            </div>
        </div>
    </div>
</form>
