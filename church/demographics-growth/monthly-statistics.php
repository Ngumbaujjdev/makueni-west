<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('churchdemographicsgrowth.monthlystatistics.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Monthly Statistics';
$pageIcon = 'ri-table-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Demographics & Growth' => SITE_URL . '/church/demographics-growth',
    'Monthly Statistics' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Monthly Statistics - Makueni West Diocese</title>
    <meta name="Description" content="Month-by-month demographics submissions for this church" />

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

                <!-- Fiscal year filter -->
                <div class="d-flex justify-content-end mb-3">
                    <select class="form-select form-select-sm" style="max-width: 160px;" id="reportFiscalYear">
                        <option value="">Loading years...</option>
                    </select>
                </div>

                <div class="row g-3 mb-3" id="statCardsRow"></div>

                <div class="card custom-card">
                    <div class="card-header">
                        <div class="card-title"><i class="ri-table-line me-2 text-primary"></i>Monthly Breakdown</div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="fw-semibold text-dark">Month</th>
                                        <th class="fw-semibold text-dark">Status</th>
                                        <th class="fw-semibold text-dark text-end">Total Members</th>
                                        <th class="fw-semibold text-dark text-end">Male</th>
                                        <th class="fw-semibold text-dark text-end">Female</th>
                                        <th class="fw-semibold text-dark text-end">Youth</th>
                                        <th class="fw-semibold text-dark text-end">Men's Fellowship</th>
                                        <th class="fw-semibold text-dark text-end">Women's Fellowship</th>
                                        <th class="fw-semibold text-dark text-end">Sunday School (M)</th>
                                        <th class="fw-semibold text-dark text-end">Sunday School (F)</th>
                                        <th class="fw-semibold text-dark text-end">Seniors</th>
                                        <th class="fw-semibold text-dark text-end">New Members</th>
                                        <th class="fw-semibold text-dark text-end">Departed</th>
                                        <th class="fw-semibold text-dark text-end">Baptisms</th>
                                        <th class="fw-semibold text-dark text-end">Communion</th>
                                        <th class="fw-semibold text-dark text-end">Conversions</th>
                                    </tr>
                                </thead>
                                <tbody id="monthlyStatsBody">
                                    <!-- Rows injected by monthly-statistics.js -->
                                </tbody>
                            </table>
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

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/monthly-statistics.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.MonthlyStatistics.init());
    </script>
</body>

</html>
