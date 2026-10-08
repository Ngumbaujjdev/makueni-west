<?php $noun = $eventsCtx['kind'] === 'initiative' ? ['one' => 'initiative', 'many' => 'initiatives', 'Many' => 'Initiatives'] : ['one' => 'event', 'many' => 'events', 'Many' => 'Events']; ?>
<div class="page-toolbar">
    <div class="page-toolbar-sub" id="placeLine"><?= htmlspecialchars($eventsCtx['place']['name'] ?: $noun['Many']) ?></div>
    <div class="page-toolbar-controls">
        <div id="yearSwitchWrap"></div>
        <button type="button" class="btn btn-outline-primary" id="exportReportBtn" data-module="<?= $eventsCtx['kind'] === 'initiative' ? 'initiatives' : 'events' ?>" data-report-key="<?= $eventsCtx['kind'] === 'initiative' ? 'initiative.year' : 'activity.year' ?>"><i class="ri-download-2-line me-1"></i>Export</button>
        <!-- Shown from the session at once, then kept right by the API's live answer (list.js) -
             so a role that just gained "add" sees it without signing in again. -->
        <a class="btn btn-primary<?= $eventsCtx['can']['manage'] ? '' : ' d-none' ?>" id="newEventBtn" href="<?= $eventsCtx['baseUrl'] ?>/new"><i class="ri-add-line me-1"></i>New <?= $noun['one'] ?></a>
    </div>
</div>

<div class="row" id="statCardsRow"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div>
            <div class="card-title"><?= $eventsCtx['kind'] === 'initiative' ? 'Sessions this year' : 'Events this year' ?></div>
            <span class="card-subtitle-text"><?= $eventsCtx['kind'] === 'initiative' ? 'Sessions held by month' : 'Our events by month' ?></span>
        </div>
        <div class="d-flex flex-wrap gap-1" id="heroChips"></div>
    </div>
    <div class="card-body"><div id="heroChart"></div></div>
</div>

<div class="nav section-tabs" id="eventTabs" role="tablist" aria-label="<?= $noun['Many'] ?>">
    <button class="nav-link section-tab active" data-scope="own" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-home-heart-line"></i></span>
        <span class="section-tab-text"><strong>Ours</strong><small data-tab-figure="own">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-scope="invited" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-mail-open-line"></i></span>
        <span class="section-tab-text"><strong>Invitations</strong><small data-tab-figure="invited">&nbsp;</small></span>
    </button>
    <?php if ($eventsCtx['can']['below']): ?>
    <button class="nav-link section-tab" data-scope="below" type="button" role="tab">
        <span class="section-tab-icon bg-success"><i class="ri-community-line"></i></span>
        <span class="section-tab-text"><strong>Places below</strong><small data-tab-figure="below">&nbsp;</small></span>
    </button>
    <?php endif ?>
</div>

<div class="card custom-card">
    <div class="card-body pb-0"><div id="eventFilters"></div></div>
    <div class="card-body"><div class="row g-3" id="eventsGrid"></div></div>
</div>
