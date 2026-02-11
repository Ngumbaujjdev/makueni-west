<?php
// 1. FIRST: Start session & check auth (NO HTML BEFORE THIS!)
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

// 2. Check specific permission for Budget Lines
requirePermission('diocese.settings.budgetsettings.budgetline.read');

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
    <title>Budget Lines Management - YNEX</title>
    <meta name="Description" content="Bootstrap Responsive Admin Web Dashboard Template" />
    <meta name="Author" content="Spruko Technologies Private Limited" />
    <meta name="keywords"
        content="budget lines, budget management, income, expense, financial management" />

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
                        <i class="ri-list-check me-2"></i>Budget Lines Management
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
                                <li class="breadcrumb-item active" aria-current="page">Budget Lines</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Budget Lines Management Card -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">
                                    <i class="ri-list-check me-2 text-primary"></i>Budget Lines
                                </div>
                            </div>
                            <div class="card-body">

                                <!-- Header -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1 fw-semibold">
                                            <i class="ri-list-check me-2 text-primary"></i>Budget Lines
                                        </h5>
                                        <p class="text-muted mb-0 fs-13">Manage income and expense line items for budgets</p>
                                    </div>
                                    <div>
                                        <button class="btn btn-success" id="createBudgetLineBtn">
                                            <i class="ri-add-line me-1"></i>Create Budget Line
                                        </button>
                                    </div>
                                </div>

                                <!-- Filter Section -->
                                <div class="card border mb-3">
                                    <div class="card-body p-3">
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold mb-1">
                                                    <i class="ri-filter-3-line me-1 text-primary"></i>Territory Scope Filter
                                                </label>
                                                <select class="form-select" id="territoryFilter">
                                                    <option value="">All Territories</option>
                                                    <option value="diocese">Diocese</option>
                                                    <option value="region">Region</option>
                                                    <option value="subregion">Sub-Region</option>
                                                    <option value="church">Church</option>
                                                    <option value="all">All Levels</option>
                                                </select>
                                                <small class="text-muted fs-12 mt-1 d-block">
                                                    <i class="ri-information-line me-1"></i>Filter updates automatically when you select
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tabs Navigation -->
                                <ul class="nav nav-tabs tab-style-6 mb-3" role="tablist">
                                    <li class="nav-item">
                                        <button class="nav-link active" id="all-lines-tab" data-bs-toggle="tab" data-bs-target="#all-lines" type="button" role="tab">
                                            <i class="ri-list-check me-2"></i>All Lines
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" id="income-lines-tab" data-bs-toggle="tab" data-bs-target="#income-lines" type="button" role="tab">
                                            <i class="ri-arrow-up-circle-line me-2 text-success"></i>Income Lines
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" id="expense-lines-tab" data-bs-toggle="tab" data-bs-target="#expense-lines" type="button" role="tab">
                                            <i class="ri-arrow-down-circle-line me-2 text-danger"></i>Expense Lines
                                        </button>
                                    </li>
                                </ul>

                                <!-- Tab Content -->
                                <div class="tab-content">
                                    <!-- All Lines Tab -->
                                    <div class="tab-pane fade show active" id="all-lines" role="tabpanel">
                                        <div class="card border">
                                            <div class="card-body">
                                                <div id="allLinesContainer">
                                                    <!-- Populated by JS -->
                                                    <div class="text-center py-5">
                                                        <div class="spinner-border text-primary" role="status">
                                                            <span class="visually-hidden">Loading...</span>
                                                        </div>
                                                        <p class="text-muted mt-2 mb-0">Loading all budget lines...</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Income Lines Tab -->
                                    <div class="tab-pane fade" id="income-lines" role="tabpanel">
                                        <div class="card border border-success">
                                            <div class="card-header bg-success-transparent">
                                                <h6 class="mb-0 text-success fw-semibold">
                                                    <i class="ri-arrow-up-circle-line me-2"></i>Income Budget Lines
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div id="incomeLinesContainer">
                                                    <!-- Populated by JS -->
                                                    <div class="text-center py-5">
                                                        <div class="spinner-border text-success" role="status">
                                                            <span class="visually-hidden">Loading...</span>
                                                        </div>
                                                        <p class="text-muted mt-2 mb-0">Loading income lines...</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Expense Lines Tab -->
                                    <div class="tab-pane fade" id="expense-lines" role="tabpanel">
                                        <div class="card border border-danger">
                                            <div class="card-header bg-danger-transparent">
                                                <h6 class="mb-0 text-danger fw-semibold">
                                                    <i class="ri-arrow-down-circle-line me-2"></i>Expense Budget Lines
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div id="expenseLinesContainer">
                                                    <!-- Populated by JS -->
                                                    <div class="text-center py-5">
                                                        <div class="spinner-border text-danger" role="status">
                                                            <span class="visually-hidden">Loading...</span>
                                                        </div>
                                                        <p class="text-muted mt-2 mb-0">Loading expense lines...</p>
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

            </div>
        </div>
        <!-- End::app-content -->

        <!-- Create/Edit Budget Line Modal -->
        <div class="modal fade" id="budgetLineModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-add-line me-2"></i><span id="modalTitle">Create Budget Line</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <form id="budgetLineForm">
                            <input type="hidden" id="budgetLineId">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Budget Line Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Budget lines represent specific income or expense items</li>
                                            <li class="mb-1">Choose appropriate category (Income or Expense)</li>
                                            <li class="mb-0">Set territory scope to control visibility</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Basic Information -->
                            <div class="row g-3">
                                <!-- Name -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Line Name <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-file-list-line text-success"></i>
                                        </span>
                                        <input type="text" class="form-control" id="lineName"
                                               placeholder="e.g., Equipment, Salaries" required>
                                    </div>
                                </div>

                                <!-- Category -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Category <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="categoryId" required>
                                        <option value="">Select Category</option>
                                    </select>
                                </div>

                                <!-- Territory Scope -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Territory Scope <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="territoryScope" required>
                                        <option value="">Select Territory</option>
                                        <option value="diocese">Diocese</option>
                                        <option value="region">Region</option>
                                        <option value="subregion">Sub-Region</option>
                                        <option value="church">Church</option>
                                        <option value="all">All Levels</option>
                                    </select>
                                    <small class="text-muted fs-12 mt-1 d-block">
                                        <i class="ri-information-line me-1"></i>Which territory level can use this line
                                    </small>
                                </div>

                                <!-- Display Order -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Display Order <span class="text-muted">(Optional)</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-sort-asc text-info"></i>
                                        </span>
                                        <input type="number" class="form-control" id="displayOrder"
                                               min="0" placeholder="e.g., 1, 2, 3">
                                    </div>
                                    <small class="text-muted fs-12 mt-1 d-block">
                                        <i class="ri-information-line me-1"></i>Leave empty for auto-generation
                                    </small>
                                </div>

                                <!-- Description -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold mb-2">Description</label>
                                    <textarea class="form-control" id="lineDescription" rows="3"
                                              placeholder="Brief description of this budget line..."></textarea>
                                </div>

                                <!-- Active Status -->
                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-between p-3 border rounded bg-light">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">
                                                <i class="ri-toggle-line me-2 text-success"></i>Active Status
                                            </h6>
                                            <p class="text-muted mb-0 fs-13">
                                                Toggle to activate or deactivate this line
                                            </p>
                                        </div>
                                        <div class="form-check form-switch form-switch-lg">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="lineActive" checked>
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
                        <button type="button" class="btn btn-success btn-lg px-4" id="saveBudgetLineBtn">
                            <i class="ri-save-line me-1"></i>Save Budget Line
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteBudgetLineModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-delete-bin-line me-2"></i>Delete Budget Line
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
                                        This action cannot be undone. The budget line will be permanently deleted.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <p class="mb-2">Are you sure you want to delete the budget line:</p>
                        <p class="fw-semibold text-primary fs-16 mb-0" id="deleteBudgetLineName"></p>
                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-danger btn-lg px-4" id="confirmDeleteBtn">
                            <i class="ri-delete-bin-line me-1"></i>Yes, Delete Line
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- View Budget Line Modal -->
        <div class="modal fade" id="viewBudgetLineModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-eye-line me-2"></i>Budget Line Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">
                        <!-- Tab Navigation -->
                        <div class="p-3 border-bottom border-block-end-dashed">
                            <ul class="nav nav-tabs mb-0 tab-style-6 justify-content-start" id="viewLineTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="line-details-tab" data-bs-toggle="tab"
                                        data-bs-target="#line-details-tab-pane" type="button" role="tab">
                                        <i class="ri-file-list-3-line me-1"></i>Details
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="line-audit-tab" data-bs-toggle="tab"
                                        data-bs-target="#line-audit-tab-pane" type="button" role="tab">
                                        <i class="ri-history-line me-1"></i>Activity Log
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Tab Content -->
                        <div class="p-3">
                            <div class="tab-content" id="viewBudgetLineTabContent">
                                <!-- Details Tab -->
                                <div class="tab-pane fade show active" id="line-details-tab-pane" role="tabpanel">
                                    <div class="card shadow-none border">
                                        <div class="card-header bg-primary-transparent">
                                            <h6 class="card-title mb-0">
                                                <i class="ri-information-line me-2"></i>Budget Line Information
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-text me-1 text-primary"></i>Line Name
                                                    </label>
                                                    <p class="mb-0 fw-semibold text-dark fs-15" id="view-line-name">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-link me-1 text-warning"></i>Slug
                                                    </label>
                                                    <p class="mb-0"><code class="fs-14" id="view-line-slug">-</code></p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-folder-line me-1 text-info"></i>Category
                                                    </label>
                                                    <p class="mb-0 fw-medium text-dark" id="view-line-category">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-map-pin-line me-1 text-success"></i>Territory Scope
                                                    </label>
                                                    <p class="mb-0" id="view-line-territory">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-sort-asc me-1 text-info"></i>Display Order
                                                    </label>
                                                    <p class="mb-0 fw-medium text-dark" id="view-line-order">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-shield-check-line me-1 text-warning"></i>System Default
                                                    </label>
                                                    <p class="mb-0" id="view-line-system-default">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-checkbox-circle-line me-1 text-success"></i>Status
                                                    </label>
                                                    <p class="mb-0" id="view-line-status">-</p>
                                                </div>
                                                <div class="col-12 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-file-text-line me-1 text-secondary"></i>Description
                                                    </label>
                                                    <p class="mb-0 text-dark" id="view-line-description">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-calendar-check-line me-1 text-info"></i>Created At
                                                    </label>
                                                    <p class="mb-0 fw-medium text-dark fs-13" id="view-line-created-at">-</p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-calendar-event-line me-1 text-secondary"></i>Updated At
                                                    </label>
                                                    <p class="mb-0 fw-medium text-dark fs-13" id="view-line-updated-at">-</p>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold text-muted">
                                                        <i class="ri-key-2-line me-1 text-primary"></i>Record ID
                                                    </label>
                                                    <p class="mb-0">
                                                        <span class="badge bg-primary fs-13 px-3 py-2" id="view-line-id">-</span>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Audit Trail Tab -->
                                <div class="tab-pane fade" id="line-audit-tab-pane" role="tabpanel">
                                    <div class="card shadow-none border">
                                        <div class="card-header bg-primary-transparent">
                                            <h6 class="card-title mb-0">
                                                <i class="ri-file-list-3-line me-2"></i>Activity Timeline
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="lineAuditContainer">
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

    <!-- Budget Lines Management JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/budget-settings/budget-line.js"></script>

    <!-- Initialize Budget Lines Page -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                if (typeof BudgetLinesManagement !== 'undefined' && typeof BudgetLinesManagement.init === 'function') {
                    console.log('📦 Initializing Budget Lines Management...');
                    BudgetLinesManagement.init();
                } else {
                    console.error('❌ BudgetLinesManagement not loaded');
                }
            }, 100);
        });
    </script>
</body>

</html>
