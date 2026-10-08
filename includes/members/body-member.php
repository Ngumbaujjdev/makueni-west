<!-- A member's page (docs/specs/people-and-care-spec.md, P1) - filled in by assets/js/pages/members/member.js. -->
<div class="card custom-card" id="mbHero">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3">
            <span class="skel" style="width:72px;height:72px;border-radius:50%"></span>
            <div class="flex-fill"><span class="skel skel-title"></span><span class="skel skel-line mt-2" style="width:40%"></span></div>
        </div>
    </div>
</div>

<div class="nav section-tabs" id="mbTabs" role="tablist" aria-label="Member" hidden>
    <button class="nav-link section-tab active" data-tab="overview" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-user-heart-line"></i></span>
        <span class="section-tab-text"><strong>Overview</strong><small>Their details and journey</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="history" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-history-line"></i></span>
        <span class="section-tab-text"><strong>History</strong><small>Every change, and who made it</small></span>
    </button>
</div>

<div class="row g-4" id="mbPanes">
    <div class="col-xl-8" id="mbMain"></div>
    <div class="col-xl-4" id="mbSide"></div>
</div>

