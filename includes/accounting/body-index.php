<?php
$c = $accCtx['can'];
$accButtons = ($c['journal'] || $c['receipt'] ? '<button type="button" class="btn btn-outline-primary" id="transferBtn" data-own-only><i class="ri-arrow-left-right-line me-1"></i>Move money</button>' : '')
    . ($c['prepare'] ? '<button type="button" class="btn btn-outline-primary" id="voucherBtn" data-own-only><i class="ri-file-list-3-line me-1"></i>Prepare a payment</button>' : '')
    . ($c['receipt'] ? '<button type="button" class="btn btn-primary" id="receiptBtn" data-own-only><i class="ri-bill-line me-1"></i>Write a receipt</button>' : '');
include __DIR__ . '/toolbar.php';
?>

<div class="card custom-card acc-start" id="startCard" hidden></div>

<div class="card custom-card acc-lastsun" id="lastSunday" hidden></div>

<div class="row" id="statCardsRow"></div>

<div class="row">
    <div class="col-xl-5 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">Where the money is</div><span class="card-subtitle-text">Each account's balance today - open one for its money in and out</span></div>
                <a class="btn btn-sm btn-outline-primary" href="<?= $accCtx['baseUrl'] ?>/accounts.php" data-keep-place><i class="ri-bank-line me-1"></i>Cash & bank</a>
            </div>
            <div class="card-body" id="cashList"></div>
        </div>
    </div>
    <div class="col-xl-7 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header justify-content-between flex-wrap gap-2">
                <div><div class="card-title">Money in and out</div><span class="card-subtitle-text">The last 12 months - transfers between our own accounts left out</span></div>
                <span id="chartChips"></span>
            </div>
            <div class="card-body"><div id="inOutChart" class="acc-chart"></div></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-4 col-lg-6 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header"><div><div class="card-title">By fund, this year</div><span class="card-subtitle-text">Restricted money stays for what it was given for</span></div></div>
            <div class="card-body" id="fundList"></div>
        </div>
    </div>
    <div class="col-xl-4 col-lg-6 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header"><div><div class="card-title">Where it came from</div><span class="card-subtitle-text">The biggest income this year</span></div></div>
            <div class="card-body" id="incomeList"></div>
        </div>
    </div>
    <div class="col-xl-4 col-lg-12 d-flex">
        <div class="card custom-card flex-fill">
            <div class="card-header"><div><div class="card-title">Where it went</div><span class="card-subtitle-text">The biggest spending this year</span></div></div>
            <div class="card-body" id="expenseList"></div>
        </div>
    </div>
</div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Latest documents</div><span class="card-subtitle-text">Receipts, payments, transfers and journals - open one to see its lines and papers</span></div>
        <a class="btn btn-sm btn-outline-primary" href="<?= $accCtx['baseUrl'] ?>/documents.php" data-keep-place>All documents<i class="ri-arrow-right-line ms-1"></i></a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 acc-table">
                <thead><tr><th>Document</th><th>Date</th><th class="d-none d-md-table-cell">From / to</th><th class="text-end">Amount</th></tr></thead>
                <tbody id="latestRows"></tbody>
            </table>
        </div>
    </div>
</div>
