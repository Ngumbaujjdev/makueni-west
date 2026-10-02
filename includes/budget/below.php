<?php
// The budgets of the places below, read-only - a region's churches, the diocese's churches and regions.
// Shared by region and diocese; the wrapper sets $budgetCtx (includes/budget/context.php).
$belowTitle = $budgetCtx['level'] === 'diocese' ? 'Regions and churches' : 'Churches\' budgets';
$pageTitle = $belowTitle;
$pageIcon = 'ri-node-tree';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    $belowTitle => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($belowTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="The budgets of the places below, read-only: who has one, received, spent, still owed" />
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
                        <?php if ($budgetCtx['level'] === 'diocese'): ?>
                        <div id="levelSwitchWrap"></div>
                        <?php endif ?>
                        <div id="yearSwitchWrap"></div>
                        <div class="budget-year-select"><select id="monthSelect" aria-label="Month"></select></div>
                        <button type="button" class="btn btn-outline-primary d-none" id="exportReportBtn" data-lock="1" data-module="budget" data-report-key="budget.rollup"><i class="ri-download-2-line me-1"></i>Export</button>
                    </div>
                </div>

                <div class="alert alert-primary d-flex align-items-center gap-3" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="belowNote">Each place runs its own budgets. Open one to see it - you can look, but only that place can change it.</span></div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="row budget-equal-row">
                    <div class="col-xl-7">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title" id="pvaTitle">Spent against plan</div>
                                    <span class="card-subtitle-text" id="pvaSub">The ten places with the most money out</span>
                                </div>
                                <div class="d-flex flex-wrap gap-1" id="pvaChips"></div>
                            </div>
                            <div class="card-body" id="pvaBody"><span class="skel" style="height: 320px; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Who has a budget</div>
                                    <span class="card-subtitle-text" id="coverSub">&nbsp;</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div id="coverDonut"><span class="skel" style="height: 180px; display: block;"></span></div>
                                <div class="budget-below-noticed">
                                    <div class="fw-semibold mb-2"><i class="ri-lightbulb-flash-line text-warning me-1"></i>What we noticed</div>
                                    <div id="insightsList"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card custom-card" id="groupsCard" hidden>
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title" id="groupsTitle">Regions at a glance</div>
                            <span class="card-subtitle-text" id="groupsSub">Tap one to see only its churches below</span>
                        </div>
                    </div>
                    <div class="card-body" id="groupsGrid"></div>
                </div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title" id="listTitle">Churches</div>
                            <span class="card-subtitle-text" id="listSub">Search, filter or sort - open a place to see its budget</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="filterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="belowTable">
                                <thead>
                                    <tr>
                                        <th id="placeHead">Church</th>
                                        <th id="groupHead">Region</th>
                                        <th>Budget</th>
                                        <th class="text-end">Money in</th>
                                        <th class="text-end">Money out</th>
                                        <th class="text-end">Money left</th>
                                        <th class="text-end">Still owed</th>
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

    <?php budgetPageScripts('assets/js/pages/budgets/below.js', true) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsBelow.init());</script>
</body>

</html>
