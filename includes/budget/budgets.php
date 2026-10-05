<?php
// Budgets list - shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
$pageTitle = 'Budgets';
$pageIcon = 'ri-wallet-3-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budgets - Makueni West Diocese</title>
    <meta name="Description" content="Plan a month or a year, and see how the money is going" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php budgetPageStyles(true) ?>
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
                    <div class="page-toolbar-sub" id="placeLine">Budgets for <?= htmlspecialchars($budgetCtx['place']['name'] ?: 'your ' . $budgetCtx['level']) ?></div>
                    <div class="page-toolbar-controls">
                        <div id="yearSwitchWrap"></div>
                        <?php if ($budgetCtx['can']['prepare']): ?>
                        <button type="button" class="btn btn-outline-primary d-none" id="exportReportBtn" data-lock="1" data-module="budget" data-report-key="budget.summary"><i class="ri-download-2-line me-1"></i>Export</button>
                        <a href="<?= $budgetCtx['baseUrl'] ?>/form.php" class="btn btn-primary" id="newBudgetBtn"><i class="ri-add-line me-1"></i>New budget</a>
                        <?php endif ?>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="viewOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="viewOnlyText"></span></div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="row budget-equal-row">
                    <div class="col-xl-6">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title" id="flowTitle">Income & Expenses, month by month</div>
                                    <span class="card-subtitle-text" id="flowSub">What each month's budget plans to receive and spend</span>
                                </div>
                                <div class="d-flex flex-wrap gap-1" id="flowChips"></div>
                            </div>
                            <div class="card-body" id="flowBody"><span class="skel" style="height: 300px; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Where the money goes</div>
                                    <span class="card-subtitle-text" id="whereSub">The biggest expense lines this year</span>
                                </div>
                                <div id="whereSwitchWrap"></div>
                            </div>
                            <div class="card-body" id="whereDonut"><span class="skel" style="height: 300px; display: block;"></span></div>
                        </div>
                    </div>
                </div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title" id="glanceTitle">The year at a glance</div>
                            <span class="card-subtitle-text">Each month and its budget - tap one to open it</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1" id="glanceChips"></div>
                    </div>
                    <div class="card-body" id="yearGrid"><span class="skel" style="height: 10rem; display: block;"></span></div>
                </div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title" id="listTitle">Budgets</div>
                            <span class="card-subtitle-text">Search, filter or sort every budget of the year</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="filterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="budgetsTable">
                                <thead>
                                    <tr>
                                        <th>Budget</th>
                                        <th>Status</th>
                                        <th class="text-end">Income</th>
                                        <th class="text-end">Expenses</th>
                                        <th class="text-end">Money left</th>
                                        <th class="d-none d-lg-table-cell">Prepared by</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="budgetsTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts('assets/js/pages/budgets/list.js', true) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsList.init());</script>
</body>

</html>
