<?php
// Contributions - what a place sends up (the diocese share), and for a region / the diocese, its churches.
// Shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
$pageTitle = 'Contributions';
$pageIcon = 'ri-hand-coin-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    'Contributions' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Contributions - Makueni West Diocese</title>
    <meta name="Description" content="What is sent up: the share due on tithes received, what was sent and what is still to send" />
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
                    <div class="page-toolbar-sub" id="placeLine">&nbsp;</div>
                    <div class="page-toolbar-controls">
                        <div id="yearSwitchWrap"></div>
                        <button type="button" class="btn btn-outline-primary d-none" id="exportReportBtn" data-lock="1" data-module="budget" data-report-key="budget.contributions"><i class="ri-download-2-line me-1"></i>Export</button>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="viewOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="viewOnlyText"></span></div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="card custom-card" id="ownCard">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title" id="ownTitle">Month by month</div>
                            <span class="card-subtitle-text" id="ownSub">The share is worked out on what was received - and sent as an expense on its line</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1" id="ownChips"></div>
                    </div>
                    <div class="card-body p-0" id="ownBody"><div class="p-3"><span class="skel" style="height: 12rem; display: block;"></span></div></div>
                </div>

                <div class="card custom-card" id="payToCard" hidden>
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title">How to send it</div>
                            <span class="card-subtitle-text">Where the share goes - from their Settings &gt; Payment details</span>
                        </div>
                    </div>
                    <div class="card-body" id="payToBody"></div>
                </div>

                <div class="card custom-card" id="belowCard" hidden>
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title">Our churches</div>
                            <span class="card-subtitle-text" id="belowSub">Each church's share for the year - open one to see its months</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="filterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="belowTable">
                                <thead>
                                    <tr>
                                        <th>Church</th>
                                        <th id="groupHead">Region</th>
                                        <th class="text-end">Due</th>
                                        <th class="text-end">Sent</th>
                                        <th class="text-end">Still to send</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="belowTableBody"></tbody>
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

    <?php budgetPageScripts(['assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/budgets/contributions.js'], true) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsContributions.init());</script>
</body>

</html>
