<?php
$accButtons = '<button type="button" class="btn btn-primary" id="addBtn"><i class="ri-add-line me-1"></i>Add an account</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="nav section-tabs" id="typeTabs" role="tablist" aria-label="Kinds of account"></div>

<div class="row">
    <div class="col-xl-8">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title" id="typeTitle">Money in</div><span class="card-subtitle-text">One chart for every place - click an account to rename it or switch it off</span></div></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 acc-table" id="stdChartTable">
                        <thead><tr><th>Account</th><th class="d-none d-md-table-cell">What it's for</th><th>In use</th><th class="text-end"></th></tr></thead>
                        <tbody id="chartRows"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Funds</div><span class="card-subtitle-text">Set up in Settings › Giving options &amp; funds</span></div></div>
            <div class="card-body" id="fundRows"></div>
        </div>
    </div>
</div>
