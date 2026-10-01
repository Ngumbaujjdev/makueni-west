<?php
// Budget Overview - shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
$pageTitle = 'Budget Overview';
$pageIcon = 'ri-dashboard-3-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    'Overview' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budget Overview - Makueni West Diocese</title>
    <meta name="Description" content="Planned against what came in and went out, for a month or a year" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php budgetPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>window.BUDGET_CTX = <?= json_encode($budgetCtx) ?>;</script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../page-header.php' ?>

                <div class="page-toolbar">
                    <div class="page-toolbar-sub" id="placeLine">&nbsp;</div>
                    <div class="page-toolbar-controls">
                        <div id="yearSwitchWrap"></div>
                        <div class="budget-year-select"><select id="monthSelect" aria-label="Month"></select></div>
                        <button type="button" class="btn btn-outline-primary d-none" id="exportReportBtn" data-lock="1" data-module="budget" data-report-key="budget.summary"><i class="ri-download-2-line me-1"></i>Export</button>
                        <a href="#" class="btn btn-outline-primary d-none" id="openBudgetBtn"><i class="ri-wallet-3-line me-1"></i>Open budget</a>
                        <div class="btn-group d-none" id="recordGroup">
                            <button type="button" class="btn btn-primary" id="recordOutBtn"><i class="ri-add-line me-1"></i>Record money</button>
                            <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Money in or out"></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="javascript:void(0);" data-record="in"><i class="ri-arrow-down-circle-line text-success me-2"></i>Money in</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" data-record="out"><i class="ri-arrow-up-circle-line text-danger me-2"></i>Money out</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="viewOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="viewOnlyText"></span></div>
                </div>
                <div id="budgetAlert"></div>

                <!-- The verdict: how this month (or year) is going -->
                <div class="card custom-card budget-hero" id="heroCard" hidden>
                    <div class="card-body">
                        <div class="budget-hero-main">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="heroTop"></div>
                            <h3 class="budget-hero-title" id="heroTitle"></h3>
                            <div id="heroBars"></div>
                        </div>
                        <div class="budget-hero-side">
                            <div class="budget-hero-side-text">
                                <div class="kpi-label">Money left</div>
                                <div class="budget-hero-left" id="heroLeft">-</div>
                                <div class="kpi-caption" id="heroLeftSub"></div>
                                <div class="d-flex flex-wrap gap-1 mt-2" id="heroKeys"></div>
                            </div>
                            <div class="budget-hero-ring" id="heroRing"></div>
                        </div>
                    </div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="row">
                    <div class="col-xl-8">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title" id="trendTitle">How the money moved</div>
                                    <span class="card-subtitle-text" id="trendSub">&nbsp;</span>
                                </div>
                                <div class="d-flex flex-wrap gap-1" id="trendChips"></div>
                            </div>
                            <div class="card-body" id="trendBody"><span class="skel" style="height: 300px; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">What we noticed</div>
                                    <span class="card-subtitle-text">From the plan and the money recorded</span>
                                </div>
                            </div>
                            <div class="card-body" id="insights"><span class="skel" style="height: 300px; display: block;"></span></div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-7">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Plan vs actual</div>
                                    <span class="card-subtitle-text" id="pvaSub">The biggest lines: planned, and what really happened</span>
                                </div>
                                <div id="pvaSwitchWrap"></div>
                            </div>
                            <div class="card-body" id="pvaBody"><span class="skel" style="height: 300px; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Money in by source</div>
                                    <span class="card-subtitle-text" id="sourceSub">Where the money received came from</span>
                                </div>
                            </div>
                            <div class="card-body" id="sourceDonut"><span class="skel" style="height: 300px; display: block;"></span></div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-7">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Where the money is going</div>
                                    <span class="card-subtitle-text" id="linesSub">Each line: planned, and spent so far</span>
                                </div>
                                <div id="sideSwitchWrap"></div>
                            </div>
                            <div class="card-body" id="lineProgress"><span class="skel" style="height: 16rem; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Recent money</div>
                                    <span class="card-subtitle-text">The latest money in and out</span>
                                </div>
                                <a href="<?= $budgetCtx['baseUrl'] ?>/spending.php" class="btn btn-sm btn-outline-primary" id="seeAllLink">See all<i class="ri-arrow-right-line ms-1"></i></a>
                            </div>
                            <div class="card-body" id="recentList"><span class="skel" style="height: 16rem; display: block;"></span></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts(['assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/budgets/overview.js']) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsOverview.init());</script>
</body>

</html>
