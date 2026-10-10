<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold"><?= htmlspecialchars($hrCtx['place']['name'] ?: 'Our place') ?></span><span class="soft-chip soft-primary"><i class="ri-git-branch-line"></i>Ours and those set above us</span></div>
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
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div class="card-title"><span id="setTitle">Positions</span> <span class="badge bg-primary text-white ms-1" id="setCount">0</span></div>
    </div>
    <div class="card-body pb-0 pt-3" id="setPills"></div>
    <div class="card-body p-0" id="setWrap">
        <div id="setFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table" id="setTable">
                <thead>
                    <tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                        <th>Name</th>
                        <th class="d-none d-md-table-cell">Set by</th>
                        <th>Here</th>
                        <th class="text-end d-none d-sm-table-cell">People</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody id="setRows"></tbody>
            </table>
        </div>
    </div>
</div>
