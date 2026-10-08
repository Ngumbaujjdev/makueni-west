<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span id="placeLine"><?= htmlspecialchars($visitorsCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <?php if ($visitorsCtx['can']['insights']): ?>
        <a class="btn btn-outline-primary" href="<?= $visitorsCtx['baseUrl'] ?>/insights"><i class="ri-pie-chart-2-line me-1"></i>Insights</a>
        <?php endif ?>
        <?php if ($visitorsCtx['can']['message']): ?>
        <a class="btn btn-outline-primary" href="<?= $visitorsCtx['messagesUrl'] ?>?channel=sms&amp;register=visitors"><i class="ri-chat-3-line me-1"></i>Message visitors</a>
        <?php endif ?>
        <?php if ($visitorsCtx['can']['manage']): ?>
        <a class="btn btn-primary" href="<?= $visitorsCtx['baseUrl'] ?>/new"><i class="ri-user-add-line me-1"></i>Record visitors</a>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card vs-due-card" id="dueCard">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm avatar-rounded bg-danger text-white"><i class="ri-alarm-warning-line"></i></span>
            <div>
                <div class="card-title">My follow-ups</div>
                <span class="card-subtitle-text">Given to you, or nobody's yet - due this week or late</span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2" id="dueHead"></div>
    </div>
    <div class="card-body" id="dueBody"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:60%"></span></div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div>
            <div class="card-title">Our visitors</div>
            <span class="card-subtitle-text" id="viewHint">Drag a card to move them along - from new to regular</span>
        </div>
        <div id="viewSwitchWrap"></div>
    </div>
    <div class="card-body pb-0 pt-3" id="visitorPills"></div>
    <div class="card-body p-0">
        <div id="visitorFilters" class="list-filterbar-wrap"></div>
        <div class="vs-board-wrap" id="vsBoardWrap"><div id="vsBoard"></div></div>
        <div id="vsTableWrap" hidden>
            <div class="table-responsive">
                <table class="table table-hover mb-0 pp-table" id="visitorTable">
                    <thead>
                        <tr>
                            <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everyone on this page"></th>
                            <th>Name</th>
                            <th>Stage</th>
                            <th>Area</th>
                            <th class="d-none d-md-table-cell">Visits</th>
                            <th class="d-none d-lg-table-cell">Last visit</th>
                            <th>Follow-up</th>
                            <th class="d-none d-lg-table-cell">Follows up</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="visitorRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
