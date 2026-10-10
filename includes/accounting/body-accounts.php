<?php
$c = $accCtx['can'];
$accButtons = ($c['journal'] ? '<button type="button" class="btn btn-outline-primary" id="openingBtn" data-own-only><i class="ri-scales-3-line me-1"></i>Opening balances</button>' : '')
    . ($c['receipt'] || $c['journal'] ? '<button type="button" class="btn btn-outline-primary" id="transferBtn" data-own-only><i class="ri-arrow-left-right-line me-1"></i>Move money</button>' : '')
    . ($c['accounts'] ? '<button type="button" class="btn btn-outline-primary" id="lineBtn" data-own-only><i class="ri-git-branch-line me-1"></i>Add our own line</button>' : '')
    . ($c['accounts'] ? '<button type="button" class="btn btn-primary" id="addBtn" data-own-only><i class="ri-add-line me-1"></i>Add an account</button>' : '');
include __DIR__ . '/toolbar.php';
?>

<div class="row" id="cashCards"></div>

<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Chart of accounts</div><span class="card-subtitle-text">The diocese's standard accounts every church, region and diocese uses, with our own under them - and what each holds in our books</span></div>
        <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="usedOnly" checked><label class="form-check-label" for="usedOnly">Only accounts with money</label></div>
    </div>
    <div class="card-body pb-0 pt-3" id="typePills"></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 acc-table" id="chartTable">
                <thead><tr><th style="width:110px">Code</th><th>Account</th><th class="d-none d-md-table-cell">Kind</th><th class="text-end">Balance</th></tr></thead>
                <tbody id="chartRows"></tbody>
            </table>
        </div>
    </div>
</div>
