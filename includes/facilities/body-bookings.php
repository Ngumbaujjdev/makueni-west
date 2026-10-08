<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span><?= htmlspecialchars($facCtx['place']['name'] ?: 'Our church') ?></span><span class="soft-chip soft-primary" id="hoursChip"><i class="ri-time-line"></i>Opening hours</span></div>
    <div class="page-toolbar-controls">
        <?php if ($facCtx['can']['manage']): ?>
        <button type="button" class="btn btn-outline-primary" id="roomsBtn"><i class="ri-door-open-line me-1"></i>Rooms</button>
        <?php endif ?>
        <?php if ($facCtx['can']['book']): ?>
        <button type="button" class="btn btn-primary" id="bookBtn"><i class="ri-calendar-check-line me-1"></i>Book a room</button>
        <?php endif ?>
    </div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Who has which room</div><span class="card-subtitle-text"><?= $facCtx['can']['book'] ? 'Drag across a free time to book it - tap a booking to see or change it' : 'Tap a booking to see it' ?></span></div>
        <div id="viewSwitchWrap"></div>
    </div>
    <div class="card-body pb-0 pt-3" id="roomPills"></div>
    <div class="card-body">
        <div id="fcCal" class="fx-book cal-clean"></div>
    </div>
</div>
