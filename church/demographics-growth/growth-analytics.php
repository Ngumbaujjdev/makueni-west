<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('churchdemographicsgrowth.growthanalytics.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Growth Analytics';
$pageIcon = 'ri-bar-chart-grouped-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Demographics & Growth' => SITE_URL . '/church/demographics-growth',
    'Growth Analytics' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Growth Analytics - Makueni West Diocese</title>
    <meta name="Description" content="How this church's membership has grown over the years" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

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

                <!-- Hero split: one dominant Tier-1 metric (left, with its own range
                     control in the header) plus secondary Tier-2 driver metrics (right) -
                     an inverted-pyramid hierarchy instead of N equal-weight cards, so the
                     single most important fact reads first. -->
                <div class="row g-3 mb-3">
                    <div class="col-xl-8">
                        <div class="card custom-card h-100">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div class="card-title">Total Members Now</div>
                                <div class="d-flex flex-wrap gap-2" id="rangeQuickSelect">
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-range="3">Last 3 Years</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-range="5">Last 5 Years</button>
                                    <button type="button" class="btn btn-primary btn-sm active" data-range="all">All Time</button>
                                </div>
                            </div>
                            <div class="card-body" id="heroCard">
                                <!-- Hero number + trend + insight sentence injected by growth-analytics.js -->
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="card custom-card h-100">
                            <div class="card-header">
                                <div class="card-title">Growth Drivers</div>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-unstyled mb-0" id="driverStats">
                                    <!-- Driver rows injected by growth-analytics.js -->
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3" id="segmentBreakdownRow">
                    <!-- Latest-value cards for the selected segment, injected by growth-analytics.js - empty while "Total Members" is selected -->
                </div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div class="card-title"><i class="ri-line-chart-line me-2 text-primary"></i>Total Members Over Time</div>
                        <div class="d-flex flex-wrap gap-2" id="chartSegmentSelect">
                            <button type="button" class="btn btn-primary btn-sm active" data-segment="total">Total Members</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-segment="gender">Gender</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-segment="sunday_school">Sunday School</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-segment="sunday_school_teachers">SS Teachers</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-segment="youth">Youth</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-segment="fellowship">Fellowship</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-segment="seniors">Seniors</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="growthChart" style="min-height: 380px;"></div>
                    </div>
                </div>

                <!-- Comparative: every tracked category, fiscal years as columns - same range-button-filtered data the chart above already uses -->
                <div class="card custom-card">
                    <div class="card-header">
                        <div class="card-title"><i class="ri-table-line me-2 text-primary"></i>Year-by-Year Comparison</div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr id="comparisonTableHead">
                                        <th class="fw-semibold text-dark">Category</th>
                                    </tr>
                                </thead>
                                <tbody id="comparisonTableBody">
                                    <!-- Rows injected by growth-analytics.js -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Heatmap: same categories/years as the comparison table above, as color intensity instead of raw numbers - which categories are growing/shrinking at a glance -->
                <div class="card custom-card">
                    <div class="card-header">
                        <div class="card-title"><i class="ri-grid-line me-2 text-primary"></i>Growth Heatmap</div>
                    </div>
                    <div class="card-body">
                        <div id="growthHeatmap" style="min-height: 380px;"></div>
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

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/growth-analytics.js<?= assetVersion('assets/js/pages/demographics/growth-analytics.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.GrowthAnalytics.init());
    </script>
</body>

</html>
