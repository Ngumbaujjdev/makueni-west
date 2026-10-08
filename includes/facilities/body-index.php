<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($facCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <button type="button" class="btn btn-outline-primary" id="reportBtn"><i class="ri-tools-line me-1"></i>Report a repair</button>
        <?php if ($facCtx['can']['book']): ?>
        <button type="button" class="btn btn-primary" id="bookBtn"><i class="ri-calendar-check-line me-1"></i>Book a room</button>
        <?php endif ?>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="row mn-fill-row">
    <div class="col-xl-8 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">Today and this week</div><span class="card-subtitle-text">Who has which room, and when</span></div>
                <a class="btn btn-sm btn-outline-primary" href="<?= $facCtx['baseUrl'] ?>/bookings"><i class="ri-calendar-line me-1"></i>The calendar</a>
            </div>
            <div class="card-body mn-fill-body" id="agenda"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:60%"></span></div>
        </div>
    </div>
    <div class="col-xl-4 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">On duty</div><span class="card-subtitle-text" id="dutySub">The next service</span></div>
                <a class="btn btn-sm btn-outline-primary" href="<?= $facCtx['baseUrl'] ?>/rota">Rota</a>
            </div>
            <div class="card-body mn-fill-body" id="duty"><span class="skel skel-line"></span></div>
        </div>
    </div>
</div>

<div class="row mn-fill-row">
    <div class="col-xl-7 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header"><div><div class="card-title">Needs attention</div><span class="card-subtitle-text">Urgent repairs, broken things and loans not back</span></div></div>
            <div class="card-body" id="attention"><span class="skel skel-line"></span></div>
        </div>
    </div>
    <div class="col-xl-5 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">Our rooms</div><span class="card-subtitle-text">Bookings in each today</span></div>
                <?php if ($facCtx['can']['manage']): ?><button type="button" class="btn btn-sm btn-outline-primary" id="roomsBtn"><i class="ri-door-open-line me-1"></i>Rooms</button><?php endif ?>
            </div>
            <div class="card-body" id="rooms"><span class="skel skel-line"></span></div>
        </div>
    </div>
</div>
