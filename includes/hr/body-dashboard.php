<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold"><?= htmlspecialchars($hrCtx['place']['name'] ?: 'Our place') ?></span>
        <span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Pay is private</span>
    </div>
    <div class="page-toolbar-controls">
        <div class="seg-control" id="dashYears" role="group" aria-label="Year"></div>
        <?php if ($hrCtx['can']['below']): ?>
        <div style="min-width: 13rem"><select class="form-select" id="dashPlace" aria-label="Place"><option value="">Every place</option></select></div>
        <?php endif ?>
    </div>
</div>

<div class="card custom-card">
    <div class="card-body d-flex flex-wrap align-items-center gap-2 py-3">
        <span class="fw-semibold me-1">Quick actions</span>
        <?php if ($hrCtx['can']['manage']): ?>
        <button type="button" class="btn btn-sm btn-primary" id="addBtn"><i class="ri-user-add-line me-1"></i>Add a person</button>
        <?php endif ?>
        <a class="btn btn-sm btn-success" href="<?= $hrCtx['baseUrl'] ?>/"><i class="ri-team-line me-1"></i>All staff</a>
        <a class="btn btn-sm btn-purple" href="<?= $hrCtx['baseUrl'] ?>/positions.php"><i class="ri-briefcase-4-line me-1"></i>Positions &amp; pay</a>
        <a class="btn btn-sm btn-warning text-dark" href="<?= $hrCtx['payrollUrl'] ?>" id="payrollBtn" hidden><i class="ri-money-dollar-box-line me-1"></i>Run payroll</a>
    </div>
</div>

<div class="row" id="dashKpis"></div>

<div class="row">
    <div class="col-xxl-8 col-xl-7">
        <div class="card custom-card">
            <div class="card-header justify-content-between align-items-center d-sm-flex d-block">
                <div class="card-title mb-sm-0 mb-2">Pay by month</div>
                <div class="btn-group" role="group" aria-label="Range" id="payRange">
                    <button type="button" class="btn btn-sm btn-primary-light btn-wave" data-range="6">6M</button>
                    <button type="button" class="btn btn-sm btn-primary btn-wave" data-range="12">1Y</button>
                </div>
            </div>
            <div class="card-body pb-0"><div id="payChart" style="min-height: 310px"></div></div>
            <div class="card-footer">
                <div class="row gy-md-0 gy-3" id="paySummary"></div>
            </div>
        </div>
    </div>
    <div class="col-xxl-4 col-xl-5">
        <div class="card custom-card">
            <div class="card-header"><div class="card-title">Staff by type</div></div>
            <div class="card-body py-4 px-0"><div id="typeDonut" style="min-height: 260px"></div></div>
            <div class="card-footer p-4 my-2"><div class="row row-cols-12" id="typeLegend"></div></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xxl-4 col-xl-6">
        <div class="card custom-card">
            <div class="card-header justify-content-between"><div class="card-title">Coming up</div><span class="soft-chip soft-primary">Next 60 days</span></div>
            <div class="card-body" id="comingUp"></div>
        </div>
    </div>
    <div class="col-xxl-4 col-xl-6">
        <div class="card custom-card">
            <div class="card-header justify-content-between"><div class="card-title">Pay by position</div><a class="soft-chip soft-purple" href="<?= $hrCtx['baseUrl'] ?>/positions.php">Positions<i class="ri-arrow-right-line"></i></a></div>
            <div class="card-body" id="byPosition"></div>
        </div>
    </div>
    <div class="col-xxl-4 col-xl-12">
        <div class="card custom-card">
            <div class="card-header justify-content-between"><div class="card-title">Recent payroll</div><a class="soft-chip soft-warning" href="<?= $hrCtx['payrollUrl'] ?>" id="runsLink" hidden>Payroll<i class="ri-arrow-right-line"></i></a></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Month</th><th class="text-end">Paid out</th></tr></thead>
                        <tbody id="runsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card custom-card" id="placesCard" hidden>
    <div class="card-header justify-content-between"><div class="card-title">By place</div><span class="soft-chip soft-primary" id="placesCount"></span></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover text-nowrap mb-0" id="placesTable">
                <thead><tr><th>Place</th><th class="text-end">Staff</th><th class="text-end">Pay a month</th><th>Paid this year</th><th class="text-end"></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
