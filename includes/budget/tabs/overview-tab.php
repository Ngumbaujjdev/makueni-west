<!-- Financial Summary Cards (Index-6 Style) -->
<div class="row">
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="flex-fill">
                        <p class="mb-1 fs-12 text-muted fw-semibold">Income Budgeted</p>
                        <h4 class="fw-semibold mb-0" id="overviewIncomeBudgeted">KES 0</h4>
                    </div>
                    <div class="ms-3 min-w-fit-content">
                        <span class="avatar avatar-md bg-primary-transparent text-primary">
                            <i class="ri-arrow-up-circle-line fs-20"></i>
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div>
                        <span class="text-success fw-semibold" id="overviewIncomeVariance">--</span>
                        <span class="text-muted opacity-7 fs-11 ms-1">vs Actual</span>
                    </div>
                    <div class="text-end">
                        <p class="mb-0 text-muted fs-11 fw-semibold">Actual</p>
                        <span class="fs-12 fw-semibold text-primary" id="overviewIncomeActual">KES 0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="flex-fill">
                        <p class="mb-1 fs-12 text-muted fw-semibold">Expense Budgeted</p>
                        <h4 class="fw-semibold mb-0" id="overviewExpenseBudgeted">KES 0</h4>
                    </div>
                    <div class="ms-3 min-w-fit-content">
                        <span class="avatar avatar-md bg-secondary-transparent text-secondary">
                            <i class="ri-arrow-down-circle-line fs-20"></i>
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div>
                        <span class="text-danger fw-semibold" id="overviewExpenseVariance">--</span>
                        <span class="text-muted opacity-7 fs-11 ms-1">vs Actual</span>
                    </div>
                    <div class="text-end">
                        <p class="mb-0 text-muted fs-11 fw-semibold">Actual</p>
                        <span class="fs-12 fw-semibold text-secondary" id="overviewExpenseActual">KES 0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="flex-fill">
                        <p class="mb-1 fs-12 text-muted fw-semibold">Net Position</p>
                        <h4 class="fw-semibold mb-0" id="overviewNetPosition">KES 0</h4>
                    </div>
                    <div class="ms-3 min-w-fit-content">
                        <span class="avatar avatar-md bg-success-transparent text-success">
                            <i class="ri-wallet-3-line fs-20"></i>
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div>
                        <span class="fw-semibold" id="overviewNetStatus">--</span>
                    </div>
                    <div class="text-end">
                        <p class="mb-0 text-muted fs-11 fw-semibold">Performance</p>
                        <span class="fs-12 fw-semibold text-success" id="overviewNetPercentage">0%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="flex-fill">
                        <p class="mb-1 fs-12 text-muted fw-semibold">Budget Utilization</p>
                        <h4 class="fw-semibold mb-0" id="overviewUtilization">0%</h4>
                    </div>
                    <div class="ms-3 min-w-fit-content">
                        <span class="avatar avatar-md bg-warning-transparent text-warning">
                            <i class="ri-pie-chart-2-line fs-20"></i>
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div class="flex-fill">
                        <div class="progress progress-sm" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-warning" id="overviewUtilizationBar" style="width: 0%"></div>
                        </div>
                    </div>
                </div>
                 <div class="mt-1">
                     <small class="text-muted fs-10" id="overviewUtilizationText">Spent vs Budgeted</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Income & Expense Trend Charts (Index-11 Style) -->
<div class="row">
    <div class="col-xl-6">
        <div class="card custom-card">
             <div class="card-header border-bottom-0 pb-0">
                <div class="card-title">
                    Income Trend (Budgeted vs Actual)
                </div>
            </div>
            <div class="card-body pt-0">
                <div id="incomeTrendChart" class="apex-charts text-center"></div>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card custom-card">
             <div class="card-header border-bottom-0 pb-0">
                 <div class="card-title">
                    Expense Trend (Budgeted vs Actual)
                </div>
            </div>
            <div class="card-body pt-0">
                <div id="expenseTrendChart" class="apex-charts text-center"></div>
            </div>
        </div>
    </div>
</div>

<!-- Top Expense Lines -->
<div class="row">
    <div class="col-xl-12">
        <div class="card custom-card">
            <div class="card-header justify-content-between">
                <div class="card-title">
                    <i class="ri-funds-line me-2 text-primary"></i>Top Expense Lines
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table text-nowrap">
                        <thead>
                            <tr>
                                <th scope="col">Budget Line</th>
                                <th scope="col">Budgeted Amount</th>
                                <th scope="col">Actual Amount</th>
                                <th scope="col">Utilization</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody id="topExpenseLinesTable">
                             <tr>
                                <td colspan="5" class="text-center py-4">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted mb-0 fs-12">Loading top expense lines...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
