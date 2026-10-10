<?php
$accButtons = $accCtx['can']['payroll']
    ? '<button type="button" class="btn btn-outline-primary" id="personBtn" data-own-only><i class="ri-user-add-line me-1"></i>Add a person</button>'
      . '<button type="button" class="btn btn-primary" id="startBtn" data-own-only><i class="ri-play-circle-line me-1"></i><span id="startLabel">Start the month</span></button>'
    : '';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="nav section-tabs" id="pyTabs" role="tablist" aria-label="Payroll">
    <button class="nav-link section-tab active" data-tab="runs" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-calendar-check-line"></i></span>
        <span class="section-tab-text"><strong>Monthly runs</strong><small id="pyRunsFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="people" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-success"><i class="ri-team-line"></i></span>
        <span class="section-tab-text"><strong>People</strong><small id="pyPeopleFigure">&nbsp;</small></span>
    </button>
</div>

<div id="pyRunsPane">
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Monthly runs</div><span class="card-subtitle-text">Drafted, approved (which posts it), then net pay and each authority paid - open one for its payslips</span></div></div>
        <div class="card-body p-0" id="pyRunsWrap"><div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>Month</th><th class="text-end d-none d-md-table-cell">Gross</th><th class="text-end d-none d-lg-table-cell">Deductions</th><th class="text-end">Net pay</th><th>Where it stands</th></tr></thead><tbody id="pyRunRows"></tbody></table></div></div>
    </div>
</div>
<div id="pyPeoplePane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">People on the payroll</div><span class="card-subtitle-text">What each is paid a month and how - ID numbers and KRA PINs are kept private and shown masked</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>Person</th><th class="d-none d-md-table-cell">Paid to</th><th class="d-none d-lg-table-cell">KRA PIN</th><th class="text-end">A month</th><th class="text-end">Action</th></tr></thead><tbody id="pyPeopleRows"></tbody></table></div></div>
    </div>
</div>
