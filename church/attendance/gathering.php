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

$pageTitle = 'Ministry & Event';
$pageIcon = 'ri-group-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Ministry & Event' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Ministry &amp; Event - Makueni West Diocese</title>
    <meta name="Description" content="One ministry or event over time - how often it meets and who comes" />

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

                <!-- Which ministry or event, and what to do with it -->
                <div class="record-head">
                    <div class="record-head-main">
                        <span id="gatheringIcon"><span class="skel" style="width: 2.4rem; height: 2.4rem;"></span></span>
                        <div style="min-width: 0;">
                            <h4 class="record-title" id="gatheringTitle"><span class="skel skel-title" style="width: 14rem;"></span></h4>
                            <div class="record-sub" id="gatheringSub"></div>
                        </div>
                    </div>
                    <div class="record-actions">
                        <a href="<?= SITE_URL ?>/church/attendance/ministries" class="btn btn-light" id="gatheringBack"><i class="ri-arrow-left-line me-1"></i>Ministry Gatherings</a>
                        <?php if (canExportAttendanceReports()): ?>
                        <button type="button" class="btn btn-outline-primary" id="exportReportBtn" data-lock="1" data-module="attendance" data-report-key="attendance.ministries" hidden><i class="ri-download-2-line me-1"></i>Export</button>
                        <?php endif ?>
                        <button type="button" class="btn btn-primary" id="gatheringRecord" hidden><i class="ri-add-line me-1"></i>Record a meeting</button>
                    </div>
                </div>

                <div class="att-filter-strip">
                    <span class="att-filter-label"><i class="ri-filter-3-line"></i>Showing</span>
                    <div class="att-period-select">
                        <select id="periodYear" aria-label="Year"></select>
                    </div>
                    <div class="att-period-select" id="periodMonthWrap">
                        <select id="periodMonth" aria-label="Month"></select>
                    </div>
                    <div class="att-period-select att-range" id="periodRangeWrap" hidden>
                        <select id="periodFrom" aria-label="From"></select>
                        <span class="att-range-to">to</span>
                        <select id="periodTo" aria-label="To"></select>
                    </div>
                    <span class="soft-chip soft-primary att-filter-period"><i class="ri-calendar-line me-1"></i><b id="periodLabel">-</b></span>
                </div>

                <div id="gatheringBody" class="att-analytics">
                    <div class="row" id="statCardsRow"></div>

                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">Every meeting</div>
                                        <span class="card-subtitle-text">Who came each time, by group</span>
                                    </div>
                                </div>
                                <div class="card-body"><div id="meetingsChart"></div></div>
                            </div>
                        </div>
                        <div class="col-xl-4">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">Who attends</div>
                                        <span class="card-subtitle-text">An average meeting</span>
                                    </div>
                                </div>
                                <div class="card-body"><div id="whoDonut"></div></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-7">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">Meetings by month</div>
                                        <span class="card-subtitle-text" id="monthlySub">How often it met each month</span>
                                    </div>
                                </div>
                                <div class="card-body"><div id="monthlyChart"></div></div>
                            </div>
                        </div>
                        <div class="col-xl-5">
                            <div class="card custom-card">
                                <div class="card-header">
                                    <div>
                                        <div class="card-title">What we noticed</div>
                                        <span class="card-subtitle-text">And what to do about it</span>
                                    </div>
                                </div>
                                <div class="card-body"><div id="gatheringInsights"></div></div>
                            </div>
                        </div>
                    </div>

                    <div class="card custom-card">
                        <div class="card-header">
                            <div>
                                <div class="card-title">Meetings</div>
                                <span class="card-subtitle-text" id="meetingsSub"></span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="d-none d-md-table-cell">Adults</th>
                                            <th class="d-none d-md-table-cell">Youth</th>
                                            <th class="d-none d-md-table-cell">Children</th>
                                            <th>Total</th>
                                            <th class="d-none d-lg-table-cell">Notes</th>
                                            <th class="text-end">Open</th>
                                        </tr>
                                    </thead>
                                    <tbody id="meetingsBody"></tbody>
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
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js<?= assetVersion('assets/js/utils/toast.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/libs/select2/select2.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-form-shared.js<?= assetVersion('assets/js/pages/demographics/attendance-form-shared.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-gathering.js<?= assetVersion('assets/js/pages/demographics/attendance-gathering.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceGathering.init());
    </script>
</body>

</html>
