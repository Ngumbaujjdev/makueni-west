<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold"><?= htmlspecialchars($hrCtx['place']['name'] ?: 'Our place') ?></span><span class="soft-chip soft-primary"><i class="ri-git-branch-line"></i>Ours, plus those set above us</span></div>
    <div class="page-toolbar-controls">
        <a class="btn btn-outline-primary" href="<?= $hrCtx['baseUrl'] ?>/"><i class="ri-team-line me-1"></i>Staff</a>
        <?php if ($hrCtx['can']['setup']): ?>
        <button type="button" class="btn btn-primary" id="addBtn"><i class="ri-add-line me-1"></i><span id="addLabel">Add a position</span></button>
        <?php endif ?>
    </div>
</div>

<div class="nav section-tabs" id="hrTabs" role="tablist" aria-label="Positions and pay">
    <button class="nav-link section-tab active" data-tab="position" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-briefcase-4-line"></i></span>
        <span class="section-tab-text"><strong>Positions</strong><small id="figPosition">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="grade" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-success"><i class="ri-bar-chart-box-line"></i></span>
        <span class="section-tab-text"><strong>Grades</strong><small id="figGrade">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="allowance" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-warning"><i class="ri-hand-coin-line"></i></span>
        <span class="section-tab-text"><strong>Allowances</strong><small id="figAllowance">&nbsp;</small></span>
    </button>
</div>

<div class="card custom-card">
    <div class="card-header"><div><div class="card-title" id="setTitle">Positions</div><span class="card-subtitle-text" id="setSub">The jobs people hold. Those set by the diocese or region are shown too - switch one off if you don't use it</span></div></div>
    <div class="card-body p-0" id="setWrap"><div class="table-responsive"><table class="table table-hover mb-0 acc-table" id="setTable"><thead><tr id="setHead"></tr></thead><tbody id="setRows"></tbody></table></div></div>
</div>
