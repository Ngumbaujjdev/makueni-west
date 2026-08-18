<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.overview.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';
$canEnter = hasPermission('attendancemanagement.serviceattendance.create')
    || hasPermission('attendancemanagement.ministryattendance.create')
    || hasPermission('attendancemanagement.specialeventsattendance.create');

$pageTitle = 'Attendance';
$pageIcon = 'ri-calendar-check-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Attendance - Makueni West Diocese</title>
    <meta name="Description" content="Church attendance overview and entry settings" />

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
        const CAN_ENTER_ATTENDANCE = <?= $canEnter ? 'true' : 'false' ?>;
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

                <div class="row g-3 mb-3" id="statCardsRow">
                    <!-- Stat cards injected by attendance-index.js -->
                </div>

                <div class="row g-3">
                    <div class="col-xl-4">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title"><i class="ri-settings-3-line me-2 text-primary"></i>Entry Mode</div>
                            </div>
                            <div class="card-body" id="entryModeCard">
                                <div class="text-center py-3">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-8">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title"><i class="ri-links-line me-2 text-primary"></i>Quick Links</div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <a href="<?= SITE_URL ?>/church/attendance/services" class="btn btn-outline-primary w-100 py-3">
                                            <i class="ri-calendar-2-line fs-20 d-block mb-1"></i>Sunday Services
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="<?= SITE_URL ?>/church/attendance/ministries" class="btn btn-outline-primary w-100 py-3">
                                            <i class="ri-group-line fs-20 d-block mb-1"></i>Ministry Gatherings
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="<?= SITE_URL ?>/church/attendance/events" class="btn btn-outline-primary w-100 py-3">
                                            <i class="ri-star-line fs-20 d-block mb-1"></i>Special Events
                                        </a>
                                    </div>
                                    <div class="col-md-12">
                                        <a href="<?= SITE_URL ?>/church/attendance/reports" class="btn btn-light w-100">
                                            <i class="ri-bar-chart-line me-1"></i>View Attendance Reports
                                        </a>
                                    </div>
                                </div>
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

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-index.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceOverview.init());
    </script>
</body>

</html>
