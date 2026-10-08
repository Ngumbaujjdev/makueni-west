<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span>Every visit, call and case</span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <?php if ($careCtx['can']['manage']): ?>
        <button type="button" class="btn btn-primary" id="recordBtn"><i class="ri-add-line me-1"></i>Record care</button>
        <?php endif ?>
    </div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Care log</div><span class="card-subtitle-text">Tick cases to close them or give them to someone - confidential notes stay locked</span></div>
    </div>
    <div class="card-body pb-0 pt-3" id="carePills"></div>
    <div class="card-body p-0" id="careTableWrap">
        <div id="careFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table" id="careTable">
                <thead>
                    <tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everyone on this page"></th>
                        <th>Who</th>
                        <th>Kind</th>
                        <th>When</th>
                        <th>Status</th>
                        <th class="d-none d-md-table-cell">Next step</th>
                        <th class="d-none d-lg-table-cell">Who went</th>
                        <th class="d-none d-xl-table-cell">Note</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody id="careRows"></tbody>
            </table>
        </div>
    </div>
</div>
