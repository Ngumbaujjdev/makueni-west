<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($minCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <a class="btn btn-outline-primary" href="<?= $minCtx['baseUrl'] ?>/"><i class="ri-team-line me-1"></i>Ministries</a>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<!-- The chart and the list side by side, one height: the list scrolls inside its card. -->
<div class="row mn-fill-row">
    <div class="col-xl-8 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">Who serves where</div><span class="card-subtitle-text">Members in each ministry - women and men</span></div>
                <span id="chartHead"></span>
            </div>
            <div class="card-body"><div id="perMinistry" class="skel-chart" style="min-height:320px"></div></div>
        </div>
    </div>
    <div class="col-xl-4 d-flex">
        <div class="card custom-card flex-fill mn-fill-card">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">Not in a ministry yet</div><span class="card-subtitle-text">Members to invite in</span></div>
                <span id="noneHead"></span>
            </div>
            <div class="mn-ring" id="noneRing"></div>
            <div class="card-body mn-fill-body" id="noneBody"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:60%"></span></div>
        </div>
    </div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Each ministry</div><span class="card-subtitle-text">Its people, and its gatherings over the last six months</span></div>
        <span class="soft-chip soft-primary" id="tableHead"><i class="ri-calendar-line"></i>Last six months</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover text-nowrap mb-0 mn-table">
                <thead><tr><th>Ministry</th><th>Members</th><th>Women · men</th><th class="d-none d-lg-table-cell">Sunday school</th><th>Attendance</th><th>Average</th><th class="text-end">Action</th></tr></thead>
                <tbody id="mnRows"><tr><td colspan="7"><span class="skel skel-line"></span></td></tr></tbody>
            </table>
        </div>
    </div>
</div>
