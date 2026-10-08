<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span id="placeLine"><?= htmlspecialchars($careCtx['place']['name'] ?: 'Our churches') ?></span><span class="soft-chip soft-primary"><i class="ri-eye-off-line"></i>Totals only - names and notes stay with each church</span></div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Each church</div><span class="card-subtitle-text">From the churches' own pastoral care - counts only</span></div>
    </div>
    <div class="card-body p-0" id="totalTableWrap">
        <div id="totalFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table text-nowrap table-hover mb-0" id="totalTable">
                <thead><tr><th>Church</th><th>Recording</th><th>Visits this month</th><th>Visits this year</th><th>Open cases</th><th>In hospital</th></tr></thead>
                <tbody id="totalRows"></tbody>
            </table>
        </div>
    </div>
</div>
