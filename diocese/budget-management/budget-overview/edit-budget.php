<?php
// 1. Start session & check auth
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

// 2. Check permission
requirePermission('diocese.budgetmanagement.budgetoverview.read');

// 3. Get user data
$user = getAuthUser();
$currentRole = getCurrentRole();

// 4. Get user territory info from current role
$userTerritoryType = $currentRole['territory_type'] ?? 'diocese';
$userTerritoryId = $currentRole['territory_id'] ?? 1;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Diocese';

// 5. Get budget ID from URL
$budgetId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($budgetId <= 0) {
    header('Location: <?= SITE_URL ?>/diocese/budget-management/budget-overview/all-budgets?error=invalid_id');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Edit Budget - Makueni West Diocese</title>
    <meta name="Description" content="Edit budget details and line items" />

    <!-- Favicon -->
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico"
        type="image/x-icon" />

    <!-- Choices JS -->
    <script src="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/scripts/choices.min.js">
    </script>

    <!-- Main Theme Js -->
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <!-- Flatpickr Css -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/flatpickr/flatpickr.min.css" />

    <!-- Choices Css -->
    <link rel="stylesheet"
        href="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/styles/choices.min.css" />

    <!-- Custom Styles for Edit Budget -->
    <style>
    .choices.is-invalid-choices .choices__inner {
        border-color: #dc3545 !important;
    }

    .choices.is-invalid-choices .choices__inner:focus {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
    }

    /* Expandable row styles */
    .line-item-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .line-item-row:hover {
        background-color: rgba(var(--primary-rgb), 0.05);
    }

    .line-item-row.expanded {
        background-color: rgba(var(--primary-rgb), 0.08);
    }

    .expanded-edit-form {
        background-color: #f8f9fa;
        border-left: 3px solid var(--primary-color);
    }

    .expanded-edit-form td {
        padding: 1rem !important;
    }

    /* Hide expanded row by default */
    .edit-row {
        display: none;
    }

    .edit-row.show {
        display: table-row;
    }

    /* Line type badges */
    .badge-income {
        background-color: rgba(25, 135, 84, 0.1);
        color: #198754;
    }

    .badge-expense {
        background-color: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    /* Loading overlay */
    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
    }

    /* Status badge */
    .status-badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.65rem;
    }

    /* Unsaved changes indicator */
    .unsaved-indicator {
        display: none;
        color: #ffc107;
    }

    .has-unsaved-changes .unsaved-indicator {
        display: inline;
    }
    </style>

    <!-- User Territory and Budget ID for JS -->
    <script>
    const USER_TERRITORY = {
        type: '<?php echo addslashes($userTerritoryType); ?>',
        id: <?php echo $userTerritoryId; ?>,
        name: '<?php echo addslashes($userTerritoryName); ?>'
    };
    const BUDGET_ID = <?php echo $budgetId; ?>;
    </script>
</head>

