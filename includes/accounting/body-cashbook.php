<?php
$c = $accCtx['can'];
$accButtons = '<button type="button" class="btn btn-outline-primary" id="printBtn"><i class="ri-file-pdf-2-line me-1"></i>Cashbook (PDF)</button><button type="button" class="btn btn-outline-primary" id="csvBtn"><i class="ri-file-excel-2-line me-1"></i>Excel (CSV)</button>'
    . ($c['receipt'] ? '<button type="button" class="btn btn-primary" id="receiptBtn" data-own-only><i class="ri-bill-line me-1"></i>Write a receipt</button>' : '');
include __DIR__ . '/toolbar.php';
?>

<div class="card custom-card">
    <div class="card-body acc-cb-pick">
        <div id="accountTiles" class="acc-tiles acc-tiles-pick" role="radiogroup" aria-label="Account"></div>
        <div class="acc-cb-range">
            <div class="btn-group" role="group" aria-label="Period" id="rangeBtns">
                <button type="button" class="btn btn-outline-primary" data-range="month">This month</button>
                <button type="button" class="btn btn-outline-primary" data-range="last">Last month</button>
                <button type="button" class="btn btn-outline-primary" data-range="year">This year</button>
            </div>
            <input type="date" class="form-control" id="fromDate" aria-label="From">
            <span class="acc-cb-to">to</span>
            <input type="date" class="form-control" id="toDate" aria-label="To">
        </div>
    </div>
</div>

<div class="row" id="cbStats"></div>

<div class="card custom-card" id="cbCard">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title" id="cbTitle">Cashbook</div><span class="card-subtitle-text" id="cbSub">Every movement, with the balance after it</span></div>
        <div class="list-search acc-cb-search"><i class="ri-search-line"></i><input type="search" class="form-control" id="cbSearch" placeholder="Search name, number, reference..." autocomplete="off"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 acc-table acc-cashbook">
                <thead><tr><th>Date</th><th>Document</th><th>Details</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Balance</th></tr></thead>
                <tbody id="cbRows"></tbody>
            </table>
        </div>
    </div>
</div>
