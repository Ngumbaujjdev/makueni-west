<?php
/**
 * Budget Details & Metadata Tab
 * Displays budget metadata, territory information, and approval workflow
 */
?>

<!-- Budget Metadata Section -->
<div class="row mb-3">
    <div class="col-xl-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">Budget Information</h5>
                <p class="text-muted mb-0 fs-12">Complete budget metadata and ownership details</p>
            </div>
        </div>
    </div>
</div>

<!-- Metadata Cards -->
<div class="row mb-3">
    <!-- Territory Information -->
    <div class="col-xxl-6 col-xl-6 col-lg-12">
        <div class="card custom-card border border-light h-100">
            <div class="card-header bg-light">
                <div class="card-title">
                    <i class="ri-map-pin-line me-2 text-primary"></i>Territory & Ownership
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <td class="text-muted fw-medium" style="width: 40%;">Territory Type:</td>
                                <td class="fw-semibold" id="territoryType">-</td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Territory Name:</td>
                                <td class="fw-semibold" id="territoryName">-</td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Budget Type:</td>
                                <td><span class="badge bg-info-transparent" id="budgetTypeDetail">-</span></td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Fiscal Year:</td>
                                <td><span class="badge bg-primary-transparent" id="fiscalYearDetail">-</span></td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Current Status:</td>
                                <td id="currentStatusDetail">-</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval Workflow -->
    <div class="col-xxl-6 col-xl-6 col-lg-12">
        <div class="card custom-card border border-light h-100">
            <div class="card-header bg-light">
                <div class="card-title">
                    <i class="ri-git-branch-line me-2 text-success"></i>Approval Workflow
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <td class="text-muted fw-medium" style="width: 40%;">Created By:</td>
                                <td class="fw-semibold" id="creatorDetail">-</td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Created Date:</td>
                                <td id="createdAtDetail">-</td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Last Updated By:</td>
                                <td class="fw-semibold" id="updaterDetail">-</td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Last Updated:</td>
                                <td id="updatedAtDetail">-</td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Approved By:</td>
                                <td class="fw-semibold" id="approverDetail">-</td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-medium">Approved Date:</td>
                                <td id="approvedAtDetail">-</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approval Notes Section -->
<div class="row">
    <div class="col-xl-12">
        <div class="card custom-card border border-light">
            <div class="card-header bg-light">
                <div class="card-title">
                    <i class="ri-file-text-line me-2 text-warning"></i>Notes & Comments
                </div>
            </div>
            <div class="card-body">
                <div id="approvalNotesSection">
                    <div class="alert alert-light mb-0" role="alert">
                        <i class="ri-information-line me-2"></i>
                        <span id="approvalNotesText">No approval notes or comments available.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// This will be populated by budget-details.js when the budget data is loaded
</script>
