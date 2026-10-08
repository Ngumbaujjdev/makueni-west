<!-- What we own - the asset register (docs/specs/people-and-care-spec.md, P5 round 2) - filled in by assets/js/pages/facilities/assets.js. -->
<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($facCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span><span class="soft-chip soft-primary"><i class="ri-information-line"></i>Worth is what we paid</span></div>
    <div class="page-toolbar-controls">
        <?php if ($facCtx['can']['export']): ?>
        <a class="btn btn-outline-primary" href="<?= $facCtx['baseUrl'] ?>/reports"><i class="ri-file-list-3-line me-1"></i>All asset reports</a>
        <button type="button" class="btn btn-primary" data-report-key="facilities.assets" data-module="facilities"><i class="ri-download-2-line me-1"></i>Export the register</button>
        <?php endif ?>
        <?php if ($facCtx['can']['manage']): ?>
        <button type="button" class="btn btn-outline-primary" id="addBtn"><i class="ri-add-line me-1"></i>Add equipment</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="row">
    <div class="col-xl-5">
        <div class="card custom-card fx-fill">
            <div class="card-header"><div><div class="card-title">What it cost, by kind</div><span class="card-subtitle-text">Where the money for our things went</span></div></div>
            <div class="card-body" id="byKind"><div class="skel" style="height:260px"></div></div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card custom-card fx-fill">
            <div class="card-header"><div><div class="card-title">Bought each year</div><span class="card-subtitle-text">What was spent on things, by the year each was bought</span></div></div>
            <div class="card-body" id="byYear"><div class="skel" style="height:260px"></div></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-5">
        <div class="card custom-card fx-fill">
            <div class="card-header"><div><div class="card-title">By room</div><span class="card-subtitle-text">What is kept where, and what it cost</span></div></div>
            <div class="card-body" id="byRoom"></div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card custom-card fx-fill">
            <div class="card-header justify-content-between"><div><div class="card-title">Cost the most</div><span class="card-subtitle-text">Our most valuable things - look after these</span></div><a class="btn btn-sm btn-outline-primary" href="<?= $facCtx['baseUrl'] ?>/equipment">All equipment<i class="ri-arrow-right-line ms-1"></i></a></div>
            <div class="card-body" id="top"></div>
        </div>
    </div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">Needs details</div><span class="card-subtitle-text">Things still missing a price, a receipt or a photo - open one to add it</span></div><span id="needsHead"></span></div>
    <div class="card-body" id="needs"></div>
</div>
