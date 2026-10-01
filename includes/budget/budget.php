<?php
// One budget - shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
$pageTitle = 'Budget';
$pageIcon = 'ri-wallet-3-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    'Budget' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budget - Makueni West Diocese</title>
    <meta name="Description" content="One budget: what was planned, what came in and went out, and its history" />
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

                <div class="record-head">
                    <div class="record-head-main">
                        <span id="budgetIcon"><span class="skel" style="width: 2.4rem; height: 2.4rem;"></span></span>
                        <div style="min-width: 0;">
                            <h4 class="record-title" id="budgetTitle"><span class="skel skel-title" style="width: 14rem;"></span></h4>
                            <div class="record-sub" id="budgetSub"></div>
                        </div>
                    </div>
                    <div class="record-actions" id="budgetActions">
                        <a href="<?= $budgetCtx['baseUrl'] ?>/budgets.php" class="btn btn-light" id="backBtn"><i class="ri-arrow-left-line me-1"></i>Budgets</a>
                        <button type="button" class="btn btn-outline-danger" id="deleteBtn" hidden><i class="ri-delete-bin-line me-1"></i>Delete</button>
                        <button type="button" class="btn btn-outline-secondary" id="closeBtn" hidden><i class="ri-lock-line me-1"></i>Close</button>
                        <button type="button" class="btn btn-outline-primary" id="reopenBtn" hidden><i class="ri-lock-unlock-line me-1"></i>Reopen</button>
                        <button type="button" class="btn btn-outline-primary d-none" id="exportReportBtn" data-lock="1" data-module="budget" data-report-key="budget.statement"><i class="ri-download-2-line me-1"></i>Export</button>
                        <a href="#" class="btn btn-outline-primary" id="editBtn" hidden><i class="ri-edit-line me-1"></i>Change</a>
                        <button type="button" class="btn btn-success" id="startBtn" hidden><i class="ri-play-circle-line me-1"></i>Start using</button>
                        <button type="button" class="btn btn-primary" id="recordBtn" hidden><i class="ri-add-line me-1"></i>Record money</button>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="viewOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="viewOnlyText"></span></div>
                </div>

                <div class="alert alert-warning d-none align-items-center gap-3" id="draftBanner" role="note">
                    <span class="avatar avatar-sm bg-warning text-dark flex-shrink-0"><i class="ri-draft-line"></i></span>
                    <div><b>This budget is still a draft.</b> Change it as much as you like, then press <b>Start using</b> when it's ready.</div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="row budget-equal-row">
                    <div class="col-xl-7">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Where the money goes</div>
                                    <span class="card-subtitle-text" id="whereSub">This budget's money out, by line</span>
                                </div>
                                <div id="whereSwitchWrap"></div>
                            </div>
                            <div class="card-body" id="whereDonut"><span class="skel" style="height: 260px; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">This budget</div>
                                    <span class="card-subtitle-text" id="statusSub">How far into it we are</span>
                                </div>
                            </div>
                            <div class="card-body" id="statusCard"><span class="skel" style="height: 260px; display: block;"></span></div>
                        </div>
                    </div>
                </div>

                <!-- A whole-year budget: when its money moved, month by month (by the date each amount was recorded) -->
                <div class="card custom-card" id="monthsCard" hidden>
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title">Month by month</div>
                            <span class="card-subtitle-text">Money in and out each month, by the date it was recorded - tap a month to see its entries</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1" id="monthsChips"></div>
                    </div>
                    <div class="card-body">
                        <div id="monthsChart"></div>
                        <div class="budget-month-strip" id="monthsStrip"></div>
                    </div>
                </div>

                <div class="nav section-tabs" id="budgetTabs" role="tablist" aria-label="Budget">
                    <button class="nav-link section-tab active" data-bs-toggle="tab" data-bs-target="#tab-lines" data-tab="lines" type="button" role="tab" aria-controls="tab-lines" aria-selected="true">
                        <span class="section-tab-icon bg-primary"><i class="ri-list-check-2"></i></span>
                        <span class="section-tab-text"><strong>Lines</strong><small data-tab-figure="lines">&nbsp;</small></span>
                    </button>
                    <button class="nav-link section-tab" data-bs-toggle="tab" data-bs-target="#tab-spending" data-tab="spending" type="button" role="tab" aria-controls="tab-spending" aria-selected="false">
                        <span class="section-tab-icon bg-success"><i class="ri-exchange-dollar-line"></i></span>
                        <span class="section-tab-text"><strong>Spending</strong><small data-tab-figure="spending">&nbsp;</small></span>
                    </button>
                    <button class="nav-link section-tab" data-bs-toggle="tab" data-bs-target="#tab-history" data-tab="history" type="button" role="tab" aria-controls="tab-history" aria-selected="false">
                        <span class="section-tab-icon bg-purple"><i class="ri-history-line"></i></span>
                        <span class="section-tab-text"><strong>History</strong><small data-tab-figure="history">&nbsp;</small></span>
                    </button>
                </div>

                <div class="tab-content section-tab-content">
                    <div class="tab-pane fade show active" id="tab-lines" role="tabpanel">
                        <div class="row">
                            <div class="col-xl-6">
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="card-title">Money in</div>
                                            <span class="card-subtitle-text">Planned, and received so far</span>
                                        </div>
                                        <span class="soft-chip soft-success" id="inChip"></span>
                                    </div>
                                    <div class="card-body p-0" id="linesIn"><span class="skel" style="height: 8rem; display: block;"></span></div>
                                </div>
                            </div>
                            <div class="col-xl-6">
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="card-title">Money out (spending)</div>
                                            <span class="card-subtitle-text">Planned, and spent so far</span>
                                        </div>
                                        <span class="soft-chip soft-danger" id="outChip"></span>
                                    </div>
                                    <div class="card-body p-0" id="linesOut"><span class="skel" style="height: 12rem; display: block;"></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="card custom-card" id="notesCard" hidden>
                            <div class="card-header"><div class="card-title">Notes</div></div>
                            <div class="card-body" id="budgetNotes"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-spending" role="tabpanel">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Money in and out</div>
                                    <span class="card-subtitle-text" id="spendingSub">Everything recorded against this budget</span>
                                </div>
                                <div class="d-flex flex-wrap gap-1" id="spendingChips"></div>
                            </div>
                            <div class="card-body" id="budgetSpending"><span class="skel" style="height: 8rem; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-history" role="tabpanel">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">History</div>
                                    <span class="card-subtitle-text">Who prepared it, and every change since</span>
                                </div>
                            </div>
                            <div class="card-body" id="budgetHistory"></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts(['assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/budgets/detail.js']) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsDetail.init());</script>
</body>

</html>
