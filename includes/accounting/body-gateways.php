<?php
$accButtons = '<a class="btn btn-outline-primary" href="' . SITE_URL . '/diocese/settings/?section=giving"><i class="ri-settings-3-line me-1"></i>Paystack keys</a>';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>
<div id="gwReady"></div>
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
<div class="card custom-card">
    <div class="card-header"><div><div class="card-title">Latest payouts</div><span class="card-subtitle-text">Paystack's payouts into each place's bank - recorded once, each morning</span></div></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 acc-table"><tbody id="gwPayouts"></tbody></table></div></div>
</div>
