<?php
// 1. FIRST: Start session & check auth (NO HTML BEFORE THIS!)
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

// 2. Check specific permission for Budget Types
requirePermission('diocese.settings.budgetsettings.budgettype.read');

// 3. Get user data
$user = getAuthUser();
$currentRole = getCurrentRole();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budget Types Management - YNEX</title>
    <meta name="Description" content="Bootstrap Responsive Admin Web Dashboard Template" />
    <meta name="Author" content="Spruko Technologies Private Limited" />
    <meta name="keywords"
        content="budget types, budget management, monthly, quarterly, yearly, financial management" />

    <!-- Favicon -->
    <link rel="icon" href="http://localhost:8080/makueni-west/assets/images/brand-logos/favicon/favicon.ico"
        type="image/x-icon" />

    <!-- Choices JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/choices.js/public/assets/scripts/choices.min.js">
    </script>

    <!-- Main Theme Js -->
    <script src="http://localhost:8080/makueni-west/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="http://localhost:8080/makueni-west/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/icons.css" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="http://localhost:8080/makueni-west/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="http://localhost:8080/makueni-west/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <!-- Color Picker Css -->
    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/libs/flatpickr/flatpickr.min.css" />
    <link rel="stylesheet"
        href="http://localhost:8080/makueni-west/assets/libs/%40simonwep/pickr/themes/nano.min.css" />

    <!-- Choices Css -->
    <link rel="stylesheet"
        href="http://localhost:8080/makueni-west/assets/libs/choices.js/public/assets/styles/choices.min.css" />

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />

</head>

