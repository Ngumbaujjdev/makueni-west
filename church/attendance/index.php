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
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Attendance - Makueni West Diocese</title>
    <meta name="Description" content="Sunday services, ministries and events at a glance" />

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

                <div class="page-toolbar">
                    <div class="page-toolbar-sub">Sunday services, ministries and events at <?= htmlspecialchars($userTerritoryName) ?></div>
                    <div class="page-toolbar-controls">
                        <a href="<?= SITE_URL ?>/church/attendance/analytics" class="btn btn-outline-primary"><i class="ri-bar-chart-box-line me-1"></i>Analytics</a>
                        <?php if (canExportAttendanceReports()): ?>
                        <button type="button" class="btn btn-outline-primary" id="exportReportBtn" data-lock="1" data-module="attendance" data-report-key="attendance.summary"><i class="ri-download-2-line me-1"></i>Export</button>
                        <?php endif ?>
                        <?php if ($canEnter): ?>
                        <button type="button" class="btn btn-primary" id="recordSundayBtn"><i class="ri-add-line me-1"></i>Record Sunday</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="row">
                    <div class="col-xl-8">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Sunday attendance</div>
                                    <span class="card-subtitle-text">The last 12 Sundays recorded, by group</span>
                                </div>
                                <div class="d-flex flex-wrap gap-1" id="trendChips"></div>
                            </div>
                            <div class="card-body">
                                <div id="sundayTrendChart"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Who attends</div>
                                    <span class="card-subtitle-text" id="whoAttendsSubtitle">Average Sunday</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div id="whoAttendsDonut"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-7">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between">
                                <div>
                                    <div class="card-title">Ministries &amp; events</div>
                                    <span class="card-subtitle-text" id="glanceSub">Most met first</span>
                                </div>
                                <a href="<?= SITE_URL ?>/church/attendance/ministries" class="fs-12 fw-semibold">All ministries <i class="ri-arrow-right-line ms-1"></i></a>
                            </div>
                            <div class="card-body">
                                <div class="att-glance" id="glanceList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title" id="monthSundaysTitle">This month's Sundays</div>
                                    <span class="card-subtitle-text" id="monthSundaysSub"></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="period-grid att-sunday-grid" id="monthSundays"></div>
                            </div>
                        </div>
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">How we record</div>
                                    <span class="card-subtitle-text">Choose what suits this church</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div id="entryModeSwitch"></div>
                                <p class="fs-13 mt-2 mb-0" id="entryModeText"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card custom-card">
                    <div class="card-body">
                        <div class="att-shortcuts">
                            <a href="<?= SITE_URL ?>/church/attendance/services" class="att-shortcut"><span class="avatar bg-primary text-white"><i class="ri-sun-line"></i></span><span><strong>Sunday services</strong><small>Every Sunday, recorded or not</small></span></a>
                            <a href="<?= SITE_URL ?>/church/attendance/ministries" class="att-shortcut"><span class="avatar bg-success text-white"><i class="ri-group-line"></i></span><span><strong>Ministry gatherings</strong><small>Fellowships, prayer, choir</small></span></a>
                            <a href="<?= SITE_URL ?>/church/attendance/events" class="att-shortcut"><span class="avatar bg-purple text-white"><i class="ri-star-line"></i></span><span><strong>Special events</strong><small>Crusades, baptisms, dedications</small></span></a>
                            <a href="<?= SITE_URL ?>/church/attendance/analytics" class="att-shortcut"><span class="avatar bg-secondary text-dark"><i class="ri-bar-chart-box-line"></i></span><span><strong>Analytics</strong><small>Trends, coverage, insights</small></span></a>
                            <a href="<?= SITE_URL ?>/church/settings/attendance-settings/gathering-types" class="att-shortcut"><span class="avatar bg-pink text-white"><i class="ri-list-settings-line"></i></span><span><strong>Gathering types</strong><small>Your ministries and events</small></span></a>
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
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-index.js<?= assetVersion('assets/js/pages/demographics/attendance-index.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceOverview.init());
    </script>
</body>

</html>
