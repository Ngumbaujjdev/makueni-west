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
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Create Budget - Makueni West Diocese</title>
    <meta name="Description" content="Create a new budget for your territory" />

    <!-- Favicon -->
    <link rel="icon" href="http://localhost:8080/makueni-west/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <!-- Choices JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/choices.js/public/assets/scripts/choices.min.js"></script>

    <!-- Main Theme Js -->
    <script src="http://localhost:8080/makueni-west/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="http://localhost:8080/makueni-west/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />

    <!-- Style Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/icons.css" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="http://localhost:8080/makueni-west/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="http://localhost:8080/makueni-west/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <!-- Flatpickr Css -->
    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/libs/flatpickr/flatpickr.min.css" />

    <!-- Choices Css -->
    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/libs/choices.js/public/assets/styles/choices.min.css" />

    <!-- Custom Styles for Choices.js validation -->
    <style>
        .choices.is-invalid-choices .choices__inner {
            border-color: #dc3545 !important;
        }
        .choices.is-invalid-choices .choices__inner:focus {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }
    </style>

    <!-- User Territory for JS -->
    <script>
        const USER_TERRITORY = {
            type: '<?php echo addslashes($userTerritoryType); ?>',
            id: <?php echo $userTerritoryId; ?>,
            name: '<?php echo addslashes($userTerritoryName); ?>'
        };
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
                    <h1 class="page-title fw-semibold fs-18 mb-0">
                        <i class="ri-add-box-line me-2"></i>Create New Budget
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="/makueni-west/diocese/dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">Budget Management</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Create Budget</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Budget Form Card -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header d-sm-flex d-block">
                                <ul class="nav nav-tabs nav-tabs-header mb-0 d-sm-flex d-block" role="tablist">
                                    <li class="nav-item m-1">
                                        <a class="nav-link active" data-bs-toggle="tab" role="tab" 
                                            href="#step1-basic" aria-selected="true" id="step1Tab">
                                            <i class="ri-file-list-3-line me-2"></i>Basic Information
                                        </a>
                                    </li>
                                    <li class="nav-item m-1">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" 
                                            href="#step2-lines" aria-selected="false" id="step2Tab">
                                            <i class="ri-list-check-2 me-2"></i>Budget Lines
                                        </a>
                                    </li>
                                    <li class="nav-item m-1">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" 
                                            href="#step3-review" aria-selected="false" id="step3Tab">
                                            <i class="ri-checkbox-circle-line me-2"></i>Review & Submit
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <form id="createBudgetForm">
                                    <div class="tab-content">
                                        
                                        <!-- Step 1: Basic Information -->
                                        <div class="tab-pane show active" id="step1-basic" role="tabpanel">
                                            <div class="alert alert-info mb-4">
                                                <i class="ri-information-line me-2"></i>
                                                Enter the basic details for your budget. Fields marked with <span class="text-danger">*</span> are required.
                                            </div>
                                            
                                            <div class="row gy-3">
                                                <!-- Budget Name -->
                                                <div class="col-md-6">
                                                    <label for="budgetName" class="form-label">Budget Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="budgetName" placeholder="e.g., Q1 2026 Budget" required>
                                                </div>

                                                <!-- Fiscal Year (populated from API) -->
                                                <div class="col-md-6">
                                                    <label for="fiscalYear" class="form-label">Fiscal Year <span class="text-danger">*</span></label>
                                                    <select class="form-control" id="fiscalYear" required>
                                                        <option value="">Loading fiscal years...</option>
                                                    </select>
                                                </div>

                                                <!-- Budget Type -->
                                                <div class="col-md-6">
                                                    <label for="budgetType" class="form-label">Budget Type <span class="text-danger">*</span></label>
                                                    <select class="form-control" id="budgetType" required>
                                                        <option value="">Select Type</option>
                                                    </select>
                                                </div>

                                                <!-- Budget Period (populated from API based on year + type) -->
                                                <div class="col-md-6">
                                                    <label for="budgetPeriod" class="form-label">Budget Period <span class="text-danger">*</span></label>
                                                    <select class="form-control" id="budgetPeriod" required disabled>
                                                        <option value="">Select year and type first</option>
                                                    </select>
                                                </div>

                                                <!-- Start Date -->
                                                <div class="col-md-6">
                                                    <label for="startDate" class="form-label">Start Date</label>
                                                    <input type="text" class="form-control" id="startDate" readonly placeholder="Auto-calculated">
                                                </div>

                                                <!-- End Date -->
                                                <div class="col-md-6">
                                                    <label for="endDate" class="form-label">End Date</label>
                                                    <input type="text" class="form-control" id="endDate" readonly placeholder="Auto-calculated">
                                                </div>

                                                <!-- Territory (Read-only) -->
                                                <div class="col-md-12">
                                                    <label class="form-label">Territory</label>
                                                    <div class="alert alert-primary mb-0 py-2">
                                                        <i class="ri-map-pin-line me-2"></i>
                                                        <strong id="territoryDisplay"><?php echo htmlspecialchars($userTerritoryName); ?></strong>
                                                        <small class="text-muted ms-2">(Auto-detected from your account)</small>
                                                    </div>
                                                </div>

                                                <!-- Description -->
                                                <div class="col-md-12">
                                                    <label for="description" class="form-label">Description</label>
                                                    <textarea class="form-control" id="description" rows="3" placeholder="Optional description for this budget"></textarea>
                                                </div>
                                            </div>

                                            <!-- Step 1 Navigation -->
                                            <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                                <a href="/makueni-west/diocese/budget-management/budget-overview/all-budgets" class="btn btn-light">
                                                    <i class="ri-arrow-left-line me-1"></i>Cancel
                                                </a>
                                                <button type="button" class="btn btn-primary" id="nextToStep2">
                                                    Next: Budget Lines <i class="ri-arrow-right-line ms-1"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Step 2: Budget Lines -->
                                        <div class="tab-pane" id="step2-lines" role="tabpanel">
                                            <div class="alert alert-info mb-4">
                                                <i class="ri-information-line me-2"></i>
                                                Add income and expense line items to your budget. Click "Add Line" to add more rows.
                                            </div>

                                            <!-- Budget Lines Container -->
                                            <div id="budgetLinesContainer">
                                                <!-- Dynamic rows will be added here -->
                                            </div>

                                            <!-- Add Line Button -->
                                            <div class="mb-4">
                                                <button type="button" class="btn btn-outline-primary" id="addLineBtn">
                                                    <i class="ri-add-line me-1"></i>Add Budget Line
                                                </button>
                                            </div>

                                            <!-- Totals Summary -->
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <div class="row text-center">
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Total Income</small>
                                                            <strong class="fs-5 text-success" id="totalIncome">KES 0.00</strong>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Total Expense</small>
                                                            <strong class="fs-5 text-danger" id="totalExpense">KES 0.00</strong>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Net Budget</small>
                                                            <strong class="fs-5" id="netBudget">KES 0.00</strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Step 2 Navigation -->
                                            <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                                <button type="button" class="btn btn-secondary" id="backToStep1">
                                                    <i class="ri-arrow-left-line me-1"></i>Previous
                                                </button>
                                                <button type="button" class="btn btn-primary" id="nextToStep3">
                                                    Next: Review & Submit <i class="ri-arrow-right-line ms-1"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Step 3: Review & Submit -->
                                        <div class="tab-pane" id="step3-review" role="tabpanel">
                                            <div class="alert alert-success mb-4">
                                                <i class="ri-checkbox-circle-line me-2"></i>
                                                Review all information below before creating your budget.
                                            </div>

                                            <!-- Basic Info Review -->
                                            <div class="card border mb-4">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0 fw-semibold"><i class="ri-file-list-3-line me-2"></i>Basic Information</h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row g-3">
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Budget Name</small>
                                                            <strong id="reviewBudgetName">-</strong>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Fiscal Year</small>
                                                            <strong id="reviewFiscalYear">-</strong>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Budget Type</small>
                                                            <strong id="reviewBudgetType">-</strong>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Period</small>
                                                            <strong id="reviewPeriod">-</strong>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">Start Date</small>
                                                            <strong id="reviewStartDate">-</strong>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <small class="text-muted d-block">End Date</small>
                                                            <strong id="reviewEndDate">-</strong>
                                                        </div>
                                                        <div class="col-md-12">
                                                            <small class="text-muted d-block">Description</small>
                                                            <span id="reviewDescription">-</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Budget Lines Review -->
                                            <div class="card border mb-4">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0 fw-semibold"><i class="ri-list-check-2 me-2"></i>Budget Lines</h6>
                                                </div>
                                                <div class="card-body p-0">
                                                    <div class="table-responsive">
                                                        <table class="table table-hover mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th>#</th>
                                                                    <th>Type</th>
                                                                    <th>Line Item</th>
                                                                    <th class="text-end">Amount</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody id="reviewLinesTable">
                                                                <tr>
                                                                    <td colspan="4" class="text-center text-muted">No lines added</td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Totals Review -->
                                            <div class="card border-primary">
                                                <div class="card-body">
                                                    <div class="row">
                                                        <div class="col-md-4 text-center">
                                                            <small class="text-muted">Total Income</small>
                                                            <div class="fs-5 fw-bold text-success" id="reviewTotalIncome">KES 0.00</div>
                                                        </div>
                                                        <div class="col-md-4 text-center">
                                                            <small class="text-muted">Total Expense</small>
                                                            <div class="fs-5 fw-bold text-danger" id="reviewTotalExpense">KES 0.00</div>
                                                        </div>
                                                        <div class="col-md-4 text-center">
                                                            <small class="text-muted">Net Budget</small>
                                                            <div class="fs-5 fw-bold" id="reviewNetBudget">KES 0.00</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Step 3 Navigation -->
                                            <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                                <button type="button" class="btn btn-secondary" id="backToStep2">
                                                    <i class="ri-arrow-left-line me-1"></i>Previous
                                                </button>
                                                <div>
                                                    <button type="button" class="btn btn-outline-secondary me-2" id="saveDraftBtn">
                                                        <i class="ri-save-line me-1"></i>Save as Draft
                                                    </button>
                                                    <button type="submit" class="btn btn-success" id="submitBudgetBtn">
                                                        <i class="ri-check-line me-1"></i>Create Budget
                                                    </button>
                                                </div>
                                            </div>
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

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <!-- Popper JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/@popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/defaultmenu.min.js"></script>

    <!-- Node Waves JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/sticky.js"></script>

    <!-- Simplebar JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/js/simplebar.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/custom-switcher.min.js"></script>

    <!-- Custom JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/custom.js"></script>

    <!-- Toast JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/Toasts.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/js/utils/toast.js"></script>

    <!-- Flatpickr JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/flatpickr/flatpickr.min.js"></script>

    <!-- Constants -->
    <script src="http://localhost:8080/makueni-west/assets/js/config/constants.js"></script>

    <!-- Budget API Handler -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/budget-management/api-handler.js"></script>

    <!-- Create Budget JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/budget-management/create-budget.js"></script>
</body>

</html>
