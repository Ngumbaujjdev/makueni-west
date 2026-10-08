<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($facCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <button type="button" class="btn btn-outline-primary" id="reportBtn"><i class="ri-tools-line me-1"></i>Report a repair</button>
        <?php if ($facCtx['can']['manage']): ?>
        <button type="button" class="btn btn-primary" id="addBtn"><i class="ri-add-line me-1"></i>Add equipment</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card" id="loansCard" hidden>
    <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">Out on loan</div><span class="card-subtitle-text">Who has what, and when it is due back</span></div><span id="loansHead"></span></div>
    <div class="card-body" id="loans"></div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">What we own</div><span class="card-subtitle-text"><?= $facCtx['can']['manage'] ? 'Tick items to move them or mark their condition together - or open one' : 'Open an item to see its loans and repairs' ?></span></div>
    </div>
    <div class="card-body pb-0 pt-3" id="eqPills"></div>
    <div class="card-body p-0" id="eqTableWrap">
        <div id="eqFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table" id="eqTable">
                <thead><tr>
                    <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                    <th>Item</th><th>Kept in</th><th>How many</th><th>Condition</th><th class="d-none d-lg-table-cell">Value</th><th class="text-end">Action</th>
                </tr></thead>
                <tbody id="eqRows"></tbody>
            </table>
        </div>
    </div>
</div>
