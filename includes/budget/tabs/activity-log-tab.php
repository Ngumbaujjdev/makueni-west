<!-- Filters Section -->
<div class="row mb-3">
    <div class="col-xl-12">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-2">
                <!-- Action Type Filter -->
                <select class="form-select" id="logActionFilter" style="width: auto;">
                    <option value="">All Actions</option>
                    <option value="created">Created</option>
                    <option value="updated">Updated</option>
                    <option value="line_added">Line Added</option>
                    <option value="line_updated">Line Updated</option>
                    <option value="line_deleted">Line Deleted</option>
                    <option value="deduction_applied">Deduction Applied</option>
                    <option value="deduction_reversed">Deduction Reversed</option>
                    <option value="status_changed">Status Changed</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="activated">Activated</option>
                    <option value="closed">Closed</option>
                </select>

                <!--Date Range Filter -->
                <input type="date" class="form-control" id="logStartDate" style="width: auto;" placeholder="Start Date">
                <input type="date" class="form-control" id="logEndDate" style="width: auto;" placeholder="End Date">
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-light btn-wave" id="refreshLogBtn">
                    <i class="ri-refresh-line me-1"></i>Refresh
                </button>
                <button class="btn btn-success-light btn-wave" id="exportLogBtn">
                    <i class="ri-file-excel-line me-1"></i>Export Log
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Activity Summary -->
<div class="row mb-3">
    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-primary-transparent">
                            <i class="ri-history-line fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Total Activities</p>
                        <h5 class="fw-semibold mb-0" id="logTotalCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-success-transparent">
                            <i class="ri-checkbox-circle-line fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Status Changes</p>
                        <h5 class="fw-semibold mb-0 text-success" id="logStatusChangesCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-info-transparent">
                            <i class="ri-edit-line fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Line Changes</p>
                        <h5 class="fw-semibold mb-0 text-info" id="logLineChangesCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xxl-3 col-xl-6 col-lg-6 col-md-6 col-sm-12">
        <div class="card custom-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="avatar avatar-md avatar-rounded bg-warning-transparent">
                            <i class="ri-user-line fs-18"></i>
                        </span>
                    </div>
                    <div class="flex-fill">
                        <p class="mb-1 text-muted fs-11">Contributors</p>
                        <h5 class="fw-semibold mb-0 text-warning" id="logContributorsCount">0</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Activity Timeline (Index-8 Style) -->
<div class="row">
    <div class="col-xl-12">
        <div class="card custom-card">
            <div class="card-header">
                <div class="card-title">
                    Recent Activities
                </div>
            </div>
            <div class="card-body">
                <ul class="list-unstyled timeline-widget mb-0" id="activityLogTimeline">
                    <li class="timeline-widget-list">
                        <div class="text-center py-4">
                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted mb-0 fs-12">Loading activity log...</p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Empty State -->
<div id="noActivityState" style="display:none;">
    <div class="row">
        <div class="col-xl-12">
            <div class="card custom-card">
                <div class="card-body text-center py-5">
                    <div class="mb-3">
                        <span class="avatar avatar-xl avatar-rounded bg-primary-transparent">
                            <i class="ri-history-line fs-48"></i>
                        </span>
                    </div>
                    <h5 class="mb-2">No Activity Yet</h5>
                    <p class="text-muted mb-0">There is no activity history for this budget yet.</p>
                </div>
            </div>
        </div>
    </div>
</div>
