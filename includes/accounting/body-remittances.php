<?php
$y = (int) date('Y');
$accButtons = '<div class="btn-group" role="group" aria-label="Year" id="rmYear">'
    . implode('', array_map(fn ($yr) => '<button type="button" class="btn btn-outline-primary' . ($yr === $y ? ' active' : '') . '" data-year="' . $yr . '">' . $yr . '</button>', [$y - 2, $y - 1, $y]))
    . '</div>'
    . ($accCtx['can']['prepare'] ? '<button type="button" class="btn btn-primary" id="shareBtn" data-own-only hidden><i class="ri-send-plane-line me-1"></i>Send the share</button>' : '')
    . ($accCtx['can']['prepare'] && $accCtx['level'] !== 'church' ? '<button type="button" class="btn btn-outline-primary" id="supportBtn" data-own-only><i class="ri-hand-heart-line me-1"></i>Send support</button>' : '');
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="nav section-tabs" id="rmTabs" role="tablist" aria-label="Remittances">
    <button class="nav-link section-tab active" data-tab="owe" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-upload-2-line"></i></span>
        <span class="section-tab-text"><strong>What we send</strong><small id="rmOweFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="in" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-success"><i class="ri-download-2-line"></i></span>
        <span class="section-tab-text"><strong>Coming in</strong><small id="rmInFigure">&nbsp;</small></span>
    </button>
    <?php if ($accCtx['level'] !== 'church' && $accCtx['can']['below']): ?>
    <button class="nav-link section-tab" data-tab="below" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-community-line"></i></span>
        <span class="section-tab-text"><strong>Places below</strong><small id="rmBelowFigure">&nbsp;</small></span>
    </button>
    <?php endif; ?>
</div>

<div id="rmOwePane">
    <div id="rmRules"></div>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Our remittances</div><span class="card-subtitle-text">Shares sent up and support sent down - each paid by a voucher, then confirmed by the place receiving it</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>Remittance</th><th class="d-none d-md-table-cell">To</th><th class="d-none d-lg-table-cell">Sent</th><th>Where it stands</th><th class="text-end">Amount</th></tr></thead><tbody id="rmSentRows"></tbody></table></div></div>
    </div>
</div>
<div id="rmInPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Coming in</div><span class="card-subtitle-text">Money other places sent us - confirm it when it reaches the account, or query it</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>From</th><th class="d-none d-md-table-cell">Sent</th><th>Where it stands</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead><tbody id="rmInRows"></tbody></table></div></div>
    </div>
</div>
<div id="rmBelowPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Places below</div><span class="card-subtitle-text">What each owes us for the year from its own books, what it sent and what we confirmed - open one for its statement</span></div></div>
        <div class="card-body pb-0 pt-3" id="rmBoardPills"></div>
        <div class="card-body p-0" id="rmBoardWrap"><div id="rmBoardFilters" class="list-filterbar-wrap"></div><div class="table-responsive"><table class="table table-hover mb-0 pp-table acc-table" id="rmBoardTable"><thead><tr><th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th><th>Place</th><th class="text-end">Due</th><th class="text-end d-none d-md-table-cell">Sent</th><th class="text-end d-none d-lg-table-cell">Confirmed</th><th class="text-end d-none d-lg-table-cell">In transit</th><th class="text-end">Owed</th></tr></thead><tbody id="rmBoardRows"></tbody></table></div></div>
    </div>
</div>
