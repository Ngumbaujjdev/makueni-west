<!-- A visitor's page (docs/specs/people-and-care-spec.md, P2) - filled in by assets/js/pages/visitors/visitor.js. -->
<div class="card custom-card" id="vsHero">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3">
            <span class="skel" style="width:72px;height:72px;border-radius:50%"></span>
            <div class="flex-fill"><span class="skel skel-title"></span><span class="skel skel-line mt-2" style="width:40%"></span></div>
        </div>
    </div>
</div>

<div class="nav section-tabs" id="vsTabs" role="tablist" aria-label="Visitor" hidden>
    <button class="nav-link section-tab active" data-tab="followup" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-phone-line"></i></span>
        <span class="section-tab-text"><strong>Follow-up</strong><small id="tabFollowups">Calls, messages and visits</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="visits" type="button" role="tab">
        <span class="section-tab-icon bg-success"><i class="ri-calendar-check-line"></i></span>
        <span class="section-tab-text"><strong>Visits</strong><small id="tabVisits">When they came</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="details" type="button" role="tab">
        <span class="section-tab-icon bg-pink"><i class="ri-user-3-line"></i></span>
        <span class="section-tab-text"><strong>Details</strong><small>Name, phone and area</small></span>
    </button>
    <?php if ($visitorsCtx['can']['care']): ?>
    <button class="nav-link section-tab" data-tab="care" type="button" role="tab">
        <span class="section-tab-icon bg-danger"><i class="ri-heart-pulse-line"></i></span>
        <span class="section-tab-text"><strong>Care</strong><small>Visits, prayer and pastoral care</small></span>
    </button>
    <?php endif ?>
    <button class="nav-link section-tab" data-tab="history" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-history-line"></i></span>
        <span class="section-tab-text"><strong>History</strong><small>Every change, and who made it</small></span>
    </button>
</div>

<div class="row g-4" id="vsPanes">
    <div class="col-xl-8" id="vsMain"></div>
    <div class="col-xl-4" id="vsSide"></div>
</div>
