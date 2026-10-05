<?php
// Income & Expenses - shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php),
// and $spendingDir ('in' = the Income page, 'out' = the Expenses page, or unset for both).
$spendingDir = $spendingDir ?? 'all';
$pageTitle = ['in' => 'Income', 'out' => 'Expenses'][$spendingDir] ?? 'Income & Expenses';
$pageIcon = 'ri-exchange-dollar-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    $pageTitle => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="Every amount received and spent, against the budget" />
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
    <script>window.BUDGET_CTX = <?= json_encode($budgetCtx) ?>; window.SPENDING_DIR = <?= json_encode($spendingDir) ?>;</script>
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
                        <div id="dirSwitchWrap"></div>
                        <div id="yearSwitchWrap"></div>
                        <div class="budget-year-select"><select id="monthSelect" aria-label="Month"></select></div>
                        <button type="button" class="btn btn-outline-primary d-none" id="exportReportBtn" data-lock="1" data-module="budget" data-report-key="budget.spending"><i class="ri-download-2-line me-1"></i>Export</button>
                        <div class="btn-group d-none" id="recordGroup">
                            <button type="button" class="btn btn-primary" id="recordOutBtn"><i class="ri-add-line me-1"></i>Record money</button>
                            <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Income or expense"></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="javascript:void(0);" data-record="in"><i class="ri-arrow-down-circle-line text-success me-2"></i>Income</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" data-record="out"><i class="ri-arrow-up-circle-line text-danger me-2"></i>Expenses</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="viewOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="viewOnlyText"></span></div>
                </div>
                <div id="recordHint"></div>

                <div class="row" id="statCardsRow"></div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title" id="listTitle">Income & Expenses</div>
                            <span class="card-subtitle-text" id="listSub">Every amount received and spent - tap one to see it</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="filterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="entriesTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>What for</th>
                                        <th>Line</th>
                                        <th class="d-none d-md-table-cell">How</th>
                                        <th class="text-end">Amount</th>
                                        <th class="d-none d-lg-table-cell">Recorded by</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="entriesTableBody"></tbody>
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

    <?php budgetPageScripts(['assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/budgets/spending.js'], true) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsSpending.init());</script>
</body>

</html>
