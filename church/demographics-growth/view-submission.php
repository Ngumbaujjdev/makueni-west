<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('churchdemographicsgrowth.overview.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$demographicId = isset($_GET['id']) ? (int) $_GET['id'] : null;

if (!$demographicId) {
    header('Location: ' . SITE_URL . '/church/demographics-growth/demographics-tracking.php');
    exit;
}

$pageTitle = 'View Submission';
$pageIcon = 'ri-file-chart-2-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Demographics & Growth' => SITE_URL . '/church/demographics-growth',
    'Demographics Tracking' => SITE_URL . '/church/demographics-growth/demographics-tracking.php',
    'View Submission' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>View Submission - Makueni West Diocese</title>
    <meta name="Description" content="Full breakdown of one demographics submission" />

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
        const DEMOGRAPHIC_ID = <?= json_encode($demographicId) ?>;
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

                <!-- Header card: period, status, submitted/reviewed info, Edit/Back actions -->
                <div class="card custom-card mb-3">
                    <div class="card-body" id="submissionHeaderCard">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Headline stats -->
                <div class="row g-3 mb-3" id="statCardsRow"></div>

                <!-- Charts -->
                <div class="row g-3 mb-3">
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
                                <div class="card-title"><i class="ri-bar-chart-2-line me-2 text-primary"></i>Membership Composition</div>
                            </div>
                            <div class="card-body">
                                <div id="compositionChart"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Changes & Spiritual Activities -->
                <div class="card custom-card mb-3">
                    <div class="card-header">
                        <div class="card-title"><i class="ri-hand-heart-line me-2 text-primary"></i>Changes & Spiritual Activities</div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 row-cols-1 row-cols-md-3 row-cols-xl-5" id="activityStatsRow"></div>
                    </div>
                </div>

                <!-- Leadership & Ministry Team -->
                <div class="card custom-card mb-3">
                    <div class="card-header">
                        <div class="card-title"><i class="ri-shield-user-line me-2 text-primary"></i>Leadership & Ministry Team</div>
                    </div>
                    <div class="card-body" id="leadershipCard">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
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

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/view-submission.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.DemographicsViewSubmission.init());
    </script>
</body>

</html>
