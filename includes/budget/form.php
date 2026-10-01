<?php
// New / change budget - a step-by-step form with a live preview, shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
$editing = isset($_GET['id']) && (int) $_GET['id'] > 0;
$pageTitle = $editing ? 'Change budget' : 'New budget';
$pageIcon = $editing ? 'ri-edit-line' : 'ri-add-circle-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Budgets' => $budgetCtx['baseUrl'].'/budgets.php',
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
    <?php include __DIR__.'/../start-switcher.php' ?>
    <?php include __DIR__.'/../loader.php' ?>

    <div class="page">
        <?php include __DIR__.'/../header.php' ?>
        <?php include __DIR__.'/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__.'/../page-header.php' ?>

                <div class="page-toolbar">
                    <div class="page-toolbar-sub" id="formSub">Plan what comes in and what goes out<?= $budgetCtx['place']['name'] ? ' for '.htmlspecialchars($budgetCtx['place']['name']) : '' ?></div>
                </div>

<?php
// One step at a time on the left, a live preview on the right - the same
// stepper as recording Demographics (.intake-*). Filled in by
// assets/js/pages/budgets/form.js.
$steps = [
    1 => ['Month or year?', 'What this budget covers'],
    2 => ['Money in', 'What you expect to receive'],
    3 => ['Money out', 'What you plan to spend'],
    4 => ['Check and save', 'Look it over, then save'],
];
?>
                <div id="budgetForm">

                    <nav class="card custom-card intake-steps" id="intakeSteps" aria-label="Steps">
                        <?php foreach ($steps as $n => [$label, $hint]) { ?>
                            <button type="button" class="intake-step-btn<?= $n === 1 ? ' is-on' : '' ?>" data-go="<?= $n ?>" <?= $n > 1 ? 'disabled' : '' ?>>
                                <span class="intake-step-dot"><span><?= $n ?></span><i class="ri-check-line"></i></span>
                                <span class="intake-step-text"><strong><?= $label ?></strong><small><?= $hint ?></small></span>
                            </button>
                        <?php } ?>
                        <span class="intake-steps-mobile" id="intakeStepsMobile">Step 1 of <?= count($steps) ?> · Month or year?</span>
                        <span class="intake-steps-bar"><i id="intakeStepsBar" style="width: <?= round(100 / count($steps)) ?>%"></i></span>
                    </nav>

                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="card custom-card intake-form" id="budgetFormCard">

                                <!-- 1 Month or year? -->
                                <section class="intake-step" data-step="1">
                                    <div class="intake-step-head">
                                        <span class="intake-step-num">1</span>
                                        <div>
                                            <h5>Month or year?</h5>
                                            <p>A budget covers one month, or the whole year</p>
                                        </div>
                                    </div>
                                    <div class="intake-errors" data-errors-for="1" hidden role="alert"></div>
                                    <div class="intake-step-body">
                                        <div class="budget-kind-row">
                                            <div>
                                                <div class="budget-field-label">This budget is for</div>
                                                <div id="kindSwitchWrap"></div>
                                            </div>
                                            <div>
                                                <div class="budget-field-label">Year</div>
                                                <div id="yearSwitchWrap"></div>
                                            </div>
                                        </div>
                                        <div class="budget-field-label mt-3" id="periodLabel">Which month?</div>
                                        <div class="period-grid" id="periodChips" role="radiogroup" aria-label="Which month or year"></div>
                                        <div class="period-legend" id="periodLegend">
                                            <span><i class="ri-checkbox-circle-fill text-success"></i>In use</span>
                                            <span><i class="ri-draft-fill text-warning"></i>Draft</span>
                                            <span><i class="ri-lock-fill text-purple"></i>Closed</span>
                                            <span><i class="ri-checkbox-blank-circle-line"></i>Free</span>
                                        </div>
                                        <div id="periodNote"></div>
                                        <div class="invalid-feedback d-block" id="periodError" hidden></div>
                                        <div class="intake-prefill mt-3 mb-0" id="copyBox" hidden>
                                            <span class="intake-prefill-icon" id="copyIcon"><i class="ri-history-line"></i></span>
                                            <span class="intake-prefill-text">
                                                <span class="d-block" id="copyTitle">Start from the last budget</span>
                                                <span class="d-block fs-12" id="copyText"></span>
                                            </span>
                                            <button type="button" class="btn btn-sm btn-primary" id="copyBtn"><i class="ri-magic-line me-1"></i>Start from these amounts</button>
                                        </div>
                                    </div>
                                </section>

                                <!-- 2 Money in -->
                                <section class="intake-step" data-step="2" hidden>
                                    <div class="intake-step-head">
                                        <span class="intake-step-num">2</span>
                                        <div class="flex-fill">
                                            <h5>Money in</h5>
                                            <p>What do you expect to receive? Lines left empty are left out.</p>
                                        </div>
                                        <span class="soft-chip soft-success"><b id="inTotal">KES 0.00</b></span>
                                    </div>
                                    <div class="intake-errors" data-errors-for="2" hidden role="alert"></div>
                                    <div class="intake-step-body" id="linesIn"><span class="skel" style="height: 8rem; display: block;"></span></div>
                                </section>

                                <!-- 3 Money out (spending) -->
                                <section class="intake-step" data-step="3" hidden>
                                    <div class="intake-step-head">
                                        <span class="intake-step-num">3</span>
                                        <div class="flex-fill">
                                            <h5>Money out (spending)</h5>
                                            <p>Salaries, rent, bills and the rest. Deductions such as the diocese share come later, from Budget Settings.</p>
                                        </div>
                                        <span class="soft-chip soft-danger"><b id="outTotal">KES 0.00</b></span>
                                    </div>
                                    <div class="intake-errors" data-errors-for="3" hidden role="alert"></div>
                                    <div class="intake-step-body" id="linesOut"><span class="skel" style="height: 12rem; display: block;"></span></div>
                                </section>

                                <!-- 4 Check and save -->
                                <section class="intake-step" data-step="4" hidden>
                                    <div class="intake-step-head">
                                        <span class="intake-step-num">4</span>
                                        <div class="flex-fill">
                                            <h5>Check and save</h5>
                                            <p>Every line you planned, and how it compares with last time</p>
                                        </div>
                                        <span class="soft-chip soft-purple"><b id="reviewChip">0 lines</b></span>
                                    </div>
                                    <div class="intake-errors" data-errors-for="4" hidden role="alert"></div>
                                    <div class="intake-step-body">
                                        <div id="reviewBody"></div>
                                        <label class="budget-field-label mt-3" for="notesInput">Notes (optional)</label>
                                        <textarea class="form-control" id="notesInput" rows="3" maxlength="2000" placeholder="e.g. Includes the roof repair agreed at the committee meeting"></textarea>
                                    </div>
                                </section>

                                <div class="intake-foot">
                                    <span class="intake-saved me-auto" id="intakeSaved">Not saved yet</span>
                                    <a href="<?= $budgetCtx['baseUrl'] ?>/budgets.php" class="btn btn-light" id="cancelBtn">Cancel</a>
                                    <button type="button" class="btn btn-light" id="backBtn" hidden><i class="ri-arrow-left-line"></i><span class="intake-btn-text ms-1">Back</span></button>
                                    <button type="button" class="btn btn-outline-primary" id="saveDraftBtn"><i class="ri-draft-line"></i><span class="intake-btn-text ms-1">Save as draft</span></button>
                                    <button type="button" class="btn btn-primary" id="nextBtn">Next<i class="ri-arrow-right-line ms-1"></i></button>
                                    <button type="button" class="btn btn-success" id="saveStartBtn" hidden><i class="ri-play-circle-line me-1"></i>Save and start using</button>
                                </div>
                            </div>
                        </div>

                        <!-- Live preview -->
                        <div class="col-lg-4">
                            <div class="intake-aside">
                                <div class="card custom-card preview-card">
                                    <div class="preview-head">
                                        <span class="preview-label"><i class="ri-eye-line"></i>Live preview</span>
                                        <span id="previewStatus"></span>
                                    </div>
                                    <div class="preview-period" id="summaryTitle">Pick a month or year</div>
                                    <div class="preview-progress">
                                        <span id="previewFilled">0 lines filled</span>
                                        <span class="preview-progress-bar"><i id="previewFilledBar" style="width: 0%"></i></span>
                                    </div>
                                    <div class="preview-section">
                                        <div class="preview-changes budget-preview-totals">
                                            <div class="preview-change" id="sumInTile">
                                                <span class="preview-change-icon bg-success text-white"><i class="ri-arrow-down-circle-line"></i></span>
                                                <span class="preview-change-value" id="sumIn">KES 0</span>
                                                <span class="preview-change-label">Money in</span>
                                            </div>
                                            <div class="preview-change" id="sumOutTile">
                                                <span class="preview-change-icon bg-danger text-white"><i class="ri-arrow-up-circle-line"></i></span>
                                                <span class="preview-change-value" id="sumOut">KES 0</span>
                                                <span class="preview-change-label">Money out</span>
                                            </div>
                                            <div class="preview-change" id="sumLeftTile">
                                                <span class="preview-change-icon bg-purple text-white"><i class="ri-scales-3-line"></i></span>
                                                <span class="preview-change-value" id="sumLeft">KES 0</span>
                                                <span class="preview-change-label">Money left</span>
                                            </div>
                                        </div>
                                        <div class="count-bar composition-bar mt-3 mb-2" id="sumBar" aria-hidden="true"><span class="bg-success" style="width: 50%"></span><span class="bg-danger" style="width: 50%"></span></div>
                                        <div class="d-flex flex-wrap gap-1" id="sumCompare"></div>
                                    </div>
                                    <div class="preview-section">
                                        <div class="preview-section-title">Money in</div>
                                        <div id="previewIn"></div>
                                    </div>
                                    <div class="preview-section">
                                        <div class="preview-section-title">Money out</div>
                                        <div id="previewOut"></div>
                                    </div>
                                    <div class="preview-section">
                                        <div class="fs-12" id="sumHint">Type an amount next to each line you plan for. Lines left empty are left out.</div>
                                    </div>
                                </div>

                                <div class="card custom-card intake-tips">
                                    <strong><i class="ri-lightbulb-line"></i>Good to know</strong>
                                    <ul>
                                        <li><b>Copy amounts</b> fills every line from the last budget. Change what's different, and Undo if you change your mind.</li>
                                        <li><b>Money out</b> is what you plan to spend. Deductions like the diocese share are worked out separately.</li>
                                        <li><b>Save as draft</b> keeps it to finish later. Money can be recorded once you <b>start using</b> it.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__.'/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts('assets/js/pages/budgets/form.js') ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsForm.init());</script>
</body>

</html>
