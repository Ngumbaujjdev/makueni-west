<!-- Filters Section -->
<div class="row mb-3">
    <div class="col-xl-12">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-2">
                <!-- Category Filter -->
                <select class="form-select" id="linesCategoryFilter" style="width: auto;">
                    <option value="">All Categories</option>
                    <option value="income">Income</option>
                    <option value="expense">Expense</option>
                </select>

                <!-- Search -->
                <div class="input-group" style="width: 300px;">
                    <input type="text" class="form-control" id="linesSearchInput" placeholder="Search budget line...">
                    <span class="input-group-text">
                        <i class="ri-search-line"></i>
                    </span>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-success-light btn-wave" id="exportLinesBtn">
                    <i class="ri-file-excel-line me-1"></i>Export Lines
                </button>
                <button class="btn btn-primary-light btn-wave no-print" id="addLineBtn" style="display:none;">
                    <i class="ri-add-line me-1"></i>Add Line
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-3">
    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card border-start border-primary border-3">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-primary-transparent">
                            <i class="ri-list-check fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Total Lines</p>
                        <h5 class="fw-semibold mb-0" id="linesTotalCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card border-start border-success border-3">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-success-transparent">
                            <i class="ri-arrow-up-line fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Income Lines</p>
                        <h5 class="fw-semibold mb-0 text-success" id="linesIncomeCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card border-start border-danger border-3">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-danger-transparent">
                            <i class="ri-arrow-down-line fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Expense Lines</p>
                        <h5 class="fw-semibold mb-0 text-danger" id="linesExpenseCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card border-start border-warning border-3">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-warning-transparent">
                            <i class="ri-alert-line fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Over Budget</p>
                        <h5 class="fw-semibold mb-0 text-warning" id="linesOverBudgetCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Budget Lines Table -->
<div class="row">
    <div class="col-xl-12">
        <div class="card custom-card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover">
                        <thead class="bg-light sticky-top">
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Budget Line</th>
                                <th scope="col">Category</th>
                                <th scope="col" class="text-end">Budgeted</th>
                                <th scope="col" class="text-end">Actual</th>
                                <th scope="col" class="text-end">Remaining</th>
                                <th scope="col" class="text-center">Utilization</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-center no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="budgetLinesTableBody">
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted mb-0 fs-12">Loading budget lines...</p>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-light">
                            <tr>
                                <th colspan="3" class="text-end">Totals:</th>
                                <th class="text-end" id="totalBudgetedFooter">KES 0</th>
                                <th class="text-end" id="totalActualFooter">KES 0</th>
                                <th class="text-end" id="totalRemainingFooter">KES 0</th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
