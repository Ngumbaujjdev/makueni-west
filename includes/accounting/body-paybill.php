<?php
$isDiocese = $accCtx['level'] === 'diocese';
$accButtons = ($accCtx['can']['receipt'] || $accCtx['can']['paybill'] ? '<button type="button" class="btn btn-outline-primary" id="askBtn" data-own-only><i class="ri-smartphone-line me-1"></i>Ask to pay</button>' : '')
    . ($accCtx['can']['paybill'] ? '<button type="button" class="btn btn-primary" id="settleBtn" data-own-only><i class="ri-hand-coin-line me-1"></i>Settle a month</button>' : '');
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="nav section-tabs" id="pbTabs" role="tablist" aria-label="Paybill">
    <button class="nav-link section-tab active" data-tab="payments" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-success"><i class="ri-smartphone-line"></i></span>
        <span class="section-tab-text"><strong>Payments</strong><small id="pbPaymentsFigure">&nbsp;</small></span>
    </button>
    <?php if ($isDiocese): ?>
    <button class="nav-link section-tab" data-tab="sort" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-warning"><i class="ri-question-answer-line"></i></span>
        <span class="section-tab-text"><strong>To sort</strong><small id="pbSortFigure">&nbsp;</small></span>
    </button>
    <?php else: ?>
    <button class="nav-link section-tab" data-tab="numbers" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-primary"><i class="ri-hashtag"></i></span>
        <span class="section-tab-text"><strong>How to pay</strong><small id="pbNumbersFigure">&nbsp;</small></span>
    </button>
    <?php endif; ?>
    <button class="nav-link section-tab" data-tab="settlements" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-exchange-funds-line"></i></span>
        <span class="section-tab-text"><strong>Settlements</strong><small id="pbSettleFigure">&nbsp;</small></span>
    </button>
    <?php if ($isDiocese && $accCtx['can']['paybill']): ?>
    <button class="nav-link section-tab" data-tab="setup" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-secondary"><i class="ri-settings-3-line"></i></span>
        <span class="section-tab-text"><strong>Setup</strong><small id="pbSetupFigure">&nbsp;</small></span>
    </button>
    <?php endif; ?>
</div>

<div id="pbPaymentsPane">
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title"><?= $isDiocese ? 'Paybill payments' : 'Our paybill giving' ?></div><span class="card-subtitle-text"><?= $isDiocese ? 'Everything paid into the diocese paybill, with the place and purpose its account number named' : 'What members paid by M-Pesa - to our own paybill or with our code to the diocese paybill - in our books the same day' ?></span></div></div>
        <div class="card-body pb-0 pt-3" id="pbPills"></div>
        <div class="card-body p-0" id="pbTableWrap">
            <div id="pbFilters" class="list-filterbar-wrap"></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 pp-table acc-table" id="pbTable">
                    <thead><tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                        <th>Paid by</th><th>When</th><th class="d-none d-md-table-cell">Account number</th><th class="d-none d-lg-table-cell">For</th><th class="text-end">Amount</th>
                    </tr></thead>
                    <tbody id="pbRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div id="pbSortPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">To sort</div><span class="card-subtitle-text">Paid with an account number that names no place - give each to its church, to the diocese, or return it</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 acc-table"><thead><tr><th>Paid by</th><th class="d-none d-md-table-cell">When</th><th>Typed</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead><tbody id="pbSortRows"></tbody></table></div></div>
    </div>
</div>
<div id="pbNumbersPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">How members pay</div><span class="card-subtitle-text">M-Pesa, Lipa na M-Pesa, Pay Bill - the diocese paybill and our account number</span></div></div>
        <div class="card-body" id="pbNumbers"></div>
    </div>
</div>
<div id="pbSettlementsPane" hidden>
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2"><div><div class="card-title">Settlements</div><span class="card-subtitle-text" id="pbSettleSub"></span></div><div id="pbMonthPick"></div></div>
        <div class="card-body p-0" id="pbSettleBody"></div>
    </div>
</div>
<div id="pbSetupPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Setup</div><span class="card-subtitle-text">The Daraja app, where Safaricom reaches us, and a test payment in the sandbox</span></div></div>
        <div class="card-body" id="pbSetup"></div>
    </div>
</div>
