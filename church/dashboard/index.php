<?php
// 1. FIRST: Start session & check auth (NO HTML BEFORE THIS!)
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

// 2. Check specific permission for Dashboard Overview
requirePermission('church.churchdashboard.dashboardoverview.read');

// 3. Get user data
$user = getAuthUser();
$currentRole = getCurrentRole();

/*
 * The church's home (docs/specs/church-home-spec.md): the YNEX Ecommerce
 * dashboard's own cards, kept in place, each filled with one side of the
 * church - money, people, attendance, care, what's coming - by
 * assets/js/pages/home/church.js. Each card asks its own module's API, so a
 * role that can't see a module gets a calm line in that card, not an error.
 */
$can = fn (string $permission) => hasGlobalAccess() || hasPermission($permission);
$church = SITE_URL . '/church';
$homeCtx = [
    'siteUrl' => SITE_URL,
    'church' => $church,
    'place' => ['id' => (int) ($currentRole['territory_id'] ?? 0), 'name' => $currentRole['territory']['name'] ?? $currentRole['territory_name'] ?? ''],
    // Staff pay shows as one line under the money chart - only for a role that sees Staff.
    'staff' => $can('church.hr.staff.read') || $can('church.hr.dashboard.read') || $can('church.hr.staff.manage') || $can('church.hr.setup.manage'),
    // Who may do each "Get your church started" step (the rest come from each module's own answer).
    'can' => [
        'attendance' => $can('attendancemanagement.serviceattendance.create'),
        'events' => $can('church.events.events.manage'),
        'settings' => $can('church.settings.hub.overview.read'),
    ],
];
// Things to do from here - each only for a role that can do it.
$quick = array_filter([
    $can('church.accounting.receipts.create') ? ['Write a receipt', 'ri-bill-line', 'success', "{$church}/accounting/receipts.php"] : null,
    $can('attendancemanagement.serviceattendance.create') ? ['Record attendance', 'ri-calendar-check-line', 'primary', "{$church}/attendance/new.php"] : null,
    $can('church.members.members.manage') ? ['Add member', 'ri-user-add-line', 'purple', "{$church}/members/new.php"] : null,
    $can('church.visitors.visitors.manage') ? ['Record visitors', 'ri-user-heart-line', 'pink', "{$church}/visitors/new.php"] : null,
    $can('church.reports.monthly.write') ? ['Write our report', 'ri-file-text-line', 'warning', "{$church}/monthly-reports/report.php"] : null,
]);

