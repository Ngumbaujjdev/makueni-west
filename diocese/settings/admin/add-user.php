<?php
// 1. FIRST: Start session & check auth (NO HTML BEFORE THIS!)
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

// 2. Check specific permission (same as users.php)
requirePermission('diocese.dashboard.dashboardoverview.read');

// 3. Get user data
$user = getAuthUser();
$currentRole = getCurrentRole();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Add New User - Makueni West Diocese</title>
    <meta name="Description" content="Create a new user account with territory assignment" />

    <!-- Favicon -->
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico"
        type="image/x-icon" />

    <!-- Choices JS -->
    <script src="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/scripts/choices.min.js">
    </script>

    <!-- Main Theme Js -->
    <script src="<?= asset_url('/assets/js/main.js') ?>"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= asset_url('/assets/css/styles.min.css') ?>" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= asset_url('/assets/css/icons.css') ?>" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <!-- Color Picker Css -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/flatpickr/flatpickr.min.css" />
    <link rel="stylesheet"
        href="<?= SITE_URL ?>/assets/libs/%40simonwep/pickr/themes/nano.min.css" />

    <!-- Choices Css -->
    <link rel="stylesheet"
        href="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/styles/choices.min.css" />



</head>

<body>
    <!-- App config (must load before any page script that uses AppConfig) -->
    <script src="<?= asset_url('/assets/js/config/app.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/config/constants.js') ?>"></script>
    <!-- start switcher  -->
    <?php include '../../../includes/start-switcher.php' ?>

    <!-- Loader -->
    <?php include "../../../includes/loader.php" ?>
    <!-- Loader -->

    <div class="page">
        <!-- app-header -->
        <?php include '../../../includes/header.php' ?>
        <!-- /app-header -->
        <!-- Start::app-sidebar -->
        <?php include '../../../includes/sidebar.php' ?>
        <!-- End::app-sidebar -->

        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <h1 class="page-title fw-semibold fs-18 mb-0">
                        <i class="ri-user-add-line me-2"></i>User Management
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="<?= SITE_URL ?>/diocese/dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">System Administration</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Add New User</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- User Form Card -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header d-sm-flex d-block">
                                <ul class="nav nav-tabs nav-tabs-header mb-0 d-sm-flex d-block" role="tablist">
                                    <li class="nav-item m-1">
                                        <a class="nav-link active" data-bs-toggle="tab" role="tab" aria-current="page"
                                            href="#basic-info" aria-selected="true">
                                            <i class="ri-user-line me-2"></i>Basic Information
                                        </a>
                                    </li>
                                    <li class="nav-item m-1">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" aria-current="page"
                                            href="#territory-assignment" aria-selected="false">
                                            <i class="ri-map-pin-line me-2"></i>Territory Assignment
                                        </a>
                                    </li>
                                    <li class="nav-item m-1">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab" aria-current="page"
                                            href="#review-confirm" aria-selected="false">
                                            <i class="ri-check-line me-2"></i>Review & Confirm
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <form id="addUserWizardForm">
                                    <div class="tab-content">
                                        <!-- Tab 1: Basic Information -->
                                        <div class="tab-pane show active" id="basic-info" role="tabpanel">
                                            <div class="row gy-3">
                                                <!-- First Name -->
                                                <div class="col-md-6">
                                                    <label for="firstName" class="form-label">First Name <span
                                                            class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="firstName"
                                                        placeholder="First Name" required>
                                                </div>

                                                <!-- Last Name -->
                                                <div class="col-md-6">
                                                    <label for="lastName" class="form-label">Last Name <span
                                                            class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="lastName"
                                                        placeholder="Last Name" required>
                                                </div>

                                                <!-- Email -->
                                                <div class="col-md-6">
                                                    <label for="email" class="form-label">Email Address <span
                                                            class="text-danger">*</span></label>
                                                    <input type="email" class="form-control" id="email"
                                                        placeholder="user@example.com" required>
                                                </div>

                                                <!-- Phone -->
                                                <div class="col-md-6">
                                                    <label for="phone" class="form-label">Phone Number</label>
                                                    <input type="tel" class="form-control" id="phone"
                                                        placeholder="+254712345678">
                                                </div>

                                                <!-- Position -->
                                                <div class="col-md-6">
                                                    <label for="position" class="form-label">Position/Title</label>
                                                    <input type="text" class="form-control" id="position"
                                                        placeholder="e.g., Senior Pastor">
                                                </div>

                                                <!-- Employee Code -->
                                                <div class="col-md-6">
                                                    <label for="employeeCode" class="form-label">Employee Code</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" id="employeeCode"
                                                            placeholder="Auto-generated" readonly>
                                                        <button type="button" class="btn btn-primary" id="generateCodeBtn">
                                                            <i class="ri-refresh-line"></i> Generate
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Password -->
                                                <div class="col-md-6">
                                                    <label for="password" class="form-label">Password</label>
                                                    <div class="input-group">
                                                        <input type="password" class="form-control" id="password"
                                                            placeholder="Auto-generated" readonly>
                                                        <button type="button" class="btn btn-primary" id="generatePasswordBtn">
                                                            <i class="ri-refresh-line"></i> Generate
                                                        </button>
                                                        <button type="button" class="btn btn-secondary" id="togglePasswordBtn">
                                                            <i class="ri-eye-line"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Force Password Change -->
                                                <div class="col-12">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                            id="mustChangePassword" checked>
                                                        <label class="form-check-label" for="mustChangePassword">
                                                            Force user to change password on first login
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Navigation Buttons -->
                                            <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                                                <button type="button" class="btn btn-primary" id="nextToTerritoryBtn">
                                                    Next: Territory Assignment <i class="ri-arrow-right-line ms-1"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Tab 2: Territory Assignment -->
                                        <div class="tab-pane" id="territory-assignment" role="tabpanel">
                                            <div class="alert alert-info">
                                                <i class="ri-information-line me-2"></i>
                                                Assign this user to territories with roles. Primary assignment is required.
                                            </div>

                                            <!-- PRIMARY ASSIGNMENT -->
                                            <div class="card mb-3">
                                                <div class="card-header bg-primary text-white">
                                                    <i class="ri-star-fill me-2"></i>Primary Assignment (Required)
                                                </div>
                                                <div class="card-body">
                                                    <div class="row gy-3">
                                                        <!-- Territory Type -->
                                                        <div class="col-md-4">
                                                            <label for="territoryType" class="form-label">Territory Type <span
                                                                    class="text-danger">*</span></label>
                                                            <select class="form-control" id="territoryType" required>
                                                                <option value="">Select territory type...</option>
                                                                <option value="diocese">Diocese</option>
                                                                <option value="region">Region</option>
                                                                <option value="church">Church</option>
                                                            </select>
                                                        </div>

                                                        <!-- Territory -->
                                                        <div class="col-md-4">
                                                            <label for="territory" class="form-label">Territory <span
                                                                    class="text-danger">*</span></label>
                                                            <select class="form-control" id="territory" disabled required>
                                                                <option value="">Select territory type first...</option>
                                                            </select>
                                                        </div>

                                                        <!-- Role -->
                                                        <div class="col-md-4">
                                                            <label for="role" class="form-label">Role <span
                                                                    class="text-danger">*</span></label>
                                                            <select class="form-control" id="role" disabled required>
                                                                <option value="">Select territory first...</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- SECONDARY ASSIGNMENTS -->
                                            <div class="card">
                                                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                                                    <span><i class="ri-user-add-line me-2"></i>Secondary Assignments (Optional)</span>
                                                    <button type="button" class="btn btn-sm btn-light" id="addSecondaryBtn">
                                                        <i class="ri-add-line me-1"></i>Add Secondary Assignment
                                                    </button>
                                                </div>
                                                <div class="card-body" id="secondaryAssignmentsContainer">
                                                    <p class="text-muted text-center mb-0" id="noSecondaryMsg">
                                                        <i class="ri-information-line me-1"></i>
                                                        No secondary assignments added. Click "Add Secondary Assignment" to add one.
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <!-- Navigation Buttons -->
                                            <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                                <button type="button" class="btn btn-secondary" id="backToBasicBtn">
                                                    <i class="ri-arrow-left-line me-1"></i> Previous: Basic Info
                                                </button>
                                                <button type="button" class="btn btn-primary" id="nextToReviewBtn">
                                                    Next: Review & Confirm <i class="ri-arrow-right-line ms-1"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Tab 3: Review & Confirm -->
                                        <div class="tab-pane" id="review-confirm" role="tabpanel">
                                            <div class="alert alert-success">
                                                <i class="ri-checkbox-circle-line me-2"></i>
                                                Please review the information below before creating the user
                                            </div>

                                            <div class="row gy-3">
                                                <!-- User Information Summary -->
                                                <div class="col-md-6">
                                                    <div class="card border">
                                                        <div class="card-header bg-light">
                                                            <h6 class="mb-0 fw-semibold">User Information</h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <!-- Name -->
                                                            <div class="d-flex align-items-start mb-3">
                                                                <div class="flex-shrink-0 me-3">
                                                                    <i class="ri-user-line fs-5 text-primary"></i>
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <span class="text-muted small">Name</span>
                                                                    <span class="d-block fw-semibold text-dark" id="reviewName">-</span>
                                                                </div>
                                                            </div>

                                                            <!-- Email -->
                                                            <div class="d-flex align-items-start mb-3">
                                                                <div class="flex-shrink-0 me-3">
                                                                    <i class="ri-mail-line fs-5 text-primary"></i>
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <span class="text-muted small">Email</span>
                                                                    <span class="d-block fw-semibold text-dark" id="reviewEmail">-</span>
                                                                </div>
                                                            </div>

                                                            <!-- Phone -->
                                                            <div class="d-flex align-items-start mb-3">
                                                                <div class="flex-shrink-0 me-3">
                                                                    <i class="ri-phone-line fs-5 text-primary"></i>
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <span class="text-muted small">Phone</span>
                                                                    <span class="d-block fw-semibold text-dark" id="reviewPhone">-</span>
                                                                </div>
                                                            </div>

                                                            <!-- Position -->
                                                            <div class="d-flex align-items-start mb-3">
                                                                <div class="flex-shrink-0 me-3">
                                                                    <i class="ri-briefcase-line fs-5 text-primary"></i>
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <span class="text-muted small">Position</span>
                                                                    <span class="d-block fw-semibold text-dark" id="reviewPosition">-</span>
                                                                </div>
                                                            </div>

                                                            <!-- Employee Code -->
                                                            <div class="d-flex align-items-start">
                                                                <div class="flex-shrink-0 me-3">
                                                                    <i class="ri-barcode-line fs-5 text-primary"></i>
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <span class="text-muted small">Employee Code</span>
                                                                    <span class="d-block fw-semibold text-dark" id="reviewEmployeeCode">-</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Assignment Summary -->
                                                <div class="col-md-6">
                                                    <div class="card border">
                                                        <div class="card-header bg-light">
                                                            <h6 class="mb-0 fw-semibold">Territory Assignments</h6>
                                                        </div>
                                                        <div class="card-body" id="reviewAssignments">
                                                            <p class="text-muted mb-0">No assignments configured</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                            
                                            <!-- Navigation Buttons -->
                                            <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                                <button type="button" class="btn btn-secondary" id="backToTerritoryBtn">
                                                    <i class="ri-arrow-left-line me-1"></i> Previous: Territory Assignment
                                                </button>
                                                <button type="submit" class="btn btn-success" id="submitBtn">
                                                    <i class="ri-user-add-line me-1"></i> Create User
                                                </button>
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
        <!-- End::app-content -->

        <!-- Footer Start -->
        <?php include '../../../includes/footer.php' ?>
        <!-- Footer End -->
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>
    <!-- Scroll To Top -->

    <!-- Popper JS -->
    <script src="<?= SITE_URL ?>/assets/libs/%40popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="<?= asset_url('/assets/js/defaultmenu.min.js') ?>"></script>

    <!-- Node Waves JS-->
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="<?= asset_url('/assets/js/sticky.js') ?>"></script>

    <!-- Simplebar JS -->
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= asset_url('/assets/js/simplebar.js') ?>"></script>

    <!-- Color Picker JS -->
    <script src="<?= SITE_URL ?>/assets/libs/%40simonwep/pickr/pickr.es5.min.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="<?= asset_url('/assets/js/custom-switcher.min.js') ?>"></script>

    <!-- Custom JS -->
    <script src="<?= asset_url('/assets/js/custom.js') ?>"></script>

    <!-- Toast JS -->
    <script src="<?= asset_url('/assets/js/Toasts.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/utils/toast.js') ?>"></script>

    <!-- Constants -->
    <script src="<?= asset_url('/assets/js/config/constants.js') ?>"></script>

    <!-- Utils -->
    <script src="<?= asset_url('/assets/js/pages/system-administration/user-management/user-management-utils.js') ?>"></script>

    <!-- API Handler -->
    <script src="<?= asset_url('/assets/js/pages/system-administration/api-handler.js') ?>"></script>

    <!-- Add User Wizard -->
    <script src="<?= asset_url('/assets/js/pages/system-administration/user-management/add-user-wizard.js') ?>"></script>
</body>

</html>
