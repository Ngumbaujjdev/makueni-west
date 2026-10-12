<div class="row">
    <div class="col-xxl-3 col-xl-4">
        <div class="card custom-card overflow-hidden hr-profile" id="hrProfile">
            <div class="card-body text-center p-4">
                <span class="skel d-inline-block" style="width:80px;height:80px;border-radius:50%"></span>
                <span class="skel skel-title mt-3 mx-auto" style="width:60%"></span>
                <span class="skel skel-line mt-2 mx-auto" style="width:40%"></span>
            </div>
        </div>
    </div>
    <div class="col-xxl-9 col-xl-8">
        <div class="nav section-tabs" id="hrTabs" role="tablist" aria-label="Staff member" hidden>
            <button class="nav-link section-tab active" data-tab="overview" type="button" role="tab" aria-selected="true">
                <span class="section-tab-icon bg-primary"><i class="ri-user-line"></i></span>
                <span class="section-tab-text"><strong>Overview</strong><small id="hrPayFigure">&nbsp;</small></span>
            </button>
            <button class="nav-link section-tab" data-tab="payslips" type="button" role="tab" aria-selected="false">
                <span class="section-tab-icon bg-success"><i class="ri-file-list-3-line"></i></span>
                <span class="section-tab-text"><strong>Payslips</strong><small id="hrSlipsFigure">&nbsp;</small></span>
            </button>
            <button class="nav-link section-tab" data-tab="history" type="button" role="tab" aria-selected="false">
                <span class="section-tab-icon bg-purple"><i class="ri-route-line"></i></span>
                <span class="section-tab-text"><strong>Where they served</strong><small id="hrHistFigure">&nbsp;</small></span>
            </button>
            <button class="nav-link section-tab" data-tab="papers" type="button" role="tab" aria-selected="false">
                <span class="section-tab-icon bg-warning"><i class="ri-attachment-2"></i></span>
                <span class="section-tab-text"><strong>Papers</strong><small id="hrDocsFigure">&nbsp;</small></span>
            </button>
        </div>
        <div id="hrPanes"><div id="hrMain"></div></div>
    </div>
</div>
