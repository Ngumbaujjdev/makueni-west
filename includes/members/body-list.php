<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span id="placeLine"><?= htmlspecialchars($membersCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <?php if ($membersCtx['can']['export']): ?>
        <button type="button" class="btn btn-outline-primary" data-report-key="members.directory" data-module="members" data-lock="1"><i class="ri-download-2-line me-1"></i>Export</button>
        <?php endif ?>
        <a class="btn btn-outline-primary" href="<?= $membersCtx['baseUrl'] ?>/transfers"><i class="ri-arrow-left-right-line me-1"></i>Transfers</a>
        <?php if ($membersCtx['can']['manage']): ?>
        <a class="btn btn-primary" href="<?= $membersCtx['baseUrl'] ?>/new"><i class="ri-user-add-line me-1"></i>Add member</a>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div>
            <div class="card-title">Our members</div>
            <span class="card-subtitle-text">Find anyone by name, phone or area, then open their page</span>
        </div>
        <div class="btn-group" role="group" aria-label="Which list" id="listSwitch">
            <button type="button" class="btn btn-sm btn-primary" data-list="current">In the register</button>
            <button type="button" class="btn btn-sm btn-outline-primary" data-list="archived">Archived</button>
        </div>
    </div>
    <div class="card-body p-0" id="memberTableWrap">
        <div id="memberFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="memberTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Area</th>
                        <th>Part of</th>
                        <th class="d-none d-md-table-cell">Gender</th>
                        <th>Status</th>
                        <th class="d-none d-lg-table-cell">Joined</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody id="memberRows"></tbody>
            </table>
        </div>
    </div>
</div>
