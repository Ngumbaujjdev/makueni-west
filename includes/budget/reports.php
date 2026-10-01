<?php
// Budget reports - shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
// The report cards and "Your recent reports" come from assets/js/pages/demographics/reports.js
// (REPORTS_PAGE.module = 'budget'); each card opens the shared export window (report-center.js).
$pageTitle = 'Budget Reports';
$pageIcon = 'ri-file-download-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    'Reports' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budget Reports - Makueni West Diocese</title>
    <meta name="Description" content="Budget reports as PDF or Excel: summary, money in and out, and one budget's statement" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php budgetPageStyles(true) ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <script>
        const USER_TERRITORY = <?= json_encode(['id' => $budgetCtx['place']['id'], 'name' => $budgetCtx['place']['name']]) ?>;
        window.REPORTS_PAGE = { module: 'budget' };
    </script>
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
                    <div class="page-toolbar-sub">Budget reports<?= $budgetCtx['place']['name'] ? ' for ' . htmlspecialchars($budgetCtx['place']['name']) : '' ?> as PDF or Excel, with what we noticed</div>
                    <div class="page-toolbar-controls">
                        <a href="<?= $budgetCtx['baseUrl'] ?>/overview.php" class="btn btn-outline-primary"><i class="ri-dashboard-3-line me-1"></i>Overview</a>
                        <a href="<?= SITE_URL ?>/verify-report" target="_blank" rel="noopener" class="btn btn-light"><i class="ri-shield-check-line me-1"></i>Verify a report</a>
                    </div>
                </div>

                <div class="alert alert-primary d-flex align-items-center gap-3" role="note">
                    <span class="avatar avatar-sm bg-purple text-white flex-shrink-0"><i class="ri-file-list-3-line"></i></span>
                    <div>Need one budget on its own? Open it from <a href="<?= $budgetCtx['baseUrl'] ?>/budgets.php" class="fw-semibold">Budgets</a> and press <b>Export</b> for its statement.</div>
                </div>

                <div class="row g-3 mb-4" id="reportCatalogue">
                    <?php for ($i = 0; $i < 2; $i++) : ?>
                        <div class="col-md-6 col-xl-4"><div class="skel" style="height: 11rem; border-radius: var(--v2-radius);"></div></div>
                    <?php endfor ?>
                </div>

                <div class="card custom-card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Your recent reports</div>
                            <span class="card-subtitle-text">Files are kept for 7 days. Their verification codes keep working after that.</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="runsFilterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="runsTable">
                                <thead>
                                    <tr>
                                        <th>Report</th>
                                        <th>Format</th>
                                        <th>Status</th>
                                        <th>Generated</th>
                                        <th>Verification code</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="runsBody"></tbody>
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

    <?php budgetPageScripts('assets/js/pages/demographics/reports.js', true) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.DemographicsReports.init());</script>
</body>

</html>
