<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.attendancereports.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Attendance Reports';
$pageIcon = 'ri-bar-chart-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Attendance Reports' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Attendance Reports - Makueni West Diocese</title>
    <meta name="Description" content="Attendance trends and gathering-type breakdown for this church" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
    </script>
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <?php include __DIR__ . '/../../includes/start-switcher.php' ?>
    <?php include __DIR__ . '/../../includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../../includes/header.php' ?>
        <?php include __DIR__ . '/../../includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../../includes/page-header.php' ?>

                <!-- Filters + view toggle -->
                <div class="card custom-card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div class="btn-group" role="group" aria-label="View toggle">
                                <button type="button" class="btn btn-primary" id="viewDashboardBtn">
                                    <i class="ri-dashboard-line me-1"></i>Dashboard
                                </button>
                                <button type="button" class="btn btn-outline-primary" id="viewTableBtn">
                                    <i class="ri-table-line me-1"></i>Table
                                </button>
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <label for="reportFiscalYear" class="form-label mb-0 fw-semibold">Year</label>
                                <select class="form-select" id="reportFiscalYear" style="min-width: 120px;">
                                    <option value="">Loading years...</option>
                                </select>
                                <label for="reportCategoryFilter" class="form-label mb-0 fw-semibold">Gathering</label>
                                <select class="form-select" id="reportCategoryFilter" style="min-width: 180px;">
                                    <option value="">All Categories</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DASHBOARD view -->
                <div id="dashboardView">
                    <div class="row g-3 mb-3" id="statCardsRow">
                        <!-- Stat cards injected by attendance-reports.js -->
                    </div>

                    <div class="card custom-card mb-3">
                        <div class="card-header">
                            <div class="card-title"><i class="ri-line-chart-line me-2 text-primary"></i>Monthly Attendance Trend</div>
                        </div>
                        <div class="card-body">
                            <div id="attendanceTrendChart"></div>
                        </div>
                    </div>

                    <div class="card custom-card">
                        <div class="card-header">
                            <div class="card-title"><i class="ri-list-check-2 me-2 text-primary"></i>Gathering Type Breakdown</div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="fw-semibold text-dark">Name</th>
                                            <th class="fw-semibold text-dark">Category</th>
                                            <th class="fw-semibold text-dark">Times Held</th>
                                            <th class="fw-semibold text-dark">Total Attendance</th>
                                            <th class="fw-semibold text-dark">Average Attendance</th>
                                            <th class="fw-semibold text-dark">Last Held</th>
                                        </tr>
                                    </thead>
                                    <tbody id="breakdownTableBody">
                                        <!-- Rows injected by attendance-reports.js -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TABLE view -->
                <div id="tableView" style="display: none;">
                    <div class="card custom-card">
                        <div class="card-header">
                            <div class="card-title"><i class="ri-file-list-3-line me-2 text-primary"></i>All Records</div>
                        </div>
                        <div class="card-body p-0">
                            <div class="px-3 pt-3" id="filterToolbar"></div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="attendanceReportTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="fw-semibold text-dark">Date</th>
                                            <th class="fw-semibold text-dark">Category</th>
                                            <th class="fw-semibold text-dark">Gathering</th>
                                            <th class="fw-semibold text-dark">Total Attendance</th>
                                            <th class="fw-semibold text-dark">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportTableBody">
                                        <!-- Rows injected by attendance-reports.js -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../includes/footer.php' ?>
    </div>

    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <script src="<?= SITE_URL ?>/assets/libs/@popperjs/core/umd/popper.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>

    <!-- jQuery + DataTables (search/filter/pagination) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/jquery.dataTables.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-reports.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceReports.init());
    </script>
</body>

</html>
