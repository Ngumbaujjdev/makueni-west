<?php
// One line of a budget - shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
// Filled in by assets/js/pages/budgets/line.js from GET /budgets/{budget}/lines/{line}.
$pageTitle = 'Budget line';
$pageIcon = 'ri-list-check-2';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    'Line' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budget line - Makueni West Diocese</title>
    <meta name="Description" content="One line of a budget: planned against what came in or went out, every amount, and when" />
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

                <div class="budget-line-head">
                    <div class="d-flex align-items-center gap-3" style="min-width: 0;">
                        <span id="lineIcon"><span class="avatar avatar-lg bg-light"></span></span>
                        <div style="min-width: 0;">
                            <div class="budget-entry-hero-kicker" id="lineKicker">&nbsp;</div>
                            <h3 class="budget-entry-hero-title" id="lineTitle">Loading…</h3>
                            <div class="fs-13 mt-1" id="lineSub">&nbsp;</div>
                        </div>
                    </div>
                    <div class="page-toolbar-controls">
                        <a href="<?= $budgetCtx['baseUrl'] ?>/budgets.php" class="btn btn-light" id="backBtn"><i class="ri-arrow-left-line me-1"></i>Back to the budget</a>
                        <button type="button" class="btn btn-primary" id="recordLineBtn" hidden><i class="ri-add-line me-1"></i>Record money on this line</button>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="viewOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="viewOnlyText"></span></div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="row budget-equal-row">
                    <div class="col-xl-8">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title" id="chartTitle">When the money moved</div>
                                    <span class="card-subtitle-text" id="chartSub">&nbsp;</span>
                                </div>
                                <div class="d-flex flex-wrap gap-1" id="chartChips"></div>
                            </div>
                            <div class="card-body" id="lineChartBody"><span class="skel" style="height: 300px; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">This line</div>
                                    <span class="card-subtitle-text" id="factsSub">Planned, and how far it has gone</span>
                                </div>
                            </div>
                            <div class="card-body" id="lineFacts"><span class="skel" style="height: 260px; display: block;"></span></div>
                        </div>
                    </div>
                </div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title" id="listTitle">Every amount on this line</div>
                            <span class="card-subtitle-text">Tap one to see everything about it</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="filterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="lineEntriesTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>What for</th>
                                        <th class="d-none d-md-table-cell" id="counterpartyHead">Paid to</th>
                                        <th>How</th>
                                        <th class="d-none d-lg-table-cell">Recorded by</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody id="lineEntriesBody"></tbody>
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

    <?php budgetPageScripts(['assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/budgets/line.js'], true) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsLine.init());</script>
</body>

</html>
