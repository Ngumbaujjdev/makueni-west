<?php
$accButtons = '<button type="button" class="btn btn-primary" id="copyLinkBtn"><i class="ri-links-line me-1"></i>Copy our giving link</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="nav section-tabs" id="gvTabs" role="tablist" aria-label="Online giving">
    <button class="nav-link section-tab active" data-tab="gifts" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-success"><i class="ri-hand-heart-line"></i></span>
        <span class="section-tab-text"><strong>Gifts</strong><small id="gvGiftsFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="payouts" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-bank-line"></i></span>
        <span class="section-tab-text"><strong>Payouts</strong><small id="gvPayoutsFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="paid" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-primary"><i class="ri-route-line"></i></span>
        <span class="section-tab-text"><strong>Getting paid</strong><small id="gvPaidFigure">&nbsp;</small></span>
    </button>
</div>

<div id="gvGiftsPane">
    <div class="row">
        <div class="col-xl-4">
            <div class="card custom-card">
                <div class="card-header"><div><div class="card-title">Our giving link</div><span class="card-subtitle-text">Share it on WhatsApp, the bulletin or a screen in church</span></div></div>
                <div class="card-body" id="gvLink"></div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card custom-card">
                <div class="card-header"><div><div class="card-title">Gifts made online</div><span class="card-subtitle-text">By M-Pesa (our own paybill, or the diocese's) or by card on Paystack - each in our books when paid</span></div></div>
                <div class="card-body pb-0 pt-3" id="gvPills"></div>
                <div class="card-body p-0" id="gvTableWrap">
                    <div id="gvFilters" class="list-filterbar-wrap"></div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 pp-table acc-table" id="gvTable">
                            <thead><tr>
                                <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                                <th>Giver</th><th>When</th><th class="d-none d-md-table-cell">For</th><th class="d-none d-lg-table-cell">Where it stands</th><th class="text-end">Amount</th>
                            </tr></thead>
                            <tbody id="gvRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="gvPayoutsPane" hidden>
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
            <div><div class="card-title">Payouts to our bank</div><span class="card-subtitle-text" id="gvPayoutsSub">Card money Paystack paid to our bank - open one to see the gifts it carried</span></div>
            <div class="btn-group" role="group" aria-label="Year" id="gvYears"></div>
        </div>
        <div class="card-body" id="gvPayoutFacts"></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 acc-table" id="gvPayoutTable">
                    <thead><tr><th>Paid out</th><th class="d-none d-md-table-cell">For gifts of</th><th>Where it stands</th><th class="d-none d-lg-table-cell">In our books</th><th class="text-end">Amount</th></tr></thead>
                    <tbody id="gvPayoutRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="gvPaidPane" hidden>
    <div class="row">
        <div class="col-xl-6">
            <div class="card custom-card">
                <div class="card-header"><div><div class="card-title">Card gifts</div><span class="card-subtitle-text">Where Paystack pays our card gifts</span></div></div>
                <div class="card-body" id="gvCard"></div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card custom-card">
                <div class="card-header"><div><div class="card-title">Our own M-Pesa</div><span class="card-subtitle-text">Gifts straight to our own paybill or till</span></div></div>
                <div class="card-body" id="gvMpesa"></div>
            </div>
        </div>
    </div>
</div>
