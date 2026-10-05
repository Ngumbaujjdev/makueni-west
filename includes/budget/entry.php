<?php
// One amount of income or expense - shared by every level; the wrapper sets $budgetCtx (includes/budget/context.php).
// Filled in by assets/js/pages/budgets/entry.js from GET /budget-entries/{id}.
$pageTitle = 'Income or expense';
$pageIcon = 'ri-exchange-dollar-line';
$breadcrumbs = [
    'Home' => $budgetCtx['homeUrl'],
    'Spending' => $budgetCtx['baseUrl'] . '/spending.php',
    'Entry' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Income or expense - Makueni West Diocese</title>
    <meta name="Description" content="One amount received or spent: what for, when, how, and its effect on the line" />
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
                    <div class="page-toolbar-sub" id="placeLine">&nbsp;</div>
                    <div class="page-toolbar-controls">
                        <a href="<?= $budgetCtx['baseUrl'] ?>/spending.php" class="btn btn-light" id="backBtn"><i class="ri-arrow-left-line me-1"></i>Back</a>
                        <button type="button" class="btn btn-outline-danger" id="deleteBtn" hidden><i class="ri-delete-bin-line me-1"></i>Delete</button>
                        <button type="button" class="btn btn-primary" id="changeBtn" hidden><i class="ri-edit-line me-1"></i>Change</button>
                    </div>
                </div>

                <div class="alert alert-primary d-none align-items-center gap-3" id="viewOnlyBanner" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-eye-line"></i></span>
                    <div><b>View only.</b> <span id="viewOnlyText"></span></div>
                </div>
                <div class="alert alert-danger d-none align-items-center gap-3" id="removedBanner" role="note">
                    <span class="avatar avatar-sm bg-danger text-white flex-shrink-0"><i class="ri-delete-bin-line"></i></span>
                    <div><b>This entry was removed.</b> It no longer counts in the budget.</div>
                    <button type="button" class="btn btn-sm btn-danger ms-auto" id="restoreBtn" hidden>Bring it back</button>
                </div>

                <!-- The amount, what for, when -->
                <div class="card custom-card budget-entry-hero" id="entryHero">
                    <div class="card-body"><span class="skel" style="height: 5rem; display: block;"></span></div>
                </div>

                <div class="row">
                    <div class="col-xl-7">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Details</div>
                                    <span class="card-subtitle-text">Everything recorded about this amount</span>
                                </div>
                            </div>
                            <div class="card-body" id="entryDetails"><span class="skel" style="height: 14rem; display: block;"></span></div>
                        </div>
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Its effect on the line</div>
                                    <span class="card-subtitle-text" id="effectSub">The line before and after this amount</span>
                                </div>
                                <a href="#" class="btn btn-sm btn-outline-primary" id="lineLink">Open the line<i class="ri-arrow-right-line ms-1"></i></a>
                            </div>
                            <div class="card-body" id="entryEffect"><span class="skel" style="height: 8rem; display: block;"></span></div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Receipts</div>
                                    <span class="card-subtitle-text" id="receiptsSub">A photo or PDF of the receipt</span>
                                </div>
                                <label class="btn btn-sm btn-primary mb-0" id="addReceiptBtn" hidden>
                                    <i class="ri-attachment-2 me-1"></i>Attach
                                    <input type="file" id="receiptInput" accept="image/jpeg,image/png,image/webp,application/pdf" hidden>
                                </label>
                            </div>
                            <div class="card-body" id="entryReceipts"><span class="skel" style="height: 6rem; display: block;"></span></div>
                        </div>
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">This entry's history</div>
                                    <span class="card-subtitle-text">When it was recorded, and every change since</span>
                                </div>
                            </div>
                            <div class="card-body" id="entryHistory"><span class="skel" style="height: 8rem; display: block;"></span></div>
                        </div>
                        <div class="card custom-card">
                            <div class="card-header justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="card-title">Other money on this line</div>
                                    <span class="card-subtitle-text" id="othersSub">The latest on the same line</span>
                                </div>
                            </div>
                            <div class="card-body" id="entryOthers"><span class="skel" style="height: 8rem; display: block;"></span></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts(['assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/budgets/entry.js']) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.BudgetsEntry.init());</script>
</body>

</html>
