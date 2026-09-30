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

$pageTitle = 'Sunday Services';
$pageIcon = 'ri-sun-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Sunday Services' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Sunday Services - Makueni West Diocese</title>
    <meta name="Description" content="Every Sunday's attendance - recorded, missed and upcoming" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/select2/select2.min.css" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />
    <link href="<?= SITE_URL ?>/assets/libs/fullcalendar/main.min.css" rel="stylesheet" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const CAN_WRITE_ATTENDANCE = <?= $canWrite ? 'true' : 'false' ?>;
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
                    <div class="page-toolbar-sub">Every Sunday at <?= htmlspecialchars($userTerritoryName) ?> - recorded, missed and coming up</div>
                    <div class="page-toolbar-controls">
                        <div class="seg-control" id="viewSwitch" role="tablist" aria-label="View">
                            <button type="button" class="seg-btn active" data-value="list"><i class="ri-list-check-2 me-1"></i>List</button>
                            <button type="button" class="seg-btn" data-value="calendar"><i class="ri-calendar-2-line me-1"></i>Calendar</button>
                        </div>
                        <?php if ($canWrite): ?>
                        <button type="button" class="btn btn-primary" id="recordNextBtn"><i class="ri-add-line me-1"></i>Record Sunday</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="entryModeBanner"></div>

                <div class="row" id="statCardsRow"></div>

                <div class="row">
                    <div class="col-xl-8">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Sundays</div>
                                    <span class="card-subtitle-text" id="sundayListSubtitle">Loading...</span>
                                </div>
                                <div class="att-year-select">
                                    <select id="sundayYear" aria-label="Year"></select>
                                </div>
                            </div>
                            <div class="card-body p-0" id="listView">
                                <div id="sundayFilterToolbar" class="list-filterbar-wrap"></div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="sundayTable">
                                        <thead>
                                            <tr>
                                                <th>Sunday</th>
                                                <th>Status</th>
                                                <th class="d-none d-md-table-cell">Adults</th>
                                                <th class="d-none d-md-table-cell">Youth</th>
                                                <th class="d-none d-md-table-cell">Children</th>
                                                <th>Total</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="sundayTableBody"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-body" id="calendarView" hidden>
                                <div id="attendanceCalendar" class="att-calendar"></div>
                                <div class="period-legend">
                                    <span><span class="count-dot bg-primary"></span>Recorded</span>
                                    <span><span class="count-dot bg-danger"></span>Sunday not recorded</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Last 12 Sundays</div>
                                    <span class="card-subtitle-text">Who came, week by week</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div id="sundayTrendChart"></div>
                            </div>
                        </div>
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
    <script src="<?= SITE_URL ?>/assets/libs/fullcalendar/main.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/libs/select2/select2.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/jquery.dataTables.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-form-shared.js<?= assetVersion('assets/js/pages/demographics/attendance-form-shared.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-services.js<?= assetVersion('assets/js/pages/demographics/attendance-services.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceServices.init());
    </script>
</body>

</html>
