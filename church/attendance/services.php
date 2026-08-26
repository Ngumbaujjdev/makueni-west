<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.overview.read');

$canWrite = hasPermission('attendancemanagement.serviceattendance.create')
    || hasPermission('attendancemanagement.serviceattendance.update');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Sunday Service Attendance';
$pageIcon = 'ri-calendar-2-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Sunday Service Attendance' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Sunday Service Attendance - Makueni West Diocese</title>
    <meta name="Description" content="Click a Sunday to record that week's service attendance" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= asset_url('/assets/js/main.js') ?>"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= asset_url('/assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= asset_url('/assets/css/icons.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/styles/choices.min.css" />
    <link href="<?= SITE_URL ?>/assets/libs/fullcalendar/main.min.css" rel="stylesheet" />

    <!-- Sunday-highlight UX: FullCalendar's day-cell background isn't
         reachable with Bootstrap utility classes, so this one rule is a
         justified exception to the "reach for Bootstrap first" rule. -->
    <style>
        .fc-sunday-highlight {
            background-color: rgba(44, 164, 191, 0.08);
        }
    </style>

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const CAN_WRITE_ATTENDANCE = <?= $canWrite ? 'true' : 'false' ?>;
    </script>
</head>

<body>
    <script src="<?= asset_url('/assets/js/config/app.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/config/constants.js') ?>"></script>
    <?php include __DIR__ . '/../../includes/start-switcher.php' ?>
    <?php include __DIR__ . '/../../includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../../includes/header.php' ?>
        <?php include __DIR__ . '/../../includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../../includes/page-header.php' ?>

                <div class="row g-3 mb-3" id="statCardsRow">
                    <!-- Stat cards injected by attendance-services.js -->
                </div>

                <div id="entryModeBanner"></div>

                <div class="row g-3">
                    <div class="col-xl-8">
                        <div class="card custom-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div class="card-title"><i class="ri-calendar-2-line me-2 text-primary"></i>Click a Sunday to Record Attendance</div>
                                <?php if ($canWrite): ?>
                                <button type="button" class="btn btn-primary btn-wave" id="addAttendanceBtn">
                                    <i class="ri-add-line me-1"></i>Add Attendance
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div id="attendanceCalendar"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title"><i class="ri-list-check-2 me-2 text-primary"></i>Recent Sundays</div>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled profile-timeline mb-0" id="recentSundaysTimeline">
                                    <!-- Entries injected by attendance-services.js -->
                                </ul>
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
    <script src="<?= asset_url('/assets/js/defaultmenu.min.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= asset_url('/assets/js/sticky.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= asset_url('/assets/js/simplebar.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/custom-switcher.min.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/custom.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/utils/toast.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/fullcalendar/main.min.js"></script>

    <script src="<?= asset_url('/assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/scripts/choices.min.js"></script>
    <script src="<?= asset_url('/assets/js/pages/demographics/attendance-form-shared.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/pages/demographics/attendance-services.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceServices.init());
    </script>
</body>

</html>
