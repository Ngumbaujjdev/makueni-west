<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2"><span>Prayer requests, open and answered</span><span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private to our church</span></div>
    <div class="page-toolbar-controls">
        <?php if ($careCtx['can']['manage']): ?>
        <button type="button" class="btn btn-primary" id="recordBtn"><i class="ri-hand-heart-line me-1"></i>A prayer request</button>
        <?php endif ?>
    </div>
</div>
<div id="prayerPills" class="mb-3"></div>
<div id="prayerBody"><div class="row g-3"><div class="col-xl-4 col-md-6"><span class="skel" style="display:block;height:160px;border-radius:1rem"></span></div></div></div>