$pageTitle = 'Dashboard';
$pageIcon = 'ri-home-4-line';
$breadcrumbs = ['Home' => SITE_URL . '/church/dashboard', ($homeCtx['place']['name'] ?: 'Our church') => null];
$skel = '<span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width: 60%"></span>';
$v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Dashboard - Makueni West Diocese</title>
    <meta name="Description" content="Our church at a glance - money, people, attendance, care and what's coming" />

    <!-- Favicon -->
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <!-- Main Theme Js -->
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= $v('assets/css/styles.min.css') ?>" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.HOME_CTX = <?= json_encode($homeCtx) ?>;
        // AccountingUI's method logos and money format read these two.
        window.ACC_CTX = { siteUrl: HOME_CTX.siteUrl, baseUrl: HOME_CTX.church + "/accounting", place: HOME_CTX.place, can: {} };
        const USER_TERRITORY = <?= json_encode($homeCtx['place']) ?>;
    </script>
    <?php include __DIR__ . '/../../includes/start-switcher.php' ?>
    <?php include __DIR__ . '/../../includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../../includes/header.php' ?>
        <?php include __DIR__ . '/../../includes/sidebar.php' ?>

        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid home-dash">
                <?php include __DIR__ . '/../../includes/page-header.php' ?>

                <?php if ($quick) { ?>
                <!-- Quick actions (added - the template has none) -->
                <div class="d-flex flex-wrap gap-2 mb-4 home-quick">
                    <?php foreach ($quick as [$label, $icon, $color, $href]) { ?>
                    <a href="<?= $href ?>" class="btn btn-light btn-wave home-quick-btn"><span class="avatar avatar-xs avatar-rounded bg-<?= $color ?> <?= $color === 'warning' ? 'text-dark' : 'text-white' ?> me-2"><i class="<?= $icon ?>"></i></span><?= $label ?></a>
                    <?php } ?>
                </div>
                <?php } ?>

                <!-- Get your church started (added): only while something isn't set up yet -->
                <div class="card custom-card home-start" id="startCard" hidden>
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div class="card-title d-flex align-items-center gap-2"><span class="avatar avatar-sm avatar-rounded bg-primary text-white"><i class="ri-rocket-line"></i></span>Get your church started</div>
                        <span class="badge bg-success text-white" id="startCount"></span>
                    </div>
                    <div class="card-body">
                        <div class="progress progress-sm mb-3"><div class="progress-bar bg-success" id="startBar" style="width: 0%"></div></div>
                        <div class="row g-3" id="startSteps"></div>
                    </div>
                </div>

                <!-- Start::row-1 -->
                <div class="row">
                    <div class="col-xxl-6 col-xl-12">
                        <div class="row">
                            <?php
                            // The 4 KPI cards - the template's icon tile, label, figure and "Increased by x% this month" line.
                            $kpis = [
                                ['kpiIn', 'Given this month', 'primary', 'ri-hand-heart-line'],
                                ['kpiOut', 'Spent this month', 'secondary', 'ri-wallet-3-line'],
                                ['kpiSunday', 'Last Sunday', 'success', 'ri-group-line'],
                                ['kpiMembers', 'Members', 'warning', 'ri-contacts-book-2-line'],
                            ];
                            foreach ($kpis as [$id, $label, $color, $icon]) { ?>
                            <div class="col-lg-6 col-sm-6 col-md-6 col-xl-6 d-flex">
                                <div class="card custom-card flex-fill" id="<?= $id ?>">
                                    <div class="card-body">
                                        <div class="row">
                                            <div
                                                class="col-xxl-3 col-xl-2 col-lg-3 col-md-3 col-sm-4 col-4 d-flex align-items-center justify-content-center ecommerce-icon <?= $color ?> px-0">
                                                <span class="rounded p-3 bg-<?= $color ?> <?= $color === 'secondary' || $color === 'warning' ? 'text-dark' : 'text-white' ?> home-kpi-tile">
                                                    <i class="<?= $icon ?>"></i>
                                                </span>
                                            </div>
                                            <div class="col-xxl-9 col-xl-10 col-lg-9 col-md-9 col-sm-8 col-8 px-0">
                                                <div class="mb-2"><?= $label ?></div>
                                                <div class="text-muted mb-1 fs-12">
                                                    <span class="text-dark fw-semibold fs-20 lh-1 vertical-bottom" data-kpi="value">
                                                        <span class="skel skel-line" style="width: 7rem"></span>
                                                    </span>
                                                </div>
                                                <div data-kpi="delta">
                                                    <span class="fs-12 mb-0"><span class="skel skel-line-sm" style="width: 9rem"></span></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php } ?>
                            <div class="col-xxl-6 col-xl-6 col-lg-6 col-md-6 d-flex">
                                <!-- Next big day: the template's sale card -->
                                <div class="card custom-card overflow-hidden flex-fill" id="nextCard">
                                    <div class="ecommerce-sale-image">
                                        <img src="<?= SITE_URL ?>/assets/images/ecommerce/png/35.png"
                                            class="card-img-top" alt="" />
                                        <div class="card-img-overlay d-flex flex-column p-4 text-fixed-white">
                                            <p class="mb-1 op-7 fs-12" id="nextKind">Coming up</p>
                                            <h6 class="fw-semibold mb-4 text-fixed-white" id="nextTitle">
                                                <span class="skel skel-line" style="width: 10rem"></span>
                                            </h6>
                                            <p class="ecommerce-bankoffer-details" id="nextDetails">Loading what's next…</p>
                                            <p class="ecommerce-sale-days mb-0" id="nextDays"></p>
                                            <p class="ecommerce-sales-calendar text-center mb-0" id="nextBadge" hidden>
                                                <span data-day></span>
                                                <span class="d-block fs-15 lh-1 fw-semibold" data-month></span>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <a href="<?= $church ?>/events/" class="text-primary fs-15 fw-semibold" id="nextLink">Events and initiatives</a>
                                        <p class="mb-4 mt-1" id="nextText"><?= $skel ?></p>
                                        <a href="<?= $church ?>/events/" class="btn btn-primary btn-wave me-2" id="nextOpen">
                                            Open
                                        </a>
                                        <a href="<?= $church ?>/calendar/" class="btn btn-outline-primary btn-wave">
                                            Calendar
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xxl-6 col-xl-6 col-lg-6 col-md-6 d-flex">
                                <!-- Latest money: the template's Recent Orders -->
                                <div class="card custom-card flex-fill">
                                    <div class="card-header justify-content-between">
                                        <div class="card-title">Latest money</div>
                                        <div class="dropdown">
                                            <a aria-label="More" href="javascript:void(0);"
                                                class="btn btn-icon btn-sm btn-light" data-bs-toggle="dropdown">
                                                <i class="fe fe-more-vertical"></i>
                                            </a>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="<?= $church ?>/accounting/documents.php">All documents</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/accounting/receipts.php">Receipts</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/accounting/collections.php">Sunday collections</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-unstyled mb-0" id="latestList">
                                            <li><?= $skel ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-12">
                                <!-- Needs attention: the template's Orders tabs -->
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between d-sm-flex d-block">
                                        <div class="card-title">Needs attention</div>
                                        <div>
                                            <ul class="nav nav-tabs justify-content-end nav-tabs-header mb-0 d-sm-flex d-block mt-sm-0 mt-2"
                                                role="tablist">
                                                <li class="nav-item">
                                                    <a class="nav-link active" data-bs-toggle="tab" role="tab"
                                                        href="#attn-approvals" aria-selected="true">Waiting for me <span class="home-tab-count" id="countApprovals"></span></a>
                                                </li>
                                                <li class="nav-item">
                                                    <a class="nav-link" data-bs-toggle="tab" role="tab"
                                                        href="#attn-visitors" aria-selected="false">Visitors to follow up <span class="home-tab-count" id="countVisitors"></span></a>
                                                </li>
                                                <li class="nav-item">
                                                    <a class="nav-link" data-bs-toggle="tab" role="tab"
                                                        href="#attn-care" aria-selected="false">Care <span class="home-tab-count" id="countCare"></span></a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="tab-content">
                                            <?php foreach (['approvals' => true, 'visitors' => false, 'care' => false] as $pane => $active) { ?>
                                            <div class="tab-pane <?= $active ? 'show active ' : '' ?>border-0 p-0" id="attn-<?= $pane ?>"
                                                role="tabpanel">
                                                <div class="table-responsive">
                                                    <table class="table card-table table-vcenter text-nowrap mb-0">
                                                        <tbody class="active-tab" id="<?= $pane ?>Rows">
                                                            <tr><td class="p-3"><?= $skel ?></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-12">
                                <!-- Recent activity (added): what happened lately, as My Profile's timeline -->
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between">
                                        <div class="card-title">Recent activity</div>
                                        <span class="badge bg-primary text-white" id="activityCount" hidden></span>
                                    </div>
                                    <div class="card-body home-activity" id="activityList"><?= $skel ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-6 col-xl-12">
                        <div class="row">
                            <div class="col-xl-12">
                                <!-- Money this year: the template's Earnings -->
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between">
                                        <div class="card-title">Money this year</div>
                                        <div class="dropdown">
                                            <a href="javascript:void(0);" class="p-2 fs-12 text-muted"
                                                data-bs-toggle="dropdown" aria-expanded="false">
                                                View All<i
                                                    class="ri-arrow-down-s-line align-middle ms-1 d-inline-block"></i>
                                            </a>
                                            <ul class="dropdown-menu" role="menu">
                                                <li><a class="dropdown-item" href="<?= $church ?>/accounting/">Accounting overview</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/accounting/statements.php">Statements</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/accounting/reports.php">Reports</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="row ps-lg-5 mb-4 pb-4 gy-sm-0 gy-3" id="moneyStats">
                                            <?php foreach (['first-half' => 'In this year', 'top-gross' => 'Out this year', 'second-half' => 'Held today'] as $dot => $label) { ?>
                                            <div class="col-xl-4 col-lg-4 col-md-4 col-sm-4">
                                                <div class="mb-1 earning <?= $dot ?> ms-3"><?= $label ?></div>
                                                <div class="mb-0" data-stat><span class="skel skel-line" style="width: 6rem"></span></div>
                                            </div>
                                            <?php } ?>
                                        </div>
                                        <div id="earnings"></div>
                                        <p class="home-staff-line mb-0" id="staffLine" hidden></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-12">
                                <!-- Budget this year: the template's Top Selling Products -->
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between">
                                        <div class="card-title">Budget this year</div>
                                        <a href="<?= $church ?>/budget/overview.php" class="fs-12 text-primary fw-semibold" id="budgetLink">Budgets<i class="ri-arrow-right-line ms-1"></i></a>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table text-nowrap mb-0">
                                                <thead>
                                                    <tr>
                                                        <th scope="col" class="text-center">#</th>
                                                        <th scope="col">Line</th>
                                                        <th scope="col">Side</th>
                                                        <th scope="col">Status</th>
                                                        <th scope="col">Used of planned</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="top-selling" id="budgetRows">
                                                    <tr><td colspan="5" class="p-3"><?= $skel ?></td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6 d-flex">
                                <!-- Who comes: the template's Top Countries By Sales -->
                                <div class="card custom-card flex-fill">
                                    <div class="card-header justify-content-between">
                                        <div class="card-title">Who comes</div>
                                        <div class="dropdown">
                                            <a aria-label="More" href="javascript:void(0);"
                                                class="btn btn-icon btn-sm btn-light" data-bs-toggle="dropdown">
                                                <i class="fe fe-more-vertical"></i>
                                            </a>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="<?= $church ?>/attendance/index.php">Attendance overview</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/attendance/analytics.php">Attendance analytics</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/attendance/new.php">Record attendance</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-3" id="whoHead">
                                            <span class="skel skel-line" style="width: 8rem"></span>
                                        </div>
                                        <ul class="mb-0 pt-3 list-unstyled" id="whoList"></ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6 d-flex">
                                <!-- This week: the template's Top Customers -->
                                <div class="card custom-card flex-fill">
                                    <div class="card-header justify-content-between">
                                        <div class="card-title">This week</div>
                                        <div class="dropdown">
                                            <a href="javascript:void(0);" class="p-2 fs-12 text-muted"
                                                data-bs-toggle="dropdown">
                                                View All<i
                                                    class="ri-arrow-down-s-line align-middle ms-1 d-inline-block"></i>
                                            </a>
                                            <ul class="dropdown-menu" role="menu">
                                                <li><a class="dropdown-item" href="<?= $church ?>/calendar/">Calendar</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/events/">Events</a></li>
                                                <li><a class="dropdown-item" href="<?= $church ?>/facilities/bookings.php">Bookings</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-unstyled mb-0" id="weekList">
                                            <li><?= $skel ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!--End::row-1 -->

                <!-- Start:: row-2 -->
                <div class="row">
                    <div class="col-xl-12">
                        <!-- Monthly reports: the template's Products Overview -->
                        <div class="card custom-card">
                            <div class="card-header justify-content-between">
                                <div class="card-title">Monthly reports this year</div>
                                <div class="d-sm-flex">
                                    <div class="me-3 mb-3 mb-sm-0">
                                        <input class="form-control form-control-sm" type="search" placeholder="Search"
                                            aria-label="Search the months" id="reportSearch" />
                                    </div>
                                    <div class="dropdown">
                                        <a href="javascript:void(0);"
                                            class="btn btn-primary btn-sm btn-wave waves-effect waves-light"
                                            data-bs-toggle="dropdown" aria-expanded="false">
                                            Sort By<i class="ri-arrow-down-s-line align-middle ms-1 d-inline-block"></i>
                                        </a>
                                        <ul class="dropdown-menu" role="menu" id="reportSort">
                                            <li><a class="dropdown-item" href="javascript:void(0);" data-sort="new">Newest first</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0);" data-sort="old">Oldest first</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0);" data-sort="status">Needs doing first</a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="home-report-strip" id="reportSummary"></div>
                                <div class="table-responsive">
                                    <table class="table text-nowrap table-hover home-report-table mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col">Month</th>
                                                <th scope="col">Due</th>
                                                <th scope="col">Status</th>
                                                <th scope="col">Sent</th>
                                                <th scope="col">Comments</th>
                                                <th scope="col"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="reportRows">
                                            <tr><td colspan="6"><?= $skel ?></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex align-items-center">
                                    <div id="reportShowing">
                                        Showing 0 Entries
                                        <i class="bi bi-arrow-right ms-2 fw-semibold"></i>
                                    </div>
                                    <div class="ms-auto">
                                        <nav aria-label="Page navigation" class="pagination-style-4">
                                            <ul class="pagination mb-0" id="reportPages"></ul>
                                        </nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End:: row-2 -->
            </div>
        </div>
        <!-- End::app-content -->

        <?php include __DIR__ . '/../../includes/footer.php' ?>
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>
    <!-- Scroll To Top -->

    <script src="<?= SITE_URL ?>/assets/libs/%40popperjs/core/umd/popper.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>
    <script src="<?= $v('assets/js/utils/auth-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <script src="<?= $v('assets/js/utils/toast.js') ?>"></script>
    <script src="<?= $v('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= $v('assets/js/pages/accounting/ui.js') ?>"></script>
    <script src="<?= $v('assets/js/pages/home/church.js') ?>"></script>
</body>

</html>
