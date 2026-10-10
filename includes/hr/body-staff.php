<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold"><?= htmlspecialchars($hrCtx['place']['name'] ?: 'Our place') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Pay is private</span></div>
    <div class="page-toolbar-controls">
        <a class="btn btn-outline-primary" href="<?= $hrCtx['payrollUrl'] ?>"><i class="ri-money-dollar-box-line me-1"></i>Payroll</a>
        <a class="btn btn-outline-primary" href="<?= $hrCtx['baseUrl'] ?>/positions.php"><i class="ri-briefcase-4-line me-1"></i>Positions &amp; pay</a>
        <?php if ($hrCtx['can']['manage']): ?>
        <button type="button" class="btn btn-primary" id="addBtn"><i class="ri-user-add-line me-1"></i>Add a person</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="nav section-tabs" id="hrTabs" role="tablist" aria-label="Staff">
    <button class="nav-link section-tab active" data-tab="ours" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-team-line"></i></span>
        <span class="section-tab-text"><strong>Our staff</strong><small id="hrOursFigure">&nbsp;</small></span>
    </button>
    <?php if ($hrCtx['can']['below']): ?>
    <button class="nav-link section-tab" data-tab="below" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-community-line"></i></span>
        <span class="section-tab-text"><strong>In the places below</strong><small id="hrBelowFigure">&nbsp;</small></span>
    </button>
    <?php endif ?>
</div>

<div id="hrOursPane">
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">The people we employ</div><span class="card-subtitle-text">Open someone for their job, pay, where they have served and their papers</span></div></div>
        <div class="card-body p-0" id="hrOursWrap"><div class="table-responsive"><table class="table table-hover mb-0 acc-table" id="hrOursTable"><thead><tr><th>Person</th><th class="d-none d-md-table-cell">Job</th><th class="d-none d-lg-table-cell">Paid by</th><th class="text-end">A month</th><th>Status</th></tr></thead><tbody id="hrOursRows"></tbody></table></div></div>
    </div>
</div>
<?php if ($hrCtx['can']['below']): ?>
<div id="hrBelowPane" hidden>
    <div class="row" id="hrPlacesRow"></div>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Staff in the places below</div><span class="card-subtitle-text">Their names, jobs and pay - each place keeps its own records; a move between places is made from here</span></div></div>
        <div class="card-body p-0" id="hrBelowWrap"><div class="table-responsive"><table class="table table-hover mb-0 acc-table" id="hrBelowTable"><thead><tr><th>Person</th><th>Place</th><th class="d-none d-md-table-cell">Job</th><th class="text-end">A month</th><th>Status</th></tr></thead><tbody id="hrBelowRows"></tbody></table></div></div>
    </div>
</div>
<?php endif ?>
