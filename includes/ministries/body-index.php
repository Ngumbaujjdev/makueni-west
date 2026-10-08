<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($minCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <?php if ($minCtx['can']['insights']): ?>
        <a class="btn btn-outline-primary" href="<?= $minCtx['baseUrl'] ?>/insights"><i class="ri-pie-chart-2-line me-1"></i>Insights</a>
        <?php endif ?>
        <?php if ($minCtx['can']['manage']): ?>
        <button type="button" class="btn btn-primary" id="addBtn"><i class="ri-add-line me-1"></i>Add ministry</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Our ministries</div><span class="card-subtitle-text" id="gridSub">Who leads each one, who serves, and how its gatherings are going</span></div>
        <?php if ($minCtx['can']['manage']): ?>
        <div class="btn-group" role="group" aria-label="Which ministries" id="gridSwitch">
            <button type="button" class="btn btn-sm btn-primary" data-show="active">Running</button>
            <button type="button" class="btn btn-sm btn-outline-primary" data-show="all">All, with switched off</button>
        </div>
        <?php endif ?>
    </div>
    <div class="card-body">
        <div class="row g-3" id="minGrid">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="col-xxl-4 col-lg-6"><div class="mn-card-skel"><span class="skel skel-title"></span><span class="skel skel-line mt-3"></span><span class="skel skel-line mt-2" style="width:60%"></span></div></div>
            <?php endfor ?>
        </div>
    </div>
</div>
