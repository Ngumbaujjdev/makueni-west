<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.attendancereports.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Attendance Reports';
$pageIcon = 'ri-bar-chart-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Attendance Reports' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Attendance Reports - Makueni West Diocese</title>
    <meta name="Description" content="Attendance trends and gathering-type breakdown for this church" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css" />
    <link href="<?= SITE_URL ?>/assets/libs/fullcalendar/main.min.css" rel="stylesheet" />

    <!-- Sunday-highlight tint isn't reachable with Bootstrap utility classes,
         same justified inline-style exception church/attendance/services.php uses. -->
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

                <!-- Compact clock + period filter strip -->
                <div class="row g-3 mb-3">
                    <div class="col-auto">
                        <div class="card custom-card p-3 mb-0">
                            <div class="fs-11 fw-semibold text-body text-uppercase mb-2">Current Time</div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar avatar-lg bg-primary-transparent">
                                    <i class="ri-time-line fs-24 text-primary"></i>
                                </span>
                                <div>
                                    <span class="d-block fw-semibold fs-18" id="reportClockTime">--:--:--</span>
                                    <span class="d-block fs-13 text-body" id="reportClockWeekday">-</span>
                                    <span class="d-block fs-13 text-body" id="reportClockDate">-</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="card custom-card dropdown p-2 mb-0">
                            <div class="fs-11 fw-semibold text-body text-uppercase mb-1 ps-1">Selected Period</div>
                            <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" id="periodPickerBtn"
                                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                <i class="ri-calendar-2-line me-1"></i><span id="periodPickerLabel">-</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-3" id="periodPickerMenu" style="min-width: 300px;">
                                <div class="mb-2 fw-semibold fs-12 text-body text-uppercase">Quick Select</div>
                                <div class="d-flex flex-wrap gap-1 mb-3">
                                    <button type="button" class="btn btn-light btn-sm" data-quick="this_month">This Month</button>
                                    <button type="button" class="btn btn-light btn-sm" data-quick="last_month">Last Month</button>
                                    <button type="button" class="btn btn-light btn-sm" data-quick="this_year">This Year</button>
                                    <button type="button" class="btn btn-light btn-sm" data-quick="last_year">Last Year</button>
                                    <button type="button" class="btn btn-light btn-sm" data-quick="all_time">All Time</button>
                                </div>
                                <div class="mb-2 fw-semibold fs-12 text-body text-uppercase">Custom</div>
                                <div class="d-flex gap-2 mb-3">
                                    <select class="form-select form-select-sm" id="reportFiscalYear">
                                        <option value="">Loading years...</option>
                                    </select>
                                    <select class="form-select form-select-sm" id="reportFiscalMonth">
                                        <option value="">All months</option>
                                    </select>
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-light btn-sm" id="periodClearBtn">Clear</button>
                                    <button type="button" class="btn btn-primary btn-sm" id="periodApplyBtn">Apply</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cross-tab summary strip -->
                <div class="row g-3 mb-3" id="summaryCardsRow">
                    <!-- Combined-category summary cards injected by attendance-reports.js -->
                </div>

                <!-- Tab bar -->
                <ul class="nav nav-tabs nav-tabs-pill mb-3" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-sunday-btn" data-bs-toggle="tab" data-bs-target="#tab-sunday" type="button" role="tab">
                            <i class="ri-sun-line me-1"></i>Sunday Service
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-ministry-btn" data-bs-toggle="tab" data-bs-target="#tab-ministry" type="button" role="tab">
                            <i class="ri-group-2-line me-1"></i>Ministry Gatherings
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-events-btn" data-bs-toggle="tab" data-bs-target="#tab-events" type="button" role="tab">
                            <i class="ri-calendar-event-line me-1"></i>Special Events
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- TAB 1: Sunday Service -->
                    <div class="tab-pane fade show active" id="tab-sunday" role="tabpanel">
                        <div class="row g-3 mb-3" id="sundayCardsRow"></div>

                        <div id="sundayInsights"></div>

                        <div class="row g-3 mb-3">
                            <div class="col-xl-3">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-pie-chart-line me-2 text-primary"></i>Coverage</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="sundayCoverageGauge"></div>
                                        <div class="d-flex justify-content-center" id="sundayCoverageLegend"></div>
                                        <p class="fs-12 text-body text-center mt-2 mb-0" id="sundayCoverageCaption"></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-line-chart-line me-2 text-primary"></i>Attendance Trend</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="sundayChartLegend"></div>
                                        <div id="sundayTrendChart"></div>
                                        <div class="border-top pt-3 mt-3" id="sundayStatColumns"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-history-line me-2 text-primary"></i>Recent Sundays</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="sundayRecentList"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title"><i class="ri-calendar-2-line me-2 text-primary"></i>Sunday Calendar</div>
                            </div>
                            <div class="card-body">
                                <div id="sundayCalendar"></div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: Ministry Gatherings -->
                    <div class="tab-pane fade" id="tab-ministry" role="tabpanel">
                        <div class="row g-3 mb-3" id="ministryCardsRow"></div>

                        <div id="ministryInsights"></div>

                        <div class="row g-3 mb-3">
                            <div class="col-xl-3">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-donut-chart-line me-2 text-primary"></i>Attendance Share</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="ministryDonutChart"></div>
                                        <div class="d-flex justify-content-center" id="ministryDonutLegend"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-bar-chart-grouped-line me-2 text-primary"></i>Total Attendance by Type</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="ministryChartLegend"></div>
                                        <div id="ministryComparisonChart"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-history-line me-2 text-primary"></i>Recent Gatherings</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="ministryRecentList"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card custom-card mb-3">
                            <div class="card-header">
                                <div class="card-title"><i class="ri-trophy-line me-2 text-primary"></i>Top Gathering Types</div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="fw-semibold text-dark" style="width: 3rem;">#</th>
                                                <th class="fw-semibold text-dark">Gathering Type</th>
                                                <th class="fw-semibold text-dark text-end">Times Held</th>
                                                <th class="fw-semibold text-dark text-end">Total Attendance</th>
                                                <th class="fw-semibold text-dark text-end">Average Attendance</th>
                                                <th class="fw-semibold text-dark text-end">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="ministryBreakdownTableBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap">
                                <div class="card-title"><i class="ri-file-list-3-line me-2 text-primary"></i>Ministry Records</div>
                                <div class="dropdown" id="ministrySortDropdown">
                                    <a href="javascript:void(0);" class="btn btn-primary btn-sm btn-wave" data-bs-toggle="dropdown" aria-expanded="false">
                                        Sort By<i class="ri-arrow-down-s-line align-middle ms-1 d-inline-block"></i>
                                    </a>
                                    <ul class="dropdown-menu dropdown-menu-end" role="menu">
                                        <li><a class="dropdown-item" href="javascript:void(0);" data-sort="date-desc">Newest First</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0);" data-sort="date-asc">Oldest First</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0);" data-sort="total-desc">Highest Attendance</a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="px-3 pt-3" id="ministryFilterToolbar"></div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="ministryRecordsTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="fw-semibold text-dark">Date</th>
                                                <th class="fw-semibold text-dark">Gathering Type</th>
                                                <th class="fw-semibold text-dark">Total Attendance</th>
                                                <th class="fw-semibold text-dark">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="ministryRecordsTableBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: Special Events -->
                    <div class="tab-pane fade" id="tab-events" role="tabpanel">
                        <div class="row g-3 mb-3" id="eventsCardsRow"></div>

                        <div id="eventsInsights"></div>

                        <div class="row g-3 mb-3">
                            <div class="col-xl-8">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-bar-chart-grouped-line me-2 text-primary"></i>Times Held vs. Average Attendance</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="eventsChartLegend"></div>
                                        <div id="eventsComboChart"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4">
                                <div class="card custom-card h-100">
                                    <div class="card-header">
                                        <div class="card-title"><i class="ri-history-line me-2 text-primary"></i>Recent Events</div>
                                    </div>
                                    <div class="card-body">
                                        <div id="eventsRecentList"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card custom-card mb-3">
                            <div class="card-header">
                                <div class="card-title"><i class="ri-trophy-line me-2 text-primary"></i>Top Event Types</div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="fw-semibold text-dark" style="width: 3rem;">#</th>
                                                <th class="fw-semibold text-dark">Event Type</th>
                                                <th class="fw-semibold text-dark text-end">Times Held</th>
                                                <th class="fw-semibold text-dark text-end">Total Attendance</th>
                                                <th class="fw-semibold text-dark text-end">Average Attendance</th>
                                                <th class="fw-semibold text-dark text-end">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="eventsBreakdownTableBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap">
                                <div class="card-title"><i class="ri-file-list-3-line me-2 text-primary"></i>Event Records</div>
                                <div class="dropdown" id="eventsSortDropdown">
                                    <a href="javascript:void(0);" class="btn btn-primary btn-sm btn-wave" data-bs-toggle="dropdown" aria-expanded="false">
                                        Sort By<i class="ri-arrow-down-s-line align-middle ms-1 d-inline-block"></i>
                                    </a>
                                    <ul class="dropdown-menu dropdown-menu-end" role="menu">
                                        <li><a class="dropdown-item" href="javascript:void(0);" data-sort="date-desc">Newest First</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0);" data-sort="date-asc">Oldest First</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0);" data-sort="total-desc">Highest Attendance</a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="px-3 pt-3" id="eventsFilterToolbar"></div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="eventsRecordsTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="fw-semibold text-dark">Date</th>
                                                <th class="fw-semibold text-dark">Event</th>
                                                <th class="fw-semibold text-dark">Total Attendance</th>
                                                <th class="fw-semibold text-dark">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="eventsRecordsTableBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../includes/footer.php' ?>
    </div>

    <!-- Read-only Sunday detail modal - Reports never creates/edits records, just shows what's already there -->
    <div class="modal fade" id="sundayDetailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="ri-calendar-check-line me-2"></i>Sunday Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="sundayDetailModalBody"></div>
            </div>
        </div>
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
    <script src="<?= SITE_URL ?>/assets/libs/fullcalendar/main.min.js"></script>

    <!-- jQuery + DataTables (search/filter/pagination) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/jquery.dataTables.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-reports.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceReports.init());
    </script>
</body>

</html>
