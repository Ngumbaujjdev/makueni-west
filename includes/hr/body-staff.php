<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold"><?= htmlspecialchars($hrCtx['place']['name'] ?: 'Our place') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Pay is private</span></div>
    <div class="page-toolbar-controls">
        <a class="btn btn-outline-primary" href="<?= $hrCtx['baseUrl'] ?>/positions.php"><i class="ri-briefcase-4-line me-1"></i>Positions &amp; pay</a>
        <?php if ($hrCtx['can']['manage']): ?>
        <button type="button" class="btn btn-primary" id="addBtn"><i class="ri-user-add-line me-1"></i>Add a person</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div class="card-title">Staff <span class="badge bg-primary text-white ms-1" id="hrCount">0</span></div>
        <div class="btn-group" role="group" aria-label="View">
            <button type="button" class="btn btn-sm btn-primary" data-view="list"><i class="ri-list-check-2 me-1"></i>List</button>
            <button type="button" class="btn btn-sm btn-outline-primary" data-view="cards"><i class="ri-layout-grid-line me-1"></i>Cards</button>
        </div>
    </div>
    <div class="card-body pb-0 pt-3" id="hrPills"></div>
    <div class="card-body p-0" id="hrWrap">
        <div id="hrFilters" class="list-filterbar-wrap"></div>
        <div class="row g-3 p-3" id="hrCards" hidden></div>
        <div class="table-responsive" id="hrTableWrap">
            <table class="table table-hover mb-0 pp-table" id="hrTable">
                <thead>
                    <tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everyone on this page"></th>
                        <th>Person</th>
                        <th>Job</th>
                        <?php if ($hrCtx['can']['below']): ?><th>Place</th><?php endif ?>
                        <th class="d-none d-lg-table-cell">Phone</th>
                        <th class="text-end">A month</th>
                        <th>Status</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody id="hrRows"></tbody>
            </table>
        </div>
    </div>
</div>
