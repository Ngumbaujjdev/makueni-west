<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span>Members who moved in from, or out to, another church</span></div>
    <div class="page-toolbar-controls">
        <div id="yearSwitchWrap"></div>
        <?php if ($membersCtx['can']['manage']): ?>
        <button type="button" class="btn btn-outline-primary" id="outBtn"><i class="ri-logout-box-r-line me-1"></i>Transfer out</button>
        <button type="button" class="btn btn-primary" id="inBtn"><i class="ri-login-box-line me-1"></i>Transfer in</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Transfers</div><span class="card-subtitle-text" id="yearLine">This year</span></div>
    </div>
    <div class="card-body pb-0 pt-3" id="transferPills"></div>
    <div class="card-body p-0" id="transferTableWrap">
        <div id="transferFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table" id="transferTable">
                <thead>
                    <tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everyone on this page"></th>
                        <th>Who</th>
                        <th>Direction</th>
                        <th>The other church</th>
                        <th class="d-none d-lg-table-cell">Why</th>
                        <th>On</th>
                        <th class="text-end"><span class="visually-hidden">Open</span></th>
                    </tr>
                </thead>
                <tbody id="transferRows"></tbody>
            </table>
        </div>
    </div>
</div>
