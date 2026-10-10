<?php
$accButtons = '<a class="btn btn-outline-primary" href="' . SITE_URL . '/diocese/settings/?section=giving"><i class="ri-settings-3-line me-1"></i>Paystack keys</a>';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>
<div id="gwReady"></div>

<div class="nav section-tabs" id="gwTabs" role="tablist" aria-label="Gateways">
    <button class="nav-link section-tab active" data-tab="places" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-community-line"></i></span>
        <span class="section-tab-text"><strong>Churches</strong><small id="gwPlacesFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="requests" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-warning"><i class="ri-inbox-archive-line"></i></span>
        <span class="section-tab-text"><strong>Requests</strong><small id="gwRequestsFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="payouts" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-bank-line"></i></span>
        <span class="section-tab-text"><strong>Payouts</strong><small id="gwPayoutsFigure">&nbsp;</small></span>
    </button>
</div>

<div id="gwPlacesPane">
<div class="card custom-card">
    <div class="card-header"><div><div class="card-title">Each church's gateways</div><span class="card-subtitle-text">Paystack: card gifts settle to the church's bank with the diocese share split off at source. Own M-Pesa: gifts go straight to its own paybill or till, through PayHero or its own Daraja app. Without them, the diocese holds the money and settles monthly.</span></div></div>
    <div class="card-body pb-0 pt-3" id="gwPills"></div>
    <div class="card-body p-0">
        <div id="gwFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table acc-table" id="gwTable">
                <thead><tr>
                    <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                    <th>Place</th><th class="d-none d-md-table-cell">Settles to</th><th>Paystack</th><th>M-Pesa</th><th class="text-end">Paystack</th>
                </tr></thead>
                <tbody id="gwRows"></tbody>
            </table>
        </div>
    </div>
</div>
</div>

<div id="gwRequestsPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Asked by the churches</div><span class="card-subtitle-text">Bank details for card payouts - check them against the church's bank letter, then approve (Paystack makes or updates the subaccount) or send back with a note</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 acc-table"><thead><tr><th>Church</th><th>Bank details</th><th class="d-none d-md-table-cell">Asked</th><th class="text-end">Check</th></tr></thead><tbody id="gwRequestRows"></tbody></table></div></div>
    </div>
</div>

<div id="gwPayoutsPane" hidden>
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
            <div><div class="card-title">Payouts per place</div><span class="card-subtitle-text">What Paystack paid to each place's bank - open a place to see its payouts and the gifts in each</span></div>
            <div class="btn-group" role="group" aria-label="Period" id="gwPeriods"></div>
        </div>
        <div class="card-body pb-0 pt-3" id="gwPoPills"></div>
        <div class="card-body p-0">
            <div id="gwPoFilters" class="list-filterbar-wrap"></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 pp-table acc-table" id="gwPoTable">
                    <thead><tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                        <th>Place</th><th>How it's paid</th><th class="d-none d-md-table-cell">Payouts</th><th class="d-none d-lg-table-cell">Diocese share</th><th class="d-none d-lg-table-cell">Last payout</th><th class="d-none d-md-table-cell">On the way</th><th class="text-end">Paid to its bank</th>
                    </tr></thead>
                    <tbody id="gwPoRows"></tbody>
                </table>
            </div>
        </div>
    </div>
<div class="card custom-card">
    <div class="card-header"><div><div class="card-title">Latest payouts</div><span class="card-subtitle-text">Paystack's payouts into each place's bank - recorded once, each morning</span></div></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 acc-table"><tbody id="gwPayouts"></tbody></table></div></div>
</div>
</div>
