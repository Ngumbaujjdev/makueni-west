<!-- Teams - who serves on each duty (docs/specs/people-and-care-spec.md, P5 round 4) - filled in by assets/js/pages/facilities/teams.js. -->
<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($facCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-primary"><i class="ri-loop-right-line"></i>The rota takes turns down each list</span></div>
    <div class="page-toolbar-controls">
        <a class="btn btn-outline-primary" href="<?= $facCtx['baseUrl'] ?>/rota"><i class="ri-calendar-schedule-line me-1"></i>Duty rota</a>
        <?php if ($facCtx['can']['manage']): ?>
        <a class="btn btn-outline-primary" href="<?= $facCtx['settingsUrl'] ?>#card-duties"><i class="ri-settings-3-line me-1"></i>Set up duties</a>
        <a class="btn btn-primary" href="<?= $facCtx['baseUrl'] ?>/rota?fill=1"><i class="ri-magic-line me-1"></i>Fill the rota</a>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow">
    <?php for ($i = 0; $i < 4; $i++): ?><div class="col-xl-3 col-sm-6 d-flex"><div class="card custom-card flex-fill"><div class="card-body"><span class="skel skel-line"></span><span class="skel skel-line w-50 mt-3"></span></div></div></div><?php endfor ?>
</div>

<div class="row" id="tmTeams">
    <?php for ($i = 0; $i < 3; $i++): ?><div class="col-xxl-4 col-lg-6 d-flex"><div class="card custom-card flex-fill"><div class="card-body"><div class="skel" style="height:220px"></div></div></div></div><?php endfor ?>
</div>
