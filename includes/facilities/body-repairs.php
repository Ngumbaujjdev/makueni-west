<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($facCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <button type="button" class="btn btn-primary" id="reportBtn"><i class="ri-add-line me-1"></i>Report a repair</button>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">The repairs board</div><span class="card-subtitle-text"><?= $facCtx['can']['manage'] ? 'Drag a card to move it along - tap it for who is on it and the cost' : 'What needs fixing, and how far along it is' ?></span></div>
        <span class="soft-chip soft-primary"><i class="ri-calendar-line"></i>Done in the last 3 months</span>
    </div>
    <div class="card-body"><div class="vs-board fx-board" id="rpBoard"><div class="vs-board-loading"><span class="skel skel-line"></span></div></div></div>
</div>
