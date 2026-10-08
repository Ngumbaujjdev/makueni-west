<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($careCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <a class="btn btn-outline-primary" href="<?= $careCtx['baseUrl'] ?>/hospital"><i class="ri-hospital-line me-1"></i>Hospital</a>
        <a class="btn btn-outline-primary" href="<?= $careCtx['baseUrl'] ?>/prayer"><i class="ri-hand-heart-line me-1"></i>Prayer</a>
        <a class="btn btn-outline-primary" href="<?= $careCtx['baseUrl'] ?>/log"><i class="ri-list-check-2 me-1"></i>Care log</a>
        <?php if ($careCtx['can']['manage']): ?>
        <button type="button" class="btn btn-primary" id="recordBtn"><i class="ri-add-line me-1"></i>Record care</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="avatar avatar-sm avatar-rounded bg-danger text-white"><i class="ri-heart-pulse-line"></i></span>
            <div><div class="card-title">Needs care</div><span class="card-subtitle-text" id="needsSub">Urgent cases, next steps this week, and members nobody has visited for a while</span></div>
        </div>
        <div class="d-flex align-items-center gap-2" id="needsHead"></div>
    </div>
    <div class="card-body" id="needsBody"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:60%"></span></div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Care by kind</div><span class="card-subtitle-text">The last twelve months</span></div></div>
            <div class="card-body"><div id="typeChart" class="skel-chart" style="min-height:300px"></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Who gave care</div><span class="card-subtitle-text">This year</span></div></div>
            <div class="card-body" id="leaders"><span class="skel skel-line"></span></div>
        </div>
        <div class="card custom-card">
            <div class="card-header justify-content-between"><div class="card-title">Latest</div><a class="btn btn-sm btn-outline-primary" href="<?= $careCtx['baseUrl'] ?>/log">See all</a></div>
            <div class="card-body" id="latest"><span class="skel skel-line"></span></div>
        </div>
    </div>
</div>
