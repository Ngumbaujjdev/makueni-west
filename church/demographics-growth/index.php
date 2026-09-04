<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('churchdemographicsgrowth.overview.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';
$canEnter = hasPermission('churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.create')
    || hasPermission('churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.update');

$pageTitle = 'Demographics & Growth';
$pageIcon = 'ri-line-chart-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Demographics & Growth' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Demographics & Growth - Makueni West Diocese</title>
    <meta name="Description" content="Church demographics overview and submission history" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const CAN_ENTER_DEMOGRAPHICS = <?= $canEnter ? 'true' : 'false' ?>;
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

                <!-- Segmented control -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="btn-group" role="group" aria-label="View toggle">
                        <button type="button" class="btn btn-primary active" id="segmentOverviewBtn">
                            <i class="ri-dashboard-line me-1"></i>Overview
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="segmentHistoryBtn">
                            <i class="ri-history-line me-1"></i>History
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <select class="form-select form-select-sm" style="max-width: 140px;" id="reportFiscalYear">
                            <option value="">Loading years...</option>
                        </select>
                        <?php if ($canEnter): ?>
                        <a href="<?= SITE_URL ?>/church/demographics-growth/demographics-tracking.php" class="btn btn-primary btn-wave">
                            <i class="ri-edit-line me-1"></i>Update This Month's Data
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- OVERVIEW segment -->
                <div id="segmentOverview">
                    <div class="row g-3 mb-3" id="statCardsRow">
                        <!-- Stat cards injected by index.js -->
                    </div>

                    <div class="card custom-card mb-3">
                        <div class="card-header">
                            <div class="card-title"><i class="ri-line-chart-line me-2 text-primary"></i>Membership Growth</div>
                        </div>
                        <div class="card-body">
                            <div id="growthChartLegend"></div>
                            <div id="growthTrendChart"></div>
                            <div class="border-top pt-3 mt-3" id="growthStatColumns"></div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-xl-6">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div class="card-title"><i class="ri-pie-chart-line me-2 text-primary"></i>Gender Split</div>
                                </div>
                                <div class="card-body">
                                    <div id="genderDonutChart"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div class="card-title"><i class="ri-shield-check-line me-2 text-primary"></i>Compliance Status</div>
                                </div>
                                <div class="card-body" id="complianceCard">
                                    <div class="text-center py-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HISTORY segment -->
                <div id="segmentHistory" style="display: none;">
                    <div class="card custom-card">
                        <div class="card-header">
                            <div class="card-title"><i class="ri-file-list-3-line me-2 text-primary"></i>Submission History</div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="fw-semibold text-dark">Period</th>
                                            <th class="fw-semibold text-dark">Total Members</th>
                                            <th class="fw-semibold text-dark">Status</th>
                                            <th class="fw-semibold text-dark text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="historyTableBody">
                                        <!-- Rows injected by index.js -->
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

    <div class="modal fade" id="demographicDetailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="ri-file-list-3-line me-2"></i>Submission Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="demographicDetailModalBody"></div>
            </div>
        </div>
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

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/index.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.DemographicsOverview.init());
    </script>
</body>

</html>