<body>
    <!-- Start Switcher -->
    <?php include '../../../includes/start-switcher.php' ?>

    <!-- Loader -->
    <?php include "../../../includes/loader.php" ?>

    <div class="page">
        <!-- Header -->
        <?php include '../../../includes/header.php' ?>

        <!-- Sidebar -->
        <?php include '../../../includes/sidebar.php' ?>

        <!-- Main Content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <div>
                        <h1 class="page-title fw-semibold fs-18 mb-0">
                            <i class="ri-edit-line me-2"></i>Edit Budget
                            <span class="unsaved-indicator ms-2" title="Unsaved changes">
                                <i class="ri-error-warning-line"></i>
                            </span>
                        </h1>
                        <small class="text-muted" id="budgetNameHeader">Loading...</small>
                    </div>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="<?= SITE_URL ?>/diocese/dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="<?= SITE_URL ?>/diocese/budget-management/budget-overview/all-budgets">Budget
                                        Management</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Edit Budget</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Alert for non-editable budgets -->
                <div class="alert alert-warning d-none" id="nonEditableAlert">
                    <i class="ri-error-warning-line me-2"></i>
                    <strong>Budget cannot be edited.</strong>
                    <span id="nonEditableReason">Only budgets in Draft or Rejected status can be edited.</span>
                    <a href="<?= SITE_URL ?>/diocese/budget-management/budget-overview/all-budgets"
                        class="alert-link ms-2">Return to Budget List</a>
                </div>

                <!-- Budget Audit Info Card -->
                <div class="row mb-3" id="auditInfoCard" style="display: none;">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-body py-3">
                                <div class="row g-3 align-items-center">
                                    <!-- Creator Info -->
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <div class="me-2">
                                                <span class="avatar avatar-sm bg-primary-transparent text-primary">
                                                    <i class="ri-user-add-line"></i>
                                                </span>
                                            </div>
                                            <div>
                                                <span class="d-block fs-11 text-muted text-uppercase fw-medium">Created By</span>
                                                <span class="d-block fw-semibold" id="budgetCreatorName">-</span>
                                                <span class="d-block fs-10 text-muted" id="budgetCreatedDate">-</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Last Updated Info -->
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <div class="me-2">
                                                <span class="avatar avatar-sm bg-info-transparent text-info">
                                                    <i class="ri-edit-line"></i>
                                                </span>
                                            </div>
                                            <div>
                                                <span class="d-block fs-11 text-muted text-uppercase fw-medium">Last Updated By</span>
                                                <span class="d-block fw-semibold" id="budgetUpdaterName">-</span>
                                                <span class="d-block fs-10 text-muted" id="budgetUpdatedDate">-</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Approver Info -->
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center">
                                            <div class="me-2">
                                                <span class="avatar avatar-sm bg-success-transparent text-success">
                                                    <i class="ri-user-star-line"></i>
                                                </span>
                                            </div>
                                            <div>
                                                <span class="d-block fs-11 text-muted text-uppercase fw-medium">Approved By</span>
                                                <span class="d-block fw-semibold" id="budgetApproverName">-</span>
                                                <span class="d-block fs-10 text-muted" id="budgetApprovedDate">-</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Edit Form Card -->
                <div class="row" id="editFormContainer">
                    <div class="col-xl-12">
                        <div class="card custom-card position-relative">
                            <!-- Loading Overlay -->
                            <div class="loading-overlay" id="loadingOverlay">
                                <div class="text-center">
                                    <div class="spinner-border text-primary mb-2" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <div>Loading budget data...</div>
                                </div>
                            </div>

                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-0">
                                        <i class="ri-file-list-3-line me-2"></i>Budget Details
                                    </h5>
                                </div>
                                <div>
                                    <span class="badge status-badge" id="budgetStatusBadge">Loading...</span>
                                </div>
                            </div>

                            <div class="card-body">
                                <form id="editBudgetForm">
                                    <!-- Basic Information Section -->
                                    <div class="mb-4">
                                        <h6 class="fw-semibold border-bottom pb-2 mb-3">
                                            <i class="ri-information-line me-2"></i>Basic Information
                                        </h6>

                                        <div class="row gy-3">
                                            <!-- Budget Name -->
                                            <div class="col-md-6">
                                                <label for="budgetName" class="form-label">Budget Name <span
                                                        class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="budgetName"
                                                    placeholder="e.g., Q1 2026 Budget" required>
                                            </div>

                                            <!-- Fiscal Year -->
                                            <div class="col-md-6">
                                                <label for="fiscalYear" class="form-label">Fiscal Year <span
                                                        class="text-danger">*</span></label>
                                                <select class="form-control" id="fiscalYear" required>
                                                    <option value="">Loading fiscal years...</option>
                                                </select>
                                            </div>

                                            <!-- Budget Type -->
                                            <div class="col-md-6">
                                                <label for="budgetType" class="form-label">Budget Type <span
                                                        class="text-danger">*</span></label>
                                                <select class="form-control" id="budgetType" required>
                                                    <option value="">Select Type</option>
                                                </select>
                                            </div>

                                            <!-- Budget Period -->
                                            <div class="col-md-6">
                                                <label for="budgetPeriod" class="form-label">Budget Period <span
                                                        class="text-danger">*</span></label>
                                                <select class="form-control" id="budgetPeriod" required>
                                                    <option value="">Select period</option>
                                                </select>
                                            </div>

                                            <!-- Start Date (Read-only) -->
                                            <div class="col-md-6">
                                                <label for="startDate" class="form-label">Start Date</label>
                                                <input type="text" class="form-control" id="startDate" readonly
                                                    placeholder="Auto-calculated">
                                            </div>

                                            <!-- End Date (Read-only) -->
                                            <div class="col-md-6">
                                                <label for="endDate" class="form-label">End Date</label>
                                                <input type="text" class="form-control" id="endDate" readonly
                                                    placeholder="Auto-calculated">
                                            </div>

                                            <!-- Territory (Read-only) -->
                                            <div class="col-md-12">
                                                <label class="form-label">Territory</label>
                                                <div class="alert alert-primary mb-0 py-2">
                                                    <i class="ri-map-pin-line me-2"></i>
                                                    <strong
                                                        id="territoryDisplay"><?php echo htmlspecialchars($userTerritoryName); ?></strong>
                                                    <small class="text-muted ms-2">(Cannot be changed)</small>
                                                </div>
                                            </div>

                                            <!-- Description -->
                                            <div class="col-md-12">
                                                <label for="description" class="form-label">Description</label>
                                                <textarea class="form-control" id="description" rows="3"
                                                    placeholder="Optional description for this budget"></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Budget Lines Section -->
                                    <div class="mb-4">
                                        <div
                                            class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                            <h6 class="fw-semibold mb-0">
                                                <i class="ri-list-check-2 me-2"></i>Budget Lines
                                            </h6>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                id="addLineBtn">
                                                <i class="ri-add-line me-1"></i>Add Line
                                            </button>
                                        </div>

                                        <div class="alert alert-info mb-3">
                                            <i class="ri-information-line me-2"></i>
                                            Click on a row to expand and edit. Changes are saved when you click "Save
                                            Line".
                                        </div>

                                        <!-- Budget Lines Table -->
                                        <div class="table-responsive">
                                            <table class="table table-bordered mb-0" id="budgetLinesTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="width: 40px;">#</th>
                                                        <th style="width: 100px;">Type</th>
                                                        <th>Line Item</th>
                                                        <th class="text-end" style="width: 150px;">Budgeted</th>
                                                        <th class="text-end" style="width: 150px;">Actual</th>
                                                        <th style="width: 100px;" class="text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="budgetLinesBody">
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-4">
                                                            <i class="ri-file-list-line fs-3 d-block mb-2"></i>
                                                            Loading budget lines...
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Totals Summary -->
                                        <div class="card bg-light mt-3">
                                            <div class="card-body py-3">
                                                <div class="row text-center">
                                                    <div class="col-md-4">
                                                        <small class="text-muted d-block">Total Income</small>
                                                        <strong class="fs-5 text-success" id="totalIncome">KES
                                                            0.00</strong>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <small class="text-muted d-block">Total Expense</small>
                                                        <strong class="fs-5 text-danger" id="totalExpense">KES
                                                            0.00</strong>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <small class="text-muted d-block">Net Budget</small>
                                                        <strong class="fs-5" id="netBudget">KES 0.00</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Form Actions -->
                                    <div class="pt-3 border-top d-flex justify-content-between">
                                        <a href="<?= SITE_URL ?>/diocese/budget-management/budget-overview/all-budgets"
                                            class="btn btn-light" id="cancelBtn">
                                            <i class="ri-arrow-left-line me-1"></i>Cancel
                                        </a>
                                        <div>
                                            <button type="button" class="btn btn-outline-primary me-2" id="saveBtn">
                                                <i class="ri-save-line me-1"></i>Save Changes
                                            </button>
                                            <button type="button" class="btn btn-primary" id="saveCloseBtn">
                                                <i class="ri-check-line me-1"></i>Save & Close
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <!-- End Main Content -->

        <!-- Footer -->
        <?php include '../../../includes/footer.php' ?>

    </div>

    <!-- Add New Line Modal -->
    <div class="modal fade" id="addLineModal" tabindex="-1" aria-labelledby="addLineModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLineModalLabel">
                        <i class="ri-add-line me-2"></i>Add Budget Line
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addLineForm">
                        <div class="mb-3">
                            <label for="newLineType" class="form-label">Line Type <span
                                    class="text-danger">*</span></label>
                            <select class="form-control" id="newLineType" required>
                                <option value="">Select Type</option>
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="newBudgetLine" class="form-label">Budget Line <span
                                    class="text-danger">*</span></label>
                            <select class="form-control" id="newBudgetLine" required disabled>
                                <option value="">Select type first</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="newBudgetedAmount" class="form-label">Budgeted Amount <span
                                    class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" class="form-control" id="newBudgetedAmount" min="0" step="0.01"
                                    required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="newActualAmount" class="form-label">Actual Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" class="form-control" id="newActualAmount" min="0" step="0.01"
                                    value="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="newLineNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="newLineNotes" rows="2"
                                placeholder="Optional notes"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmAddLineBtn">
                        <i class="ri-add-line me-1"></i>Add Line
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteLineModal" tabindex="-1" aria-labelledby="deleteLineModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteLineModalLabel">
                        <i class="ri-delete-bin-line me-2 text-danger"></i>Delete Line
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this budget line?</p>
                    <p class="fw-semibold text-danger mb-0" id="deleteLineName">-</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteLineBtn">
                        <i class="ri-delete-bin-line me-1"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <!-- Popper JS -->
    <script src="<?= SITE_URL ?>/assets/libs/@popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>

    <!-- Node Waves JS -->
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>

    <!-- Simplebar JS -->
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>

    <!-- Custom JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>

    <!-- Toast JS -->
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>

    <!-- Flatpickr JS -->
    <script src="<?= SITE_URL ?>/assets/libs/flatpickr/flatpickr.min.js"></script>

    <!-- Constants -->
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>

    <!-- Budget API Handler -->
    <script src="<?= SITE_URL ?>/assets/js/pages/budget-management/api-handler.js"></script>

    <!-- Edit Budget JS -->
    <script src="<?= SITE_URL ?>/assets/js/pages/budget-management/edit-budget.js"></script>
</body>

</html>