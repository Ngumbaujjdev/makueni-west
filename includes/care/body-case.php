<!-- A care case (docs/specs/people-and-care-spec.md, P3) - filled in by assets/js/pages/care/case.js. -->
<div class="card custom-card" id="crHero">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3">
            <span class="skel" style="width:64px;height:64px;border-radius:50%"></span>
            <div class="flex-fill"><span class="skel skel-title"></span><span class="skel skel-line mt-2" style="width:40%"></span></div>
        </div>
    </div>
</div>

<div class="nav section-tabs" id="crTabs" role="tablist" aria-label="Care" hidden>
    <button class="nav-link section-tab active" data-tab="timeline" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-time-line"></i></span>
        <span class="section-tab-text"><strong>Timeline</strong><small id="tabContacts">Each visit and call</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="details" type="button" role="tab">
        <span class="section-tab-icon bg-pink"><i class="ri-file-list-3-line"></i></span>
        <span class="section-tab-text"><strong>Details</strong><small>Kind, who went, next step</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="history" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-history-line"></i></span>
        <span class="section-tab-text"><strong>History</strong><small>Every change, and who made it</small></span>
    </button>
</div>

<div class="row g-4" id="crPanes">
    <div class="col-xl-8" id="crMain"></div>
    <div class="col-xl-4" id="crSide"></div>
</div>
