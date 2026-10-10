<?php
$accButtons = '<button type="button" class="btn btn-primary" id="copyLinkBtn"><i class="ri-links-line me-1"></i>Copy our giving link</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>
<div class="row">
    <div class="col-xl-4">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Our giving link</div><span class="card-subtitle-text">Share it on WhatsApp, the bulletin or a screen in church</span></div></div>
            <div class="card-body" id="gvLink"></div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Gifts made online</div><span class="card-subtitle-text">By M-Pesa through the diocese paybill, or by card on Paystack - each in our books when paid</span></div></div>
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
