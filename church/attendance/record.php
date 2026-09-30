<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.overview.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

// Who can edit / record, per kind of gathering (the API checks the same).
$canWrite = [
    'sunday_service' => hasPermission('attendancemanagement.serviceattendance.update') || hasPermission('attendancemanagement.serviceattendance.create'),
    'ministry_gathering' => hasPermission('attendancemanagement.ministryattendance.update') || hasPermission('attendancemanagement.ministryattendance.create'),
    'special_event' => hasPermission('attendancemanagement.specialeventsattendance.update') || hasPermission('attendancemanagement.specialeventsattendance.create'),
];

$canDelete = [
    'sunday_service' => hasPermission('attendancemanagement.serviceattendance.delete'),
    'ministry_gathering' => hasPermission('attendancemanagement.ministryattendance.delete'),
    'special_event' => hasPermission('attendancemanagement.specialeventsattendance.delete'),
];

$pageTitle = 'Attendance Record';
$pageIcon = 'ri-file-list-3-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
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
    <title>Attendance Record - Makueni West Diocese</title>
    <meta name="Description" content="One recorded Sunday or meeting - who came and how it compares" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/select2/select2.min.css" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const CAN_WRITE_ATTENDANCE = <?= json_encode($canWrite) ?>;
        const CAN_DELETE_ATTENDANCE = <?= json_encode($canDelete) ?>;
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

                <!-- What this is, and where to go from it -->
                <div class="record-head">
                    <div class="record-head-main">
                        <span id="recordTitleIcon"><span class="skel" style="width: 2.4rem; height: 2.4rem;"></span></span>
                        <div style="min-width: 0;">
                            <h4 class="record-title" id="recordTitle"><span class="skel skel-title" style="width: 16rem;"></span></h4>
                            <div class="record-sub" id="recordSub"></div>
                        </div>
                    </div>
                    <div class="record-actions" id="recordActions">
                        <a href="<?= SITE_URL ?>/church/attendance" class="btn btn-light" id="recordBack"><i class="ri-arrow-left-line me-1"></i>Attendance</a>
                        <button type="button" class="btn btn-outline-danger" id="recordDelete" hidden><i class="ri-delete-bin-line me-1"></i>Delete</button>
                        <button type="button" class="btn btn-primary" id="recordEdit" hidden><i class="ri-edit-line me-1"></i>Edit</button>
                    </div>
                </div>

                <div id="recordBody">
                    <div class="record-nav" id="recordNav"></div>

                    <div class="row">
                        <div class="col-xl-7">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">Who came</div>
                                        <span class="card-subtitle-text">Adults, youth, boys and girls</span>
                                    </div>
                                </div>
                                <div class="card-body" id="recordCounts"><span class="skel" style="height: 10rem; display: block;"></span></div>
                            </div>
                        </div>
                        <div class="col-xl-5">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">How it compares</div>
                                        <span class="card-subtitle-text">With the same gathering before it</span>
                                    </div>
                                </div>
                                <div class="card-body" id="recordCompare"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-7">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">The last few</div>
                                        <span class="card-subtitle-text">This one in solid colour - tap a bar to open it</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div id="recordRecent"></div>
                                </div>
                            </div>
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div class="card-title">Notes</div>
                                </div>
                                <div class="card-body" id="recordNotes"></div>
                            </div>
                        </div>
                        <div class="col-xl-5">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">History</div>
                                        <span class="card-subtitle-text">Who recorded it, and every change since</span>
                                    </div>
                                </div>
                                <div class="card-body" id="recordHistory"></div>
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
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js<?= assetVersion('assets/js/utils/toast.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/libs/select2/select2.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-form-shared.js<?= assetVersion('assets/js/pages/demographics/attendance-form-shared.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-record.js<?= assetVersion('assets/js/pages/demographics/attendance-record.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceRecord.init());
    </script>
</body>

</html>
