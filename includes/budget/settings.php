<?php
require_once __DIR__ . '/../settings/context.php';
// Budget Settings - one page per level (lines; deductions next). The wrapper sets $budgetCtx (includes/budget/context.php).
// Filled in by assets/js/pages/budgets/settings.js from GET /budget-settings.
$pageTitle = 'Budget Settings';
$pageIcon = 'ri-settings-3-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'] . '/budgets.php',
    'Budget Settings' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budget Settings - Makueni West Diocese</title>
    <meta name="Description" content="The money in and money out lines budgets are built from, and the shares worked out from money in" />
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

                <?php $settingsShell = settingsShellFor($budgetCtx['level'], 'budgets'); include __DIR__ . '/../settings/shell-start.php' ?>

                <div class="page-toolbar">
                    <div class="page-toolbar-sub" id="placeLine">The lines every budget is built from</div>
                    <div class="page-toolbar-controls">
                        <a href="<?= $budgetCtx['baseUrl'] ?>/form.php" class="btn btn-outline-primary"><i class="ri-add-circle-line me-1"></i>New budget</a>
                        <button type="button" class="btn btn-primary" id="addLineBtn" hidden><i class="ri-add-line me-1"></i>Add a line</button>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="readOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> Your role can look at Budget Settings, but not change them.</div>
                </div>

                <div class="nav section-tabs" id="settingsTabs" role="tablist" aria-label="Budget Settings">
                    <button class="nav-link section-tab active" data-bs-toggle="tab" data-bs-target="#tab-lines" data-tab="lines" type="button" role="tab" aria-controls="tab-lines" aria-selected="true">
                        <span class="section-tab-icon bg-primary"><i class="ri-list-check-2"></i></span>
                        <span class="section-tab-text"><strong>Lines</strong><small data-tab-figure="lines">&nbsp;</small></span>
                    </button>
                    <button class="nav-link section-tab" data-bs-toggle="tab" data-bs-target="#tab-deductions" data-tab="deductions" type="button" role="tab" aria-controls="tab-deductions" aria-selected="false">
                        <span class="section-tab-icon bg-purple"><i class="ri-percent-line"></i></span>
                        <span class="section-tab-text"><strong>Deductions</strong><small data-tab-figure="deductions">Shares of money in</small></span>
                    </button>
                </div>

                <div class="tab-content section-tab-content">
                    <div class="tab-pane fade show active" id="tab-lines" role="tabpanel">
                        <div class="row" id="statCardsRow"></div>
                        <div class="card custom-card">
                            <div class="card-body py-2" id="linesToolbar"></div>
                        </div>
                        <div class="row budget-equal-row is-top">
                            <div class="col-xl-6">
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="card-title">Money in</div>
                                            <span class="card-subtitle-text">What comes in: tithes, offerings, gifts…</span>
                                        </div>
                                        <span class="soft-chip soft-success" id="inCount"></span>
                                    </div>
                                    <div class="card-body p-0" id="linesIn"><span class="skel m-3" style="height: 12rem; display: block;"></span></div>
                                </div>
                            </div>
                            <div class="col-xl-6">
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="card-title">Money out (spending)</div>
                                            <span class="card-subtitle-text">What goes out: salaries, rent, bills…</span>
                                        </div>
                                        <span class="soft-chip soft-danger" id="outCount"></span>
                                    </div>
                                    <div class="card-body p-0" id="linesOut"><span class="skel m-3" style="height: 12rem; display: block;"></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-deductions" role="tabpanel">
                        <div class="budget-deduction-intro">
                            <span class="avatar avatar-md bg-purple text-white flex-shrink-0"><i class="ri-percent-line"></i></span>
                            <div class="flex-fill">
                                <div class="fw-semibold">A deduction is a share sent up out of money in - worked out for you.</div>
                                <div class="fs-13">For example <b>"Diocese share: 10% of Tithes received"</b>: when a church records KES 60,000 of tithes, KES 6,000 is due to the diocese. Sending it is money out recorded on the line it's paid through. Until money comes in, the budget shows an estimate from the plan.</div>
                            </div>
                            <button type="button" class="btn btn-primary flex-shrink-0" id="addDeductionBtn" hidden><i class="ri-add-line me-1"></i>Add a deduction</button>
                        </div>
                        <div class="row" id="deductionsList"><div class="col-12"><span class="skel" style="height: 10rem; display: block;"></span></div></div>
                    </div>
                </div>

                <?php include __DIR__ . '/../settings/shell-end.php' ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <!-- Add or change a line -->
    <div class="modal fade app-modal" id="lineModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="lineModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="app-modal-icon" id="lineModalIcon"><i class="ri-price-tag-3-line"></i></span>
                    <div class="flex-fill" style="min-width: 0;">
                        <h5 class="modal-title" id="lineModalTitle">Add a line</h5>
                        <div class="app-modal-subtitle" id="lineModalSub">&nbsp;</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="budget-field-label">Money in or money out?</div>
                    <div id="lineSideWrap" class="mb-1"></div>
                    <div class="fs-12 mb-3" id="lineSideHint"></div>
                    <label class="budget-field-label" for="lineName">Name</label>
                    <input type="text" class="form-control mb-3" id="lineName" maxlength="255" placeholder="e.g. Choir uniforms">
                    <div class="invalid-feedback mb-2" id="lineNameError">Give the line a name.</div>
                    <label class="budget-field-label" for="lineDescription">What goes under it (optional)</label>
                    <textarea class="form-control mb-3" id="lineDescription" rows="2" maxlength="1000" placeholder="e.g. Fabric, tailoring and alterations"></textarea>
                    <div id="lineShareWrap" hidden>
                        <div class="budget-field-label">Who uses it?</div>
                        <select class="form-select mb-3" id="lineShare" aria-label="Who uses it">
                            <option value="own" data-icon="ri-government-line" data-color="purple">Only the diocese's own budgets</option>
                            <option value="church" data-icon="ri-building-line" data-color="primary">Every church</option>
                            <option value="region" data-icon="ri-map-pin-line" data-color="warning">Every region</option>
                            <option value="all" data-icon="ri-earth-line" data-color="success">Everyone (churches, regions and the diocese)</option>
                        </select>
                    </div>
                    <div class="budget-field-label">How it will look on the budget form</div>
                    <div class="num-tile budget-tile" id="linePreview"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="lineSaveBtn"><i class="ri-check-line me-1"></i>Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add or change a deduction -->
    <div class="modal fade app-modal" id="deductionModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="deductionModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="app-modal-icon bg-purple"><i class="ri-percent-line"></i></span>
                    <div class="flex-fill" style="min-width: 0;">
                        <h5 class="modal-title" id="deductionModalTitle">Add a deduction</h5>
                        <div class="app-modal-subtitle" id="deductionModalSub">A share of the money received - worked out on what is recorded</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <label class="budget-field-label" for="dedName">Name</label>
                            <input type="text" class="form-control" id="dedName" maxlength="255" placeholder="e.g. Diocese share">
                            <div class="invalid-feedback">Give the deduction a name.</div>

                            <div class="budget-field-label mt-3">How is it worked out?</div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <div id="dedTypeWrap"></div>
                                <div class="input-group" style="max-width: 13rem;">
                                    <span class="input-group-text" id="dedPrefix">%</span>
                                    <input type="text" inputmode="decimal" class="form-control text-end fw-semibold" id="dedValue" placeholder="10">
                                </div>
                            </div>
                            <div class="fs-12 mt-1" id="dedTypeHint"></div>

                            <div id="dedBasisBlock">
                                <div class="budget-field-label mt-3">On which money received?</div>
                                <div id="dedBasisWrap"></div>
                                <div class="mt-2" id="dedLinesWrap" hidden>
                                    <select class="form-select" id="dedLines" multiple aria-label="Money in lines"></select>
                                </div>
                            </div>

                            <div id="dedAppliesWrap">
                                <div class="budget-field-label mt-3">Who does it apply to?</div>
                                <select class="form-select" id="dedApplies" aria-label="Who it applies to"></select>
                            </div>

                            <div class="budget-field-label mt-3">Paid through which money out line?</div>
                            <select class="form-select" id="dedLine" aria-label="Paid through"></select>
                            <input type="text" class="form-control mt-2" id="dedNewLine" maxlength="255" placeholder="Name of the new line, e.g. Diocese share" hidden>
                            <div class="fs-12 mt-1">Record what was actually sent as money out on this line.</div>
                        </div>
                        <div class="col-lg-5">
                            <div class="budget-field-label">Example</div>
                            <div class="budget-deduction-example" id="dedExample"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="dedSaveBtn"><i class="ri-check-line me-1"></i>Save</button>
                </div>
            </div>
        </div>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts('assets/js/pages/budgets/settings.js') ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsSettings.init());</script>
    <?php settingsRailScripts() ?>
</body>

</html>
