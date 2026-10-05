<?php
// One event: header with its actions, then tabs - filled in by assets/js/pages/events/event.js.
// What the viewer may do (edit, register, record money...) comes from the API's `can`.
?>
<div id="eventPage">
    <div class="card custom-card ev-hero" id="evHero">
        <div class="card-body">
            <div class="d-flex gap-3" aria-hidden="true">
                <span class="skel skel-tile" style="width:64px;height:68px"></span>
                <div class="flex-fill"><span class="skel skel-title" style="width:50%"></span><span class="skel skel-line mt-2" style="width:35%"></span><span class="skel skel-line skel-line-sm mt-2" style="width:60%"></span></div>
            </div>
        </div>
    </div>

    <div class="row" id="statCardsRow"></div>

    <div class="nav section-tabs" id="evTabs" role="tablist" aria-label="Event" hidden></div>

    <div class="row g-4">
        <div class="col-xl-8" id="evMain">
            <div class="section-tab-content" id="evPanes"></div>
        </div>
        <div class="col-xl-4" id="evSide"></div>
    </div>
</div>
