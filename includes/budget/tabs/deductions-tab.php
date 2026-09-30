<!-- Header with Add Button -->
<div class="row mb-3">
    <div class="col-xl-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">Applied Deductions</h5>
                <p class="text-muted mb-0 fs-12">View and manage deductions applied to this budget</p>
            </div>
            <button class="btn btn-primary btn-wave no-print" id="applyDeductionBtn" style="display:none;">
                <i class="ri-add-line me-1"></i>Apply Deduction
            </button>
        </div>
    </div>
</div>

<!-- Deductions Summary Cards -->
<div class="row mb-3">
    <div class="col-xxl-4 col-xl-4 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-lg avatar-rounded bg-primary text-white shadow-sm">
                            <i class="ri-subtract-line fs-24"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-12 text-uppercase fw-bold">Total Deductions</p>
                        <h4 class="fw-bold mb-0 text-primary" id="totalDeductionsCount">0</h4>
                        <small class="text-muted"><i class="ri-arrow-right-circle-line text-primary me-1"></i>Active deductions</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-4 col-xl-4 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-lg avatar-rounded bg-danger text-white shadow-sm">
                            <i class="ri-money-dollar-circle-line fs-24"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-12 text-uppercase fw-bold">Total Deducted</p>
                        <h4 class="fw-bold mb-0 text-danger" id="totalDeductedAmount">KES 0</h4>
                        <small class="text-muted"><i class="ri-arrow-down-circle-line text-danger me-1"></i>From all lines</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-4 col-xl-4 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-lg avatar-rounded bg-warning text-white shadow-sm">
                            <i class="ri-list-check fs-24"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-12 text-uppercase fw-bold">Affected Lines</p>
                        <h4 class="fw-bold mb-0 text-warning" id="affectedLinesCount">0</h4>
                        <small class="text-muted"><i class="ri-alert-line text-warning me-1"></i>Budget lines impacted</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Deductions Table -->
<div class="row">
    <div class="col-xl-12">
        <div class="card custom-card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover align-middle">
                        <thead class="bg-primary text-white">
                            <tr>
                                <th scope="col" class="text-white">Deduction Name</th>
                                <th scope="col" class="text-white">Type</th>
                                <th scope="col" class="text-white">Rate/Amount</th>
                                <th scope="col" class="text-white text-center">Affected Lines</th>
                                <th scope="col" class="text-white text-end">Total Deducted</th>
                                <th scope="col" class="text-white">Applied By</th>
                                <th scope="col" class="text-white">Applied Date</th>
                                <th scope="col" class="text-white text-center no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="deductionsTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted mb-0 fs-12">Loading deductions...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Empty State (will be shown via JS if no deductions) -->
<div id="noDeductionsState" style="display:none;">
    <div class="row">
        <div class="col-xl-12">
            <div class="card custom-card">
                <div class="card-body text-center py-5">
                    <div class="mb-3">
                        <span class="avatar avatar-xl avatar-rounded bg-primary-transparent">
                            <i class="ri-subtract-line fs-48"></i>
                        </span>
                    </div>
                    <h5 class="mb-2">No Deductions Applied</h5>
                    <p class="text-muted mb-3">This budget has no deductions applied yet.</p>
                    <button class="btn btn-primary btn-wave no-print" id="applyFirstDeductionBtn" style="display:none;">
                        <i class="ri-add-line me-1"></i>Apply First Deduction
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
