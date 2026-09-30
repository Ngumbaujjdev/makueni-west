<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.attendanceanalytics.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Attendance Analytics';
$pageIcon = 'ri-bar-chart-box-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Analytics' => null,
];

/** A card with a title, subtitle and a body - keeps the tab markup below readable. */
function analyticsCard(string $title, string $subtitle, string $body, string $headerExtra = '', string $bodyClass = 'card-body'): string
{
    $sub = $subtitle ? '<span class="card-subtitle-text">' . $subtitle . '</span>' : '';
    return <<<HTML
    <div class="card custom-card">
        <div class="card-header justify-content-between flex-wrap gap-2">
            <div><div class="card-title">{$title}</div>{$sub}</div>
            {$headerExtra}
        </div>
        <div class="{$bodyClass}">{$body}</div>
    </div>
    HTML;
}

function gatheringsPane(string $key, string $noun, string $plural): string
{
    $Noun = ucfirst($noun);
    $share = analyticsCard('Share of attendance', "Everyone who came to {$plural}, by {$noun}", "<div id=\"{$key}Share\"></div>");
    $bars = analyticsCard('Average per meeting', "How many come each time a {$noun} meets", "<div id=\"{$key}Bars\"></div>");
    $table = analyticsCard(ucfirst($plural), "Every {$noun} set up, with when it last met", <<<HTML
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>{$Noun}</th>
                        <th>Met</th>
                        <th>Average</th>
                        <th class="d-none d-md-table-cell">Most</th>
                        <th class="d-none d-md-table-cell">Last met</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="{$key}Body"></tbody>
            </table>
        </div>
    HTML, '', 'card-body p-0');
    $insights = analyticsCard('What we noticed', 'And what to do about it', "<div id=\"{$key}Insights\"></div>");

    return <<<HTML
    <div class="row">
        <div class="col-xl-4">{$share}</div>
        <div class="col-xl-8">{$bars}</div>
    </div>
    <div class="row">
        <div class="col-xl-8">{$table}</div>
        <div class="col-xl-4">{$insights}</div>
    </div>
    HTML;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Attendance Analytics - Makueni West Diocese</title>
    <meta name="Description" content="Sunday trends, coverage, ministries, children and insights" />

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
                    <div class="page-toolbar-sub">How attendance at <?= htmlspecialchars($userTerritoryName) ?> is going · <strong id="periodLabel">-</strong></div>
                    <div class="page-toolbar-controls">
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
                        <?php if (canExportAttendanceReports()): ?>
                        <button type="button" class="btn btn-outline-primary" id="exportReportBtn" data-lock="1" data-module="attendance" data-report-key="attendance.sunday"><i class="ri-download-2-line me-1"></i>Export</button>
                        <?php endif ?>
                    </div>
                </div>

                <div id="analyticsBody" class="att-analytics">
                    <div class="row" id="summaryCardsRow"></div>

                    <div class="nav section-tabs" id="analyticsTabs" role="tablist" aria-label="Attendance">
                        <button class="nav-link section-tab active" id="tab-sunday-btn" data-bs-toggle="tab" data-bs-target="#tab-sunday" type="button" role="tab" aria-controls="tab-sunday" aria-selected="true">
                            <span class="section-tab-icon bg-primary"><i class="ri-sun-line"></i></span>
                            <span class="section-tab-text"><strong>Sunday service</strong><small data-tab-figure="sunday">&nbsp;</small></span>
                        </button>
                        <button class="nav-link section-tab" id="tab-ministries-btn" data-bs-toggle="tab" data-bs-target="#tab-ministries" type="button" role="tab" aria-controls="tab-ministries" aria-selected="false">
                            <span class="section-tab-icon bg-success"><i class="ri-group-line"></i></span>
                            <span class="section-tab-text"><strong>Ministries</strong><small data-tab-figure="ministries">&nbsp;</small></span>
                        </button>
                        <button class="nav-link section-tab" id="tab-events-btn" data-bs-toggle="tab" data-bs-target="#tab-events" type="button" role="tab" aria-controls="tab-events" aria-selected="false">
                            <span class="section-tab-icon bg-purple"><i class="ri-star-line"></i></span>
                            <span class="section-tab-text"><strong>Special events</strong><small data-tab-figure="events">&nbsp;</small></span>
                        </button>
                        <button class="nav-link section-tab" id="tab-children-btn" data-bs-toggle="tab" data-bs-target="#tab-children" type="button" role="tab" aria-controls="tab-children" aria-selected="false">
                            <span class="section-tab-icon bg-pink"><i class="ri-parent-line"></i></span>
                            <span class="section-tab-text"><strong>Children</strong><small data-tab-figure="children">&nbsp;</small></span>
                        </button>
                    </div>

                    <div class="tab-content section-tab-content">
                        <!-- Sunday service -->
                        <div class="tab-pane fade show active" id="tab-sunday" role="tabpanel">
                            <div class="row">
                                <div class="col-xl-8"><?= analyticsCard('Every Sunday', 'Who came each week, by group', '<div id="weeklyChart"></div>', '<div class="d-flex flex-wrap gap-1" id="sundayTopChips"></div>') ?></div>
                                <div class="col-xl-4"><?= analyticsCard('Sundays recorded', 'Sundays with a count, of those that have happened', '<div id="coverageRing"></div><div id="missingSundays"></div>') ?></div>
                            </div>
                            <div class="row">
                                <div class="col-xl-7"><?= analyticsCard('Sundays at a glance', 'Each square is a Sunday - darker means more people, red means not recorded', '<div id="heatmapChart"></div>') ?></div>
                                <div class="col-xl-5"><?= analyticsCard('Who attends', 'An average Sunday', '<div id="whoDonut"></div>') ?></div>
                            </div>
                            <div class="row">
                                <div class="col-xl-7"><?= analyticsCard('Month by month', 'Sundays recorded, the average and the best', '
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead><tr><th>Month</th><th>Sundays</th><th>Average</th><th>Best</th><th class="d-none d-md-table-cell">Children</th></tr></thead>
                                            <tbody id="monthsBody"></tbody>
                                        </table>
                                    </div>', '', 'card-body p-0') ?></div>
                                <div class="col-xl-5"><?= analyticsCard('What we noticed', 'And what to do about it', '<div id="sundayInsights"></div>') ?></div>
                            </div>
                        </div>

                        <!-- Ministries -->
                        <div class="tab-pane fade" id="tab-ministries" role="tabpanel">
                            <?= gatheringsPane('ministries', 'ministry', 'ministries') ?>
                        </div>

                        <!-- Special events -->
                        <div class="tab-pane fade" id="tab-events" role="tabpanel">
                            <?= gatheringsPane('events', 'event', 'events') ?>
                        </div>

                        <!-- Children -->
                        <div class="tab-pane fade" id="tab-children" role="tabpanel">
                            <div class="row">
                                <div class="col-xl-8"><?= analyticsCard('Boys and girls each Sunday', 'Children counted at each Sunday service', '<div id="childrenWeekly"></div>', '<div class="d-flex flex-wrap gap-1" id="childrenChips"></div>') ?></div>
                                <div class="col-xl-4"><?= analyticsCard('Boys and girls', 'An average Sunday', '<div id="childrenDonut"></div>') ?></div>
                            </div>
                            <div class="row">
                                <div class="col-xl-7"><?= analyticsCard("Children's share of Sunday", 'Children as a share of everyone at an average Sunday, by month', '<div id="childrenShare"></div>') ?></div>
                                <div class="col-xl-5"><?= analyticsCard('What we noticed', 'And what to do about it', '<div id="childrenInsights"></div>') ?></div>
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
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-analytics.js<?= assetVersion('assets/js/pages/demographics/attendance-analytics.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceAnalytics.init());
    </script>
</body>

</html>
