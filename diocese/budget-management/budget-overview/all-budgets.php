<?php
// 1. Start session & check auth
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

// 2. Check permission
requirePermission('diocese.budgetmanagement.budgetoverview.allbudgets.read');

// 3. Get user data
$user = getAuthUser();
$currentRole = getCurrentRole();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>All Budgets - Makueni West Diocese</title>
    <meta name="Description" content="View and manage all diocese budgets" />

    <!-- Favicon -->
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <!-- Choices JS -->
    <script src="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/scripts/choices.min.js"></script>

    <!-- Main Theme Js -->
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <!-- Choices Css -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/styles/choices.min.css" />
</head>

<body>
    <!-- App config (must load before any page script that uses AppConfig) -->
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <!-- Start Switcher -->
    <?php include '../../../includes/start-switcher.php' ?>

    <!-- Loader -->
    <?php include "../../../includes/loader.php" ?>

    <div class="page">
        <!-- Header -->
        <?php include '../../../includes/header.php' ?>

        <!-- Sidebar -->
        <?php include '../../../includes/sidebar.php' ?>

        <!-- Main Content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <?php
$pageTitle = 'All Budgets';
$pageIcon = 'ri-file-list-3-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/diocese/dashboard',
    'Budget Management' => null,
    'All Budgets' => null,
];
include __DIR__ . '/../../../includes/page-header.php';
?>

                <!-- Filters & Actions -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div class="card-title">
                                    <i class="ri-filter-3-line me-2 text-primary"></i>Filters & Actions
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-primary btn-wave" id="createBudgetBtn">
                                        <i class="ri-add-line me-1"></i>Create New Budget
                                    </button>
                                    <button class="btn btn-success-light btn-wave" id="exportBudgetsBtn">
                                        <i class="ri-file-excel-line me-1"></i>Export
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <!-- Fiscal Year Filter -->
                                    <div class="col-md-3">
                                        <label for="fiscalYearFilter" class="form-label">Fiscal Year</label>
                                        <select class="form-control" id="fiscalYearFilter">
                                            <option value="">All Years</option>
                                        </select>
                                    </div>

                                    <!-- Status Filter -->
                                    <div class="col-md-3">
                                        <label for="statusFilter" class="form-label">Status</label>
                                        <select class="form-control" id="statusFilter">
                                            <option value="">All Statuses</option>
                                        </select>
                                    </div>

                                    <!-- Budget Type Filter -->
                                    <div class="col-md-3">
                                        <label for="budgetTypeFilter" class="form-label">Budget Type</label>
                                        <select class="form-control" id="budgetTypeFilter">
                                            <option value="">All Types</option>
                                        </select>
                                    </div>

                                    <!-- Search -->
                                    <div class="col-md-3">
                                        <label for="searchInput" class="form-label">Search</label>
                                        <input type="text" class="form-control" id="searchInput" placeholder="Search budget name...">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row">
                    <!-- Total Budgets (compact stat card - template html/index-3.html) -->
                    <div class="col-xl-3 col-lg-6 col-md-6">
                        <div class="card custom-card">
                            <div class="card-body">
                                <div class="d-flex align-items-top">
                                    <div class="me-3">
                                        <span class="avatar avatar-md bg-primary text-white"><i class="ri-file-list-3-line fs-20"></i></span>
                                    </div>
                                    <div class="flex-fill" style="min-width: 0;">
                                        <h5 class="fw-semibold mb-1 lh-1 fs-20 text-break" id="totalBudgetsCount">0</h5>
                                        <p class="mb-0 fs-11 text-muted fw-semibold text-uppercase">Total Budgets</p>
                                        <p class="mb-0 mt-1 fs-12 text-muted" id="totalBudgetsTrend">+0% this month</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Active Budgets (compact stat card - template html/index-3.html) -->
                    <div class="col-xl-3 col-lg-6 col-md-6">
                        <div class="card custom-card">
                            <div class="card-body">
                                <div class="d-flex align-items-top">
                                    <div class="me-3">
                                        <span class="avatar avatar-md bg-success text-white"><i class="ri-play-circle-line fs-20"></i></span>
                                    </div>
                                    <div class="flex-fill" style="min-width: 0;">
                                        <h5 class="fw-semibold mb-1 lh-1 fs-20 text-break" id="activeBudgetsCount">0</h5>
                                        <p class="mb-0 fs-11 text-muted fw-semibold text-uppercase">Active Budgets</p>
                                        <p class="mb-0 mt-1 fs-12 text-muted" id="activeBudgetsTrend">+0% this month</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Approval (compact stat card - template html/index-3.html) -->
                    <div class="col-xl-3 col-lg-6 col-md-6">
                        <div class="card custom-card">
                            <div class="card-body">
                                <div class="d-flex align-items-top">
                                    <div class="me-3">
                                        <span class="avatar avatar-md bg-secondary text-dark"><i class="ri-time-line fs-20"></i></span>
                                    </div>
                                    <div class="flex-fill" style="min-width: 0;">
                                        <h5 class="fw-semibold mb-1 lh-1 fs-20 text-break" id="pendingApprovalCount">0</h5>
                                        <p class="mb-0 fs-11 text-muted fw-semibold text-uppercase">Pending Approval</p>
                                        <p class="mb-0 mt-1 fs-12 text-muted">Needs review</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Amount (compact stat card - template html/index-3.html) -->
                    <div class="col-xl-3 col-lg-6 col-md-6">
                        <div class="card custom-card">
                            <div class="card-body">
                                <div class="d-flex align-items-top">
                                    <div class="me-3">
                                        <span class="avatar avatar-md bg-primary text-white"><i class="ri-money-dollar-circle-line fs-20"></i></span>
                                    </div>
                                    <div class="flex-fill" style="min-width: 0;">
                                        <h5 class="fw-semibold mb-1 lh-1 fs-20 text-break" id="totalAmountValue">KES 0</h5>
                                        <p class="mb-0 fs-11 text-muted fw-semibold text-uppercase">Total Amount</p>
                                        <p class="mb-0 mt-1 fs-12 text-muted">All budgets combined</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Budgets Table -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">
                                    <i class="ri-list-check-2 me-2"></i>Budgets List
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover text-nowrap mb-0">
                                        <thead>
                                            <tr class="border-bottom">
                                                <th class="bg-light fw-semibold">Year</th>
                                                <th class="bg-light fw-semibold">Type</th>
                                                <th class="bg-light fw-semibold">Status</th>
                                                <th class="bg-light fw-semibold text-end">Income Budgeted</th>
                                                <th class="bg-light fw-semibold text-end">Expense Budgeted</th>
                                                <th class="bg-light fw-semibold text-end">Net Budget</th>
                                                <th class="bg-light fw-semibold">Created</th>
                                                <th class="bg-light fw-semibold text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="budgetsTableBody">
                                            <tr>
                                                <td colspan="8" class="text-center py-5">
                                                    <div class="spinner-border text-primary" role="status">
                                                        <span class="visually-hidden">Loading...</span>
                                                    </div>
                                                    <p class="mt-2 text-muted">Loading budgets...</p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-muted" id="resultsInfo">Showing 0 of 0 budgets</span>
                                    </div>
                                    <ul class="pagination mb-0" id="pagination">
                                        <!-- Pagination will be rendered here -->
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <!-- End Main Content -->

        <!-- Footer -->
        <?php include '../../../includes/footer.php' ?>

    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <!-- Popper JS -->
    <script src="<?= SITE_URL ?>/assets/libs/@popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>

    <!-- Node Waves JS -->
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>

    <!-- Simplebar JS -->
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>

    <!-- Custom JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>

    <!-- Toast JS -->
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>

    <!-- Constants -->
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>

    <!-- Budget API Handler -->
    <script src="<?= SITE_URL ?>/assets/js/pages/budget-management/api-handler.js"></script>

    <!-- Define Base URL for JavaScript -->
    <script>
        window.APP_BASE_URL = '<?= SITE_URL ?>';
    </script>

    <!-- Budget List JS V2 - NEW FILE -->
    <script src="<?= SITE_URL ?>/assets/js/pages/budget-management/budget-list-v2.js"></script>

    <!-- Initialize -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof BudgetList !== 'undefined' && BudgetList.init) {
        BudgetList.init();
      }
    });
    </script>
</body>

</html>
