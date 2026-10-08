<!-- One ministry (docs/specs/people-and-care-spec.md, P4) - filled in by assets/js/pages/ministries/ministry.js. -->
<div class="card custom-card" id="mnHero">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3">
            <span class="skel" style="width:64px;height:64px;border-radius:50%"></span>
            <div class="flex-fill"><span class="skel skel-title"></span><span class="skel skel-line mt-2" style="width:40%"></span></div>
        </div>
    </div>
</div>

<div class="nav section-tabs" id="mnTabs" role="tablist" aria-label="Ministry" hidden>
    <button class="nav-link section-tab active" data-tab="members" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-group-line"></i></span>
        <span class="section-tab-text"><strong>Members</strong><small id="tabMembers">Who serves in it</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="gatherings" type="button" role="tab">
        <span class="section-tab-icon bg-success"><i class="ri-bar-chart-box-line"></i></span>
        <span class="section-tab-text"><strong>Gatherings</strong><small id="tabGatherings">From Attendance</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="activities" type="button" role="tab">
        <span class="section-tab-icon bg-pink"><i class="ri-calendar-event-line"></i></span>
        <span class="section-tab-text"><strong>Activities</strong><small>Events and initiatives for it</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="history" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-history-line"></i></span>
        <span class="section-tab-text"><strong>History</strong><small>Every change, and who made it</small></span>
    </button>
</div>

<div id="mnMain"></div>
