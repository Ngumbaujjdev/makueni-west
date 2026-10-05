<?php
// Record Attendance - the menu's way in to recording: pick what you're
// recording (Sunday service, ministry gathering or special event), then the
// usual entry window asks when. record.php is the page for one saved record.
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.overview.read');

$currentRole = getCurrentRole();
$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

// Who can record each kind - the same check record.php and the Overview use (the API checks it too).
$canRecord = [
    'sunday_service' => hasPermission('attendancemanagement.serviceattendance.create') || hasPermission('attendancemanagement.serviceattendance.update'),
    'ministry_gathering' => hasPermission('attendancemanagement.ministryattendance.create') || hasPermission('attendancemanagement.ministryattendance.update'),
    'special_event' => hasPermission('attendancemanagement.specialeventsattendance.create') || hasPermission('attendancemanagement.specialeventsattendance.update'),
];

$pageTitle = 'Record Attendance';
$pageIcon = 'ri-edit-box-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance/',
    'Record' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Record Attendance - Makueni West Diocese</title>
    <meta name="Description" content="Record a Sunday service, ministry gathering or special event" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/select2/select2.min.css" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <script>
        const USER_TERRITORY = { id: <?= json_encode($userTerritoryId) ?>, name: <?= json_encode($userTerritoryName) ?> };
        const CAN_RECORD = <?= json_encode($canRecord) ?>;
        const CAN_DELETE_ATTENDANCE = <?= hasPermission('attendancemanagement.serviceattendance.delete') ? 'true' : 'false' ?>;
        const RECORD_URL = <?= json_encode(SITE_URL . '/church/attendance/record') ?>;
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

                <div class="page-toolbar">
                    <div class="page-toolbar-sub">Record attendance at <?= htmlspecialchars($userTerritoryName) ?></div>
                    <div class="page-toolbar-controls">
                        <a href="<?= SITE_URL ?>/church/attendance/" class="btn btn-outline-primary"><i class="ri-calendar-check-line me-1"></i>Attendance overview</a>
                    </div>
                </div>

                <div class="card custom-card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">What are you recording?</div>
                            <div class="fs-12">Pick one - the next window asks for the date and how many came</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3" id="recordChoices">
                            <?php foreach (range(1, 3) as $i): ?>
                                <div class="col-lg-4 placeholder-glow"><span class="placeholder col-12 rounded" style="height: 9rem;"></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between">
                        <div>
                            <div class="card-title">Recently recorded</div>
                            <div class="fs-12">The last few records - open one to see or change it</div>
                        </div>
                        <a href="<?= SITE_URL ?>/church/attendance/services" class="btn btn-sm btn-outline-primary">All Sundays</a>
                    </div>
                    <div class="card-body" id="recentList">
                        <?php foreach (range(1, 4) as $i): ?>
                            <div class="placeholder-glow mb-2"><span class="placeholder col-12 rounded" style="height: 2.6rem;"></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../includes/footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
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
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js<?= assetVersion('assets/js/utils/toast.js') ?>"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/libs/select2/select2.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-form-shared.js<?= assetVersion('assets/js/pages/demographics/attendance-form-shared.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-new.js<?= assetVersion('assets/js/pages/demographics/attendance-new.js') ?>"></script>
    <script>document.addEventListener('DOMContentLoaded', () => window.AttendanceNew.init());</script>
</body>

</html>
