<?php
$accButtons = '<button type="button" class="btn btn-primary" id="addBtn"><i class="ri-add-line me-1"></i>Add an account</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="alert alert-info d-flex gap-2 align-items-start"><i class="ri-information-line mt-1"></i><div>One chart for the whole diocese: every church, region and the diocese post to these accounts, so their books can be added up and compared. Places add only their own bank and M-Pesa accounts under it. Accounts the books rely on can be renamed but not switched off.</div></div>
<div class="row">
    <div class="col-xl-8">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Standard accounts</div><span class="card-subtitle-text">By type - change a name, or switch an account off</span></div></div>
            <div class="card-body pb-0 pt-3" id="typePills"></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 acc-table" id="stdChartTable">
                        <thead><tr><th style="width:110px">Code</th><th>Account</th><th class="d-none d-md-table-cell">Used for</th><th class="text-end">Action</th></tr></thead>
                        <tbody id="chartRows"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card custom-card">
            <div class="card-header"><div><div class="card-title">Funds</div><span class="card-subtitle-text">Every amount belongs to a fund; restricted ones stay for their purpose</span></div></div>
            <div class="card-body" id="fundRows"></div>
        </div>
    </div>
</div>
