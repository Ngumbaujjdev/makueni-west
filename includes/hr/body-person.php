<div class="card custom-card" id="hrHero">
    <div class="card-body"><div class="d-flex align-items-center gap-3">
        <span class="skel" style="width:72px;height:72px;border-radius:50%"></span>
        <div class="flex-fill"><span class="skel skel-title"></span><span class="skel skel-line mt-2" style="width:40%"></span></div>
    </div></div>
</div>

<div class="nav section-tabs" id="hrTabs" role="tablist" aria-label="Staff member" hidden>
    <button class="nav-link section-tab active" data-tab="overview" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-user-line"></i></span>
        <span class="section-tab-text"><strong>Overview</strong><small>Their job and pay</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="payslips" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-success"><i class="ri-file-list-3-line"></i></span>
        <span class="section-tab-text"><strong>Payslips</strong><small id="hrSlipsFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="history" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-route-line"></i></span>
        <span class="section-tab-text"><strong>Where they have served</strong><small id="hrHistFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="papers" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-warning"><i class="ri-attachment-2"></i></span>
        <span class="section-tab-text"><strong>Papers</strong><small id="hrDocsFigure">&nbsp;</small></span>
    </button>
</div>

<div class="row g-4" id="hrPanes">
    <div class="col-xl-8" id="hrMain"></div>
    <div class="col-xl-4" id="hrSide"></div>
</div>
