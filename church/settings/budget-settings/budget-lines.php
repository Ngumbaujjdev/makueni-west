<?php
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

requirePermission('church.settings.budgetsettings.budgetlines.read');

$canCreate = hasPermission('church.settings.budgetsettings.budgetlines.create');
$canUpdate = hasPermission('church.settings.budgetsettings.budgetlines.update');
$canDelete = hasPermission('church.settings.budgetsettings.budgetlines.delete');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Budget Lines';
$pageIcon = 'ri-settings-3-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Budget Settings' => null,
    'Budget Lines' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Budget Lines - Makueni West Diocese</title>
    <meta name="Description" content="The diocese's budget lines, and the lines only this church uses" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/select2/select2.min.css" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: <?= json_encode($userTerritoryName) ?>
        };
        const BUDGET_LINES_CAN = <?= json_encode(['create' => $canCreate, 'update' => $canUpdate, 'delete' => $canDelete]) ?>;
    </script>
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <?php include __DIR__ . '/../../../includes/start-switcher.php' ?>
    <?php include __DIR__ . '/../../../includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../../../includes/header.php' ?>
        <?php include __DIR__ . '/../../../includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../../../includes/page-header.php' ?>

                <div class="alert alert-primary d-flex align-items-start gap-3 mb-4" role="note">
                    <span class="avatar avatar-sm bg-primary text-white flex-shrink-0"><i class="ri-information-line"></i></span>
                    <div>
                        <div class="fw-semibold">What your budgets are built from</div>
                        <div>The diocese sets the budget types, categories and shared lines every church uses - those are locked here.
                            Add a line only <?= htmlspecialchars($userTerritoryName) ?> needs (say, a choir uniform fund) and it shows up
                            when you prepare a <a href="<?= SITE_URL ?>/church/budget/all-budgets.php" class="fw-semibold">budget</a>. Other churches never see it.</div>
                    </div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="card custom-card">
                    <div class="card-header justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="card-title">Budget lines</div>
                            <span class="card-subtitle-text">What you choose from when you prepare a budget</span>
                        </div>
                        <?php if ($canCreate): ?>
                        <button type="button" class="btn btn-primary" id="addBudgetLineBtn">
                            <i class="ri-add-line me-1"></i>Add a line for our church
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div id="filterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="budgetLinesTable">
                                <thead>
                                    <tr>
                                        <th>Line</th>
                                        <th>Category</th>
                                        <th>Set by</th>
                                        <th class="d-none d-md-table-cell">In our budgets</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="budgetLinesTableBody">
                                    <!-- Rows injected by church-budget-lines.js -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Budget types</div>
                                    <span class="card-subtitle-text">Set by the diocese</span>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush" id="budgetTypesList"></ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Categories</div>
                                    <span class="card-subtitle-text">Set by the diocese - every line belongs to one</span>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush" id="budgetCategoriesList"></ul>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../../includes/footer.php' ?>
    </div>

    <!-- Add/Edit a church's own budget line -->
    <div class="modal fade app-modal" id="budgetLineModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="budgetLineModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="app-modal-icon"><i class="ri-price-tag-3-line"></i></span>
                    <div class="flex-fill" style="min-width: 0;">
                        <h5 class="modal-title" id="budgetLineModalTitle">Add a line for our church</h5>
                        <div class="app-modal-subtitle">Only <?= htmlspecialchars($userTerritoryName) ?> sees and uses it</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="budgetLineCategory" class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-select" id="budgetLineCategory" required>
                                <option value="">Loading categories...</option>
                            </select>
                            <div class="invalid-feedback">Choose a category.</div>
                        </div>
                        <div class="col-12">
                            <label for="budgetLineName" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="budgetLineName" maxlength="255" placeholder="e.g. Choir uniforms" required>
                            <div class="invalid-feedback">Give this line a name.</div>
                        </div>
                        <div class="col-12">
                            <label for="budgetLineDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="budgetLineDescription" rows="2" maxlength="1000" placeholder="What goes under this line (optional)"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="appearance-toggle-row border rounded-3 px-3">
                                <label class="flex-fill mb-0" for="budgetLineActive">
                                    <span class="d-block fw-semibold">Active</span>
                                    <span class="d-block fs-12 text-muted">Switched-off lines aren't offered for new budgets</span>
                                </label>
                                <div class="form-check form-switch form-switch-lg mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="budgetLineActive" checked>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveBudgetLineBtn">
                        <i class="ri-check-line me-1"></i>Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <script src="<?= SITE_URL ?>/assets/libs/@popperjs/core/umd/popper.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js<?= assetVersion('assets/js/utils/toast.js') ?>"></script>

    <!-- jQuery + DataTables (search/filter/pagination) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/libs/select2/select2.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/jquery.dataTables.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/budget-management/api-handler.js<?= assetVersion('assets/js/pages/budget-management/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/budget-settings/church-budget-lines.js<?= assetVersion('assets/js/pages/budget-settings/church-budget-lines.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.ChurchBudgetLines.init());
    </script>
</body>

</html>
