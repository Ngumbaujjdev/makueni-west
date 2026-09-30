<?php
// New / change budget - one page, shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
$editing = isset($_GET['id']) && (int) $_GET['id'] > 0;
$pageTitle = $editing ? 'Change budget' : 'New budget';
$pageIcon = $editing ? 'ri-edit-line' : 'ri-add-circle-line';
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
    <title><?= $pageTitle ?> - Makueni West Diocese</title>
    <meta name="Description" content="Plan what comes in and what goes out for a month or a year" />
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
                    <div class="page-toolbar-sub" id="formSub">Plan what comes in and what goes out<?= $budgetCtx['place']['name'] ? ' for ' . htmlspecialchars($budgetCtx['place']['name']) : '' ?></div>
                </div>

                <div class="row budget-form" id="budgetForm">
                    <div class="col-xl-8">

                        <!-- 1 Which month or year? -->
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="budget-step">1</span>
                                    <div>
                                        <div class="card-title">Which month or year?</div>
                                        <span class="card-subtitle-text">A budget covers one month, or a whole year</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                    <span class="fw-semibold">Year</span>
                                    <div id="yearSwitchWrap"></div>
                                </div>
                                <div class="budget-period-chips" id="periodChips" role="radiogroup" aria-label="Which month or year"></div>
                                <div class="invalid-feedback d-block" id="periodError" hidden></div>
                                <div class="budget-copy mt-3" id="copyBox" hidden>
                                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-file-copy-line"></i></span>
                                    <div class="flex-fill">
                                        <div class="fw-semibold" id="copyTitle">Start from last budget</div>
                                        <div class="fs-12" id="copyText"></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-primary" id="copyBtn"><i class="ri-file-copy-line me-1"></i>Copy amounts</button>
                                </div>
                            </div>
                        </div>

                        <!-- 2 Money in -->
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="budget-step">2</span>
                                    <div>
                                        <div class="card-title">Money in</div>
                                        <span class="card-subtitle-text">What do you expect to receive?</span>
                                    </div>
                                </div>
                                <span class="soft-chip soft-success"><b id="inTotal">KES 0.00</b></span>
                            </div>
                            <div class="card-body" id="linesIn"><span class="skel" style="height: 8rem; display: block;"></span></div>
                        </div>

                        <!-- 3 Money out -->
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="budget-step">3</span>
                                    <div>
                                        <div class="card-title">Money out</div>
                                        <span class="card-subtitle-text">What do you plan to spend?</span>
                                    </div>
                                </div>
                                <span class="soft-chip soft-danger"><b id="outTotal">KES 0.00</b></span>
                            </div>
                            <div class="card-body" id="linesOut"><span class="skel" style="height: 12rem; display: block;"></span></div>
                        </div>

                        <!-- 4 Notes -->
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="budget-step">4</span>
                                    <div>
                                        <div class="card-title">Notes</div>
                                        <span class="card-subtitle-text">Anything worth remembering about this budget (optional)</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <textarea class="form-control" id="notesInput" rows="3" maxlength="2000" placeholder="e.g. Includes the roof repair agreed at the committee meeting"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Totals + save, stays in view -->
                    <div class="col-xl-4">
                        <div class="card custom-card budget-summary">
                            <div class="card-header">
                                <div>
                                    <div class="card-title" id="summaryTitle">This budget</div>
                                    <span class="card-subtitle-text" id="summarySub">Updates as you type</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="budget-sum-row"><span><i class="ri-arrow-down-circle-line text-success me-1"></i>Money in</span><b id="sumIn">KES 0.00</b></div>
                                <div class="budget-sum-row"><span><i class="ri-arrow-up-circle-line text-danger me-1"></i>Money out</span><b id="sumOut">KES 0.00</b></div>
                                <div class="budget-sum-row is-total"><span>Money left</span><b id="sumLeft">KES 0.00</b></div>
                                <div class="count-bar composition-bar my-2" id="sumBar" aria-hidden="true"><span class="bg-success" style="width: 50%"></span><span class="bg-danger" style="width: 50%"></span></div>
                                <div class="d-flex flex-wrap gap-1" id="sumCompare"></div>
                                <div class="fs-12 mt-2" id="sumHint">Type an amount next to each line you plan for. Lines left empty are left out.</div>
                                <div class="d-grid gap-2 mt-3" id="saveButtons">
                                    <button type="button" class="btn btn-primary" id="saveStartBtn"><i class="ri-play-circle-line me-1"></i>Save and start using</button>
                                    <button type="button" class="btn btn-outline-primary" id="saveDraftBtn"><i class="ri-draft-line me-1"></i>Save as draft</button>
                                    <a href="<?= $budgetCtx['baseUrl'] ?>/budgets.php" class="btn btn-light" id="cancelBtn">Cancel</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts('assets/js/pages/budgets/form.js') ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsForm.init());</script>
</body>

</html>