<body>
    <!-- start switcher  -->
    <?php include '../../../includes/start-switcher.php' ?>

    <!-- Loader -->
    <?php include "../../../includes/loader.php" ?>
    <!-- Loader -->

    <div class="page">
        <!-- app-header -->
        <?php include '../../../includes/header.php' ?>
        <!-- /app-header -->
        <!-- Start::app-sidebar -->
        <?php include '../../../includes/sidebar.php' ?>
        <!-- End::app-sidebar -->

        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <h1 class="page-title fw-semibold fs-18 mb-0">
                        <i class="ri-calendar-2-line me-2"></i>Budget Types Management
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="/makueni-west/diocese/dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">Settings</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">Budget Settings</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Budget Types</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Budget Types Management Card -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">
                                    <i class="ri-calendar-2-line me-2 text-primary"></i>Budget Types
                                </div>
                            </div>
                            <div class="card-body">

                                <!-- Header -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1 fw-semibold">
                                            <i class="ri-calendar-2-line me-2 text-primary"></i>Budget Types
                                        </h5>
                                        <p class="text-muted mb-0 fs-13">Define budget duration types (Monthly, Quarterly, Yearly, etc.)</p>
                                    </div>
                                    <div>
                                        <button class="btn btn-success" id="createBudgetTypeBtn">
                                            <i class="ri-add-line me-1"></i>Create Budget Type
                                        </button>
                                    </div>
                                </div>

                                <!-- Budget Types List -->
                                <div class="card border">
                                    <div class="card-body">
                                        <div id="budgetTypesContainer">
                                            <!-- Populated by JS -->
                                            <div class="text-center py-5">
                                                <div class="spinner-border text-primary" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                                <p class="text-muted mt-2 mb-0">Loading budget types...</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <!-- End::app-content -->

        <!-- Create/Edit Budget Type Modal -->
        <div class="modal fade" id="budgetTypeModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-add-line me-2"></i><span id="modalTitle">Create Budget Type</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <form id="budgetTypeForm">
                            <input type="hidden" id="budgetTypeId">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Budget Type Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Budget types define the time period for budgets (e.g., Monthly = 1 month)</li>
                                            <li class="mb-1">Duration is measured in months</li>
                                            <li class="mb-0">Leave duration empty for custom/flexible periods</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Basic Information -->
                            <div class="row g-3">
                                <!-- Name -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Type Name <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-calendar-line text-success"></i>
                                        </span>
                                        <input type="text" class="form-control" id="typeName"
                                               placeholder="e.g., Monthly, Quarterly" required>
                                    </div>
                                </div>

                                <!-- Duration Months -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Duration (Months) <span class="text-muted">(Optional)</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-time-line text-info"></i>
                                        </span>
                                        <input type="number" class="form-control" id="durationMonths"
                                               min="1" placeholder="e.g., 1, 3, 12">
                                    </div>
                                    <small class="text-muted fs-12 mt-1 d-block">
                                        <i class="ri-information-line me-1"></i>Leave empty for custom duration
                                    </small>
                                </div>

                                <!-- Slug -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold mb-2">
                                        Slug <span class="text-muted">(Optional - Auto-generated)</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-link text-warning"></i>
                                        </span>
                                        <input type="text" class="form-control" id="typeSlug"
                                               placeholder="Leave empty for auto-generation">
                                    </div>
                                    <small class="text-muted fs-12 mt-1 d-block">
                                        <i class="ri-magic-line me-1 text-success"></i>Auto-generated from name if empty
                                    </small>
                                </div>

                                <!-- Active Status -->
                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-between p-3 border rounded bg-light">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">
                                                <i class="ri-toggle-line me-2 text-success"></i>Active Status
                                            </h6>
                                            <p class="text-muted mb-0 fs-13">
                                                Toggle to activate or deactivate this type
                                            </p>
                                        </div>
                                        <div class="form-check form-switch form-switch-lg">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="typeActive" checked>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-success btn-lg px-4" id="saveBudgetTypeBtn">
                            <i class="ri-save-line me-1"></i>Save Budget Type
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteBudgetTypeModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-delete-bin-line me-2"></i>Delete Budget Type
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-danger bg-danger-transparent border-0 mb-3 p-3">
                            <div class="d-flex align-items-start">
                                <i class="ri-error-warning-line fs-20 me-3 text-danger"></i>
                                <div class="flex-fill">
                                    <h6 class="alert-heading mb-2 fw-semibold">Warning: Permanent Action</h6>
                                    <p class="mb-0 text-dark fs-13">
                                        This action cannot be undone. The budget type will be permanently deleted.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <p class="mb-2">Are you sure you want to delete the budget type:</p>
                        <p class="fw-semibold text-primary fs-16 mb-0" id="deleteBudgetTypeName"></p>
                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-danger btn-lg px-4" id="confirmDeleteBtn">
                            <i class="ri-delete-bin-line me-1"></i>Yes, Delete Type
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- View Budget Type Modal -->
        <div class="modal fade" id="viewBudgetTypeModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-eye-line me-2"></i>Budget Type Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">
                        <!-- Tab Navigation -->
                        <div class="p-3 border-bottom border-block-end-dashed">
                            <ul class="nav nav-tabs mb-0 tab-style-6 justify-content-start" id="viewTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="details-tab" data-bs-toggle="tab"
                                        data-bs-target="#details-tab-pane" type="button" role="tab">
                                        <i class="ri-file-list-3-line me-1"></i>Details
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="audit-tab" data-bs-toggle="tab"
                                        data-bs-target="#audit-tab-pane" type="button" role="tab">
                                        <i class="ri-history-line me-1"></i>Activity Log
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Tab Content -->
                        <div class="p-3">
                            <div class="tab-content" id="viewBudgetTypeTabContent">
                                <!-- Details Tab -->
                                <div class="tab-pane fade show active" id="details-tab-pane" role="tabpanel">
                                    <div class="card shadow-none border">
                                        <div class="card-header bg-primary-transparent">
                                            <h6 class="card-title mb-0">
                                                <i class="ri-information-line me-2"></i>Budget Type Information
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-text me-1 text-primary"></i>Type Name
                                                    </label>
                                                    <p class="mb-0 fw-semibold text-dark fs-15" id="view-name">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-link me-1 text-warning"></i>Slug
                                                    </label>
                                                    <p class="mb-0"><code class="fs-14" id="view-slug">-</code></p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-time-line me-1 text-info"></i>Duration (Months)
                                                    </label>
                                                    <p class="mb-0 fw-medium text-dark" id="view-duration">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-checkbox-circle-line me-1 text-success"></i>Status
                                                    </label>
                                                    <p class="mb-0" id="view-status">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-calendar-check-line me-1 text-info"></i>Created At
                                                    </label>
                                                    <p class="mb-0 fw-medium text-dark fs-13" id="view-created-at">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-calendar-event-line me-1 text-secondary"></i>Updated At
                                                    </label>
                                                    <p class="mb-0 fw-medium text-dark fs-13" id="view-updated-at">-</p>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-key-2-line me-1 text-primary"></i>Record ID
                                                    </label>
                                                    <p class="mb-0">
                                                        <span class="badge bg-primary fs-13 px-3 py-2" id="view-id">-</span>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Audit Trail Tab -->
                                <div class="tab-pane fade" id="audit-tab-pane" role="tabpanel">
                                    <div class="card shadow-none border">
                                        <div class="card-header bg-primary-transparent">
                                            <h6 class="card-title mb-0">
                                                <i class="ri-file-list-3-line me-2"></i>Activity Timeline
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="auditTrailContainer">
                                                <!-- Populated by JS -->
                                                <div class="text-center py-5">
                                                    <div class="spinner-border text-primary" role="status">
                                                        <span class="visually-hidden">Loading...</span>
                                                    </div>
                                                    <p class="text-muted mt-2 mb-0">Loading activity log...</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Start -->
        <?php include '../../../includes/footer.php' ?>
        <!-- Footer End -->
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>
    <!-- Scroll To Top -->

    <!-- Popper JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/%40popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/defaultmenu.min.js"></script>

    <!-- Node Waves JS-->
    <script src="http://localhost:8080/makueni-west/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/sticky.js"></script>

    <!-- Simplebar JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/js/simplebar.js"></script>

    <!-- Color Picker JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/%40simonwep/pickr/pickr.es5.min.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/custom-switcher.min.js"></script>

    <!-- Custom JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/custom.js"></script>

    <!-- Toast JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/Toasts.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/js/utils/toast.js"></script>

    <!-- Constants -->
    <script src="http://localhost:8080/makueni-west/assets/js/config/constants.js"></script>

    <!-- Auth Helpers -->
    <script src="http://localhost:8080/makueni-west/assets/js/utils/auth-helpers.js"></script>

    <!-- Logout Handler -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/authentication/logout.js"></script>

    <!-- jQuery (Required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <!-- DataTables JS -->
    <script src="http://localhost:8080/makueni-west/assets/data-tables/1.12.1/js/jquery.dataTables.min.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js"></script>

    <!-- Budget Types Management JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/budget-settings/budget-type.js"></script>

    <!-- Initialize Budget Types Page -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                if (typeof BudgetTypesManagement !== 'undefined' && typeof BudgetTypesManagement.init === 'function') {
                    console.log('📦 Initializing Budget Types Management...');
                    BudgetTypesManagement.init();
                } else {
                    console.error('❌ BudgetTypesManagement not loaded');
                }
            }, 100);
        });
    </script>
</body>

</html>
