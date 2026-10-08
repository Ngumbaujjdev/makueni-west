<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($facCtx['place']['name'] ?: 'Our church') ?></span><span id="reminderChip"></span></div>
    <div class="page-toolbar-controls">
        <?php if ($facCtx['can']['manage']): ?>
        <a class="btn btn-outline-primary" href="<?= $facCtx['settingsUrl'] ?>"><i class="ri-team-line me-1"></i>Teams</a>
        <button type="button" class="btn btn-outline-primary" id="copyBtn"><i class="ri-file-copy-line me-1"></i>Copy a week</button>
        <button type="button" class="btn btn-primary" id="fillBtn"><i class="ri-magic-line me-1"></i>Fill from the teams</button>
        <?php endif ?>
    </div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title" id="rtTitle">Duty rota</div><span class="card-subtitle-text"><?= $facCtx['can']['manage'] ? 'Tap a box to put people on that duty' : 'Who is on duty at each service' ?></span></div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-icon btn-sm btn-light border" id="rtPrev" aria-label="Earlier"><i class="ri-arrow-left-s-line"></i></button>
            <button type="button" class="btn btn-sm btn-light border" id="rtToday">This week</button>
            <button type="button" class="btn btn-icon btn-sm btn-light border" id="rtNext" aria-label="Later"><i class="ri-arrow-right-s-line"></i></button>
        </div>
    </div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fx-rota" id="rtTable"><tbody><tr><td><span class="skel skel-line"></span></td></tr></tbody></table></div></div>
</div>
