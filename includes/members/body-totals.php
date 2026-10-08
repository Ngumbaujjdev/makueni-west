<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span id="placeLine"><?= htmlspecialchars($membersCtx['place']['name'] ?: 'Our churches') ?></span><span class="soft-chip soft-primary"><i class="ri-eye-off-line"></i>Totals only - names stay with each church</span></div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Each church</div><span class="card-subtitle-text">From the churches' own registers - counts only</span></div>
    </div>
    <div class="card-body pb-0"><div id="totalFilters"></div></div>
    <div class="card-body" id="totalTableWrap">
        <div class="table-responsive">
            <table class="table text-nowrap table-hover mb-0" id="totalTable">
                <thead><tr><th>Church</th><th>Register</th><th>Active members</th><th>New this month</th><th>Baptised</th><th>Moved in</th><th>Moved out</th></tr></thead>
                <tbody id="totalRows"></tbody>
            </table>
        </div>
    </div>
</div>
