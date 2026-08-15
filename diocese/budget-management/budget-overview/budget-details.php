<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: <?= SITE_URL ?>/auth/login.php");
    exit();
}

// Get budget ID from URL (can be null if accessing from sidebar)
$budgetId = isset($_GET['id']) ? intval($_GET['id']) : null;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'view'; // view or edit
$printMode = isset($_GET['print']) ? $_GET['print'] === 'true' : false;

// If no budget ID is provided, the JavaScript will load the current year's budget
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Budget Details - Makueni West Diocese Management System</title>
    <meta name="Description" content="Budget details and management">
    <meta name="Author" content="Makueni West Diocese">

    <!-- Favicon -->
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <!-- Choices JS -->
    <script src="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/scripts/choices.min.js"></script>

    <!-- Main Theme Js -->
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <link
      rel="stylesheet"
      href="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.css"
    />
    <!-- Custom CSS for Tab Navigation -->
    <style>
        .nav-pills .nav-link.active {
            color: #fff !important;
        }
    </style>
</head>

<body>
    <!-- start switcher  -->
    <?php if (!$printMode): ?>
        <?php include '../../../includes/start-switcher.php' ?>
    <?php endif; ?>

    <!-- Loader -->
    <?php if (!$printMode): ?>
        <?php include "../../../includes/loader.php" ?>
    <?php endif; ?>
    <!-- Loader -->

    <div class="page">
        <!-- app-header -->
        <?php if (!$printMode): ?>
            <?php include '../../../includes/header.php' ?>
        <?php endif; ?>
        <!-- /app-header -->

        <!-- Start::app-sidebar -->
        <?php if (!$printMode): ?>
            <?php include '../../../includes/sidebar.php' ?>
        <?php endif; ?>
        <!-- End::app-sidebar -->

        <!-- Main Content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header & Actions -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <div>
                        <h1 class="page-title fw-semibold fs-18 mb-0">Budget Details</h1>
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="<?= SITE_URL ?>/diocese/dashboard.php">Dashboard</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="<?= SITE_URL ?>/diocese/budget-management/budget-overview/all-budgets.php">Budget Overview</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Details</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex gap-2 align-items-center no-print mt-md-0 mt-3 flex-wrap">
                        <button class="btn btn-secondary btn-wave btn-sm" onclick="window.history.back()">
                            <i class="ri-arrow-left-line me-1"></i>Back
                        </button>
                        
                        <!-- Action Buttons (Hidden by default, shown via JS based on status) -->
                        <button class="btn btn-primary btn-wave btn-sm" id="editBudgetBtn" style="display:none;">
                            <i class="ri-edit-line me-1"></i>Edit
                        </button>
                        <button class="btn btn-success btn-wave btn-sm" id="submitBudgetBtn" style="display:none;">
                            <i class="ri-send-plane-line me-1"></i>Submit
                        </button>
                        <button class="btn btn-success btn-wave btn-sm" id="approveBudgetBtn" style="display:none;">
                            <i class="ri-check-double-line me-1"></i>Approve
                        </button>
                        <button class="btn btn-danger btn-wave btn-sm" id="rejectBudgetBtn" style="display:none;">
                            <i class="ri-close-circle-line me-1"></i>Reject
                        </button>
                        <button class="btn btn-info btn-wave btn-sm" id="activateBudgetBtn" style="display:none;">
                            <i class="ri-play-circle-line me-1"></i>Activate
                        </button>
                        
                        <button class="btn btn-primary-light btn-wave btn-sm" id="printBudgetBtn">
                            <i class="ri-printer-line me-1"></i>Print
                        </button>
                    </div>
                </div>

                <!-- Budget Header Info -->
                <div class="row">
                    <div class="col-xl-9">
                        <div class="card custom-card">
                            <div class="card-body">
                                <div class="d-flex align-items-start">
                                    <div class="me-3">
                                        <span class="avatar avatar-xxl avatar-rounded bg-light text-primary">
                                            <i class="ri-wallet-3-line fs-32"></i>
                                        </span>
                                    </div>
                                    <div class="flex-fill">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h4 class="fw-bold mb-0" id="budgetName">Loading...</h4>
                                            <span class="badge bg-secondary" id="budgetStatus">Loading...</span>
                                        </div>
                                        <p class="text-muted mb-3" id="budgetDescription">Please wait while we load the budget details...</p>
                                        
                                        <div class="row g-3">
                                            <div class="col-sm-6 col-md-4 col-lg-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <span class="avatar avatar-sm bg-primary-transparent text-primary">
                                                            <i class="ri-calendar-line"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-block fs-11 text-muted text-uppercase fw-medium">Fiscal Year</span>
                                                        <span class="d-block fw-semibold" id="budgetYear">-</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6 col-md-4 col-lg-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <span class="avatar avatar-sm bg-info-transparent text-info">
                                                            <i class="ri-bar-chart-box-line"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-block fs-11 text-muted text-uppercase fw-medium">Type</span>
                                                        <span class="d-block fw-semibold" id="budgetType">-</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6 col-md-4 col-lg-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <span class="avatar avatar-sm bg-success-transparent text-success">
                                                            <i class="ri-checkbox-circle-line"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-block fs-11 text-muted text-uppercase fw-medium">Utilization</span>
                                                        <span class="d-block fw-semibold" id="utilizationRate">0%</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6 col-md-4 col-lg-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <span class="avatar avatar-sm bg-warning-transparent text-warning">
                                                            <i class="ri-list-check-2"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-block fs-11 text-muted text-uppercase fw-medium">Items</span>
                                                        <span class="d-block fw-semibold" id="totalLines">0</span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Creator Info -->
                                            <div class="col-sm-6 col-md-4 col-lg-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <span class="avatar avatar-sm bg-secondary-transparent text-secondary">
                                                            <i class="ri-user-add-line"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-block fs-11 text-muted text-uppercase fw-medium">Created By</span>
                                                        <span class="d-block fw-semibold" id="budgetCreator">-</span>
                                                        <span class="d-block fs-10 text-muted" id="budgetCreatedAt">-</span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Approver Info -->
                                            <div class="col-sm-6 col-md-4 col-lg-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <span class="avatar avatar-sm bg-success-transparent text-success">
                                                            <i class="ri-user-star-line"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-block fs-11 text-muted text-uppercase fw-medium">Approved By</span>
                                                        <span class="d-block fw-semibold" id="budgetApprover">-</span>
                                                        <span class="d-block fs-10 text-muted" id="budgetApprovedAt">-</span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Last Updated Info -->
                                            <div class="col-sm-6 col-md-4 col-lg-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <span class="avatar avatar-sm bg-primary-transparent text-primary">
                                                            <i class="ri-edit-line"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-block fs-11 text-muted text-uppercase fw-medium">Last Updated</span>
                                                        <span class="d-block fw-semibold" id="budgetUpdater">-</span>
                                                        <span class="d-block fs-10 text-muted" id="budgetUpdatedAt">-</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Stats (Right Side) -->
                    <div class="col-xl-3">
                        <div class="card custom-card">
                            <div class="card-body p-0">
                                <div class="p-3">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="fs-12 text-muted fw-medium">Total Income</span>
                                        <span class="badge bg-success-transparent text-success">+</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <h5 class="fw-bold mb-0" id="totalIncome">KES 0</h5>
                                        <span class="fs-11 text-muted" id="incomeActual">Act: 0</span>
                                    </div>
                                </div>
                                <div class="p-3">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="fs-12 text-muted fw-medium">Total Expense</span>
                                        <span class="badge bg-danger-transparent text-danger">-</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <h5 class="fw-bold mb-0" id="totalExpense">KES 0</h5>
                                        <span class="fs-11 text-muted" id="expenseActual">Act: 0</span>
                                    </div>
                                </div>
                                <div class="p-3">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="fs-12 text-muted fw-medium">Net Position</span>
                                        <span class="badge bg-primary-transparent text-primary">=</span>
                                    </div>
                                    <h5 class="fw-bold mb-0" id="netPosition">KES 0</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <ul class="nav nav-pills mb-0 d-flex p-3" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active text-white" data-bs-toggle="tab" role="tab" href="#overview-tab" aria-selected="true"
                                           style="border-radius: 8px; font-weight: 500; padding: 10px 20px; margin-right: 8px;">
                                            <i class="ri-dashboard-line me-1 align-middle fs-16"></i>Overview
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" href="#budget-lines-tab" aria-selected="false"
                                            style="border-radius: 8px; font-weight: 500; padding: 10px 20px; margin-right: 8px;">
                                            <i class="ri-list-check me-1 align-middle fs-16"></i>Budget Lines
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" href="#deductions-tab" aria-selected="false"
                                            style="border-radius: 8px; font-weight: 500; padding: 10px 20px; margin-right: 8px;">
                                            <i class="ri-subtract-line me-1 align-middle fs-16"></i>Deductions
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" href="#details-tab" aria-selected="false"
                                            style="border-radius: 8px; font-weight: 500; padding: 10px 20px; margin-right: 8px;">
                                            <i class="ri-information-line me-1 align-middle fs-16"></i>Details
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" href="#activity-log-tab" aria-selected="false"
                                            style="border-radius: 8px; font-weight: 500; padding: 10px 20px;">
                                            <i class="ri-history-line me-1 align-middle fs-16"></i>Budget Logs
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content">
                                    <!-- Overview Tab -->
                                    <div class="tab-pane show active" id="overview-tab" role="tabpanel">
                                        <?php include 'tabs/overview-tab.php'; ?>
                                    </div>

                                    <!-- Budget Lines Tab -->
                                    <div class="tab-pane" id="budget-lines-tab" role="tabpanel">
                                        <?php include 'tabs/budget-lines-tab.php'; ?>
                                    </div>

                                    <!-- Deductions Tab -->
                                    <div class="tab-pane" id="deductions-tab" role="tabpanel">
                                        <?php include 'tabs/deductions-tab.php'; ?>
                                    </div>

                                    <!-- Details Tab -->
                                    <div class="tab-pane" id="details-tab" role="tabpanel">
                                        <?php include 'tabs/details-tab.php'; ?>
                                    </div>

                                    <!-- Activity Log Tab (Budget Logs) -->
                                    <div class="tab-pane" id="activity-log-tab" role="tabpanel">
                                        <?php include 'tabs/activity-log-tab.php'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php if (!$printMode): ?>
            <?php include '../../../includes/footer.php'; ?>
        <?php endif; ?>

    </div>

    <!-- Scripts -->
    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>

    <!-- Node Waves JS-->
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>

    <!-- Simplebar JS -->
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>

    <!-- Color Picker JS -->
    <script src="<?= SITE_URL ?>/assets/libs/%40simonwep/pickr/pickr.es5.min.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>

    <!-- Apex Charts JS -->
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>

    <!-- Custom JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>

    <!-- Toast JS -->
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>

    <!-- Constants -->
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>

    <!-- Budget Details JS -->
    <script src="<?= SITE_URL ?>/assets/js/pages/budget-management/budget-details.js"></script>

    <script>
        // Initialize budget details with ID from URL
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const budgetId = urlParams.get('id') || <?php echo $budgetId ? $budgetId : 'null'; ?>;
            const pageMode = '<?php echo $mode; ?>';
            const isPrintMode = <?php echo $printMode ? 'true' : 'false'; ?>;

            if (budgetId && typeof BudgetDetailsManagement !== 'undefined') {
                BudgetDetailsManagement.init(budgetId, pageMode, isPrintMode);
            } else if (!budgetId) {
                console.error('No budget ID provided');
            }
        });
    </script>
</body>

</html>
